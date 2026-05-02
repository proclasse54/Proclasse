<?php
class PhotoController
{
    public function serve(): void
    {
        $studentId = (int)($_GET['student_id'] ?? 0);
        if (!$studentId) { http_response_code(404); exit; }

        // Paramètre optionnel : ?original=1 → retourne la photo brute sans recadrage
        $original = !empty($_GET['original']);

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

        $path = '/var/www/sub-domains/proclasse/public/data/photos_eleves/'."{$classeFichier}.{$nomFichier}.{$prenomFichier}.jpg";

        if (!file_exists($path)) { http_response_code(404); exit; }

        if ($original) {
            // Mode original : on sert le fichier brut sans aucun recadrage
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store'); // pas de cache pour l'original (usage éditeur)
            readfile($path);
            exit;
        }

        $crop    = getCropSettings($studentId, (int)$student['class_id']);
        $rogned  = rognerPortrait(file_get_contents($path), $crop);

        header('Content-Type: image/jpeg');
        header('Cache-Control: max-age=3600, private');
        echo $rogned;
        exit;
    }
}
