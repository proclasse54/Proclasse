<?php
$pageTitle = 'Nouvelle salle — ProClasse';
$room = ['id' => null, 'name' => '', 'rows' => 5, 'cols' => 6, 'seats' => []];
ob_start();
require __DIR__ . '/editor.php';
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
