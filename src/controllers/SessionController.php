<?php
// src/controllers/SessionController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class SessionController
{
    public function index(): void
    {
        $db = Database::get();

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 100;
        $offset  = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT se.*,
                sp.name AS plan_name,
                COALESCE(g.name, c.name, se.multi_classes) AS class_name,
                r.name AS room_name
            FROM sessions se
            LEFT JOIN seating_plans sp ON sp.id = se.plan_id
            LEFT JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            LEFT JOIN rooms r ON r.id = sp.room_id
            ORDER BY se.date DESC, se.time_start DESC, se.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([(int)$perPage, (int)$offset]);
        $sessions = $stmt->fetchAll();

        $total      = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $totalPages = (int)ceil($total / $perPage);

        $weekParam = $_GET['week'] ?? null;
        if ($weekParam && preg_match('/^\d{4}-W(\d{2})$/', $weekParam, $m)) {
            $weekDate = new \DateTime();
            $weekDate->setISODate((int)explode('-W', $weekParam)[0], (int)$m[1]);
        } else {
            $weekDate = new \DateTime();
        }
        $weekStart = (clone $weekDate)->modify('monday this week')->format('Y-m-d');
        $weekEnd   = (clone $weekDate)->modify('sunday this week')->format('Y-m-d');

        $stmtWeek = $db->prepare("
            SELECT se.*,
                sp.name AS plan_name,
                COALESCE(g.name, c.name, se.multi_classes) AS class_name,
                r.name AS room_name
            FROM sessions se
            LEFT JOIN seating_plans sp ON sp.id = se.plan_id
            LEFT JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            LEFT JOIN rooms r ON r.id = sp.room_id
            WHERE se.date BETWEEN ? AND ?
            ORDER BY se.date, se.time_start
        ");
        $stmtWeek->execute([$weekStart, $weekEnd]);
        $weekSessions = $stmtWeek->fetchAll();
        $currentWeek  = $weekDate->format('o\-\WW');

        $plans = $db->query("
            SELECT sp.*, c.name as class_name, r.name as room_name
            FROM seating_plans sp
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            ORDER BY c.name
        ")->fetchAll();

        $pageTitle = 'Séances';

        ob_start();
        require __DIR__ . '/../../views/sessions/index.php';
        $content = ob_get_clean();

        require __DIR__ . '/../../views/layouts/app.php';
    }

    public function live(array $p): void
    {
        $db = Database::get();

        $stmtSession = $db->prepare("
            SELECT se.*, sp.name as plan_name, sp.room_id, sp.class_id,
                   COALESCE(g.name, c.name) AS class_name,
                   c.id  AS raw_class_id,
                   r.name as room_name,
                   r.`rows` as room_rows, r.`cols` as room_cols
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE se.id = ?
        ");
        $stmtSession->execute([$p['id']]);
        $session = $stmtSession->fetch();

        if (!$session) {
            http_response_code(404);
            return;
        }

        $stmtPrev = $db->prepare("
            SELECT se.id, se.date, se.time_start,
                   COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE c.id = ?
              AND (
                    se.date < ?
                    OR (se.date = ? AND (se.time_start IS NULL OR se.time_start < ?))
                  )
              AND se.id != ?
            ORDER BY se.date DESC, se.time_start DESC
            LIMIT 1
        ");
        $stmtPrev->execute([
            $session['raw_class_id'],
            $session['date'],
            $session['date'],
            $session['time_start'] ?? '99:99:99',
            $session['id'],
        ]);
        $prevRow = $stmtPrev->fetch() ?: null;
        $prevId  = $prevRow ? (int)$prevRow['id'] : null;

        $stmtNext = $db->prepare("
            SELECT se.id, se.date, se.time_start,
                   COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE c.id = ?
              AND (
                    se.date > ?
                    OR (se.date = ? AND se.time_start IS NOT NULL AND se.time_start > ?)
                  )
              AND se.id != ?
            ORDER BY se.date ASC, se.time_start ASC
            LIMIT 1
        ");
        $stmtNext->execute([
            $session['raw_class_id'],
            $session['date'],
            $session['date'],
            $session['time_start'] ?? '00:00:00',
            $session['id'],
        ]);
        $nextRow = $stmtNext->fetch() ?: null;
        $nextId  = $nextRow ? (int)$nextRow['id'] : null;

        $stmtSeats = $db->prepare("
            SELECT s.*, sa.student_id AS plan_student_id,
                   st.last_name, st.first_name
            FROM seats s
            LEFT JOIN seating_assignments sa
                   ON sa.seat_id = s.id AND sa.plan_id = ?
            LEFT JOIN students st ON st.id = sa.student_id
            WHERE s.room_id = ?
            ORDER BY s.row_index, s.col_index
        ");
        $stmtSeats->execute([$session['plan_id'], $session['room_id']]);
        $seatsRaw = $stmtSeats->fetchAll();

        $stmtOv = $db->prepare("
            SELECT sso.seat_id,
                   sso.student_id AS override_student_id,
                   st.last_name,
                   st.first_name
            FROM session_seat_overrides sso
            LEFT JOIN students st ON st.id = sso.student_id
            WHERE sso.session_id = ?
        ");
        $stmtOv->execute([$p['id']]);
        $overrides = [];
        foreach ($stmtOv->fetchAll() as $ov) {
            $overrides[(int)$ov['seat_id']] = $ov;
        }

        $seats = [];
        foreach ($seatsRaw as $seat) {
            $seatId = (int)$seat['id'];
            if (array_key_exists($seatId, $overrides)) {
                $ov = $overrides[$seatId];
                $seat['student_id'] = $ov['override_student_id'];
                $seat['last_name']  = $ov['last_name']  ?? null;
                $seat['first_name'] = $ov['first_name'] ?? null;
            } else {
                $seat['student_id'] = $seat['plan_student_id'];
            }
            $seats[] = $seat;
        }

        $tags = $db->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();

        $stmtObs = $db->prepare("
            SELECT o.*, t.color, t.icon
            FROM observations o
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
        ");
        $stmtObs->execute([$p['id']]);
        $observations = $stmtObs->fetchAll();

        require __DIR__ . '/../../views/sessions/live.php';
    }

    // API -------------------------------------------------------

    public function apiCreate(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['plan_id']) || empty($data['date'])) {
            Response::json(['error' => 'plan_id et date sont requis'], 400);
            return;
        }

        if (!\DateTime::createFromFormat('Y-m-d', $data['date'])) {
            Response::json(['error' => 'Format de date invalide (attendu : YYYY-MM-DD)'], 400);
            return;
        }

        foreach (['time_start', 'time_end'] as $field) {
            if (!empty($data[$field]) && !\DateTime::createFromFormat('H:i', $data[$field]) && !\DateTime::createFromFormat('H:i:s', $data[$field])) {
                Response::json(['error' => "Format d'heure invalide pour $field (attendu : HH:MM)"], 400);
                return;
            }
        }

        $db = Database::get();

        $stmtPlan = $db->prepare("SELECT id FROM seating_plans WHERE id = ?");
        $stmtPlan->execute([(int)$data['plan_id']]);
        if (!$stmtPlan->fetch()) {
            Response::json(['error' => 'Plan introuvable'], 404);
            return;
        }

        $db->prepare(
            "INSERT INTO sessions (plan_id, `date`, time_start, time_end, subject)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            (int)$data['plan_id'],
            $data['date'],
            $data['time_start'] ?? null,
            $data['time_end']   ?? null,
            $data['subject']    ?? null,
        ]);

        Response::json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    public function apiDelete(array $p): void
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM observations WHERE session_id = ?")->execute([$p['id']]);
            $db->prepare("DELETE FROM sessions WHERE id = ?")->execute([$p['id']]);
            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression'], 500);
        }
    }

    public function apiObservationsSummary(array $p): void
    {
        $stmt = Database::get()->prepare("
            SELECT st.first_name, st.last_name, o.tag, t.color, t.icon
            FROM observations o
            JOIN students st ON st.id = o.student_id
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
            ORDER BY st.last_name, st.first_name, o.tag
        ");
        $stmt->execute([$p['id']]);
        $rows = $stmt->fetchAll();

        Response::json(['count' => count($rows), 'rows' => $rows]);
    }

    public function apiObservationsExport(array $p): void
    {
        $db = Database::get();

        $stmtSes = $db->prepare("
            SELECT se.date, se.time_start, COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE se.id = ?
        ");
        $stmtSes->execute([$p['id']]);
        $ses = $stmtSes->fetch();

        if (!$ses) {
            http_response_code(404);
            echo 'Séance introuvable';
            return;
        }

        $stmtObs = $db->prepare("
            SELECT st.last_name, st.first_name, o.tag, o.note, o.created_at
            FROM observations o
            JOIN students st ON st.id = o.student_id
            WHERE o.session_id = ?
            ORDER BY st.last_name, st.first_name, o.tag
        ");
        $stmtObs->execute([$p['id']]);
        $rows = $stmtObs->fetchAll();

        $filename = 'observations_'
            . str_replace([' ', '/'], '_', $ses['class_name'])
            . '_' . $ses['date']
            . ($ses['time_start'] ? '_' . substr($ses['time_start'], 0, 5) : '')
            . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Nom', 'Prénom', 'Tag', 'Note', 'Horodatage'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['last_name'],
                $row['first_name'],
                $row['tag'],
                $row['note'] ?? '',
                $row['created_at'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function apiAddObservation(array $p): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['student_id']) || empty($data['tag'])) {
            Response::json(['error' => 'student_id et tag sont requis'], 400);
            return;
        }

        $db = Database::get();
        $db->prepare(
            "INSERT INTO observations (session_id, student_id, tag, note) VALUES (?, ?, ?, ?)"
        )->execute([$p['id'], $data['student_id'], $data['tag'], $data['note'] ?? null]);

        Response::json(['ok' => true, 'obs_id' => (int)$db->lastInsertId()]);
    }

    public function apiRemoveObservation(array $p): void
    {
        $stmt = Database::get()->prepare("DELETE FROM observations WHERE id = ? AND session_id = ?");
        $stmt->execute([$p['obs_id'], $p['id']]);

        if ($stmt->rowCount() === 0) {
            Response::json(['error' => 'Observation introuvable ou non autorisée'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    public function apiGetObservations(array $p): void
    {
        $stmtObs = Database::get()->prepare("
            SELECT o.*, t.color, t.icon
            FROM observations o
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
        ");
        $stmtObs->execute([$p['id']]);
        Response::json($stmtObs->fetchAll());
    }

    public function apiGetTags(): void
    {
        Response::json(Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll());
    }

    public function apiSaveTag(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['label'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        $db = Database::get();
        if (!empty($data['id'])) {
            $db->prepare("UPDATE tags SET label = ?, color = ?, icon = ? WHERE id = ?")
               ->execute([$data['label'], $data['color'], $data['icon'], $data['id']]);
        } else {
            $db->prepare("INSERT INTO tags (label, color, icon, sort_order) VALUES (?, ?, ?, ?)")
               ->execute([$data['label'], $data['color'], $data['icon'] ?? '', $data['sort_order'] ?? 99]);
        }

        Response::json(['ok' => true]);
    }

    public function apiDeleteTag(array $p): void
    {
        $db = Database::get();

        if (empty($_GET['force'])) {
            $stmtCheck = $db->prepare(
                "SELECT COUNT(*) FROM observations o
                JOIN tags t ON t.label = o.tag
                WHERE t.id = ?"
            );
            $stmtCheck->execute([$p['id']]);
            $count = (int)$stmtCheck->fetchColumn();

            if ($count > 0) {
                Response::json([
                    'error'     => "Ce tag est utilisé dans $count observation(s) existante(s). Supprimez-les d'abord ou forcez la suppression.",
                    'count'     => $count,
                    'can_force' => true,
                ], 409);
                return;
            }
        }

        $db->prepare("DELETE FROM tags WHERE id = ?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }

    public function tagsIndex(): void
    {
        $tags = Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();
        $pageTitle = 'Tags';
        ob_start();
        require ROOT . '/views/tags/index.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    /**
     * Swap atomique de deux élèves dans seating_assignments.
     */
    private function swapPlanAssignments(
        \PDO $db,
        int  $planId,
        int  $sourceSeatId,
        int  $studentId,
        int  $targetSeatId,
        ?int $targetStudentId
    ): void {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');

        $db->prepare("
            UPDATE seating_assignments
            SET student_id = 0
            WHERE seat_id = ? AND plan_id = ? AND student_id = ?
        ")->execute([$sourceSeatId, $planId, $studentId]);

        if ($targetStudentId !== null) {
            $db->prepare("
                UPDATE seating_assignments
                SET student_id = ?
                WHERE seat_id = ? AND plan_id = ?
            ")->execute([$studentId, $targetSeatId, $planId]);
        } else {
            $db->prepare("
                INSERT INTO seating_assignments (plan_id, seat_id, student_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE student_id = VALUES(student_id)
            ")->execute([$planId, $targetSeatId, $studentId]);
        }

        if ($targetStudentId !== null) {
            $db->prepare("
                UPDATE seating_assignments
                SET student_id = ?
                WHERE seat_id = ? AND plan_id = ? AND student_id = 0
            ")->execute([$targetStudentId, $sourceSeatId, $planId]);
        } else {
            $db->prepare("
                DELETE FROM seating_assignments
                WHERE seat_id = ? AND plan_id = ? AND student_id = 0
            ")->execute([$sourceSeatId, $planId]);
        }

        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Après un swap de plan, recalcule proprement les overrides
     * de la séance courante pour les sièges impactés.
     *
     * Principe : on supprime TOUS les overrides existants sur les
     * sièges concernés, puis on réécrit uniquement ceux qui divergent
     * du nouvel état du plan (= évite les doublons et les fantômes).
     *
     * @param int[]   $seatIds     Tous les seat_id à recalculer (plan + visuel)
     * @param array   $newPlanMap  [seat_id => student_id|null]  état du plan après swap
     * @param array   $wantedMap   [seat_id => student_id|null]  état visuel voulu pour s
     */
    private function resyncSessionOverrides(
        \PDO $db,
        int  $sessionId,
        array $seatIds,
        array $newPlanMap,
        array $wantedMap
    ): void {
        if (empty($seatIds)) return;

        // 1. Supprimer tous les overrides existants sur ces sièges pour cette séance
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $db->prepare("
            DELETE FROM session_seat_overrides
            WHERE session_id = ? AND seat_id IN ($in)
        ")->execute(array_merge([$sessionId], $seatIds));

        // 2. Réécrire uniquement les overrides qui divergent du plan
        $stmtIns = $db->prepare("
            INSERT INTO session_seat_overrides (session_id, seat_id, student_id)
            VALUES (?, ?, ?)
        ");
        foreach ($seatIds as $seatId) {
            $planVal   = $newPlanMap[$seatId]  ?? null;
            $wantedVal = $wantedMap[$seatId]   ?? null;
            if ($wantedVal !== $planVal) {
                $stmtIns->execute([$sessionId, $seatId, $wantedVal]);
            }
        }
    }

    public function apiMoveSeat(array $p): void
    {
        set_error_handler(function () {});

        $data = json_decode(file_get_contents('php://input'), true);

        $studentId    = (int)($data['student_id']    ?? 0);
        $sourceSeatId = (int)($data['source_seat_id'] ?? 0); // position visuelle (peut être un override)
        $targetSeatId = (int)($data['target_seat_id'] ?? 0);
        $sessionId    = (int)$p['id'];

        $rawScope = $data['scope'] ?? 'session';
        $scope    = in_array($rawScope, ['session', 'plan', 'all'], true) ? $rawScope : 'session';

        if (!$studentId || !$sourceSeatId || !$targetSeatId || !$sessionId) {
            restore_error_handler();
            Response::json(['error' => 'Paramètres manquants'], 400);
            return;
        }

        $db = Database::get();

        $stmt = $db->prepare("SELECT plan_id, `date`, time_start FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            restore_error_handler();
            Response::json(['error' => 'Séance introuvable'], 404);
            return;
        }

        $planId      = (int)$session['plan_id'];
        $sessionDate = $session['date'];
        $sessionTime = $session['time_start'];

        try {
            $db->beginTransaction();

            if ($scope === 'session') {
                // ── Override uniquement pour cette séance ──────────────────────

                $stmtOvTgt = $db->prepare("
                    SELECT student_id FROM session_seat_overrides
                    WHERE session_id = ? AND seat_id = ?
                ");
                $stmtOvTgt->execute([$sessionId, $targetSeatId]);
                $ovRow = $stmtOvTgt->fetch();

                if ($ovRow) {
                    $targetStudentId = $ovRow['student_id'] !== null ? (int)$ovRow['student_id'] : null;
                } else {
                    $stmtPlanTgt = $db->prepare("
                        SELECT student_id FROM seating_assignments
                        WHERE seat_id = ? AND plan_id = ?
                    ");
                    $stmtPlanTgt->execute([$targetSeatId, $planId]);
                    $planRow = $stmtPlanTgt->fetch();
                    $targetStudentId = $planRow ? (int)$planRow['student_id'] : null;
                }

                $db->prepare("
                    INSERT INTO session_seat_overrides (session_id, seat_id, student_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE student_id = VALUES(student_id)
                ")->execute([$sessionId, $sourceSeatId, $targetStudentId]);

                $db->prepare("
                    INSERT INTO session_seat_overrides (session_id, seat_id, student_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE student_id = VALUES(student_id)
                ")->execute([$sessionId, $targetSeatId, $studentId]);

            } elseif ($scope === 'plan') {
                // ── Modification du plan → affecte les futures séances ────────

                // Retrouver la VRAIE position de l'élève dans le plan
                $stmtRealSrc = $db->prepare("
                    SELECT seat_id FROM seating_assignments
                    WHERE student_id = ? AND plan_id = ?
                ");
                $stmtRealSrc->execute([$studentId, $planId]);
                $realSrcRow = $stmtRealSrc->fetch();

                if (!$realSrcRow) {
                    $db->rollBack();
                    restore_error_handler();
                    Response::json(['error' => 'Élève introuvable dans le plan'], 404);
                    return;
                }
                $planSourceSeatId = (int)$realSrcRow['seat_id'];

                // Occupant actuel du siège cible dans le plan
                $stmtTgt = $db->prepare("
                    SELECT student_id FROM seating_assignments
                    WHERE seat_id = ? AND plan_id = ?
                ");
                $stmtTgt->execute([$targetSeatId, $planId]);
                $targetRow       = $stmtTgt->fetch();
                $targetStudentId = $targetRow ? (int)$targetRow['student_id'] : null;

                // Photographier les séances antérieures sans override sur ces sièges
                $stmtPastSessions = $db->prepare("
                    SELECT se.id AS session_id,
                           s.id  AS seat_id,
                           COALESCE(sso.student_id, sa.student_id) AS frozen_student_id
                    FROM sessions se
                    JOIN seats s ON s.room_id = (
                        SELECT room_id FROM seating_plans WHERE id = ?
                    )
                    LEFT JOIN session_seat_overrides sso
                           ON sso.session_id = se.id AND sso.seat_id = s.id
                    LEFT JOIN seating_assignments sa
                           ON sa.seat_id = s.id AND sa.plan_id = ?
                    WHERE se.plan_id = ?
                      AND se.id != ?
                      AND s.id IN (?, ?)
                      AND sso.seat_id IS NULL
                      AND (
                            se.date < ?
                            OR (
                                 se.date = ?
                                 AND ? IS NOT NULL
                                 AND (se.time_start IS NULL OR se.time_start < ?)
                               )
                          )
                ");
                $stmtPastSessions->execute([
                    $planId, $planId, $planId, $sessionId,
                    $planSourceSeatId, $targetSeatId,
                    $sessionDate, $sessionDate, $sessionTime, $sessionTime,
                ]);
                $pastRows = $stmtPastSessions->fetchAll();

                $stmtFreeze = $db->prepare("
                    INSERT IGNORE INTO session_seat_overrides (session_id, seat_id, student_id)
                    VALUES (?, ?, ?)
                ");
                foreach ($pastRows as $row) {
                    $stmtFreeze->execute([
                        (int)$row['session_id'],
                        (int)$row['seat_id'],
                        $row['frozen_student_id'] !== null ? (int)$row['frozen_student_id'] : null,
                    ]);
                }

                // Swap atomique dans le plan
                $this->swapPlanAssignments(
                    $db, $planId, $planSourceSeatId, $studentId, $targetSeatId, $targetStudentId
                );

                // État du plan APRÈS le swap
                // planSourceSeatId → targetStudentId (ou null)
                // targetSeatId     → studentId
                $newPlanMap = [
                    $planSourceSeatId => $targetStudentId,
                    $targetSeatId     => $studentId,
                ];
                // Si le sourceSeatId VISUEL diffère du siège plan,
                // le plan ne l'a pas touché : on lit son état actuel
                if ($sourceSeatId !== $planSourceSeatId) {
                    $stmtVisualPlan = $db->prepare("
                        SELECT student_id FROM seating_assignments
                        WHERE seat_id = ? AND plan_id = ?
                    ");
                    $stmtVisualPlan->execute([$sourceSeatId, $planId]);
                    $visualPlanRow = $stmtVisualPlan->fetch();
                    $newPlanMap[$sourceSeatId] = $visualPlanRow ? (int)$visualPlanRow['student_id'] : null;
                }

                // État VOULU visuellement pour la séance s
                // Le visuel que l'utilisateur vient de valider :
                //   - siège visuel source (sourceSeatId) → targetStudentId (l'occupant du plan cible)
                //   - siège cible          (targetSeatId)  → studentId (A)
                //   - siège plan source   (planSourceSeatId) → si diff du visuel,
                //     il faut y mettre targetStudentId (car le plan vient d'être bougé)
                $wantedMap = [
                    $sourceSeatId     => $targetStudentId, // là où l'utilisateur a pris A
                    $targetSeatId     => $studentId,       // là où A est déposé
                ];
                if ($sourceSeatId !== $planSourceSeatId) {
                    // Le siège plan de A (planSourceSeatId) doit recevoir
                    // ce que le plan y a mis après le swap = targetStudentId
                    // mais comme c'est également ce que le plan dit, pas besoin d'override.
                    // En revanche le siège visuel source (sourceSeatId) contenait A
                    // grâce à un override scope session ; maintenant que le plan a bougé,
                    // planSourceSeatId contient targetStudentId dans le plan.
                    // sourceSeatId dans le plan contient son occupant habituel (inchangé).
                    // L'utilisateur a pris A visuellement en sourceSeatId donc
                    // on veut que sourceSeatId affiche targetStudentId pour la séance s.
                    // (déjà dans $wantedMap ci-dessus)
                    // planSourceSeatId : le plan y met targetStudentId, pas d'override nécessaire
                    // (sera géré par resyncSessionOverrides qui comparera avec newPlanMap)
                    $wantedMap[$planSourceSeatId] = $targetStudentId;
                }

                // Tous les sièges concernés
                $affectedSeats = array_values(array_unique([
                    $planSourceSeatId,
                    $targetSeatId,
                    $sourceSeatId,
                ]));

                // Purger les overrides des séances strictement postérieures
                $db->prepare("
                    DELETE sso FROM session_seat_overrides sso
                    JOIN sessions se ON se.id = sso.session_id
                    WHERE sso.seat_id IN (?, ?)
                      AND se.plan_id = ?
                      AND se.id != ?
                      AND (
                            se.date > ?
                            OR (
                                 se.date = ?
                                 AND ? IS NOT NULL
                                 AND se.time_start IS NOT NULL
                                 AND se.time_start > ?
                               )
                          )
                ")->execute([
                    $planSourceSeatId, $targetSeatId, $planId, $sessionId,
                    $sessionDate, $sessionDate, $sessionTime, $sessionTime,
                ]);

                // Resync propre des overrides de la séance courante
                $this->resyncSessionOverrides(
                    $db, $sessionId, $affectedSeats, $newPlanMap, $wantedMap
                );

            } else {
                // ── scope === 'all' : toutes les séances ──────────────────────

                $stmtRealSrc = $db->prepare("
                    SELECT seat_id FROM seating_assignments
                    WHERE student_id = ? AND plan_id = ?
                ");
                $stmtRealSrc->execute([$studentId, $planId]);
                $realSrcRow = $stmtRealSrc->fetch();

                if (!$realSrcRow) {
                    $db->rollBack();
                    restore_error_handler();
                    Response::json(['error' => 'Élève introuvable dans le plan'], 404);
                    return;
                }
                $planSourceSeatId = (int)$realSrcRow['seat_id'];

                $stmtTgt = $db->prepare("
                    SELECT student_id FROM seating_assignments
                    WHERE seat_id = ? AND plan_id = ?
                ");
                $stmtTgt->execute([$targetSeatId, $planId]);
                $targetRow       = $stmtTgt->fetch();
                $targetStudentId = $targetRow ? (int)$targetRow['student_id'] : null;

                // Swap atomique dans le plan
                $this->swapPlanAssignments(
                    $db, $planId, $planSourceSeatId, $studentId, $targetSeatId, $targetStudentId
                );

                // Supprimer TOUS les overrides sur ces sièges (toutes séances)
                $allSeats = array_values(array_unique([$planSourceSeatId, $targetSeatId, $sourceSeatId]));
                $in = implode(',', array_fill(0, count($allSeats), '?'));
                $db->prepare("
                    DELETE sso FROM session_seat_overrides sso
                    JOIN sessions se ON se.id = sso.session_id
                    WHERE sso.seat_id IN ($in)
                      AND se.plan_id = ?
                ")->execute(array_merge($allSeats, [$planId]));
            }

            $db->commit();
            restore_error_handler();

            Response::json([
                'ok'                 => true,
                'scope'              => $scope,
                'swapped_student_id' => $targetStudentId ?? null,
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable $ignored) {}
            restore_error_handler();
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiRemoveStudent(array $p): void
    {
        $sessionId = (int)$p['id'];
        $studentId = (int)$p['student_id'];

        $db = Database::get();

        $stmt = $db->prepare("SELECT plan_id FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $planId = (int)$stmt->fetchColumn();

        if (!$planId) {
            Response::json(['error' => 'Séance introuvable'], 404);
            return;
        }

        $stmtSeat = $db->prepare("
            SELECT COALESCE(sso.seat_id, sa.seat_id) AS seat_id
            FROM students st
            LEFT JOIN session_seat_overrides sso
                   ON sso.student_id = st.id AND sso.session_id = ?
            LEFT JOIN seating_assignments sa
                   ON sa.student_id = st.id AND sa.plan_id = ?
            WHERE st.id = ?
            LIMIT 1
        ");
        $stmtSeat->execute([$sessionId, $planId, $studentId]);
        $seatId = $stmtSeat->fetchColumn();

        if (!$seatId) {
            Response::json(['error' => 'Élève non placé dans cette séance'], 404);
            return;
        }

        $db->prepare("
            INSERT INTO session_seat_overrides (session_id, seat_id, student_id)
            VALUES (?, ?, NULL)
            ON DUPLICATE KEY UPDATE student_id = NULL
        ")->execute([$sessionId, (int)$seatId]);

        Response::json(['ok' => true]);
    }
}
