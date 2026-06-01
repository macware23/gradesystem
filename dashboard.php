<?php require_once __DIR__ . '/includes/auth.php'; require_login();
// Chairs have their own dashboard — they do not manage classes
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
<title>My Classes — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="wrap">
  <div class="page-head">
    <div>
      <h1>My Classes</h1>
      <div class="sub">Set up each class with its own criteria and weights. Nothing is fixed — build it to fit the subject.</div>
    </div>
    <button class="btn btn-primary" onclick="openClassModal()">+ New Class</button>
  </div>

  <!-- Active classes (original flat grid) -->
  <div id="classList">
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

<!-- Class modal -->
<div class="modal-backdrop" id="classModal">
  <div class="modal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 id="cmTitle" style="margin:0">New Class</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('classModal')">&#10005; Close</button>
    </div>
    <input type="hidden" id="cm_id">
    <div class="row">
      <div class="field"><label>Subject Name *</label><input id="cm_name" placeholder="e.g. Data Structures"></div>
      <div class="field" style="flex:.5"><label>Code</label><input id="cm_code" placeholder="CS201"></div>
    </div>
    <div class="row">
      <div class="field"><label>Section</label><input id="cm_section" placeholder="BSCS-2A"></div>
      <div class="field"><label>School Year</label><input id="cm_year" placeholder="2025-2026"></div>
      <div class="field">
        <label>Semester</label>
        <select id="cm_semester">
          <option value="">— Select —</option>
          <option value="1st Semester">1st Semester</option>
          <option value="2nd Semester">2nd Semester</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="field"><label>Terms (comma-separated)</label><input id="cm_terms" value="Prelim,Midterm,Finals"></div>
      <div class="field" style="flex:.5"><label>Passing Grade</label><input id="cm_pass" type="number" step="0.01" value="75"></div>
    </div>
    <div class="help-note">Terms define your grading periods. Examples: <em>Prelim,Midterm,Finals</em> or <em>Endterm</em> or just <em>Semester</em>.</div>
    <div class="row" style="margin-top:14px;align-items:flex-end">
      <div class="field" style="flex:0 0 100%"><label style="font-weight:600;color:var(--ink)">Transmutation (your school's grade scale)</label>
        <div class="help-note" style="margin-bottom:10px">When ON, raw scores are converted to grade equivalents. Turn OFF for plain percentage grading.</div></div>
      <div class="field" style="flex:.6"><label>Transmute?</label>
        <select id="cm_transmute"><option value="1">Yes (transmutation table)</option><option value="0">No (plain %)</option></select></div>
      <div class="field" style="flex:.5"><label>Cut Off</label><input id="cm_cutoff" type="number" step="0.01" value="50"></div>
      <div class="field" style="flex:.5"><label>Zero Equivalent</label><input id="cm_zero" type="number" step="0.01" value="65"></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button class="btn btn-ghost" onclick="closeModal('classModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveClass()">Save Class</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
let dragSrcId = null;

// ─────────────────────────────────────────────────────────────────────
// Active class card — original design + Archive button
// ─────────────────────────────────────────────────────────────────────
function activeCard(c) {
  return `
    <div class="card class-card" id="cc_${c.id}" data-id="${c.id}" draggable="true">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px">
          <div style="display:flex;align-items:flex-start;gap:6px;flex:1;min-width:0">
            <span class="drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
            <div style="min-width:0;flex:1">
              <h2 style="margin:0 0 2px;font-size:1.15rem;line-height:1.35;word-break:break-word">${esc(c.subject_name)}</h2>
              <div class="muted" style="font-size:.82rem">${esc(c.subject_code||'')}${c.section?' &middot; '+esc(c.section):''}</div>
            </div>
          </div>
          <span class="pill pill-blue" style="flex-shrink:0;white-space:nowrap">${c.student_count} student${c.student_count==1?'':'s'}</span>
        </div>
        <div class="muted" style="font-size:.85rem">${esc(c.term_system)} &middot; ${esc(c.school_year||'')}${c.semester?' &middot; '+esc(c.semester):''}</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--line);margin-top:14px;align-items:center">
        <a class="btn btn-dark btn-sm" href="gradebook.php?class_id=${c.id}">Open Gradebook</a>
        <button class="btn btn-ghost btn-sm" onclick='openClassModal(${JSON.stringify(c)})'>Edit</button>
        <button class="btn btn-ghost btn-sm" title="Copy structure — no students or scores" onclick="dupClass(${c.id},'${esc(c.subject_name)}')">&#x2398; Duplicate</button>
        <button class="btn-archive" onclick="archiveClass(${c.id},'${esc(c.subject_name)}')">&#128196; Archive</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="delClass(${c.id},'${esc(c.subject_name)}')">Delete</button>
      </div>
    </div>`;
}

// ─────────────────────────────────────────────────────────────────────
// Load and render
// ─────────────────────────────────────────────────────────────────────
async function loadClasses() {
  const r  = await api('classes');
  const cl = document.getElementById('classList');

  // Active — original flat grid, identical to before
  if (!r.classes.length) {
    cl.innerHTML = `<div class="empty" style="grid-column:1/-1">
      <div class="big">No classes yet</div>
      <div>Create your first class to start grading.</div>
    </div>`;
  } else {
    cl.innerHTML = r.classes.map(c => activeCard(c)).join('');
  }

  // Drag-and-drop on active cards (identical to original)
  document.querySelectorAll('#classList .class-card').forEach(card => {
    card.addEventListener('dragstart', onDragStart);
    card.addEventListener('dragend',   onDragEnd);
    card.addEventListener('dragover',  onDragOver);
    card.addEventListener('dragenter', onDragEnter);
    card.addEventListener('dragleave', onDragLeave);
    card.addEventListener('drop',      onDrop);
  });
}

// ─────────────────────────────────────────────────────────────────────
// Archive / Unarchive
// ─────────────────────────────────────────────────────────────────────
async function archiveClass(id, name) {
  showConfirm({
    title: '📁 Archive Class',
    message: `Archive "${name}"?\n\nThe class will be moved to the Archived section. All data is kept — you can unarchive it anytime.`,
    confirmText: 'Archive',
    onConfirm: async () => {
      await api('archive_class', { id });
      toast(`"${name}" archived`);
      loadClasses();
    }
  });
}

// ─────────────────────────────────────────────────────────────────────
// Drag-and-drop (active grid only — identical to original)
// ─────────────────────────────────────────────────────────────────────
function onDragStart(e) {
  dragSrcId = this.dataset.id;
  this.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', dragSrcId);
}
function onDragEnd() {
  this.classList.remove('dragging');
  document.querySelectorAll('#classList .class-card').forEach(c => c.classList.remove('drag-over'));
}
function onDragOver(e)  { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }
function onDragEnter(e) { e.preventDefault(); if (this.dataset.id !== dragSrcId) this.classList.add('drag-over'); }
function onDragLeave()  { this.classList.remove('drag-over'); }

async function onDrop(e) {
  e.preventDefault();
  this.classList.remove('drag-over');
  const targetId = this.dataset.id;
  if (!dragSrcId || dragSrcId === targetId) return;
  const grid    = document.getElementById('classList');
  const cards   = [...grid.querySelectorAll('.class-card')];
  const srcCard = grid.querySelector(`[data-id="${dragSrcId}"]`);
  const tgtCard = grid.querySelector(`[data-id="${targetId}"]`);
  if (!srcCard || !tgtCard) return;
  const srcIdx = cards.indexOf(srcCard);
  const tgtIdx = cards.indexOf(tgtCard);
  if (srcIdx < tgtIdx) tgtCard.after(srcCard); else tgtCard.before(srcCard);
  const newOrder = [...grid.querySelectorAll('.class-card')].map(c => c.dataset.id);
  await api('reorder_classes', { ids: newOrder });
  toast('Class order saved');
  dragSrcId = null;
}

// ─────────────────────────────────────────────────────────────────────
// Class modal — identical to original
// ─────────────────────────────────────────────────────────────────────
function openClassModal(c) {
  document.getElementById('cmTitle').textContent     = c ? 'Edit Class' : 'New Class';
  document.getElementById('cm_id').value             = c?.id || '';
  document.getElementById('cm_name').value           = c?.subject_name || '';
  document.getElementById('cm_code').value           = c?.subject_code || '';
  document.getElementById('cm_section').value        = c?.section || '';
  document.getElementById('cm_year').value           = c?.school_year || '';
  document.getElementById('cm_semester').value       = c?.semester || '';
  document.getElementById('cm_terms').value          = c?.term_system || 'Prelim,Midterm,Finals';
  document.getElementById('cm_pass').value           = c?.passing_grade || 75;
  document.getElementById('cm_transmute').value      = (c && c.use_transmutation != null) ? String(c.use_transmutation) : '1';
  document.getElementById('cm_cutoff').value         = c?.cutoff ?? 50;
  document.getElementById('cm_zero').value           = c?.zero_equiv ?? 65;
  document.getElementById('classModal').classList.add('show');
}

async function saveClass() {
  const name = document.getElementById('cm_name').value.trim();
  if (!name) { toast('Subject name is required'); return; }
  await api('save_class', {
    id:               document.getElementById('cm_id').value,
    subject_name:     name,
    subject_code:     document.getElementById('cm_code').value,
    section:          document.getElementById('cm_section').value,
    school_year:      document.getElementById('cm_year').value,
    semester:         document.getElementById('cm_semester').value,
    term_system:      document.getElementById('cm_terms').value,
    passing_grade:    document.getElementById('cm_pass').value,
    use_transmutation:document.getElementById('cm_transmute').value,
    cutoff:           document.getElementById('cm_cutoff').value,
    zero_equiv:       document.getElementById('cm_zero').value,
  });
  closeModal('classModal');
  toast('Class saved');
  loadClasses();
}

// ─────────────────────────────────────────────────────────────────────
// Delete — identical to original
// ─────────────────────────────────────────────────────────────────────
let _delClassId = null;
function delClass(id, name) {
  _delClassId = id;
  document.getElementById('delClassMsg').textContent = `Delete class "${name}" and ALL its data?`;
  document.getElementById('delClassPass').value      = '';
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
  closeModal('deleteClassModal');
  _delClassId = null;
  toast('Class deleted');
  loadClasses();
  btn.disabled = false; btn.textContent = '🗑 Delete Class';
}

// ─────────────────────────────────────────────────────────────────────
// Duplicate — identical to original
// ─────────────────────────────────────────────────────────────────────
async function dupClass(id, name) {
  showConfirm({
    title: '⎘ Duplicate Class',
    message: `Duplicate "${name}"?\n\nCopies structure, criteria, and activities — NOT students or scores.`,
    confirmText: 'Duplicate',
    onConfirm: async () => {
      const r = await api('duplicate_class', { class_id: id });
      if (r.ok) { toast(`"${r.name}" created — open it to add students`); loadClasses(); }
    }
  });
}

loadClasses();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body>
</html>
