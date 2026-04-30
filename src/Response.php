<?php
// src/Response.php
// ============================================================
//  Classe Response — Helpers pour les réponses HTTP
//  Simplifie l'envoi de réponses JSON et les redirections.
//  Usage :
//    Response::json(['ok' => true])          → 200 OK avec JSON
//    Response::json(['error' => 'ko'], 400)  → 400 Bad Request JSON
//    Response::redirect('/login')            → redirection HTTP 302
// ============================================================

class Response {

    /**
     * Envoie une réponse JSON et arrête l'exécution du script.
     *
     * @param mixed $data  Les données à sérialiser en JSON (tableau, objet, scalaire…)
     * @param int   $code  Code HTTP à retourner (200 par défaut)
     *
     * Exemples :
     *   Response::json(['ok' => true]);               // 200
     *   Response::json(['error' => 'Non trouvé'], 404); // 404
     */
    public static function json(mixed $data, int $code = 200): void {
        // Définit le code de statut HTTP (200, 400, 404, 500…)
        http_response_code($code);

        // Indique au navigateur/client que la réponse est du JSON encodé en UTF-8
        header('Content-Type: application/json; charset=utf-8');

        // Sérialise le tableau PHP en chaîne JSON
        // JSON_UNESCAPED_UNICODE = conserve les accents lisibles au lieu de \uXXXX
        echo json_encode($data, JSON_UNESCAPED_UNICODE);

        // Stoppe l'exécution pour qu'aucun autre code PHP n'envoie du contenu après
        exit;
    }

    /**
     * Redirige le navigateur vers une autre URL (redirection HTTP 302).
     *
     * @param string $url  URL cible (ex: '/login', '/classes')
     *
     * Note : PHP continue l'exécution après header(), c'est pourquoi on appelle exit.
     * Sans exit, le reste du script s'exécuterait même si le navigateur est redirigé.
     */
    public static function redirect(string $url): void {
        // Envoie l'en-tête HTTP Location pour déclencher la redirection côté navigateur
        header("Location: $url");

        // Stoppe l'exécution immédiatement après l'envoi de l'en-tête
        exit;
    }
}
