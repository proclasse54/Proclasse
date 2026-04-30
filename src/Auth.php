<?php
// src/Auth.php
// ============================================================
//  Service d'authentification — toutes les méthodes sont statiques
//  car Auth est utilisé partout sans instanciation.
//
//  Utilisation :
//    Auth::start()               → à appeler en tête de index.php pour démarrer la session
//    Auth::check()               → redirige vers /login si l'utilisateur n'est pas connecté
//    Auth::requireAdmin()        → redirige avec 403 si l'utilisateur n'est pas admin
//    Auth::can('import.photos')  → true si l'utilisateur possède la permission
//    Auth::user()                → retourne le tableau de l'utilisateur courant (ou null)
//    Auth::login($u, $p)         → vérifie les identifiants et ouvre la session
//    Auth::logout()              → détruit la session
// ============================================================
class Auth
{
    /**
     * Durée d'inactivité maximale avant déconnexion automatique.
     * 7200 secondes = 2 heures. Après ce délai sans activité, la session expire.
     */
    private const SESSION_LIFETIME = 7200;

    // ================================================================
    //  INITIALISATION
    // ================================================================

    /**
     * Démarre la session PHP de façon sécurisée.
     * À appeler une seule fois en début de requête (public/index.php).
     *
     * La méthode :
     *  1. Configure le cookie de session (httponly, samesite)
     *  2. Lance la session si elle n'est pas déjà démarrée
     *  3. Vérifie si la session est expirée par inactivité
     *  4. Met à jour l'horodatage de dernière activité
     */
    public static function start(): void
    {
        // Vérifie que la session n'est pas déjà active (appels multiples sans effet)
        if (session_status() === PHP_SESSION_NONE) {
            // Configure le cookie de session avant de l'envoyer
            session_set_cookie_params([
                'lifetime' => 0,       // 0 = cookie de session (supprimé à la fermeture du navigateur)
                'path'     => '/',     // valable pour tout le site
                'secure'   => false,   // mettre true si le site est en HTTPS
                'httponly' => true,    // le cookie est inaccessible via JavaScript (protection XSS)
                'samesite' => 'Lax',   // protection CSRF partielle (cookie envoyé sur navigation normale)
            ]);
            // Démarre réellement la session PHP (lit/crée le fichier de session côté serveur)
            session_start();
        }

        // ── Vérification de l'expiration par inactivité ──
        if (isset($_SESSION['_last_activity'])) {
            // Calcule le nombre de secondes depuis la dernière activité
            if (time() - $_SESSION['_last_activity'] > self::SESSION_LIFETIME) {
                // Session expirée : on déconnecte l'utilisateur proprement
                self::logout();
                return; // stoppe l'exécution de start() (logout() aura vidé la session)
            }
        }
        // Mise à jour de l'horodatage de dernière activité à chaque requête
        $_SESSION['_last_activity'] = time();
    }

    // ================================================================
    //  VÉRIFICATIONS D'ACCÈS
    // ================================================================

    /**
     * Protège une page : redirige vers /login si l'utilisateur n'est pas connecté.
     *
     * Comportement adapté selon le type de requête :
     *  - Requête classique (navigation HTML) → redirection 302 vers /login
     *  - Requête API/fetch (XHR ou Accept: json) → réponse JSON 401 avec {expired: true}
     *    (le JS front-end peut détecter cela et afficher une modale de reconnexion)
     */
    public static function check(): void
    {
        if (!self::isLoggedIn()) {
            if (self::isApiRequest()) {
                // Réponse JSON pour les appels fetch() en JavaScript
                http_response_code(401); // 401 Unauthorized
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Session expirée', 'expired' => true]);
                exit;
            }
            // Mémorise l'URL demandée pour y revenir après connexion
            // Ex : l'utilisateur accède à /classes/5, est redirigé sur /login,
            // puis après connexion retourne automatiquement sur /classes/5
            $_SESSION['_redirect_after_login'] = $_SERVER['REQUEST_URI'];
            Response::redirect('/login');
            exit;
        }
    }

    /**
     * Protège une page réservée aux administrateurs.
     * Vérifie d'abord que l'utilisateur est connecté (via check()),
     * puis vérifie qu'il a le rôle 'admin'.
     * En cas d'échec : 403 Forbidden (page ou JSON selon le contexte).
     */
    public static function requireAdmin(): void
    {
        // Délègue la vérification de connexion à check()
        self::check();
        if (!self::isAdmin()) {
            if (self::isApiRequest()) {
                http_response_code(403); // 403 Forbidden
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Accès interdit']);
                exit;
            }
            // Affiche la page d'erreur 403 pour les requêtes HTML
            http_response_code(403);
            require ROOT . '/views/403.php';
            exit;
        }
    }

    /**
     * Vérifie si l'utilisateur courant possède une permission donnée.
     *
     * Règles :
     *  - Non connecté           → false
     *  - Rôle 'admin'           → true (l'admin a TOUTES les permissions)
     *  - Rôle 'user'            → false (aucune permission admin)
     *  - Rôle 'sub_admin'       → vérification dans la table user_permissions
     *
     * @param string $permission  Identifiant de la permission (ex: 'import.photos')
     * @return bool
     */
    public static function can(string $permission): bool
    {
        if (!self::isLoggedIn()) return false;  // non connecté = accès refusé
        if (self::isAdmin()) return true;        // admin = accès total

        $user = self::user();
        if ($user['role'] !== 'sub_admin') return false; // user simple = pas de permission

        // Charge les permissions depuis la BDD si elles ne sont pas encore en session
        // (optimisation : une seule requête par session, pas à chaque appel de can())
        if (!isset($_SESSION['_permissions'])) {
            self::loadPermissions($user['id']);
        }

        // Vérifie si la permission demandée est dans la liste des permissions de l'utilisateur
        return in_array($permission, $_SESSION['_permissions'], true);
    }

    // ================================================================
    //  HELPERS DE RÔLE (accès rapide aux informations de session)
    // ================================================================

    /** @return bool Vrai si l'utilisateur est connecté (clé 'user' présente en session) */
    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user']);
    }

    /** @return bool Vrai si l'utilisateur connecté a le rôle 'admin' */
    public static function isAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /** @return bool Vrai si l'utilisateur connecté a le rôle 'sub_admin' (admin partiel) */
    public static function isSubAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'sub_admin';
    }

    /**
     * Retourne les données de l'utilisateur actuellement connecté.
     * @return array|null  Tableau ['id', 'username', 'email', 'role'] ou null si non connecté
     */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    // ================================================================
    //  LOGIN / LOGOUT
    // ================================================================

    /**
     * Tente d'authentifier un utilisateur avec ses identifiants.
     *
     * Étapes :
     *  1. Cherche l'utilisateur en BDD par username OU email
     *  2. Vérifie que le compte est actif
     *  3. Vérifie le mot de passe avec password_verify() (bcrypt)
     *  4. En cas de succès : régénère l'ID de session et stocke l'utilisateur
     *  5. Logue la tentative (succès ou échec) dans app_logs
     *
     * @param string $username  Username ou email de l'utilisateur
     * @param string $password  Mot de passe en clair (sera vérifié contre le hash)
     * @return bool             true si la connexion est réussie, false sinon
     */
    public static function login(string $username, string $password): bool
    {
        $db   = Database::get();
        // Requête : cherche par username OU email (l'utilisateur peut utiliser les deux)
        $stmt = $db->prepare("
            SELECT id, username, email, password_hash, role, is_active
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        // Récupère l'IP du visiteur (utile pour les logs de sécurité)
        $ip = self::getIp();

        // ── Cas 1 : utilisateur introuvable ──
        if (!$user) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'user_not_found',
                'username' => $username,
            ], null, $ip);
            return false; // échec silencieux (ne pas révéler si le compte existe)
        }

        // ── Cas 2 : compte désactivé par l'administrateur ──
        if (!$user['is_active']) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'account_inactive',
                'username' => $username,
            ], $user['id'], $ip);
            return false;
        }

        // ── Cas 3 : mauvais mot de passe ──
        // password_verify() compare le mot de passe en clair avec le hash bcrypt stocké en BDD.
        // Ne PAS comparer les mots de passe directement (jamais de $password === $user['password'] !)
        if (!password_verify($password, $user['password_hash'])) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'wrong_password',
                'username' => $username,
            ], $user['id'], $ip);
            return false;
        }

        // ── Cas 4 : authentification réussie ──
        // Sécurité : régénère l'identifiant de session pour éviter les attaques
        // de "fixation de session" (session fixation attack)
        session_regenerate_id(true);

        // Stocke les infos de l'utilisateur en session (disponibles dans toute l'appli)
        $_SESSION['user'] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ];
        // Initialise l'horodatage d'inactivité
        $_SESSION['_last_activity'] = time();
        // Efface le cache des permissions pour le recharger au prochain appel de can()
        unset($_SESSION['_permissions']);

        // Met à jour la date de dernière connexion dans la BDD
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
           ->execute([$user['id']]);

        // Enregistre le succès dans les logs d'application
        Logger::info('auth', 'login_success', [
            'username' => $user['username'],
            'role'     => $user['role'],
        ], $user['id'], $ip);

        return true;
    }

    /**
     * Déconnecte l'utilisateur courant.
     * Enregistre la déconnexion dans les logs, vide la session et supprime le cookie.
     */
    public static function logout(): void
    {
        // Logue la déconnexion avant de vider la session (sinon on perd le nom d'utilisateur)
        $user = self::user();
        if ($user) {
            Logger::info('auth', 'logout', [
                'username' => $user['username'],
            ], $user['id'], self::getIp());
        }

        // Vide le tableau $_SESSION (supprime toutes les données de session)
        $_SESSION = [];

        // Supprime le cookie de session du navigateur
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            // Expire le cookie dans le passé (time() - 42000) pour forcer sa suppression
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        // Supprime le fichier de session côté serveur
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ================================================================
    //  HELPERS PRIVÉS (usage interne uniquement)
    // ================================================================

    /**
     * Détecte si la requête HTTP courante est une requête API (AJAX/fetch).
     * Utile pour envoyer du JSON au lieu d'une redirection HTML.
     *
     * Une requête est considérée comme API si :
     *  - Header X-Requested-With: XMLHttpRequest (envoyé par jQuery, axios)
     *  - Header Accept contient 'application/json' (fetch() moderne)
     *  - L'URL commence par /api/ (routes API explicites)
     *
     * @return bool
     */
    private static function isApiRequest(): bool
    {
        // Vérifie le header standard Ajax (jQuery, axios)
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            return true;
        }
        // Vérifie si le client accepte du JSON (fetch() natif)
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        // Vérifie si l'URL est une route API
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_starts_with($uri, '/api/')) {
            return true;
        }
        return false;
    }

    /**
     * Charge les permissions d'un sous-admin depuis la base de données
     * et les stocke en session pour éviter les requêtes répétées.
     *
     * @param int $userId  ID de l'utilisateur
     */
    private static function loadPermissions(int $userId): void
    {
        // Récupère toutes les permissions de l'utilisateur (liste de chaînes)
        $stmt = Database::get()->prepare(
            'SELECT permission FROM user_permissions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        // FETCH_COLUMN = retourne directement un tableau de valeurs (pas de tableaux imbriques)
        // Ex : ['import.photos', 'manage.classes']
        $_SESSION['_permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Récupère l'adresse IP réelle du visiteur.
     * Gère le cas d'un proxy ou révérse-proxy (ex: Nginx, Cloudflare)
     * qui transmet l'IP originale via l'en-tête X-Forwarded-For.
     *
     * @return string  Adresse IP ou 'unknown' si non déterminable
     */
    private static function getIp(): string
    {
        // En cas de proxy, l'IP réelle est dans HTTP_X_FORWARDED_FOR
        // REMOTE_ADDR est l'IP du proxy (pas du client)
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }
}
