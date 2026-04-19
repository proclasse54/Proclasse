<?php
// editor.php — contenu uniquement (wrappé par create.php ou edit.php)
$isNew = !($room['id'] ?? null);

// Construire la map des sièges actifs
$activeSeats = [];
foreach (($room['seats'] ?? []) as $s) {
    $activeSeats[$s['row_index'] . '_' . $s['col_index']] = true;
}
?>
<div class="page-header">
  <div>
    <a href="/rooms" class="btn btn-ghost btn-sm">← Retour</a>
    <h1><?= $isNew ? 'Nouvelle salle' : 'Modifier : ' . htmlspecialchars($room['name']) ?></h1>
  </div>
  <button class="btn btn-primary" onclick="saveRoom()">Enregistrer</button>
</div>

<div class="editor-layout">
  <div class="editor-panel">
    <h2>Configuration</h2>
    <div class="form-group">
      <label>Nom de la salle</label>
      <input type="text" id="roomName" value="<?= htmlspecialchars($room['name'] ?? '') ?>" placeholder="ex: Salle 101">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Rangées</label>
        <input type="number" id="roomRows" value="<?= (int)($room['rows'] ?? 5) ?>" min="1" max="15" onchange="rebuildGrid()">
      </div>
      <div class="form-group">
        <label>Colonnes</label>
        <input type="number" id="roomCols" value="<?= (int)($room['cols'] ?? 6) ?>" min="1" max="12" onchange="rebuildGrid()">
      </div>
    </div>
    <p class="form-hint">Cliquez sur une place pour l'activer ou la désactiver.<br>Les places grises sont inactives (allée, bureau…).</p>
    <div class="editor-legend">
      <span class="seat-demo active"></span> Active &nbsp;
      <span class="seat-demo inactive"></span> Inactive
    </div>
  </div>

  <div class="editor-grid-wrap">
    <div class="room-label-top">Tableau / Bureau</div>
    <div id="roomGrid" class="room-grid"></div>
  </div>
</div>

<script>
const ROOM_ID  = <?= json_encode($room['id'] ?? null) ?>;
let activeMap  = <?= json_encode($activeSeats) ?>;

function rebuildGrid() {
  var rows = parseInt(document.getElementById('roomRows').value) || 5;
  var cols = parseInt(document.getElementById('roomCols').value) || 6;
  var grid = document.getElementById('roomGrid');
  grid.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
  grid.innerHTML = '';
  for (var r = 0; r < rows; r++) {
    for (var c = 0; c < cols; c++) {
      (function(row, col) {
        var key  = row + '_' + col;
        var seat = document.createElement('div');
        seat.className   = 'seat-cell ' + (activeMap[key] ? 'active' : 'inactive');
        seat.textContent = String.fromCharCode(65 + row) + (col + 1);
        seat.addEventListener('click', function() {
          if (activeMap[key]) { delete activeMap[key]; seat.className = 'seat-cell inactive'; }
          else                { activeMap[key] = true;  seat.className = 'seat-cell active';   }
        });
        grid.appendChild(seat);
      })(r, c);
    }
  }
}

function saveRoom() {
  var rows = parseInt(document.getElementById('roomRows').value);
  var cols = parseInt(document.getElementById('roomCols').value);
  var name = document.getElementById('roomName').value.trim();
  if (!name) { alert('Donnez un nom à la salle.'); return; }

  var seats = Object.keys(activeMap).map(function(k) {
    var p = k.split('_');
    var r = parseInt(p[0]), c = parseInt(p[1]);
    return { row: r, col: c, label: String.fromCharCode(65 + r) + (c + 1) };
  });

  var url     = ROOM_ID ? '/api/rooms/' + ROOM_ID : '/api/rooms';
  var payload = JSON.stringify({ name: name, rows: rows, cols: cols, seats: seats });

  fetch(url, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    payload
  })
  .then(function(response) {
    // On lit toujours en texte d'abord pour voir l'erreur PHP éventuelle
    return response.text().then(function(text) {
      try {
        var d = JSON.parse(text);
        if (d.ok) {
          window.location.href = '/rooms';
        } else {
          alert('Erreur serveur : ' + JSON.stringify(d));
        }
      } catch(e) {
        // Le serveur a renvoyé du HTML (erreur PHP) → on l'affiche
        document.open();
        document.write('<h2 style="color:red;font-family:monospace">Erreur PHP reçue :</h2>' + text);
        document.close();
      }
    });
  })
  .catch(function(err) {
    alert('Erreur fetch : ' + err.message);
  });
}

rebuildGrid();
</script>
