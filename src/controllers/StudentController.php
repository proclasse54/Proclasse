<?php
class StudentController
{
    public function apiGet(array $p): void
    {
        $db = Database::get();

        $stmt = $db->prepare("
            SELECT s.*, c.name AS class_name
            FROM students s
            JOIN classes c ON c.id = s.class_id
            WHERE s.id = ?
        ");
        $stmt->execute([(int)$p['id']]);
        $student = $stmt->fetch();

        if (!$student) {
            Response::json(['error' => 'Élève introuvable'], 404);
            return;
        }

        $stmtExtra = $db->prepare("
            SELECT field_name, field_value
            FROM student_pronote_data
            WHERE student_id = ?
            ORDER BY field_name
        ");
        $stmtExtra->execute([(int)$p['id']]);
        $student['pronote_data'] = $stmtExtra->fetchAll();

        Response::json($student);
    }

    public function apiUploadPhoto(array $p): void
    {
        $studentId = (int)$p['id'];
        $db   = Database::get();

        // Vérifier que l'élève existe
        $stmt = $db->prepare("SELECT s.last_name, s.first_name, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE s.id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { Response::json(['error' => 'Élève introuvable'], 404); return; }

        // Vérifier le fichier uploadé
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'Aucun fichier valide reçu'], 400); return;
        }

        $file = $_FILES['photo'];

        // Vérifications sécurité
        if ($file['size'] > 2097152) { Response::json(['error' => 'Fichier trop lourd (max 2 Mo)'], 400); return; }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            Response::json(['error' => 'Format non supporté (JPG, PNG, WEBP uniquement)'], 400); return;
        }

        // Construire le chemin cible (même convention que PhotoController)
        $classeFichier = nettoyerChaine($student['class_name']);
        $nomFichier    = nettoyerChaine(mb_strtoupper($student['last_name'], 'UTF-8'));
        $prenomFichier = removeAccents(nettoyerChaine($student['first_name']));

        $dir  = '/var/www/sub-domains/proclasse/public/data/photos_eleves/';
        $path = $dir . "{$classeFichier}.{$nomFichier}.{$prenomFichier}.jpg";

        // Convertir en JPEG et enregistrer (via GD)
        $data = file_get_contents($file['tmp_name']);
        $img  = @imagecreatefromstring($data);
        if (!$img) { Response::json(['error' => 'Image corrompue ou illisible'], 400); return; }

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (!imagejpeg($img, $path, 90)) {
            imagedestroy($img);
            Response::json(['error' => 'Impossible d\'enregistrer la photo'], 500); return;
        }
        imagedestroy($img);

        Response::json(['ok' => true]);
    }

    public function apiDeletePhoto(array $p): void
    {
        $studentId = (int)$p['id'];
        $db   = Database::get();

        $stmt = $db->prepare("SELECT s.last_name, s.first_name, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE s.id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { Response::json(['error' => 'Élève introuvable'], 404); return; }

        $classeFichier = nettoyerChaine($student['class_name']);
        $nomFichier    = nettoyerChaine(mb_strtoupper($student['last_name'], 'UTF-8'));
        $prenomFichier = removeAccents(nettoyerChaine($student['first_name']));

        $path = '/var/www/sub-domains/proclasse/public/data/photos_eleves/' . "{$classeFichier}.{$nomFichier}.{$prenomFichier}.jpg";

        if (!file_exists($path)) { Response::json(['error' => 'Aucune photo à supprimer'], 404); return; }

        if (!unlink($path)) { Response::json(['error' => 'Impossible de supprimer la photo'], 500); return; }

        Response::json(['ok' => true]);
    }

    public function apiSummary(array $p): void
    {
        $studentId = (int)($p['id'] ?? 0);
        $sessionId = (int)($_GET['session_id'] ?? 0);

        if (!$studentId) {
            Response::json(['error' => 'Élève introuvable'], 404);
            return;
        }

        $db = Database::get();

        // Élève + classe
        $stmt = $db->prepare("
            SELECT s.id, s.first_name, s.last_name, s.class_id, c.name AS class_name
            FROM students s
            JOIN classes c ON c.id = s.class_id
            WHERE s.id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            Response::json(['error' => 'Élève introuvable'], 404);
            return;
        }

        // Observations de la séance courante
        $observations = [];
        if ($sessionId > 0) {
            $stmtObs = $db->prepare("
                SELECT o.id, o.tag, o.note, t.color, t.icon
                FROM observations o
                LEFT JOIN tags t ON t.label = o.tag
                WHERE o.student_id = ? AND o.session_id = ?
                ORDER BY o.id DESC
            ");
            $stmtObs->execute([$studentId, $sessionId]);
            $observations = $stmtObs->fetchAll();
        }

        // Historique récent (hors séance courante si fournie)
        if ($sessionId > 0) {
            $stmtHistory = $db->prepare("
                SELECT
                    o.id,
                    o.tag,
                    o.note,
                    o.session_id,
                    se.date,
                    se.time_start,
                    t.color,
                    t.icon
                FROM observations o
                JOIN sessions se ON se.id = o.session_id
                LEFT JOIN tags t ON t.label = o.tag
                WHERE o.student_id = ?
                AND o.session_id != ?
                ORDER BY se.date DESC, se.time_start DESC, o.id DESC
                LIMIT 20
            ");
            $stmtHistory->execute([$studentId, $sessionId]);
        } else {
            $stmtHistory = $db->prepare("
                SELECT
                    o.id,
                    o.tag,
                    o.note,
                    o.session_id,
                    se.date,
                    se.time_start,
                    t.color,
                    t.icon
                FROM observations o
                JOIN sessions se ON se.id = o.session_id
                LEFT JOIN tags t ON t.label = o.tag
                WHERE o.student_id = ?
                ORDER BY se.date DESC, se.time_start DESC, o.id DESC
                LIMIT 20
            ");
            $stmtHistory->execute([$studentId]);
        }

        $history = $stmtHistory->fetchAll();

        Response::json([
            'id'           => (int)$student['id'],
            'first_name'   => $student['first_name'],
            'last_name'    => $student['last_name'],
            'class_id'     => (int)$student['class_id'],
            'class_name'   => $student['class_name'],
            'observations' => $observations,
            'history'      => $history,
        ]);
    }

}