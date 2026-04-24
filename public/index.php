<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));


require ROOT . '/src/Photos.php';


// ── Config ────────────────────────────────────────────────────
$appCfg = require ROOT . '/config/app.php';
date_default_timezone_set($appCfg['timezone'] ?? 'Europe/Paris');

// ── Autoload ──────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT . '/src/' . $class . '.php',
        ROOT . '/src/controllers/' . $class . '.php',
        ROOT . '/src/models/' . $class . '.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) { require_once $path; return; }
    }
});

// ── Erreurs ───────────────────────────────────────────────────
if ($appCfg['debug'] ?? false) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e): void {
        http_response_code(500);
        require ROOT . '/views/500.php';
    });
}

// ── Router ────────────────────────────────────────────────────
$router = new Router();

// Salles
$router->add('GET',    '/rooms',                fn()  => (new RoomController)->index());
$router->add('GET',    '/rooms/create',         fn()  => (new RoomController)->create());
$router->add('GET',    '/rooms/{id}/edit',      fn($p)=> (new RoomController)->edit($p));
$router->add('POST',   '/api/rooms',            fn()  => (new RoomController)->apiSave([]));
$router->add('POST',   '/api/rooms/{id}',       fn($p)=> (new RoomController)->apiSave($p));
$router->add('GET',    '/api/rooms/{id}',       fn($p)=> (new RoomController)->apiGet($p));
$router->add('DELETE', '/api/rooms/{id}',       fn($p)=> (new RoomController)->apiDelete($p));

// Classes
$router->add('GET',    '/classes',                          fn()  => (new ClassController)->index());
$router->add('GET',    '/classes/{id}',                     fn($p)=> (new ClassController)->show($p));
$router->add('POST',   '/api/classes',                      fn()  => (new ClassController)->apiSaveClass([]));
$router->add('DELETE', '/api/classes',                      fn()  => (new ClassController)->apiDeleteAllClasses());
$router->add('POST',   '/api/classes/{id}',                 fn($p)=> (new ClassController)->apiSaveClass($p));
$router->add('DELETE', '/api/classes/{id}',                 fn($p)=> (new ClassController)->apiDeleteClass($p));
$router->add('POST',   '/api/classes/{id}/import-paste',    fn($p)=> (new ClassController)->apiImportPaste($p));
$router->add('POST',   '/api/classes/{id}/import',          fn($p)=> (new ClassController)->apiImportStudents($p));
$router->add('GET',    '/api/classes/{id}/students',        fn($p)=> (new ClassController)->apiGetStudents($p));
$router->add('POST',   '/api/classes/{id}/plans',           fn($p)=> (new ClassController)->apiSavePlan($p));
// Plans
$router->add('GET',    '/plans/{plan_id}/edit',             fn($p)=> (new ClassController)->planEdit($p));
$router->add('GET',    '/api/plans/{plan_id}',              fn($p)=> (new ClassController)->apiGetPlan($p));
$router->add('POST',   '/api/plans/{plan_id}/assignments',  fn($p)=> (new ClassController)->apiSaveAssignments($p));
$router->add('DELETE', '/api/plans/{plan_id}',              fn($p)=> (new ClassController)->apiDeletePlan($p));

// Séances
$router->add('GET',    '/sessions',                         fn()  => (new SessionController)->index());
$router->add('GET',    '/sessions/{id}/live',               fn($p)=> (new SessionController)->live($p));
$router->add('POST',   '/api/sessions',                     fn()  => (new SessionController)->apiCreate());
$router->add('POST',   '/api/sessions/import-ics',          fn()  => (new IcsImportController)->apiImportIcs());
$router->add('DELETE', '/api/sessions/{id}',                fn($p)=> (new SessionController)->apiDelete($p));
$router->add('GET',    '/api/sessions/{id}/observations',   fn($p)=> (new SessionController)->apiGetObservations($p));
$router->add('POST',   '/api/sessions/{id}/observations',   fn($p)=> (new SessionController)->apiAddObservation($p));
$router->add('DELETE', '/api/sessions/{id}/observations/{obs_id}',  fn($p)=> (new SessionController)->apiRemoveObservation($p));
$router->add('GET',    '/api/tags',                         fn()  => (new SessionController)->apiGetTags());
$router->add('POST',   '/api/tags',                         fn()  => (new SessionController)->apiSaveTag());
$router->add('DELETE', '/api/tags/{id}',                    fn($p)=> (new SessionController)->apiDeleteTag($p));

// Racine
$router->add('GET',     '/',                                fn()  => Response::redirect('/sessions'));

// Tags
$router->add('GET',    '/tags',                             fn()  => (new SessionController)->tagsIndex());

// ── Dispatch ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$router->dispatch($method, $uri);
