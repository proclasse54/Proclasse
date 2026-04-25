<?php
// src/Auth.php
// ============================================================
//  Service d'authentification
//  Usage :
//    Auth::start()          → à appeler en tête de index.php
//    Auth::check()          → redirige vers /login si non connecté
//    Auth::requireAdmin()   → redirige si pas admin
//    Auth::can('import.photos') → true/false
//    Auth::user()           → tableau de l'utilisateur courant ou null
//    Auth::login($user)     → ouvre la session après vérification mot de passe
//    Auth::logout()         → détruit la session
// ============================================================
class Auth
{
    // Durée de vie de la session inactive : 2 heures
    private const SESSION_LIFETIME = 7200;

    // ── Initialisation ────────────────────────────────────────
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,           // cookie de session (expire à la fermeture du navigateur)
                'path'     => '/',
                'secure'   => false,       // passer à true si HTTPS
                'httponly' => true,        // inaccessible en JS
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        // Expiration par inactivité
        if (isset($_SESSION['_last_activity'])) {
            if (time() - $_SESSION['_last_activity'] > self::SESSION_LIFETIME) {
                self::logout();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    // ── Vérifications d'accès ─────────────────────────────────

    /**
     * Redirige vers /login si l'utilisateur n'est pas connecté.
     * À appeler en tête de chaque route protégée.
     */
    public static function check(): void
    {
        if (!self::isLoggedIn()) {
            // Mémorise l'URL demandée pour rediriger après login
            $_SESSION['_redirect_after_login'] = $_SERVER['REQUEST_URI'];
            Response::redirect('/login');
            exit;
        }
    }

    /**
     * Redirige avec 403 si l'utilisateur n'est pas admin.
     */
    public static function requireAdmin(): void
    {
        self::check();
        if (!self::isAdmin()) {
            http_response_code(403);
            require ROOT . '/views/403.php';
            exit;
        }
    }

    /**
     * Vérifie si l'utilisateur courant possède une permission.
     * Un admin a TOUT. Un user simple n'a rien d'admin.
     * Un sub_admin a les permissions listées dans user_permissions.
     */
    public static function can(string $permission): bool
    {
        if (!self::isLoggedIn()) return false;
        if (self::isAdmin()) return true;

        $user = self::user();
        if ($user['role'] !== 'sub_admin') return false;

        // Les permissions sont chargées une fois en session
        if (!isset($_SESSION['_permissions'])) {
            self::loadPermissions($user['id']);
        }

        return in_array($permission, $_SESSION['_permissions'], true);
    }

    // ── Helpers de rôle ───────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user']);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    public static function isSubAdmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'sub_admin';
    }

    /**
     * Retourne le tableau de l'utilisateur connecté, ou null.
     * Contient : id, username, email, role, is_active
     */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    // ── Login / Logout ────────────────────────────────────────

    /**
     * Tente de connecter un utilisateur.
     * Retourne true si OK, false si identifiants invalides.
     * Logue la tentative dans app_logs.
     */
    public static function login(string $username, string $password): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare("
            SELECT id, username, email, password_hash, role, is_active
            FROM users
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        $ip = self::getIp();

        // Utilisateur introuvable
        if (!$user) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'user_not_found',
                'username' => $username,
            ], null, $ip);
            return false;
        }

        // Compte désactivé
        if (!$user['is_active']) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'account_inactive',
                'username' => $username,
            ], $user['id'], $ip);
            return false;
        }

        // Mauvais mot de passe
        if (!password_verify($password, $user['password_hash'])) {
            Logger::warning('auth', 'login_failed', [
                'reason'   => 'wrong_password',
                'username' => $username,
            ], $user['id'], $ip);
            return false;
        }

        // ── Succès ────────────────────────────────────────────
        // Regénère l'ID de session pour éviter la fixation de session
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ];
        $_SESSION['_last_activity'] = time();
        unset($_SESSION['_permissions']); // sera rechargé si besoin

        // Mise à jour last_login_at
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
           ->execute([$user['id']]);

        Logger::info('auth', 'login_success', [
            'username' => $user['username'],
            'role'     => $user['role'],
        ], $user['id'], $ip);

        return true;
    }

    /**
     * Déconnecte l'utilisateur courant.
     */
    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Logger::info('auth', 'logout', [
                'username' => $user['username'],
            ], $user['id'], self::getIp());
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ── Helpers privés ────────────────────────────────────────

    private static function loadPermissions(int $userId): void
    {
        $stmt = Database::get()->prepare(
            'SELECT permission FROM user_permissions WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $_SESSION['_permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function getIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }
}
