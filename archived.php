<?php require_once __DIR__ . '/includes/auth.php'; require_login();
if (is_chair()) { header('Location: chair.php'); exit; }
if (is_admin()) { header('Location: admin.php'); exit; }
$me = db()->prepare('SELECT full_name FROM teachers WHERE id=?');
$me->execute([current_teacher_id()]);
$teacherName = $me->fetchColumn();
$_pageSubtitle = school_settings()['system_subtitle'] ?? 'GradeFlow';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Archived Classes — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* ── Folder grid ── */
.folder-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 10px;
}

/* ── Folder card (year or semester) ── */
.folder-card {
  background: var(--card);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 20px 18px 16px;
  cursor: pointer;
  transition: box-shadow .15s, transform .12s, border-color .15s;
  display: flex; flex-direction: column; gap: 8px;
  text-align: center; user-select: none;
}
.folder-card:hover {
  box-shadow: 0 4px 18px rgba(0,0,0,.11);
  transform: translateY(-2px);
  border-color: var(--amber);
}
.folder-icon {
  font-size: 2.6rem; line-height: 1;
  margin-bottom: 2px;
}
.folder-title  { font-weight: 700; font-size: 1rem; color: var(--ink); }
.folder-sub    { font-size: .8rem; color: var(--ink-soft); }
.folder-count  { font-size: .78rem; font-weight: 600; color: var(--amber); }

/* ── Breadcrumb ── */
.breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: .86rem; color: var(--ink-soft);
  margin-bottom: 20px; flex-wrap: wrap;
}
.breadcrumb a {
  color: var(--amber); text-decoration: none; font-weight: 600;
}
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb-sep { color: var(--line); }
.breadcrumb-cur { color: var(--ink); font-weight: 600; }

/* ── Archived badge on card ── */
.pill-archived { background: #e8e8e8; color: #666; }

/* ── Empty state ── */
.empty-archive {
  text-align: center; padding: 60px 20px;
  color: var(--ink-soft);
}
.empty-archive .big-icon { font-size: 3.5rem; margin-bottom: 12px; }
.empty-archive .big-label { font-size: 1.15rem; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="wrap">

  <div class="page-head">
    <div>
      <h1>&#128196; Archived Classes</h1>
      <div class="sub">All data is preserved. Open any class to view grades, duplicate its structure, or restore it to your active list.</div>
    </div>
    <a href="dashboard.php" class="btn btn-ghost">&#8592; My Classes</a>
  </div>

  <!-- Breadcrumb (updated by JS) -->
  <div class="breadcrumb" id="breadcrumb"></div>

  <!-- Main content area (folders or class cards) -->
  <div id="mainContent">
    <div class="empty"><div class="spinner"></div> Loading…</div>
  </div>

</div>

<!-- Delete Class confirmation modal -->
<div class="modal-backdrop" id="deleteClassModal">
  <div class="modal" style="max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0;color:var(--red)">&#9888; Delete Class</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('deleteClassModal')">&#10005; Close</button>
    </div>
    <p id="delClassMsg" style="margin:0 0 6px;font-size:.95rem"></p>
    <p style="margin:0 0 16px;font-size:.87rem;color:var(--red);font-weight:600">
      This CANNOT be undone. All students, scores, and grades will be permanently deleted.
    </p>
    <div class="field">
      <label>Enter your password to confirm</label>
      <input type="password" id="delClassPass" placeholder="Your password"
        autocomplete="current-password"
        onkeydown="if(event.key==='Enter') commitDelClass()">
    </div>
    <div id="delClassErr" style="display:none;color:var(--red);font-size:.85rem;
         padding:8px 12px;background:#fdecea;border-radius:8px;margin-bottom:10px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('deleteClassModal')">Cancel</button>
      <button class="btn btn-primary" id="delClassBtn"
        style="background:var(--red);border-color:var(--red)"
        onclick="commitDelClass()">&#128465; Delete Class</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
// ─── State ──────────────────────────────────────────────────────────
let ALL_ARCHIVED = [];   // flat array from API
let VIEW = 'years';      // 'years' | 'sems' | 'classes'
let SEL_YEAR = null;
let SEL_SEM  = null;

// ─── Load once ──────────────────────────────────────────────────────
async function init() {
  const r = await api('classes');
  ALL_ARCHIVED = r.archived || [];
  showYears();
}

// ─── Group helpers ───────────────────────────────────────────────────
function getYears() {
  const map = new Map();
  ALL_ARCHIVED.forEach(c => {
    const y = c.school_year || 'No Year';
    map.set(y, (map.get(y) || 0) + 1);
  });
  // Sort newest first
  return [...map.entries()].sort((a, b) => b[0].localeCompare(a[0]));
}

function getSems(year) {
  const classes = ALL_ARCHIVED.filter(c => (c.school_year || 'No Year') === year);
  const map = new Map();
  classes.forEach(c => {
    const s = c.semester || 'No Semester';
    map.set(s, (map.get(s) || 0) + 1);
  });
  const order = s => s.startsWith('1') ? 0 : s.startsWith('2') ? 1 : 2;
  return [...map.entries()].sort((a, b) => order(a[0]) - order(b[0]));
}

function getClasses(year, sem) {
  return ALL_ARCHIVED.filter(c =>
    (c.school_year || 'No Year') === year &&
    (c.semester    || 'No Semester') === sem
  );
}

// ─── Breadcrumb ─────────────────────────────────────────────────────
function renderBreadcrumb() {
  const bc = document.getElementById('breadcrumb');
  if (VIEW === 'years') { bc.innerHTML = ''; return; }

  let html = `<a href="#" onclick="showYears();return false">All Archives</a>`;
  if (VIEW === 'sems' || VIEW === 'classes') {
    html += `<span class="breadcrumb-sep">&#9656;</span>`;
    if (VIEW === 'classes') {
      html += `<a href="#" onclick="showSems('${esc(SEL_YEAR)}');return false">${esc(SEL_YEAR)}</a>`;
      html += `<span class="breadcrumb-sep">&#9656;</span>`;
      html += `<span class="breadcrumb-cur">${esc(SEL_SEM)}</span>`;
    } else {
      html += `<span class="breadcrumb-cur">${esc(SEL_YEAR)}</span>`;
    }
  }
  bc.innerHTML = html;
}

// ─── Views ──────────────────────────────────────────────────────────
function showYears() {
  VIEW = 'years'; SEL_YEAR = null; SEL_SEM = null;
  renderBreadcrumb();
  const years = getYears();
  const el = document.getElementById('mainContent');

  if (!years.length) {
    el.innerHTML = `
      <div class="empty-archive">
        <div class="big-icon">&#128196;</div>
        <div class="big-label">No archived classes yet</div>
        <div>Use the Archive button on any class to move it here.</div>
      </div>`;
    return;
  }

  el.innerHTML = `
    <div class="folder-grid">
      ${years.map(([year, count]) => `
        <div class="folder-card" onclick="showSems('${esc(year)}')">
          <div class="folder-icon">&#128193;</div>
          <div class="folder-title">${esc(year)}</div>
          <div class="folder-count">${count} class${count!==1?'es':''}</div>
        </div>`).join('')}
    </div>`;
}

function showSems(year) {
  VIEW = 'sems'; SEL_YEAR = year; SEL_SEM = null;
  renderBreadcrumb();
  const sems = getSems(year);
  const el = document.getElementById('mainContent');

  el.innerHTML = `
    <div class="folder-grid">
      ${sems.map(([sem, count]) => `
        <div class="folder-card" onclick="showClasses('${esc(year)}','${esc(sem)}')">
          <div class="folder-icon">&#128194;</div>
          <div class="folder-title">${esc(sem)}</div>
          <div class="folder-sub">${esc(year)}</div>
          <div class="folder-count">${count} class${count!==1?'es':''}</div>
        </div>`).join('')}
    </div>`;
}

function showClasses(year, sem) {
  VIEW = 'classes'; SEL_YEAR = year; SEL_SEM = sem;
  renderBreadcrumb();
  const classes = getClasses(year, sem);
  const el = document.getElementById('mainContent');

  el.innerHTML = `
    <div id="archiveClassGrid">
      ${classes.map(c => archivedCard(c)).join('')}
    </div>`;
}

// ─── Archived class card ─────────────────────────────────────────────
function archivedCard(c) {
  return `
    <div class="card" id="cc_${c.id}">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px">
          <div style="min-width:0;flex:1">
            <h2 style="margin:0 0 2px;font-size:1.1rem;line-height:1.35;word-break:break-word">${esc(c.subject_name)}</h2>
            <div class="muted" style="font-size:.82rem">${esc(c.subject_code||'')}${c.section?' &middot; '+esc(c.section):''}</div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0">
            <span class="pill pill-blue" style="white-space:nowrap">${c.student_count} student${c.student_count==1?'':'s'}</span>
            <span class="pill pill-archived" style="font-size:.72rem">Archived</span>
          </div>
        </div>
        <div class="muted" style="font-size:.84rem">${esc(c.term_system)} &middot; ${esc(c.school_year||'')}${c.semester?' &middot; '+esc(c.semester):''}</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--line);margin-top:14px;align-items:center">
        <a class="btn btn-dark btn-sm" href="gradebook.php?class_id=${c.id}">&#128065; View Grades</a>
        <button class="btn btn-ghost btn-sm" title="Copy structure — no students or scores" onclick="dupClass(${c.id},'${esc(c.subject_name)}')">&#x2398; Duplicate</button>
        <button class="btn-unarchive" onclick="unarchiveClass(${c.id},'${esc(c.subject_name)}')">&#8635; Unarchive</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="delClass(${c.id},'${esc(c.subject_name)}')">Delete</button>
      </div>
    </div>`;
}

// ─── Unarchive ───────────────────────────────────────────────────────
async function unarchiveClass(id, name) {
  await api('unarchive_class', { id });
  // Remove from local array and refresh current view
  ALL_ARCHIVED = ALL_ARCHIVED.filter(c => c.id !== id && c.id !== +id);
  toast(`"${name}" restored to My Classes`);
  // Re-render — if folder is now empty, go up a level
  if (VIEW === 'classes') {
    const remaining = getClasses(SEL_YEAR, SEL_SEM);
    if (!remaining.length) {
      const sems = getSems(SEL_YEAR);
      if (!sems.length) showYears(); else showSems(SEL_YEAR);
    } else {
      showClasses(SEL_YEAR, SEL_SEM);
    }
  } else if (VIEW === 'sems') {
    const sems = getSems(SEL_YEAR);
    if (!sems.length) showYears(); else showSems(SEL_YEAR);
  } else {
    showYears();
  }
}

// ─── Duplicate ───────────────────────────────────────────────────────
async function dupClass(id, name) {
  showConfirm({
    title: '⎘ Duplicate Class',
    message: `Duplicate "${name}"?\n\nCopies structure, criteria, and activities — NOT students or scores.`,
    confirmText: 'Duplicate',
    onConfirm: async () => {
      const r = await api('duplicate_class', { class_id: id });
      if (r.ok) toast(`"${r.name}" created in My Classes`);
    }
  });
}

// ─── Delete ──────────────────────────────────────────────────────────
let _delClassId = null;
function delClass(id, name) {
  _delClassId = id;
  document.getElementById('delClassMsg').textContent = `Delete class "${name}" and ALL its data?`;
  document.getElementById('delClassPass').value = '';
  document.getElementById('delClassErr').style.display = 'none';
  document.getElementById('deleteClassModal').classList.add('show');
  setTimeout(() => document.getElementById('delClassPass').focus(), 80);
}

async function commitDelClass() {
  const pw  = document.getElementById('delClassPass').value;
  const err = document.getElementById('delClassErr');
  const btn = document.getElementById('delClassBtn');
  err.style.display = 'none';
  if (!pw.trim()) { err.textContent = 'Password is required.'; err.style.display = ''; document.getElementById('delClassPass').focus(); return; }
  btn.disabled = true; btn.textContent = 'Verifying…';
  const vr = await api('verify_password', { password: pw });
  if (!vr.ok) {
    err.textContent = 'Incorrect password. Class was NOT deleted.';
    err.style.display = '';
    btn.disabled = false; btn.textContent = '🗑 Delete Class';
    document.getElementById('delClassPass').select();
    return;
  }
  await api('delete_class', { id: _delClassId });
  ALL_ARCHIVED = ALL_ARCHIVED.filter(c => c.id !== _delClassId && c.id !== +_delClassId);
  closeModal('deleteClassModal');
  _delClassId = null;
  toast('Class deleted');
  btn.disabled = false; btn.textContent = '🗑 Delete Class';
  if (VIEW === 'classes') {
    const remaining = getClasses(SEL_YEAR, SEL_SEM);
    if (!remaining.length) {
      const sems = getSems(SEL_YEAR);
      if (!sems.length) showYears(); else showSems(SEL_YEAR);
    } else {
      showClasses(SEL_YEAR, SEL_SEM);
    }
  } else {
    showYears();
  }
}

init();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body>
</html>
