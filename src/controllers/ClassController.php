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

        $pageTitle = 'Classes';
        ob_start();
        require ROOT . '/views/classes/index.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    public function show(array $p): void {
        $db = Database::get();

        $stmtClass = $db->prepare("SELECT * FROM classes WHERE id=?");
        $stmtClass->execute([$p['id']]);
        $class = $stmtClass->fetch();
        if (!$class) { http_response_code(404); return; }

        $stmtStudents = $db->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmtStudents->execute([$p['id']]);
        $students = $stmtStudents->fetchAll();

        $rooms = $db->query("SELECT * FROM rooms ORDER BY name")->fetchAll();

        $stmtPlans = $db->prepare("SELECT sp.*, r.name as room_name FROM seating_plans sp JOIN rooms r ON r.id=sp.room_id WHERE sp.class_id=?");
        $stmtPlans->execute([$p['id']]);
        $plans = $stmtPlans->fetchAll();

        $stmtGroups = $db->prepare("
            SELECT g.*, COUNT(gs.student_id) as student_count
            FROM `groups` g
            LEFT JOIN group_students gs ON gs.group_id = g.id
            WHERE g.class_id = ?
            GROUP BY g.id
            ORDER BY g.name
        ");
        $stmtGroups->execute([$p['id']]);
        $groups = $stmtGroups->fetchAll();

        $pageTitle = htmlspecialchars($class['name']);
        ob_start();
        require ROOT . '/views/classes/show.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    public function planEdit(array $p): void {
        $planId = (int)$p['plan_id'];
        $db = Database::get();

        $stmtPlan = $db->prepare("
            SELECT sp.*, c.name AS class_name, c.id AS class_id,
                   r.name AS room_name, r.`cols` AS room_cols, r.`rows` AS room_rows, r.id AS room_id
            FROM seating_plans sp
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE sp.id = ?
        ");
        $stmtPlan->execute([$planId]);
        $plan = $stmtPlan->fetch();
        if (!$plan) { http_response_code(404); return; }

        if (!empty($plan['group_id'])) {
            $stmtStudents = $db->prepare("
                SELECT s.id, s.first_name, s.last_name
                FROM students s
                JOIN group_students gs ON gs.student_id = s.id
                WHERE gs.group_id = ?
                ORDER BY s.last_name, s.first_name
            ");
            $stmtStudents->execute([$plan['group_id']]);
        } else {
            $stmtStudents = $db->prepare(
                "SELECT id, first_name, last_name FROM students WHERE class_id=? ORDER BY last_name, first_name"
            );
            $stmtStudents->execute([$plan['class_id']]);
        }
        $students = $stmtStudents->fetchAll();

        $stmtAssignments = $db->prepare("
            SELECT sa.seat_id, sa.student_id, st.first_name, st.last_name
            FROM seating_assignments sa
            JOIN students st ON st.id = sa.student_id
            WHERE sa.plan_id = ?
        ");
        $stmtAssignments->execute([$planId]);
        $assignments = $stmtAssignments->fetchAll();
        $assignedStudents = array_column($assignments, null, 'student_id');

        $stmtSeats = $db->prepare("
            SELECT s.*, sa.student_id, st.first_name, st.last_name
            FROM seats s
            LEFT JOIN seating_assignments sa ON sa.seat_id = s.id AND sa.plan_id = ?
            LEFT JOIN students st ON st.id = sa.student_id
            WHERE s.room_id = ?
            ORDER BY s.row_index, s.col_index
        ");
        $stmtSeats->execute([$planId, $plan['room_id']]);
        $seats = $stmtSeats->fetchAll();

        $room = ['cols' => $plan['room_cols'], 'rows' => $plan['room_rows']];
        require ROOT . '/views/plans/edit.php';
    }

    // ── API ────────────────────────────────────────────

    public function apiSaveClass(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['name'])) {
            Response::json(['error' => 'Le nom de la classe est requis'], 400);
            return;
        }
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
        $db  = Database::get();
        $id  = (int)$p['id'];

        $db->beginTransaction();
        try {
            // Observations
            $db->prepare("
                DELETE o FROM observations o
                JOIN sessions se ON se.id = o.session_id
                JOIN seating_plans sp ON sp.id = se.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Placements par séance
            $db->prepare("
                DELETE ss FROM session_seats ss
                JOIN sessions se ON se.id = ss.session_id
                JOIN seating_plans sp ON sp.id = se.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Séances
            $db->prepare("
                DELETE se FROM sessions se
                JOIN seating_plans sp ON sp.id = se.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Affectations du plan
            $db->prepare("
                DELETE sa FROM seating_assignments sa
                JOIN seating_plans sp ON sp.id = sa.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Plans
            $db->prepare("DELETE FROM seating_plans WHERE class_id = ?")->execute([$id]);

            // Membres des groupes
            $db->prepare("
                DELETE gs FROM group_students gs
                JOIN `groups` g ON g.id = gs.group_id
                WHERE g.class_id = ?
            ")->execute([$id]);

            // Groupes
            $db->prepare("DELETE FROM `groups` WHERE class_id = ?")->execute([$id]);

            // Élèves
            $db->prepare("DELETE FROM students WHERE class_id = ?")->execute([$id]);

            // Classe
            $db->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);

            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression'], 500);
        }
    }

    public function apiDeleteAllClasses(): void
    {
        $db = Database::get();
        try {
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            // Dépendants d'abord
            $db->exec("TRUNCATE TABLE observations");
            $db->exec("TRUNCATE TABLE session_seats");
            $db->exec("TRUNCATE TABLE sessions");
            // Placements et plans
            $db->exec("TRUNCATE TABLE seating_assignments");
            $db->exec("TRUNCATE TABLE seating_plans");
            // Groupes
            $db->exec("TRUNCATE TABLE group_students");
            $db->exec("TRUNCATE TABLE `groups`");
            // Élèves et classes
            $db->exec("TRUNCATE TABLE students");
            $db->exec("TRUNCATE TABLE classes");
            // NB : rooms, seats, school_years → intentionnellement conservés
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    public function apiImportStudents(array $p): void {
        require_once __DIR__ . '/PronoteImportController.php';
        (new PronoteImportController)->import($p);
    }

    public function apiGetStudents(array $p): void {
        $stmtStudents = Database::get()->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmtStudents->execute([$p['id']]);
        Response::json($stmtStudents->fetchAll());
    }

    public function apiSavePlan(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['room_id'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        $groupId = !empty($data['group_id']) ? (int)$data['group_id'] : null;

        $db = Database::get();
        $stmtExisting = $db->prepare(
            "SELECT id FROM seating_plans
            WHERE class_id=? AND room_id=? AND name=? AND (group_id <=> ?)"
        );
        $stmtExisting->execute([$p['id'], $data['room_id'], $data['name'] ?? 'Plan par défaut', $groupId]);
        $existing = $stmtExisting->fetch();

        if ($existing) {
            $planId = $existing['id'];
        } else {
            $db->prepare("INSERT INTO seating_plans (class_id, group_id, room_id, name) VALUES (?,?,?,?)")
            ->execute([$p['id'], $groupId, $data['room_id'], $data['name'] ?? 'Plan par défaut']);
            $planId = (int)$db->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $planId]);
    }

    public function apiGetPlan(array $p): void {
        $stmtPlan = Database::get()->prepare("
            SELECT sa.seat_id, sa.student_id, s.last_name, s.first_name
            FROM seating_assignments sa
            JOIN students s ON s.id = sa.student_id
            WHERE sa.plan_id = ?
        ");
        $stmtPlan->execute([$p['plan_id']]);
        Response::json($stmtPlan->fetchAll());
    }

    public function apiDeletePlan(array $p): void {
        $db     = Database::get();
        $planId = (int)$p['plan_id'];

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM seating_assignments WHERE plan_id=?")->execute([$planId]);
            $db->prepare("DELETE FROM seating_plans WHERE id=?")->execute([$planId]);
            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression du plan'], 500);
        }
    }

    public function apiSaveAssignments(array $p): void {
        $planId = (int)$p['plan_id'];
        $data   = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['assignments'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        $db = Database::get();
        $db->prepare("DELETE FROM seating_assignments WHERE plan_id=?")->execute([$planId]);
        $stmtAssign = $db->prepare("INSERT INTO seating_assignments (plan_id, seat_id, student_id) VALUES (?,?,?)");
        foreach ($data['assignments'] as $a) {
            $stmtAssign->execute([$planId, (int)$a['seat_id'], (int)$a['student_id']]);
        }
        Response::json(['ok' => true]);
    }

    public function apiImportPaste(array $p): void {
        $body = json_decode(file_get_contents('php://input'), true);
        $raw  = $body['data'] ?? '';
        if (!$raw) {
            Response::json(['error' => 'données vides'], 400);
            return;
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pronote_');
        try {
            file_put_contents($tmp, $raw);
            $_FILES['csv'] = [
                'tmp_name' => $tmp,
                'error'    => UPLOAD_ERR_OK,
                'size'     => strlen($raw),
                'name'     => 'paste.csv',
                'type'     => 'text/plain',
            ];
            require_once __DIR__ . '/PronoteImportController.php';
            (new PronoteImportController)->import($p);
        } finally {
            if (file_exists($tmp)) unlink($tmp);
        }
    }
}
