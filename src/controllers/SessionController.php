<?php
// src/controllers/SessionController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class SessionController {

    public function index(): void {
        $db = Database::get();
        $sessions = $db->query("
            SELECT se.*, sp.name as plan_name, c.name as class_name, r.name as room_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            ORDER BY se.date DESC, se.created_at DESC
            LIMIT 50
        ")->fetchAll();
        $plans = $db->query("SELECT sp.*, c.name as class_name, r.name as room_name FROM seating_plans sp JOIN classes c ON c.id=sp.class_id JOIN rooms r ON r.id=sp.room_id ORDER BY c.name")->fetchAll();
        require __DIR__ . '/../../views/sessions/index.php';
    }

    public function live(array $p): void {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT se.*, sp.name as plan_name, sp.room_id, sp.class_id,
                   c.name as class_name, r.name as room_name, r.`rows` as room_rows, r.`cols` as room_cols
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE se.id = ?
        ");
        $stmt->execute([$p['id']]);
        $session = $stmt->fetch();
        if (!$session) { http_response_code(404); return; }

        $stmt4 = $db->prepare("
            SELECT s.*, sa.student_id, st.last_name, st.first_name
            FROM seats s
            LEFT JOIN seating_assignments sa ON sa.seat_id=s.id AND sa.plan_id=?
            LEFT JOIN students st ON st.id=sa.student_id
            WHERE s.room_id=?
            ORDER BY s.row_index, s.col_index
        ");
        $stmt4->execute([$session['plan_id'], $session['room_id']]);
        $seats = $stmt4->fetchAll();

        $tags = $db->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();
        $stmt5 = $db->prepare("SELECT * FROM observations WHERE session_id=?");
        $stmt5->execute([$p['id']]);
        $observations = $stmt5->fetchAll();
        require __DIR__ . '/../../views/sessions/live.php';
    }

    // API -------------------------------------------------------
    public function apiCreate(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['plan_id']) || empty($data['date'])) {
            Response::json(['error' => 'plan_id et date sont requis'], 400);
            return;
        }
        $db = Database::get();
        // Vérifier que le plan existe
        $chk = $db->prepare("SELECT id FROM seating_plans WHERE id=?");
        $chk->execute([(int)$data['plan_id']]);
        if (!$chk->fetch()) {
            Response::json(['error' => 'Plan introuvable'], 404);
            return;
        }
        $db->prepare("INSERT INTO sessions (plan_id, date, subject) VALUES (?,?,?)")
        ->execute([(int)$data['plan_id'], $data['date'], $data['subject'] ?? null]);
        Response::json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    public function apiDelete(array $p): void {
        Database::get()->prepare("DELETE FROM sessions WHERE id=?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }

    public function apiAddObservation(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['student_id']) || empty($data['tag'])) {
            Response::json(['error' => 'student_id et tag sont requis'], 400);
            return;
        }        
        $db = Database::get();
        $db->prepare("INSERT INTO observations (session_id, student_id, tag, note) VALUES (?,?,?,?)")
           ->execute([$p['id'], $data['student_id'], $data['tag'], $data['note'] ?? null]);
        $obsId = (int)$db->lastInsertId();
        Response::json(['ok' => true, 'obs_id' => $obsId]);
    }

    public function apiRemoveObservation(array $p): void {
        Database::get()->prepare("DELETE FROM observations WHERE id=?")->execute([$p['obs_id']]);
        Response::json(['ok' => true]);
    }

    public function apiGetObservations(array $p): void {
        $stmt = Database::get()->prepare("SELECT o.*, t.color, t.icon FROM observations o LEFT JOIN tags t ON t.label=o.tag WHERE o.session_id=?");
        $stmt->execute([$p['id']]);
        Response::json($stmt->fetchAll());
    }

    public function apiGetTags(): void {
        Response::json(Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll());
    }

    public function apiSaveTag(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['label'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }        
        $db = Database::get();
        if (!empty($data['id'])) {
            $db->prepare("UPDATE tags SET label=?, color=?, icon=? WHERE id=?")->execute([$data['label'], $data['color'], $data['icon'], $data['id']]);
        } else {
            $db->prepare("INSERT INTO tags (label, color, icon, sort_order) VALUES (?,?,?,?)")->execute([$data['label'], $data['color'], $data['icon'] ?? '', $data['sort_order'] ?? 99]);
        }
        Response::json(['ok' => true]);
    }

    public function apiDeleteTag(array $p): void {
        Database::get()->prepare("DELETE FROM tags WHERE id=?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }
}
