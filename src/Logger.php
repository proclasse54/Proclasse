<?php
// src/Logger.php
// ============================================================
//  Service de logs applicatifs — toutes les méthodes sont statiques.
//
//  Niveaux disponibles (du moins grave au plus grave) :
//    info     → événement normal (connexion réussie, import terminé…)
//    warning  → anomalie non bloquante (tentative de connexion échouée…)
//    error    → erreur récupérée (ex: exception catchée)
//    critical → erreur grave, intervention requise
//
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

    /**
     * Enregistre un événement de niveau INFO.
     * À utiliser pour les actions normales et attendues (connexion réussie, import OK…).
     *
     * @param string   $category  Catégorie fonctionnelle : 'auth', 'import', 'system'…
     * @param string   $action    Action précise       : 'login_success', 'photos_ok'…
     * @param array    $details   Données contextuelles libres (sérialisées en JSON en BDD)
     * @param int|null $userId    ID utilisateur (détecté automatiquement depuis Auth si null)
     * @param string|null $ip     Adresse IP (détectée automatiquement si null)
     */
    public static function info(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('info', $category, $action, $details, $userId, $ip);
    }

    /**
     * Enregistre un événement de niveau WARNING.
     * À utiliser pour les anomalies non bloquantes (mauvais mot de passe, fichier ignoré…).
     *
     * @param string   $category  Catégorie fonctionnelle
     * @param string   $action    Action précise
     * @param array    $details   Données contextuelles libres
     * @param int|null $userId    ID utilisateur (auto-détecté si null)
     * @param string|null $ip     Adresse IP (auto-détectée si null)
     */
    public static function warning(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('warning', $category, $action, $details, $userId, $ip);
    }

    /**
     * Enregistre un événement de niveau ERROR.
     * À utiliser pour les erreurs récupérées qui impactent une fonctionnalité
     * sans faire crasher l'application (ex: exception catchée dans un contrôleur).
     *
     * @param string   $category  Catégorie fonctionnelle
     * @param string   $action    Action précise
     * @param array    $details   Données contextuelles (message d'exception, trace…)
     * @param int|null $userId    ID utilisateur (auto-détecté si null)
     * @param string|null $ip     Adresse IP (auto-détectée si null)
     */
    public static function error(
        string $category,
        string $action,
        array  $details  = [],
        ?int   $userId   = null,
        ?string $ip      = null
    ): void {
        self::write('error', $category, $action, $details, $userId, $ip);
    }

    /**
     * Enregistre un événement de niveau CRITICAL.
     * À utiliser pour les erreurs graves nécessitant une intervention
     * (exception non catchée, base de données inaccessible…).
     *
     * @param string   $category  Catégorie fonctionnelle
     * @param string   $action    Action précise
     * @param array    $details   Données contextuelles (trace complète recommandée)
     * @param int|null $userId    ID utilisateur (auto-détecté si null)
     * @param string|null $ip     Adresse IP (auto-détectée si null)
     */
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

    /**
     * Méthode interne qui effectue l'écriture réelle du log en base de données.
     *
     * Comportement :
     *  1. Si user_id non fourni, tente de le récupérer depuis Auth (si initialisé)
     *  2. Si IP non fournie, lit HTTP_X_FORWARDED_FOR ou REMOTE_ADDR
     *  3. Insère la ligne dans la table app_logs
     *  4. En cas d'échec BDD (ex: MariaDB down), bascule sur error_log PHP
     *     → le logger ne doit JAMAIS faire planter l'application principale
     *
     * @param string      $level     Niveau : 'info', 'warning', 'error', 'critical'
     * @param string      $category  Catégorie fonctionnelle
     * @param string      $action    Action précise
     * @param array       $details   Données contextuelles (encodées en JSON)
     * @param int|null    $userId    ID de l'utilisateur concerné
     * @param string|null $ip        Adresse IP de l'auteur de l'action
     */
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
            // En cas de reverse-proxy, l'IP réelle est dans X-Forwarded-For
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
                // Sérialise les détails en JSON uniquement s'il y en a
                // JSON_UNESCAPED_UNICODE = conserve les accents lisibles (pas de \uXXXX)
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
