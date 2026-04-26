<?php
// src/controllers/SessionController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class SessionController
{
    public function index(): void
    {
        $db = Database::get();

        // Pagination : 100 séances par page, triées par date décroissante
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 100;
        $offset  = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT se.*, sp.name AS plan_name,
                COALESCE(g.name, c.name) AS class_name,
                r.name AS room_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            JOIN rooms r ON r.id = sp.room_id
            ORDER BY se.date DESC, se.time_start DESC, se.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $stmt->execute([(int)$perPage, (int)$offset]);
        $sessions = $stmt->fetchAll();

        $total      = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $totalPages = (int)ceil($total / $perPage);

        // Semaine affichée (paramètre ?week=2025-W36, défaut = semaine courante)
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
                COALESCE(g.name, c.name) AS class_name,
                r.name AS room_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE se.date BETWEEN ? AND ?
            ORDER BY se.date, se.time_start
        ");
        $stmtWeek->execute([$weekStart, $weekEnd]);
        $weekSessions = $stmtWeek->fetchAll();
        $currentWeek  = $weekDate->format('o\-\WW'); // ex: "2025-W36"        

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
                   c.name as class_name, r.name as room_name,
                   r.`rows` as room_rows, r.`cols` as room_cols
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE se.id = ?
        ");
        $stmtSession->execute([$p['id']]);
        $session = $stmtSession->fetch();

        if (!$session) {
            http_response_code(404);
            return;
        }

        $stmtSeats = $db->prepare("
            SELECT s.*, sa.student_id, st.last_name, st.first_name
            FROM seats s
            LEFT JOIN seating_assignments sa ON sa.seat_id = s.id AND sa.plan_id = ?
            LEFT JOIN students st ON st.id = sa.student_id
            WHERE s.room_id = ?
            ORDER BY s.row_index, s.col_index
        ");
        $stmtSeats->execute([$session['plan_id'], $session['room_id']]);
        $seats = $stmtSeats->fetchAll();

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

        // validation du format de date
        if (!\DateTime::createFromFormat('Y-m-d', $data['date'])) {
            Response::json(['error' => 'Format de date invalide (attendu : YYYY-MM-DD)'], 400);
            return;
        }

        // Validation optionnelle des heures
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

    /**
     * Retourne le résumé des observations d'une séance (avant suppression).
     * GET /api/sessions/:id/observations-summary
     */
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

        Response::json([
            'count' => count($rows),
            'rows'  => $rows,
        ]);
    }

    /**
     * Export CSV des observations d'une séance.
     * GET /api/sessions/:id/observations-export
     */
    public function apiObservationsExport(array $p): void
    {
        $db = Database::get();

        // Infos séance pour le nom du fichier
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
        // BOM UTF-8 pour Excel
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

    // vérification que l'observation appartient bien à la séance
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

    public function apiMoveSeat(array $p): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $studentId    = (int)($data['student_id']    ?? 0);
        $sourceSeatId = (int)($data['source_seat_id'] ?? 0);
        $targetSeatId = (int)($data['target_seat_id'] ?? 0);
        $sessionId    = (int)$p['id'];

        if (!$studentId || !$sourceSeatId || !$targetSeatId || !$sessionId) {
            Response::json(['error' => 'Paramètres manquants'], 400);
            return;
        }

        $db = Database::get();

        $stmt = $db->prepare("SELECT plan_id FROM sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $planId = (int)$stmt->fetchColumn();

        if (!$planId) {
            Response::json(['error' => 'Séance introuvable'], 404);
            return;
        }

        try {
            $db->beginTransaction();

            // Qui est sur la cible ?
            $stmt = $db->prepare("
                SELECT id, student_id FROM seating_assignments
                WHERE seat_id = ? AND plan_id = ?
            ");
            $stmt->execute([$targetSeatId, $planId]);
            $targetRow = $stmt->fetch();
            $targetStudentId  = $targetRow ? (int)$targetRow['student_id'] : null;
            $targetAssignId   = $targetRow ? (int)$targetRow['id'] : null;

            // Étape 1 : supprimer l'affectation cible pour libérer seat_id (contourne NOT NULL)
            if ($targetStudentId) {
                $db->prepare("
                    DELETE FROM seating_assignments WHERE id = ?
                ")->execute([$targetAssignId]);
            }

            // Étape 2 : déplacer la source → cible
            $db->prepare("
                UPDATE seating_assignments
                SET seat_id = ?
                WHERE seat_id = ? AND plan_id = ? AND student_id = ?
            ")->execute([$targetSeatId, $sourceSeatId, $planId, $studentId]);

            // Étape 3 : recréer l'affectation de l'ancien élève cible sur la source
            if ($targetStudentId) {
                $db->prepare("
                    INSERT INTO seating_assignments (plan_id, seat_id, student_id)
                    VALUES (?, ?, ?)
                ")->execute([$planId, $sourceSeatId, $targetStudentId]);
            }

            $db->commit();

            Response::json([
                'ok'                 => true,
                'swapped_student_id' => $targetStudentId,
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
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

        $stmt = $db->prepare("
            DELETE FROM seating_assignments
            WHERE plan_id = ? AND student_id = ?
        ");
        $stmt->execute([$planId, $studentId]);

        if ($stmt->rowCount() === 0) {
            Response::json(['error' => 'Affectation introuvable'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }
}
