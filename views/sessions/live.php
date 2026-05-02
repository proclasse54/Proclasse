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

// ── Séance passée ? (date strictement < aujourd'hui) ──────────────────────────────────────────────
$isPast = strtotime($session['date']) < strtotime(date('Y-m-d'));

// URLs séance précédente / suivante (conserve from_week si présent)
$fromWeek = preg_match('/^\d{4}-W\d{2}$/', $_GET['from_week'] ?? '') ? $_GET['from_week'] : null;
$backUrl  = $fromWeek ? '/sessions?view=week&week=' . htmlspecialchars($fromWeek) : '/sessions';

$prevUrl       = $prevId       ? '/sessions/' . (int)$prevId       . '/live' . ($fromWeek ? '?from_week=' . htmlspecialchars($fromWeek) : '') : null;
$nextUrl       = $nextId       ? '/sessions/' . (int)$nextId       . '/live' . ($fromWeek ? '?from_week=' . htmlspecialchars($fromWeek) : '') : null;
$globalNextUrl = $globalNextId ? '/sessions/' . (int)$globalNextId . '/live' . ($fromWeek ? '?from_week=' . htmlspecialchars($fromWeek) : '') : null;

// Tooltips formatés
function formatNavTooltip(?array $row, string $direction): string {
    if (!$row) return '';
    $label = htmlspecialchars($row['class_name']);
    $date  = date('d/m/Y', strtotime($row['date']));
    $time  = ($row['time_start'] && $row['time_start'] !== '00:00:00')
             ? ' ' . substr($row['time_start'], 0, 5)
             : '';
    $arrow = $direction === 'prev' ? '← ' : '→ ';
    return $arrow . $label . ' · ' . $date . $time;
}
$prevTooltip       = formatNavTooltip($prevRow       ?? null, 'prev');
$nextTooltip       = formatNavTooltip($nextRow       ?? null, 'next');
$globalNextTooltip = formatNavTooltip($globalNextRow ?? null, 'next');

?>
<style>
/* ── Navigation séance précédente / suivante ── */
.live-date-nav {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
}
.live-date-label {
  font-variant-numeric: tabular-nums;
}
.live-nav-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  text-decoration: none;
  transition: background var(--transition), color var(--transition);
  flex-shrink: 0;
  position: relative;
}
a.live-nav-btn:hover {
  background: var(--divider);
  color: var(--primary);
}
.live-nav-btn--disabled {
  opacity: 0.25;
  cursor: default;
  pointer-events: none;
}
/* Tooltip CSS natif enrichi */
a.live-nav-btn::after {
  content: attr(title);
  position: absolute;
  top: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  background: var(--color-text, #28251d);
  color: var(--color-text-inverse, #f9f8f4);
  font-size: 0.72rem;
  line-height: 1.4;
  white-space: nowrap;
  padding: 4px 8px;
  border-radius: 4px;
  pointer-events: none;
  opacity: 0;
  transition: opacity 150ms ease;
  z-index: 100;
}
a.live-nav-btn:hover::after,
a.live-nav-btn:focus-visible::after {
  opacity: 1;
}

/* ── Nom du plan (discret, italique) ── */
.live-title-plan {
  font-style: italic;
  color: var(--color-text-muted);
  font-size: var(--text-sm);
}

/* ── Badge lecture seule ── */
.badge-past {
  background: var(--color-warning-highlight, #ddcfc6);
  color: var(--color-warning, #964219);
  font-size: var(--text-xs);
  border-radius: var(--radius-full);
  padding: 2px 8px;
  font-weight: 600;
  vertical-align: middle;
}

/* ── Bouton Supprimer la séance ── */
.btn-delete-session {
  margin-left: var(--space-2);
  flex-shrink: 0;
}

/* ── Bandeau séance passée ── */
.past-session-banner {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  background: var(--color-warning-highlight, #ddcfc6);
  color: var(--color-warning, #964219);
  border-bottom: 1px solid oklch(from var(--color-warning, #964219) l c h / 0.2);
  padding: var(--space-2) var(--space-4);
  font-size: var(--text-sm);
}

/* ── Plan de salle lecture seule ── */
.live-room--readonly .live-seat.occupied {
  cursor: default;
  opacity: 0.88;
}
.live-room--readonly .live-seat.occupied:active {
  transform: none;
}

/* ── Toast session expirée ── */
.session-expired-toast {
  position: fixed;
  bottom: var(--space-6);
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: var(--space-4);
  background: var(--color-surface, #fff);
  border: 1.5px solid var(--color-warning, #964219);
  border-radius: var(--radius-lg);
  box-shadow: 0 8px 32px oklch(0.2 0.02 60 / 0.18);
  padding: var(--space-4) var(--space-5);
  z-index: 10000;
  max-width: min(480px, calc(100vw - var(--space-8)));
  animation: toastIn 250ms cubic-bezier(0.16, 1, 0.3, 1) both;
}
/* CORRECTIF : [hidden] doit l'emporter sur display:flex */
.session-expired-toast[hidden] {
  display: none !important;
}
@keyframes toastIn {
  from { opacity: 0; transform: translateX(-50%) translateY(12px); }
  to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}
.session-expired-icon {
  font-size: 1.5rem;
  flex-shrink: 0;
}
.session-expired-body {
  flex: 1;
  min-width: 0;
}
.session-expired-body strong {
  display: block;
  color: var(--color-warning, #964219);
  font-size: var(--text-sm);
  margin-bottom: var(--space-1);
}
.session-expired-body p {
  margin: 0;
  font-size: var(--text-xs);
  color: var(--color-text-muted);
}
/* Plan de salle grisé quand session expirée */
.live-room.session-expired {
  opacity: 0.45;
  pointer-events: none;
  user-select: none;
  filter: grayscale(0.4);
  transition: opacity 300ms ease, filter 300ms ease;
}
.skipped-list {
  list-style: none;
  margin: var(--space-3) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  max-height: 260px;
  overflow-y: auto;
}
.skipped-list li {
  background: var(--color-warning-highlight, #ddcfc6);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  font-size: var(--text-sm);
  color: var(--color-text);
}

/* ── Variante danger pour scope-btn (modale suppression) ── */
.scope-btn--danger {
  border-color: var(--color-error, #a12c7b);
  color: var(--color-error, #a12c7b);
}
.scope-btn--danger:hover {
  background: var(--color-error-highlight, #e0ced7);
}

/* ── Crop : zone canvas ── */
.crop-area {
  position: relative;
  display: inline-block;
  line-height: 0;
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-md);
  max-width: 100%;
  cursor: crosshair;
  user-select: none;
  touch-action: none;
}
.crop-area canvas {
  display: block;
  max-width: 100%;
}
/* Overlay de sélection crop */
.crop-selection {
  position: absolute;
  border: 2px solid var(--color-primary, #01696f);
  box-shadow: 0 0 0 9999px oklch(0 0 0 / 0.45);
  box-sizing: border-box;
  cursor: move;
  border-radius: 2px;
}
/* Poignées de redimensionnement */
.crop-handle {
  position: absolute;
  width: 12px;
  height: 12px;
  background: var(--color-primary, #01696f);
  border: 2px solid #fff;
  border-radius: 2px;
}
.crop-handle--tl { top: -6px;  left: -6px;  cursor: nw-resize; }
.crop-handle--tr { top: -6px;  right: -6px; cursor: ne-resize; }
.crop-handle--bl { bottom: -6px; left: -6px; cursor: sw-resize; }
.crop-handle--br { bottom: -6px; right: -6px; cursor: se-resize; }
/* Zone photo + actions */
.modal-photo-zone {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) 0;
}
.modal-photo-actions {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
  justify-content: center;
}
.modal-photo-empty {
  width: 100px;
  height: 100px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-surface-offset);
  border-radius: var(--radius-md);
  color: var(--color-text-muted);
  font-size: var(--text-sm);
}
</style>

<div class="live-header">

  <!-- ── Zone gauche : retour + identité de la séance ── -->
  <div class="live-header-left">
    <a href="<?= $backUrl ?>" class="btn btn-ghost btn-sm live-back-btn" title="Retour aux séances">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
      Séances
    </a>

    <div class="live-identity">
      <span class="live-identity-class"><?= htmlspecialchars($session['class_name']) ?></span>

      <?php if (!empty($session['room_name'])): ?>
        <span class="live-identity-sep" aria-hidden="true">·</span>
        <span class="live-identity-room"><?= htmlspecialchars($session['room_name']) ?></span>
      <?php endif; ?>

      <?php if (!empty($session['plan_name'])): ?>
        <span class="live-identity-sep" aria-hidden="true">·</span>
        <span class="live-identity-plan" title="Plan de salle"><?= htmlspecialchars($session['plan_name']) ?></span>
      <?php endif; ?>

      <?php if (!empty($session['subject'])): ?>
        <span class="live-identity-sep" aria-hidden="true">·</span>
        <span class="badge"><?= htmlspecialchars($session['subject']) ?></span>
      <?php endif; ?>

      <?php if ($isPast): ?>
        <span class="badge badge-past" aria-label="Séance passée – lecture seule">🔒 Lecture seule</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Zone droite : navigation + actions ── -->
  <div class="live-header-right">

    <!-- Groupe 1 : navigation même classe (← date →) -->
    <div class="live-nav-group" title="Navigation dans la classe <?= htmlspecialchars($session['class_name']) ?>">

      <?php if ($prevUrl): ?>
        <a href="<?= $prevUrl ?>" class="live-nav-btn" title="<?= $prevTooltip ?>" aria-label="Séance précédente (<?= htmlspecialchars($session['class_name']) ?>)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
      <?php else: ?>
        <span class="live-nav-btn live-nav-btn--disabled" aria-hidden="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </span>
      <?php endif; ?>

      <span class="live-nav-date">
        <span class="live-nav-class-badge"><?= htmlspecialchars($session['class_name']) ?></span>
        <?= date('d/m/Y', strtotime($session['date'])) ?>
        <?php if (!empty($session['time_start']) && $session['time_start'] !== '00:00:00'): ?>
          <span class="live-nav-time"><?= substr($session['time_start'], 0, 5) ?></span>
        <?php endif; ?>
      </span>

      <?php if ($nextUrl): ?>
        <a href="<?= $nextUrl ?>" class="live-nav-btn" title="<?= $nextTooltip ?>" aria-label="Séance suivante (<?= htmlspecialchars($session['class_name']) ?>)">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      <?php else: ?>
        <span class="live-nav-btn live-nav-btn--disabled" aria-hidden="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
      <?php endif; ?>
    </div><!-- /.live-nav-group -->

    <!-- Groupe 2 : séance globale suivante (toutes classes) -->
    <?php if ($globalNextUrl): ?>
      <div class="live-nav-divider" aria-hidden="true"></div>
      <a href="<?= $globalNextUrl ?>" class="live-nav-global-next btn btn-ghost btn-sm"
         title="<?= $globalNextTooltip ?>"
         aria-label="Prochaine séance planning : <?= $globalNextTooltip ?>">
        Séance suivante
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php endif; ?>

    <!-- Séparateur avant actions -->
    <div class="live-nav-divider" aria-hidden="true"></div>

    <!-- Bouton Supprimer -->
    <button type="button" id="btnDeleteSession" class="btn btn-danger btn-sm btn-delete-session" title="Supprimer cette séance">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
      Supprimer
    </button>

  </div><!-- /.live-header-right -->
</div><!-- /.live-header -->

<?php if ($isPast): ?>
<!-- ================================================
     BANDEAU SÉANCE PASSÉE
     ================================================ -->
<div class="past-session-banner" role="note" aria-live="polite">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  Séance passée — le plan de salle est en <strong>lecture seule</strong>. Les tags restent modifiables.
</div>
<?php endif; ?>

<!-- ================================================
     TOAST SESSION EXPIRÉE
     ================================================ -->
<div id="sessionExpiredToast" class="session-expired-toast" hidden role="alert" aria-live="assertive">
  <div class="session-expired-icon">&#128274;</div>
  <div class="session-expired-body">
    <strong>Session expirée</strong>
    <p>Vous avez été déconnecté. Vos modifications ne sont plus enregistrées.</p>
  </div>
  <a href="/login?redirect=<?= urlencode('/sessions/' . (int)$session['id'] . '/live') ?>" class="btn btn-primary btn-sm">Se reconnecter</a>
</div>

<div class="live-layout">
  <div class="live-room-wrap">
    <div class="room-label-top">Tableau / Bureau du professeur</div>
    <div class="live-room <?= $isPast ? 'live-room--readonly' : '' ?>" id="liveRoom" style="--room-cols: <?= $session['room_cols'] ?>">
      <?php foreach ($grid as $rowIdx => $cols): ?>
        <?php foreach ($cols as $colIdx => $seat): ?>
          <?php if ($seat === null): ?>
            <div class="live-seat inactive"></div>
          <?php else: ?>
            <div class="live-seat <?= $seat['student_id'] ? 'occupied' : 'empty' ?>"
                 data-seat-id="<?= $seat['id'] ?>"
                 data-student-id="<?= $seat['student_id'] ?? '' ?>"
                 data-student-name="<?= $seat['student_id'] ? htmlspecialchars($seat['last_name'] . ' ' . $seat['first_name'], ENT_QUOTES) : '' ?>"
                 <?= ($seat['student_id'] && !$isPast) ? 'draggable="true"' : '' ?>>
              <?php if ($seat['student_id']): ?>
                <?php
                  $photoUrl = $seat['student_id'] ? '/photo?student_id=' . (int)$seat['student_id'] : null;
                ?>
                <div class="seat-photo-wrapper">
                  <img src="<?= htmlspecialchars($photoUrl) ?>"
                      alt="<?= htmlspecialchars($seat['first_name'] . ' ' . $seat['last_name']) ?>"
                      class="seat-photo" loading="lazy">
                  <div class="seat-photo-placeholder" style="display:none;">
                    <?= htmlspecialchars(mb_strtoupper(mb_substr($seat['first_name'], 0, 1) . mb_substr($seat['last_name'], 0, 1))) ?>
                  </div>
                </div>

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
                <div class="seat-empty-label">&mdash;</div>
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

<!-- ================================================
     MODALE SCOPE : session / futures / toutes
     ================================================ -->
<div id="scopeModal" class="scope-modal" role="dialog" aria-modal="true" aria-labelledby="scopeModalTitle" hidden>
  <div class="scope-modal-box">
    <p class="scope-modal-title" id="scopeModalTitle">
      Déplacer <strong id="scopeStudentName"></strong>
    </p>
    <p class="scope-modal-subtitle">Ce déplacement doit-il affecter :</p>
    <div class="scope-modal-btns">
      <button id="scopeBtnSession" class="scope-btn" type="button">
        <span class="scope-btn-icon">📅</span>
        <span class="scope-btn-label">Cette séance uniquement</span>
        <span class="scope-btn-hint">Les autres séances ne sont pas modifiées</span>
      </button>
      <button id="scopeBtnForward" class="scope-btn scope-btn--primary" type="button">
        <span class="scope-btn-icon">⏩</span>
        <span class="scope-btn-label">Cette séance + les suivantes</span>
        <span class="scope-btn-hint">Les séances passées ne sont jamais modifiées</span>
      </button>
    </div>
    <button id="scopeBtnCancel" class="scope-cancel-btn" type="button">✕ Annuler</button>
  </div>
</div>

<div id="skippedModal" class="scope-modal" role="dialog" aria-modal="true" aria-labelledby="skippedModalTitle" hidden>
  <div class="scope-modal-box">
    <p class="scope-modal-title" id="skippedModalTitle">⚠️ Propagation partielle</p>
    <p class="scope-modal-subtitle">
      Le déplacement n'a pas pu être appliqué sur certaines séances car
      les élèves n'étaient pas aux places attendues :
    </p>
    <ul id="skippedList" class="skipped-list"></ul>
    <button id="skippedClose" class="scope-btn scope-btn--primary" type="button" style="margin-top:var(--space-4)">
      Compris
    </button>
  </div>
</div>

<!-- ================================================
     MODALE CONFIRMATION SUPPRESSION SÉANCE
     ================================================ -->
<div id="deleteSessionModal" class="scope-modal" role="dialog" aria-modal="true" aria-labelledby="deleteSessionModalTitle" hidden>
  <div class="scope-modal-box">
    <p class="scope-modal-title" id="deleteSessionModalTitle">🗑 Supprimer la séance</p>
    <p class="scope-modal-subtitle">
      Êtes-vous sûr de vouloir supprimer la séance du
      <strong><?= date('d/m/Y', strtotime($session['date'])) ?></strong>
      pour la classe <strong><?= htmlspecialchars($session['class_name']) ?></strong> ?<br>
      <span style="color:var(--color-error);font-size:var(--text-sm)">
        Cette action supprimera aussi toutes les observations enregistrées. Elle est irréversible.
      </span>
    </p>
    <div class="scope-modal-btns">
      <button id="deleteSessionConfirm" class="scope-btn scope-btn--danger" type="button">
        <span class="scope-btn-icon">🗑</span>
        <span class="scope-btn-label">Oui, supprimer définitivement</span>
      </button>
    </div>
    <button id="deleteSessionCancel" class="scope-cancel-btn" type="button">✕ Annuler</button>
  </div>
</div>

<script>
const SESSION_ID     = <?= (int)$session['id'] ?>;
const IS_PAST_SESSION = <?= $isPast ? 'true' : 'false' ?>; // séance passée → lecture seule
let currentStudentId = null;
let currentStudentName = '';

const liveRoom = document.getElementById('liveRoom');
const tagsList = document.getElementById('tagsList');

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
  el.innerHTML = '<div class="seat-empty-label">&mdash;</div>';
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

// --------------------------------------------------
// SESSION EXPIRÉE : détection + toast
// --------------------------------------------------
let _sessionExpired = false;

function showSessionExpiredToast() {
  if (_sessionExpired) return;
  _sessionExpired = true;
  document.getElementById('sessionExpiredToast').hidden = false;
  liveRoom.classList.add('session-expired');
}

/**
 * Wrapper fetch() sûr pour les API JSON de ProClasse.
 *
 * Détection session expirée :
 *  1. Statut 401 → toast (authentification refusée)
 *  2. Statut 403 → on tente de lire le JSON :
 *     - { expired: true }  → toast session expirée
 *     - { error: '...' }   → erreur métier, on relance avec le message
 *     - JSON illisible     → toast (probable page HTML de login)
 *  3. Statut non-2xx + body non parseable en JSON → toast
 *  4. Statut 2xx mais body non parseable → erreur technique normale
 */
async function apiFetch(url, options = {}) {
  let r;
  try {
    r = await fetch(url, options);
  } catch (networkErr) {
    throw networkErr; // perte réseau, pas une déconnexion
  }

  // 401 = session expirée certaine
  if (r.status === 401) {
    showSessionExpiredToast();
    throw new Error('Session expirée');
  }

  // Type de réponse
  const contentType = r.headers.get('content-type') || '';
  const isJson = contentType.includes('application/json');

  // Lecture du body sans supposer qu'il soit toujours en JSON
  let body = null;
  if (isJson) {
    try {
      body = await r.json();
    } catch (_) {
      body = null;
    }
  } else {
    try {
      body = await r.text();
    } catch (_) {
      body = null;
    }
  }

  // 403 = session expirée uniquement si le backend le dit explicitement
  if (r.status === 403) {
    if (isJson && body && body.expired === true) {
      showSessionExpiredToast();
      throw new Error('Session expirée');
    }
    throw new Error(isJson && body && body.error ? body.error : 'Action non autorisée');
  }

  // Autres erreurs HTTP : ne pas les confondre avec une session expirée
  if (!r.ok) {
    if (isJson && body && body.expired === true) {
      showSessionExpiredToast();
      throw new Error('Session expirée');
    }
    if (isJson && body && body.error) {
      throw new Error(body.error);
    }
    throw new Error(`Erreur HTTP ${r.status}`);
  }

  // Succès HTTP mais réponse non JSON
  if (!isJson) {
    throw new Error(`Réponse invalide du serveur (${r.status})`);
  }

  // Succès HTTP mais JSON illisible
  if (body === null) {
    throw new Error(`Réponse JSON invalide du serveur (${r.status})`);
  }

  return body;
}

// --------------------------------------------------
// API
// --------------------------------------------------
function selectTag(tag, icon = '', color = '#888') {
  if (!currentStudentId) return;

  apiFetch(`/api/sessions/${SESSION_ID}/observations`, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ student_id: currentStudentId, tag })
  })
  .then(d => {
    if (d.ok) addTagChip(currentStudentId, d.obs_id, tag, color, icon);
  })
  .catch(() => {});
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
  apiFetch(`/api/sessions/${SESSION_ID}/observations/${obsId}`, { method: 'DELETE' })
    .then(d => {
      if (!d.ok) return;
      if (chipEl) {
        chipEl.remove();
      } else {
        refreshTags(studentId);
      }
    })
    .catch(() => {});
}

function refreshTags(studentId) {
  apiFetch(`/api/sessions/${SESSION_ID}/observations`)
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
    })
    .catch(() => {});
}

// ──────────────────────────────────────────────
// MODALE SCOPE (2 boutons : session / forward)
// ──────────────────────────────────────────────
const scopeModal   = document.getElementById('scopeModal');
const skippedModal = document.getElementById('skippedModal');
const scopeNameEl  = document.getElementById('scopeStudentName');
let _scopeResolve  = null;

function scopeOpen(studentName, isSwap) {
  scopeNameEl.textContent = studentName;
  const subtitle = scopeModal.querySelector('.scope-modal-subtitle');
  if (subtitle) {
    subtitle.textContent = isSwap
      ? 'Cette permutation doit-elle affecter :'
      : 'Ce déplacement doit-il affecter :';
  }
  scopeModal.hidden = false;
  document.getElementById('scopeBtnSession').focus();
}
function scopeClose() { scopeModal.hidden = true; }

function askScope(studentName, isSwap = false) {
  if (_sessionExpired) return Promise.resolve(null);
  return new Promise(resolve => {
    _scopeResolve = resolve;
    scopeOpen(studentName, isSwap);
  });
}
function scopeResolve(value) {
  scopeClose();
  if (_scopeResolve) { _scopeResolve(value); _scopeResolve = null; }
}

document.getElementById('scopeBtnSession').addEventListener('click', () => scopeResolve('session'));
document.getElementById('scopeBtnForward').addEventListener('click', () => scopeResolve('forward'));
document.getElementById('scopeBtnCancel').addEventListener('click',  () => scopeResolve(null));

scopeModal.addEventListener('click', e => { if (e.target === scopeModal) scopeResolve(null); });

// Escape : gère toutes les modales (scope, skipped, suppression)
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (!scopeModal.hidden)         { e.stopImmediatePropagation(); scopeResolve(null); }
    if (!skippedModal.hidden)       { e.stopImmediatePropagation(); skippedModal.hidden = true; }
    if (!deleteSessionModal.hidden) { e.stopImmediatePropagation(); deleteSessionModal.hidden = true; }
  }
});

// ── Modal avertissement séances ignorées ──
function showSkippedWarning(skipped) {
  const list = document.getElementById('skippedList');
  list.innerHTML = skipped.map(s => {
    const d = new Date(s.date);
    const dateStr = d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
    const timeStr = s.time ? ' ' + s.time.substring(0, 5) : '';
    return `<li><strong>${dateStr}${timeStr}</strong> — ${s.reason}</li>`;
  }).join('');
  skippedModal.hidden = false;
  document.getElementById('skippedClose').focus();
}
document.getElementById('skippedClose').addEventListener('click', () => { skippedModal.hidden = true; });
skippedModal.addEventListener('click', e => { if (e.target === skippedModal) skippedModal.hidden = true; });

// ──────────────────────────────────────────────
// API persistMove (scope: 'session' | 'forward')
// ──────────────────────────────────────────────
async function persistMove(studentId, sourceSeatId, targetSeatId, scope) {
  return apiFetch(`/api/sessions/${SESSION_ID}/move-seat`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({
      student_id:    studentId,
      source_seat_id: sourceSeatId,
      target_seat_id: targetSeatId,
      scope:          scope,
    }),
  });
}

// ──────────────────────────────────────────────
// moveSeat : swap ou déplacement vers place vide
// ──────────────────────────────────────────────
async function moveSeat(studentId, targetSeatId) {
  // Séance passée → aucun déplacement autorisé
  if (IS_PAST_SESSION || _sessionExpired) return;

  const sourceSeatId = parseInt(
    Object.keys(seatStudentMap).find(k => seatStudentMap[k] === studentId)
  );
  if (isNaN(sourceSeatId) || sourceSeatId === targetSeatId) return;

  const srcEl = getSeatEl(sourceSeatId);
  const tgtEl = getSeatEl(targetSeatId);
  if (!srcEl || !tgtEl) return;

  const targetStudentId = seatStudentMap[targetSeatId] != null
    ? parseInt(seatStudentMap[targetSeatId])
    : null;

  const isSwap    = targetStudentId !== null;
  const srcName   = srcEl.dataset.studentName || 'l\'élève';
  const scope     = await askScope(srcName, isSwap);
  if (!scope) return;

  // Optimistic UI
  const srcPayload = seatMarkupFromData(srcEl);
  const tgtPayload = seatMarkupFromData(tgtEl);
  setSeatOccupied(tgtEl, srcPayload);
  if (isSwap) setSeatOccupied(srcEl, tgtPayload); else setSeatEmpty(srcEl);
  seatStudentMap[targetSeatId] = studentId ? parseInt(studentId) : null;
  seatStudentMap[sourceSeatId] = isSwap ? parseInt(targetStudentId) : null;
  clearSelection();

  try {
    const result = await persistMove(studentId, sourceSeatId, targetSeatId, scope);
    if (!result.ok) throw new Error(result.error || 'Erreur inconnue');

    if (result.skipped_sessions && result.skipped_sessions.length > 0) {
      showSkippedWarning(result.skipped_sessions);
    }
  } catch (e) {
    if (_sessionExpired) return;
    // Rollback optimistic UI
    if (srcPayload.occupied) setSeatOccupied(srcEl, srcPayload); else setSeatEmpty(srcEl);
    if (tgtPayload.occupied) setSeatOccupied(tgtEl, tgtPayload); else setSeatEmpty(tgtEl);
    seatStudentMap[sourceSeatId] = srcPayload.studentId ? parseInt(srcPayload.studentId) : null;
    seatStudentMap[targetSeatId] = tgtPayload.studentId ? parseInt(tgtPayload.studentId) : null;
    alert('Déplacement non enregistré.\n\nDétail : ' + e.message);
  }
}

// --------------------------------------------------
// Événements UI
// --------------------------------------------------

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

liveRoom.addEventListener('click', e => {
  const chip = e.target.closest('.tag-chip');
  if (!chip) return;
  e.stopPropagation();
  removeObs(parseInt(chip.dataset.obsId), parseInt(chip.dataset.studentId), chip);
});

tagsList.addEventListener('click', e => {
  const btn = e.target.closest('.tag-btn');
  if (!btn) return;
  selectTag(btn.dataset.tag, btn.dataset.icon, btn.dataset.color);
});

// Drag souris — désactivé si séance passée
let draggedStudentId = null;

liveRoom.addEventListener('dragstart', e => {
  if (IS_PAST_SESSION || _sessionExpired) { e.preventDefault(); return; }
  const seat = e.target.closest('.live-seat.occupied');
  if (!seat) { e.preventDefault(); return; }

  const studentId = parseInt(seat.dataset.studentId);
  if (!studentId) { e.preventDefault(); return; }

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
  if (IS_PAST_SESSION || _sessionExpired) return;
  const seat = e.target.closest('.live-seat:not(.inactive)');
  if (!seat) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  seat.classList.add('drag-over');
});

liveRoom.addEventListener('dragleave', e => {
  const seat = e.target.closest('.live-seat');
  if (seat && !seat.contains(e.relatedTarget)) seat.classList.remove('drag-over');
});

liveRoom.addEventListener('drop', e => {
  if (IS_PAST_SESSION || _sessionExpired) return;
  const seat = e.target.closest('.live-seat:not(.inactive)');
  if (!seat) return;

  e.preventDefault();
  seat.classList.remove('drag-over');
  liveRoom.classList.remove('drag-active');

  const studentId    = parseInt(e.dataTransfer.getData('text/plain'));
  const targetSeatId = parseInt(seat.dataset.seatId);

  if (!isNaN(studentId) && !isNaN(targetSeatId)) {
    seat._dragJustHappened = true;
    moveSeat(studentId, targetSeatId);
  }
});

// Tactile — désactivée si séance passée
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
  if (IS_PAST_SESSION || _sessionExpired) return;
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
  touchClone.style.top  = (t.clientY - touchOffY) + 'px';

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

    if (!IS_PAST_SESSION && !_sessionExpired && target && target !== touchSrcEl && touchStudId !== null) {
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
  if (touchClone) { touchClone.remove(); touchClone = null; }
  if (touchSrcEl) touchSrcEl.classList.remove('dragging');
  liveRoom.classList.remove('drag-active');
  liveRoom.querySelectorAll('.drag-over').forEach(s => s.classList.remove('drag-over'));
  touchSrcEl = null;
  touchStudId = null;
  touchIsDrag = false;
});

// ──────────────────────────────────────────────
// SUPPRESSION DE LA SÉANCE
// ──────────────────────────────────────────────
const deleteSessionModal   = document.getElementById('deleteSessionModal');
const btnDeleteSession     = document.getElementById('btnDeleteSession');
const deleteSessionCancel  = document.getElementById('deleteSessionCancel');
const deleteSessionConfirm = document.getElementById('deleteSessionConfirm');

btnDeleteSession.addEventListener('click', () => {
  deleteSessionModal.hidden = false;
  deleteSessionCancel.focus();
});

deleteSessionCancel.addEventListener('click', () => {
  deleteSessionModal.hidden = true;
});

deleteSessionModal.addEventListener('click', e => {
  if (e.target === deleteSessionModal) deleteSessionModal.hidden = true;
});

deleteSessionConfirm.addEventListener('click', () => {
  deleteSessionConfirm.disabled = true;
  deleteSessionConfirm.querySelector('.scope-btn-label').textContent = 'Suppression…';

  apiFetch(`/api/sessions/${SESSION_ID}`, { method: 'DELETE' })
    .then(d => {
      if (d.ok) {
        window.location.href = '<?= htmlspecialchars($backUrl) ?>';
      } else {
        alert('Erreur : ' + (d.error || 'Suppression échouée'));
        deleteSessionConfirm.disabled = false;
        deleteSessionConfirm.querySelector('.scope-btn-label').textContent = 'Oui, supprimer définitivement';
      }
    })
    .catch(() => {
      deleteSessionConfirm.disabled = false;
      deleteSessionConfirm.querySelector('.scope-btn-label').textContent = 'Oui, supprimer définitivement';
    });
});
</script>


<!-- Modale infos élève -->
<div id="studentModal" class="student-modal-overlay" hidden
     aria-modal="true" role="dialog" aria-labelledby="modalStudentName">
  <div class="student-modal">
    <button class="student-modal-close" id="modalClose" aria-label="Fermer">&#x2715;</button>
    <div class="student-modal-header">
      <div class="student-modal-avatar" id="modalAvatar"></div>
      <div>
        <div class="student-modal-name" id="modalStudentName"></div>
        <div class="student-modal-class" id="modalClass"></div>
      </div>
    </div>
    <!-- Onglets -->
    <div class="student-modal-tabs">
      <button class="student-modal-tab active" data-tab="donnees">Données</button>
      <button class="student-modal-tab" data-tab="photo">Photo</button>
    </div>    
    <!-- Onglet Données -->
    <div class="student-modal-body" id="modalBody" data-panel="donnees">
      <div class="student-modal-loading">Chargement&hellip;</div>
    </div>
    <!-- Onglet Photo : upload + recadrage interactif -->
    <div class="student-modal-body" id="modalPhotoPanel" data-panel="photo" hidden>
      <div class="modal-photo-zone">

        <!-- Prévisualisation photo actuelle (hors mode crop) -->
        <div id="modalPhotoPreview"></div>

        <!-- Zone de recadrage (masquée par défaut, affichée après sélection d'un fichier ou clic sur Modifier) -->
        <div id="cropContainer" style="display:none; width:100%; text-align:center;">
          <div class="crop-area" id="cropArea">
            <!-- Le canvas recevra l'image source -->
            <canvas id="cropCanvas"></canvas>
            <!-- La sélection de recadrage (overlay CSS) -->
            <div class="crop-selection" id="cropSelection">
              <div class="crop-handle crop-handle--tl" data-handle="tl"></div>
              <div class="crop-handle crop-handle--tr" data-handle="tr"></div>
              <div class="crop-handle crop-handle--bl" data-handle="bl"></div>
              <div class="crop-handle crop-handle--br" data-handle="br"></div>
            </div>
          </div>
          <!-- Boutons d'action du crop -->
          <div class="modal-photo-actions" style="margin-top:var(--space-3);">
            <button class="btn btn-primary btn-sm" id="cropSaveBtn">✓ Recadrer &amp; enregistrer</button>
            <button class="btn btn-ghost btn-sm" id="cropCancelBtn">✕ Annuler</button>
          </div>
        </div>

        <!-- Boutons principaux (hors mode crop) — reconstruits dynamiquement par renderPhotoTab() -->
        <div class="modal-photo-actions" id="photoMainActions"></div>

        <!-- Input fichier (caché, déclenché programmatiquement) -->
        <input type="file" id="modalPhotoInput" accept="image/*" style="display:none;">

        <p class="form-hint" id="modalPhotoHint">Formats : JPG, PNG, WEBP. Max 2 Mo.</p>
      </div>
    </div>
    <div class="student-modal-footer">
      <button class="btn btn-danger btn-sm" id="modalRemoveBtn">
        &#128465; Retirer du plan de salle
      </button>
    </div>
  </div>
</div>

<script>
// NOTE : SESSION_ID et seatStudentMap sont déjà définis dans le bloc <script> précédent.
window.seatStudentMap  = seatStudentMap;

// ──────────────────────────────────────────────
// MODALE FICHE ÉLÈVE (double-clic sur vignette)
// ──────────────────────────────────────────────
const studentModal  = document.getElementById('studentModal');
const modalClose    = document.getElementById('modalClose');
const modalAvatar   = document.getElementById('modalAvatar');
const modalName     = document.getElementById('modalStudentName');
const modalClass    = document.getElementById('modalClass');
const modalBody     = document.getElementById('modalBody');
const modalRemoveBtn = document.getElementById('modalRemoveBtn');
const modalPhotoPanel     = document.getElementById('modalPhotoPanel');
const modalPhotoInput     = document.getElementById('modalPhotoInput');
const modalPhotoPreview   = document.getElementById('modalPhotoPreview');
const modalPhotoHint      = document.getElementById('modalPhotoHint');
const photoMainActions    = document.getElementById('photoMainActions');

// ──────────────────────────────────────────────
// CROP : état et références DOM
// ──────────────────────────────────────────────
const cropContainer  = document.getElementById('cropContainer');
const cropArea       = document.getElementById('cropArea');
const cropCanvas     = document.getElementById('cropCanvas');
const cropSelection  = document.getElementById('cropSelection');
const cropSaveBtn    = document.getElementById('cropSaveBtn');
const cropCancelBtn  = document.getElementById('cropCancelBtn');

// Image source chargée dans le canvas
let _cropImage   = null;   // HTMLImageElement
let _cropScale   = 1;      // facteur d'échelle affichage / réel

// Position + taille de la sélection en pixels canvas (coordonnées affichées)
let _sel = { x: 0, y: 0, w: 0, h: 0 };

// État de l'interaction
let _dragMode   = null;    // 'move' | 'tl' | 'tr' | 'bl' | 'br' | null
let _dragStart  = { mx: 0, my: 0, sx: 0, sy: 0, sw: 0, sh: 0 };

// Taille max du canvas affiché dans la modale
const CANVAS_MAX_W = 420;
const CANVAS_MAX_H = 340;

/**
 * Charge une image (File) dans le canvas de crop et initialise la sélection.
 * Nouvelle photo uploadée : pas de crop existant en BDD — on place le carré par défaut.
 * @param {File} file
 */
function initCrop(file) {
  const reader = new FileReader();
  reader.onload = ev => {
    const img = new Image();
    img.onload = () => {
      // Nouvelle photo : pas de crop existant en BDD — on place le carré par défaut
      _loadImageIntoCrop(img, null);
    };
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
}

/**
 * Charge la photo ORIGINALE (sans recadrage) d'un élève dans le canvas de crop.
 * Récupère simultanément le crop BDD pour pré-positionner le cadre sur le
 * recadrage déjà enregistré, permettant à l'utilisateur de le visualiser et
 * de l'ajuster sur l'image complète.
 * @param {number} studentId
 */
function startCropFromExistingPhoto(studentId) {
  modalPhotoHint.textContent = 'Chargement de la photo…';

  // Chargement en parallèle : image ORIGINALE + paramètres crop BDD
  const imgPromise = new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous'; // nécessaire pour toDataURL sur le canvas
    img.onload  = () => resolve(img);
    img.onerror = () => reject(new Error('Impossible de charger la photo existante.'));
    // On charge la photo ORIGINALE (sans recadrage) via ?original=1
    // pour que le canvas affiche l'image complète et que le cadre de sélection
    // représente fidèlement la zone recadrée enregistrée en BDD.
    img.src = '/photo?student_id=' + studentId + '&original=1&t=' + Date.now();
  });

  const cropPromise = apiFetch('/api/students/' + studentId + '/photo-crop')
    .catch(() => null); // En cas d'erreur API on continue sans crop pré-positionné

  Promise.all([imgPromise, cropPromise])
    .then(([img, cropData]) => {
      _loadImageIntoCrop(img, cropData);
    })
    .catch(err => {
      modalPhotoHint.textContent = err.message || 'Impossible de charger la photo existante.';
    });
}

/**
 * Logique commune : prend un HTMLImageElement déjà chargé,
 * l'affiche dans le canvas et initialise la sélection de crop.
 *
 * Si cropData est fourni (résultat de GET /api/students/{id}/photo-crop),
 * la sélection est pré-positionnée sur le recadrage existant.
 * Sinon, on place un carré centré de 80% de la dimension minimale.
 *
 * CORRECTIF POSITIONNEMENT : cropContainer est rendu visible AVANT d'appeler
 * renderSelection(), puis renderSelection() est différé via requestAnimationFrame
 * pour que le navigateur ait calculé le layout et que cropCanvas.offsetWidth
 * retourne les dimensions CSS réelles (et non 0).
 *
 * @param {HTMLImageElement} img
 * @param {object|null} cropData  { crop_x, crop_y, crop_w, crop_h } ou null
 */
function _loadImageIntoCrop(img, cropData) {
  _cropImage = img;

  // Calculer l'échelle pour faire tenir l'image dans CANVAS_MAX
  _cropScale = Math.min(1, CANVAS_MAX_W / img.naturalWidth, CANVAS_MAX_H / img.naturalHeight);
  const dw = Math.round(img.naturalWidth  * _cropScale);
  const dh = Math.round(img.naturalHeight * _cropScale);

  cropCanvas.width  = dw;
  cropCanvas.height = dh;

  // Dessiner l'image sur le canvas
  const ctx = cropCanvas.getContext('2d');
  ctx.clearRect(0, 0, dw, dh);
  ctx.drawImage(img, 0, 0, dw, dh);

  if (cropData && cropData.crop_x !== undefined) {
    // Pré-positionner la sélection sur le crop enregistré en BDD
    // Les coordonnées BDD sont proportionnelles (0→1), on les convertit en pixels canvas
    _sel = {
      x: Math.round(cropData.crop_x * dw),
      y: Math.round(cropData.crop_y * dh),
      w: Math.round(cropData.crop_w * dw),
      h: Math.round(cropData.crop_h * dh),
    };
  } else {
    // Aucun crop enregistré : sélection initiale carré centré de 80% de la dimension minimale
    const side = Math.round(Math.min(dw, dh) * 0.8);
    _sel = {
      x: Math.round((dw - side) / 2),
      y: Math.round((dh - side) / 2),
      w: side,
      h: side
    };
  }

  // Afficher la zone de crop, masquer la prévisualisation et les boutons principaux
  // IMPORTANT : rendre cropContainer visible AVANT renderSelection() pour que
  // le navigateur calcule les dimensions CSS réelles du canvas.
  modalPhotoPreview.style.display  = 'none';
  photoMainActions.style.display   = 'none';
  cropContainer.style.display      = 'block';
  modalPhotoHint.textContent        = 'Déplacez et redimensionnez le cadre, puis cliquez sur « Recadrer & enregistrer ».';

  // Différer renderSelection() via requestAnimationFrame : le navigateur a ainsi
  // le temps de calculer le layout et cropCanvas.offsetWidth retourne la bonne valeur.
  requestAnimationFrame(() => renderSelection());
}

/**
 * Met à jour la position CSS de l'overlay de sélection.
 *
 * CORRECTIF : utilise cropCanvas.offsetWidth / offsetHeight (dimensions CSS réelles
 * de l'élément dans le DOM) au lieu de getBoundingClientRect() qui peut retourner
 * des valeurs incorrectes si le canvas n'est pas encore visible lors de l'appel initial.
 */
function renderSelection() {
  const cssW = cropCanvas.offsetWidth;
  const cssH = cropCanvas.offsetHeight;
  // Si le canvas n'est pas encore visible (offsetWidth === 0), ne rien faire
  if (!cssW || !cssH) return;
  const scaleX = cssW / cropCanvas.width;
  const scaleY = cssH / cropCanvas.height;
  cropSelection.style.left   = (_sel.x * scaleX) + 'px';
  cropSelection.style.top    = (_sel.y * scaleY) + 'px';
  cropSelection.style.width  = (_sel.w * scaleX) + 'px';
  cropSelection.style.height = (_sel.h * scaleY) + 'px';
}

/** Clamp un nombre entre min et max */
function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

/**
 * Obtenir les coordonnées souris/tactile relatives au canvas.
 * Utilise getBoundingClientRect() ici (correct car appelé lors d'un événement
 * interactif, donc le canvas est forcément visible et son layout est stable).
 */
function getPos(e) {
  const rect = cropCanvas.getBoundingClientRect();
  const src  = e.touches ? e.touches[0] : e;
  // Correction du ratio entre taille CSS affichée et taille canvas réelle
  const scaleX = cropCanvas.width  / rect.width;
  const scaleY = cropCanvas.height / rect.height;
  return {
    x: (src.clientX - rect.left) * scaleX,
    y: (src.clientY - rect.top)  * scaleY
  };
}

/** Détermine si le pointeur est dans la zone de sélection */
function inSelection(px, py) {
  return px >= _sel.x && px <= _sel.x + _sel.w
      && py >= _sel.y && py <= _sel.y + _sel.h;
}

/** Détermine si le pointeur est sur une poignée (retourne le nom ou null) */
function hitHandle(px, py) {
  const R = 14; // rayon de hit
  const handles = [
    { name: 'tl', hx: _sel.x,          hy: _sel.y          },
    { name: 'tr', hx: _sel.x + _sel.w, hy: _sel.y          },
    { name: 'bl', hx: _sel.x,          hy: _sel.y + _sel.h },
    { name: 'br', hx: _sel.x + _sel.w, hy: _sel.y + _sel.h },
  ];
  for (const h of handles) {
    if (Math.abs(px - h.hx) <= R && Math.abs(py - h.hy) <= R) return h.name;
  }
  return null;
}

// ── Événements souris sur le canvas de crop ──
cropArea.addEventListener('mousedown', e => {
  if (!_cropImage) return;
  const { x, y } = getPos(e);
  const handle = hitHandle(x, y);
  if (handle) {
    _dragMode  = handle;
  } else if (inSelection(x, y)) {
    _dragMode  = 'move';
  } else {
    // Nouvelle sélection par tiré
    _dragMode  = 'new';
    _sel = { x, y, w: 0, h: 0 };
  }
  _dragStart = { mx: x, my: y, sx: _sel.x, sy: _sel.y, sw: _sel.w, sh: _sel.h };
  e.preventDefault();
});

document.addEventListener('mousemove', e => {
  if (!_dragMode || !_cropImage) return;
  const { x, y } = getPos(e);
  const dx = x - _dragStart.mx;
  const dy = y - _dragStart.my;
  const W  = cropCanvas.width;
  const H  = cropCanvas.height;

  if (_dragMode === 'move') {
    _sel.x = clamp(_dragStart.sx + dx, 0, W - _sel.w);
    _sel.y = clamp(_dragStart.sy + dy, 0, H - _sel.h);
  } else if (_dragMode === 'new') {
    const x0 = Math.min(_dragStart.mx, x);
    const y0 = Math.min(_dragStart.my, y);
    const x1 = Math.max(_dragStart.mx, x);
    const y1 = Math.max(_dragStart.my, y);
    _sel = {
      x: clamp(x0, 0, W), y: clamp(y0, 0, H),
      w: clamp(x1 - x0, 0, W - clamp(x0, 0, W)),
      h: clamp(y1 - y0, 0, H - clamp(y0, 0, H)),
    };
  } else {
    // Redimensionnement par poignée
    let { sx, sy, sw, sh } = _dragStart;
    if (_dragMode === 'tl') {
      const nx = clamp(sx + dx, 0, sx + sw - 10);
      const ny = clamp(sy + dy, 0, sy + sh - 10);
      _sel = { x: nx, y: ny, w: sx + sw - nx, h: sy + sh - ny };
    } else if (_dragMode === 'tr') {
      const ny = clamp(sy + dy, 0, sy + sh - 10);
      _sel = { x: sx, y: ny, w: clamp(sw + dx, 10, W - sx), h: sy + sh - ny };
    } else if (_dragMode === 'bl') {
      const nx = clamp(sx + dx, 0, sx + sw - 10);
      _sel = { x: nx, y: sy, w: sx + sw - nx, h: clamp(sh + dy, 10, H - sy) };
    } else if (_dragMode === 'br') {
      _sel = { x: sx, y: sy, w: clamp(sw + dx, 10, W - sx), h: clamp(sh + dy, 10, H - sy) };
    }
  }
  renderSelection();
});

document.addEventListener('mouseup', () => { _dragMode = null; });

// ── Événements tactiles sur le canvas de crop ──
cropArea.addEventListener('touchstart', e => {
  if (!_cropImage) return;
  e.preventDefault();
  const { x, y } = getPos(e);
  const handle = hitHandle(x, y);
  if (handle) {
    _dragMode = handle;
  } else if (inSelection(x, y)) {
    _dragMode = 'move';
  } else {
    _dragMode = 'new';
    _sel = { x, y, w: 0, h: 0 };
  }
  _dragStart = { mx: x, my: y, sx: _sel.x, sy: _sel.y, sw: _sel.w, sh: _sel.h };
}, { passive: false });

cropArea.addEventListener('touchmove', e => {
  if (!_dragMode || !_cropImage) return;
  e.preventDefault();
  const { x, y } = getPos(e);
  const dx = x - _dragStart.mx;
  const dy = y - _dragStart.my;
  const W  = cropCanvas.width;
  const H  = cropCanvas.height;

  if (_dragMode === 'move') {
    _sel.x = clamp(_dragStart.sx + dx, 0, W - _sel.w);
    _sel.y = clamp(_dragStart.sy + dy, 0, H - _sel.h);
  } else if (_dragMode === 'new') {
    const x0 = Math.min(_dragStart.mx, x);
    const y0 = Math.min(_dragStart.my, y);
    const x1 = Math.max(_dragStart.mx, x);
    const y1 = Math.max(_dragStart.my, y);
    _sel = {
      x: clamp(x0, 0, W), y: clamp(y0, 0, H),
      w: clamp(x1 - x0, 0, W - clamp(x0, 0, W)),
      h: clamp(y1 - y0, 0, H - clamp(y0, 0, H)),
    };
  } else {
    let { sx, sy, sw, sh } = _dragStart;
    if (_dragMode === 'tl') {
      const nx = clamp(sx + dx, 0, sx + sw - 10);
      const ny = clamp(sy + dy, 0, sy + sh - 10);
      _sel = { x: nx, y: ny, w: sx + sw - nx, h: sy + sh - ny };
    } else if (_dragMode === 'tr') {
      const ny = clamp(sy + dy, 0, sy + sh - 10);
      _sel = { x: sx, y: ny, w: clamp(sw + dx, 10, W - sx), h: sy + sh - ny };
    } else if (_dragMode === 'bl') {
      const nx = clamp(sx + dx, 0, sx + sw - 10);
      _sel = { x: nx, y: sy, w: sx + sw - nx, h: clamp(sh + dy, 10, H - sy) };
    } else if (_dragMode === 'br') {
      _sel = { x: sx, y: sy, w: clamp(sw + dx, 10, W - sx), h: clamp(sh + dy, 10, H - sy) };
    }
  }
  renderSelection();
}, { passive: false });

cropArea.addEventListener('touchend', () => { _dragMode = null; });

// ──────────────────────────────────────────────
// Bouton "Recadrer & enregistrer"
// Envoie uniquement les coordonnées proportionnelles en BDD.
// Ne touche PAS au fichier photo sur le serveur.
// Le recadrage est appliqué à la volée par PhotoController via getCropSettings().
// ──────────────────────────────────────────────
cropSaveBtn.addEventListener('click', () => {
  if (!_cropImage || _sel.w < 2 || _sel.h < 2) return;

  // Convertir les coordonnées pixels canvas en proportions (0 → 1) par rapport à l'image réelle.
  // _cropScale = facteur canvas/réel, donc on divise par les dimensions du canvas.
  const W = cropCanvas.width;
  const H = cropCanvas.height;
  const crop_x = _sel.x / W;
  const crop_y = _sel.y / H;
  const crop_w = _sel.w / W;
  const crop_h = _sel.h / H;

  cropSaveBtn.disabled = true;
  cropSaveBtn.textContent = 'Enregistrement…';

  apiFetch('/api/students/' + _modalStudentId + '/photo-crop', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ crop_x, crop_y, crop_w, crop_h }),
  })
  .then(d => {
    if (!d.ok) throw new Error(d.error || 'Erreur inconnue');

    // Fermer le mode crop et rafraîchir la prévisualisation
    cropContainer.style.display    = 'none';
    photoMainActions.style.display = '';
    modalPhotoHint.textContent     = 'Recadrage enregistré. La vignette sera mise à jour au prochain rechargement.';

    // Rafraîchir l'image miniature dans la vignette du plan de salle (cache-busting)
    const seatImgs = liveRoom.querySelectorAll(`[data-student-id="${_modalStudentId}"] .seat-photo`);
    seatImgs.forEach(img => {
      img.src = '/photo?student_id=' + _modalStudentId + '&t=' + Date.now();
    });

    // Rafraîchir l'aperçu dans la modale
    renderPhotoTab(_modalStudentId);
  })
  .catch(err => {
    alert('Erreur lors de l\'enregistrement du recadrage :\n' + err.message);
  })
  .finally(() => {
    cropSaveBtn.disabled = false;
    cropSaveBtn.textContent = '✓ Recadrer & enregistrer';
  });
});

// ── Bouton Annuler le crop ──
cropCancelBtn.addEventListener('click', () => {
  cropContainer.style.display    = 'none';
  modalPhotoPreview.style.display = '';
  photoMainActions.style.display  = '';
  modalPhotoHint.textContent      = 'Formats : JPG, PNG, WEBP. Max 2 Mo.';
  _cropImage = null;
  _dragMode  = null;
});

let _modalStudentId = null;
let _modalSeatId    = null;

function openStudentModal(studentId, seatId, studentName) {
  _modalStudentId = studentId;
  _modalSeatId    = seatId;

  switchTab('donnees');

  // Avatar
  const img = document.createElement('img');
  img.src = '/photo?student_id=' + studentId;
  img.alt = studentName;
  img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:inherit;';
  img.onerror = () => {
    modalAvatar.innerHTML = studentName.split(' ').map(p => p[0] || '').join('').substring(0, 2).toUpperCase();
  };
  modalAvatar.innerHTML = '';
  modalAvatar.appendChild(img);

  modalName.textContent  = studentName;
  modalClass.textContent = '';
  modalBody.innerHTML    = '<div class="student-modal-loading">Chargement…</div>';
  studentModal.hidden    = false;
  modalClose.focus();

  // Bouton retirer : masqué en lecture seule
  modalRemoveBtn.style.display = IS_PAST_SESSION ? 'none' : '';

  // Chargement données élève
  apiFetch(`/api/students/${studentId}/summary?session_id=${SESSION_ID}`)
    .then(data => {
      if (data.class_name) modalClass.textContent = data.class_name;
      renderModalBody(data);
    })
    .catch(() => {
      modalBody.innerHTML = '<p style="color:var(--color-error)">Impossible de charger la fiche.</p>';
    });
}

function renderModalBody(data) {
  const obs = data.observations || [];
  const history = data.history || [];

  let html = '';

  // Observations de la séance en cours
  if (obs.length) {
    html += `<div class="modal-section"><h4>Tags de cette séance</h4><div class="modal-tags">`;
    obs.forEach(o => {
      html += `<span class="tag-chip" style="background:${o.color || '#888'}">${(o.icon ? o.icon + ' ' : '') + (o.tag || '')}</span>`;
    });
    html += `</div></div>`;
  } else {
    html += `<div class="modal-section"><p class="text-muted text-sm">Aucun tag pour cette séance.</p></div>`;
  }

  // Historique des observations récentes
  if (history.length) {
    html += `<div class="modal-section"><h4>Historique récent</h4><ul class="modal-history">`;
    history.forEach(h => {
      const date = new Date(h.date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
      html += `<li><span class="modal-history-date">${date}</span>
               <span class="tag-chip" style="background:${h.color || '#888'}">${(h.icon ? h.icon + ' ' : '') + (h.tag || '')}</span></li>`;
    });
    html += `</ul></div>`;
  }

  modalBody.innerHTML = html || '<p class="text-muted text-sm">Aucune donnée disponible.</p>';
}

function closeStudentModal() {
  // Réinitialiser le crop si en cours
  cropContainer.style.display     = 'none';
  modalPhotoPreview.style.display = '';
  photoMainActions.style.display  = '';
  _cropImage = null;
  modalPhotoInput.value = '';

  studentModal.hidden = true;
  _modalStudentId = null;
  _modalSeatId    = null;
}

// Double-clic sur une vignette occupée → ouvre la fiche élève
liveRoom.addEventListener('dblclick', e => {
  if (e.target.closest('.tag-chip')) return;
  const seat = e.target.closest('.live-seat.occupied');
  if (!seat || !seat.dataset.studentId) return;
  e.preventDefault();
  openStudentModal(
    parseInt(seat.dataset.studentId),
    parseInt(seat.dataset.seatId),
    seat.dataset.studentName
  );
});

// Fermeture modale élève
modalClose.addEventListener('click', closeStudentModal);
studentModal.addEventListener('click', e => { if (e.target === studentModal) closeStudentModal(); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && !studentModal.hidden) {
    e.stopImmediatePropagation();
    closeStudentModal();
  }
});

// Bouton "Retirer du plan de salle"
modalRemoveBtn.addEventListener('click', () => {
  if (!_modalStudentId || !_modalSeatId || IS_PAST_SESSION) return;

  modalRemoveBtn.disabled = true;
  modalRemoveBtn.textContent = 'Retrait en cours…';

  apiFetch(`/api/sessions/${SESSION_ID}/remove-student`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ student_id: _modalStudentId, seat_id: _modalSeatId })
  })
  .then(d => {
    if (!d.ok) throw new Error(d.error || 'Erreur');
    // Mise à jour UI optimiste
    const seatEl = getSeatEl(_modalSeatId);
    if (seatEl) {
      setSeatEmpty(seatEl);
      seatStudentMap[_modalSeatId] = null;
    }
    closeStudentModal();
  })
  .catch(err => {
    alert('Impossible de retirer l\'élève : ' + err.message);
    modalRemoveBtn.disabled = false;
    modalRemoveBtn.textContent = '\ud83d\uddd1 Retirer du plan de salle';
  });
});


// ── Onglets ─────────────────────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.student-modal-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  modalBody.hidden       = (name !== 'donnees');
  modalPhotoPanel.hidden = (name !== 'photo');
}
document.querySelectorAll('.student-modal-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    switchTab(btn.dataset.tab);
    if (btn.dataset.tab === 'photo' && _modalStudentId) renderPhotoTab(_modalStudentId);
  });
});

// ── Onglet Photo ─────────────────────────────────────────────────────────────
/**
 * Construit dynamiquement l'onglet Photo :
 * - Affiche la photo existante (ou un placeholder si aucune)
 * - Si une photo existe : bouton ✂️ Modifier le cadrage + 📷 Choisir une photo + 🗑 Supprimer
 * - Si aucune photo      : bouton 📷 Choisir une photo uniquement
 */
function renderPhotoTab(studentId) {
  modalPhotoPreview.innerHTML = '';
  modalPhotoHint.textContent  = 'Formats : JPG, PNG, WEBP. Max 2 Mo.';

  // ── Prévisualisation ──
  const img = document.createElement('img');
  img.alt = 'Photo élève';
  img.style.cssText = 'max-width:160px;max-height:160px;border-radius:var(--radius-md);object-fit:cover;';

  img.onload = () => {
    // Photo présente → afficher aussi le bouton Modifier le cadrage
    photoMainActions.innerHTML = `
      <button type="button" class="btn btn-secondary btn-sm" id="btnCropExisting">✂️ Modifier le cadrage</button>
      <label class="btn btn-ghost btn-sm" style="cursor:pointer;">
        📷 Choisir une photo
        <span id="fakePhotoTrigger" style="display:none;"></span>
      </label>
      <button type="button" class="btn btn-danger btn-sm" id="btnDeletePhoto">🗑 Supprimer</button>
    `;
    // Bouton Modifier le cadrage → charge la photo originale dans le crop
    document.getElementById('btnCropExisting').addEventListener('click', () => {
      startCropFromExistingPhoto(studentId);
    });
    // Label "Choisir une photo" → déclenche l'input file
    photoMainActions.querySelector('label').addEventListener('click', () => {
      modalPhotoInput.value = '';
      modalPhotoInput.click();
    });
    // Bouton Supprimer
    document.getElementById('btnDeletePhoto').addEventListener('click', () => {
      _handlePhotoDelete(studentId);
    });
  };

  img.onerror = () => {
    // Pas de photo → afficher un placeholder et uniquement le bouton Choisir
    modalPhotoPreview.innerHTML = '<div class="modal-photo-empty">Aucune photo</div>';
    photoMainActions.innerHTML = `
      <label class="btn btn-ghost btn-sm" style="cursor:pointer;">
        📷 Choisir une photo
        <span id="fakePhotoTrigger" style="display:none;"></span>
      </label>
    `;
    photoMainActions.querySelector('label').addEventListener('click', () => {
      modalPhotoInput.value = '';
      modalPhotoInput.click();
    });
  };

  // Cache-busting pour forcer l'affichage de la dernière version
  img.src = '/photo?student_id=' + studentId + '&t=' + Date.now();
  modalPhotoPreview.appendChild(img);
}

/**
 * Supprime la photo d'un élève via apiFetch (détection session expirée incluse)
 * et rafraîchit l'UI.
 * @param {number} studentId
 */
function _handlePhotoDelete(studentId) {
  if (!confirm('Supprimer la photo ?')) return;
  apiFetch('/api/students/' + studentId + '/photo', { method: 'DELETE' })
    .then(d => {
      modalPhotoHint.textContent = d.ok ? 'Photo supprimée.' : (d.error || 'Erreur.');
      if (d.ok) {
        renderPhotoTab(studentId);
        // Vider l'avatar en-tête de la modale
        modalAvatar.innerHTML = modalName.textContent.split(' ').map(p=>p[0]||'').join('').substring(0,2).toUpperCase();
        // Masquer la photo dans la vignette siège
        const seatEl = getSeatEl(_modalSeatId);
        if (seatEl) {
          const si = seatEl.querySelector('.seat-photo');
          if (si) { si.style.display='none'; if(si.nextElementSibling) si.nextElementSibling.style.display='flex'; }
        }
      }
    }).catch(() => { modalPhotoHint.textContent = 'Erreur réseau.'; });
}

// Sélection d'un fichier → lancer le mode crop (au lieu d'uploader directement)
modalPhotoInput.addEventListener('change', () => {
  const file = modalPhotoInput.files[0];
  if (!file || !_modalStudentId) return;
  if (file.size > 2097152) { modalPhotoHint.textContent = 'Fichier trop lourd (max 2 Mo).'; return; }
  // Lancer l'interface de recadrage
  initCrop(file);
});

// ── Fallback robuste pour les photos de vignettes ────────────────────────────────────────
liveRoom.querySelectorAll('.seat-photo').forEach(img => {
  img.addEventListener('error', () => {
    img.style.display = 'none';
    const wrapper = img.closest('.seat-photo-wrapper');
    const placeholder = wrapper ? wrapper.querySelector('.seat-photo-placeholder') : null;
    if (placeholder) placeholder.style.display = 'flex';
  });

  img.addEventListener('load', () => {
    img.style.display = '';
    const wrapper = img.closest('.seat-photo-wrapper');
    const placeholder = wrapper ? wrapper.querySelector('.seat-photo-placeholder') : null;
    if (placeholder) placeholder.style.display = 'none';
  });
});

</script>
