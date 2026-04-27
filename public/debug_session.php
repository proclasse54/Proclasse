<?php
// FICHIER TEMPORAIRE DE DEBUG - A SUPPRIMER APRES UTILISATION
require_once __DIR__ . '/../src/Database.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) { die('Passe ?session_id=XXX dans l URL'); }

$db = Database::get();

// Action : clear overrides de la session
if (isset($_GET['clear'])) {
    $db->prepare("DELETE FROM session_seat_overrides WHERE session_id = ?")->execute([$sessionId]);
    header("Location: debug_session.php?session_id=$sessionId");
    exit;
}

// Action : reset plan (remet les 3 sièges à leur état d'origine sauvegardé en GET)
// Non implémenté ici — on passe par clear uniquement

$session = $db->prepare("SELECT id, plan_id, date, time_start FROM sessions WHERE id = ?");
$session->execute([$sessionId]);
$ses = $session->fetch(PDO::FETCH_ASSOC);
if (!$ses) { die('Session introuvable'); }

$planId = (int)$ses['plan_id'];

// Récupère TOUS les sièges de la salle pour le plan
$stmtSeats = $db->prepare("
    SELECT sa.seat_id, sa.student_id, st.first_name, st.last_name
    FROM seating_assignments sa
    LEFT JOIN students st ON st.id = sa.student_id
    WHERE sa.plan_id = ?
    ORDER BY sa.seat_id
");
$stmtSeats->execute([$planId]);
$planSeats = $stmtSeats->fetchAll(PDO::FETCH_ASSOC);

// Overrides pour cette session
$stmtOv = $db->prepare("
    SELECT sso.seat_id, sso.student_id, st.first_name, st.last_name
    FROM session_seat_overrides sso
    LEFT JOIN students st ON st.id = sso.student_id
    WHERE sso.session_id = ?
    ORDER BY sso.seat_id
");
$stmtOv->execute([$sessionId]);
$overrides = $stmtOv->fetchAll(PDO::FETCH_ASSOC);

// Toutes les séances du plan avec overrides sur sièges impliqués
$stmtAll = $db->prepare("
    SELECT se.id, se.date, se.time_start,
           sso.seat_id, sso.student_id,
           st.first_name, st.last_name
    FROM sessions se
    LEFT JOIN session_seat_overrides sso ON sso.session_id = se.id
    LEFT JOIN students st ON st.id = sso.student_id
    WHERE se.plan_id = ?
    ORDER BY se.date, se.time_start, sso.seat_id
");
$stmtAll->execute([$planId]);
$allRows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Debug session <?= $sessionId ?></title>
<style>
  body { font-family: monospace; font-size: 13px; padding: 16px; background: #1a1a1a; color: #d4d4d4; }
  h2 { color: #7ec8d4; margin-bottom: 4px; }
  h3 { color: #f0a860; margin: 16px 0 4px; }
  table { border-collapse: collapse; margin-bottom: 12px; }
  th, td { border: 1px solid #444; padding: 4px 10px; text-align: left; }
  th { background: #2a2a2a; color: #aaa; }
  tr:nth-child(even) td { background: #222; }
  .btn { display: inline-block; padding: 6px 14px; border-radius: 4px; cursor: pointer; font: 13px monospace; border: none; margin-right: 8px; }
  .btn-red { background: #7a2020; color: #fdd; }
  .btn-green { background: #1f5c2e; color: #cfc; }
  .btn-blue { background: #1a3a5c; color: #adf; }
  #log { background: #111; border: 1px solid #333; padding: 10px; max-height: 300px; overflow-y: auto; }
  .log-entry { margin: 4px 0; padding: 4px 8px; border-radius: 3px; }
  .log-req  { color: #7ec8d4; border-left: 3px solid #7ec8d4; }
  .log-ok   { color: #6fcf97; border-left: 3px solid #6fcf97; }
  .log-err  { color: #eb5757; border-left: 3px solid #eb5757; }
  .log-info { color: #aaa;    border-left: 3px solid #555; }
  .badge-session { background: #2a3f2a; color: #6fcf97; padding: 1px 6px; border-radius: 3px; }
  .badge-plan    { background: #2a2a4a; color: #7ec8d4; padding: 1px 6px; border-radius: 3px; }
  .badge-all     { background: #4a2a2a; color: #f0a860; padding: 1px 6px; border-radius: 3px; }
</style>
</head>
<body>

<h2>🔍 Debug — Session <?= $sessionId ?> | plan_id=<?= $planId ?> | <?= $ses['date'] ?> <?= $ses['time_start'] ?></h2>

<div style="margin-bottom:12px">
  <a class="btn btn-red" href="?session_id=<?= $sessionId ?>&clear=1" onclick="return confirm('Vider tous les overrides de cette session ?')">🗑 Clear overrides session <?= $sessionId ?></a>
  <a class="btn btn-blue" href="?session_id=<?= $sessionId ?>">🔄 Rafraîchir</a>
</div>

<h3>📋 Plan <?= $planId ?> — seating_assignments</h3>
<table>
  <tr><th>seat_id</th><th>student_id</th><th>nom</th></tr>
  <?php foreach ($planSeats as $r): ?>
  <tr><td><?= $r['seat_id'] ?></td><td><?= $r['student_id'] ?></td><td><?= htmlspecialchars($r['last_name'].' '.$r['first_name']) ?></td></tr>
  <?php endforeach; ?>
</table>

<h3>🎯 Overrides session <?= $sessionId ?></h3>
<?php if (!$overrides): ?>
  <p style="color:#aaa"><em>Aucun override.</em></p>
<?php else: ?>
<table>
  <tr><th>seat_id</th><th>student_id</th><th>nom</th></tr>
  <?php foreach ($overrides as $r): ?>
  <tr><td><?= $r['seat_id'] ?></td><td><?= $r['student_id'] ?? 'NULL' ?></td><td><?= htmlspecialchars($r['last_name'].' '.$r['first_name']) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h3>📅 Toutes les séances du plan <?= $planId ?> — tous les overrides</h3>
<table>
  <tr><th>session_id</th><th>date</th><th>time_start</th><th>seat_id</th><th>student_id</th><th>nom</th></tr>
  <?php foreach ($allRows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><?= $r['date'] ?></td>
    <td><?= $r['time_start'] ?></td>
    <td><?= $r['seat_id'] ?? '—' ?></td>
    <td><?= $r['student_id'] ?? '—' ?></td>
    <td><?= htmlspecialchars($r['last_name'].' '.$r['first_name']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h3>📝 Log des actions (cette page)</h3>
<div id="log"><div class="log-entry log-info">En attente d'actions sur la séance <?= $sessionId ?>…</div></div>

<script>
const SESSION_ID = <?= $sessionId ?>;
const log = document.getElementById('log');

function addLog(cls, msg) {
  const d = document.createElement('div');
  d.className = 'log-entry ' + cls;
  d.textContent = new Date().toLocaleTimeString() + ' | ' + msg;
  log.appendChild(d);
  log.scrollTop = log.scrollHeight;
}

// Intercepte fetch pour logger les appels à apiMoveSeat
const _fetch = window.fetch.bind(window);
window.fetch = function(url, opts) {
  const urlStr = typeof url === 'string' ? url : url.url || String(url);
  if (urlStr.includes('/sessions/') && urlStr.includes('/move-seat')) {
    let body = {};
    try { body = JSON.parse(opts?.body || '{}'); } catch(e) {}
    const scope = body.scope || 'session';
    const badgeCls = scope === 'plan' ? 'badge-plan' : scope === 'all' ? 'badge-all' : 'badge-session';
    addLog('log-req', `→ POST ${urlStr} | student=${body.student_id} src=${body.source_seat_id} tgt=${body.target_seat_id} scope=[${scope}]`);
    return _fetch(url, opts).then(async r => {
      const clone = r.clone();
      let json = {};
      try { json = await clone.json(); } catch(e) {}
      if (json.ok) {
        addLog('log-ok', `✓ OK | scope=${json.scope} swapped_student_id=${json.swapped_student_id}`);
      } else {
        addLog('log-err', `✗ ERREUR | ${json.error || JSON.stringify(json)}`);
      }
      // Auto-refresh du tableau après 300ms
      setTimeout(() => refreshTables(), 300);
      return r;
    }).catch(e => {
      addLog('log-err', `✗ FETCH ERROR | ${e.message}`);
      throw e;
    });
  }
  return _fetch(url, opts);
};

function refreshTables() {
  fetch('?session_id=' + SESSION_ID + '&ajax=1')
    .then(r => r.text())
    .then(html => {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      // Remplace les 3 tableaux (plan, overrides session, toutes séances)
      const sections = ['plan-table', 'override-table', 'all-table'];
      sections.forEach(id => {
        const newEl = doc.getElementById(id);
        const oldEl = document.getElementById(id);
        if (newEl && oldEl) oldEl.outerHTML = newEl.outerHTML;
      });
      addLog('log-info', '↻ Tableaux mis à jour');
    });
}
</script>

</body>
</html>
