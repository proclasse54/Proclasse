<?php
$pageTitle = $class['name'] . ' — ProClasse';
ob_start();
?>
<div class="page-header">
  <div>
    <a href="/classes" class="btn btn-ghost btn-sm">← Retour</a>
    <h1><?= htmlspecialchars($class['name']) ?><?= $class['year'] ? ' <small>'.$class['year'].'</small>' : '' ?></h1>
  </div>
</div>

<div class="tabs">
  <button class="tab active" onclick="showTab('students', this)">Élèves (<?= count($students) ?>)</button>
  <button class="tab" onclick="showTab('plans', this)">Plans de salle (<?= count($plans) ?>)</button>
</div>

<!-- ── Onglet Élèves ─────────────────────────────────────── -->
<div id="tab-students" class="tab-content">
  <div class="tab-actions">
    <button class="btn btn-primary btn-sm" onclick="openImportModal()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Importer depuis Pronote
    </button>
  </div>

  <?php if (empty($students)): ?>
  <div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <h3>Aucun élève</h3>
    <p>Copiez la liste depuis Pronote puis cliquez sur "Importer depuis Pronote".</p>
  </div>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr><th>Nom</th><th>Prénom</th><th>Classe</th><th>Niveau</th><th>INE</th></tr>
    </thead>
    <tbody>
      <?php foreach ($students as $s): ?>
      <tr>
        <td><?= htmlspecialchars($s['last_name']) ?></td>
        <td><?= htmlspecialchars($s['first_name']) ?></td>
        <td><?= htmlspecialchars($s['class_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($s['level'] ?? '') ?></td>
        <td class="text-muted text-sm"><?= htmlspecialchars($s['pronote_id'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ── Onglet Plans ──────────────────────────────────────── -->
<div id="tab-plans" class="tab-content" hidden>
  <div class="tab-actions">
    <button class="btn btn-primary btn-sm" onclick="openNewPlanModal()">+ Nouveau plan</button>
  </div>
  <?php if (empty($plans)): ?>
  <div class="empty-state"><p>Aucun plan de salle. Créez-en un pour placer les élèves.</p></div>
  <?php else: ?>
  <div class="cards-grid">
    <?php foreach ($plans as $pl): ?>
    <div class="card">
      <div class="card-body">
        <div class="card-title"><?= htmlspecialchars($pl['name']) ?></div>
        <div class="card-meta"><?= htmlspecialchars($pl['room_name']) ?></div>
      </div>
      <div class="card-footer">
        <a href="/plans/<?= $pl['id'] ?>/edit" class="btn btn-sm btn-primary">Placer élèves</a>
        <button class="btn btn-sm btn-danger" onclick="deletePlan(<?= $pl['id'] ?>)">Supprimer</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Modal import Pronote (coller) ────────────────────── -->
<div class="modal-overlay" id="importModal" hidden>
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2>Importer depuis Pronote</h2>
      <button class="modal-close" onclick="closeModal('importModal')">&times;</button>
    </div>

    <div class="import-instructions">
      <ol>
        <li>Dans Pronote, allez dans <strong>Élèves → Liste des élèves</strong></li>
        <li>Sélectionnez toutes les lignes <kbd>Ctrl+A</kbd></li>
        <li>Copiez <kbd>Ctrl+C</kbd></li>
        <li>Collez ci-dessous <kbd>Ctrl+V</kbd></li>
      </ol>
    </div>

    <div class="form-group">
      <label>Données copiées depuis Pronote</label>
      <textarea id="pronoteData" rows="10"
        placeholder="Collez ici les données copiées depuis Pronote (Ctrl+V)..."
        oninput="previewImport(this.value)"></textarea>
    </div>

    <div id="importPreview" class="import-preview" hidden>
      <span id="previewCount"></span>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('importModal')">Annuler</button>
      <button type="button" class="btn btn-primary" id="importBtn" onclick="doImport()" disabled>Importer</button>
    </div>
  </div>
</div>

<!-- ── Modal nouveau plan ─────────────────────────────── -->
<div class="modal-overlay" id="newPlanModal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Nouveau plan de salle</h2>
      <button class="modal-close" onclick="closeModal('newPlanModal')">&times;</button>
    </div>
    <form onsubmit="createPlan(event)">
      <div class="form-group">
        <label>Salle</label>
        <select name="room_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($rooms as $r): ?>
          <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Nom du plan</label>
        <input type="text" name="name" value="Plan par défaut">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('newPlanModal')">Annuler</button>
        <button type="submit" class="btn btn-primary">Créer</button>
      </div>
    </form>
  </div>
</div>

<script>
const CLASS_ID = <?= $class['id'] ?>;

function showTab(name, btn) {
  document.querySelectorAll('.tab-content').forEach(t => t.hidden = true);
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-' + name).hidden = false;
  btn.classList.add('active');
}

// ── Import par collage ─────────────────────────────────
function openImportModal() {
  document.getElementById('pronoteData').value = '';
  document.getElementById('importPreview').hidden = true;
  document.getElementById('importBtn').disabled = true;
  document.getElementById('importModal').hidden = false;
  setTimeout(() => document.getElementById('pronoteData').focus(), 100);
}

function previewImport(text) {
  const lines = text.trim().split('\n').filter(l => l.trim());
  if (lines.length < 2) {
    document.getElementById('importPreview').hidden = true;
    document.getElementById('importBtn').disabled = true;
    return;
  }
  // La 1ère ligne = en-têtes, les suivantes = élèves
  const dataLines = lines.slice(1).filter(l => l.trim());
  const count = dataLines.length;
  document.getElementById('previewCount').textContent =
    '✅ ' + count + ' élève' + (count > 1 ? 's' : '') + ' détecté' + (count > 1 ? 's' : '');
  document.getElementById('importPreview').hidden = false;
  document.getElementById('importBtn').disabled = count === 0;
}

function doImport() {
  const text = document.getElementById('pronoteData').value.trim();
  if (!text) return;
  document.getElementById('importBtn').disabled = true;
  document.getElementById('importBtn').textContent = 'Import en cours…';

  fetch('/api/classes/' + CLASS_ID + '/import-paste', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: text })
  })
  .then(r => r.text()).then(text => {
    try {
      const d = JSON.parse(text);
      if (d.ok) {
        closeModal('importModal');
        location.reload();
      } else {
        alert('Erreur : ' + (d.error || JSON.stringify(d)));
      }
    } catch(e) {
      document.open(); document.write(text); document.close();
    }
  });
}

// ── Plans ──────────────────────────────────────────────
function openNewPlanModal() { document.getElementById('newPlanModal').hidden = false; }

function createPlan(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fetch('/api/classes/' + CLASS_ID + '/plans', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.fromEntries(fd))
  }).then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}

function deletePlan(id) {
  if (!confirm('Supprimer ce plan ?')) return;
  fetch('/api/plans/' + id, { method: 'DELETE' })
    .then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
