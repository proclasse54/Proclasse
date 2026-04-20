<?php
// src/controllers/IcsImportController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class IcsImportController
{
    /**
     * POST /api/sessions/import-ics
     * Importe les séances depuis un fichier ICS Pronote.
     * Si aucun plan n'existe pour une classe, en crée un aléatoire.
     */
    public function apiImportIcs(): void
    {
        if (empty($_FILES['icsfile']['tmp_name'])) {
            Response::json(['error' => 'Aucun fichier ICS reçu'], 400);
            return;
        }

        $originalName = $_FILES['icsfile']['name'] ?? '';
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['ics', 'ical', 'ifb', 'icalendar'], true)) {
            Response::json(['error' => 'Seuls les fichiers .ics sont acceptés'], 400);
            return;
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($_FILES['icsfile']['tmp_name']);
        $allowedMimes = ['text/calendar', 'text/plain', 'application/octet-stream'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            Response::json(['error' => "Type de fichier invalide : $mimeType"], 400);
            return;
        }

        $content = file_get_contents($_FILES['icsfile']['tmp_name']);
        if (!$content) {
            Response::json(['error' => 'Fichier illisible'], 400);
            return;
        }

        $events   = $this->parseIcs($content);
        $db       = Database::get();
        $inserted = 0;
        $skipped  = 0;
        $created  = 0;
        $errors   = [];

        foreach ($events as $ev) {
            // N'importer que les cours (Cours, Cours modifié...)
            if (!str_contains($ev['categories'] ?? '', 'Cours')) {
                continue;
            }

            [$subject, $className] = $this->parseSummary($ev['summary'] ?? '');
            if (!$subject || !$className) {
                $errors[] = "Impossible de parser : " . ($ev['summary'] ?? '');
                continue;
            }

            // Convertir DTSTART en date + heure locale Paris
            $dtStart = $this->icsDateToLocal($ev['dtstart'] ?? '');
            if (!$dtStart) {
                $errors[] = "Date invalide pour : " . ($ev['summary'] ?? '');
                continue;
            }
            $date      = $dtStart->format('Y-m-d');
            $timeStart = $dtStart->format('H:i:s');

            // DTEND → heure de fin
            $dtEnd   = $this->icsDateToLocal($ev['dtend'] ?? '');
            $timeEnd = $dtEnd ? $dtEnd->format('H:i:s') : null;

            // --- Trouver la classe ---
            $stmtClass = $db->prepare("SELECT id FROM classes WHERE name = ? LIMIT 1");
            $stmtClass->execute([$className]);
            $class = $stmtClass->fetch();

            $group = null;
            if (!$class) {
                $stmtGroup = $db->prepare("SELECT id, class_id FROM `groups` WHERE name = ? LIMIT 1");
                $stmtGroup->execute([$className]);
                $group = $stmtGroup->fetch();
                if ($group && !empty($group['class_id'])) {
                    $class = ['id' => (int)$group['class_id']];
                }
            }

            if (!$class) {
                $errors[] = "Classe/Groupe inconnue en base : « $className » (séance du $date)";
                continue;
            }

            // --- Trouver ou créer un plan ---
            $stmtPlan = $db->prepare(
                "SELECT id FROM seating_plans WHERE class_id = ? LIMIT 1"
            );
            $stmtPlan->execute([$class['id']]);
            $plan = $stmtPlan->fetch();

            if (!$plan) {
                $planId = $this->createRandomPlan($db, $class['id'], $className);
                if (!$planId) {
                    $errors[] = "Impossible de créer un plan pour : $className (pas d'élèves ou de salle ?)";
                    continue;
                }
                $plan = ['id' => $planId];
                $created++;
            }

            // --- Déduplication sur plan_id + date + time_start ---
            $stmtDup = $db->prepare(
                "SELECT id FROM sessions
                 WHERE plan_id = ? AND `date` = ? AND time_start = ?
                 LIMIT 1"
            );
            $stmtDup->execute([$plan['id'], $date, $timeStart]);
            if ($stmtDup->fetch()) {
                $skipped++;
                continue;
            }

            // --- Insérer la séance ---
            $db->prepare(
                "INSERT INTO sessions (plan_id, `date`, time_start, time_end, subject)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$plan['id'], $date, $timeStart, $timeEnd, $subject]);
            $inserted++;
        }

        Response::json([
            'ok'            => true,
            'inserted'      => $inserted,
            'skipped'       => $skipped,
            'plans_created' => $created,
            'errors'        => $errors,
        ]);
    }

    // ------------------------------------------------------------------
    // Création d'un plan aléatoire
    // ------------------------------------------------------------------

    private function createRandomPlan(\PDO $db, int $classId, string $className): ?int
    {
        // 1. Récupérer les élèves
        $stmtStudents = $db->prepare(
            "SELECT id FROM students WHERE class_id = ? ORDER BY id"
        );
        $stmtStudents->execute([$classId]);
        $studentIds = $stmtStudents->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($studentIds)) {
            return null;
        }

        $count = count($studentIds);

        // 2. Chercher une salle avec assez de sièges
        $stmtRoom = $db->prepare(
            "SELECT r.id FROM rooms r
             INNER JOIN seats s ON s.room_id = r.id
             GROUP BY r.id
             HAVING COUNT(s.id) >= ?
             ORDER BY COUNT(s.id) ASC
             LIMIT 1"
        );
        $stmtRoom->execute([$count]);
        $room = $stmtRoom->fetch();

        // Sinon, prendre la première salle dispo
        if (!$room) {
            $room = $db->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetch();
        }

        // Toujours rien → créer une salle 5×6 par défaut
        if (!$room) {
            $db->prepare(
                "INSERT INTO rooms (name, `rows`, `cols`) VALUES (?, 5, 6)"
            )->execute(["Salle $className"]);
            $roomId = (int)$db->lastInsertId();
            $this->createSeats($db, $roomId, 5, 6);
            $room = ['id' => $roomId];
        }

        $roomId = (int)$room['id'];

        // 3. Créer le plan
        $db->prepare(
            "INSERT INTO seating_plans (class_id, room_id, name) VALUES (?, ?, ?)"
        )->execute([$classId, $roomId, "Plan aléatoire – $className"]);
        $planId = (int)$db->lastInsertId();

        // 4. Récupérer les sièges de la salle
        $stmtSeats = $db->prepare(
            "SELECT id FROM seats WHERE room_id = ? ORDER BY row_index, col_index"
        );
        $stmtSeats->execute([$roomId]);
        $seatIds = $stmtSeats->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($seatIds)) {
            return null;
        }

        // 5. Mélange aléatoire
        shuffle($studentIds);
        shuffle($seatIds);

        // 6. Affecter élèves → sièges
        $stmtAssign = $db->prepare(
            "INSERT INTO seating_assignments (plan_id, seat_id, student_id) VALUES (?, ?, ?)"
        );
        $limit = min(count($studentIds), count($seatIds));
        for ($i = 0; $i < $limit; $i++) {
            $stmtAssign->execute([$planId, $seatIds[$i], $studentIds[$i]]);
        }

        return $planId;
    }

    private function createSeats(\PDO $db, int $roomId, int $rows, int $cols): void
    {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO seats (room_id, row_index, col_index) VALUES (?, ?, ?)"
        );
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $stmt->execute([$roomId, $r, $c]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Parsers ICS
    // ------------------------------------------------------------------

    private function parseIcs(string $content): array
    {
        // Déplier les lignes repliées RFC 5545
        $content = preg_replace("/\r\n[ \t]/", '', $content);
        $content = preg_replace("/\n[ \t]/",   '', $content);

        $events  = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $content)) as $rawLine) {
            $line = rtrim($rawLine);

            if ($line === 'BEGIN:VEVENT') { $current = []; continue; }
            if ($line === 'END:VEVENT')   {
                if ($current !== null) $events[] = $current;
                $current = null;
                continue;
            }
            if ($current === null) { continue; }

            // regex robuste supportant TZID= et autres paramètres contenant ":"
            // La valeur commence après le premier ":" qui suit le nom de propriété (+ paramètres)
            if (!preg_match('/^([A-Z][A-Z0-9\-]*)((?:;[^:]+)*):(.*)/s', $line, $m)) {
                continue;
            }

            $key    = strtolower($m[1]);
            $params = $m[2]; // ex: ";TZID=Europe/Paris"
            $val    = str_replace(
                ['\\n', '\\N', '\\,', '\\;'],
                ["\n",  "\n",  ',',   ';'],
                $m[3]
            );

            // Stocker la timezone du DTSTART/DTEND si présente dans les paramètres
            if (in_array($key, ['dtstart', 'dtend'], true) && str_contains($params, 'TZID=')) {
                preg_match('/TZID=([^;]+)/', $params, $tzMatch);
                $current[$key . '_tzid'] = $tzMatch[1] ?? null;
            }

            $current[$key] = $val;
        }

        return $events;
    }

    // parseSummary plus robuste — le dernier segment est la classe
    private function parseSummary(string $summary): array
    {
        $parts = array_map('trim', explode(' - ', $summary));
        if (count($parts) < 2) {
            return [null, null];
        }

        // Le sujet est le premier segment, la classe est le dernier
        $subject  = $parts[0];
        $rawClass = trim(end($parts), '[]<>');

        // Si plusieurs classes séparées par virgule, prendre la première
        if (str_contains($rawClass, ',')) {
            $rawClass = trim(explode(',', $rawClass)[0]);
        }

        return [$subject ?: null, $rawClass ?: null];
    }

    private function icsDateToLocal(string $icsDate, ?string $tzid = null): ?\DateTime
    {
        $icsDate = trim($icsDate);
        if (!$icsDate) return null;
        try {
            if (str_ends_with($icsDate, 'Z')) {
                $dt = new \DateTime($icsDate, new \DateTimeZone('UTC'));
                $dt->setTimezone(new \DateTimeZone('Europe/Paris'));
            } elseif ($tzid) {
                // Bug 17 : utiliser la TZID extraite des paramètres si disponible
                $tz = new \DateTimeZone($tzid);
                $dt = new \DateTime($icsDate, $tz);
                $dt->setTimezone(new \DateTimeZone('Europe/Paris'));
            } else {
                $dt = new \DateTime($icsDate, new \DateTimeZone('Europe/Paris'));
            }
            return $dt;
        } catch (\Exception) {
            return null;
        }
    }
}
