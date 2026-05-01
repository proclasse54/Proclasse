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
  if (el) el.classList.remove('is-open');
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
    const viewList = document.getElementById('viewList');
    const viewWeek = document.getElementById('viewWeek');
    if (!viewList || !viewWeek) return; // pas sur la page séances → on sort
    viewList.hidden = (v === 'week');
    viewWeek.hidden = (v === 'list');
    const btnList = document.getElementById('btnList');
    const btnWeek = document.getElementById('btnWeek');
    if (btnList) btnList.classList.toggle('active', v === 'list');
    if (btnWeek) btnWeek.classList.toggle('active', v === 'week');
    const url = new URL(location.href);
    url.searchParams.set('view', v);
    history.replaceState(null, '', url);
}
setView(new URLSearchParams(location.search).get('view') || 'week');


// ── Modale infos élève (live.php) ─────────────────────────
const LONG_PRESS_DELAY = 600;
let longPressTimer = null;
let modalStudentId = null;
let modalSeatEl    = null;

function initStudentModal() {
  const modal     = document.getElementById('studentModal');
  const modalBody = document.getElementById('modalBody');
  const liveRoom  = document.getElementById('liveRoom');
  if (!modal || !liveRoom) return; // pas sur la page live

  async function openStudentModal(studentId, seatEl) {
    modalStudentId = studentId;
    modalSeatEl    = seatEl;
    modal.hidden   = false;
    document.getElementById('modalStudentName').textContent = '…';
    document.getElementById('modalClass').textContent = '';
    document.getElementById('modalAvatar').innerHTML  = '';
    modalBody.innerHTML = '<div class="student-modal-loading">Chargement…</div>';
    try {
      const r    = await fetch(`/api/students/${studentId}`);
      const data = await r.json();
      if (!r.ok) throw new Error(data.error ?? 'Erreur');
      renderModal(data);
    } catch(e) {
      modalBody.innerHTML = `<p style="color:var(--color-error)">Erreur : ${e.message}</p>`;
    }
  }

  function renderModal(s) {
    document.getElementById('modalStudentName').textContent = `${s.first_name} ${s.last_name}`;
    document.getElementById('modalClass').textContent = s.class_name + (s.level ? ' — ' + s.level : '');

    const av = document.getElementById('modalAvatar');
    if (modalSeatEl) {
      const img = modalSeatEl.querySelector('.seat-photo');
      if (img) { const c = img.cloneNode(); c.style.cssText = ''; av.appendChild(c); }
      else av.textContent = (s.first_name[0] ?? '') + (s.last_name[0] ?? '');
    }

    const fmt = d => { if (!d) return ''; const [y,m,j] = d.split('-'); return `${j}/${m}/${y}`; };

    const sections = [
      { titre: 'Identité', champs: [
        ['Genre',            s.gender === 'M' ? 'Masculin' : s.gender === 'F' ? 'Féminin' : null],
        ['Prénom d\'usage',  s.first_name_usage],
        ['Autres prénoms',   [s.first_name_2, s.first_name_3].filter(Boolean).join(', ') || null],
        ['Naissance',        [s.birthdate ? fmt(s.birthdate) : null, s.birthplace, s.birth_country].filter(Boolean).join(', ') || null],
        ['Nationalité',      s.nationality],
        ['INE',              s.pronote_id],
        ['Majeur',           s.is_major ? 'Oui' : null],
      ]},
      { titre: 'Contact', champs: [
        ['Email', s.email ? `<a href="mailto:${s.email}">${s.email}</a>` : null],
        ['Tél.',  s.phone],
      ]},
      { titre: 'Scolarité', champs: [
        ['Formation',        s.formation],
        ['Régime',           s.regime],
        ['Prof. principal',  s.head_teacher],
        ['Début scolarité',  s.school_start ? fmt(s.school_start) : null],
        ['Fin scolarité',    s.school_end   ? fmt(s.school_end)   : null],
        ['Redoublant',       s.is_repeating ? 'Oui' : null],
        ['Projet accom.',    s.support_project],
        ['Allergies',        s.allergies],
      ]},
      { titre: 'Groupes & options', champs: [
        ['Groupes', s.groups],
        ['Options', s.options],
      ]},
    ];

    let html = '';
    for (const sec of sections) {
      const rows = sec.champs.filter(([,v]) => v)
        .map(([k,v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('');
      if (!rows) continue;
      html += `<div class="modal-section">
        <div class="modal-section-title">${sec.titre}</div>
        <table class="modal-table">${rows}</table>
      </div>`;
    }

    // ── Données brutes Pronote (section dépliable) ──────────
    if (s.pronote_data && s.pronote_data.length > 0) {
      const rawRows = s.pronote_data
        .filter(r => r.field_value)
        .map(r => `<tr><th>${escHtml(r.field_name)}</th><td>${escHtml(r.field_value)}</td></tr>`)
        .join('');

      if (rawRows) {
        html += `
        <div class="modal-section">
          <details class="modal-details">
            <summary class="modal-section-title modal-details-summary">
              <span>Données brutes Pronote</span>
              <span class="modal-details-icon">＋</span>
            </summary>
            <table class="modal-table" style="margin-top:.5rem">${rawRows}</table>
          </details>
        </div>`;
      }
    }

    modalBody.innerHTML = html || '<p style="color:var(--color-text-muted)">Aucune info disponible.</p>';
  }

  function escHtml(str) {
    return String(str ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }


  function closeModal() {
    modal.hidden = true;
    modalStudentId = null;
    modalSeatEl    = null;
  }

  document.getElementById('modalRemoveBtn').addEventListener('click', async () => {
    if (!modalStudentId) return;
    if (!confirm('Retirer cet élève du plan de salle ?')) return;
    const SESSION_ID = window.SESSION_ID;
    const r = await fetch(`/api/sessions/${SESSION_ID}/remove-student/${modalStudentId}`, { method: 'DELETE' });
    const d = await r.json();
    if (!d.ok) { alert('Erreur : ' + (d.error ?? '?')); return; }
    if (modalSeatEl) {
      modalSeatEl.innerHTML           = '<div class="seat-empty-label">—</div>';
      modalSeatEl.dataset.studentId   = '';
      modalSeatEl.dataset.studentName = '';
      modalSeatEl.className           = 'live-seat empty';
      modalSeatEl.draggable           = false;
      if (window.seatStudentMap) seatStudentMap[parseInt(modalSeatEl.dataset.seatId)] = null;
    }
    closeModal();
    if (typeof clearSelection === 'function') clearSelection();
  });

  document.getElementById('modalClose').addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

  // Double-clic PC
  liveRoom.addEventListener('dblclick', e => {
    if (e.target.closest('.tag-chip')) return;
    const seat = e.target.closest('.live-seat.occupied');
    if (!seat) return;
    openStudentModal(parseInt(seat.dataset.studentId), seat);
  });

  // Appui long tactile
  liveRoom.addEventListener('touchstart', e => {
    if (e.target.closest('.tag-chip')) return;
    const seat = e.target.closest('.live-seat.occupied');
    if (!seat) return;
    longPressTimer = setTimeout(() => {
      longPressTimer = null;
      openStudentModal(parseInt(seat.dataset.studentId), seat);
    }, LONG_PRESS_DELAY);
  }, { passive: true });

  liveRoom.addEventListener('touchend',    () => { clearTimeout(longPressTimer); longPressTimer = null; });
  liveRoom.addEventListener('touchcancel', () => { clearTimeout(longPressTimer); longPressTimer = null; });
  liveRoom.addEventListener('touchmove',   () => { clearTimeout(longPressTimer); longPressTimer = null; });
}

document.addEventListener('DOMContentLoaded', initStudentModal);