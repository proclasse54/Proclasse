<?php
$pageTitle = 'Modifier ' . ($room['name'] ?? '') . ' — ProClasse';
ob_start();
require __DIR__ . '/editor.php';
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
