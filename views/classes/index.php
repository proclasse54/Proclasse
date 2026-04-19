<?php
$pageTitle = 'Classes — ProClasse';
ob_start();
?>
<div class="page-header">
  <div>
    <h1>Classes</h1>
    <p class="text-muted">Gérez vos classes et importez les élèves depuis Pronote</p>
  </div>
  <div class="header-actions">
    <button class="btn btn-secondary" onclick="openImportModal()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Importer Pronote
    </button>
    <button class="btn btn-primary" onclick="openCreateClassModal()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nouvelle classe
    </button>
  </div>
</div>

<?php if (empty($classes)): ?>
<div class="empty-state">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
  <h3>Aucune classe</h3>
  <p>Importez depuis Pronote — les classes seront créées automatiquement.</p>
  <button class="btn btn-primary" onclick="openImportModal()">Importer depuis Pronote</button>
</div>
<?php else: ?>

<!-- Barre de sélection multiple -->
<div class="bulk-bar" id="bulkBar">
  <label class="checkbox-label">
    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"> Tout sélectionner
  </label>
  <span id="selectedCount" class="text-muted text-sm">0 sélectionnée(s)</span>
  <button class="btn btn-danger btn-sm" id="deleteSelectedBtn" onclick="deleteSelected()" disabled>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
    Supprimer la sélection
  </button>
  <button class="btn btn-danger btn-sm btn-outline" onclick="deleteAll()">Tout supprimer</button>
</div>

<div class="cards-grid" id="classesGrid">
  <?php foreach ($classes as $c): ?>
  <div class="card selectable" data-id="<?= $c['id'] ?>">
    <div class="card-select">
      <input type="checkbox" class="class-checkbox" value="<?= $c['id'] ?>" onchange="updateSelection()">
    </div>
    <div class="card-body">
      <div class="card-title"><?= htmlspecialchars($c['name']) ?></div>
      <div class="card-meta">
        <?= $c['student_count'] ?> élève<?= $c['student_count'] > 1 ? 's' : '' ?>
        <?= $c['year'] ? ' · ' . $c['year'] : '' ?>
      </div>
    </div>
    <div class="card-footer">
      <a href="/classes/<?= $c['id'] ?>" class="btn btn-sm btn-primary">Gérer</a>
      <button class="btn btn-sm btn-danger" onclick="deleteClass(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">Supprimer</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal import Pronote (global) ──────────────────────── -->
<div class="modal-overlay" id="importModal" hidden>
  <div class="modal modal-lg">
    <div class="modal-header">
      <h2>Importer depuis Pronote</h2>
      <button class="modal-close" onclick="closeModal('importModal')">&times;</button>
    </div>

    <div class="import-instructions">
      <ol>
        <li>Dans Pronote → <strong>Élèves → Liste des élèves</strong></li>
        <li>Sélectionnez toutes les lignes <kbd>Ctrl+A</kbd></li>
        <li>Copiez <kbd>Ctrl+C</kbd></li>
        <li>Collez ci-dessous <kbd>Ctrl+V</kbd></li>
      </ol>
      <p style="margin-top:.75rem;color:var(--text-muted);font-size:var(--text-xs)">
        Les classes sont créées automatiquement d'après le champ "Classe" de chaque élève.
      </p>
    </div>

    <div class="form-group">
      <label>Données copiées depuis Pronote</label>
      <textarea id="pronoteData" rows="10"
        placeholder="Collez ici les données (Ctrl+V)..."
        oninput="previewImport(this.value)"></textarea>
    </div>

    <div id="importPreview" class="import-preview" hidden></div>

    <div class="modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeModal('importModal')">Annuler</button>
      <button type="button" class="btn btn-primary" id="importBtn" onclick="doImport()" disabled>Importer</button>
    </div>
  </div>
</div>

<!-- ── Modal création manuelle ────────────────────────────── -->
<div class="modal-overlay" id="createClassModal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Nouvelle classe</h2>
      <button class="modal-close" onclick="closeModal('createClassModal')">&times;</button>
    </div>
    <form onsubmit="createClass(event)">
      <div class="form-group">
        <label>Nom de la classe</label>
        <input type="text" name="name" placeholder="ex: 3ème B" required>
      </div>
      <div class="form-group">
        <label>Année scolaire</label>
        <input type="text" name="year" placeholder="ex: 2025-2026">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('createClassModal')">Annuler</button>
        <button type="submit" class="btn btn-primary">Créer</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Import Pronote ─────────────────────────────────────────
function openImportModal() {
  document.getElementById('pronoteData').value = '';
  document.getElementById('importPreview').hidden = true;
  document.getElementById('importBtn').disabled = true;
  document.getElementById('importModal').hidden = false;
  setTimeout(() => document.getElementById('pronoteData').focus(), 100);
}

function previewImport(text) {
  const lines = text.trim().split('\n').filter(l => l.trim());
  const preview = document.getElementById('importPreview');
  const btn = document.getElementById('importBtn');
  if (lines.length < 2) { preview.hidden = true; btn.disabled = true; return; }
  const count = lines.slice(1).filter(l => l.trim()).length;
  // Détecter les classes uniques
  const headerLine = lines[0].split('\t');
  const classeIdx = headerLine.findIndex(h => h.trim() === 'Classe');
  let classes = new Set();
  if (classeIdx >= 0) {
    lines.slice(1).forEach(l => {
      const val = l.split('\t')[classeIdx];
      if (val && val.trim()) classes.add(val.trim());
    });
  }
  preview.innerHTML = '✅ <strong>' + count + '</strong> élève(s) · '
    + '<strong>' + classes.size + '</strong> classe(s) détectée(s) : '
    + Array.from(classes).sort().join(', ');
  preview.hidden = false;
  btn.disabled = count === 0;
}

function doImport() {
  const text = document.getElementById('pronoteData').value.trim();
  if (!text) return;
  const btn = document.getElementById('importBtn');
  btn.disabled = true;
  btn.textContent = 'Import en cours…';

  fetch('/api/classes/0/import-paste', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: text })
  })
  .then(r => r.text()).then(text => {
    try {
      const d = JSON.parse(text);
      if (d.ok) {
        closeModal('importModal');
        let msg = '✅ ' + d.inserted + ' élève(s) importé(s)';
        if (d.classes_created) msg += '\n📚 ' + d.classes_created + ' classe(s) créée(s)';
        if (d.skipped) msg += '\n⚠️ ' + d.skipped + ' ligne(s) ignorée(s)';
        alert(msg);
        location.reload();
      } else {
        btn.disabled = false; btn.textContent = 'Importer';
        alert('Erreur : ' + (d.error || JSON.stringify(d)));
      }
    } catch(e) {
      document.open(); document.write(text); document.close();
    }
  });
}

// ── Création manuelle ──────────────────────────────────────
function openCreateClassModal() { document.getElementById('createClassModal').hidden = false; }
function createClass(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fetch('/api/classes', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(Object.fromEntries(fd)) })
    .then(r => r.json()).then(d => { if (d.ok) window.location = '/classes/' + d.id; });
}

// ── Suppression simple ─────────────────────────────────────
function deleteClass(id, name) {
  if (!confirm('Supprimer "' + name + '" et tous ses élèves ?')) return;
  fetch('/api/classes/' + id, { method: 'DELETE' })
    .then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}

// ── Sélection multiple ─────────────────────────────────────
function updateSelection() {
  const checked = document.querySelectorAll('.class-checkbox:checked');
  const count = checked.length;
  document.getElementById('selectedCount').textContent = count + ' sélectionnée(s)';
  document.getElementById('deleteSelectedBtn').disabled = count === 0;
  document.getElementById('selectAll').indeterminate =
    count > 0 && count < document.querySelectorAll('.class-checkbox').length;
  document.getElementById('selectAll').checked =
    count === document.querySelectorAll('.class-checkbox').length;
}

function toggleSelectAll(cb) {
  document.querySelectorAll('.class-checkbox').forEach(c => c.checked = cb.checked);
  updateSelection();
}

function deleteSelected() {
  const ids = Array.from(document.querySelectorAll('.class-checkbox:checked')).map(c => c.value);
  if (!ids.length) return;
  if (!confirm('Supprimer ' + ids.length + ' classe(s) et tous leurs élèves ?')) return;
  Promise.all(ids.map(id => fetch('/api/classes/' + id, { method: 'DELETE' }).then(r => r.json())))
    .then(() => location.reload());
}

function deleteAll() {
  const total = document.querySelectorAll('.class-checkbox').length;
  if (!confirm('⚠️ Supprimer les ' + total + ' classes ET tous leurs élèves ? Cette action est irréversible.')) return;
  const ids = Array.from(document.querySelectorAll('.class-checkbox')).map(c => c.value);
  Promise.all(ids.map(id => fetch('/api/classes/' + id, { method: 'DELETE' }).then(r => r.json())))
    .then(() => location.reload());
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
