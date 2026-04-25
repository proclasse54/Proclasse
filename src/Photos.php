<?php
// src/Photo.php

/**
 * Supprime les accents pour correspondre aux noms de fichiers photos.
 * Ex : "Éloïse" → "Eloise", "Rémi" → "Remi"
 */
function removeAccents(string $str): string {
    if (class_exists('Normalizer')) {
        $str = Normalizer::normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
        return $str;
    }
    // Fallback si intl non dispo
    return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
}

function nettoyerChaine(string $str): string
{
    if (class_exists('Normalizer')) {
        $str = Normalizer::normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
    } else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }
    // espaces → tirets, caractères spéciaux supprimés
    $str = preg_replace('/\s+/', '-', trim($str));
    return preg_replace('/[^a-zA-Z0-9\-]/', '', $str);
}


/**
 * Résout les paramètres de recadrage pour un élève donné.
 * Priorité : student > class > default
 */
function getCropSettings(int $studentId = 0, int $classId = 0): array
{
    $db = Database::get();

    // Cherche dans l'ordre : student → class → default
    $rows = $db->prepare("
        SELECT * FROM photo_crop_settings
        WHERE (scope = 'student'  AND scope_id = ?)
           OR (scope = 'class'    AND scope_id = ?)
           OR (scope = 'default'  AND scope_id IS NULL)
        ORDER BY
            CASE scope
                WHEN 'student'  THEN 1
                WHEN 'class'    THEN 2
                WHEN 'default'  THEN 3
            END
        LIMIT 1
    ");
    $rows->execute([$studentId, $classId]);
    $cfg = $rows->fetch();

    // Valeurs de repli si rien en BDD
    return $cfg ?: [
        'crop_x' => 0.20,
        'crop_y' => 0.20,
        'crop_w' => 0.60,
        'crop_h' => 0.60,
    ];
}

/**
 * Recadre une image selon les paramètres de recadrage donnés.
 */
function rognerPortrait(string $imageData, array $crop): string
{
    $img = @imagecreatefromstring($imageData);
    if (!$img) return $imageData;

    $w = imagesx($img);
    $h = imagesy($img);

    $x  = (int)($w * $crop['crop_x']);
    $y  = (int)($h * $crop['crop_y']);
    $nw = (int)($w * $crop['crop_w']);
    $nh = (int)($h * $crop['crop_h']);

    if ($nw <= 0 || $nh <= 0) return $imageData;

    $canvas = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($canvas, $img, 0, 0, $x, $y, $nw, $nh, $nw, $nh);

    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagejpeg($canvas, $tmp, 95);
    $out = file_get_contents($tmp);
    unlink($tmp);

    imagedestroy($img);
    imagedestroy($canvas);
    return $out;
}