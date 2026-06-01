<?php
require_once __DIR__ . '/includes/auth.php';
require_chair();

$me = db()->prepare('SELECT full_name, college, department FROM teachers WHERE id=?');
$me->execute([current_teacher_id()]);
$meRow = $me->fetch();
$teacherName = $meRow['full_name'];

// All assignments for this chair
$asgStmt = db()->prepare(
    'SELECT college, department FROM chair_assignments WHERE chair_id=? ORDER BY id');
$asgStmt->execute([current_teacher_id()]);
$myAssignments = $asgStmt->fetchAll();
if (!$myAssignments && ($meRow['college'] || $meRow['department'])) {
    $myAssignments = [['college'=>$meRow['college'],'department'=>$meRow['department']]];
}
$subText = implode(' | ', array_map(fn($a) =>
    trim(($a['college'] ?? '') . ($a['department'] ? ' — '.$a['department'] : '')),
    $myAssignments));

$_pageSubtitle = school_settings()['system_subtitle'] ?? 'GradeFlow';
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Program Chair — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* ── Faculty card grid ── */
#facultyGrid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 16px; align-items: stretch;
}
#facultyGrid > .card {
  display: flex; flex-direction: column; margin: 0;
  transition: transform .13s, box-shadow .13s; cursor: pointer;
}
#facultyGrid > .card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,.10);
  border-color: var(--amber);
}

/* ── Class card grid — identical to admin ── */
#classGrid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 16px;
  align-items: stretch;
}
#classGrid > .card {
  display: flex;
  flex-direction: column;
  padding: 16px;
  margin: 0;
}
#classGrid > .card .card-body {
  flex: 1;
  padding: 0;
}

/* ── Faculty avatar ── */
.faculty-avatar {
  width: 46px; height: 46px; border-radius: 50%;
  background: var(--ink); color: var(--paper);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 1rem; flex-shrink: 0;
}

/* ── Status pills ── */
.pill-active  { background: #d4edda; color: #155724; }
.pill-pending { background: #fff3cd; color: #856404; }

/* ── Breadcrumb ── */
.chair-breadcrumb {
  display: flex; align-items: center; gap: 8px;
  font-size: .88rem; color: var(--ink-soft);
  margin-bottom: 18px; flex-wrap: wrap;
}
.chair-breadcrumb a { color: var(--amber); text-decoration: none; font-weight: 600; }
.chair-breadcrumb a:hover { text-decoration: underline; }
.chair-breadcrumb-sep { color: var(--line); }
.chair-breadcrumb-cur { color: var(--ink); font-weight: 600; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="wrap">

  <!-- ── Page header ── -->
  <div class="page-head">
    <div>
      <h1 id="pageTitle">&#128101; Faculty Overview</h1>
      <div class="sub" id="pageSub"><?= htmlspecialchars($subText ?: 'No assignments set') ?></div>
    </div>
  </div>

  <!-- ── Breadcrumb (shown when viewing a faculty's classes) ── -->
  <div class="chair-breadcrumb" id="breadcrumb" style="display:none"></div>

  <!-- ── Faculty view ── -->
  <div id="facultyView">
    <div id="facultyGrid">
      <div class="empty"><div class="spinner"></div> Loading faculty…</div>
    </div>
  </div>

  <!-- ── Classes of selected faculty ── -->
  <div id="classView" style="display:none">
    <div id="classGrid"></div>
  </div>

</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
// ── State ─────────────────────────────────────────────────────────
let allFaculty = [];
let selTeacher = null;

// ── Load faculty ──────────────────────────────────────────────────
async function loadFaculty() {
  const r = await fetch('api/admin.php?action=chair_faculty').then(r=>r.json());
  allFaculty = r.faculty || [];
  renderFaculty();
}

// ── Faculty card grid ─────────────────────────────────────────────
function renderFaculty() {
  document.getElementById('pageTitle').textContent = '👥 Faculty Overview';
  document.getElementById('pageSub').textContent   =
    `<?= htmlspecialchars($subText ?: 'No assignments set') ?>`;
  document.getElementById('breadcrumb').style.display = 'none';
  document.getElementById('facultyView').style.display = '';
  document.getElementById('classView').style.display   = 'none';

  const grid = document.getElementById('facultyGrid');
  if (!allFaculty.length) {
    grid.innerHTML = `<div class="empty" style="grid-column:1/-1">
      <div class="big">No faculty found</div>
      <div class="muted">No approved faculty match your assigned college and department.</div>
    </div>`;
    return;
  }

  grid.innerHTML = allFaculty.map(t => {
    const initials = t.full_name.trim().split(/\s+/)
      .slice(0,2).map(w => w[0]?.toUpperCase() || '').join('');
    const approved   = +t.approved;
    const classCount = +t.class_count;
    return `
      <div class="card" onclick="openFaculty(${t.id})">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px">
            <div class="faculty-avatar">${esc(initials)}</div>
            <div style="min-width:0;flex:1">
              <div style="font-weight:700;font-size:.95rem;margin-bottom:2px;word-break:break-word">${esc(t.full_name)}</div>
              <div class="muted" style="font-size:.78rem">${esc(t.email)}</div>
            </div>
          </div>
          <div class="muted" style="font-size:.8rem;margin-bottom:8px">
            ${esc(t.college||'—')}<br>
            <span style="color:var(--ink-soft)">${esc(t.department||'—')}</span>
          </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding-top:10px;border-top:1px solid var(--line)">
          <span class="pill ${approved?'pill-active':'pill-pending'}" style="font-size:.72rem">
            ${approved?'✓ Active':'⏳ Pending'}</span>
          <span class="muted" style="font-size:.8rem">${classCount} class${classCount!==1?'es':''}</span>
          <span style="font-size:.8rem;color:var(--amber);font-weight:600">View &#8594;</span>
        </div>
      </div>`;
  }).join('');
}

// ── Open faculty classes — same card design as admin ──────────────
async function openFaculty(tid) {
  selTeacher = allFaculty.find(t => t.id == tid) || {id:tid, full_name:'Faculty'};

  document.getElementById('facultyView').style.display = 'none';
  document.getElementById('classView').style.display   = '';
  document.getElementById('pageTitle').textContent = selTeacher.full_name;
  document.getElementById('pageSub').textContent   =
    selTeacher.email + (selTeacher.college ? ' · ' + selTeacher.college : '') +
    (selTeacher.department ? ' — ' + selTeacher.department : '');

  // Breadcrumb
  const bc = document.getElementById('breadcrumb');
  bc.style.display = '';
  bc.innerHTML = `
    <a href="#" onclick="goBack();return false">&#128101; Faculty Overview</a>
    <span class="chair-breadcrumb-sep">&#9656;</span>
    <span class="chair-breadcrumb-cur">${esc(selTeacher.full_name)}</span>`;

  const grid = document.getElementById('classGrid');
  grid.innerHTML = '<div class="empty"><div class="spinner"></div> Loading classes…</div>';

  const r = await fetch(`api/admin.php?action=chair_teacher_classes&teacher_id=${tid}`)
    .then(r => r.json());

  if (!r.ok) {
    grid.innerHTML = '<div class="empty"><div>Could not load classes.</div></div>';
    return;
  }

  const all = (r.classes||[]).concat(r.archived||[]);

  if (!all.length) {
    grid.innerHTML = `<div class="empty" style="grid-column:1/-1">
      <div class="big">No classes yet</div>
      <div class="muted">This faculty has not created any classes.</div>
    </div>`;
    return;
  }

  // ── Identical card design to admin.php ──────────────────────────
  grid.innerHTML = all.map(c => {
    const terms = (c.term_system||'').split(',').map(t=>t.trim()).filter(Boolean);
    const isArchived = +c.is_archived;

    const reportLinks = [
      `<a class="btn btn-ghost btn-sm" style="font-size:.75rem;padding:4px 10px"
          href="api/report.php?class_id=${c.id}&type=final" target="_blank">
          &#128196; Final PDF</a>`,
      ...terms.map(t =>
        `<a class="btn btn-ghost btn-sm" style="font-size:.75rem;padding:4px 10px"
            href="api/report.php?class_id=${c.id}&type=term&term=${encodeURIComponent(t)}"
            target="_blank">&#128196; ${esc(t)}</a>`)
    ].join('');

    return `
      <div class="card class-card">
        <div class="card-body">
          <h2 style="margin:0 0 2px;font-size:1rem;line-height:1.3;word-break:break-word;font-weight:700">
            ${esc(c.subject_name)}
            ${isArchived ? '<span class="pill pill-archived" style="font-size:.68rem;margin-left:4px;vertical-align:middle">Archived</span>' : ''}
          </h2>
          <div class="muted" style="font-size:.78rem;margin-bottom:6px">
            ${esc(c.subject_code||'')}${c.section?' &middot; '+esc(c.section):''}
          </div>
          <div class="muted" style="font-size:.78rem">
            ${c.student_count} student${c.student_count!=1?'s':''}
            &nbsp;&middot;&nbsp; ${esc(c.school_year||'')}
            &nbsp;&middot;&nbsp; Passing ${(+c.passing_grade).toFixed(0)}
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;
                    padding-top:10px;border-top:1px solid var(--line);margin-top:10px">
          ${reportLinks}
        </div>
      </div>`;
  }).join('');
}

// ── Back to faculty list ─────────────────────────────────────────
function goBack() {
  selTeacher = null;
  renderFaculty();
}

loadFaculty();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;
  padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">
  Copyright &copy; 2026 Arnel Maghinay. All rights reserved.
</footer>
</body></html>
