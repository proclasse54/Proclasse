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

/**
 * Retourne l'URL de la photo d'un élève, ou null si absente.
 * Format fichier : CLASSE.NOM.Prenom.jpg
 */
function getPhotoUrl(string $classe, string $nom, string $prenom): ?string {
    $prenomFichier = removeAccents($prenom);
    
    // Chemin absolu réel sur le serveur
    $cheminAbsolu = '/var/www/sub-domains/proclasse/data/photos_eleves/' 
                    . "{$classe}.{$nom}.{$prenomFichier}.jpg";
    
    // URL publique retournée au navigateur
    $urlPublique = "/data/photos_eleves/{$classe}.{$nom}.{$prenomFichier}.jpg";
    
    return file_exists($cheminAbsolu) ? $urlPublique : null;
}