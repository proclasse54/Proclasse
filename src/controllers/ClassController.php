<?php
// src/controllers/ClassController.php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class ClassController {

    public function index(): void {
        $db = Database::get();
        $classes = $db->query("
            SELECT c.*, COUNT(s.id) as student_count
            FROM classes c
            LEFT JOIN students s ON s.class_id = c.id
            GROUP BY c.id ORDER BY c.name
        ")->fetchAll();
        require ROOT . '/views/classes/index.php';
    }

    public function show(array $p): void {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM classes WHERE id=?");
        $stmt->execute([$p['id']]);
        $class = $stmt->fetch();
        if (!$class) { http_response_code(404); return; }

        $stmt2 = $db->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmt2->execute([$p['id']]);
        $students = $stmt2->fetchAll();

        $rooms = $db->query("SELECT * FROM rooms ORDER BY name")->fetchAll();

        $stmt3 = $db->prepare("SELECT sp.*, r.name as room_name FROM seating_plans sp JOIN rooms r ON r.id=sp.room_id WHERE sp.class_id=?");
        $stmt3->execute([$p['id']]);
        $plans = $stmt3->fetchAll();

        require ROOT . '/views/classes/show.php';
    }

    public function planEdit(array $p): void {
        $planId = (int)$p['plan_id'];
        $db = Database::get();

        $stmt = $db->prepare("
            SELECT sp.*, c.name AS class_name, c.id AS class_id,
                   r.name AS room_name, r.`cols` AS room_cols, r.`rows` AS room_rows, r.id AS room_id
            FROM seating_plans sp
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE sp.id = ?
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if (!$plan) { http_response_code(404); return; }

        $stmt3 = $db->prepare("SELECT id, first_name, last_name FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmt3->execute([$plan['class_id']]);
        $students = $stmt3->fetchAll();
        $stmt4 = $db->prepare("
            SELECT sa.seat_id, sa.student_id, st.first_name, st.last_name
            FROM seating_assignments sa
            JOIN students st ON st.id = sa.student_id
            WHERE sa.plan_id = ?
        ");
        $stmt4->execute([$planId]);
        $assignments = $stmt4->fetchAll();
        $assignedStudents = array_column($assignments, null, 'student_id');

        $stmt_seats = $db->prepare("
            SELECT s.*, sa.student_id, st.first_name, st.last_name
            FROM seats s
            LEFT JOIN seating_assignments sa ON sa.seat_id = s.id AND sa.plan_id = ?
            LEFT JOIN students st ON st.id = sa.student_id
            WHERE s.room_id = ?
            ORDER BY s.row_index, s.col_index
        ");
        $stmt_seats->execute([$planId, $plan['room_id']]);
        $seats = $stmt_seats->fetchAll();

        $room = ['cols' => $plan['room_cols'], 'rows' => $plan['room_rows']];
        require ROOT . '/views/plans/edit.php';
    }

    // ── API ────────────────────────────────────────────────────

    public function apiSaveClass(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::get();
        if (!empty($p['id'])) {
            $db->prepare("UPDATE classes SET name=?, year=? WHERE id=?")->execute([$data['name'], $data['year'] ?? null, $p['id']]);
            $id = (int)$p['id'];
        } else {
            $db->prepare("INSERT INTO classes (name, year) VALUES (?,?)")->execute([$data['name'], $data['year'] ?? null]);
            $id = (int)$db->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function apiDeleteClass(array $p): void {
        Database::get()->prepare("DELETE FROM classes WHERE id=?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }

    public function apiImportStudents(array $p): void {
        require_once __DIR__ . '/PronoteImportController.php';
        (new PronoteImportController)->import($p);
    }

    public function apiGetStudents(array $p): void {
        $stmt = Database::get()->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmt->execute([$p['id']]);
        Response::json($stmt->fetchAll());
    }

    public function apiSavePlan(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['room_id'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }        

        $db = Database::get();
        $stmt = $db->prepare("SELECT id FROM seating_plans WHERE class_id=? AND room_id=? AND name=?");
        $stmt->execute([$p['id'], $data['room_id'], $data['name'] ?? 'Plan par défaut']);
        $existing = $stmt->fetch();
        if ($existing) {
            $planId = $existing['id'];
        } else {
            $db->prepare("INSERT INTO seating_plans (class_id, room_id, name) VALUES (?,?,?)")
               ->execute([$p['id'], $data['room_id'], $data['name'] ?? 'Plan par défaut']);
            $planId = (int)$db->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $planId]);
    }

    public function apiGetPlan(array $p): void {
        $stmt = Database::get()->prepare("
            SELECT sa.seat_id, sa.student_id, s.last_name, s.first_name
            FROM seating_assignments sa
            JOIN students s ON s.id = sa.student_id
            WHERE sa.plan_id = ?
        ");
        $stmt->execute([$p['plan_id']]);
        Response::json($stmt->fetchAll());
    }

    public function apiDeletePlan(array $p): void {
        Database::get()->prepare("DELETE FROM seating_plans WHERE id=?")->execute([$p['plan_id']]);
        Response::json(['ok' => true]);
    }

    public function apiSaveAssignments(array $p): void {
        $planId = (int)$p['plan_id'];
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['assignments'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }        

        $db = Database::get();
        $db->prepare("DELETE FROM seating_assignments WHERE plan_id=?")->execute([$planId]);
        $stmt = $db->prepare("INSERT INTO seating_assignments (plan_id, seat_id, student_id) VALUES (?,?,?)");
        foreach ($data['assignments'] ?? [] as $a) {
            $stmt->execute([$planId, (int)$a['seat_id'], (int)$a['student_id']]);
        }
        Response::json(['ok' => true]);
    }

    public function apiImportPaste(array $p): void {
        $body = json_decode(file_get_contents('php://input'), true);
        $raw  = $body['data'] ?? '';
        if (!$raw) { Response::json(['error' => 'données vides'], 400); return; }

        // Simuler un fichier temporaire pour réutiliser PronoteImportController
        $tmp = tempnam(sys_get_temp_dir(), 'pronote_');
        register_shutdown_function('unlink', $tmp);

        // Encoder en UTF-8 (le texte collé depuis Pronote Windows peut être CP1252)
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        file_put_contents($tmp, $raw);

        // Créer une fausse entrée $_FILES pour réutiliser l'importeur existant
        $_FILES['csv'] = [
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($raw),
        ];

        require_once __DIR__ . '/PronoteImportController.php';
        (new PronoteImportController)->import($p);
    }
}
