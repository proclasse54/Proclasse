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
            SELECT se.*, sp.name as plan_name, c.name as class_name, r.name as room_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
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
            SELECT se.*, sp.name as plan_name, c.name as class_name, r.name as room_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
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


}
