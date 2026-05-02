<?php
// src/controllers/SessionController.php
// ============================================================
//  Contrôleur des séances de cours — gère l'affichage et les
//  opérations sur les séances, leurs placements et observations.
//
//  Méthodes HTML (rendu de vue) :
//    index()         → liste paginée + vue semaine des séances
//    live($p)        → vue en direct d'une séance (plan interactif)
//    tagsIndex()     → gestion des tags d'observation
//
//  Méthodes API (réponses JSON) :
//    apiCreate               → créer une séance (+ récurrence hebdo)
//    apiDelete               → supprimer une séance et ses observations
//    apiMoveSeat             → déplacer/échanger un élève sur une place
//    apiRemoveStudent        → retirer un élève d'une séance (place → null)
//    apiAddObservation       → ajouter une observation sur un élève
//    apiRemoveObservation    → supprimer une observation
//    apiGetObservations      → liste JSON des observations d'une séance
//    apiObservationsSummary  → résumé des observations (pour modale)
//    apiObservationsExport   → export CSV des observations
//    apiGetTags              → liste JSON des tags
//    apiSaveTag              → créer ou mettre à jour un tag
//    apiDeleteTag            → supprimer un tag (avec vérification d'usage)
// ============================================================

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class SessionController
{
    // ================================================================
    //  VUE HTML
    // ================================================================

    /**
     * Affiche la liste paginée des séances et la vue hebdomadaire.
     * Route : GET /sessions  (+ paramètres optionnels : ?page=N&week=YYYY-WNN)
     */
    public function index(): void
    {
        $db = Database::get();

        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 100;
        $offset  = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT se.*,
                sp.name AS plan_name,
                COALESCE(g.name, c.name, se.multi_classes) AS class_name,
                r.name AS room_name
            FROM sessions se
            LEFT JOIN seating_plans sp ON sp.id = se.plan_id
            LEFT JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            LEFT JOIN rooms r ON r.id = sp.room_id
            ORDER BY se.date DESC, se.time_start DESC, se.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([(int)$perPage, (int)$offset]);
        $sessions = $stmt->fetchAll();

        $total      = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $totalPages = (int)ceil($total / $perPage);

        $weekParam = $_GET['week'] ?? null;
        if ($weekParam && preg_match('/^\d{4}-W(\d{2})$/', $weekParam, $m)) {
            $weekDate = new \DateTime();
            $weekDate->setISODate((int)explode('-W', $weekParam)[0], (int)$m[1]);
        } else {
            $weekDate = new \DateTime();
        }
        $weekStart = (clone $weekDate)->modify('monday this week')->format('Y-m-d');
        $weekEnd   = (clone $weekDate)->modify('sunday this week')->format('Y-m-d');

        $stmtWeek = $db->prepare("
            SELECT se.*,
                sp.name AS plan_name,
                COALESCE(g.name, c.name, se.multi_classes) AS class_name,
                r.name AS room_name
            FROM sessions se
            LEFT JOIN seating_plans sp ON sp.id = se.plan_id
            LEFT JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            LEFT JOIN rooms r ON r.id = sp.room_id
            WHERE se.date BETWEEN ? AND ?
            ORDER BY se.date, se.time_start
        ");
        $stmtWeek->execute([$weekStart, $weekEnd]);
        $weekSessions = $stmtWeek->fetchAll();
        $currentWeek  = $weekDate->format('o\-\WW');

        $plans = $db->query("
            SELECT sp.*, c.name as class_name, r.name as room_name
            FROM seating_plans sp
            JOIN classes c ON c.id = sp.class_id
            JOIN rooms r ON r.id = sp.room_id
            ORDER BY c.name
        ")->fetchAll();

        $pageTitle = 'Séances';

        ob_start();
        require __DIR__ . '/../../views/sessions/index.php';
        $content = ob_get_clean();

        require __DIR__ . '/../../views/layouts/app.php';
    }

    /**
     * Affiche la vue en direct d'une séance (plan de classe interactif).
     * Route : GET /sessions/{id}/live
     */
    public function live(array $p): void
    {
        $db = Database::get();

        $stmtSession = $db->prepare("
            SELECT se.*, sp.name as plan_name, sp.room_id, sp.class_id,
                   COALESCE(g.name, c.name) AS class_name,
                   c.id  AS raw_class_id,
                   r.name as room_name,
                   r.`rows` as room_rows, r.`cols` as room_cols
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            JOIN rooms r ON r.id = sp.room_id
            WHERE se.id = ?
        ");
        $stmtSession->execute([$p['id']]);
        $session = $stmtSession->fetch();

        if (!$session) {
            http_response_code(404);
            return;
        }

        // ── Séance précédente (même classe) ──────────────────────────────────
        $stmtPrev = $db->prepare("
            SELECT se.id, se.date, se.time_start,
                   COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE sp.class_id = ?
              AND (
                    se.date < ?
                    OR (se.date = ? AND (se.time_start IS NULL OR se.time_start < ?))
                  )
              AND se.id != ?
            ORDER BY se.date DESC, se.time_start DESC
            LIMIT 1
        ");
        $stmtPrev->execute([
            $session['raw_class_id'],
            $session['date'],
            $session['date'],
            $session['time_start'] ?? '99:99:99',
            $session['id'],
        ]);
        $prevRow = $stmtPrev->fetch() ?: null;
        $prevId  = $prevRow ? (int)$prevRow['id'] : null;

        // ── Séance suivante (même classe) ─────────────────────────────────────
        $stmtNext = $db->prepare("
            SELECT se.id, se.date, se.time_start,
                   COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE sp.class_id = ?
              AND (
                    se.date > ?
                    OR (se.date = ? AND se.time_start IS NOT NULL AND se.time_start > ?)
                  )
              AND se.id != ?
            ORDER BY se.date ASC, se.time_start ASC
            LIMIT 1
        ");
        $stmtNext->execute([
            $session['raw_class_id'],
            $session['date'],
            $session['date'],
            $session['time_start'] ?? '00:00:00',
            $session['id'],
        ]);
        $nextRow = $stmtNext->fetch() ?: null;
        $nextId  = $nextRow ? (int)$nextRow['id'] : null;

        // ── Séance globale suivante (toutes classes) ──────────────────────────
        // Prochaine séance chronologique, quelle que soit la classe.
        // Exclut la séance courante et, si elle existe, la séance $nextId
        // (même classe) pour ne pas la dupliquer dans la vue.
        $stmtGlobalNext = $db->prepare("
            SELECT se.id, se.date, se.time_start,
                   COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE (
                    se.date > ?
                    OR (se.date = ? AND se.time_start IS NOT NULL AND se.time_start > ?)
                  )
              AND se.id != ?
            ORDER BY se.date ASC, se.time_start ASC
            LIMIT 1
        ");
        $stmtGlobalNext->execute([
            $session['date'],
            $session['date'],
            $session['time_start'] ?? '00:00:00',
            $session['id'],
        ]);
        $globalNextRow = $stmtGlobalNext->fetch() ?: null;
        $globalNextId  = $globalNextRow ? (int)$globalNextRow['id'] : null;

        // Si la séance globale suivante est la même que la suivante de la classe,
        // on ne l'affiche pas en double dans la barre : on la masque.
        if ($globalNextId && $globalNextId === $nextId) {
            $globalNextRow = null;
            $globalNextId  = null;
        }

        $stmtSeats = $db->prepare("
            SELECT s.id, s.row_index, s.col_index, s.label,
                   ss.student_id,
                   st.last_name, st.first_name
            FROM seats s
            LEFT JOIN session_seats ss
                   ON ss.seat_id = s.id AND ss.session_id = ?
            LEFT JOIN students st ON st.id = ss.student_id
            WHERE s.room_id = ?
            ORDER BY s.row_index, s.col_index
        ");
        $stmtSeats->execute([$session['id'], $session['room_id']]);
        $seats = $stmtSeats->fetchAll();

        $tags = $db->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();

        $stmtObs = $db->prepare("
            SELECT o.*, t.color, t.icon
            FROM observations o
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
        ");
        $stmtObs->execute([$p['id']]);
        $observations = $stmtObs->fetchAll();

        ob_start();
        require __DIR__ . '/../../views/sessions/live.php';
        $content = ob_get_clean();

        require __DIR__ . '/../../views/layouts/app.php';
    }

    // ================================================================
    //  API JSON
    // ================================================================

    /**
     * Crée une (ou plusieurs) séance(s) et prend un snapshot du plan.
     * Route : POST /sessions
     *
     * Corps JSON :
     *   { "plan_id": 5, "date": "2026-05-07", "time_start": "09:00",
     *     "time_end": "10:00", "subject": "Maths",
     *     "recurrence": { "type": "none" }  ← null ou absent = séance unique
     *     "recurrence": { "type": "count", "count": 12 }
     *     "recurrence": { "type": "until", "until": "2026-06-27" } }
     *
     * Réponse séance unique : { "ok": true, "id": 42 }
     * Réponse récurrence    : { "ok": true, "ids": [42, 43, …], "count": 12 }
     */
    public function apiCreate(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['plan_id']) || empty($data['date'])) {
            Response::json(['error' => 'plan_id et date sont requis'], 400);
            return;
        }

        if (!\DateTime::createFromFormat('Y-m-d', $data['date'])) {
            Response::json(['error' => 'Format de date invalide (attendu : YYYY-MM-DD)'], 400);
            return;
        }

        foreach (['time_start', 'time_end'] as $field) {
            if (!empty($data[$field]) && !\DateTime::createFromFormat('H:i', $data[$field]) && !\DateTime::createFromFormat('H:i:s', $data[$field])) {
                Response::json(['error' => "Format d'heure invalide pour $field (attendu : HH:MM)"], 400);
                return;
            }
        }

        $db = Database::get();

        $stmtPlan = $db->prepare("
            SELECT sp.id, sp.room_id
            FROM seating_plans sp
            WHERE sp.id = ?
        ");
        $stmtPlan->execute([(int)$data['plan_id']]);
        $plan = $stmtPlan->fetch();
        if (!$plan) {
            Response::json(['error' => 'Plan introuvable'], 404);
            return;
        }

        // ── Calcul des dates selon la récurrence ──────────────────
        $recurrence = $data['recurrence'] ?? null;
        $recType    = is_array($recurrence) ? ($recurrence['type'] ?? 'none') : 'none';

        $dates = [$data['date']]; // tableau des dates à créer

        if ($recType === 'count') {
            $count = max(2, min(52, (int)($recurrence['count'] ?? 2)));
            $cur   = new \DateTime($data['date']);
            for ($i = 1; $i < $count; $i++) {
                $cur->modify('+7 days');
                $dates[] = $cur->format('Y-m-d');
            }
        } elseif ($recType === 'until') {
            $until = \DateTime::createFromFormat('Y-m-d', $recurrence['until'] ?? '');
            if ($until) {
                $cur = new \DateTime($data['date']);
                while (true) {
                    $cur->modify('+7 days');
                    if ($cur > $until) break;
                    $dates[] = $cur->format('Y-m-d');
                    if (count($dates) >= 52) break; // sécurité anti-boucle infinie
                }
            }
        }

        // ── Création en transaction ──────────────────────────────
        $db->beginTransaction();
        try {
            $insertedIds = [];
            $stmtIns = $db->prepare(
                "INSERT INTO sessions (plan_id, `date`, time_start, time_end, subject)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmtSnap = $db->prepare("
                INSERT INTO session_seats (session_id, seat_id, student_id)
                SELECT ?, s.id, sa.student_id
                FROM seats s
                LEFT JOIN seating_assignments sa
                       ON sa.seat_id = s.id AND sa.plan_id = ?
                WHERE s.room_id = ?
            ");

            foreach ($dates as $d) {
                $stmtIns->execute([
                    (int)$data['plan_id'],
                    $d,
                    $data['time_start'] ?? null,
                    $data['time_end']   ?? null,
                    $data['subject']    ?? null,
                ]);
                $sessionId = (int)$db->lastInsertId();
                $stmtSnap->execute([$sessionId, (int)$data['plan_id'], (int)$plan['room_id']]);
                $insertedIds[] = $sessionId;
            }

            $db->commit();

            if (count($insertedIds) === 1) {
                Response::json(['ok' => true, 'id' => $insertedIds[0]]);
            } else {
                Response::json(['ok' => true, 'ids' => $insertedIds, 'count' => count($insertedIds)]);
            }
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Supprime une séance et toutes ses observations.
     * Route : DELETE /sessions/{id}
     */
    public function apiDelete(array $p): void
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM observations WHERE session_id = ?")->execute([$p['id']]);
            $db->prepare("DELETE FROM sessions WHERE id = ?")->execute([$p['id']]);
            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression'], 500);
        }
    }

    /**
     * Retourne un résumé des observations d'une séance.
     * Route : GET /sessions/{id}/observations/summary
     */
    public function apiObservationsSummary(array $p): void
    {
        $stmt = Database::get()->prepare("
            SELECT st.first_name, st.last_name, o.tag, t.color, t.icon
            FROM observations o
            JOIN students st ON st.id = o.student_id
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
            ORDER BY st.last_name, st.first_name, o.tag
        ");
        $stmt->execute([$p['id']]);
        $rows = $stmt->fetchAll();
        Response::json(['count' => count($rows), 'rows' => $rows]);
    }

    /**
     * Exporte les observations d'une séance en CSV.
     * Route : GET /sessions/{id}/observations/export
     */
    public function apiObservationsExport(array $p): void
    {
        $db = Database::get();

        $stmtSes = $db->prepare("
            SELECT se.date, se.time_start, COALESCE(g.name, c.name) AS class_name
            FROM sessions se
            JOIN seating_plans sp ON sp.id = se.plan_id
            JOIN classes c ON c.id = sp.class_id
            LEFT JOIN groups g ON g.id = sp.group_id
            WHERE se.id = ?
        ");
        $stmtSes->execute([$p['id']]);
        $ses = $stmtSes->fetch();

        if (!$ses) {
            http_response_code(404);
            echo 'Séance introuvable';
            return;
        }

        $stmtObs = $db->prepare("
            SELECT st.last_name, st.first_name, o.tag, o.note, o.created_at
            FROM observations o
            JOIN students st ON st.id = o.student_id
            WHERE o.session_id = ?
            ORDER BY st.last_name, st.first_name, o.tag
        ");
        $stmtObs->execute([$p['id']]);
        $rows = $stmtObs->fetchAll();

        $filename = 'observations_'
            . str_replace([' ', '/'], '_', $ses['class_name'])
            . '_' . $ses['date']
            . ($ses['time_start'] ? '_' . substr($ses['time_start'], 0, 5) : '')
            . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Nom', 'Prénom', 'Tag', 'Note', 'Horodatage'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['last_name'],
                $row['first_name'],
                $row['tag'],
                $row['note'] ?? '',
                $row['created_at'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Ajoute une observation sur un élève dans une séance.
     * Route : POST /sessions/{id}/observations
     */
    public function apiAddObservation(array $p): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['student_id']) || empty($data['tag'])) {
            Response::json(['error' => 'student_id et tag sont requis'], 400);
            return;
        }

        $db = Database::get();
        $db->prepare(
            "INSERT INTO observations (session_id, student_id, tag, note) VALUES (?, ?, ?, ?)"
        )->execute([$p['id'], $data['student_id'], $data['tag'], $data['note'] ?? null]);

        Response::json(['ok' => true, 'obs_id' => (int)$db->lastInsertId()]);
    }

    /**
     * Supprime une observation spécifique.
     * Route : DELETE /sessions/{id}/observations/{obs_id}
     */
    public function apiRemoveObservation(array $p): void
    {
        $stmt = Database::get()->prepare("DELETE FROM observations WHERE id = ? AND session_id = ?");
        $stmt->execute([$p['obs_id'], $p['id']]);

        if ($stmt->rowCount() === 0) {
            Response::json(['error' => 'Observation introuvable ou non autorisée'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    /**
     * Retourne toutes les observations d'une séance.
     * Route : GET /sessions/{id}/observations
     */
    public function apiGetObservations(array $p): void
    {
        $stmtObs = Database::get()->prepare("
            SELECT o.*, t.color, t.icon
            FROM observations o
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
        ");
        $stmtObs->execute([$p['id']]);
        Response::json($stmtObs->fetchAll());
    }

    /**
     * Retourne la liste JSON de tous les tags.
     * Route : GET /tags
     */
    public function apiGetTags(): void
    {
        Response::json(Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll());
    }

    /**
     * Crée ou met à jour un tag.
     * Route : POST /tags
     */
    public function apiSaveTag(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data) || empty($data['label'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        $db = Database::get();
        if (!empty($data['id'])) {
            $db->prepare("UPDATE tags SET label = ?, color = ?, icon = ? WHERE id = ?")
               ->execute([$data['label'], $data['color'], $data['icon'], $data['id']]);
        } else {
            $db->prepare("INSERT INTO tags (label, color, icon, sort_order) VALUES (?, ?, ?, ?)")
               ->execute([$data['label'], $data['color'], $data['icon'] ?? '', $data['sort_order'] ?? 99]);
        }

        Response::json(['ok' => true]);
    }

    /**
     * Supprime un tag avec vérification d'usage.
     * Route : DELETE /tags/{id}  (+ ?force=1)
     */
    public function apiDeleteTag(array $p): void
    {
        $db = Database::get();

        if (empty($_GET['force'])) {
            $stmtCheck = $db->prepare(
                "SELECT COUNT(*) FROM observations o
                JOIN tags t ON t.label = o.tag
                WHERE t.id = ?"
            );
            $stmtCheck->execute([$p['id']]);
            $count = (int)$stmtCheck->fetchColumn();

            if ($count > 0) {
                Response::json([
                    'error'     => "Ce tag est utilisé dans $count observation(s) existante(s). Supprimez-les d'abord ou forcez la suppression.",
                    'count'     => $count,
                    'can_force' => true,
                ], 409);
                return;
            }
        }

        $db->prepare("DELETE FROM tags WHERE id = ?")->execute([$p['id']]);
        Response::json(['ok' => true]);
    }

    /**
     * Affiche la page de gestion des tags.
     * Route : GET /tags
     */
    public function tagsIndex(): void
    {
        $tags = Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();
        $pageTitle = 'Tags';
        ob_start();
        require ROOT . '/views/tags/index.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    /**
     * Déplace ou échange un élève sur une place dans le snapshot d'une séance.
     * Route : POST /sessions/{id}/move-seat
     */
    public function apiMoveSeat(array $p): void
    {
        $data         = json_decode(file_get_contents('php://input'), true);
        $studentId    = (int)($data['student_id']    ?? 0);
        $sourceSeatId = (int)($data['source_seat_id'] ?? 0);
        $targetSeatId = (int)($data['target_seat_id'] ?? 0);
        $sessionId    = (int)$p['id'];
        $rawScope     = $data['scope'] ?? 'session';
        $scope        = in_array($rawScope, ['session', 'forward'], true) ? $rawScope : 'session';

        if (!$studentId || !$sourceSeatId || !$targetSeatId || !$sessionId) {
            Response::json(['error' => 'Paramètres manquants'], 400);
            return;
        }
        if ($sourceSeatId === $targetSeatId) {
            Response::json(['error' => 'Source et cible identiques'], 400);
            return;
        }

        $db = Database::get();

        $stmtSes = $db->prepare("SELECT plan_id, `date`, time_start FROM sessions WHERE id = ?");
        $stmtSes->execute([$sessionId]);
        $session = $stmtSes->fetch();
        if (!$session) {
            Response::json(['error' => 'Séance introuvable'], 404);
            return;
        }

        $today  = date('Y-m-d');
        $isPast = $session['date'] < $today;
        if ($isPast) {
            Response::json(['error' => 'Impossible de modifier une séance passée'], 403);
            return;
        }

        $planId  = (int)$session['plan_id'];
        $sesDate = $session['date'];
        $sesTime = $session['time_start'];

        try {
            $db->beginTransaction();

            $stmtSrcCheck = $db->prepare(
                "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
            );
            $stmtSrcCheck->execute([$sessionId, $sourceSeatId]);
            $srcRow = $stmtSrcCheck->fetch();
            if (!$srcRow || (int)$srcRow['student_id'] !== $studentId) {
                $db->rollBack();
                Response::json(['error' => "L'élève n'est pas sur la place source dans cette séance"], 422);
                return;
            }

            $stmtTgt = $db->prepare(
                "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
            );
            $stmtTgt->execute([$sessionId, $targetSeatId]);
            $tgtRow          = $stmtTgt->fetch();
            $targetStudentId = ($tgtRow && $tgtRow['student_id'] !== null)
                               ? (int)$tgtRow['student_id']
                               : null;

            $db->prepare(
                "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
            )->execute([$targetStudentId, $sessionId, $sourceSeatId]);
            $db->prepare(
                "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
            )->execute([$studentId, $sessionId, $targetSeatId]);

            $skippedSessions = [];

            if ($scope === 'forward') {
                $stmtFuture = $db->prepare("
                    SELECT id, `date`, time_start
                    FROM sessions
                    WHERE plan_id = ?
                      AND (
                            `date` > ?
                        OR (`date` = ? AND (time_start IS NULL OR time_start > ?))
                      )
                      AND id != ?
                    ORDER BY `date`, time_start
                ");
                $stmtFuture->execute([$planId, $sesDate, $sesDate, $sesTime, $sessionId]);
                $futureSessions = $stmtFuture->fetchAll();

                foreach ($futureSessions as $fut) {
                    $futId = (int)$fut['id'];

                    $stmtFS = $db->prepare(
                        "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
                    );
                    $stmtFS->execute([$futId, $sourceSeatId]);
                    $futSrcRow = $stmtFS->fetch();

                    if (!$futSrcRow || (int)$futSrcRow['student_id'] !== $studentId) {
                        $skippedSessions[] = [
                            'id'     => $futId,
                            'date'   => $fut['date'],
                            'time'   => $fut['time_start'],
                            'reason' => "élève absent de la place source",
                        ];
                        continue;
                    }

                    $stmtFT = $db->prepare(
                        "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
                    );
                    $stmtFT->execute([$futId, $targetSeatId]);
                    $futTgtRow        = $stmtFT->fetch();
                    $futTargetStudent = ($futTgtRow && $futTgtRow['student_id'] !== null)
                                       ? (int)$futTgtRow['student_id']
                                       : null;

                    if (
                        $futTargetStudent !== null
                        && $targetStudentId !== null
                        && $futTargetStudent !== $targetStudentId
                    ) {
                        $skippedSessions[] = [
                            'id'     => $futId,
                            'date'   => $fut['date'],
                            'time'   => $fut['time_start'],
                            'reason' => "place cible occupée par un autre élève",
                        ];
                        continue;
                    }

                    $db->prepare(
                        "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
                    )->execute([$futTargetStudent, $futId, $sourceSeatId]);
                    $db->prepare(
                        "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
                    )->execute([$studentId, $futId, $targetSeatId]);
                }
            }

            $db->commit();

            if (!empty($skippedSessions)) {
                $this->logMoveSeatWarning([
                    'session_id'       => $sessionId,
                    'student_id'       => $studentId,
                    'source_seat_id'   => $sourceSeatId,
                    'target_seat_id'   => $targetSeatId,
                    'scope'            => $scope,
                    'skipped_sessions' => $skippedSessions,
                ]);
            }

            Response::json([
                'ok'                => true,
                'scope'             => $scope,
                'swapped_student_id' => $targetStudentId,
                'skipped_sessions'  => $skippedSessions,
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retire un élève de sa place dans le snapshot d'une séance.
     * Route : DELETE /sessions/{id}/students/{student_id}
     */
    public function apiRemoveStudent(array $p): void
    {
        $sessionId = (int)$p['id'];
        $studentId = (int)$p['student_id'];

        $db = Database::get();

        $stmtSeat = $db->prepare("
            SELECT seat_id FROM session_seats
            WHERE session_id = ? AND student_id = ?
            LIMIT 1
        ");
        $stmtSeat->execute([$sessionId, $studentId]);
        $seatId = $stmtSeat->fetchColumn();

        if (!$seatId) {
            Response::json(['error' => 'Élève non placé dans cette séance'], 404);
            return;
        }

        $db->prepare("
            UPDATE session_seats SET student_id = NULL
            WHERE session_id = ? AND seat_id = ?
        ")->execute([$sessionId, (int)$seatId]);

        Response::json(['ok' => true]);
    }

    // ================================================================
    //  HELPERS PRIVÉS
    // ================================================================

    private function logMoveSeatWarning(array $details): void
    {
        try {
            Database::get()
                ->prepare(
                    "INSERT INTO app_logs (level, category, action, details)
                     VALUES ('warning', 'seats', 'move_seat_partial', ?)"
                )
                ->execute([json_encode($details, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $_) {
            // Silencieux
        }
    }
}
