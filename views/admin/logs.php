<?php
// views/admin/logs.php
// $logs, $levels, $categories, $flash injectés par AdminController::logs()
?>
<div class="page-header">
  <div>
    <h1>Logs applicatifs
      <small><?= count($logs) ?> entrée<?= count($logs) > 1 ? 's' : '' ?></small>
    </h1>
  </div>
  <button class="btn btn-danger btn-sm" onclick="document.getElementById('modalPurge').removeAttribute('hidden')">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
    Purger
  </button>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:var(--space-5);padding:var(--space-3) var(--space-4);border-radius:var(--radius-md);font-size:var(--text-sm);background:<?= $flash['type'] === 'success' ? 'var(--primary-light)' : 'var(--danger-light)' ?>;color:<?= $flash['type'] === 'success' ? 'var(--primary)' : 'var(--danger)' ?>">
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
<?php endif ?>

<!-- Filtres -->
<form method="GET" action="/admin/logs" style="display:flex;gap:var(--space-3);margin-bottom:var(--space-5);align-items:center;flex-wrap:wrap">
  <select name="level" style="font-size:var(--text-sm);padding:var(--space-2) var(--space-3);border-radius:var(--radius-md);border:1px solid var(--border);background:var(--color-surface)">
    <option value="">Tous niveaux</option>
    <?php foreach ($levels as $l): ?>
      <option value="<?= $l ?>" <?= ($_GET['level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach ?>
  </select>
  <select name="category" style="font-size:var(--text-sm);padding:var(--space-2) var(--space-3);border-radius:var(--radius-md);border:1px solid var(--border);background:var(--color-surface)">
    <option value="">Toutes catégories</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= $c ?>" <?= ($_GET['category'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
    <?php endforeach ?>
  </select>
  <select name="limit" style="font-size:var(--text-sm);padding:var(--space-2) var(--space-3);border-radius:var(--radius-md);border:1px solid var(--border);background:var(--color-surface)">
    <?php foreach ([50, 200, 500] as $n): ?>
      <option value="<?= $n ?>" <?= ((int)($_GET['limit'] ?? 200)) === $n ? 'selected' : '' ?>><?= $n ?> lignes</option>
    <?php endforeach ?>
  </select>
  <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
  <?php if (!empty($_GET['level']) || !empty($_GET['category'])): ?>
    <a href="/admin/logs" class="btn btn-ghost btn-sm">Réinitialiser</a>
  <?php endif ?>
</form>

<!-- Table -->
<div class="card" style="overflow:auto">
  <table class="data-table" style="font-size:var(--text-xs)">
    <thead>
      <tr>
        <th style="width:140px">Date</th>
        <th style="width:70px">Niveau</th>
        <th style="width:90px">Catégorie</th>
        <th style="width:150px">Action</th>
        <th>Détails</th>
        <th style="width:130px">Utilisateur</th>
        <th style="width:110px">IP</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:var(--space-8)">Aucun log.</td></tr>
      <?php else: ?>
      <?php foreach ($logs as $log): ?>
      <tr>
        <td style="white-space:nowrap;color:var(--text-muted)"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
        <td>
          <?php
          $lvlColors = [
            'info'     => 'color:var(--primary)',
            'warning'  => 'color:#d97706',
            'error'    => 'color:#dc2626',
            'critical' => 'color:#7c3aed;font-weight:700',
          ];
          $style = $lvlColors[$log['level']] ?? '';
          ?>
          <span style="<?= $style ?>"><?= htmlspecialchars($log['level']) ?></span>
        </td>
        <td><?= htmlspecialchars($log['category']) ?></td>
        <td><?= htmlspecialchars($log['action']) ?></td>
        <td style="font-family:monospace;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($log['details'] ?? '') ?>">
          <?= htmlspecialchars($log['details'] ?? '') ?>
        </td>
        <td style="color:var(--text-muted)"><?= htmlspecialchars($log['email'] ?? '—') ?></td>
        <td style="color:var(--text-muted)"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
      </tr>
      <?php endforeach ?>
      <?php endif ?>
    </tbody>
  </table>
</div>

<!-- Modal purge -->
<div id="modalPurge" class="modal-overlay" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Purger les logs</h2>
      <button class="modal-close" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">&times;</button>
    </div>
    <form method="POST" action="/admin/logs/purge">
      <div class="form-group">
        <label for="before">Supprimer les entrées…</label>
        <select id="before" name="before">
          <option value="7">de plus de 7 jours</option>
          <option value="30" selected>de plus de 30 jours</option>
          <option value="90">de plus de 90 jours</option>
          <option value="all">toutes (purge totale)</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">Annuler</button>
        <button type="submit" class="btn btn-danger">Purger</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('keydown', e => {
  if (e.key === 'Escape')
    document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => m.setAttribute('hidden', ''));
});
</script>
