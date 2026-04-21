<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
  <h1>Séances</h1>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <button onclick="openNewSessionModal()" class="btn btn-primary">+ Nouvelle séance</button>
  </div>
</div>

<!-- Import ICS -->
<details style="margin-bottom:1.5rem;">
  <summary style="cursor:pointer;font-weight:600;">📅 Importer depuis Pronote (ICS)</summary>
  <p style="margin:.5rem 0;font-size:var(--text-sm);color:var(--color-text-muted);">
    Exporte ton EDT depuis Pronote → <em>Mon EDT → Exporter → Calendrier (.ics)</em>, puis dépose le fichier ici.
  </p>
  <form id="icsForm" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
    <label>Fichier <code>.ics</code>
      <input type="file" id="icsFile" name="icsfile" accept=".ics" required>
    </label>
    <button type="submit" class="btn btn-primary">Importer les séances</button>
  </form>
  <div id="icsResult" style="margin-top:.5rem;"></div>
</details>

<!-- ═══ TOGGLE VUE ═══ -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;">
  <button id="btnList" onclick="setView('list')" class="btn btn-view active">☰ Liste</button>
  <button id="btnWeek" onclick="setView('week')" class="btn btn-view">📅 Semaine</button>
</div>

<!-- ═══ VUE LISTE ═══ -->
<div id="viewList">
  <?php if (empty($sessions)): ?>
    <p class="text-muted">Aucune séance — créez-en une ou importez votre EDT Pronote.</p>
  <?php else: ?>
    <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:.5rem;">
      <?= $total ?> séance(s) au total — page <?= $page ?>/<?= $totalPages ?>
    </p>
    <table class="table">
      <thead><tr>
        <th>Date</th><th>Heure</th><th>Classe</th><th>Matière</th><th>Plan / Salle</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
        <tr>
          <td><?= htmlspecialchars($s['date']) ?></td>
          <td><?= $s['time_start'] ? substr($s['time_start'],0,5).' – '.substr($s['time_end'],0,5) : '—' ?></td>
          <td><?= htmlspecialchars($s['class_name']) ?></td>
          <td><?= htmlspecialchars($s['subject'] ?? '—') ?></td>
          <td><?= htmlspecialchars($s['plan_name']) ?> (<?= htmlspecialchars($s['room_name']) ?>)</td>
          <td style="white-space:nowrap;">
            <a href="/sessions/<?= $s['id'] ?>/live" class="btn btn-sm btn-primary">Ouvrir</a>
            <button onclick="deleteSession(<?= $s['id'] ?>)" class="btn btn-sm btn-danger">Supprimer</button>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem;">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>" class="btn btn-sm">← Précédent</a>
      <?php endif ?>
      <span>Page <?= $page ?>/<?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?>" class="btn btn-sm">Suivant →</a>
      <?php endif ?>
    </div>
    <?php endif ?>
  <?php endif ?>
</div>

<!-- ═══ VUE SEMAINE ═══ -->
<div id="viewWeek" hidden>

  <!-- Navigation semaine -->
  <?php
    $prevWeek = (clone $weekDate)->modify('-1 week')->format('o\-\WW');
    $nextWeek = (clone $weekDate)->modify('+1 week')->format('o\-\WW');
    $weekLabel = 'Semaine du '.(new \DateTime($weekStart))->format('d/m').' au '.(new \DateTime($weekEnd))->format('d/m/Y');
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <a href="?view=week&week=<?= $prevWeek ?>" class="btn btn-sm">← Semaine préc.</a>
    <strong><?= $weekLabel ?></strong>
    <a href="?view=week&week=<?= $nextWeek ?>" class="btn btn-sm">Semaine suiv. →</a>
  </div>

  <!-- Grille 5 jours -->
  <?php
    $jours = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi'];
    $dates = [];
    for ($i = 0; $i < 5; $i++) {
      $dates[] = (new \DateTime($weekStart))->modify("+$i days")->format('Y-m-d');
    }
    $byDate = [];
    foreach ($weekSessions as $ws) {
      $byDate[$ws['date']][] = $ws;
    }
  ?>
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem;">
    <?php foreach ($dates as $i => $d): ?>
    <div style="border:1px solid var(--color-border);border-radius:var(--radius-md);overflow:hidden;min-height:200px;">
      <div style="background:var(--color-surface-offset);padding:.4rem;text-align:center;font-weight:600;font-size:var(--text-sm);border-bottom:1px solid var(--color-border);">
        <?= $jours[$i] ?><br>
        <small style="font-weight:400;"><?= (new \DateTime($d))->format('d/m') ?></small>
      </div>
      <?php if (empty($byDate[$d])): ?>
        <div style="color:var(--color-text-faint);text-align:center;padding:1rem;">—</div>
      <?php else: ?>
        <?php foreach ($byDate[$d] as $ws): ?>
        <div onclick="window.location='/sessions/<?= $ws['id'] ?>/live'"
             style="margin:.4rem;padding:.5rem .6rem;background:var(--color-primary-highlight);border-left:3px solid var(--color-primary);border-radius:var(--radius-sm);cursor:pointer;font-size:var(--text-sm);">
          <div style="font-size:var(--text-xs);color:var(--color-text-muted);">
            <?= $ws['time_start'] ? substr($ws['time_start'],0,5) : '' ?>
            <?= $ws['time_end']   ? '–'.substr($ws['time_end'],0,5) : '' ?>
          </div>
          <div style="font-weight:600;"><?= htmlspecialchars($ws['class_name']) ?></div>
          <div style="font-size:var(--text-xs);color:var(--color-text-muted);"><?= htmlspecialchars($ws['room_name']) ?></div>
          <?php if ($ws['subject']): ?>
          <div style="font-size:var(--text-xs);color:var(--color-text-muted);"><?= htmlspecialchars($ws['subject']) ?></div>
          <?php endif ?>
        </div>
        <?php endforeach ?>
      <?php endif ?>
    </div>
    <?php endforeach ?>
  </div>
</div>

<!-- ═══ MODAL NOUVELLE SÉANCE ═══ -->
<div id="newSessionModal" class="modal-overlay" hidden>
  <div style="background:var(--color-surface);padding:var(--space-6);border-radius:var(--radius-lg);width:min(480px,90vw);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4);">
      <h2>Nouvelle séance</h2>
      <button onclick="document.getElementById('newSessionModal').hidden=true">×</button>
    </div>
    <form onsubmit="createSession(event)">
      <label>Date <input type="date" name="date" required value="<?= date('Y-m-d') ?>"></label>
      <label>Heure de début <input type="time" name="time_start"></label>
      <label>Heure de fin   <input type="time" name="time_end"></label>
      <label>Classe / Salle
        <select name="plan_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['class_name'].' – '.$pl['room_name']) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <?php if (empty($plans)): ?>
        <p style="color:var(--color-warning);">⚠️ Aucun plan configuré. Créez d'abord une salle, une classe, et assignez-les.</p>
      <?php endif ?>
      <label>Matière (optionnel) <input type="text" name="subject" placeholder="ex: Mathématiques"></label>
      <div style="display:flex;gap:.5rem;margin-top:var(--space-4);justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('newSessionModal').hidden=true" class="btn">Annuler</button>
        <button type="submit" class="btn btn-primary">Démarrer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Toggle vue ──────────────────────────────────────────────
function setView(v) {
    document.getElementById('viewList').hidden = (v === 'week');
    document.getElementById('viewWeek').hidden = (v === 'list');
    document.getElementById('btnList').classList.toggle('active', v === 'list');
    document.getElementById('btnWeek').classList.toggle('active', v === 'week');
    const url = new URL(location.href);
    url.searchParams.set('view', v);
    history.replaceState(null, '', url);
}
// Restaurer la vue depuis l'URL au chargement
setView(new URLSearchParams(location.search).get('view') || 'list');

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
function deleteSession(id) {
    if (!confirm('Supprimer cette séance et toutes ses observations ?')) return;
    fetch('/api/sessions/' + id, {method:'DELETE'})
        .then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}

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
        let html = `<span style="color:var(--color-success,green)">
            ✅ ${data.inserted} séance(s) créée(s)
            ${data.plans_created ? ` · ${data.plans_created} plan(s) généré(s)` : ''}
            · ${data.skipped} ignorée(s) (doublons)
        </span>`;
        if (data.errors?.length) {
            html += '<br><details><summary>⚠️ ' + data.errors.length + ' avertissement(s)</summary>'
                  + data.errors.map(e => `<div>• ${e}</div>`).join('') + '</details>';
        }
        el.innerHTML = html;
        setTimeout(() => location.reload(), 2000);
    } else {
        el.innerHTML = `<span style="color:var(--color-error,red)">❌ ${data.error}</span>`;
    }
    btn.disabled = false; btn.textContent = 'Importer les séances';
});
</script>