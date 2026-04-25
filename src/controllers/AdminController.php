<?php
// src/controllers/AdminController.php
// ============================================================
//  Routes admin — réservé au rôle 'admin'
//  GET  /admin/users
//  POST /admin/users          → créer un compte
//  POST /admin/users/{id}     → modifier un compte
//  DELETE /admin/users/{id}   → supprimer (JSON)
//  GET  /admin/logs
//  POST /admin/logs/purge     → purger les logs
// ============================================================
class AdminController
{
    // ── GET /admin/users ──────────────────────────────────────
    public function users(): void
    {
        Auth::requireAdmin();
        $pdo   = Database::get();
        $users = $pdo->query('SELECT id, username, email, role, is_active, created_at FROM users ORDER BY created_at DESC')
                     ->fetchAll(PDO::FETCH_ASSOC);

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle = 'Utilisateurs';
        ob_start();
        require ROOT . '/views/admin/users.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    // ── POST /admin/users — créer un compte ───────────────────
    public function userCreate(): void
    {
        Auth::requireAdmin();
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = $_POST['role'] ?? 'user';

        if ($email === '' || $password === '' || strlen($password) < 8) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Email et mot de passe (8 car. min.) obligatoires.'];
            Response::redirect('/admin/users');
            return;
        }

        if (!in_array($role, ['admin', 'sub_admin', 'user'], true)) {
            $role = 'user';
        }

        $pdo      = Database::get();
        $username = explode('@', $email)[0];
        $hash     = password_hash($password, PASSWORD_BCRYPT);

        try {
            $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)'
            )->execute([$username, $email, $hash, $role]);
            Logger::info('admin', 'user_created', ['email' => $email, 'role' => $role], Auth::user()['id']);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Compte $email créé."];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Email déjà utilisé ou erreur BDD.'];
        }

        Response::redirect('/admin/users');
    }

    // ── POST /admin/users/{id} — modifier un compte ───────────
    public function userUpdate(array $params): void
    {
        Auth::requireAdmin();
        $id       = (int) ($params['id'] ?? 0);
        $email    = trim($_POST['email']    ?? '');
        $role     = $_POST['role']      ?? 'user';
        $active   = (int) ($_POST['is_active'] ?? 1);
        $password = trim($_POST['password'] ?? '');

        if (!in_array($role, ['admin', 'sub_admin', 'user'], true)) {
            $role = 'user';
        }

        $pdo = Database::get();

        if ($password !== '') {
            if (strlen($password) < 8) {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Mot de passe trop court (8 car. min.).'];
                Response::redirect('/admin/users');
                return;
            }
            $hash     = password_hash($password, PASSWORD_BCRYPT);
            $username = explode('@', $email)[0];
            $pdo->prepare(
                'UPDATE users SET username=?, email=?, role=?, is_active=?, password_hash=? WHERE id=?'
            )->execute([$username, $email, $role, $active, $hash, $id]);
        } else {
            $username = explode('@', $email)[0];
            $pdo->prepare(
                'UPDATE users SET username=?, email=?, role=?, is_active=? WHERE id=?'
            )->execute([$username, $email, $role, $active, $id]);
        }

        Logger::info('admin', 'user_updated', ['id' => $id, 'email' => $email], Auth::user()['id']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Compte mis à jour.'];
        Response::redirect('/admin/users');
    }

    // ── DELETE /admin/users/{id} — supprimer (JSON) ───────────
    public function userDelete(array $params): void
    {
        Auth::requireAdmin();
        $id      = (int) ($params['id'] ?? 0);
        $current = Auth::user()['id'];

        if ($id === $current) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Impossible de supprimer votre propre compte.']);
            return;
        }

        Database::get()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        Logger::info('admin', 'user_deleted', ['id' => $id], $current);
        echo json_encode(['ok' => true]);
    }

    // ── GET /admin/logs ───────────────────────────────────────
    public function logs(): void
    {
        Auth::requireAdmin();
        $pdo = Database::get();

        $level    = $_GET['level']    ?? '';
        $category = $_GET['category'] ?? '';
        $limit    = min((int) ($_GET['limit'] ?? 200), 500);

        $where  = [];
        $binds  = [];
        if ($level !== '')    { $where[] = 'level = ?';    $binds[] = $level; }
        if ($category !== '') { $where[] = 'category = ?'; $binds[] = $category; }
        $sql = 'SELECT l.*, u.email FROM app_logs l LEFT JOIN users u ON u.id = l.user_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY l.created_at DESC LIMIT ' . $limit;

        $logs       = $pdo->prepare($sql);
        $logs->execute($binds);
        $logs       = $logs->fetchAll(PDO::FETCH_ASSOC);

        $levels     = $pdo->query("SELECT DISTINCT level    FROM app_logs ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);
        $categories = $pdo->query("SELECT DISTINCT category FROM app_logs ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle = 'Logs';
        ob_start();
        require ROOT . '/views/admin/logs.php';
        $content = ob_get_clean();
        require ROOT . '/views/layouts/app.php';
    }

    // ── POST /admin/logs/purge ────────────────────────────────
    public function logsPurge(): void
    {
        Auth::requireAdmin();
        $before = $_POST['before'] ?? '';
        $pdo    = Database::get();

        if ($before === 'all') {
            $count = $pdo->exec('DELETE FROM app_logs');
        } elseif (in_array($before, ['7', '30', '90'], true)) {
            $stmt  = $pdo->prepare('DELETE FROM app_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
            $stmt->execute([$before]);
            $count = $stmt->rowCount();
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Paramètre de purge invalide.'];
            Response::redirect('/admin/logs');
            return;
        }

        Logger::info('admin', 'logs_purged', ['deleted' => $count, 'before' => $before], Auth::user()['id']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "$count entrée(s) supprimée(s)."];
        Response::redirect('/admin/logs');
    }
}
