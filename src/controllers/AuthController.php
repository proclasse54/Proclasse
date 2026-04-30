<?php
// src/controllers/AuthController.php
// ============================================================
//  Contrôleur d'authentification
//  Gère les routes :
//    GET  /login    → loginForm()    Affiche le formulaire de connexion
//    POST /login    → loginSubmit()  Traite le formulaire de connexion
//    GET  /logout   → logout()       Déconnecte l'utilisateur
//    GET|POST /install → install()   Crée le premier compte admin (usage unique)
// ============================================================
class AuthController
{
    // ================================================================
    //  GET /login  — Affiche le formulaire de connexion
    // ================================================================
    public function loginForm(): void
    {
        // Si l'utilisateur est déjà connecté, inutile de lui montrer le login
        // → on le redirige directement vers la page d'accueil
        if (Auth::user()) {
            Response::redirect('/');
        }

        // Récupère le message d'erreur stocké en session (par loginSubmit() en cas d'échec)
        // puis le supprime pour qu'il n'apparaisse qu'une seule fois
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        // Affiche la vue du formulaire de connexion
        // $error est disponible dans la vue pour afficher l'alerte d'erreur
        require ROOT . '/views/auth/login.php';
    }

    // ================================================================
    //  POST /login  — Traite la soumission du formulaire
    // ================================================================
    public function loginSubmit(): void
    {
        // Récupère et nettoie les champs du formulaire
        // trim() supprime les espaces inutiles en début/fin de chaîne
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        // ── Validation basique : les deux champs sont obligatoires ──
        if ($email === '' || $password === '') {
            // Stocke l'erreur en session pour l'afficher après la redirection
            $_SESSION['login_error'] = 'Veuillez remplir tous les champs.';
            Response::redirect('/login'); // redirect vers GET /login
            return;
        }

        // ── Authentification via le service Auth ──
        // Auth::login() vérifie les identifiants en BDD, hache/compare le mot de passe,
        // ouvre la session en cas de succès, et enregistre tout dans les logs
        $ok = Auth::login($email, $password);

        if (!$ok) {
            // Identifiants incorrects (utilisateur inexistant ou mauvais mot de passe)
            $_SESSION['login_error'] = 'Email ou mot de passe incorrect.';
            Response::redirect('/login');
            return;
        }

        // ── Connexion réussie : redirection vers la page initialement demandée ──
        // Si l'utilisateur avait été redirigé vers /login depuis une page protégée,
        // Auth::check() a mémorisé l'URL dans $_SESSION['_redirect_after_login']
        $redirect = $_SESSION['_redirect_after_login'] ?? '/'; // '/' = accueil par défaut
        unset($_SESSION['_redirect_after_login']); // nettoie la clé de session
        Response::redirect($redirect);
    }

    // ================================================================
    //  GET /logout  — Déconnexion
    // ================================================================
    public function logout(): void
    {
        // Appelle Auth::logout() qui vide la session et supprime le cookie
        Auth::logout();
        // Redirige vers la page de connexion
        Response::redirect('/login');
    }

    // ================================================================
    //  GET|POST /install  — Création du premier compte administrateur
    //  ⚠️ À bloquer (.htaccess ou suppression) après l'installation initiale !
    // ================================================================
    public function install(): void
    {
        $pdo = Database::get();

        // ── Sécurité : vérifie que la table users existe ──
        try {
            // COUNT(*) retourne le nombre d'utilisateurs existants
            $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (\Throwable $e) {
            // La table n'existe pas encore → migration SQL non exécutée
            $this->renderInstall('error', 'La table <code>users</code> n\'existe pas encore. Lancez d\'abord la migration SQL.');
            return;
        }

        // ── Sécurité : empêche de recréer un admin si l'un existe déjà ──
        if ((int) $count > 0) {
            $this->renderInstall('already', 'Un compte administrateur existe déjà. Cette page est désactivée.');
            return;
        }

        // Variables pour afficher le résultat dans la vue
        $message = null;
        $type    = null;

        // ── Traitement du formulaire d'installation (soumis en POST) ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email    = trim($_POST['email']    ?? '');
            $password = trim($_POST['password'] ?? '');
            $confirm  = trim($_POST['confirm']  ?? ''); // confirmation du mot de passe

            // Validation des champs un par un
            if ($email === '' || $password === '' || $confirm === '') {
                $message = 'Tous les champs sont obligatoires.';
                $type    = 'error';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // FILTER_VALIDATE_EMAIL vérifie que l'email a le bon format (xxx@yyy.zzz)
                $message = 'Adresse email invalide.';
                $type    = 'error';
            } elseif (strlen($password) < 8) {
                // Longueur minimale du mot de passe
                $message = 'Le mot de passe doit faire au moins 8 caractères.';
                $type    = 'error';
            } elseif ($password !== $confirm) {
                // Vérifie que les deux saisies du mot de passe correspondent
                $message = 'Les mots de passe ne correspondent pas.';
                $type    = 'error';
            } else {
                // ── Création du compte admin ──

                // Extrait le nom d'utilisateur depuis la partie locale de l'email
                // Ex : 'admin@lycee.fr' → 'admin'
                $username = explode('@', $email)[0];

                // Hache le mot de passe avec bcrypt (algorithme sûr, avec salage automatique)
                // Ne JAMAIS stocker un mot de passe en clair dans la BDD
                $hash = password_hash($password, PASSWORD_BCRYPT);

                // Insère le premier utilisateur avec le rôle 'admin'
                $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, role, is_active, created_at)
                     VALUES (?, ?, ?, \'admin\', 1, NOW())'
                )->execute([$username, $email, $hash]);

                // Enregistre l'événement dans les logs d'application
                Logger::info('install', 'admin_created', ['email' => $email]);

                $message = 'Compte admin créé ! Vous pouvez maintenant <a href="/login">vous connecter</a>.';
                $type    = 'success';
            }
        }

        // Affiche la vue d'installation avec le résultat (échec ou succès)
        $this->renderInstall($type, $message);
    }

    // ================================================================
    //  HELPER PRIVÉ
    // ================================================================

    /**
     * Charge la vue d'installation avec les variables de contexte.
     * Séparé dans une méthode pour éviter la duplication du require.
     *
     * @param string|null $type     Type d'alerte : 'error', 'success', 'already', ou null
     * @param string|null $message  Message à afficher dans la vue
     */
    private function renderInstall(?string $type, ?string $message): void
    {
        // $type et $message sont disponibles dans la vue views/auth/install.php
        require ROOT . '/views/auth/install.php';
    }
}
