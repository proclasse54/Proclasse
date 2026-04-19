<?php
$pageTitle = 'Séances — ProClasse';
ob_start();
?>
<div class="page-header">
  <div>
    <h1>Séances</h1>
    <p class="text-muted">Historique et démarrage d'une nouvelle séance</p>
  </div>
  <button class="btn btn-primary" onclick="openNewSessionModal()">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Nouvelle séance
  </button>
</div>

<?php if (empty($sessions)): ?>
<div class="empty-state">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
  <h3>Aucune séance</h3>
  <p>Créez votre première séance en cliquant sur le bouton ci-dessus.<br>Vous aurez besoin d'une salle et d'une classe configurée.</p>
</div>
<?php else: ?>
<div class="sessions-list">
  <?php foreach ($sessions as $s): ?>
  <div class="session-card">
    <div class="session-card-left">
      <div class="session-date"><?= date('d/m/Y', strtotime($s['date'])) ?></div>
      <div class="session-meta">
        <strong><?= htmlspecialchars($s['class_name']) ?></strong>
        <span>·</span>
        <span><?= htmlspecialchars($s['room_name']) ?></span>
        <?php if ($s['subject']): ?>
        <span>·</span>
        <span class="badge"><?= htmlspecialchars($s['subject']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <a href="/sessions/<?= $s['id'] ?>/live" class="btn btn-sm btn-primary">Ouvrir</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal nouvelle séance -->
<div class="modal-overlay" id="newSessionModal" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Nouvelle séance</h2>
      <button class="modal-close" onclick="closeModal('newSessionModal')">&times;</button>
    </div>
    <form id="newSessionForm" onsubmit="createSession(event)">
      <div class="form-group">
        <label for="sessionDate">Date</label>
        <input type="date" id="sessionDate" name="date" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-group">
        <label for="sessionPlan">Classe / Salle</label>
        <select id="sessionPlan" name="plan_id" required>
          <option value="">— Choisir —</option>
          <?php foreach ($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['class_name'] . ' — ' . $pl['room_name'] . ' (' . $pl['name'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="sessionSubject">Matière <span class="text-muted">(optionnel)</span></label>
        <input type="text" id="sessionSubject" name="subject" placeholder="ex: Mathématiques">
      </div>
      <?php if (empty($plans)): ?>
      <p class="form-hint error">⚠️ Aucun plan de classe configuré. Créez d'abord une salle, une classe, et assignez-les.</p>
      <?php endif; ?>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('newSessionModal')">Annuler</button>
        <button type="submit" class="btn btn-primary" <?= empty($plans) ? 'disabled' : '' ?>>Démarrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openNewSessionModal() {
  document.getElementById('newSessionModal').hidden = false;
}
function createSession(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  fetch('/api/sessions', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(Object.fromEntries(fd))
  })
  .then(r => r.json())
  .then(d => { if (d.ok) window.location = '/sessions/' + d.id + '/live'; });
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../layouts/app.php';
