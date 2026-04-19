<?php $pageTitle = 'Erreur serveur'; ob_start(); ?>
<div class="empty-state">
  <h1 style="font-size:4rem;opacity:.2">500</h1>
  <h3>Erreur serveur</h3>
  <p>Une erreur inattendue s'est produite.</p>
  <a href="/" class="btn btn-primary">Retour à l'accueil</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/layouts/app.php'; ?>
