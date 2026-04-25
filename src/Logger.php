<?php
// src/Logger.php
// ============================================================
//  Service de logs applicatifs
//  Usage :
//    Logger::info('import', 'photos_ok', ['extracted' => 27, 'unknown' => 3])
//    Logger::warning('import', 'photo_skipped', ['reason' => 'no_text', 'page' => 5])
//    Logger::error('system', 'db_error', ['message' => $e->getMessage()])
//    Logger::critical('system', 'uncaught_exception', ['trace' => ...])
//
//  Le user_id et l'IP sont récupérés automatiquement depuis Auth si disponible.
//  On peut les passer manuellement (utile avant qu'Auth soit initialisé).
// ============================================================
class Logger
{
    // ── Méthodes publiques ────────────────────────────────────

    public static function info(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('info', $category, $action, $details, $userId, $ip);
    }

    public static function warning(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('warning', $category, $action, $details, $userId, $ip);
    }

    public static function error(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('error', $category, $action, $details, $userId, $ip);
    }

    public static function critical(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('critical', $category, $action, $details, $userId, $ip);
    }

    // ── Cœur ──────────────────────────────────────────────────

    private static function write(
        string  $level,
        string  $category,
        string  $action,
        array   $details,
        ?int    $userId,
        ?string $ip
    ): void {
        // Récupère automatiquement user_id et IP si non fournis
        if ($userId === null && class_exists('Auth') && Auth::isLoggedIn()) {
            $userId = Auth::user()['id'] ?? null;
        }
        if ($ip === null) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR']
                ?? null;
        }

        try {
            $db   = Database::get();
            $stmt = $db->prepare("
                INSERT INTO app_logs (user_id, level, category, action, details, ip)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $level,
                $category,
                $action,
                !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $ip,
            ]);
        } catch (Throwable $e) {
            // Le logger ne doit JAMAIS faire planter l'appli.
            // Si la BDD est down, on écrit en fallback dans error_log PHP.
            error_log(sprintf(
                '[ProClasse][%s][%s] %s | %s | details: %s | err: %s',
                strtoupper($level),
                $category,
                $action,
                $ip ?? '-',
                json_encode($details),
                $e->getMessage()
            ));
        }
    }
}
