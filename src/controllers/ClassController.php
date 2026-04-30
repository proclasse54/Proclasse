<?php
// src/controllers/ClassController.php
// ============================================================
//  Contrôleur des classes — gère l'affichage et les opérations
//  CRUD sur les classes, leurs plans de placement et leurs élèves.
//
//  Méthodes HTML (rendu de vue) :
//    index()         → liste de toutes les classes
//    show($p)        → détail d'une classe (élèves, plans, groupes)
//    planEdit($p)    → éditeur de plan de placement d'une classe
//
//  Méthodes API (réponses JSON) :
//    apiSaveClass       → créer ou mettre à jour une classe
//    apiDeleteClass     → supprimer une classe et toutes ses dépendances
//    apiDeleteAllClasses→ vider toutes les données (classes + élèves + plans)
//    apiGetStudents     → liste JSON des élèves d'une classe
//    apiImportStudents  → délègue à PronoteImportController
//    apiImportPaste     → import Pronote via copier-coller
//    apiSavePlan        → créer ou retrouver un plan de placement
//    apiGetPlan         → liste JSON des affectations d'un plan
//    apiDeletePlan      → supprimer un plan et ses affectations
//    apiSaveAssignments → enregistrer/remplacer les affectations d'un plan
// ============================================================
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Response.php';

class ClassController {

    // ================================================================
    //  VUE HTML
    // ================================================================

    /**
     * Affiche la liste de toutes les classes avec le nombre d'élèves.
     * Route : GET /classes
     */
    public function index(): void {
        $db = Database::get();

        // Récupère toutes les classes avec le compte d'élèves via LEFT JOIN
        // LEFT JOIN : inclut aussi les classes sans élèves (count = 0)
        $classes = $db->query("
            SELECT c.*, COUNT(s.id) as student_count
            FROM classes c
            LEFT JOIN students s ON s.class_id = c.id
            GROUP BY c.id ORDER BY c.name
        ")->fetchAll();

        // Rendu via le layout principal (output buffering)
        $pageTitle = 'Classes';
        ob_start();
        require ROOT . '/views/classes/index.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    /**
     * Affiche le détail d'une classe : élèves, salles, plans de placement et groupes.
     * Route : GET /classes/{id}
     *
     * @param array $p  Paramètres d'URL, $p['id'] = identifiant de la classe
     */
    public function show(array $p): void {
        $db = Database::get();

        // Charge la classe (404 si introuvable)
        $stmtClass = $db->prepare("SELECT * FROM classes WHERE id=?");
        $stmtClass->execute([$p['id']]);
        $class = $stmtClass->fetch();
        if (!$class) { http_response_code(404); return; }

        // Liste des élèves triés alphabétiquement
        $stmtStudents = $db->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmtStudents->execute([$p['id']]);
        $students = $stmtStudents->fetchAll();

        // Toutes les salles disponibles (pour le formulaire de création de plan)
        $rooms = $db->query("SELECT * FROM rooms ORDER BY name")->fetchAll();

        // Plans de placement de la classe, avec le nom de la salle associée
        $stmtPlans = $db->prepare("SELECT sp.*, r.name as room_name FROM seating_plans sp JOIN rooms r ON r.id=sp.room_id WHERE sp.class_id=?");
        $stmtPlans->execute([$p['id']]);
        $plans = $stmtPlans->fetchAll();

        // Groupes de la classe avec le nombre de membres (pour les plans de sous-groupes)
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

    /**
     * Affiche l'éditeur de plan de placement (drag & drop des élèves sur les sièges).
     * Route : GET /plans/{plan_id}/edit
     *
     * Charge :
     *  - Les métadonnées du plan (classe, salle, dimensions)
     *  - Les élèves concernés (toute la classe OU uniquement un groupe)
     *  - Les affectations actuelles siège ↔ élève
     *  - Tous les sièges de la salle avec l'élève éventuellement placé dessus
     *
     * @param array $p  $p['plan_id'] = identifiant du plan
     */
    public function planEdit(array $p): void {
        $planId = (int)$p['plan_id'];
        $db = Database::get();

        // Charge le plan avec les infos de la classe et de la salle (colonnes, lignes)
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

        // Si le plan est lié à un groupe → charge uniquement les élèves du groupe
        // Sinon → charge tous les élèves de la classe
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

        // Affectations actuelles du plan : siège → élève
        $stmtAssignments = $db->prepare("
            SELECT sa.seat_id, sa.student_id, st.first_name, st.last_name
            FROM seating_assignments sa
            JOIN students st ON st.id = sa.student_id
            WHERE sa.plan_id = ?
        ");
        $stmtAssignments->execute([$planId]);
        $assignments = $stmtAssignments->fetchAll();
        // Réindexe par student_id pour accès rapide dans la vue
        $assignedStudents = array_column($assignments, null, 'student_id');

        // Tous les sièges de la salle, avec l'élève placé dessus (LEFT JOIN → null si vide)
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

        // Dimensions de la salle (utilisées par la vue pour construire la grille)
        $room = ['cols' => $plan['room_cols'], 'rows' => $plan['room_rows']];
        require ROOT . '/views/plans/edit.php';
    }

    // ================================================================
    //  API JSON
    // ================================================================

    /**
     * Crée ou met à jour une classe.
     * Route : POST /classes  (création)  ou  PUT /classes/{id}  (mise à jour)
     *
     * Corps JSON attendu : { "name": "3ème A", "year": "2025-2026" }
     * Réponse : { "ok": true, "id": 42 }
     *
     * @param array $p  $p['id'] présent = mise à jour, absent = création
     */
    public function apiSaveClass(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['name'])) {
            Response::json(['error' => 'Le nom de la classe est requis'], 400);
            return;
        }
        $db = Database::get();
        if (!empty($p['id'])) {
            // Mise à jour d'une classe existante
            $db->prepare("UPDATE classes SET name=?, year=? WHERE id=?")->execute([$data['name'], $data['year'] ?? null, $p['id']]);
            $id = (int)$p['id'];
        } else {
            // Création d'une nouvelle classe
            $db->prepare("INSERT INTO classes (name, year) VALUES (?,?)")->execute([$data['name'], $data['year'] ?? null]);
            $id = (int)$db->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $id]);
    }

    /**
     * Supprime une classe et TOUTES ses dépendances en cascade, dans une transaction.
     * Route : DELETE /classes/{id}
     *
     * Ordre de suppression (du plus dépendant au moins dépendant) :
     *  observations → session_seats → sessions
     *  → seating_assignments → seating_plans
     *  → group_students → groups
     *  → student_pronote_data → students → classe
     *
     * @param array $p  $p['id'] = identifiant de la classe à supprimer
     */
    public function apiDeleteClass(array $p): void {
        $db  = Database::get();
        $id  = (int)$p['id'];

        $db->beginTransaction();
        try {
            // Observations (liées aux séances → plans → classe)
            $db->prepare("
                DELETE o FROM observations o
                JOIN sessions se ON se.id = o.session_id
                JOIN seating_plans sp ON sp.id = se.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Placements par séance (snapshot)
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

            // Affectations du plan (placement de référence)
            $db->prepare("
                DELETE sa FROM seating_assignments sa
                JOIN seating_plans sp ON sp.id = sa.plan_id
                WHERE sp.class_id = ?
            ")->execute([$id]);

            // Plans de placement
            $db->prepare("DELETE FROM seating_plans WHERE class_id = ?")->execute([$id]);

            // Membres des groupes
            $db->prepare("
                DELETE gs FROM group_students gs
                JOIN `groups` g ON g.id = gs.group_id
                WHERE g.class_id = ?
            ")->execute([$id]);

            // Groupes
            $db->prepare("DELETE FROM `groups` WHERE class_id = ?")->execute([$id]);

            // Données Pronote brutes liées aux élèves
            $db->prepare("
                DELETE spd FROM student_pronote_data spd
                JOIN students s ON s.id = spd.student_id
                WHERE s.class_id = ?
            ")->execute([$id]);

            // Élèves
            $db->prepare("DELETE FROM students WHERE class_id = ?")->execute([$id]);

            // Classe elle-même (en dernier)
            $db->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);

            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression'], 500);
        }
    }

    /**
     * Supprime TOUTES les données classes/élèves/plans en une seule opération.
     * Route : DELETE /classes  (action admin — irréversible)
     *
     * Utilise TRUNCATE + désactivation temporaire des FK pour la rapidité.
     * Les salles et sièges (rooms, seats) sont intentionnellement conservés.
     */
    public function apiDeleteAllClasses(): void
    {
        $db = Database::get();
        try {
            // Désactivation des contraintes FK pour pouvoir TRUNCATEr dans n'importe quel ordre
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Dépendants en premier (observations, séances…)
            $db->exec("TRUNCATE TABLE observations");
            $db->exec("TRUNCATE TABLE session_seats");
            $db->exec("TRUNCATE TABLE sessions");

            // Placements et plans
            $db->exec("TRUNCATE TABLE seating_assignments");
            $db->exec("TRUNCATE TABLE seating_plans");

            // Groupes
            $db->exec("TRUNCATE TABLE group_students");
            $db->exec("TRUNCATE TABLE `groups`");

            // Données Pronote brutes
            $db->exec("TRUNCATE TABLE student_pronote_data");

            // Élèves et classes (en dernier)
            $db->exec("TRUNCATE TABLE students");
            $db->exec("TRUNCATE TABLE classes");

            // NB : rooms et seats sont intentionnellement conservés
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            // Réactive les FK même en cas d'erreur pour ne pas laisser la BDD dans un état incohérent
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Délègue l'import d'élèves (fichier CSV Pronote) à PronoteImportController.
     * Route : POST /classes/{id}/import
     *
     * @param array $p  $p['id'] = identifiant de la classe cible
     */
    public function apiImportStudents(array $p): void {
        require_once __DIR__ . '/PronoteImportController.php';
        (new PronoteImportController)->import($p);
    }

    /**
     * Retourne la liste JSON des élèves d'une classe, triés alphabétiquement.
     * Route : GET /classes/{id}/students
     *
     * @param array $p  $p['id'] = identifiant de la classe
     */
    public function apiGetStudents(array $p): void {
        $stmtStudents = Database::get()->prepare("SELECT * FROM students WHERE class_id=? ORDER BY last_name, first_name");
        $stmtStudents->execute([$p['id']]);
        Response::json($stmtStudents->fetchAll());
    }

    /**
     * Crée un plan de placement ou retrouve le plan existant avec les mêmes critères.
     * Route : POST /classes/{id}/plans
     *
     * Corps JSON : { "room_id": 3, "name": "Plan A", "group_id": null }
     *
     * La comparaison utilise <=> (NULL-safe equality) pour gérer group_id = null.
     * Si le plan existe déjà (même classe + salle + nom + groupe), retourne son id
     * sans créer de doublon.
     *
     * @param array $p  $p['id'] = identifiant de la classe
     */
    public function apiSavePlan(array $p): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['room_id'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        // group_id null = plan pour toute la classe, sinon plan pour un sous-groupe
        $groupId = !empty($data['group_id']) ? (int)$data['group_id'] : null;

        $db = Database::get();
        // Recherche d'un plan identique existant
        // <=> = opérateur NULL-safe : NULL <=> NULL retourne true (contrairement à NULL = NULL)
        $stmtExisting = $db->prepare(
            "SELECT id FROM seating_plans
            WHERE class_id=? AND room_id=? AND name=? AND (group_id <=> ?)"
        );
        $stmtExisting->execute([$p['id'], $data['room_id'], $data['name'] ?? 'Plan par défaut', $groupId]);
        $existing = $stmtExisting->fetch();

        if ($existing) {
            // Plan déjà existant : on retourne son id sans rien créer
            $planId = $existing['id'];
        } else {
            // Nouveau plan
            $db->prepare("INSERT INTO seating_plans (class_id, group_id, room_id, name) VALUES (?,?,?,?)")
            ->execute([$p['id'], $groupId, $data['room_id'], $data['name'] ?? 'Plan par défaut']);
            $planId = (int)$db->lastInsertId();
        }
        Response::json(['ok' => true, 'id' => $planId]);
    }

    /**
     * Retourne la liste JSON des affectations (siège → élève) d'un plan.
     * Route : GET /plans/{plan_id}/assignments
     *
     * @param array $p  $p['plan_id'] = identifiant du plan
     */
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

    /**
     * Supprime un plan de placement et toutes ses affectations.
     * Route : DELETE /plans/{plan_id}
     *
     * Note : les séances déjà créées à partir de ce plan ne sont PAS supprimées
     * (elles sont autonomes via le snapshot session_seats).
     *
     * @param array $p  $p['plan_id'] = identifiant du plan
     */
    public function apiDeletePlan(array $p): void {
        $db     = Database::get();
        $planId = (int)$p['plan_id'];

        $db->beginTransaction();
        try {
            // Supprime d'abord les affectations (contrainte FK)
            $db->prepare("DELETE FROM seating_assignments WHERE plan_id=?")->execute([$planId]);
            $db->prepare("DELETE FROM seating_plans WHERE id=?")->execute([$planId]);
            $db->commit();
            Response::json(['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::json(['error' => 'Erreur lors de la suppression du plan'], 500);
        }
    }

    /**
     * Enregistre (ou remplace intégralement) les affectations siège ↔ élève d'un plan.
     * Route : POST /plans/{plan_id}/assignments
     *
     * Stratégie : DELETE + INSERT (plus simple qu'un UPSERT pour un plan entier).
     * Corps JSON : { "assignments": [ { "seat_id": 12, "student_id": 7 }, … ] }
     *
     * @param array $p  $p['plan_id'] = identifiant du plan
     */
    public function apiSaveAssignments(array $p): void {
        $planId = (int)$p['plan_id'];
        $data   = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || empty($data['assignments'])) {
            Response::json(['error' => 'Données invalides'], 400);
            return;
        }

        $db = Database::get();
        // Supprime toutes les affectations existantes du plan avant de réinsérer
        $db->prepare("DELETE FROM seating_assignments WHERE plan_id=?")->execute([$planId]);
        $stmtAssign = $db->prepare("INSERT INTO seating_assignments (plan_id, seat_id, student_id) VALUES (?,?,?)");
        foreach ($data['assignments'] as $a) {
            $stmtAssign->execute([$planId, (int)$a['seat_id'], (int)$a['student_id']]);
        }
        Response::json(['ok' => true]);
    }

    /**
     * Import Pronote via copier-coller : reçoit le texte brut collé par l'utilisateur,
     * le sauvegarde dans un fichier temporaire et délègue à PronoteImportController.
     * Route : POST /classes/{id}/import-paste
     *
     * Flux :
     *  1. Lit le corps JSON ({ "data": "...texte CSV..." })
     *  2. Vérifie et convertit l'encodage (Windows-1252 → UTF-8 si nécessaire)
     *  3. Écrit dans un fichier temporaire et simule un upload ($_FILES['csv'])
     *  4. Appelle PronoteImportController->import()
     *  5. Supprime le fichier temporaire dans le bloc finally
     *
     * @param array $p  $p['id'] = identifiant de la classe cible
     */
    public function apiImportPaste(array $p): void {
        $body = json_decode(file_get_contents('php://input'), true);
        $raw  = $body['data'] ?? '';
        if (!$raw) {
            Response::json(['error' => 'données vides'], 400);
            return;
        }

        // Pronote exporte parfois en Windows-1252 : on force UTF-8 si besoin
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        // Crée un fichier temporaire pour réutiliser la logique d'import fichier
        $tmp = tempnam(sys_get_temp_dir(), 'pronote_');
        try {
            file_put_contents($tmp, $raw);
            // Simule la structure $_FILES attendue par PronoteImportController
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
            // Nettoyage garanti même en cas d'exception
            if (file_exists($tmp)) unlink($tmp);
        }
    }
}
