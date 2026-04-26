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
    $prevWeek = (clone $weekDate)->modify('-1 week')->format('o\-\WW');
    $nextWeek = (clone $weekDate)->modify('+1 week')->format('o\-\WW');
    $weekLabel = 'Semaine du '.(new \DateTime($weekStart))->format('d/m').' au '.(new \DateTime($weekEnd))->format('d/m/Y');
  ?>

  <!-- Navigation semaine -->
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
    // APRÈS — plage calculée dynamiquement sur les séances de la semaine
    $heureDebut = 8;   // plancher minimum
    $heureFin   = 18;  // plafond minimum

    foreach ($weekSessions as $_ws) {
        if ($_ws['time_start']) {
            $h = (int)substr($_ws['time_start'], 0, 2);
            if ($h < $heureDebut) $heureDebut = $h;
        }
        if ($_ws['time_end']) {
            $h = (int)substr($_ws['time_end'], 0, 2);
            $m = (int)substr($_ws['time_end'], 3, 2);
            // Si la séance finit après l'heure plafond, étendre (+ arrondir à l'heure sup)
            $hFin = $m > 0 ? $h + 1 : $h;
            if ($hFin > $heureFin) $heureFin = $hFin;
        }
    }

    $pxParHeure   = 64;
    $hauteurTotal = ($heureFin - $heureDebut) * $pxParHeure;
  ?>

  <!-- Agenda : axe horaire + grille 5 jours -->
  <div class="week-agenda">

    <!-- Colonne axe horaire -->
    <div class="week-axis">
      <div class="week-axis-header"></div><!-- vide, aligne avec les headers de jours -->
      <div class="week-axis-body" style="height:<?= $hauteurTotal ?>px;">
        <?php for ($h = $heureDebut; $h <= $heureFin; $h++): ?>
        <div class="week-axis-label" style="top:<?= ($h - $heureDebut) * $pxParHeure ?>px;">
          <?= sprintf('%02d', $h) ?>:00
        </div>
        <?php endfor ?>
      </div>
    </div>

    <!-- Grille 5 jours -->
    <div class="week-grid">
      <?php foreach ($dates as $i => $d): ?>
      <div class="week-col">
        <div class="week-col-header">
          <?= $jours[$i] ?>
          <small><?= (new \DateTime($d))->format('d/m') ?></small>
        </div>
        <div class="week-col-body" style="height:<?= $hauteurTotal ?>px;">
          <?php if (empty($byDate[$d])): ?>
            <!-- jour vide, les lignes horaires suffisent -->
          <?php else: ?>
            <?php foreach ($byDate[$d] as $ws):
              if (!$ws['time_start'] || !$ws['time_end']) continue;
              [$h,  $m ] = array_map('intval', explode(':', substr($ws['time_start'], 0, 5)));
              [$h2, $m2] = array_map('intval', explode(':', substr($ws['time_end'],   0, 5)));
              $top    = (($h  + $m  / 60) - $heureDebut) * $pxParHeure;
              $height = (($h2 + $m2 / 60) - ($h + $m / 60)) * $pxParHeure - 2;
            ?>
            <div class="week-card <?= $ws['plan_id'] ? '' : 'week-card--multi' ?>"
                style="top:<?= round($top) ?>px;height:<?= round($height) ?>px;<?= $ws['plan_id'] ? '' : 'opacity:.6;cursor:default;' ?>"
                <?= $ws['plan_id'] ? "onclick=\"window.location='/sessions/{$ws['id']}/live'\"" : '' ?>>
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

  </div><!-- .week-agenda -->
</div>

<!-- ═══ MODAL NOUVELLE SÉANCE ═══ -->
<div id="newSessionModal" class="modal-overlay" hidden>
  <div style="background:var(--color-surface);padding:var(--space-6);border-radius:var(--radius-lg);width:min(480px,90vw);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
      <h2>Nouvelle séance</h2>
      <button onclick="document.getElementById('newSessionModal').hidden=true">&times;</button>
    </div>
    <form onsubmit="createSession(event)">
      <label>Date <input type="date" name="date" required value="<?= date('Y-m-d') ?>"></label>
      <label>Heure de début <input type="time" name="time_start"></label>
      <label>Heure de fin   <input type="time" name="time_end"></label>
      <label>Classe / Salle
        <select name="plan_id" required>
          <option value="">&mdash; Choisir &mdash;</option>
          <?php foreach ($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['class_name'].' &ndash; '.$pl['room_name']) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <?php if (empty($plans)): ?>
        <p style="color:var(--color-warning);">&#9888;&#65039; Aucun plan configuré. Créez d'abord une salle, une classe, et assignez-les.</p>
      <?php endif ?>
      <label>Mati&egrave;re (optionnel) <input type="text" name="subject" placeholder="ex: Math&eacute;matiques"></label>
      <div style="display:flex;gap:.5rem;margin-top:var(--space-4);justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('newSessionModal').hidden=true" class="btn">Annuler</button>
        <button type="submit" class="btn btn-primary">Démarrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ MODAL SUPPRESSION SÉANCE ═══ -->
<div id="deleteModal" class="modal-overlay" hidden>
  <div style="background:var(--color-surface);padding:var(--space-6);border-radius:var(--radius-lg);
              width:min(540px,92vw);max-height:80vh;overflow-y:auto;">
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

// ── Modal & CRUD ────────────────────────────────────────────
function openNewSessionModal() {
    document.getElementById('newSessionModal').hidden = false;
}

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

async function deleteSession(id) {
    // 1. Vérifier s'il y a des observations
    const summary = await fetch('/api/sessions/' + id + '/observations-summary')
                          .then(r => r.json());

    if (summary.count === 0) {
        // Pas d'observations → confirmation simple
        if (!confirm('Supprimer cette séance ? (aucune observation enregistrée)')) return;
        await doDeleteSession(id);
        return;
    }

    // 2. Construire le détail par élève
    const byStudent = {};
    summary.rows.forEach(r => {
        const key = r.last_name + ' ' + r.first_name;
        if (!byStudent[key]) byStudent[key] = [];
        byStudent[key].push((r.icon ?? '') + ' ' + r.tag);
    });

    let html = `<p style="margin-bottom:.75rem">
        <strong>${summary.count} observation(s)</strong> seront définitivement supprimées&nbsp;:
    </p><ul style="margin:.5rem 0 1rem 1.25rem;line-height:2">`;
    for (const [student, tags] of Object.entries(byStudent)) {
        html += `<li><strong>${student}</strong>&nbsp;: ${tags.join(', ')}</li>`;
    }
    html += `</ul>`;

    // 3. Afficher la modale
    document.getElementById('deleteModalBody').innerHTML = html;
    const modal = document.getElementById('deleteModal');
    modal.dataset.sessionId = id;
    modal.hidden = false;
}

async function doDeleteSession(id) {
    const d = await fetch('/api/sessions/' + id, {method:'DELETE'}).then(r => r.json());
    if (d.ok) location.reload();
    else alert('Erreur : ' + (d.error ?? JSON.stringify(d)));
}

// ── Boutons de la modale de suppression ──────────────────────
document.addEventListener('DOMContentLoaded', () => {

    document.getElementById('btnDeleteConfirm').addEventListener('click', async () => {
        const id = document.getElementById('deleteModal').dataset.sessionId;
        document.getElementById('deleteModal').hidden = true;
        await doDeleteSession(id);
    });

    document.getElementById('btnDeleteCancel').addEventListener('click', () => {
        document.getElementById('deleteModal').hidden = true;
    });

    // "Sauvegarder" : télécharge le CSV, laisse la modale ouverte pour décider ensuite
    document.getElementById('btnDeleteSave').addEventListener('click', () => {
        const id = document.getElementById('deleteModal').dataset.sessionId;
        window.open('/api/sessions/' + id + '/observations-export', '_blank');
    });
});

// ── Import ICS ────────────────────────────────────────────
document.getElementById('icsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true; btn.textContent = 'Import en cours…';
    const data = await fetch('/api/sessions/import-ics', {
        method: 'POST', body: new FormData(this),
    }).then(r => r.json());
    const el = document.getElementById('icsResult');
    if (data.ok) {
        let html = `<span style="color:var(--color-success,green)">
            ✅ ${data.inserted} séance(s) créée(s)
            ${data.plans_created ? ` &middot; ${data.plans_created} plan(s) généré(s)` : ''}
            &middot; ${data.skipped} ignorée(s) (doublons)
        </span>`;
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
