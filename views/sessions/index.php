<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
  <h1>Séances</h1>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <button onclick="openNewSessionModal()" class="btn btn-primary">+ Nouvelle séance</button>
  </div>
</div>

<!-- Import ICS -->
<details style="margin-bottom:1.5rem;">
  <summary style="cursor:pointer;font-weight:600;">&#128197; Importer depuis Pronote (ICS)</summary>
  <p style="margin:.5rem 0;font-size:var(--text-sm);color:var(--color-text-muted);">
    Exporte ton EDT depuis Pronote &rarr; <em>Mon EDT &rarr; Exporter &rarr; Calendrier (.ics)</em>, puis d&eacute;pose le fichier ici.
  </p>
  <form id="icsForm" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <label>Fichier <code>.ics</code>
      <input type="file" id="icsFile" name="icsfile" accept=".ics" required>
    </label>
    <button type="submit" class="btn btn-primary">Importer les s&eacute;ances</button>
  </form>
  <div id="icsResult" style="margin-top:.5rem;"></div>
</details>

<!-- ═══ TOGGLE VUE ═══ -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;">
  <button id="btnList" onclick="setView('list')" class="btn btn-view active">☰ Liste</button>
  <button id="btnWeek" onclick="setView('week')" class="btn btn-view">&#128197; Semaine</button>
</div>

<!-- ═══ VUE LISTE ═══ -->
<div id="viewList">
  <?php if (empty($sessions)): ?>
    <p class="text-muted">Aucune séance &mdash; créez-en une ou importez votre EDT Pronote.</p>
  <?php else: ?>
    <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:.5rem;">
      <?= $total ?> séance(s) au total &mdash; page <?= $page ?>/<?= $totalPages ?>
    </p>
    <table class="table">
      <thead><tr>
        <th>Date</th><th>Heure</th><th>Classe</th><th>Mati&egrave;re</th><th>Plan / Salle</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['date']) ?></td>
          <td><?= $s['time_start'] ? substr($s['time_start'],0,5).' &ndash; '.substr($s['time_end'],0,5) : '&mdash;' ?></td>
          <td><?= htmlspecialchars($s['class_name']) ?></td>
          <td><?= htmlspecialchars($s['subject'] ?? '&mdash;') ?></td>
          <td>
            <?php if ($s['plan_id']): ?>
              <?= htmlspecialchars($s['plan_name'] ?? '') ?> (<?= htmlspecialchars($s['room_name'] ?? '') ?>)
            <?php else: ?>
              <em style="color:var(--color-text-muted);">Multi-classes</em>
            <?php endif ?>
          </td>
          <td style="white-space:nowrap;">
          <?php if ($s['plan_id']): ?>
            <a href="/sessions/<?= $s['id'] ?>/live" class="btn btn-sm btn-primary">Ouvrir</a>
          <?php else: ?>
            <span class="btn btn-sm" style="opacity:.4;cursor:default;" title="Séance informative, pas de plan de salle">—</span>
          <?php endif ?>
            <button onclick="deleteSession(<?= $s['id'] ?>)" class="btn btn-sm btn-danger">Supprimer</button>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem;">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>" class="btn btn-sm">&larr; Pr&eacute;c&eacute;dent</a>
      <?php endif ?>
      <span>Page <?= $page ?>/<?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?>" class="btn btn-sm">Suivant &rarr;</a>
      <?php endif ?>
    </div>
    <?php endif ?>
  <?php endif ?>
</div>

<!-- ═══ VUE SEMAINE ═══ -->
<div id="viewWeek" hidden>

  <?php
    $prevWeek = (clone $weekDate)->modify('-1 week')->format('o\\-\\WW');
    $nextWeek = (clone $weekDate)->modify('+1 week')->format('o\\-\\WW');
    $weekLabel = 'Semaine du '.(new \DateTime($weekStart))->format('d/m').' au '.(new \DateTime($weekEnd))->format('d/m/Y');
  ?>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <a href="?view=week&week=<?= $prevWeek ?>" class="btn btn-sm">&larr; Semaine pr&eacute;c.</a>
    <strong><?= $weekLabel ?></strong>
    <a href="?view=week&week=<?= $nextWeek ?>" class="btn btn-sm">Semaine suiv. &rarr;</a>
  </div>

  <?php
    $jours      = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi'];
    $dates      = [];
    for ($i = 0; $i < 5; $i++) {
        $dates[] = (new \DateTime($weekStart))->modify("+$i days")->format('Y-m-d');
    }
    $byDate = [];
    foreach ($weekSessions as $ws) {
        $byDate[$ws['date']][] = $ws;
    }
    $heureDebut = 8;
    $heureFin   = 18;
    foreach ($weekSessions as $_ws) {
        if ($_ws['time_start']) {
            $h = (int)substr($_ws['time_start'], 0, 2);
            if ($h < $heureDebut) $heureDebut = $h;
        }
        if ($_ws['time_end']) {
            $h = (int)substr($_ws['time_end'], 0, 2);
            $m = (int)substr($_ws['time_end'], 3, 2);
            $hFin = $m > 0 ? $h + 1 : $h;
            if ($hFin > $heureFin) $heureFin = $hFin;
        }
    }
    $pxParHeure   = 64;
    $hauteurTotal = ($heureFin - $heureDebut) * $pxParHeure;
    $currentWeekSlug = $weekDate->format('o-\\WW');
  ?>

  <div class="week-agenda">
    <div class="week-axis">
      <div class="week-axis-header"></div>
      <div class="week-axis-body" style="height:<?= $hauteurTotal ?>px;">
        <?php for ($h = $heureDebut; $h <= $heureFin; $h++): ?>
        <div class="week-axis-label" style="top:<?= ($h - $heureDebut) * $pxParHeure ?>px;">
          <?= sprintf('%02d', $h) ?>:00
        </div>
        <?php endfor ?>
      </div>
    </div>
    <div class="week-grid">
      <?php foreach ($dates as $i => $d): ?>
      <div class="week-col">
        <div class="week-col-header">
          <?= $jours[$i] ?>
          <small><?= (new \DateTime($d))->format('d/m') ?></small>
        </div>
        <div class="week-col-body" style="height:<?= $hauteurTotal ?>px;">
          <?php if (!empty($byDate[$d])): ?>
            <?php foreach ($byDate[$d] as $ws):
              if (!$ws['time_start'] || !$ws['time_end']) continue;
              [$h,  $m ] = array_map('intval', explode(':', substr($ws['time_start'], 0, 5)));
              [$h2, $m2] = array_map('intval', explode(':', substr($ws['time_end'],   0, 5)));
              $top    = (($h  + $m  / 60) - $heureDebut) * $pxParHeure;
              $height = (($h2 + $m2 / 60) - ($h + $m / 60)) * $pxParHeure - 2;
            ?>
            <div class="week-card <?= $ws['plan_id'] ? '' : 'week-card--multi' ?>"
                style="top:<?= round($top) ?>px;height:<?= round($height) ?>px;<?= $ws['plan_id'] ? '' : 'opacity:.6;cursor:default;' ?>"
                <?php if ($ws['plan_id']): ?>onclick="window.location='/sessions/<?= $ws['id'] ?>/live?from_week=<?= $currentWeekSlug ?>'"<?php endif ?>>
              <div style="display:flex;justify-content:space-between;align-items:baseline;gap:.25rem;">
                <div class="week-card-class"><?= htmlspecialchars($ws['class_name'] ?? '') ?></div>
                <?php if ($ws['room_name']): ?>
                <div class="week-card-room" style="font-size:var(--text-xs);white-space:nowrap;"><?= htmlspecialchars($ws['room_name']) ?></div>
                <?php endif ?>
              </div>
              <?php if ($ws['subject']): ?>
              <div class="week-card-subject"><?= htmlspecialchars($ws['subject']) ?></div>
              <?php endif ?>
            </div>
            <?php endforeach ?>
          <?php endif ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>
  </div>
</div>

<!-- ═══ MODAL NOUVELLE SÉANCE ═══ -->
<div id="newSessionModal" class="modal-overlay" hidden>
  <div class="modal-box" style="width:min(560px,95vw);">
    <div class="modal-header">
      <h2>Nouvelle séance</h2>
      <button class="modal-close" onclick="closeNewSessionModal()" aria-label="Fermer">&times;</button>
    </div>
    <form id="newSessionForm" onsubmit="createSession(event)" style="display:flex;flex-direction:column;gap:var(--space-5);">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
        <div class="form-group">
          <label class="form-label" for="nsDate">Date</label>
          <input class="form-input" type="date" id="nsDate" name="date" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="nsSubject">Matière <span style="font-weight:400;color:var(--text-muted)">(optionnel)</span></label>
          <input class="form-input" type="text" id="nsSubject" name="subject" placeholder="ex : Mathématiques">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
        <div class="form-group">
          <label class="form-label" for="nsTimeStart">Heure de début</label>
          <select class="form-input" id="nsTimeStart" name="time_start"></select>
        </div>
        <div class="form-group">
          <label class="form-label" for="nsTimeEnd">Heure de fin</label>
          <select class="form-input" id="nsTimeEnd" name="time_end"></select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="nsClass">Classe</label>
        <select class="form-input" id="nsClass" required>
          <option value="">— Choisir une classe —</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="nsRoom">Salle</label>
        <select class="form-input" id="nsRoom" disabled required>
          <option value="">— Choisir d'abord une classe —</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="nsPlan">Disposition</label>
        <select class="form-input" id="nsPlan" name="plan_id" disabled required>
          <option value="">— Choisir d'abord une salle —</option>
        </select>
        <?php if (empty($plans)): ?>
          <p style="margin-top:var(--space-2);color:var(--danger);font-size:var(--text-sm);">&#9888;&#65039; Aucun plan configuré. Créez d'abord une salle, une classe, et assignez-les.</p>
        <?php endif ?>
      </div>
      <div style="display:flex;gap:var(--space-3);justify-content:flex-end;padding-top:var(--space-2);border-top:1px solid var(--divider);">
        <button type="button" onclick="closeNewSessionModal()" class="btn">Annuler</button>
        <button type="submit" class="btn btn-primary" id="nsSubmitBtn" disabled>Démarrer la séance</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ MODAL SUPPRESSION SÉANCE ═══ -->
<div id="deleteModal" class="modal-overlay" hidden>
  <div class="modal-box" style="max-height:80vh;overflow-y:auto;">
    <h2 style="margin-bottom:var(--space-4);">&#9888;&#65039; Supprimer cette séance&nbsp;?</h2>
    <div id="deleteModalBody"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end;margin-top:var(--space-4);">
      <button id="btnDeleteCancel"  class="btn">Annuler</button>
      <button id="btnDeleteSave"    class="btn btn-warning">&#128190; Sauvegarder les obs. d'abord</button>
      <button id="btnDeleteConfirm" class="btn btn-danger">&#128465; Supprimer quand même</button>
    </div>
  </div>
</div>

<script>
// ── Données plans injectées depuis PHP ──────────────────────
const PLANS = <?= json_encode(array_values($plans), JSON_HEX_TAG) ?>;

const TIME_SLOTS = [
  '07:00','07:30','08:00','08:30','09:00','09:30',
  '10:00','10:30','11:00','11:30','12:00','12:30',
  '13:00','13:30','14:00','14:30','15:00','15:30',
  '16:00','16:30','17:00','17:30','18:00','18:30',
  '19:00'
];

// ── Ouverture / fermeture modale nouvelle séance ────────────
function openNewSessionModal() {
  buildClassSelect();
  buildTimeSelects();
  document.getElementById('nsClass').value = '';
  document.getElementById('nsRoom').value  = '';
  document.getElementById('nsRoom').disabled = true;
  document.getElementById('nsPlan').value  = '';
  document.getElementById('nsPlan').disabled = true;
  document.getElementById('nsSubmitBtn').disabled = true;
  document.getElementById('newSessionModal').removeAttribute('hidden');
}

function closeNewSessionModal() {
  document.getElementById('newSessionModal').setAttribute('hidden', '');
  document.getElementById('newSessionForm').reset();
}

// ── Selects horaires ────────────────────────────────────────
function buildTimeSelects() {
  const start = document.getElementById('nsTimeStart');
  const end   = document.getElementById('nsTimeEnd');
  start.innerHTML = '<option value="">— Début —</option>';
  end.innerHTML   = '<option value="">— Fin —</option>';
  TIME_SLOTS.forEach(t => {
    start.innerHTML += `<option value="${t}">${t}</option>`;
    end.innerHTML   += `<option value="${t}">${t}</option>`;
  });
  start.value = '08:00';
  filterEndTimes();
  document.getElementById('nsTimeEnd').value = '09:00';
  start.addEventListener('change', filterEndTimes);
}

function filterEndTimes() {
  const startVal = document.getElementById('nsTimeStart').value;
  const end = document.getElementById('nsTimeEnd');
  const prev = end.value;
  end.innerHTML = '<option value="">— Fin —</option>';
  TIME_SLOTS.filter(t => t > startVal).forEach(t => {
    end.innerHTML += `<option value="${t}">${t}</option>`;
  });
  if (prev && prev > startVal) end.value = prev;
}

// ── Selects chaînés classe → salle → disposition ────────────
function buildClassSelect() {
  const classes = [...new Map(PLANS.map(p => [p.class_id, p.class_name])).entries()];
  const sel = document.getElementById('nsClass');
  sel.innerHTML = '<option value="">— Choisir une classe —</option>';
  classes.sort((a,b) => a[1].localeCompare(b[1]))
         .forEach(([id, name]) => {
    sel.innerHTML += `<option value="${id}">${name}</option>`;
  });
}

document.getElementById('nsClass').addEventListener('change', function() {
  const classId = parseInt(this.value);
  const roomSel = document.getElementById('nsRoom');
  const planSel = document.getElementById('nsPlan');
  planSel.innerHTML = '<option value="">— Choisir d\'abord une salle —</option>';
  planSel.disabled = true;
  document.getElementById('nsSubmitBtn').disabled = true;
  if (!classId) {
    roomSel.innerHTML = '<option value="">— Choisir d\'abord une classe —</option>';
    roomSel.disabled = true;
    return;
  }
  const rooms = [...new Map(
    PLANS.filter(p => p.class_id == classId)
         .map(p => [p.room_id, p.room_name])
  ).entries()];
  roomSel.innerHTML = '<option value="">— Choisir une salle —</option>';
  rooms.sort((a,b) => a[1].localeCompare(b[1]))
       .forEach(([id, name]) => {
    roomSel.innerHTML += `<option value="${id}">${name}</option>`;
  });
  roomSel.disabled = false;
});

document.getElementById('nsRoom').addEventListener('change', function() {
  const classId = parseInt(document.getElementById('nsClass').value);
  const roomId  = parseInt(this.value);
  const planSel = document.getElementById('nsPlan');
  document.getElementById('nsSubmitBtn').disabled = true;
  if (!roomId) {
    planSel.innerHTML = '<option value="">— Choisir d\'abord une salle —</option>';
    planSel.disabled = true;
    return;
  }
  const plans = PLANS.filter(p => p.class_id == classId && p.room_id == roomId);
  planSel.innerHTML = '<option value="">— Choisir une disposition —</option>';
  plans.sort((a,b) => a.name.localeCompare(b.name))
       .forEach(p => {
    planSel.innerHTML += `<option value="${p.id}">${p.name}</option>`;
  });
  if (plans.length === 1) {
    planSel.value = plans[0].id;
    document.getElementById('nsSubmitBtn').disabled = false;
  }
  planSel.disabled = false;
});

document.getElementById('nsPlan').addEventListener('change', function() {
  document.getElementById('nsSubmitBtn').disabled = !this.value;
});

// ── Création séance ─────────────────────────────────────────
function createSession(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fetch('/api/sessions', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      plan_id:    parseInt(fd.get('plan_id'), 10),
      date:       fd.get('date'),
      time_start: fd.get('time_start') || null,
      time_end:   fd.get('time_end')   || null,
      subject:    fd.get('subject')    || null,
    }),
  }).then(r => r.json()).then(d => {
    if (d.ok) window.location = '/sessions/' + d.id + '/live';
    else alert('Erreur : ' + (d.error ?? JSON.stringify(d)));
  });
}

// ── Suppression séance ──────────────────────────────────────
async function deleteSession(id) {
  const summary = await fetch('/api/sessions/' + id + '/observations-summary')
                        .then(r => r.json());
  if (summary.count === 0) {
    if (!confirm('Supprimer cette séance ? (aucune observation enregistrée)')) return;
    await doDeleteSession(id);
    return;
  }
  const byStudent = {};
  summary.rows.forEach(r => {
    const key = r.last_name + ' ' + r.first_name;
    if (!byStudent[key]) byStudent[key] = [];
    byStudent[key].push((r.icon ?? '') + ' ' + r.tag);
  });
  let html = `<p style="margin-bottom:.75rem"><strong>${summary.count} observation(s)</strong> seront définitivement supprimées&nbsp;:</p>
    <ul style="margin:.5rem 0 1rem 1.25rem;line-height:2">`;
  for (const [student, tags] of Object.entries(byStudent)) {
    html += `<li><strong>${student}</strong>&nbsp;: ${tags.join(', ')}</li>`;
  }
  html += `</ul>`;
  document.getElementById('deleteModalBody').innerHTML = html;
  const modal = document.getElementById('deleteModal');
  modal.dataset.sessionId = id;
  modal.removeAttribute('hidden');
}

async function doDeleteSession(id) {
  const d = await fetch('/api/sessions/' + id, {method:'DELETE'}).then(r => r.json());
  if (d.ok) location.reload();
  else alert('Erreur : ' + (d.error ?? JSON.stringify(d)));
}

// ── Boutons modale suppression ──────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnDeleteConfirm').addEventListener('click', async () => {
    const id = document.getElementById('deleteModal').dataset.sessionId;
    document.getElementById('deleteModal').setAttribute('hidden', '');
    await doDeleteSession(id);
  });
  document.getElementById('btnDeleteCancel').addEventListener('click', () => {
    document.getElementById('deleteModal').setAttribute('hidden', '');
  });
  document.getElementById('btnDeleteSave').addEventListener('click', () => {
    const id = document.getElementById('deleteModal').dataset.sessionId;
    window.open('/api/sessions/' + id + '/observations-export', '_blank');
  });
});

// ── Import ICS ──────────────────────────────────────────────
document.getElementById('icsForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = this.querySelector('button[type=submit]');
  btn.disabled = true; btn.textContent = 'Import en cours…';
  const data = await fetch('/api/sessions/import-ics', {
    method: 'POST', body: new FormData(this),
  }).then(r => r.json());
  const el = document.getElementById('icsResult');
  if (data.ok) {
    let html = `<span style="color:var(--color-success,green)">✅ ${data.inserted} séance(s) créée(s)${data.plans_created ? ` &middot; ${data.plans_created} plan(s) généré(s)` : ''} &middot; ${data.skipped} ignorée(s) (doublons)</span>`;
    if (data.errors?.length) {
      html += '<br><details><summary>&#9888;&#65039; ' + data.errors.length + ' avertissement(s)</summary>'
            + data.errors.map(e => `<div>&bull; ${e}</div>`).join('') + '</details>';
    }
    el.innerHTML = html;
    setTimeout(() => location.reload(), 2000);
  } else {
    el.innerHTML = `<span style="color:var(--color-error,red)">❌ ${data.error}</span>`;
  }
  btn.disabled = false; btn.textContent = 'Importer les séances';
});
</script>
