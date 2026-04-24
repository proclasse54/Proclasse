<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../src/parse_trombi_pdf/autoload.php';
use Smalot\PdfParser\Parser;

$outputDir = __DIR__ . '/data/photos_eleves/';
if (!is_dir($outputDir)) mkdir($outputDir, 0775, true);

$eleves = [];
$traite = false;
$erreur = '';

// ════════════════════════════════════════════════════════════════════
// TRAITEMENT DU PDF
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf'])) {
    $file = $_FILES['pdf'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $erreur = "Erreur lors de l'upload (code {$file['error']}).";
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $erreur = "Le fichier doit être un PDF.";
    } else {
        $parser = new Parser();
        $pdf    = $parser->parseFile($file['tmp_name']);
        $traite = true;

        foreach ($pdf->getPages() as $pageNum => $page) {

            // ── 1. Récupérer l'image de la page ──────────────────────
            $imageData = null;
            foreach ($page->getXObjects() as $name => $xobj) {
                if (!preg_match('/^wpt\d+$/i', $name)) continue;
                if (method_exists($xobj, 'getDetails')) {
                    $details = $xobj->getDetails();
                    if (($details['Subtype'] ?? '') === 'Image') {
                        $imageData = $xobj->getContent();
                        break; // une seule photo par page
                    }
                }
            }
            if ($imageData === null) continue;

            // ── 2. Extraire le texte ──────────────────────────────────
            // smalot retourne un bloc unique : "4D\nARRAGONI Charlotte"
            $texte = trim($page->getText());

            // Supprimer la ligne copyright
            $texte = preg_replace('/©.*$/m', '', $texte);

            // Découper en lignes non vides
            $lignes = array_values(array_filter(
                array_map('trim', explode("\n", $texte)),
                fn($l) => $l !== ''
            ));

            // lignes[0] = "4D"
            // lignes[1] = "DE MOURA Rebecca"
            if (count($lignes) < 2) continue;

            $classe    = preg_replace('/\s+/', '', strtoupper($lignes[0])); // "4 A" → "4A"
            $nomPrenom = trim($lignes[1]);

            // Dernier mot = Prénom, tout le reste = NOM
            $mots   = preg_split('/\s+/', $nomPrenom);
            $prenom = array_pop($mots);
            $nom    = implode(' ', $mots);

            if ($classe === '' || $nom === '' || $prenom === '') continue;

            $eleves[] = [
                'classe'    => $classe,
                'nom'       => $nom,
                'prenom'    => $prenom,
                'imageData' => $imageData,
            ];
        }

        // ── 3. Sauvegarder les photos ─────────────────────────────────
        foreach ($eleves as $e) {
            $classeFichier = nettoyerChaine($e['classe']);
            $nomFichier    = nettoyerChaine(strtoupper($e['nom']));
            $prenomFichier = nettoyerChaine($e['prenom']);
            $ext           = substr($e['imageData'], 0, 2) === "\xFF\xD8" ? 'jpg' : 'png';

            $dest = $outputDir . $classeFichier . '.' . $nomFichier . '.' . $prenomFichier . '.' . $ext;
            file_put_contents($dest, rognerPortrait($e['imageData']));
        }
    }
}

// ════════════════════════════════════════════════════════════════════
// FONCTIONS
// ════════════════════════════════════════════════════════════════════

function nettoyerChaine(string $str): string
{
    if (class_exists('Normalizer')) {
        $str = Normalizer::normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
    } else {
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }
    $str = preg_replace('/\s+/', '-', trim($str));
    return preg_replace('/[^a-zA-Z0-9\-]/', '', $str);
}

function rognerPortrait(string $imageData): string
{
    $img = imagecreatefromstring($imageData);
    if (!$img) return $imageData;

    $w = imagesx($img);
    $h = imagesy($img);

    $margeHaut   = (int)($h * 0.30);
    $margeBas    = (int)($h * 0.20);
    $margeGauche = (int)($w * 0.20);
    $margeDroite = (int)($w * 0.20);

    $nw = $w - $margeGauche - $margeDroite;
    $nh = $h - $margeHaut   - $margeBas;

    if ($nw <= 0 || $nh <= 0) return $imageData;

    $crop = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($crop, $img, 0, 0, $margeGauche, $margeHaut, $nw, $nh, $nw, $nh);

    ob_start();
    imagejpeg($crop, null, 90);
    $output = ob_get_clean();

    imagedestroy($img);
    imagedestroy($crop);
    return $output;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Extraction trombinoscope Pronote</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#222;padding:2rem}
h1{font-size:1.4rem;margin-bottom:1.5rem;color:#1a1a1a}
.card{background:#fff;border-radius:10px;padding:2rem;max-width:600px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
label{display:block;font-weight:600;margin-bottom:.5rem}
.drop-zone{border:2px dashed #ccc;border-radius:8px;padding:2rem;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}
.drop-zone:hover,.drop-zone.dragover{border-color:#0066cc;background:#f0f6ff}
.drop-zone input[type="file"]{display:none}
.drop-zone .icon{font-size:2.5rem;margin-bottom:.5rem}
.drop-zone .hint{color:#888;font-size:.9rem;margin-top:.4rem}
#file-name{margin-top:.6rem;font-size:.9rem;color:#0066cc;font-weight:500;min-height:1.2rem}
button[type="submit"]{margin-top:1.2rem;width:100%;padding:.75rem;background:#0066cc;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:background .2s}
button[type="submit"]:hover{background:#0052a3}
.erreur{margin-top:1rem;padding:.75rem 1rem;background:#fff0f0;border-left:4px solid #c00;border-radius:4px;color:#c00}
.resultats{margin-top:1.5rem}
.resultats h2{font-size:1.1rem;margin-bottom:.8rem}
.badge{display:inline-block;background:#e6f4ea;color:#1a7a35;padding:.3rem .9rem;border-radius:20px;font-weight:700;margin-bottom:1rem}
.liste{list-style:none;max-height:360px;overflow-y:auto;border:1px solid #eee;border-radius:6px}
.liste li{padding:.45rem .8rem;border-bottom:1px solid #f0f0f0;font-size:.82rem;font-family:monospace}
.liste li:last-child{border-bottom:none}
.liste li::before{content:"✓ ";color:#1a7a35;font-weight:bold}
</style>
</head>
<body>
<div class="card">
  <h1>🎓 Extraction photos — Trombinoscope Pronote</h1>

  <form method="post" enctype="multipart/form-data">
    <label>Fichier PDF trombinoscope Pronote</label>
    <div class="drop-zone" id="drop-zone">
      <div class="icon">📄</div>
      <div>Glissez votre PDF ici ou <strong>cliquez pour choisir</strong></div>
      <div class="hint">Format attendu : trombinoscope exporté depuis Pronote</div>
      <input type="file" name="pdf" id="pdf-input" accept=".pdf">
    </div>
    <div id="file-name"></div>

    <?php if ($erreur): ?>
      <div class="erreur">⚠️ <?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <button type="submit">⚙️ Extraire les photos</button>
  </form>

  <?php if ($traite && !$erreur): ?>
  <div class="resultats">
    <h2>Résultats</h2>
    <div class="badge">✅ <?= count($eleves) ?> photo(s) extraite(s)</div>
    <ul class="liste">
      <?php foreach ($eleves as $e): ?>
        <li><?= htmlspecialchars(
              nettoyerChaine($e['classe']) . '.' .
              nettoyerChaine(strtoupper($e['nom'])) . '.' .
              nettoyerChaine($e['prenom']) . '.jpg'
            ) ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin-top:.8rem;font-size:.85rem;color:#666">
      Enregistrées dans <code><?= htmlspecialchars($outputDir) ?></code>
    </p>
  </div>
  <?php endif; ?>
</div>

<script>
const zone  = document.getElementById('drop-zone');
const input = document.getElementById('pdf-input');
const label = document.getElementById('file-name');
zone.addEventListener('click', () => input.click());
input.addEventListener('change', () => { if (input.files[0]) label.textContent = '📎 ' + input.files[0].name; });
zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if (f && f.name.endsWith('.pdf')) {
    const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
    label.textContent = '📎 ' + f.name;
  } else { label.textContent = '⚠️ Fichier non valide (PDF requis)'; }
});
</script>
</body>
</html>