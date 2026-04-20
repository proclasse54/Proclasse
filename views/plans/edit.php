<?php
$pageTitle = 'Plan : ' . $plan['name'] . ' — ProClasse';

// Grille des sièges
$grid = [];
foreach ($seats as $s) { $grid[$s['row_index']][$s['col_index']] = $s; }
ksort($grid);
foreach ($grid as &$row) ksort($row);

// Index des affectations existantes : seat_id → student
$assignMap = [];
foreach ($assignments as $a) { $assignMap[$a['seat_id']] = $a; }

ob_start();
?>
<div class="page-header">
  <div>
    <a href="/classes/<?= $plan['class_id'] ?>" class="btn btn-ghost btn-sm">← Retour</a>
    <h1>Plan : <?= htmlspecialchars($plan['name']) ?></h1>
    <p class="text-muted">Cliquez sur un siège puis choisissez un élève</p>
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
const PLAN_ID  = <?= $plan['id'] ?>;
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

function selectSeat(seatId) {
  document.querySelectorAll('.plan-seat').forEach(s => s.classList.remove('selected'));
  const el = document.querySelector(`[data-seat-id="${seatId}"]`);
  if (el) el.classList.add('selected');
  selectedSeatId = seatId;
}

function assignStudent(studentId) {
  if (!selectedSeatId) { alert('Cliquez d\'abord sur un siège.'); return; }

  // Désaffecter l'élève de son ancien siège s'il en avait un
  if (studentSeat[studentId]) {
    const oldSeatId = studentSeat[studentId];
    assignments[oldSeatId] = null;
    renderSeat(oldSeatId, null);
  }

  // Désaffecter l'élève qui était sur ce siège
  const prevStudent = assignments[selectedSeatId];
  if (prevStudent) { delete studentSeat[prevStudent]; updateStudentEl(prevStudent); }

  // Nouvelle affectation
  assignments[selectedSeatId] = studentId;
  studentSeat[studentId] = selectedSeatId;
  renderSeat(selectedSeatId, studentId);
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
    el.innerHTML = `<span class="plan-seat-name">${first}<br><small>${last}</small></span>`;
  } else {
    el.className = 'plan-seat free';
    el.innerHTML = `<span class="plan-seat-empty">—</span>`;
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
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
