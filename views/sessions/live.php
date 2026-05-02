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

// ── Séance passée ? (date strictement < aujourd'hui) ──────────────────────
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

ob_start();
?>
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
                <?php if ($photoUrl): ?>
                  <div class="seat-photo-wrapper">
                    <img src="<?= htmlspecialchars($photoUrl) ?>"
                        alt="<?= htmlspecialchars($seat['first_name'] . ' ' . $seat['last_name']) ?>"
                        class="seat-photo" loading="lazy">
                  </div>
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

  // 401 : toujours une expiration de session
  if (r.status === 401) {
    showSessionExpiredToast();
    throw new Error('Session expirée');
  }

  // 403 : peut être expiration OU refus métier (séance passée, etc.)
  if (r.status === 403) {
    let body;
    try { body = await r.json(); } catch (_) { body = null; }
    if (body && body.expired === true) {
      showSessionExpiredToast();
      throw new Error('Session expirée');
    }
    // Refus métier : on remonte l'erreur avec le message serveur
    throw new Error(body?.error || 'Action non autorisée');
  }

  // Essai de parse JSON pour les autres statuts
  let data;
  try {
    data = await r.json();
  } catch (_) {
    if (!r.ok) {
      showSessionExpiredToast();
      throw new Error('Session expirée');
    }
    throw new Error(`Réponse invalide du serveur (${r.status})`);
  }

  return data;
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
    const fmt = d.toLocaleDateString('fr-FR');
    const t = s.time ? ' ' + s.time.slice(0,5) : '';
    return `<li>${fmt}${t} — ${s.reason}</li>`;
  }).join('');
  skippedModal.hidden = false;
}

document.getElementById('skippedClose').addEventListener('click', () => { skippedModal.hidden = true; });
skippedModal.addEventListener('click', e => { if (e.target === skippedModal) skippedModal.hidden = true; });

// ──────────────────────────────────────────────
// SUPPRESSION SÉANCE
// ──────────────────────────────────────────────
const deleteSessionModal   = document.getElementById('deleteSessionModal');
const btnDeleteSession     = document.getElementById('btnDeleteSession');
const deleteSessionConfirm = document.getElementById('deleteSessionConfirm');
const deleteSessionCancel  = document.getElementById('deleteSessionCancel');

btnDeleteSession.addEventListener('click', () => {
  deleteSessionModal.hidden = false;
  deleteSessionConfirm.focus();
});
deleteSessionCancel.addEventListener('click', () => { deleteSessionModal.hidden = true; });
deleteSessionModal.addEventListener('click', e => { if (e.target === deleteSessionModal) deleteSessionModal.hidden = true; });

deleteSessionConfirm.addEventListener('click', () => {
  deleteSessionConfirm.disabled = true;
  apiFetch(`/api/sessions/${SESSION_ID}`, { method: 'DELETE' })
    .then(d => {
      if (d.ok) {
        window.location.href = '<?= $backUrl ?>';
      } else {
        alert('Erreur lors de la suppression.');
        deleteSessionConfirm.disabled = false;
      }
    })
    .catch(() => { deleteSessionConfirm.disabled = false; });
});
</script>
