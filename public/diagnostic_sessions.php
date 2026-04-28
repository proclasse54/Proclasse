<?php
/**
 * Diagnostic de conformité des séances
 * Accès : /tools/diagnostic_sessions.php
 *
 * Vérifie pour chaque séance :
 *  1. Doublons d'élèves (même student_id sur plusieurs sièges)
 *  2. Sièges manquants (siège de la salle absent du snapshot)
 *  3. Sièges fantômes (siège dans snapshot mais absent de la salle)
 *
 * NE MODIFIE RIEN — lecture seule.
 */

// ── Connexion ─────────────────────────────────────────────────────────────────
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

// ── Requête principale ────────────────────────────────────────────────────────

// 1. Doublons : student_id non NULL apparaissant 2+ fois dans la même séance
$duplicates = $pdo->query("
    SELECT
        ss.session_id,
        se.date,
        se.time_start,
        COALESCE(g.name, c.name, se.multi_classes) AS class_name,
        r.name         AS room_name,
        ss.student_id,
        st.last_name,
        st.first_name,
        COUNT(*)       AS nb_sieges,
        GROUP_CONCAT(ss.seat_id ORDER BY ss.seat_id SEPARATOR ', ')  AS seat_ids,
        GROUP_CONCAT(
            CONCAT('R', s.row_index+1, 'C', s.col_index+1)
            ORDER BY ss.seat_id SEPARATOR ', '
        ) AS positions
    FROM session_seats ss
    JOIN sessions se      ON se.id   = ss.session_id
    LEFT JOIN seating_plans sp ON sp.id = se.plan_id
    LEFT JOIN classes c   ON c.id    = sp.class_id
    LEFT JOIN groups g    ON g.id    = sp.group_id
    LEFT JOIN rooms r     ON r.id    = sp.room_id
    JOIN students st      ON st.id   = ss.student_id
    JOIN seats s          ON s.id    = ss.seat_id
    WHERE ss.student_id IS NOT NULL
    GROUP BY ss.session_id, ss.student_id
    HAVING COUNT(*) > 1
    ORDER BY se.date DESC, se.time_start DESC, ss.session_id, st.last_name, st.first_name
")->fetchAll();

// 2. Sièges manquants : sièges de la salle absents du snapshot session_seats
$missingSeats = $pdo->query("
    SELECT
        se.id          AS session_id,
        se.date,
        se.time_start,
        COALESCE(g.name, c.name, se.multi_classes) AS class_name,
        r.name         AS room_name,
        s.id           AS seat_id,
        CONCAT('R', s.row_index+1, 'C', s.col_index+1) AS position,
        sa.student_id  AS plan_student_id,
        CONCAT(st.last_name, ' ', st.first_name) AS plan_student_name
    FROM sessions se
    JOIN seating_plans sp ON sp.id = se.plan_id
    JOIN classes c        ON c.id  = sp.class_id
    LEFT JOIN groups g    ON g.id  = sp.group_id
    JOIN rooms r          ON r.id  = sp.room_id
    JOIN seats s          ON s.room_id = sp.room_id
    LEFT JOIN session_seats ss ON ss.session_id = se.id AND ss.seat_id = s.id
    LEFT JOIN seating_assignments sa ON sa.seat_id = s.id AND sa.plan_id = se.plan_id
    LEFT JOIN students st ON st.id = sa.student_id
    WHERE ss.id IS NULL
      AND se.plan_id IS NOT NULL
    ORDER BY se.date DESC, se.id, s.row_index, s.col_index
")->fetchAll();

// 3. Sièges fantômes : sièges dans session_seats mais absent de la salle
$ghostSeats = $pdo->query("
    SELECT
        ss.session_id,
        se.date,
        se.time_start,
        COALESCE(g.name, c.name, se.multi_classes) AS class_name,
        r.name         AS room_name,
        ss.seat_id,
        ss.student_id,
        CONCAT(st.last_name, ' ', st.first_name) AS student_name
    FROM session_seats ss
    JOIN sessions se      ON se.id   = ss.session_id
    LEFT JOIN seating_plans sp ON sp.id = se.plan_id
    LEFT JOIN classes c   ON c.id    = sp.class_id
    LEFT JOIN groups g    ON g.id    = sp.group_id
    LEFT JOIN rooms r     ON r.id    = sp.room_id
    LEFT JOIN seats s     ON s.id    = ss.seat_id AND s.room_id = sp.room_id
    LEFT JOIN students st ON st.id   = ss.student_id
    WHERE s.id IS NULL
      AND se.plan_id IS NOT NULL
    ORDER BY se.date DESC, ss.session_id
")->fetchAll();

// 4. Stats globales
$stats = $pdo->query("
    SELECT
        COUNT(DISTINCT se.id)   AS nb_sessions,
        COUNT(ss.id)            AS nb_lignes_snapshot,
        COUNT(ss.student_id)    AS nb_places_occupees,
        COUNT(DISTINCT se.plan_id) AS nb_plans
    FROM sessions se
    LEFT JOIN session_seats ss ON ss.session_id = se.id
    WHERE se.plan_id IS NOT NULL
")->fetch();

$totalErrors = count($duplicates) + count($missingSeats) + count($ghostSeats);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic session_seats — ProClasse</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #f5f5f2; color: #1a1a18; }
  h1 { padding: 24px 32px 8px; font-size: 1.4rem; }
  .meta { padding: 0 32px 24px; color: #666; font-size: 0.875rem; }

  .stats { display: flex; gap: 16px; padding: 0 32px 24px; flex-wrap: wrap; }
  .stat { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px 20px; min-width: 160px; }
  .stat-value { font-size: 1.8rem; font-weight: 700; }
  .stat-label { color: #666; font-size: 0.8rem; margin-top: 2px; }

  .banner { margin: 0 32px 24px; padding: 14px 18px; border-radius: 8px; font-weight: 600; font-size: 1rem; }
  .banner.ok  { background: #d4efcc; color: #1e5c10; }
  .banner.err { background: #fde8e8; color: #8b1a1a; }

  .section { margin: 0 32px 32px; }
  .section h2 { font-size: 1rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
  .badge { display: inline-flex; align-items: center; justify-content: center;
           background: #e74c3c; color: #fff; border-radius: 99px;
           font-size: 0.75rem; font-weight: 700; min-width: 22px; height: 22px; padding: 0 6px; }
  .badge.ok { background: #27ae60; }

  table { width: 100%; border-collapse: collapse; background: #fff;
          border: 1px solid #ddd; border-radius: 8px; overflow: hidden; font-size: 0.82rem; }
  th { background: #f0ede8; text-align: left; padding: 8px 12px; font-weight: 600; white-space: nowrap; }
  td { padding: 7px 12px; border-top: 1px solid #eee; vertical-align: top; }
  tr:hover td { background: #fafaf8; }
  .dup  { background: #fff5f5 !important; }
  .miss { background: #fffbf0 !important; }
  .ghost { background: #f0f5ff !important; }
  .ok-msg { color: #27ae60; font-style: italic; padding: 12px 0; }
  code { background: #f0ede8; border-radius: 4px; padding: 1px 5px; font-size: 0.8rem; }
</style>
</head>
<body>

<h1>🔍 Diagnostic session_seats</h1>
<p class="meta">Généré le <?= date('d/m/Y à H:i:s') ?> — lecture seule, aucune modification</p>

<!-- Stats globales -->
<div class="stats">
  <div class="stat">
    <div class="stat-value"><?= $stats['nb_sessions'] ?></div>
    <div class="stat-label">Séances avec plan</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= $stats['nb_lignes_snapshot'] ?></div>
    <div class="stat-label">Lignes dans session_seats</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= $stats['nb_places_occupees'] ?></div>
    <div class="stat-label">Places occupées</div>
  </div>
  <div class="stat">
    <div class="stat-value" style="color:<?= $totalErrors > 0 ? '#e74c3c' : '#27ae60' ?>">
      <?= $totalErrors ?>
    </div>
    <div class="stat-label">Anomalies détectées</div>
  </div>
</div>

<!-- Bannière résultat -->
<?php if ($totalErrors === 0): ?>
  <div class="banner ok">✅ Toutes les séances sont conformes — aucun doublon, aucun siège manquant.</div>
<?php else: ?>
  <div class="banner err">⚠️ <?= $totalErrors ?> anomalie(s) détectée(s) — voir le détail ci-dessous.</div>
<?php endif; ?>

<!-- ── 1. Doublons ─────────────────────────────────────────────────────────── -->
<div class="section">
  <h2>
    🔴 Doublons d'élèves
    <span class="badge <?= count($duplicates) === 0 ? 'ok' : '' ?>"><?= count($duplicates) ?></span>
  </h2>
  <?php if (empty($duplicates)): ?>
    <p class="ok-msg">Aucun doublon détecté.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>session_id</th>
        <th>Date</th>
        <th>Heure</th>
        <th>Classe</th>
        <th>Salle</th>
        <th>student_id</th>
        <th>Élève</th>
        <th>Nb sièges</th>
        <th>seat_ids</th>
        <th>Positions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($duplicates as $row): ?>
      <tr class="dup">
        <td><code><?= $row['session_id'] ?></code></td>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= $row['time_start'] ? substr($row['time_start'], 0, 5) : '—' ?></td>
        <td><?= htmlspecialchars($row['class_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['room_name'] ?? '—') ?></td>
        <td><code><?= $row['student_id'] ?></code></td>
        <td><?= htmlspecialchars($row['last_name'] . ' ' . $row['first_name']) ?></td>
        <td style="font-weight:700;color:#e74c3c"><?= $row['nb_sieges'] ?></td>
        <td><code><?= htmlspecialchars($row['seat_ids']) ?></code></td>
        <td><?= htmlspecialchars($row['positions']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── 2. Sièges manquants ────────────────────────────────────────────────── -->
<div class="section">
  <h2>
    🟡 Sièges manquants dans le snapshot
    <span class="badge <?= count($missingSeats) === 0 ? 'ok' : '' ?>"><?= count($missingSeats) ?></span>
  </h2>
  <p style="font-size:0.8rem;color:#888;margin-bottom:8px">
    Sièges présents dans la salle mais absents de session_seats pour la séance.
  </p>
  <?php if (empty($missingSeats)): ?>
    <p class="ok-msg">Aucun siège manquant.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>session_id</th>
        <th>Date</th>
        <th>Heure</th>
        <th>Classe</th>
        <th>Salle</th>
        <th>seat_id</th>
        <th>Position</th>
        <th>Élève plan (attendu)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($missingSeats as $row): ?>
      <tr class="miss">
        <td><code><?= $row['session_id'] ?></code></td>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= $row['time_start'] ? substr($row['time_start'], 0, 5) : '—' ?></td>
        <td><?= htmlspecialchars($row['class_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['room_name'] ?? '—') ?></td>
        <td><code><?= $row['seat_id'] ?></code></td>
        <td><?= htmlspecialchars($row['position']) ?></td>
        <td><?= $row['plan_student_name'] ? htmlspecialchars($row['plan_student_name']) : '<em style="color:#aaa">vide</em>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── 3. Sièges fantômes ─────────────────────────────────────────────────── -->
<div class="section">
  <h2>
    🔵 Sièges fantômes dans le snapshot
    <span class="badge <?= count($ghostSeats) === 0 ? 'ok' : '' ?>"><?= count($ghostSeats) ?></span>
  </h2>
  <p style="font-size:0.8rem;color:#888;margin-bottom:8px">
    Lignes dans session_seats dont le seat_id n'appartient pas à la salle de la séance.
  </p>
  <?php if (empty($ghostSeats)): ?>
    <p class="ok-msg">Aucun siège fantôme.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>session_id</th>
        <th>Date</th>
        <th>Heure</th>
        <th>Classe</th>
        <th>Salle</th>
        <th>seat_id (fantôme)</th>
        <th>student_id</th>
        <th>Élève</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ghostSeats as $row): ?>
      <tr class="ghost">
        <td><code><?= $row['session_id'] ?></code></td>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= $row['time_start'] ? substr($row['time_start'], 0, 5) : '—' ?></td>
        <td><?= htmlspecialchars($row['class_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($row['room_name'] ?? '—') ?></td>
        <td><code style="color:#e74c3c"><?= $row['seat_id'] ?></code></td>
        <td><?= $row['student_id'] ?? '<em>NULL</em>' ?></td>
        <td><?= $row['student_name'] ? htmlspecialchars($row['student_name']) : '<em style="color:#aaa">—</em>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

</body>
</html>
