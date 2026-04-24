<?php
$pageTitle = 'Séance — ' . $session['class_name'] . ' — ProClasse';

// Organiser les sièges en grille
$seatMap = [];
foreach ($seats as $s) { $seatMap[$s['row_index']][$s['col_index']] = $s; }

$grid = [];
for ($r = 0; $r < $session['room_rows']; $r++) {
    for ($c = 0; $c < $session['room_cols']; $c++) {
        $grid[$r][$c] = $seatMap[$r][$c] ?? null;
    }
}

// Observations indexées par student_id
$obsMap = [];
foreach ($observations as $o) { $obsMap[$o['student_id']][] = $o; }

ob_start();
?>
<div class="live-header">
  <a href="/sessions" class="btn btn-ghost btn-sm">← Séances</a>
  <div class="live-title">
    <strong><?= htmlspecialchars($session['class_name']) ?></strong>
    <span>·</span>
    <span><?= htmlspecialchars($session['room_name']) ?></span>
    <span>·</span>
    <span><?= date('d/m/Y', strtotime($session['date'])) ?></span>
    <?php if ($session['subject']): ?>
    <span class="badge"><?= htmlspecialchars($session['subject']) ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="live-layout">
  <div class="live-room-wrap">
    <div class="room-label-top">Tableau / Bureau du professeur</div>
    <div class="live-room" id="liveRoom" style="--room-cols: <?= $session['room_cols'] ?>">
      <?php foreach ($grid as $rowIdx => $cols): ?>
        <?php foreach ($cols as $colIdx => $seat): ?>
          <?php if ($seat === null): ?>
            <div class="live-seat inactive"></div>
          <?php else: ?>
            <div class="live-seat <?= $seat['student_id'] ? 'occupied' : 'empty' ?>"
                 data-seat-id="<?= $seat['id'] ?>"
                 data-student-id="<?= $seat['student_id'] ?? '' ?>"
                 data-student-name="<?= $seat['student_id'] ? htmlspecialchars($seat['last_name'] . ' ' . $seat['first_name'], ENT_QUOTES) : '' ?>"
                 <?= $seat['student_id'] ? 'draggable="true"' : '' ?>>
              <?php if ($seat['student_id']): ?>
                <?php
                  $photoUrl = getPhotoUrl(
                    $session['class_name'],
                    $seat['last_name'],
                    $seat['first_name']
                  );
                ?>
                <?php if ($photoUrl): ?>
                  <img src="<?= htmlspecialchars($photoUrl) ?>"
                       alt="<?= htmlspecialchars($seat['first_name'] . ' ' . $seat['last_name']) ?>"
                       class="seat-photo" loading="lazy">
                <?php else: ?>
                  <div class="seat-photo-placeholder">
                    <?= htmlspecialchars(mb_substr($seat['first_name'], 0, 1) . mb_substr($seat['last_name'], 0, 1)) ?>
                  </div>
                <?php endif; ?>

                <div class="seat-name">
                  <?= htmlspecialchars($seat['first_name']) ?><br>
                  <small><?= htmlspecialchars($seat['last_name']) ?></small>
                </div>

                <div class="seat-tags" id="tags-<?= $seat['student_id'] ?>">
                  <?php foreach ($obsMap[$seat['student_id']] ?? [] as $o): ?>
                  <span class="tag-chip"
                        style="background:<?= htmlspecialchars($o['color'] ?? '#888') ?>"
                        data-obs-id="<?= $o['id'] ?>"
                        data-student-id="<?= $seat['student_id'] ?>"
                        title="Retirer"><?= htmlspecialchars(($o['icon'] ?? '') . (($o['tag'] ?? '') ? ' ' . $o['tag'] : '')) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="seat-empty-label">—</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="live-sidebar">
    <h3>Tags rapides</h3>
    <div class="tags-list" id="tagsList">
      <?php foreach ($tags as $t): ?>
      <button class="tag-btn"
              style="--tag-color:<?= htmlspecialchars($t['color']) ?>"
              data-tag="<?= htmlspecialchars($t['label']) ?>"
              data-icon="<?= htmlspecialchars($t['icon'] ?? '') ?>"
              data-color="<?= htmlspecialchars($t['color']) ?>">
        <?= htmlspecialchars(($t['icon'] ?? '') . ' ' . $t['label']) ?>
      </button>
      <?php endforeach; ?>
    </div>

    <div id="selectedStudent" class="selected-student" hidden>
      <div class="selected-name" id="selectedName"></div>
      <p class="text-muted text-sm">Choisissez un tag ci-dessus</p>
    </div>
  </div>
</div>

<script>
const SESSION_ID = <?= (int)$session['id'] ?>;
let currentStudentId = null;
let currentStudentName = '';

const liveRoom = document.getElementById('liveRoom');
const tagsList = document.getElementById('tagsList');

// État local seat_id -> student_id
const seatStudentMap = {};
liveRoom.querySelectorAll('.live-seat[data-seat-id]').forEach(el => {
  seatStudentMap[parseInt(el.dataset.seatId)] = el.dataset.studentId ? parseInt(el.dataset.studentId) : null;
});

function getSeatEl(seatId) {
  return liveRoom.querySelector(`[data-seat-id="${seatId}"]`);
}

function seatMarkupFromData(sourceEl) {
  return {
    html: sourceEl.innerHTML,
    studentId: sourceEl.dataset.studentId || '',
    studentName: sourceEl.dataset.studentName || '',
    occupied: sourceEl.classList.contains('occupied')
  };
}

function setSeatOccupied(el, payload) {
  el.innerHTML = payload.html;
  el.dataset.studentId = payload.studentId;
  el.dataset.studentName = payload.studentName;
  el.className = 'live-seat occupied';
  el.draggable = true;
}

function setSeatEmpty(el) {
  el.innerHTML = '<div class="seat-empty-label">—</div>';
  el.dataset.studentId = '';
  el.dataset.studentName = '';
  el.className = 'live-seat empty';
  el.draggable = false;
}

function clearSelection() {
  liveRoom.querySelectorAll('.live-seat.selected').forEach(s => s.classList.remove('selected'));
  currentStudentId = null;
  currentStudentName = '';
  document.getElementById('selectedStudent').hidden = true;
  document.getElementById('selectedName').textContent = '';
}

function openTagMenu(seatId, studentId, name) {
  liveRoom.querySelectorAll('.live-seat.selected').forEach(s => s.classList.remove('selected'));
  const seatEl = getSeatEl(seatId);
  if (seatEl) seatEl.classList.add('selected');
  currentStudentId = studentId;
  currentStudentName = name;
  document.getElementById('selectedName').textContent = name;
  document.getElementById('selectedStudent').hidden = false;
}

function selectTag(tag, icon = '', color = '#888') {
  if (!currentStudentId) return;

  fetch(`/api/sessions/${SESSION_ID}/observations`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ student_id: currentStudentId, tag })
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) addTagChip(currentStudentId, d.obs_id, tag, color, icon);
  });
}

function addTagChip(studentId, obsId, tag, color = '#888', icon = '') {
  const container = document.getElementById('tags-' + studentId);
  if (!container) return;

  const span = document.createElement('span');
  span.className = 'tag-chip';
  span.style.background = color;
  span.title = 'Retirer';
  span.dataset.obsId = obsId;
  span.dataset.studentId = studentId;
  span.textContent = (icon ? icon + ' ' : '') + tag;
  container.appendChild(span);
}

function removeObs(obsId, studentId, chipEl = null) {
  fetch(`/api/sessions/${SESSION_ID}/observations/${obsId}`, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      if (chipEl) {
        chipEl.remove();
      } else {
        refreshTags(studentId);
      }
    });
}

function refreshTags(studentId) {
  fetch(`/api/sessions/${SESSION_ID}/observations`)
    .then(r => r.json())
    .then(obs => {
      const container = document.getElementById('tags-' + studentId);
      if (!container) return;

      const mine = obs.filter(o => o.student_id == studentId);
      container.innerHTML = mine.map(o =>
        `<span class="tag-chip"
              style="background:${o.color || '#888'}"
              data-obs-id="${o.id}"
              data-student-id="${studentId}"
              title="Retirer">${(o.icon ? o.icon + ' ' : '') + (o.tag || '')}</span>`
      ).join('');
    });
}

async function persistMove(studentId, sourceSeatId, targetSeatId) {
  const r = await fetch(`/api/sessions/${SESSION_ID}/move-seat`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      student_id: studentId,
      source_seat_id: sourceSeatId,
      target_seat_id: targetSeatId
    })
  });
  return r.json();
}

async function moveSeat(studentId, targetSeatId) {
  const sourceSeatId = parseInt(
    Object.keys(seatStudentMap).find(k => seatStudentMap[k] === studentId)
  );

  if (isNaN(sourceSeatId) || sourceSeatId === targetSeatId) return;

  const srcEl = getSeatEl(sourceSeatId);
  const tgtEl = getSeatEl(targetSeatId);
  if (!srcEl || !tgtEl) return;

  const srcPayload = seatMarkupFromData(srcEl);
  const tgtPayload = seatMarkupFromData(tgtEl);

  const targetStudentId = seatStudentMap[targetSeatId] ?? null;

  // Mise à jour optimiste UI
  setSeatOccupied(tgtEl, srcPayload);

  if (targetStudentId) {
    setSeatOccupied(srcEl, tgtPayload);
  } else {
    setSeatEmpty(srcEl);
  }

  seatStudentMap[targetSeatId] = studentId;
  seatStudentMap[sourceSeatId] = targetStudentId;

  clearSelection();

  try {
    const result = await persistMove(studentId, sourceSeatId, targetSeatId);
    if (!result.ok) throw new Error('save failed');
  } catch (e) {
    // rollback
    if (srcPayload.occupied) setSeatOccupied(srcEl, srcPayload); else setSeatEmpty(srcEl);
    if (tgtPayload.occupied) setSeatOccupied(tgtEl, tgtPayload); else setSeatEmpty(tgtEl);

    seatStudentMap[sourceSeatId] = srcPayload.studentId ? parseInt(srcPayload.studentId) : null;
    seatStudentMap[targetSeatId] = tgtPayload.studentId ? parseInt(tgtPayload.studentId) : null;

    alert('Déplacement non enregistré.');
  }
}

// Clic siège -> ouvrir tags
liveRoom.addEventListener('click', e => {
  if (e.target.closest('.tag-chip')) return;

  const seat = e.target.closest('.live-seat.occupied');
  if (!seat) return;

  if (seat._dragJustHappened) {
    seat._dragJustHappened = false;
    return;
  }

  openTagMenu(
    parseInt(seat.dataset.seatId),
    parseInt(seat.dataset.studentId),
    seat.dataset.studentName
  );
});

// Clic chip -> supprimer observation
liveRoom.addEventListener('click', e => {
  const chip = e.target.closest('.tag-chip');
  if (!chip) return;
  e.stopPropagation();

  removeObs(
    parseInt(chip.dataset.obsId),
    parseInt(chip.dataset.studentId),
    chip
  );
});

// Clic bouton tag
tagsList.addEventListener('click', e => {
  const btn = e.target.closest('.tag-btn');
  if (!btn) return;
  selectTag(btn.dataset.tag, btn.dataset.icon, btn.dataset.color);
});

// Drag souris
let draggedStudentId = null;

liveRoom.addEventListener('dragstart', e => {
  const seat = e.target.closest('.live-seat.occupied');
  if (!seat) {
    e.preventDefault();
    return;
  }

  const studentId = parseInt(seat.dataset.studentId);
  if (!studentId) {
    e.preventDefault();
    return;
  }

  draggedStudentId = studentId;
  seat.classList.add('dragging');
  liveRoom.classList.add('drag-active');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(studentId));
});

liveRoom.addEventListener('dragend', e => {
  const seat = e.target.closest('.live-seat');
  if (seat) seat.classList.remove('dragging');
  liveRoom.classList.remove('drag-active');
  liveRoom.querySelectorAll('.drag-over').forEach(s => s.classList.remove('drag-over'));
  draggedStudentId = null;
});

liveRoom.addEventListener('dragover', e => {
  const seat = e.target.closest('.live-seat:not(.inactive)');
  if (!seat) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  seat.classList.add('drag-over');
});

liveRoom.addEventListener('dragleave', e => {
  const seat = e.target.closest('.live-seat');
  if (seat && !seat.contains(e.relatedTarget)) {
    seat.classList.remove('drag-over');
  }
});

liveRoom.addEventListener('drop', e => {
  const seat = e.target.closest('.live-seat:not(.inactive)');
  if (!seat) return;

  e.preventDefault();
  seat.classList.remove('drag-over');
  liveRoom.classList.remove('drag-active');

  const studentId = parseInt(e.dataTransfer.getData('text/plain'));
  const targetSeatId = parseInt(seat.dataset.seatId);

  if (!isNaN(studentId) && !isNaN(targetSeatId)) {
    seat._dragJustHappened = true;
    moveSeat(studentId, targetSeatId);
  }
});

// Tactile
const DRAG_THRESHOLD = 8;
let touchClone = null;
let touchStudId = null;
let touchSrcEl = null;
let touchStartX = 0;
let touchStartY = 0;
let touchOffX = 0;
let touchOffY = 0;
let touchIsDrag = false;

liveRoom.addEventListener('touchstart', e => {
  if (e.target.closest('.tag-chip')) return;

  const seat = e.target.closest('.live-seat.occupied');
  if (!seat) return;

  const t = e.touches[0];
  touchStudId = parseInt(seat.dataset.studentId);
  touchSrcEl = seat;
  touchStartX = t.clientX;
  touchStartY = t.clientY;
  touchIsDrag = false;

  const rect = seat.getBoundingClientRect();
  touchOffX = t.clientX - rect.left;
  touchOffY = t.clientY - rect.top;
}, { passive: true });

liveRoom.addEventListener('touchmove', e => {
  if (!touchSrcEl) return;

  const t = e.touches[0];
  const dx = t.clientX - touchStartX;
  const dy = t.clientY - touchStartY;

  if (!touchIsDrag && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;

  if (!touchIsDrag) {
    touchIsDrag = true;
    liveRoom.classList.add('drag-active');
    touchSrcEl.classList.add('dragging');

    const rect = touchSrcEl.getBoundingClientRect();
    touchClone = touchSrcEl.cloneNode(true);
    Object.assign(touchClone.style, {
      position: 'fixed',
      left: rect.left + 'px',
      top: rect.top + 'px',
      width: rect.width + 'px',
      height: rect.height + 'px',
      opacity: '0.75',
      pointerEvents: 'none',
      zIndex: '9999',
      boxShadow: '0 8px 24px rgba(0,0,0,.25)',
      borderRadius: 'var(--radius-lg)',
      transform: 'scale(1.05)',
      transition: 'none'
    });
    document.body.appendChild(touchClone);
  }

  e.preventDefault();
  touchClone.style.left = (t.clientX - touchOffX) + 'px';
  touchClone.style.top = (t.clientY - touchOffY) + 'px';

  liveRoom.querySelectorAll('.drag-over').forEach(s => s.classList.remove('drag-over'));

  touchClone.style.display = 'none';
  const under = document.elementFromPoint(t.clientX, t.clientY)?.closest('.live-seat:not(.inactive)');
  touchClone.style.display = '';

  if (under && under !== touchSrcEl) under.classList.add('drag-over');
}, { passive: false });

liveRoom.addEventListener('touchend', e => {
  if (!touchSrcEl) return;

  if (touchIsDrag && touchClone) {
    const t = e.changedTouches[0];
    touchClone.style.display = 'none';
    const target = document.elementFromPoint(t.clientX, t.clientY)?.closest('.live-seat:not(.inactive)');

    touchClone.remove();
    touchClone = null;
    touchSrcEl.classList.remove('dragging');
    liveRoom.classList.remove('drag-active');
    liveRoom.querySelectorAll('.drag-over').forEach(s => s.classList.remove('drag-over'));

    if (target && target !== touchSrcEl && touchStudId !== null) {
      const targetSeatId = parseInt(target.dataset.seatId);
      if (!isNaN(targetSeatId)) {
        target._dragJustHappened = true;
        moveSeat(touchStudId, targetSeatId);
      }
    }
  }

  touchSrcEl = null;
  touchStudId = null;
  touchIsDrag = false;
});

liveRoom.addEventListener('touchcancel', () => {
  if (touchClone) {
    touchClone.remove();
    touchClone = null;
  }
  if (touchSrcEl) touchSrcEl.classList.remove('dragging');
  liveRoom.classList.remove('drag-active');
  liveRoom.querySelectorAll('.drag-over').forEach(s => s.classList.remove('drag-over'));
  touchSrcEl = null;
  touchStudId = null;
  touchIsDrag = false;
});
</script>
<?php
$content = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="/css/app.css">
</head>
<body class="live-body">
<?= $content ?>
<script src="/js/app.js"></script>
</body>
</html>