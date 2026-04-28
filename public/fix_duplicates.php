<?php
/**
 * Correction des doublons session_seats
 * Accès : /tools/fix_duplicates.php
 *
 * Stratégie : pour chaque (session_id, student_id) en doublon,
 * on conserve la ligne dont le seat_id correspond au plan (seating_assignments).
 * Si aucune ne correspond au plan, on garde la ligne avec le seat_id le plus récent (MAX).
 * L'autre ligne est mise à NULL (siège libéré, pas supprimé).
 *
 * Mode dry-run par défaut — passe ?apply=1 pour appliquer.
 */

$cfg = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
$user = $cfg['user'];          // à adapter
$pass = $cfg['password'];              // à adapter

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('<pre style="color:red">Connexion impossible : ' . htmlspecialchars($e->getMessage()) . '</pre>');
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// ── Récupérer tous les doublons avec leur contexte ──
$doublons = $pdo->query("
    SELECT
        ss.session_id,
        ss.student_id,
        se.date,
        se.time_start,
        se.plan_id,
        COALESCE(g.name, c.name, se.multi_classes) AS class_name,
        r.name         AS room_name,
        st.last_name,
        st.first_name,
        GROUP_CONCAT(ss.seat_id ORDER BY ss.seat_id SEPARATOR ',') AS seat_ids
    FROM session_seats ss
    JOIN sessions se      ON se.id   = ss.session_id
    LEFT JOIN seating_plans sp ON sp.id = se.plan_id
    LEFT JOIN classes c   ON c.id  = sp.class_id
    LEFT JOIN groups g    ON g.id  = sp.group_id
    LEFT JOIN rooms r     ON r.id  = sp.room_id
    JOIN students st      ON st.id = ss.student_id
    WHERE ss.student_id IS NOT NULL
    GROUP BY ss.session_id, ss.student_id
    HAVING COUNT(*) > 1
    ORDER BY se.date DESC, ss.session_id
")->fetchAll();

if (empty($doublons)) {
    echo '<p style="font-family:sans-serif;padding:32px;color:green;font-size:1.2rem">✅ Aucun doublon à corriger.</p>';
    exit;
}

// ── Calculer les décisions ──
$decisions = [];
foreach ($doublons as $d) {
    $sessionId = (int)$d['session_id'];
    $studentId = (int)$d['student_id'];
    $planId    = (int)$d['plan_id'];
    $seatIds   = array_map('intval', explode(',', $d['seat_ids']));

    // Siège attendu d'après le plan
    $stmtPlan = $pdo->prepare("
        SELECT seat_id FROM seating_assignments
        WHERE plan_id = ? AND student_id = ?
        LIMIT 1
    ");
    $stmtPlan->execute([$planId, $studentId]);
    $planSeatId = $stmtPlan->fetchColumn();

    if ($planSeatId && in_array((int)$planSeatId, $seatIds, true)) {
        $keepSeatId   = (int)$planSeatId;
        $reason       = 'correspond au plan';
    } else {
        // Pas de correspondance plan → garder le MAX seat_id (arbitraire mais déterministe)
        $keepSeatId   = max($seatIds);
        $reason       = 'aucune correspondance plan — MAX seat_id conservé';
    }

    $nullSeatIds = array_values(array_filter($seatIds, fn($s) => $s !== $keepSeatId));

    $decisions[] = [
        'session_id'    => $sessionId,
        'student_id'    => $studentId,
        'date'          => $d['date'],
        'time_start'    => $d['time_start'],
        'class_name'    => $d['class_name'],
        'room_name'     => $d['room_name'],
        'name'          => $d['last_name'] . ' ' . $d['first_name'],
        'plan_seat_id'  => $planSeatId ?: null,
        'all_seat_ids'  => $seatIds,
        'keep_seat_id'  => $keepSeatId,
        'null_seat_ids' => $nullSeatIds,
        'reason'        => $reason,
    ];
}

// ── Appliquer si demandé ──
$applied = false;
$errors  = [];
if ($apply) {
    $pdo->beginTransaction();
    try {
        foreach ($decisions as $dec) {
            foreach ($dec['null_seat_ids'] as $seatId) {
                $pdo->prepare("
                    UPDATE session_seats
                    SET student_id = NULL
                    WHERE session_id = ? AND seat_id = ?
                ")->execute([$dec['session_id'], $seatId]);
            }
        }
        $pdo->commit();
        $applied = true;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Correction doublons — ProClasse</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #f5f5f2; color: #1a1a18; padding: 32px; }
  h1 { font-size: 1.3rem; margin-bottom: 6px; }
  .meta { color: #666; font-size: 0.85rem; margin-bottom: 24px; }
  .banner { padding: 14px 18px; border-radius: 8px; font-weight: 600; margin-bottom: 24px; }
  .banner.dry  { background: #fff3cd; color: #7a5200; }
  .banner.ok   { background: #d4efcc; color: #1e5c10; }
  .banner.err  { background: #fde8e8; color: #8b1a1a; }
  table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd;
          border-radius: 8px; overflow: hidden; font-size: 0.82rem; margin-bottom: 28px; }
  th { background: #f0ede8; text-align: left; padding: 8px 12px; font-weight: 600; }
  td { padding: 7px 12px; border-top: 1px solid #eee; vertical-align: top; }
  tr:hover td { background: #fafaf8; }
  code { background: #f0ede8; border-radius: 4px; padding: 1px 5px; font-size: 0.8rem; }
  .keep { color: #27ae60; font-weight: 700; }
  .null { color: #e74c3c; text-decoration: line-through; }
  .btn { display: inline-block; margin-top: 8px; padding: 10px 20px; background: #e74c3c;
         color: #fff; border-radius: 6px; font-weight: 700; text-decoration: none;
         font-size: 0.9rem; }
  .btn:hover { background: #c0392b; }
  .btn.back { background: #2980b9; margin-left: 12px; }
  .btn.back:hover { background: #1a5f7a; }
</style>
</head>
<body>

<h1>🔧 Correction des doublons session_seats</h1>
<p class="meta">Généré le <?= date('d/m/Y à H:i:s') ?></p>

<?php if (!empty($errors)): ?>
  <div class="banner err">❌ Erreur lors de l'application : <?= htmlspecialchars(implode(', ', $errors)) ?></div>
<?php elseif ($applied): ?>
  <div class="banner ok">✅ <?= count($decisions) ?> doublon(s) corrigé(s) avec succès. Les sièges en trop ont été libérés (student_id = NULL).</div>
  <a href="diagnostic_sessions.php" class="btn back">→ Retour au diagnostic</a>
<?php else: ?>
  <div class="banner dry">
    🔍 Mode prévisualisation — aucune modification en base.<br>
    Vérifiez le tableau ci-dessous puis cliquez <strong>Appliquer la correction</strong>.
  </div>
<?php endif; ?>

<?php if (!$applied || !empty($errors)): ?>
<table>
  <thead>
    <tr>
      <th>session_id</th>
      <th>Date</th>
      <th>Classe</th>
      <th>student_id</th>
      <th>Élève</th>
      <th>Siège plan</th>
      <th>Tous les sièges</th>
      <th>Décision</th>
      <th>Raison</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($decisions as $dec): ?>
    <tr>
      <td><code><?= $dec['session_id'] ?></code></td>
      <td><?= htmlspecialchars($dec['date']) ?><?= $dec['time_start'] ? ' ' . substr($dec['time_start'], 0, 5) : '' ?><br>
          <small style="color:#888"><?= htmlspecialchars($dec['class_name'] ?? '') ?></small></td>
      <td><?= htmlspecialchars($dec['room_name'] ?? '—') ?></td>
      <td><code><?= $dec['student_id'] ?></code></td>
      <td><?= htmlspecialchars($dec['name']) ?></td>
      <td><?= $dec['plan_seat_id'] ? '<code>' . $dec['plan_seat_id'] . '</code>' : '<em style="color:#aaa">absent</em>' ?></td>
      <td>
        <?php foreach ($dec['all_seat_ids'] as $sid): ?>
          <code class="<?= $sid === $dec['keep_seat_id'] ? 'keep' : 'null' ?>"><?= $sid ?></code>
        <?php endforeach; ?>
      </td>
      <td>
        Garder <code class="keep"><?= $dec['keep_seat_id'] ?></code><br>
        Libérer <?php foreach ($dec['null_seat_ids'] as $sid): ?><code class="null"><?= $sid ?></code> <?php endforeach; ?>
      </td>
      <td style="color:#666;font-size:0.78rem"><?= htmlspecialchars($dec['reason']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if (!$applied && empty($errors)): ?>
  <a href="?apply=1" class="btn" onclick="return confirm('Appliquer la correction sur <?= count($decisions) ?> doublon(s) ?')">
    ⚠️ Appliquer la correction
  </a>
  <a href="diagnostic_sessions.php" class="btn back">← Retour au diagnostic</a>
<?php endif; ?>
<?php endif; ?>

</body>
</html>
