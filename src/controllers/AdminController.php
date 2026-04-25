<?php
// src/controllers/AdminController.php
// ============================================================
//  Gestion des utilisateurs — réservé au rôle admin
//  Routes :
//    GET  /admin/users          → liste
//    POST /admin/users          → créer
//    POST /admin/users/{id}     → modifier (email, role, actif, mdp)
//    DELETE /admin/users/{id}   → supprimer
// ============================================================
class AdminController
{
    // ── Middleware rôle ────────────────────────────────────────
    private function requireAdmin(): void
    {
        if (Auth::role() !== 'admin') {
            http_response_code(403);
            exit('Accès interdit.');
        }
    }

    // ── GET /admin/users ───────────────────────────────────────
    public function users(): void
    {
        $this->requireAdmin();
        $pdo   = Database::get();
        $users = $pdo->query(
            'SELECT id, email, role, is_active, created_at FROM users ORDER BY created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        ob_start();
        require ROOT . '/views/admin/users.php';
        $content = ob_get_clean();
        $pageTitle = 'Utilisateurs — ProClasse';
        require ROOT . '/views/layouts/app.php';
    }

    // ── POST /admin/users  (créer) ─────────────────────────────
    public function createUser(): void
    {
        $this->requireAdmin();
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';

        if ($email === '' || $password === '') {
            $this->flash('error', 'Email et mot de passe obligatoires.');
            Response::redirect('/admin/users');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Adresse email invalide.');
            Response::redirect('/admin/users');
        }
        if (strlen($password) < 8) {
            $this->flash('error', 'Mot de passe trop court (8 caractères min.).');
            Response::redirect('/admin/users');
        }

        $pdo  = Database::get();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $pdo->prepare(
                'INSERT INTO users (email, password_hash, role, is_active, created_at)
                 VALUES (?, ?, ?, 1, NOW())'
            )->execute([$email, $hash, $role]);
            Logger::info('admin', 'user_created', ['email' => $email, 'role' => $role, 'by' => Auth::user()]);
            $this->flash('success', "Compte <strong>{$email}</strong> créé.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Cet email est déjà utilisé.');
        }
        Response::redirect('/admin/users');
    }

    // ── POST /admin/users/{id}  (modifier) ────────────────────
    public function updateUser(array $params): void
    {
        $this->requireAdmin();
        $id   = (int) ($params['id'] ?? 0);
        $pdo  = Database::get();

        // On ne peut pas se désactiver soi-même
        if ($id === Auth::user() && isset($_POST['is_active']) && (int)$_POST['is_active'] === 0) {
            $this->flash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
            Response::redirect('/admin/users');
        }

        $fields = [];
        $vals   = [];

        if (!empty($_POST['email'])) {
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $this->flash('error', 'Email invalide.');
                Response::redirect('/admin/users');
            }
            $fields[] = 'email = ?';
            $vals[]   = trim($_POST['email']);
        }
        if (in_array($_POST['role'] ?? '', ['admin', 'user'])) {
            // On ne peut pas se retirer le rôle admin
            if ($id === Auth::user() && $_POST['role'] !== 'admin') {
                $this->flash('error', 'Vous ne pouvez pas modifier votre propre rôle.');
                Response::redirect('/admin/users');
            }
            $fields[] = 'role = ?';
            $vals[]   = $_POST['role'];
        }
        if (isset($_POST['is_active'])) {
            $fields[] = 'is_active = ?';
            $vals[]   = (int)(bool)$_POST['is_active'];
        }
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) {
                $this->flash('error', 'Nouveau mot de passe trop court.');
                Response::redirect('/admin/users');
            }
            $fields[] = 'password_hash = ?';
            $vals[]   = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }

        if ($fields) {
            $vals[] = $id;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
                ->execute($vals);
            Logger::info('admin', 'user_updated', ['id' => $id, 'by' => Auth::user()]);
            $this->flash('success', 'Compte mis à jour.');
        }
        Response::redirect('/admin/users');
    }

    // ── DELETE /admin/users/{id}  (supprimer) ──────────────────
    public function deleteUser(array $params): void
    {
        $this->requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        if ($id === Auth::user()) {
            http_response_code(400);
            echo json_encode(['error' => 'Impossible de supprimer votre propre compte.']);
            return;
        }

        Database::get()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        Logger::info('admin', 'user_deleted', ['id' => $id, 'by' => Auth::user()]);
        echo json_encode(['ok' => true]);
    }

    // ── Helper flash ───────────────────────────────────────────
    private function flash(string $type, string $msg): void
    {
        $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $msg];
    }
}
