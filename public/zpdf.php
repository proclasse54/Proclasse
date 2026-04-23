<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Autoload manuel — inclus dans le ZIP de smalot/pdfparser
require_once '../zpdf/src/autoload.php';

$pdfFile   = '../public/data/Trombinoscope3pm.pdf';
$jsonFile   = './data/eleves.json';      // nom;prenom;classe
$outputDir = './data/photos_eleves/';

if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

// 1. Extraire les images
$parser = new \Smalot\PdfParser\Parser();
$pdf    = $parser->parseFile($pdfFile);

$images = [];
foreach ($pdf->getPages() as $page) {
    foreach ($page->getXObjects() as $xobject) {
        if (method_exists($xobject, 'getDetails')) {
            $details = $xobject->getDetails();
            if (isset($details['Subtype']) && $details['Subtype'] === 'Image') {
                $images[] = $xobject->getContent();
            }
        }
    }
}

echo count($images) . " image(s) trouvée(s).\n";

include('./parse_pronote.php');

// 2. Charger la liste des élèves depuis le JSON
$eleves = json_decode(file_get_contents($jsonFile), true);

if (count($images) !== count($eleves)) {
    echo "⚠️ " . count($images) . " images vs " . count($eleves) . " élèves !\n";
}

// 3. Sauvegarder avec le bon nom
foreach ($images as $i => $imgData) {
    if (!isset($eleves[$i])) break;
    $e      = $eleves[$i];
    $nom    = nettoyerChaine($e['nom']);
    $prenom = nettoyerChaine($e['prenom']);
    $classe = nettoyerChaine($e['classe']);
    $ext    = (substr($imgData, 0, 2) === "\xFF\xD8") ? 'jpg' : 'png';
    $dest   = $outputDir . strtoupper($nom) . '_' . $prenom . '_' . $classe . '.' . $ext;
    file_put_contents($dest, $imgData);
    echo "✓ $dest\n";
}

function nettoyerChaine(string $str): string {
    $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
    return preg_replace('/[^a-zA-Z0-9\-]/', '_', trim($str));
}