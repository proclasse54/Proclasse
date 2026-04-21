<?php
/**
 * views/sessions/index.php
 * Variables disponibles : $sessions, $plans, $page, $totalPages, $total
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Séances – ProClasse</title>
</head>
<body>

<h1>Séances</h1>

<!-- Import ICS Pronote -->
<section class="card" style="margin-bottom:1.5rem;">
    <h2>📅 Importer depuis Pronote (ICS)</h2>
    <p style="color:var(--color-text-muted);margin-bottom:1rem;">
        Exporte ton EDT depuis Pronote → <em>Mon EDT → Exporter → Calendrier (.ics)</em>, puis dépose le fichier ici.
    </p>
    <form id="icsForm" enctype="multipart/form-data">
        <label for="icsFile">Fichier <code>.ics</code></label>
        <input type="file" id="icsFile" name="icsfile" accept=".ics" required>
        <button type="submit" class="btn btn-primary">Importer les séances</button>
    </form>
    <div id="icsResult" style="margin-top:.75rem;"></div>
</section>

<!-- Bouton nouvelle séance manuelle -->
<button class="btn btn-primary" onclick="openNewSessionModal()">Nouvelle séance</button>
<!-- Toggle vue -->
<div class="view-toggle">
    <button id="btnList"  class="btn-view active" onclick="setView('list')">☰ Liste</button>
    <button id="btnWeek"  class="btn-view"        onclick="setView('week')">📅 Semaine</button>
</div>
<!-- Liste des séances -->
<div id="viewList">
    <?php if (empty($sessions)): ?>
        <div class="card" style="margin-top:1.5rem;">
            <h3>Aucune séance</h3>
            <p>Créez votre première séance ou importez votre EDT Pronote ci-dessus.</p>
        </div>
    <?php else: ?>
        <p style="margin:.75rem 0;color:var(--color-text-muted);">
            <?= $total ?> séance(s) au total — page <?= $page ?>/<?= $totalPages ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Heure</th>
                    <th>Classe</th>
                    <th>Matière</th>
                    <th>Plan / Salle</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($s['date']) ?></td>
                    <td>
                        <?php if (!empty($s['time_start'])): ?>
                            <?= substr($s['time_start'], 0, 5) ?>
                            <?= !empty($s['time_end']) ? ' → ' . substr($s['time_end'], 0, 5) : '' ?>
                        <?php else: ?>
                            –
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($s['class_name']) ?></td>
                    <td><?= htmlspecialchars($s['subject'] ?? '–') ?></td>
                    <td>
                        <?= htmlspecialchars($s['plan_name']) ?>
                        <small style="color:var(--color-text-muted)">(<?= htmlspecialchars($s['room_name']) ?>)</small>
                    </td>
                    <td>
                        <a href="/sessions/<?= $s['id'] ?>/live" class="btn btn-sm">Ouvrir</a>
                        <button class="btn btn-sm" onclick="deleteSession(<?= $s['id'] ?>)">Supprimer</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav style="margin-top:1rem;display:flex;gap:.5rem;align-items:center;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn">← Précédent</a>
            <?php endif; ?>
            <span>Page <?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn">Suivant →</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>        
</div>

<!-- ═══ VUE SEMAINE ═══ -->
<div id="viewWeek" hidden>

    <!-- Navigation semaine -->
    <div class="week-nav">
        <a href="?view=week&week=<?= $prevWeek ?>" class="btn-week-nav">← Semaine préc.</a>
        <strong><?= $weekLabel ?></strong>
        <a href="?view=week&week=<?= $nextWeek ?>" class="btn-week-nav">Semaine suiv. →</a>
    </div>

    <!-- Grille 5 jours -->
    <div class="week-grid">
        <?php
        $jours = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi'];
        $dates = [];
        for ($i = 0; $i < 5; $i++) {
            $d = (new \DateTime($weekStart))->modify("+$i days");
            $dates[] = $d->format('Y-m-d');
        }

        // Indexer les sessions par date
        $byDate = [];
        foreach ($weekSessions as $ws) {
            $byDate[$ws['date']][] = $ws;
        }
        ?>
        <?php foreach ($dates as $i => $d): ?>
        <div class="week-col">
            <div class="week-col-header">
                <?= $jours[$i] ?><br>
                <small><?= (new \DateTime($d))->format('d/m') ?></small>
            </div>
            <?php if (empty($byDate[$d])): ?>
                <div class="week-empty">—</div>
            <?php else: ?>
                <?php foreach ($byDate[$d] as $ws): ?>
                <div class="week-card" onclick="window.location='/sessions/<?= $ws['id'] ?>/live'">
                    <div class="week-card-time">
                        <?= $ws['time_start'] ? substr($ws['time_start'],0,5) : '' ?>
                        <?= $ws['time_end']   ? '–'.substr($ws['time_end'],0,5) : '' ?>
                    </div>
                    <div class="week-card-class"><?= htmlspecialchars($ws['class_name']) ?></div>
                    <div class="week-card-room"><?= htmlspecialchars($ws['room_name']) ?></div>
                    <?php if ($ws['subject']): ?>
                    <div class="week-card-subject"><?= htmlspecialchars($ws['subject']) ?></div>
                    <?php endif ?>
                </div>
                <?php endforeach ?>
            <?php endif ?>
        </div>
        <?php endforeach ?>
    </div>
</div>


<!-- Modale nouvelle séance manuelle -->
<div id="newSessionModal" hidden style="position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:center;justify-content:center;z-index:999;">
    <div class="card" style="min-width:340px;max-width:480px;width:100%;position:relative;">
        <h2>Nouvelle séance</h2>
        <button onclick="document.getElementById('newSessionModal').hidden=true"
                style="position:absolute;top:1rem;right:1rem;font-size:1.25rem;">×</button>
        <form onsubmit="createSession(event)">
            <label>Date
                <input type="date" name="date" required value="<?= date('Y-m-d') ?>">
            </label>
            <label>Heure de début
                <input type="time" name="time_start">
            </label>
            <label>Heure de fin
                <input type="time" name="time_end">
            </label>
            <label>Classe / Salle
                <select name="plan_id" required>
                    <option value="">— Choisir —</option>
                    <?php foreach ($plans as $pl): ?>
                        <option value="<?= $pl['id'] ?>">
                            <?= htmlspecialchars($pl['class_name'] . ' – ' . $pl['room_name'] . ' (' . $pl['name'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Matière <small>(optionnel)</small>
                <input type="text" name="subject" placeholder="ex: Mathématiques">
            </label>
            <?php if (empty($plans)): ?>
                <p style="color:var(--color-warning);">
                    ⚠️ Aucun plan configuré. Créez d'abord une salle, une classe, et assignez-les.
                </p>
            <?php endif; ?>
            <div style="display:flex;gap:.75rem;margin-top:1rem;">
                <button type="button" class="btn"
                        onclick="document.getElementById('newSessionModal').hidden=true">Annuler</button>
                <button type="submit" class="btn btn-primary">Démarrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewSessionModal() {
    document.getElementById('newSessionModal').hidden = false;
}

function createSession(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fetch('/api/sessions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            plan_id:    parseInt(fd.get('plan_id'), 10),
            date:       fd.get('date'),
            time_start: fd.get('time_start') || null,
            time_end:   fd.get('time_end')   || null,
            subject:    fd.get('subject')    || null,
        }),
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) window.location = '/sessions/' + d.id + '/live';
        else alert('Erreur : ' + (d.error ?? JSON.stringify(d)));
    });
}

function deleteSession(id) {
    if (!confirm('Supprimer cette séance et toutes ses observations ?')) return;
    fetch('/api/sessions/' + id, { method: 'DELETE' })
        .then(r => r.json())
        .then(d => { if (d.ok) location.reload(); });
}

// Import ICS
document.getElementById('icsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Import en cours…';

    const data = await fetch('/api/sessions/import-ics', {
        method: 'POST',
        body: new FormData(this),
    }).then(r => r.json());

    const el = document.getElementById('icsResult');
    if (data.ok) {
        let html = `<span style="color:var(--color-success,green)">
            ✅ ${data.inserted} séance(s) créée(s)
            ${data.plans_created ? ` · ${data.plans_created} plan(s) généré(s) automatiquement` : ''}
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

    btn.disabled = false;
    btn.textContent = 'Importer les séances';
});
</script>

</body>
</html>