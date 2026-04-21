<?php
$pageTitle = 'Tags — ProClasse';
ob_start();
?>
<div class="page-header">
  <div>
    <h1>Tags</h1>
    <p class="text-muted">Gérez les tags utilisés pendant vos séances</p>
  </div>
  <button class="btn btn-primary" onclick="openCreateModal()">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
    Nouveau tag
  </button>
</div>

<?php if (empty($tags)): ?>
<div class="empty-state">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
    <line x1="7" y1="7" x2="7.01" y2="7"/>
  </svg>
  <h3>Aucun tag</h3>
  <p>Créez votre premier tag pour annoter vos élèves pendant les séances.</p>
  <button class="btn btn-primary" onclick="openCreateModal()">Créer un tag</button>
</div>
<?php else: ?>
<div class="cards-grid">
  <?php foreach ($tags as $t): ?>
  <div class="card" style="border-left: 4px solid <?= htmlspecialchars($t['color'] ?? '#888') ?>">
    <div class="card-body">
      <div class="card-title">
        <?= htmlspecialchars($t['icon'] ?? '') ?> <?= htmlspecialchars($t['label']) ?>
      </div>
      <div class="card-meta">Couleur : <?= htmlspecialchars($t['color'] ?? '#888') ?></div>
    </div>
    <div class="card-footer">
      <button class="btn btn-sm btn-secondary"
              onclick="openEditModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['label'])) ?>', '<?= htmlspecialchars($t['color'] ?? '#888') ?>', '<?= htmlspecialchars(addslashes($t['icon'] ?? '')) ?>', <?= (int)$t['sort_order'] ?>)">
        Modifier
      </button>
      <button class="btn btn-sm btn-danger"
              onclick="deleteTag(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['label'])) ?>')">
        Supprimer
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal création / édition -->
<div class="modal-overlay" id="tagModal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2 id="tagModalTitle">Nouveau tag</h2>
      <button class="modal-close" onclick="closeModal('tagModal')">&times;</button>
    </div>
    <form onsubmit="saveTag(event)">
      <input type="hidden" id="tagId">
      <div class="form-group">
        <label>Label</label>
        <input type="text" id="tagLabel" placeholder="ex: Distrait" required>
      </div>
      <div class="form-group">
        <label>Icône (emoji)</label>
        <input type="text" id="tagIcon" placeholder="ex: 😴">
      </div>
      <div class="form-group">
        <label>Couleur</label>
        <input type="color" id="tagColor" value="#888888">
      </div>
      <div class="form-group">
        <label>Ordre d'affichage</label>
        <input type="number" id="tagOrder" value="99" min="0">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('tagModal')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateModal() {
  document.getElementById('tagModalTitle').textContent = 'Nouveau tag';
  document.getElementById('tagId').value    = '';
  document.getElementById('tagLabel').value = '';
  document.getElementById('tagIcon').value  = '';
  document.getElementById('tagColor').value = '#888888';
  document.getElementById('tagOrder').value = '99';
  document.getElementById('tagModal').hidden = false;
}

function openEditModal(id, label, color, icon, order) {
  document.getElementById('tagModalTitle').textContent = 'Modifier le tag';
  document.getElementById('tagId').value    = id;
  document.getElementById('tagLabel').value = label;
  document.getElementById('tagIcon').value  = icon;
  document.getElementById('tagColor').value = color;
  document.getElementById('tagOrder').value = order;
  document.getElementById('tagModal').hidden = false;
}

function saveTag(e) {
  e.preventDefault();
  const id = document.getElementById('tagId').value;
  fetch('/api/tags', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      id:         id ? parseInt(id) : null,
      label:      document.getElementById('tagLabel').value,
      icon:       document.getElementById('tagIcon').value,
      color:      document.getElementById('tagColor').value,
      sort_order: parseInt(document.getElementById('tagOrder').value),
    })
  })
  .then(r => r.json())
  .then(d => { if (d.ok) location.reload(); else alert(d.error); });
}

function deleteTag(id, label) {
  // 1er appel : suppression normale
  fetch(`/api/tags/${id}`, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        location.reload();
      } else if (d.can_force) {
        // Le tag est utilisé dans des observations
        if (confirm(`⚠️ ${d.error}\n\nForcer la suppression quand même ?`)) {
          // 2ème appel avec ?force=1
          fetch(`/api/tags/${id}?force=1`, { method: 'DELETE' })
            .then(r => r.json())
            .then(d2 => { if (d2.ok) location.reload(); });
        }
      } else {
        alert(d.error);
      }
    });
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';