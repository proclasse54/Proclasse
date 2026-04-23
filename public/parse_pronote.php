<?php
$eleves = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['data'])) {
    $lines = explode("\n", trim($_POST['data']));

    // Trouver les index des colonnes depuis l'en-tête (ligne 1)
    $headers = str_getcsv(array_shift($lines), "\t");
    $headers = array_map('trim', $headers);

    $idxNom    = array_search('Nom',    $headers);
    $idxPrenom = array_search('Prénom', $headers);
    $idxClasse = array_search('Classe', $headers);

    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line, "\t");

        $nom    = trim($cols[$idxNom]    ?? '');
        $prenom = trim($cols[$idxPrenom] ?? '');
        $classe = trim($cols[$idxClasse] ?? '');

        if ($nom && $prenom) {
            $eleves[] = ['nom' => $nom, 'prenom' => $prenom, 'classe' => $classe];
        }
    }

    // Générer le fichier JSON de correspondance pour le script de renommage
    if (!empty($eleves)) {
        file_put_contents('./data/eleves.json', json_encode($eleves, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Import liste PRONOTE</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
        textarea { width: 100%; height: 150px; font-size: 0.85rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
        th { background: #eee; }
        .success { color: green; font-weight: bold; }
    </style>
</head>
<body>
    <h2>Import liste élèves PRONOTE</h2>
    <form method="POST">
        <p>Copiez-collez ici le tableau PRONOTE (avec l'en-tête) :</p>
        <textarea name="data" placeholder="Collez ici le contenu copié depuis PRONOTE..."></textarea>
        <br><br>
        <button type="submit">Extraire</button>
    </form>

    <?php if (!empty($eleves)): ?>
        <p class="success">✓ <?= count($eleves) ?> élèves extraits — fichier <strong>eleves.json</strong> généré.</p>
        <table>
            <tr><th>#</th><th>Nom</th><th>Prénom</th><th>Classe</th></tr>
            <?php foreach ($eleves as $i => $e): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($e['nom']) ?></td>
                <td><?= htmlspecialchars($e['prenom']) ?></td>
                <td><?= htmlspecialchars($e['classe']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>