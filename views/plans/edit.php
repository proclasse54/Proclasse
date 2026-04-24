<?php
$pageTitle = 'Plan : ' . $plan['name'] . ' — ProClasse';

// Grille des sièges
$seatMap = [];
foreach ($seats as $s) { $seatMap[$s['row_index']][$s['col_index']] = $s; }

$grid = [];
for ($r = 0; $r < $room['rows']; $r++) {
    for ($c = 0; $c < $room['cols']; $c++) {
        $grid[$r][$c] = $seatMap[$r][$c] ?? null;
    }
}

// Index des affectations existantes : seat_id → student
$assignMap = [];
foreach ($assignments as $a) { $assignMap[$a['seat_id']] = $a; }

ob_start();
?>
<div class="page-header">
  <div>
    <a href="/classes/<?= $plan['class_id'] ?>" class="btn btn-ghost btn-sm">← Retour</a>
    <h1>Plan : <?= htmlspecialchars($plan['name']) ?></h1>
    <p class="text-muted">Glissez un élève sur un siège — ou cliquez sur un siège puis sur un élève</p>
  </div>
  <button class="btn btn-primary" onclick="savePlan()">Enregistrer le plan</button>
</div>

<div class="plan-layout">
  <!-- Grille salle -->
  <div class="plan-room-wrap">
    <div class="room-label-top">Tableau / Bureau</div>
    <div class="plan-room" id="planRoom" style="--room-cols: <?= $room['cols'] ?>">
      <?php foreach ($grid as $rowIdx => $cols): ?>
        <?php foreach ($cols as $colIdx => $seat): ?>

          <?php if ($seat === null): ?>
            <div class="plan-seat inactive"></div>
          <?php else: ?>
            <?php $assigned = $assignMap[$seat['id']] ?? null; ?>
            <div class="plan-seat <?= $assigned ? 'assigned' : 'free' ?>"
                data-seat-id="<?= $seat['id'] ?>"
                onclick="selectSeat(<?= $seat['id'] ?>)">
              <?php if ($assigned): ?>
                <span class="plan-seat-name"><?= htmlspecialchars($assigned['first_name']) ?><br><small><?= htmlspecialchars($assigned['last_name']) ?></small></span>
              <?php else: ?>
                <span class="plan-seat-empty"><?= chr(65 + $rowIdx) . ($colIdx + 1) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Liste élèves -->
  <div class="plan-sidebar">
    <div class="plan-sidebar-header">
      <h3>Élèves</h3>
      <input type="search" id="studentSearch" placeholder="Rechercher…" oninput="filterStudents(this.value)">
    </div>
    <div class="plan-students" id="studentList">
      <?php foreach ($students as $st): ?>
      <div class="plan-student <?= isset($assignedStudents[$st['id']]) ? 'placed' : '' ?>"
          data-student-id="<?= $st['id'] ?>"
          data-first="<?= htmlspecialchars($st['first_name']) ?>"
          data-last="<?= htmlspecialchars($st['last_name']) ?>"
          data-name="<?= strtolower($st['last_name'] . ' ' . $st['first_name'])?>"
          draggable="true"
          onclick="assignStudent(<?= $st['id'] ?>)">
        <span class="student-initials"><?= mb_substr($st['first_name'],0,1) . mb_substr($st['last_name'],0,1) ?></span>
        <span class="student-fullname"><?= htmlspecialchars($st['first_name'] . ' ' . $st['last_name']) ?></span>
        <?php if (isset($assignedStudents[$st['id']])): ?>
        <span class="placed-badge">✓</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-ghost btn-sm" style="margin-top: auto" onclick="clearAll()">Tout effacer</button>
  </div>
</div>

<script>
const PLAN_ID   = <?= $plan['id'] ?>;
const ROOM_COLS = <?= $room['cols'] ?>;

// État local : seat_id → student_id (ou null)
let assignments = {};
<?php foreach ($assignments as $a): ?>
assignments[<?= $a['seat_id'] ?>] = <?= $a['student_id'] ?>;
<?php endforeach; ?>

// student_id → seat_id (inverse)
let studentSeat = {};
Object.entries(assignments).forEach(([sid, stid]) => { if(stid) studentSeat[stid] = parseInt(sid); });

let selectedSeatId = null;

/* ─────────────────────────────────────────────
   LOGIQUE MÉTIER (inchangée)
───────────────────────────────────────────── */
function selectSeat(seatId) {
  document.querySelectorAll('.plan-seat').forEach(s => s.classList.remove('selected'));
  const el = document.querySelector(`[data-seat-id="${seatId}"]`);
  if (el) el.classList.add('selected');
  selectedSeatId = seatId;
}

function assignStudent(studentId) {
  if (!selectedSeatId) { alert('Cliquez d\'abord sur un siège.'); return; }

  if (studentSeat[studentId]) {
    const oldSeatId = studentSeat[studentId];
    assignments[oldSeatId] = null;
    renderSeat(oldSeatId, null);
  }

  const prevStudent = assignments[selectedSeatId];
  if (prevStudent) { delete studentSeat[prevStudent]; updateStudentEl(prevStudent); }

  assignments[selectedSeatId] = studentId;
  studentSeat[studentId] = selectedSeatId;
  renderSeat(selectedSeatId, studentId);
  updateStudentEl(studentId);
  selectedSeatId = null;
  document.querySelectorAll('.plan-seat').forEach(s => s.classList.remove('selected'));
}

// Variante sans alerte, utilisée par le drag & drop
function assignStudentToSeat(studentId, seatId) {
  if (studentSeat[studentId]) {
    const oldSeatId = studentSeat[studentId];
    assignments[oldSeatId] = null;
    renderSeat(oldSeatId, null);
  }
  const prevStudent = assignments[seatId];
  if (prevStudent) { delete studentSeat[prevStudent]; updateStudentEl(prevStudent); }

  assignments[seatId] = studentId;
  studentSeat[studentId] = seatId;
  renderSeat(seatId, studentId);
  updateStudentEl(studentId);
  selectedSeatId = null;
  document.querySelectorAll('.plan-seat').forEach(s => s.classList.remove('selected'));
}

function renderSeat(seatId, studentId) {
  const el = document.querySelector(`[data-seat-id="${seatId}"]`);
  if (!el) return;
  if (studentId) {
    const stEl = document.querySelector(`[data-student-id="${studentId}"]`);
    const first = stEl?.dataset.first ?? '';
    const last  = stEl?.dataset.last  ?? '';
    el.className = 'plan-seat assigned';
    el.dataset.seatId = seatId;
    el.innerHTML = `<span class="plan-seat-name">${first}<br><small>${last}</small></span>`;
    // Rendre le siège occupé aussi draggable (pour déplacer d'un siège à l'autre)
    initSeatDrag(el, studentId);
  } else {
    el.className = 'plan-seat free';
    el.dataset.seatId = seatId;
    el.innerHTML = `<span class="plan-seat-empty">—</span>`;
    el.removeAttribute('draggable');
  }
}

function updateStudentEl(studentId) {
  const el = document.querySelector(`[data-student-id="${studentId}"]`);
  if (!el) return;
  if (studentSeat[studentId]) {
    el.classList.add('placed');
    el.querySelector('.placed-badge') || el.insertAdjacentHTML('beforeend','<span class="placed-badge">✓</span>');
  } else {
    el.classList.remove('placed');
    el.querySelector('.placed-badge')?.remove();
  }
}

function filterStudents(q) {
  const term = q.toLowerCase();
  document.querySelectorAll('.plan-student').forEach(el => {
    el.hidden = !el.dataset.name.includes(term);
  });
}

function clearAll() {
  if (!confirm('Effacer toutes les affectations ?')) return;
  Object.keys(assignments).forEach(sid => { assignments[sid] = null; renderSeat(sid, null); });
  studentSeat = {};
  document.querySelectorAll('.plan-student').forEach(el => {
    el.classList.remove('placed');
    el.querySelector('.placed-badge')?.remove();
  });
}

function savePlan() {
  const data = Object.entries(assignments)
    .filter(([,v]) => v !== null)
    .map(([seat_id, student_id]) => ({ seat_id: parseInt(seat_id), student_id }));

  fetch(`/api/plans/${PLAN_ID}/assignments`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ assignments: data })
  })
  .then(r => r.json())
  .then(d => { if (d.ok) alert('Plan enregistré ✅'); });
}

/* ─────────────────────────────────────────────
   DRAG & DROP — SOURIS (HTML5 natif)
───────────────────────────────────────────── */

// draggedStudentId : ID de l'élève en cours de glissement
let draggedStudentId = null;

// Initialise le drag sur un élément .plan-student (liste sidebar)
function initStudentDrag(el) {
el.addEventListener('dragstart', e => {
  draggedStudentId = parseInt(el.dataset.studentId);
  el.classList.add('dragging');
  document.getElementById('planRoom').classList.add('drag-active'); // ← ajouter
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', draggedStudentId);
});
el.addEventListener('dragend', () => {
  el.classList.remove('dragging');
  document.getElementById('planRoom').classList.remove('drag-active'); // ← ajouter
  draggedStudentId = null;
});
}



// Initialise le drag sur un .plan-seat occupé (pour déplacer d'un siège à l'autre)
function initSeatDrag(el, studentId) {
  el.setAttribute('draggable', 'true');
  // Supprimer les anciens listeners en clonant (simple et propre)
  const clone = el.cloneNode(true);
  el.parentNode.replaceChild(clone, el);
  const fresh = clone;

  // Remettre l'onclick perdu lors du cloneNode
  fresh.addEventListener('click', () => selectSeat(parseInt(fresh.dataset.seatId)));

  fresh.addEventListener('dragstart', e => {
    draggedStudentId = studentId;
    fresh.classList.add('dragging');
    document.getElementById('planRoom').classList.add('drag-active'); // ← ajouter
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', studentId);
  });
  fresh.addEventListener('dragend', () => {
    fresh.classList.remove('dragging');
    document.getElementById('planRoom').classList.remove('drag-active'); // ← ajouter
    draggedStudentId = null;
  });

  // Remettre les drop listeners sur ce siège aussi
  initSeatDrop(fresh);
}

// Initialise le drop sur un .plan-seat
function initSeatDrop(el) {
  el.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    el.classList.add('drag-over');
  });
  el.addEventListener('dragleave', e => {
    // Ne pas déclencher sur les enfants (span internes)
    if (!el.contains(e.relatedTarget)) el.classList.remove('drag-over');
  });
  el.addEventListener('drop', e => {
    e.preventDefault();
    el.classList.remove('drag-over');
    const sId = parseInt(el.dataset.seatId);
    const stuId = parseInt(e.dataTransfer.getData('text/plain'));
    if (!isNaN(stuId) && !isNaN(sId)) {
      assignStudentToSeat(stuId, sId);
    }
  });
}

/* ─────────────────────────────────────────────
   DRAG & DROP — TACTILE (Touch Events)
   Clone visuel qui suit le doigt
───────────────────────────────────────────── */

let touchClone    = null;   // élément fantôme qui suit le doigt
let touchStudId   = null;   // student_id en cours
let touchOffsetX  = 0;
let touchOffsetY  = 0;

function initStudentTouch(el) {
  el.addEventListener('touchstart', e => {
    const t = e.touches[0];
    touchStudId = parseInt(el.dataset.studentId);

    // Créer le clone visuel
    touchClone = el.cloneNode(true);
    const rect = el.getBoundingClientRect();
    touchOffsetX = t.clientX - rect.left;
    touchOffsetY = t.clientY - rect.top;

    Object.assign(touchClone.style, {
      position:       'fixed',
      left:           rect.left + 'px',
      top:            rect.top  + 'px',
      width:          rect.width + 'px',
      opacity:        '0.75',
      pointerEvents:  'none',
      zIndex:         '9999',
      boxShadow:      '0 8px 24px rgba(0,0,0,.2)',
      borderRadius:   'var(--radius-lg)',
      transform:      'scale(1.05)',
      transition:     'none',
    });
    document.body.appendChild(touchClone);
    el.classList.add('dragging');
    document.getElementById('planRoom').classList.add('drag-active');    
  }, { passive: true });

  el.addEventListener('touchmove', e => {
    if (!touchClone) return;
    e.preventDefault();
    const t = e.touches[0];
    touchClone.style.left = (t.clientX - touchOffsetX) + 'px';
    touchClone.style.top  = (t.clientY - touchOffsetY) + 'px';
    highlightSeatUnderTouch(t.clientX, t.clientY);
  }, { passive: false });

  el.addEventListener('touchend', e => {
    if (!touchClone) return;
    const t = e.changedTouches[0];
    cleanupTouch(el);
    dropOnSeatUnderPoint(t.clientX, t.clientY);
    document.getElementById('planRoom').classList.remove('drag-active');
  });

  el.addEventListener('touchcancel', e => {
    cleanupTouch(el);
    clearDragOverAll();
    document.getElementById('planRoom').classList.remove('drag-active');
  });
}

// Idem pour un siège occupé (drag siège → siège)
function initSeatTouch(el, studentId) {
  el.addEventListener('touchstart', e => {
    const t = e.touches[0];
    touchStudId = studentId;
    const rect = el.getBoundingClientRect();
    touchOffsetX = t.clientX - rect.left;
    touchOffsetY = t.clientY - rect.top;

    touchClone = el.cloneNode(true);
    Object.assign(touchClone.style, {
      position:      'fixed',
      left:          rect.left + 'px',
      top:           rect.top  + 'px',
      width:         rect.width + 'px',
      height:        rect.height + 'px',
      opacity:       '0.75',
      pointerEvents: 'none',
      zIndex:        '9999',
      boxShadow:     '0 8px 24px rgba(0,0,0,.25)',
      borderRadius:  'var(--radius-lg)',
      transform:     'scale(1.05)',
      transition:    'none',
    });
    document.body.appendChild(touchClone);
    el.classList.add('dragging');
    document.getElementById('planRoom').classList.add('drag-active');
  }, { passive: true });

  el.addEventListener('touchmove', e => {
    if (!touchClone) return;
    e.preventDefault();
    const t = e.touches[0];
    touchClone.style.left = (t.clientX - touchOffsetX) + 'px';
    touchClone.style.top  = (t.clientY - touchOffsetY) + 'px';
    highlightSeatUnderTouch(t.clientX, t.clientY);
  }, { passive: false });

  el.addEventListener('touchend', e => {
    if (!touchClone) return;
    const t = e.changedTouches[0];
    cleanupTouch(el);
    dropOnSeatUnderPoint(t.clientX, t.clientY);
    document.getElementById('planRoom').classList.remove('drag-active');
  });

  el.addEventListener('touchcancel', e => {
    cleanupTouch(el);
    clearDragOverAll();
    document.getElementById('planRoom').classList.remove('drag-active');
  });
}

function highlightSeatUnderTouch(x, y) {
  clearDragOverAll();
  // Masquer temporairement le clone pour "voir dessous"
  if (touchClone) touchClone.style.display = 'none';
  const target = document.elementFromPoint(x, y)?.closest('.plan-seat:not(.inactive)');
  if (touchClone) touchClone.style.display = '';
  if (target) target.classList.add('drag-over');
}

function dropOnSeatUnderPoint(x, y) {
  if (touchClone) touchClone.style.display = 'none';
  const target = document.elementFromPoint(x, y)?.closest('.plan-seat:not(.inactive)');
  if (touchClone) touchClone.style.display = '';
  clearDragOverAll();

  if (target && touchStudId !== null) {
    const seatId = parseInt(target.dataset.seatId);
    if (!isNaN(seatId)) assignStudentToSeat(touchStudId, seatId);
  }
  touchStudId = null;
}

function cleanupTouch(el) {
  touchClone?.remove();
  touchClone = null;
  el.classList.remove('dragging');
}

function clearDragOverAll() {
  document.querySelectorAll('.plan-seat.drag-over').forEach(s => s.classList.remove('drag-over'));
}

/* ─────────────────────────────────────────────
   INITIALISATION au chargement de la page
───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {

  // Sidebar : élèves draggables (souris + touch)
  document.querySelectorAll('.plan-student').forEach(el => {
    initStudentDrag(el);
    initStudentTouch(el);
  });

  // Sièges : drop targets (souris)
  document.querySelectorAll('.plan-seat:not(.inactive)').forEach(el => {
    initSeatDrop(el);
    // Si le siège est déjà occupé au chargement → aussi draggable
    const seatId  = parseInt(el.dataset.seatId);
    const studId  = assignments[seatId];
    if (studId) {
      initSeatDrag(el, studId);
      initSeatTouch(el, studId);
    }
  });
});
</script>
