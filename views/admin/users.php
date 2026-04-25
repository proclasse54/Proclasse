<?php
// views/admin/users.php — inclus par AdminController::users() via ob_start()
// $users et $flash sont injectés par le contrôleur
?>
<div class="page-header">
  <div>
    <h1>Utilisateurs
      <small><?= count($users) ?> compte<?= count($users) > 1 ? 's' : '' ?></small>
    </h1>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('modalCreate').removeAttribute('hidden')">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Nouveau compte
  </button>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:var(--space-5); padding:var(--space-3) var(--space-4); border-radius:var(--radius-md); font-size:var(--text-sm); background:<?= $flash['type'] === 'success' ? 'var(--primary-light)' : 'var(--danger-light)' ?>; color:<?= $flash['type'] === 'success' ? 'var(--primary)' : 'var(--danger)' ?>">
    <?= $flash['msg'] ?>
  </div>
<?php endif ?>

<!-- Tableau des utilisateurs -->
<div class="card" style="overflow:hidden">
  <table class="data-table">
    <thead>
      <tr>
        <th>Email</th>
        <th>Rôle</th>
        <th>Statut</th>
        <th>Créé le</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr id="row-<?= $u['id'] ?>">
        <td style="font-weight:500"><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <span class="badge" style="<?= $u['role'] === 'admin' ? 'background:oklch(from var(--primary) l c h/.15);color:var(--primary)' : '' ?>">
            <?= $u['role'] === 'admin' ? '⚙️ Admin' : '👤 Utilisateur' ?>
          </span>
        </td>
        <td>
          <?php if ($u['is_active']): ?>
            <span style="color:var(--primary);font-size:var(--text-xs);font-weight:600">● Actif</span>
          <?php else: ?>
            <span style="color:var(--text-muted);font-size:var(--text-xs)">○ Inactif</span>
          <?php endif ?>
        </td>
        <td style="color:var(--text-muted);font-size:var(--text-xs)">
          <?= date('d/m/Y', strtotime($u['created_at'])) ?>
        </td>
        <td style="text-align:right">
          <div style="display:flex;gap:var(--space-2);justify-content:flex-end">
            <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($u)) ?>)">
              Modifier
            </button>
            <?php if ($u['id'] !== Auth::user()): ?>
            <button class="btn btn-danger btn-sm" onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['email']) ?>')">
              Supprimer
            </button>
            <?php endif ?>
          </div>
        </td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>

<!-- ── Modal : créer un compte ──────────────────────────────── -->
<div id="modalCreate" class="modal-overlay" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Nouveau compte</h2>
      <button class="modal-close" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">&times;</button>
    </div>
    <form method="POST" action="/admin/users">
      <div class="form-group">
        <label for="c_email">Email</label>
        <input type="email" id="c_email" name="email" required autocomplete="off" placeholder="prenom.nom@etab.fr">
      </div>
      <div class="form-group">
        <label for="c_role">Rôle</label>
        <select id="c_role" name="role">
          <option value="user">👤 Utilisateur</option>
          <option value="admin">⚙️ Admin</option>
        </select>
      </div>
      <div class="form-group">
        <label for="c_password">Mot de passe <span class="form-hint">(8 car. min.)</span></label>
        <input type="password" id="c_password" name="password" required minlength="8" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">Annuler</button>
        <button type="submit" class="btn btn-primary">Créer</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal : modifier un compte ───────────────────────────── -->
<div id="modalEdit" class="modal-overlay" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Modifier le compte</h2>
      <button class="modal-close" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">&times;</button>
    </div>
    <form id="formEdit" method="POST" action="">
      <div class="form-group">
        <label for="e_email">Email</label>
        <input type="email" id="e_email" name="email" required autocomplete="off">
      </div>
      <div class="form-group">
        <label for="e_role">Rôle</label>
        <select id="e_role" name="role">
          <option value="user">👤 Utilisateur</option>
          <option value="admin">⚙️ Admin</option>
        </select>
      </div>
      <div class="form-group">
        <label for="e_active">Statut</label>
        <select id="e_active" name="is_active">
          <option value="1">Actif</option>
          <option value="0">Inactif</option>
        </select>
      </div>
      <div class="form-group">
        <label for="e_password">Nouveau mot de passe <span class="form-hint">(laisser vide = inchangé)</span></label>
        <input type="password" id="e_password" name="password" minlength="8" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="this.closest('.modal-overlay').setAttribute('hidden','')">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(u) {
  const f = document.getElementById('formEdit');
  f.action = '/admin/users/' + u.id;
  document.getElementById('e_email').value  = u.email;
  document.getElementById('e_role').value   = u.role;
  document.getElementById('e_active').value = u.is_active;
  document.getElementById('e_password').value = '';
  document.getElementById('modalEdit').removeAttribute('hidden');
}

async function deleteUser(id, email) {
  if (!confirm('Supprimer le compte ' + email + ' ?')) return;
  const res = await fetch('/admin/users/' + id, { method: 'DELETE' });
  const json = await res.json();
  if (json.ok) {
    const row = document.getElementById('row-' + id);
    if (row) row.remove();
  } else {
    alert(json.error ?? 'Erreur lors de la suppression.');
  }
}

// Fermer les modales avec Échap
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => m.setAttribute('hidden', ''));
  }
});
</script>
