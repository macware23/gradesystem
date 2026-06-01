/* GradeFlow — shared front-end helpers */

async function api(action, body) {
  const opts = { method: body ? 'POST' : 'GET', headers: {} };
  if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const url = 'api/data.php?action=' + encodeURIComponent(action) +
    (body && body.class_id ? '&class_id=' + body.class_id : '') +
    (!body && arguments[2] ? arguments[2] : '');
  const r = await fetch(url, opts);
  const j = await r.json();
  if (!j.ok) { toast(j.error || 'Something went wrong'); throw new Error(j.error || 'error'); }
  return j;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[m]));
}

let _toastTimer;
function toast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

function closeModal(id) { document.getElementById(id).classList.remove('show'); }

// Modals must ONLY close via their explicit close/cancel button.
// Block backdrop clicks from closing by stopping propagation ONLY when the
// click target is the backdrop itself (not any element inside the .modal).
document.addEventListener('click', function(e) {
  // If the click landed directly on a modal-backdrop (not inside the .modal), do nothing.
  // This prevents closing by clicking the dark overlay area.
  if (e.target.classList.contains('modal-backdrop')) {
    e.stopPropagation();
    // Do NOT close — user must click the explicit close button.
  }
});

// ── Reusable confirm modal (replaces window.confirm) ─────────────────
// Usage: showConfirm({ title, message, confirmText, danger, onConfirm })
// The modal will NOT close when clicking outside — only via Close/Cancel.

(function() {
  function inject() {
    if (document.getElementById('_gfConfirm')) return;
    const el = document.createElement('div');
    el.id = '_gfConfirm';
    el.className = 'modal-backdrop';
    el.innerHTML = `
      <div class="modal" style="max-width:420px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <h2 id="_gfConfirmTitle" style="margin:0;font-size:1.05rem"></h2>
          <button class="btn btn-ghost btn-sm"
            onclick="document.getElementById('_gfConfirm').classList.remove('show')">&#10005; Close</button>
        </div>
        <p id="_gfConfirmMsg" style="margin:0 0 20px;font-size:.92rem;line-height:1.55;
           color:var(--ink);white-space:pre-wrap"></p>
        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button class="btn btn-ghost"
            onclick="document.getElementById('_gfConfirm').classList.remove('show')">Cancel</button>
          <button id="_gfConfirmOk" class="btn btn-primary"></button>
        </div>
      </div>`;
    document.body.appendChild(el);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', inject);
  else inject();
})();

function showConfirm(opts) {
  const m  = document.getElementById('_gfConfirm');
  const ti = document.getElementById('_gfConfirmTitle');
  const ms = document.getElementById('_gfConfirmMsg');
  const ok = document.getElementById('_gfConfirmOk');
  ti.textContent     = opts.title       || 'Confirm';
  ms.textContent     = opts.message     || '';
  ok.textContent     = opts.confirmText || 'Confirm';
  ok.style.background  = opts.danger ? 'var(--red)' : '';
  ok.style.borderColor = opts.danger ? 'var(--red)' : '';
  ok.onclick = () => {
    m.classList.remove('show');
    if (opts.onConfirm) opts.onConfirm();
  };
  m.classList.add('show');
}
