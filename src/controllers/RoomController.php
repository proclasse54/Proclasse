<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class RoomController {

    public function index(): void {
        $db = Database::get();
        $rooms = $db->query("
            SELECT r.*, COUNT(s.id) as seat_count
            FROM rooms r
            LEFT JOIN seats s ON s.room_id = r.id
            GROUP BY r.id ORDER BY r.name
        ")->fetchAll();
        require ROOT . '/views/rooms/index.php';
    }

    public function create(): void {
        $room = ['id' => null, 'name' => '', 'rows' => 5, 'cols' => 6, 'seats' => []];
        require ROOT . '/views/rooms/create.php';
    }

    public function edit(array $p): void {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$p['id']]);
        $room = $stmt->fetch();
        if (!$room) { http_response_code(404); return; }
        $stmt2 = $db->prepare("SELECT * FROM seats WHERE room_id = ? ORDER BY row_index, col_index");
        $stmt2->execute([$p['id']]);
        $room['seats'] = $stmt2->fetchAll();
        require ROOT . '/views/rooms/edit.php';
    }

    // API -------------------------------------------------------

    public function apiSave(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['name'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }        
        $db   = Database::get();

        if (!empty($p['id'])) {
            $db->prepare("UPDATE rooms SET name=?, `rows`=?, `cols`=? WHERE id=?")
               ->execute([$data['name'], $data['rows'], $data['cols'], $p['id']]);
            $roomId = (int)$p['id'];
        } else {
            $db->prepare("INSERT INTO rooms (name, `rows`, `cols`) VALUES (?,?,?)")
               ->execute([$data['name'], $data['rows'], $data['cols']]);
            $roomId = (int)$db->lastInsertId();
        }

        // Resynchroniser les sièges
        $db->prepare("DELETE FROM seats WHERE room_id = ?")->execute([$roomId]);
        if (!empty($data['seats'])) {
            $ins = $db->prepare("INSERT INTO seats (room_id, row_index, col_index, label) VALUES (?,?,?,?)");
            foreach ($data['seats'] as $s) {
                $ins->execute([$roomId, $s['row'], $s['col'], $s['label'] ?? null]);
            }
        }

        Response::json(['ok' => true, 'id' => $roomId]);
    }

    public function apiGet(array $p): void {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$p['id']]);
        $room = $stmt->fetch();
        if (!$room) { Response::json(['error' => 'not found'], 404); return; }
        $stmt2 = $db->prepare("SELECT * FROM seats WHERE room_id = ? ORDER BY row_index, col_index");
        $stmt2->execute([$p['id']]);
        $room['seats'] = $stmt2->fetchAll();
        Response::json($room);
    }

    public function apiDelete(array $p): void {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            WHERE sp.room_id = ?
        ");
        $stmt->execute([$p['id']]);
        if ($stmt->fetchColumn() > 0) {
            Response::json(['error' => 'Des séances utilisent cette salle'], 409);
            return;
        }
        $db->prepare("DELETE FROM rooms WHERE id=?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }

}
