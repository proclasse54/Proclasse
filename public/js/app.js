// ── Dark mode ─────────────────────────────────────────────
(function(){
  const root = document.documentElement;
  const btn  = document.querySelector('[data-theme-toggle]');
  let theme  = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  root.setAttribute('data-theme', theme);
  if (btn) btn.addEventListener('click', () => {
    theme = theme === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', theme);
  });
})();

// ── Helpers globaux ───────────────────────────────────────
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.hidden = true;
}

// Fermer modale en cliquant sur l'overlay
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.hidden = true;
  }
});

// Fermer avec Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay:not([hidden])').forEach(m => m.hidden = true);
  }
});


//  toggle vue séances + persistance dans l'URL
function setView(v) {
    document.getElementById('viewList').hidden = (v === 'week');
    document.getElementById('viewWeek').hidden = (v === 'list');
    document.getElementById('btnList').classList.toggle('active', v === 'list');
    document.getElementById('btnWeek').classList.toggle('active', v === 'week');
    const url = new URL(location.href);
    url.searchParams.set('view', v);
    history.replaceState(null, '', url);
}
// Restaurer la vue depuis l'URL
const initView = new URLSearchParams(location.search).get('view') || 'list';
setView(initView);