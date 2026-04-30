<?php
// src/Router.php
// ============================================================
//  Classe Router — Routeur HTTP léger
//  Permet d'associer des routes (méthode + chemin URL) à des
//  fonctions PHP (handlers/contrôleurs).
//
//  Usage dans index.php :
//    $router = new Router();
//    $router->add('GET',  '/classes',        fn() => ...);
//    $router->add('POST', '/classes/{id}',   fn($p) => ...);
//    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
// ============================================================

class Router {

    /**
     * Tableau contenant toutes les routes enregistrées.
     * Chaque entrée est un tableau ['method', 'path', 'handler'].
     */
    private array $routes = [];

    /**
     * Enregistre une nouvelle route.
     *
     * @param string   $method   Méthode HTTP : 'GET', 'POST', 'DELETE', etc.
     * @param string   $path     Chemin URL avec paramètres optionnels : '/classes/{id}'
     *                           Les segments {param} seront capturés et passés au handler.
     * @param callable $handler  Fonction (ou méthode) à appeler quand la route correspond.
     *                           Reçoit un tableau $params avec les paramètres d'URL capturés.
     *
     * Exemple :
     *   $router->add('GET', '/classes/{id}', fn($p) => (new ClassController)->show($p));
     *   // Lorsque /classes/42 est demandée, $p['id'] === '42'
     */
    public function add(string $method, string $path, callable $handler): void {
        // Stocke la route sous forme de tableau compact (method + path + handler)
        $this->routes[] = compact('method', 'path', 'handler');
    }

    /**
     * Parcourt les routes enregistrées et appelle le handler de la première qui correspond.
     * Si aucune route ne correspond, renvoie une page 404.
     *
     * @param string $method  Méthode HTTP reçue ('GET', 'POST', 'HEAD'…)
     * @param string $uri     URI demandée telle que $_SERVER['REQUEST_URI']
     */
    public function dispatch(string $method, string $uri): void {

        // Supprime la query string de l'URI pour ne comparer que le chemin
        // Ex : '/classes?page=2' → '/classes'
        $uri = strtok($uri, '?');

        // HTTP HEAD est identique à GET mais sans corps de réponse.
        // On le traite comme GET mais on capture et jette la sortie à la fin.
        $isHead = ($method === 'HEAD');
        if ($isHead) $method = 'GET';

        // Parcourt toutes les routes enregistrées
        foreach ($this->routes as $route) {

            // Convertit le path de la route en expression régulière.
            // Ex : '/classes/{id}' devient '#^/classes/(?P<id>[^/]+)$#'
            // (?P<name>...) = groupe nommé → accessible via $m['id'] après preg_match
            $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']) . '$#';

            // Vérifie que la méthode HTTP correspond ET que l'URI matche le pattern
            if ($route['method'] === $method && preg_match($pattern, $uri, $m)) {

                // Extrait uniquement les clés nommées (string) du résultat de preg_match.
                // preg_match retourne aussi des clés numériques (0, 1, 2…) qu'on ne veut pas.
                // ARRAY_FILTER_USE_KEY = filtre sur la clé, pas la valeur
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                if (!$isHead) {
                    // Appel normal : exécute le handler en passant les paramètres d'URL
                    ($route['handler'])($params);
                } else {
                    // Pour HEAD : on exécute quand même le handler pour générer les en-têtes HTTP,
                    // mais on capture la sortie (body) dans un buffer et on la jette.
                    ob_start();            // démarre la capture de sortie
                    ($route['handler'])($params);
                    ob_end_clean();        // vide le buffer sans l'envoyer
                }

                // Route trouvée et traitée, on arrête la recherche
                return;
            }
        }

        // Aucune route ne correspond → erreur 404 Not Found
        http_response_code(404);
        require __DIR__ . '/../views/404.php';
    }

}
