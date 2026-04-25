<?php
class PhotoController
{
    public function serve(): void
    {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if (!$studentId) { http_response_code(404); exit; }

        $db   = Database::get();
        $stmt = $db->prepare("
            SELECT s.last_name, s.first_name, s.class_id, c.name AS class_name
            FROM students s JOIN classes c ON c.id = s.class_id
            WHERE s.id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        if (!$student) { http_response_code(404); exit; }

        // Chemin absolu du fichier brut
        $classeFichier = nettoyerChaine($student['class_name']);
        $nomFichier    = nettoyerChaine(mb_strtoupper($student['last_name'], 'UTF-8'));
        $prenomFichier = removeAccents(nettoyerChaine($student['first_name']));

        $path = getPhotoPath($student['class_name'], $student['last_name'], $student['first_name']);

        if (!file_exists($path)) { http_response_code(404); exit; }

        $crop    = getCropSettings($studentId, (int)$student['class_id']);
        $rogned  = rognerPortrait(file_get_contents($path), $crop);

        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=3600, private');
        echo $rogned;
        exit;
    }
}