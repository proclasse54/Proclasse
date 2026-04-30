<?php
// src/Photos.php
// ============================================================
//  Fonctions utilitaires pour la gestion des photos élèves.
//  Ces fonctions globales sont utilisées par PhotoController
//  et ImportController.
// ============================================================

/**
 * Supprime les accents d'une chaîne pour normaliser les noms.
 * Nécessaire pour faire correspondre les noms avec les noms de fichiers photos.
 *
 * Exemple : "Éloïse" → "Eloise", "Rémi" → "Remi", "François" → "Francois"
 *
 * @param string $str  Chaîne avec accents potentiels
 * @return string      Chaîne sans accents
 */
function removeAccents(string $str): string {
    // Utilise l'extension PHP intl (recommandée) si disponible
    if (class_exists('Normalizer')) {
        // FORM_D = décompose les caractères accentués en caractère de base + diacritique
        // Ex : "é" (1 caractère) → "e" + accent combinant (2 entités Unicode)
        $str = Normalizer::normalize($str, Normalizer::FORM_D);

        // Supprime toutes les marques diacritiques (\p{Mn} = catégorie Unicode "Nonspacing Mark")
        // Résultat : seuls les caractères de base subsistent
        $str = preg_replace('/\p{Mn}/u', '', $str);
        return $str;
    }

    // Fallback si l'extension intl n'est pas disponible :
    // iconv avec TRANSLIT essaie de translittérer les caractères non-ASCII
    return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
}

/**
 * Normalise une chaîne pour l'utiliser dans un nom de fichier.
 * Supprime les accents, remplace les espaces par des tirets,
 * et supprime tous les caractères spéciaux restants.
 *
 * Exemple : "3ème A" → "3eme-A", "DUPONT Jean" → "DUPONT-Jean"
 *
 * @param string $str  Chaîne originale (nom, prénom, classe…)
 * @return string      Chaîne utilisable comme partie de nom de fichier
 */
function nettoyerChaine(string $str): string
{
    // Étape 1 : suppression des accents (identique à removeAccents)
    if (class_exists('Normalizer')) {
        $str = Normalizer::normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
    } else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }

    // Étape 2 : remplace un ou plusieurs espaces/tabulations par un tiret
    $str = preg_replace('/\s+/', '-', trim($str));

    // Étape 3 : supprime tout caractère qui n'est pas alphanumérique ou tiret
    return preg_replace('/[^a-zA-Z0-9\-]/', '', $str);
}


/**
 * Récupère les paramètres de recadrage (crop) à appliquer sur la photo d'un élève.
 * Cherche dans la table photo_crop_settings dans cet ordre de priorité :
 *   1. Paramètre spécifique à l'élève (scope = 'student')
 *   2. Paramètre commun à toute la classe (scope = 'class')
 *   3. Paramètre par défaut global (scope = 'default')
 *
 * @param int $studentId  ID de l'élève (0 si non précisé)
 * @param int $classId    ID de la classe (0 si non précisé)
 * @return array          Tableau avec crop_x, crop_y, crop_w, crop_h (valeurs entre 0 et 1)
 */
function getCropSettings(int $studentId = 0, int $classId = 0): array
{
    $db = Database::get();

    // Requête avec CASE WHEN dans ORDER BY pour trier par priorité :
    // student (priorité 1) > class (priorité 2) > default (priorité 3)
    // LIMIT 1 : on ne retourne que la règle la plus prioritaire
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

    // Si aucune config n'est trouvée en BDD, on retourne les valeurs de repli.
    // crop_x=0.20 / crop_y=0.20 → on commence à 20% du bord gauche et haut
    // crop_w=0.60 / crop_h=0.60 → on prend 60% de la largeur et hauteur
    // → résultat : carré central de 60% de l'image originale
    return $cfg ?: [
        'crop_x' => 0.20,
        'crop_y' => 0.20,
        'crop_w' => 0.60,
        'crop_h' => 0.60,
    ];
}

/**
 * Recadre une image JPEG selon les paramètres de crop fournis.
 * Les coordonnées sont exprimées en proportion (entre 0 et 1) :
 *   crop_x/crop_y = coin haut-gauche du cadrage (0=bord, 1=autre bord)
 *   crop_w/crop_h = largeur/hauteur du cadrage
 *
 * Exemple : crop_x=0.1, crop_y=0.0, crop_w=0.8, crop_h=1.0
 *   → enlève 10% sur chaque côté horizontal, garde toute la hauteur
 *
 * @param string $imageData  Données binaires de l'image (contenu du fichier .jpg)
 * @param array  $crop       Paramètres de recadrage (crop_x, crop_y, crop_w, crop_h)
 * @return string            Données binaires de l'image recadrée (JPEG qualité 95)
 *                           En cas d'erreur, retourne les données originales inchangées.
 */
function rognerPortrait(string $imageData, array $crop): string
{
    // Tente de créer une ressource image GD depuis les données binaires
    // Le '@' supprime les warnings PHP si le format est invalide
    $img = @imagecreatefromstring($imageData);
    if (!$img) return $imageData; // En cas d'échec, retourne l'image originale

    // Dimensions de l'image originale
    $w = imagesx($img); // largeur en pixels
    $h = imagesy($img); // hauteur en pixels

    // Calcul des coordonnées de recadrage en pixels absolus
    $x  = (int)($w * $crop['crop_x']); // position X du coin haut-gauche
    $y  = (int)($h * $crop['crop_y']); // position Y du coin haut-gauche
    $nw = (int)($w * $crop['crop_w']); // largeur de la zone à extraire
    $nh = (int)($h * $crop['crop_h']); // hauteur de la zone à extraire

    // Sécurité : si les dimensions calculées sont invalides, retourne l'original
    if ($nw <= 0 || $nh <= 0) return $imageData;

    // Crée une nouvelle image vide aux dimensions du recadrage
    $canvas = imagecreatetruecolor($nw, $nh);

    // Copie la zone définie depuis l'image source vers le canvas
    // imagecopyresampled = copie avec redimensionnement (ici pas de redim, src = dst size)
    // Paramètres : (dst, src, dst_x, dst_y, src_x, src_y, dst_w, dst_h, src_w, src_h)
    imagecopyresampled($canvas, $img, 0, 0, $x, $y, $nw, $nh, $nw, $nh);

    // Sauvegarde le résultat dans un fichier temporaire pour pouvoir le relire en binaire
    $tmp = tempnam(sys_get_temp_dir(), 'photo_');
    imagejpeg($canvas, $tmp, 95); // qualité JPEG 95/100

    // Lit les données binaires du fichier temporaire
    $out = file_get_contents($tmp);

    // Supprime le fichier temporaire (nettoyage)
    unlink($tmp);

    // Libère la mémoire allouée par GD pour les deux images
    imagedestroy($img);
    imagedestroy($canvas);

    return $out; // Retourne les données binaires de l'image recadrée
}
