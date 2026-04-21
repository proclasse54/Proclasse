<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'ProClasse') ?></title>
<link rel="stylesheet" href="/css/app.css">
</head>
<body>
<nav class="sidebar">
  <div class="sidebar-logo">
    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-label="ProClasse">
      <rect x="2" y="2" width="11" height="11" rx="2" fill="currentColor" opacity=".9"/>
      <rect x="15" y="2" width="11" height="11" rx="2" fill="currentColor" opacity=".6"/>
      <rect x="2" y="15" width="11" height="11" rx="2" fill="currentColor" opacity=".6"/>
      <rect x="15" y="15" width="11" height="11" rx="2" fill="currentColor" opacity=".3"/>
    </svg>
    <span>ProClasse</span>
  </div>
  <ul class="sidebar-nav">
    <li><a href="/sessions" <?= str_starts_with($_SERVER['REQUEST_URI'], '/sessions') ? 'class="active"' : '' ?>>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Séances
    </a></li>
    <li><a href="/classes" <?= (str_starts_with($_SERVER['REQUEST_URI'], '/classes') || str_starts_with($_SERVER['REQUEST_URI'], '/plans')) ? 'class="active"' : '' ?>>      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Classes
    </a></li>
    <li><a href="/rooms" <?= str_starts_with($_SERVER['REQUEST_URI'], '/rooms') ? 'class="active"' : '' ?>>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Salles
    </a></li>
    <li><a href="/tags" <?= str_starts_with($_SERVER['REQUEST_URI'], '/tags') ? 'class="active"' : '' ?>>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
        <line x1="7" y1="7" x2="7.01" y2="7"/>
      </svg>
      Tags
    </a></li>
  </ul>
  <button class="theme-toggle" data-theme-toggle aria-label="Changer le thème">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
  </button>
</nav>
<main class="main-content">
  <?= $content ?? '' ?>
</main>
<script src="/js/app.js"></script>
</body>
</html>
