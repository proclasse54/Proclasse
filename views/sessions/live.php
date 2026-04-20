<?php
$pageTitle = 'Séance — ' . $session['class_name'] . ' — ProClasse';
// Organiser les sièges en grille
$seatMap = [];
foreach ($seats as $s) { $seatMap[$s['row_index']][$s['col_index']] = $s; }

$grid = [];
for ($r = 0; $r < $session['room_rows']; $r++) {
    for ($c = 0; $c < $session['room_cols']; $c++) {
        $grid[$r][$c] = $seatMap[$r][$c] ?? null; // null = place inactive
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
  <!-- Grille de la salle -->
  <div class="live-room-wrap">
    <div class="room-label-top">Tableau / Bureau du professeur</div>
    <div class="live-room" id="liveRoom" style="--room-cols: <?= $session['room_cols'] ?>">
      <?php foreach ($grid as $rowIdx => $cols): ?>
        <?php foreach ($cols as $colIdx => $seat): ?>
          <?php if ($seat === null): ?>
            <div class="live-seat inactive"></div>  <!-- allée / espace vide -->
          <?php else: ?>
            <div class="live-seat <?= $seat['student_id'] ? 'occupied' : 'empty' ?>"
                data-seat-id="<?= $seat['id'] ?>"
                data-student-id="<?= $seat['student_id'] ?? '' ?>"
                onclick="<?= $seat['student_id'] ? "openTagMenu({$seat['id']}, {$seat['student_id']}, '" . htmlspecialchars(addslashes($seat['last_name'] . ' ' . $seat['first_name'])) . "')" : 'void(0)' ?>">
              <?php if ($seat['student_id']): ?>
                <div class="seat-name"><?= htmlspecialchars($seat['first_name']) ?><br><small><?= htmlspecialchars($seat['last_name']) ?></small></div>
                <div class="seat-tags" id="tags-<?= $seat['student_id'] ?>">
                  <?php foreach ($obsMap[$seat['student_id']] ?? [] as $o): ?>
                  <span class="tag-chip" style="background:<?= htmlspecialchars($o['color'] ?? '#888') ?>"
                        onclick="event.stopPropagation(); removeObs(<?= $o['id'] ?>, <?= $seat['student_id'] ?>)"
                        title="Retirer"><?= $o['icon'] ?? '' ?></span>
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

  <!-- Panneau latéral des tags -->
  <div class="live-sidebar">
    <h3>Tags rapides</h3>
    <div class="tags-list" id="tagsList">
      <?php foreach ($tags as $t): ?>
      <button class="tag-btn" style="--tag-color:<?= $t['color'] ?>"
              data-tag="<?= htmlspecialchars($t['label']) ?>"
              data-icon="<?= htmlspecialchars($t['icon'] ?? '') ?>"
              onclick="selectTag('<?= htmlspecialchars(addslashes($t['label'])) ?>')">
        <?= $t['icon'] ?? '' ?> <?= htmlspecialchars($t['label']) ?>
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
const SESSION_ID = <?= $session['id'] ?>;
let currentStudentId = null;
let currentStudentName = '';

function openTagMenu(seatId, studentId, name) {
  // Déselectionner le précédent
  document.querySelectorAll('.live-seat.selected').forEach(s => s.classList.remove('selected'));
  // Sélectionner le nouveau
  const seatEl = document.querySelector(`[data-seat-id="${seatId}"]`);
  if (seatEl) seatEl.classList.add('selected');
  currentStudentId = studentId;
  currentStudentName = name;
  document.getElementById('selectedName').textContent = name;
  document.getElementById('selectedStudent').hidden = false;
}

// ✅ selectTag — récupérer aussi l'icône
function selectTag(tag) {
  if (!currentStudentId) return;
  const tagBtn = document.querySelector(`.tag-btn[data-tag="${tag}"]`);
  const color  = tagBtn ? getComputedStyle(tagBtn).getPropertyValue('--tag-color').trim() : '#888';
  const icon   = tagBtn ? (tagBtn.dataset.icon ?? '') : ''; // ← ajouter cette ligne

  fetch(`/api/sessions/${SESSION_ID}/observations`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ student_id: currentStudentId, tag: tag })
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) addTagChip(currentStudentId, d.obs_id, tag, color, icon); // ← passer icon
  });
}

// ✅ addTagChip — afficher l'emoji + le label
function addTagChip(studentId, obsId, tag, color = '#888', icon = '') {
  const container = document.getElementById('tags-' + studentId);
  if (!container) return;
  const span = document.createElement('span');
  span.className = 'tag-chip';
  span.style.background = color;
  span.title = 'Retirer';
  span.textContent = (icon ? icon + ' ' : '') + tag; // ← afficher emoji + label
  span.onclick = (e) => { e.stopPropagation(); removeObs(obsId, studentId); span.remove(); };
  container.appendChild(span);
}

function removeObs(obsId, studentId) {
  fetch(`/api/sessions/${SESSION_ID}/observations/${obsId}`, {method:'DELETE'})
    .then(r => r.json()).then(d => { if(d.ok) refreshTags(studentId); });
}

function refreshTags(studentId) {
  fetch(`/api/sessions/${SESSION_ID}/observations`)
    .then(r => r.json())
    .then(obs => {
      const container = document.getElementById('tags-' + studentId);
      if (!container) return;
      const mine = obs.filter(o => o.student_id == studentId);
      container.innerHTML = mine.map(o =>
        `<span class="tag-chip" style="background:${o.color||'#888'}"
               onclick="event.stopPropagation();removeObs(${o.id},${studentId})"
               title="Retirer">${o.icon||''}</span>`
      ).join('');
    });
}
</script>
<?php
$content = ob_get_clean();
// Vue live sans sidebar (plein écran)
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
