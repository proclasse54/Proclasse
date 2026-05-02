<?php
$pageTitle = 'Séance — ' . $session['class_name'] . ' — ProClasse';

// Organiser les sièges en grille
$seatMap = [];
foreach ($seats as $s) { $seatMap[$s['row_index']][$s['col_index']] = $s; }

$grid = [];
for ($r = 0; $r < $session['room_rows']; $r++) {
    for ($c = 0; $c < $session['room_cols']; $c++) {
        $grid[$r][$c] = $seatMap[$r][$c] ?? null;
    }
}

// Observations indexées par student_id
$obsMap = [];
foreach ($observations as $o) { $obsMap[$o['student_id']][] = $o; }

// ── Séance passée ? (date strictement < aujourd'hui) ──────────────────────
$isPast = strtotime($session['date']) < strtotime(date('Y-m-d'));
