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
//    apiCreate               → créer une séance + snapshot des places
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
     *
     * Charge :
     *  - La page courante de séances (100 par page, triées par date DESC)
     *  - Les séances de la semaine sélectionnée (pour le mini-calendrier)
     *  - Tous les plans disponibles (pour le formulaire de création)
     */
    public function index(): void
    {
        $db = Database::get();

        // ── Pagination ──
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 100;
        $offset  = ($page - 1) * $perPage;

        // Séances paginées avec noms de classe, plan et salle
        // COALESCE(g.name, c.name, se.multi_classes) : groupe > classe > multi-classes
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

        // Comptage total pour le calcul du nombre de pages
        $total      = (int)$db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
        $totalPages = (int)ceil($total / $perPage);

        // ── Vue semaine ──
        // Parse le paramètre ?week=YYYY-WNN (format ISO 8601)
        $weekParam = $_GET['week'] ?? null;
        if ($weekParam && preg_match('/^\d{4}-W(\d{2})$/', $weekParam, $m)) {
            $weekDate = new \DateTime();
            $weekDate->setISODate((int)explode('-W', $weekParam)[0], (int)$m[1]);
        } else {
            // Par défaut : semaine courante
            $weekDate = new \DateTime();
        }
        $weekStart = (clone $weekDate)->modify('monday this week')->format('Y-m-d');
        $weekEnd   = (clone $weekDate)->modify('sunday this week')->format('Y-m-d');

        // Séances de la semaine sélectionnée, triées chronologiquement
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

        // Tous les plans (pour le formulaire de création de séance)
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
     *
     * Charge :
     *  - Les métadonnées de la séance (classe, salle, dimensions)
     *  - La séance précédente et suivante de la même classe (toutes salles confondues)
     *  - Le snapshot des places (session_seats) avec les élèves placés
     *  - Les tags d'observation disponibles
     *  - Les observations déjà enregistrées pour cette séance
     *
     * @param array $p  $p['id'] = identifiant de la séance
     */
    public function live(array $p): void
    {
        $db = Database::get();

        // Séance avec toutes ses métadonnées (classe, groupe, salle, dimensions)
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

        // ── Navigation précédente / suivante (même class_id, toutes salles) ──
        // "Précédente" = séance antérieure (date < ou même date avec heure <)
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

        // "Suivante" = séance postérieure (date > ou même date avec heure >)
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

        // ── Snapshot des places (lecture depuis session_seats) ──
        // session_seats est un snapshot figé au moment de la création de la séance :
        // les places reflètent le plan tel qu'il était, indépendamment des modifications
        // ultérieures du plan. LEFT JOIN → place vide si student_id est null.
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

        // Tags disponibles (pour les boutons d'observation)
        $tags = $db->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll();

        // Observations déjà enregistrées pour cette séance, avec couleur/icône du tag
        $stmtObs = $db->prepare("
            SELECT o.*, t.color, t.icon
            FROM observations o
            LEFT JOIN tags t ON t.label = o.tag
            WHERE o.session_id = ?
        ");
        $stmtObs->execute([$p['id']]);
        $observations = $stmtObs->fetchAll();

        require __DIR__ . '/../../views/sessions/live.php';
    }

    // ================================================================
    //  API JSON
    // ================================================================

    /**
     * Crée une séance et prend un snapshot immédiat du plan dans session_seats.
     * Route : POST /sessions
     *
     * Corps JSON : { "plan_id": 5, "date": "2025-10-15", "time_start": "09:00",
     *               "time_end": "10:00", "subject": "Maths" }
     *
     * Le snapshot (INSERT INTO session_seats … SELECT … FROM seats LEFT JOIN seating_assignments)
     * copie l'état actuel du plan. Toute modification ultérieure du plan n'affecte pas
     * les séances déjà créées — elles sont autonomes.
     *
     * Validation : plan_id requis, date au format YYYY-MM-DD, heures au format HH:MM.
     */
    public function apiCreate(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validation des champs obligatoires
        if (!is_array($data) || empty($data['plan_id']) || empty($data['date'])) {
            Response::json(['error' => 'plan_id et date sont requis'], 400);
            return;
        }

        // Validation du format de date (YYYY-MM-DD)
        if (!\DateTime::createFromFormat('Y-m-d', $data['date'])) {
            Response::json(['error' => 'Format de date invalide (attendu : YYYY-MM-DD)'], 400);
            return;
        }

        // Validation des formats d'heure (HH:MM ou HH:MM:SS)
        foreach (['time_start', 'time_end'] as $field) {
            if (!empty($data[$field]) && !\DateTime::createFromFormat('H:i', $data[$field]) && !\DateTime::createFromFormat('H:i:s', $data[$field])) {
                Response::json(['error' => "Format d'heure invalide pour $field (attendu : HH:MM)"], 400);
                return;
            }
        }

        $db = Database::get();

        // Vérifie l'existence du plan et récupère room_id pour le snapshot
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

        $db->beginTransaction();
        try {
            // Crée la séance
            $db->prepare(
                "INSERT INTO sessions (plan_id, `date`, time_start, time_end, subject)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([
                (int)$data['plan_id'],
                $data['date'],
                $data['time_start'] ?? null,
                $data['time_end']   ?? null,
                $data['subject']    ?? null,
            ]);
            $sessionId = (int)$db->lastInsertId();

            // Snapshot : copie de toutes les places de la salle avec l'élève affecté
            // (ou NULL si la place est vide dans le plan de référence).
            // Ce snapshot est indépendant du plan : les modifications ultérieures du plan
            // n'affecteront pas cette séance.
            $db->prepare("
                INSERT INTO session_seats (session_id, seat_id, student_id)
                SELECT ?, s.id, sa.student_id
                FROM seats s
                LEFT JOIN seating_assignments sa
                       ON sa.seat_id = s.id AND sa.plan_id = ?
                WHERE s.room_id = ?
            ")->execute([$sessionId, (int)$data['plan_id'], (int)$plan['room_id']]);

            $db->commit();
            Response::json(['ok' => true, 'id' => $sessionId]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Supprime une séance et toutes ses observations.
     * Route : DELETE /sessions/{id}
     *
     * Note : session_seats n'est pas supprimé explicitement ici — vérifier si une
     * contrainte FK avec ON DELETE CASCADE est définie sur la table.
     *
     * @param array $p  $p['id'] = identifiant de la séance
     */
    public function apiDelete(array $p): void
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            // Supprime d'abord les observations (contrainte FK)
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
     * Retourne un résumé des observations d'une séance (pour affichage en modale)
     * Route : GET /sessions/{id}/observations/summary
     *
     * Réponse : { "count": 5, "rows": [ { "first_name", "last_name", "tag", "color", "icon" } ] }
     *
     * @param array $p  $p['id'] = identifiant de la séance
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
     * Exporte les observations d'une séance en fichier CSV (téléchargement direct).
     * Route : GET /sessions/{id}/observations/export
     *
     * Format CSV : séparateur « ; », BOM UTF-8 (\xEF\xBB\xBF) pour compatibilité Excel.
     * Colonnes : Nom, Prénom, Tag, Note, Horodatage.
     * Nom du fichier : observations_CLASSE_DATE_HEURE.csv
     *
     * @param array $p  $p['id'] = identifiant de la séance
     */
    public function apiObservationsExport(array $p): void
    {
        $db = Database::get();

        // Récupère les métadonnées de la séance pour nommer le fichier
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

        // Observations triées alphabétiquement par élève puis par tag
        $stmtObs = $db->prepare("
            SELECT st.last_name, st.first_name, o.tag, o.note, o.created_at
            FROM observations o
            JOIN students st ON st.id = o.student_id
            WHERE o.session_id = ?
            ORDER BY st.last_name, st.first_name, o.tag
        ");
        $stmtObs->execute([$p['id']]);
        $rows = $stmtObs->fetchAll();

        // Construction du nom de fichier sécurisé (espaces/slashes remplacés par _)
        $filename = 'observations_'
            . str_replace([' ', '/'], '_', $ses['class_name'])
            . '_' . $ses['date']
            . ($ses['time_start'] ? '_' . substr($ses['time_start'], 0, 5) : '')
            . '.csv';

        // En-têtes HTTP pour déclencher le téléchargement
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 : nécessaire pour qu'Excel détecte correctement l'encodage
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
     * Ajoute une observation (tag + note optionnelle) sur un élève dans une séance.
     * Route : POST /sessions/{id}/observations
     *
     * Corps JSON : { "student_id": 12, "tag": "Participation", "note": "Très actif" }
     * Réponse : { "ok": true, "obs_id": 42 }
     *
     * @param array $p  $p['id'] = identifiant de la séance
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
     * Supprime une observation spécifique d'une séance.
     * Route : DELETE /sessions/{id}/observations/{obs_id}
     *
     * La double condition (id ET session_id) empêche de supprimer une observation
     * d'une autre séance si l'obs_id est deviné.
     *
     * @param array $p  $p['id'] = séance, $p['obs_id'] = observation à supprimer
     */
    public function apiRemoveObservation(array $p): void
    {
        $stmt = Database::get()->prepare("DELETE FROM observations WHERE id = ? AND session_id = ?");
        $stmt->execute([$p['obs_id'], $p['id']]);

        // rowCount() = 0 signifie que l'observation n'existait pas ou n'appartient pas à cette séance
        if ($stmt->rowCount() === 0) {
            Response::json(['error' => 'Observation introuvable ou non autorisée'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    /**
     * Retourne toutes les observations d'une séance avec couleur/icône du tag.
     * Route : GET /sessions/{id}/observations
     *
     * @param array $p  $p['id'] = identifiant de la séance
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
     * Retourne la liste JSON de tous les tags, triés par sort_order.
     * Route : GET /tags
     */
    public function apiGetTags(): void
    {
        Response::json(Database::get()->query("SELECT * FROM tags ORDER BY sort_order")->fetchAll());
    }

    /**
     * Crée ou met à jour un tag d'observation.
     * Route : POST /tags  (création) ou PUT/POST /tags/{id} (mise à jour)
     *
     * Corps JSON : { "label": "Participation", "color": "#4caf50", "icon": "👋", "sort_order": 1 }
     * La présence de "id" dans le corps détermine la création ou la mise à jour.
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
            // Mise à jour d'un tag existant
            $db->prepare("UPDATE tags SET label = ?, color = ?, icon = ? WHERE id = ?")
               ->execute([$data['label'], $data['color'], $data['icon'], $data['id']]);
        } else {
            // Création d'un nouveau tag
            $db->prepare("INSERT INTO tags (label, color, icon, sort_order) VALUES (?, ?, ?, ?)")
               ->execute([$data['label'], $data['color'], $data['icon'] ?? '', $data['sort_order'] ?? 99]);
        }

        Response::json(['ok' => true]);
    }

    /**
     * Supprime un tag, avec vérification d'usage préalable.
     * Route : DELETE /tags/{id}  (+ ?force=1 pour ignorer la vérification)
     *
     * Comportement :
     *  - Sans ?force=1 : vérifie si le tag est utilisé dans des observations.
     *    Si oui, retourne 409 Conflict avec le nombre d'usages et can_force=true.
     *  - Avec ?force=1 : supprime le tag sans vérification (les observations
     *    conserveront leur tag sous forme de texte libre, sans lien vers un tag valide).
     *
     * @param array $p  $p['id'] = identifiant du tag à supprimer
     */
    public function apiDeleteTag(array $p): void
    {
        $db = Database::get();

        if (empty($_GET['force'])) {
            // Vérifie si le tag est référencé dans des observations existantes
            $stmtCheck = $db->prepare(
                "SELECT COUNT(*) FROM observations o
                JOIN tags t ON t.label = o.tag
                WHERE t.id = ?"
            );
            $stmtCheck->execute([$p['id']]);
            $count = (int)$stmtCheck->fetchColumn();

            if ($count > 0) {
                // 409 Conflict : le tag est en cours d'utilisation
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
     * Affiche la page de gestion des tags d'observation.
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
     *
     * Corps JSON :
     *  {
     *    "student_id":    12,      // élève à déplacer
     *    "source_seat_id": 5,      // place actuelle de l'élève
     *    "target_seat_id": 8,      // place cible
     *    "scope": "session"        // "session" = séance courante uniquement
     *                              // "forward"  = séance courante + toutes les séances futures du même plan
     *  }
     *
     * Logique scope=forward :
     *  Pour chaque séance future du même plan :
     *    - Vérifie que l'élève est bien sur la place source → sinon : skip + warning
     *    - Si la place cible est occupée par un élève différent de l'occupant actuel
     *      dans la séance courante → skip + warning (évite d'écraser un placement
     *      intentionnel qui diffère du plan de référence)
     *    - Sinon : swap source ↔ cible
     *
     * Sécurité : refuse les modifications sur les séances strictement passées
     * (date antérieure à aujourd'hui). Les séances d'aujourd'hui restent toujours
     * modifiables, même si l'heure de début est déjà dépassée.
     *
     * @param array $p  $p['id'] = identifiant de la séance
     */
    public function apiMoveSeat(array $p): void
    {
        $data         = json_decode(file_get_contents('php://input'), true);
        $studentId    = (int)($data['student_id']    ?? 0);
        $sourceSeatId = (int)($data['source_seat_id'] ?? 0);
        $targetSeatId = (int)($data['target_seat_id'] ?? 0);
        $sessionId    = (int)$p['id'];
        // Valide le scope : seules les valeurs 'session' et 'forward' sont acceptées
        $rawScope     = $data['scope'] ?? 'session';
        $scope        = in_array($rawScope, ['session', 'forward'], true) ? $rawScope : 'session';

        // Validation des paramètres obligatoires
        if (!$studentId || !$sourceSeatId || !$targetSeatId || !$sessionId) {
            Response::json(['error' => 'Paramètres manquants'], 400);
            return;
        }
        if ($sourceSeatId === $targetSeatId) {
            Response::json(['error' => 'Source et cible identiques'], 400);
            return;
        }

        $db = Database::get();

        // Charge la séance pour vérifier la date et récupérer plan_id
        $stmtSes = $db->prepare("SELECT plan_id, `date`, time_start FROM sessions WHERE id = ?");
        $stmtSes->execute([$sessionId]);
        $session = $stmtSes->fetch();
        if (!$session) {
            Response::json(['error' => 'Séance introuvable'], 404);
            return;
        }

        // ── Protection : refuse la modification d'une séance strictement passée ──
        // Seule la DATE compte : une séance d'aujourd'hui reste toujours modifiable,
        // même si l'heure de début est déjà dépassée (l'enseignant peut corriger
        // le plan après le cours).
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

            // ── 1. Vérifier que l'élève est bien sur la place source dans cette séance ──
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

            // ── 2. Occupant actuel de la place cible (peut être null si vide) ──
            $stmtTgt = $db->prepare(
                "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
            );
            $stmtTgt->execute([$sessionId, $targetSeatId]);
            $tgtRow          = $stmtTgt->fetch();
            $targetStudentId = ($tgtRow && $tgtRow['student_id'] !== null)
                               ? (int)$tgtRow['student_id']
                               : null;

            // ── 3. Swap dans la séance courante ──
            // Place source → reçoit l'ancien occupant de la cible (ou null)
            $db->prepare(
                "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
            )->execute([$targetStudentId, $sessionId, $sourceSeatId]);
            // Place cible → reçoit l'élève déplacé
            $db->prepare(
                "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
            )->execute([$studentId, $sessionId, $targetSeatId]);

            $skippedSessions = [];

            // ── 4. Propagation aux séances futures (scope=forward uniquement) ──
            if ($scope === 'forward') {
                // Récupère toutes les séances futures du même plan (strictement après la séance courante)
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
                $stmtFuture->execute([
                    $planId,
                    $sesDate,
                    $sesDate,
                    $sesTime,
                    $sessionId,
                ]);
                $futureSessions = $stmtFuture->fetchAll();

                foreach ($futureSessions as $fut) {
                    $futId = (int)$fut['id'];

                    // Vérifie que l'élève est sur la place source dans cette séance future
                    $stmtFS = $db->prepare(
                        "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
                    );
                    $stmtFS->execute([$futId, $sourceSeatId]);
                    $futSrcRow = $stmtFS->fetch();

                    if (!$futSrcRow || (int)$futSrcRow['student_id'] !== $studentId) {
                        // L'élève n'est pas sur la place source → séance ignorée
                        $skippedSessions[] = [
                            'id'     => $futId,
                            'date'   => $fut['date'],
                            'time'   => $fut['time_start'],
                            'reason' => "élève absent de la place source",
                        ];
                        continue;
                    }

                    // Vérifie l'occupant de la place cible dans la séance future
                    $stmtFT = $db->prepare(
                        "SELECT student_id FROM session_seats WHERE session_id = ? AND seat_id = ?"
                    );
                    $stmtFT->execute([$futId, $targetSeatId]);
                    $futTgtRow       = $stmtFT->fetch();
                    $futTargetStudent = ($futTgtRow && $futTgtRow['student_id'] !== null)
                                       ? (int)$futTgtRow['student_id']
                                       : null;

                    // Si la cible est occupée par un élève différent de l'occupant
                    // dans la séance de référence → placement intentionnellement différent,
                    // on ne l'écrase pas pour ne pas défaire un swap précédent
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

                    // Applique le swap dans la séance future
                    $db->prepare(
                        "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
                    )->execute([$futTargetStudent, $futId, $sourceSeatId]);
                    $db->prepare(
                        "UPDATE session_seats SET student_id = ? WHERE session_id = ? AND seat_id = ?"
                    )->execute([$studentId, $futId, $targetSeatId]);
                }
            }

            $db->commit();

            // Si certaines séances futures ont été ignorées, on logue un warning
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
                'swapped_student_id' => $targetStudentId,  // null si la cible était vide
                'skipped_sessions'  => $skippedSessions,   // tableau vide si tout s'est bien passé
            ]);

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retire un élève de sa place dans le snapshot d'une séance (place → null).
     * Route : DELETE /sessions/{id}/students/{student_id}
     *
     * La place reste dans le snapshot mais student_id passe à NULL.
     * L'élève n'est pas supprimé de la BDD, uniquement de la séance.
     *
     * @param array $p  $p['id'] = séance, $p['student_id'] = élève à retirer
     */
    public function apiRemoveStudent(array $p): void
    {
        $sessionId = (int)$p['id'];
        $studentId = (int)$p['student_id'];

        $db = Database::get();

        // Trouve le siège occupé par l'élève dans le snapshot de la séance
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

        // Met student_id à NULL sur la place (la place reste, l'élève est retiré)
        $db->prepare("
            UPDATE session_seats SET student_id = NULL
            WHERE session_id = ? AND seat_id = ?
        ")->execute([$sessionId, (int)$seatId]);

        Response::json(['ok' => true]);
    }

    // ================================================================
    //  HELPERS PRIVÉS
    // ================================================================

    /**
     * Insère un warning dans app_logs pour signaler qu'un déplacement
     * avec scope=forward a été partiellement propagé (certaines séances ignorées).
     *
     * Utilisé pour traçabilité — ne génère pas d'erreur côté utilisateur.
     * Silencieux en cas d'échec (le log ne doit jamais faire rater la requête principale).
     *
     * @param array $details  Contexte complet : session_id, student_id, sièges, séances ignorées
     */
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
            // Silencieux : le log ne doit pas faire échouer la requête principale
        }
    }
}
