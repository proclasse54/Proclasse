<?php
// src/controllers/IcsImportController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class IcsImportController
{
    /**
     * POST /api/sessions/import-ics
     * Importe les séances depuis un fichier ICS Pronote.
     * Si aucun plan n'existe pour une classe, en crée un aléatoire
     * UNIQUEMENT pour les séances à venir (date >= aujourd'hui).
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
        $ignored  = 0;
        $errors   = [];

        // Date du jour (sans heure) pour comparer avec les dates de séances
        $today = date('Y-m-d');

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
            // On transmet la TZID extraite par parseIcs() pour gérer correctement
            // les dates Pronote de type DTSTART;TZID=Europe/Paris:20260427T080000
            $dtStart = $this->icsDateToLocal(
                $ev['dtstart'] ?? '',
                $ev['dtstart_tzid'] ?? null
            );
            if (!$dtStart) {
                $errors[] = "Date invalide pour : " . ($ev['summary'] ?? '');
                continue;
            }
            $date      = $dtStart->format('Y-m-d');
            $timeStart = $dtStart->format('H:i:s');

            // DTEND → heure de fin
            $dtEnd   = $this->icsDateToLocal(
                $ev['dtend'] ?? '',
                $ev['dtend_tzid'] ?? null
            );
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
                // Séance multi-classes (ex: "3A, 3B, 3C…") ou libéllé sans classe reconnue
                // → insérer comme séance informative sans plan_id ni affectations
                if (str_contains($className, ',')) {
                    // Déduplication : même subject + date + time_start sans plan
                    $stmtDup = $db->prepare(
                        "SELECT id FROM sessions
                        WHERE plan_id IS NULL AND `date` = ? AND time_start = ? AND subject = ?
                        LIMIT 1"
                    );
                    $stmtDup->execute([$date, $timeStart, $subject]);
                    if ($stmtDup->fetch()) { $skipped++; continue; }

                    $db->prepare(
                        "INSERT INTO sessions (plan_id, multi_classes, `date`, time_start, time_end, subject)
                        VALUES (NULL, ?, ?, ?, ?, ?)"
                    )->execute([trim($className), $date, $timeStart, $timeEnd, $subject]);
                    $inserted++;
                } else {
                    // Classe simple mais inconnue en base (ex: "M. JACQUE ARNAUD" = réunion sans classe)
                    // → ignorer silencieusement
                    $ignored++;
                }
                continue;
            }

            // --- Trouver ou créer un plan ---
            // Si c'est un groupe, chercher un plan lié à ce groupe
            // Sinon, chercher un plan lié à la classe entière (group_id NULL)
            if ($group) {
                $stmtPlan = $db->prepare(
                    "SELECT id FROM seating_plans 
                    WHERE class_id = ? AND group_id = ? 
                    LIMIT 1"
                );
                $stmtPlan->execute([$class['id'], $group['id']]);
            } else {
                $stmtPlan = $db->prepare(
                    "SELECT id FROM seating_plans 
                    WHERE class_id = ? AND group_id IS NULL 
                    LIMIT 1"
                );
                $stmtPlan->execute([$class['id']]);
            }

            $plan = $stmtPlan->fetch();

            if (!$plan) {
                // Séance passée sans plan existant → on l'importe sans plan
                // (pas de génération aléatoire pour une séance terminée)
                if ($date < $today) {
                    $skipped++;
                    continue;
                }

                $planId = $this->createRandomPlan(
                    $db,
                    $class['id'],
                    $className,
                    $group ? (int)$group['id'] : null
                );
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

            // --- Insérer la séance + snapshot session_seats ---
            // Le snapshot copie l'état actuel de seating_assignments dans session_seats,
            // exactement comme SessionController::apiCreate() le fait.
            // Sans ce snapshot, aucun élève n'apparaît sur les places en vue live.
            $stmtPlanRoom = $db->prepare("SELECT room_id FROM seating_plans WHERE id = ?");
            $stmtPlanRoom->execute([$plan['id']]);
            $planRow = $stmtPlanRoom->fetch();

            if (!$planRow) {
                $errors[] = "Plan introuvable en base (id={$plan['id']}) pour : $className";
                continue;
            }

            $db->beginTransaction();
            try {
                $db->prepare(
                    "INSERT INTO sessions (plan_id, `date`, time_start, time_end, subject)
                     VALUES (?, ?, ?, ?, ?)"
                )->execute([$plan['id'], $date, $timeStart, $timeEnd, $subject]);
                $sessionId = (int)$db->lastInsertId();

                // Snapshot : copie de toutes les places de la salle avec l'élève affecté
                // (NULL si la place est vide dans seating_assignments pour ce plan).
                $db->prepare(
                    "INSERT INTO session_seats (session_id, seat_id, student_id)
                     SELECT ?, s.id, sa.student_id
                     FROM seats s
                     LEFT JOIN seating_assignments sa
                            ON sa.seat_id = s.id AND sa.plan_id = ?
                     WHERE s.room_id = ?"
                )->execute([$sessionId, (int)$plan['id'], (int)$planRow['room_id']]);

                $db->commit();
                $inserted++;
            } catch (\Throwable $e) {
                $db->rollBack();
                $errors[] = "Erreur insertion séance $className $date : " . $e->getMessage();
            }
        }

        Response::json([
            'ok'            => true,
            'inserted'      => $inserted,
            'skipped'       => $skipped,
            'plans_created' => $created,
            'ignored'       => $ignored,
            'errors'        => $errors,
        ]);
    }

    // ------------------------------------------------------------------
    // Création d'un plan aléatoire
    // ------------------------------------------------------------------

    private function createRandomPlan(\PDO $db, int $classId, string $className, ?int $groupId = null): ?int
    {
        // 1. Récupérer les élèves — du groupe si précisé, sinon de toute la classe
        if ($groupId) {
            $stmtStudents = $db->prepare(
                "SELECT s.id FROM students s
                JOIN group_students gs ON gs.student_id = s.id
                WHERE gs.group_id = ?
                ORDER BY s.id"
            );
            $stmtStudents->execute([$groupId]);
        } else {
            $stmtStudents = $db->prepare(
                "SELECT id FROM students WHERE class_id = ? ORDER BY id"
            );
            $stmtStudents->execute([$classId]);
        }
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

        if (!$room) {
            $room = $db->query("SELECT id FROM rooms ORDER BY id LIMIT 1")->fetch();
        }

        if (!$room) {
            $db->prepare(
                "INSERT INTO rooms (name, `rows`, `cols`) VALUES (?, 5, 6)"
            )->execute(["Salle $className"]);
            $roomId = (int)$db->lastInsertId();
            $this->createSeats($db, $roomId, 5, 6);
            $room = ['id' => $roomId];
        }

        $roomId = (int)$room['id'];

        // 3. Créer le plan — avec group_id si c'est un plan de groupe
        $db->prepare(
            "INSERT INTO seating_plans (class_id, group_id, room_id, name) VALUES (?, ?, ?, ?)"
        )->execute([$classId, $groupId, $roomId, "Plan aléatoire - $className"]);
        $planId = (int)$db->lastInsertId();

        // 4. Récupérer les sièges
        $stmtSeats = $db->prepare(
            "SELECT id FROM seats WHERE room_id = ? ORDER BY row_index, col_index"
        );
        $stmtSeats->execute([$roomId]);
        $seatIds = $stmtSeats->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($seatIds)) { return null; }

        // 5. Mélange et affectation
        shuffle($studentIds);
        shuffle($seatIds);

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
                $current[$key . '_tzid'] = isset($tzMatch[1]) ? trim($tzMatch[1]) : null;
            }

            $current[$key] = $val;
        }

        return $events;
    }

    private function parseSummary(string $summary): array
    {
        $parts = array_map('trim', explode(' - ', $summary));
        if (count($parts) < 2) {
            return [null, null];
        }

        $subject = $parts[0];

        // Priorité 1 : chercher un [...] dans la chaîne entière
        // → cas "MATIERE - NOM - [3PM_GR1] - <3 PM> 3PM_GR1"
        if (preg_match('/\[([^\]]+)\]/', $summary, $m)) {
            return [$subject ?: null, trim($m[1]) ?: null];
        }

        // Priorité 2 : chercher un <...>
        if (preg_match('/<([^>]+)>/', $summary, $m)) {
            return [$subject ?: null, trim($m[1]) ?: null];
        }

        // Priorité 3 : pas de crochets → le dernier segment est la classe
        // (gère "MATIERE - NOM PROF - 3B" et "MATIERE - NOM - 3A, 3B, 3C")
        $last = trim(end($parts), '[]<>()');
        return [$subject ?: null, $last ?: null];
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
                // Utiliser la TZID extraite des paramètres ICS si disponible
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
