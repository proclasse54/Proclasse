<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === DIAGNOSTIC ===

// 1. Vérifier que la librairie se charge bien
require_once '../zpdf/src/autoload.php'; // ou votre chemin
echo "✓ Librairie chargée\n";

// 2. Vérifier que le PDF existe et est lisible
$pdfFile = './data/Trombinoscope3pm.pdf';
if (!file_exists($pdfFile)) die("❌ PDF introuvable : $pdfFile\n");
echo "✓ PDF trouvé (" . filesize($pdfFile) . " octets)\n";

// 3. Parser le PDF
$parser = new \Smalot\PdfParser\Parser();
$pdf    = $parser->parseFile($pdfFile);
echo "✓ PDF parsé\n";

// 4. Compter les pages
$pages = $pdf->getPages();
echo "✓ Nombre de pages : " . count($pages) . "\n";

// 5. Inspecter TOUS les objets de chaque page
foreach ($pages as $pageNum => $page) {
    echo "\n--- Page $pageNum ---\n";
    $xobjects = $page->getXObjects();
    echo "  Nombre d'XObjects : " . count($xobjects) . "\n";

    foreach ($xobjects as $name => $xobject) {
        echo "  XObject '$name' — classe : " . get_class($xobject) . "\n";
        if (method_exists($xobject, 'getDetails')) {
            $details = $xobject->getDetails();
            echo "  Détails : " . print_r($details, true) . "\n";
        }
    }
}