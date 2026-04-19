<?php $pageTitle = 'Page introuvable'; ob_start(); ?>
<div class="empty-state">
  <h1 style="font-size:4rem;opacity:.2">404</h1>
  <h3>Page introuvable</h3>
  <a href="/" class="btn btn-primary">Retour à l'accueil</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/layouts/app.php'; ?>
