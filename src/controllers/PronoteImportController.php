<?php
// src/controllers/PronoteImportController.php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class PronoteImportController {

    private const FIELD_MAP = [
        'Nom'                      => 'last_name',
        'Prénom'                   => 'first_name',
        "Prénom d'usage"           => 'first_name_usage',
        'Prénom 2'                 => 'first_name_2',
        'Prénom 3'                 => 'first_name_3',
        'Sexe'                     => 'gender',
        "Né(e) le"                 => 'birthdate',
        "Né(e) à"                  => 'birthplace',
        'Nationalité'              => 'nationality',
        'Pays de naissance'        => 'birth_country',
        'Majeur'                   => 'is_major',
        'Numéro national'          => 'pronote_id',
        'Adresse E-mail'           => 'email',
        'Tél. (SMS)'               => 'phone',
        'Classe'                   => 'class_name',
        'Niveau'                   => 'level',
        'Formation'                => 'formation',
        'Régime'                   => 'regime',
        'Professeur principal'     => 'head_teacher',
        'Date début scolarité'     => 'school_start',
        'Date fin scolarité'       => 'school_end',
        'Redoublant'               => 'is_repeating',
        "Projet d'accompagnement"  => 'support_project',
        'Allergies'                => 'allergies',
        'Groupes'                  => 'groups',
        'Toutes les options'       => 'options',
    ];

    private const SKIP_RAW = ['Convocation'];

    public function import(array $p): void {
        if (empty($_FILES['csv']['tmp_name'])) {
            Response::json(['error' => 'Fichier manquant'], 400);
        }

        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", trim($raw));

        if (count($lines) < 2) {
            Response::json(['error' => 'Fichier vide ou invalide'], 400);
        }

        $headers          = self::parseTsvLine($lines[0]);
        $headers[0]       = ltrim($headers[0], "\xEF\xBB\xBF");
        $colIndex         = array_flip($headers);

        $db     = Database::get();
        $stats  = ['inserted' => 0, 'skipped' => 0, 'classes_created' => 0, 'errors' => []];

        // ── Cache des classes : nom → id ─────────────────────
        $classCache = [];
        foreach ($db->query("SELECT id, name FROM classes") as $row) {
            $classCache[strtolower(trim($row['name']))] = (int)$row['id'];
        }

        $stmtInsertClass = $db->prepare(
            "INSERT INTO classes (name, year) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );

        $stmtInsert = $db->prepare("
            INSERT INTO students
                (class_id, last_name, first_name, first_name_usage, first_name_2, first_name_3,
                 gender, birthdate, birthplace, nationality, birth_country, is_major,
                 pronote_id, email, phone, class_name, level, formation, regime,
                 head_teacher, school_start, school_end, is_repeating,
                 support_project, allergies, groups, options)
            VALUES
                (:class_id, :last_name, :first_name, :first_name_usage, :first_name_2, :first_name_3,
                 :gender, :birthdate, :birthplace, :nationality, :birth_country, :is_major,
                 :pronote_id, :email, :phone, :class_name, :level, :formation, :regime,
                 :head_teacher, :school_start, :school_end, :is_repeating,
                 :support_project, :allergies, :groups, :options)
            ON DUPLICATE KEY UPDATE
                class_id         = VALUES(class_id),
                last_name        = VALUES(last_name),
                first_name       = VALUES(first_name),
                first_name_usage = VALUES(first_name_usage),
                first_name_2     = VALUES(first_name_2),
                first_name_3     = VALUES(first_name_3),
                gender           = VALUES(gender),
                birthdate        = VALUES(birthdate),
                birthplace       = VALUES(birthplace),
                nationality      = VALUES(nationality),
                birth_country    = VALUES(birth_country),
                is_major         = VALUES(is_major),
                email            = VALUES(email),
                phone            = VALUES(phone),
                class_name       = VALUES(class_name),
                level            = VALUES(level),
                formation        = VALUES(formation),
                regime           = VALUES(regime),
                head_teacher     = VALUES(head_teacher),
                school_start     = VALUES(school_start),
                school_end       = VALUES(school_end),
                is_repeating     = VALUES(is_repeating),
                support_project  = VALUES(support_project),
                allergies        = VALUES(allergies),
                groups           = VALUES(groups),
                options          = VALUES(options),
                updated_at       = CURRENT_TIMESTAMP
        ");

        $stmtGetId     = $db->prepare("SELECT id FROM students WHERE pronote_id = ?");
        $stmtGetIdName = $db->prepare("SELECT id FROM students WHERE class_id=? AND last_name=? AND first_name=?");
        $stmtRaw       = $db->prepare("
            INSERT INTO student_pronote_data (student_id, field_name, field_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE field_value=VALUES(field_value), imported_at=CURRENT_TIMESTAMP
        ");

        // Détecter l'année scolaire courante (ex: "2025-2026")
        $month       = (int)date('n');
        $year        = (int)date('Y');
        $schoolYear  = $month >= 9
            ? $year . '-' . ($year + 1)
            : ($year - 1) . '-' . $year;

        $db->beginTransaction();
        try {
            foreach (array_slice($lines, 1) as $lineNum => $line) {
                $line = trim($line);
                if ($line === '') continue;

                $cols   = self::parseTsvLine($line);
                $values = [];

                foreach (self::FIELD_MAP as $header => $field) {
                    $idx     = $colIndex[$header] ?? null;
                    $rawVal  = ($idx !== null && isset($cols[$idx])) ? trim($cols[$idx]) : null;
                    $rawVal  = ($rawVal === '') ? null : $rawVal;

                    $values[':' . $field] = match($field) {
                        'gender'       => self::parseGender($rawVal),
                        'birthdate',
                        'school_start',
                        'school_end'   => self::parseDate($rawVal),
                        'is_major',
                        'is_repeating' => self::parseBool($rawVal),
                        default        => $rawVal,
                    };
                }

                if (empty($values[':last_name']) || empty($values[':first_name'])) {
                    $stats['skipped']++;
                    continue;
                }

                // ── Résoudre / créer la classe automatiquement ──
                $pronoteClassName = trim($values[':class_name'] ?? '');
                $classKey         = strtolower($pronoteClassName);

                if ($pronoteClassName && !isset($classCache[$classKey])) {
                    // Créer la classe automatiquement
                    $stmtInsertClass->execute([$pronoteClassName, $schoolYear]);
                    $newId                  = (int)$db->lastInsertId();
                    $classCache[$classKey]  = $newId;
                    $stats['classes_created']++;
                }

                // class_id = classe Pronote si connue, sinon classe passée en paramètre URL
                $values[':class_id'] = $classCache[$classKey]
                    ?? (int)($p['id'] ?? 0)
                    ?: null;

                if (!$values[':class_id']) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    $stmtInsert->execute($values);

                    // Récupérer l'id de l'élève
                    $studentId = null;
                    if (!empty($values[':pronote_id'])) {
                        $stmtGetId->execute([$values[':pronote_id']]);
                        $row       = $stmtGetId->fetch();
                        $studentId = $row['id'] ?? null;
                    }
                    if (!$studentId) {
                        $stmtGetIdName->execute([$values[':class_id'], $values[':last_name'], $values[':first_name']]);
                        $row       = $stmtGetIdName->fetch();
                        $studentId = $row['id'] ?? null;
                    }

                    // Sauvegarder toutes les colonnes brutes
                    if ($studentId) {
                        foreach ($headers as $hIdx => $headerName) {
                            if (in_array($headerName, self::SKIP_RAW)) continue;
                            $rawVal = isset($cols[$hIdx]) ? trim($cols[$hIdx]) : null;
                            if ($rawVal !== null && $rawVal !== '') {
                                $stmtRaw->execute([$studentId, $headerName, $rawVal]);
                            }
                        }
                        $stats['inserted']++;
                    }
                } catch (\PDOException $e) {
                    $stats['errors'][] = "Ligne " . ($lineNum + 2) . " : " . $e->getMessage();
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            Response::json(['error' => $e->getMessage()], 500);
        }

        Response::json([
            'ok'              => true,
            'inserted'        => $stats['inserted'],
            'classes_created' => $stats['classes_created'],
            'skipped'         => $stats['skipped'],
            'errors'          => array_slice($stats['errors'], 0, 10),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function parseTsvLine(string $line): array {
        $fields   = [];
        $current  = '';
        $inQuotes = false;
        $len      = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '"') {
                if ($inQuotes && isset($line[$i+1]) && $line[$i+1] === '"') {
                    $current .= '"'; $i++;
                } else {
                    $inQuotes = !$inQuotes;
                }
            } elseif ($c === "\t" && !$inQuotes) {
                $fields[] = $current; $current = '';
            } else {
                $current .= $c;
            }
        }
        $fields[] = $current;
        return $fields;
    }

    private static function parseGender(?string $v): ?string {
        if (!$v) return null;
        $v = mb_strtolower($v);
        if (str_contains($v, 'masc')) return 'M';
        if (str_contains($v, 'fém') || str_contains($v, 'fem')) return 'F';
        return null;
    }

    private static function parseDate(?string $v): ?string {
        if (!$v) return null;
        $d = \DateTime::createFromFormat('d/m/Y', $v);
        return $d ? $d->format('Y-m-d') : null;
    }

    private static function parseBool(?string $v): int {
        if (!$v) return 0;
        return in_array(mb_strtolower(trim($v)), ['oui', 'r', '1', 'true']) ? 1 : 0;
    }
}
