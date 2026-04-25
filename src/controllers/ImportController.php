<?php
// src/controllers/ImportController.php

class ImportController
{
    // GET /import  → affiche la page unifiée
    public function index(array $p): void
    {
        require ROOT . '/views/import/index.php';
    }

    // POST /import/photos → extraction PDF trombinoscope (logique déplacée depuis parse_trombi.php)
    public function photos(array $p): void
    {
        // Fichier absent à cause d'un dépassement de limite PHP
        if (empty($_FILES) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
            Response::json(['error' => 'Fichier trop volumineux (limite serveur dépassée)'], 413);
            return;
        }

        if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'Fichier PDF manquant ou invalide'], 400);
            return;
        }

        $ext = strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            Response::json(['error' => 'Le fichier doit être un PDF'], 400);
            return;
        }

        $outputDir = ROOT . '/public/data/photos_eleves/';
        if (!is_dir($outputDir)) mkdir($outputDir, 0775, true);

        $data = file_get_contents($_FILES['pdf']['tmp_name']);

        // ── Décompresser les objets FlateDecode ──────────────────
        $objMap = [];
        preg_match_all(
            '/(\d+) 0 obj\s*<<(.*?)>>\s*stream\r?\n(.*?)endstream/s',
            $data, $matches, PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $objNum = (int)$m[1];
            if (strpos($m[2], 'FlateDecode') === false) continue;
            $dec = @gzuncompress($m[3]) ?: @gzinflate($m[3]);
            if ($dec !== false) $objMap[$objNum] = $dec;
        }

        // ── Trouver les pages ────────────────────────────────────
        $pages = [];
        preg_match_all('/(\d+) 0 obj\s*<<(.*?)>>/s', $data, $pm, PREG_SET_ORDER);
        foreach ($pm as $m) {
            if (!preg_match('/\/Type\s*\/Page\b/', $m[2])) continue;
            if (!preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $m[2], $c)) continue;
            $pages[] = (int)$c[1];
        }

        // ── Smalot pour le texte ─────────────────────────────────
        require_once ROOT . '/src/parse_trombi_pdf/autoload.php';
        $parser   = new \Smalot\PdfParser\Parser();
        $pdf      = $parser->parseFile($_FILES['pdf']['tmp_name']);
        $pdfPages = $pdf->getPages();

        // ── Extraire les JPEG ────────────────────────────────────
        $images = [];
        $pos    = 0;
        while (true) {
            $start = strpos($data, "\xFF\xD8\xFF", $pos);
            if ($start === false) break;
            $end = strpos($data, "\xFF\xD9", $start);
            if ($end === false) break;
            $images[] = substr($data, $start, ($end + 2) - $start);
            $pos = $end + 2;
        }

        // ── Associer pages ↔ images ──────────────────────────────
        $eleves   = [];
        $unknown  = [];
        $imgIndex = 0;

        foreach ($pages as $pageIndex => $contentsObjNum) {
            if (!isset($pdfPages[$pageIndex])) continue;

            $wptName = null;
            if (isset($objMap[$contentsObjNum])) {
                if (preg_match('/\/(wpt\d+)\s+Do/i', $objMap[$contentsObjNum], $wm))
                    $wptName = $wm[1];
            }

            $texte  = $pdfPages[$pageIndex]->getText();
            $texte  = preg_replace('/©[^\n]*/u', '', $texte);
            $lignes = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/u', $texte)),
                fn($l) => $l !== ''
            ));

            if (count($lignes) < 2) { if ($wptName) $imgIndex++; continue; }

            $classe = preg_replace('/\s+/u', '-', mb_strtoupper(trim($lignes[0]), 'UTF-8'));
            [$nom, $prenom] = self::splitNomPrenom($lignes[1]);

            if ($classe === '' || $nom === '' || $prenom === '') {
                if ($wptName) $imgIndex++;
                continue;
            }

            if ($wptName === null) continue;

            $imageData = $images[$imgIndex] ?? null;
            $imgIndex++;
            if ($imageData === null) continue;

            $eleves[] = compact('classe', 'nom', 'prenom', 'imageData');
        }

        // ── Sauvegarder ──────────────────────────────────────────
        $extracted = 0;

        foreach ($eleves as $e) {
            $classeFichier = nettoyerChaine($e['classe']);
            $nomFichier    = nettoyerChaine(mb_strtoupper($e['nom'], 'UTF-8'));
            $prenomFichier = nettoyerChaine($e['prenom']);
            $dest = $outputDir . $classeFichier . '.' . $nomFichier . '.' . $prenomFichier . '.jpg';

            if (file_put_contents($dest, $e['imageData']) !== false) $extracted++;
        }

        Response::json([
            'ok'        => true,
            'extracted' => $extracted,
            'unknown'   => $unknown,
        ]);
    }




    private static function splitNomPrenom(string $s): array
    {
        $s    = preg_replace('/[\s\xA0]+/u', ' ', trim($s));
        $mots = explode(' ', $s);
        $nom  = []; $prenom = [];
        foreach ($mots as $mot) {
            $l = preg_replace('/[^\p{L}]/u', '', $mot);

            if ($l === '') {
                if (empty($prenom)) $nom[]    = $mot;
                else                $prenom[] = $mot;
            } elseif ($l === mb_strtoupper($l, 'UTF-8')) {
                $nom[] = $mot;
            } else {
                $prenom[] = $mot;
            }
        }
        return [implode(' ', $nom), implode(' ', $prenom)];
    }


}
