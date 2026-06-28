<?php
require_once __DIR__ . '/includes/auth.php';
require_admin();
$me = db()->prepare('SELECT full_name FROM teachers WHERE id=?');
$me->execute([current_teacher_id()]);
$teacherName = $me->fetchColumn();
$_pageSubtitle = school_settings()['system_subtitle'] ?? 'GradeFlow';
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="wrap">
  <div class="page-head">
    <div><h1>Admin Dashboard</h1>
      <div class="sub">Browse teachers and their classes. Generate reports. Reset passwords if needed.</div>
    </div>
  </div>

  <div id="pendingBanner" style="display:none;background:#fff3cd;color:#856404;border:1px solid #ffc107;
    border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.9rem"></div>

  <!-- Search bar -->
  <div class="card" style="margin-bottom:20px;padding:16px">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <input id="searchBox" placeholder="Search by teacher name or email…"
        style="flex:1;min-width:220px" oninput="searchTeachers(this.value)">
      <button class="btn btn-ghost" onclick="searchTeachers('')">Show All</button>
      <span class="muted" id="resultCount"></span>
    </div>
  </div>

  <!-- Teacher list -->
  <div id="teacherListWrap">
    <div id="teacherList">
      <div class="empty"><div class="spinner"></div> Loading…</div>
    </div>
  </div>

  <!-- Teacher detail panel -->
  <div id="teacherDetail" style="display:none;margin-top:4px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
      <div>
        <h2 id="detailTitle" style="margin:0"></h2>
        <div id="detailMeta" class="muted" style="font-size:.88rem;margin-top:2px"></div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" id="resetPwBtn" onclick="openResetPassword()">
          &#128273; Reset Password
        </button>
        <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="openDeleteTeacher()">
          &#128465; Delete Account
        </button>
        <button class="btn btn-ghost" onclick="closeDetail()">← Back to list</button>
      </div>
    </div>
    <!-- Class search -->
    <div class="card" style="margin-bottom:16px;padding:12px 16px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input id="classSearchBox" placeholder="Search by class name, code, or section…"
          style="flex:1;min-width:200px" oninput="searchClasses(this.value)">
        <button class="btn btn-ghost btn-sm" onclick="searchClasses('')">Show All</button>
        <span class="muted" id="classResultCount" style="font-size:.84rem"></span>
      </div>
    </div>
    <div id="detailClasses" class="class-grid"></div>
  </div>
</div>

<!-- Delete Teacher Modal -->
<div class="modal-backdrop" id="deleteTeacherModal">
  <div class="modal" style="max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0;color:var(--red)">&#128465; Delete Teacher Account</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('deleteTeacherModal')">&#10005; Close</button>
    </div>
    <p id="delTeacherFor" class="muted" style="margin-top:-4px"></p>
    <div class="alert alert-error" style="margin-bottom:14px;font-size:.87rem">
      This permanently deletes the teacher account. The teacher must have <strong>no classes</strong> before deletion.
    </div>
    <div class="field">
      <label>Your Admin Password</label>
      <input type="password" id="del_admin_pass" placeholder="Enter your admin password"
        autocomplete="current-password">
    </div>
    <div id="delTeacherErr" class="alert alert-error" style="display:none;margin-bottom:12px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('deleteTeacherModal')">Cancel</button>
      <button class="btn btn-primary" style="background:var(--red);border-color:var(--red)"
        onclick="commitDeleteTeacher()">&#128465; Delete Account</button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-backdrop" id="resetPwModal">
  <div class="modal" style="max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0">&#128273; Reset Teacher Password</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('resetPwModal')">&#10005; Close</button>
    </div>
    <p class="muted" id="resetPwFor" style="margin-top:-4px"></p>
    <div class="field">
      <label>New Password <span class="muted">(min 6 characters)</span></label>
      <input type="password" id="rp_pass" placeholder="New password" autocomplete="new-password">
    </div>
    <div class="field">
      <label>Confirm Password</label>
      <input type="password" id="rp_pass2" placeholder="Repeat new password" autocomplete="new-password">
    </div>
    <div id="rpErr" class="alert alert-error" style="display:none;margin-bottom:12px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('resetPwModal')">Cancel</button>
      <button class="btn btn-primary" onclick="commitResetPassword()">&#10003; Set New Password</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
let allTeachers = [];
let currentTeacherId = null;
let currentTeacherName = '';
let currentTeacherClasses = [];

async function loadTeachers() {
  const r = await fetch('api/admin.php?action=teachers').then(r=>r.json());
  allTeachers = r.teachers || [];
  if (r.pending_count > 0) {
    document.getElementById('pendingBanner').style.display = '';
    document.getElementById('pendingBanner').innerHTML =
      `⏳ <strong>${r.pending_count} teacher${r.pending_count!==1?'s':''} pending approval</strong>
       — scroll down to review or use the search above.`;
  } else {
    document.getElementById('pendingBanner').style.display = 'none';
  }
  renderTeachers(allTeachers);
}

async function approveTeacher(tid, name) {
  showConfirm({
    title: 'Approve Teacher',
    message: `Approve "${name}" as a teacher?`,
    confirmText: 'Approve',
    onConfirm: async () => {
      const r = await fetch('api/admin.php?action=approve_teacher', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({teacher_id: tid})
      }).then(r=>r.json());
      if (r.ok) { toast(`${name} approved`); loadTeachers(); }
      else toast('Error: ' + (r.error||'Unknown'));
    }
  });
}

async function rejectTeacher(tid, name) {
  showConfirm({
    title: 'Reject Registration',
    message: `Reject and delete "${name}"'s registration?\nThis cannot be undone.`,
    confirmText: 'Reject & Delete', danger: true,
    onConfirm: async () => {
      const r = await fetch('api/admin.php?action=reject_teacher', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({teacher_id: tid})
      }).then(r=>r.json());
      if (r.ok) { toast(`${name} rejected and removed`); loadTeachers(); }
      else toast('Error: ' + (r.error||'Unknown'));
    }
  });
}

function renderTeachers(list) {
  document.getElementById('resultCount').textContent =
    `${list.length} teacher${list.length!==1?'s':''}`;
  const el = document.getElementById('teacherList');
  if (!list.length) {
    el.innerHTML = '<div class="empty"><div class="big">No teachers found</div></div>';
    return;
  }
  el.innerHTML = list.map(t => {
    const pending = !+t.approved;
    // Initials avatar
    const initials = t.full_name.trim().split(/\s+/).slice(0,2).map(w=>w[0].toUpperCase()).join('');
    const avatarBg = pending ? '#ffc107' : 'var(--ink)';
    const avatarTxt = pending ? '#000' : 'var(--paper)';

    const statusBadge = pending
      ? `<span style="display:inline-block;font-size:.60rem;background:#fff3cd;color:#856404;padding:2px 6px;font-weight:600">⏳ Pending</span>`
      : `<span style="display:inline-block;font-size:.60rem;background:#d4edda;color:#155724;padding:2px 6px;font-weight:600">✓ Approved</span>`;

    const actionBtn = pending
      ? `<div style="display:flex;gap:4px;margin-top:8px">
           <button style="flex:1;font-size:.65rem;padding:4px 0;background:var(--amber);color:#fff;border:none;cursor:pointer;font-weight:600"
             onclick="event.stopPropagation();approveTeacher(${t.id},'${esc(t.full_name)}')">Approve</button>
           <button style="flex:1;font-size:.65rem;padding:4px 0;background:#f8d7da;color:#721c24;border:none;cursor:pointer"
             onclick="event.stopPropagation();rejectTeacher(${t.id},'${esc(t.full_name)}')">Reject</button>
         </div>`
      : `<div style="margin-top:8px">
           <button style="width:100%;font-size:.65rem;padding:4px 0;background:var(--ink);color:var(--paper);border:none;cursor:pointer;font-weight:600;margin-bottom:3px"
             onclick="event.stopPropagation();viewTeacher(${t.id},'${esc(t.full_name)}','${esc(t.email)}')">View Reports</button>
           <button style="width:100%;font-size:.65rem;padding:4px 0;background:transparent;color:var(--ink-soft);border:1px solid var(--line);cursor:pointer"
             onclick="event.stopPropagation();currentTeacherId=${t.id};currentTeacherName='${esc(t.full_name)}';openResetPassword()">🔑 Reset PW</button>
         </div>`;

    return `<div class="teacher-tile ${pending?'pending':''}"
               onclick="${pending?'':` viewTeacher(${t.id},'${esc(t.full_name)}','${esc(t.email)}')`}">
      <!-- Avatar -->
      <div style="width:36px;height:36px;background:${avatarBg};color:${avatarTxt};
                  display:flex;align-items:center;justify-content:center;
                  font-size:.80rem;font-weight:700;margin-bottom:8px;flex-shrink:0">
        ${initials}
      </div>
      <!-- Name + email -->
      <div style="font-size:.78rem;font-weight:700;line-height:1.25;word-break:break-word;margin-bottom:2px">${esc(t.full_name)}</div>
      <div style="font-size:.62rem;color:var(--ink-soft);word-break:break-all;margin-bottom:4px">${esc(t.email)}</div>
      <!-- Stats -->
      <div style="font-size:.62rem;color:var(--ink-soft);margin-bottom:4px">
        ${t.class_count} class${t.class_count!=1?'es':''} · ${t.student_count} student${t.student_count!=1?'s':''}
      </div>
      <!-- Status badge -->
      ${statusBadge}
      <!-- Action buttons -->
      ${actionBtn}
    </div>`;
  }).join('');
}

function searchTeachers(q) {
  document.getElementById('searchBox').value = q;
  if (!q.trim()) { renderTeachers(allTeachers); return; }
  const lc = q.toLowerCase();
  renderTeachers(allTeachers.filter(t =>
    t.full_name.toLowerCase().includes(lc) || t.email.toLowerCase().includes(lc)));
}

function toggleFolder(header) {
  const body = header.nextElementSibling;
  const icon = header.querySelector('.fold-icon');
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : '';
  icon.textContent = isOpen ? '▶' : '▼';
  header.style.borderRadius = isOpen ? '10px' : '10px 10px 0 0';
}

function renderClassRow(c, isEven) {
  const terms = (c.term_system||'').split(',').map(t=>t.trim()).filter(Boolean);
  const reportBtns = [
    `<a href="api/report.php?class_id=${c.id}&type=final" target="_blank"
       style="font-size:.68rem;padding:3px 8px;border:1px solid var(--line);border-radius:5px;
              background:var(--paper);color:var(--ink);text-decoration:none;white-space:nowrap;
              transition:background .12s" onmouseover="this.style.background='var(--amber-soft)'"
       onmouseout="this.style.background='var(--paper)'">&#128196; Final</a>`,
    ...terms.map(t=>`<a href="api/report.php?class_id=${c.id}&type=term&term=${encodeURIComponent(t)}"
       target="_blank"
       style="font-size:.68rem;padding:3px 8px;border:1px solid var(--line);border-radius:5px;
              background:var(--paper);color:var(--ink);text-decoration:none;white-space:nowrap;
              transition:background .12s" onmouseover="this.style.background='var(--amber-soft)'"
       onmouseout="this.style.background='var(--paper)'">${esc(t)}</a>`)
  ].join('');
  const sem = c.semester
    ? `<span style="font-size:.64rem;background:var(--amber-soft);color:var(--amber);
                   padding:1px 7px;border-radius:20px;font-weight:600;white-space:nowrap;flex-shrink:0">${esc(c.semester)}</span>`
    : '';
  const rowBg = isEven ? 'var(--paper-2)' : 'var(--paper)';
  return `<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;
                      background:${rowBg};border-bottom:1px solid var(--line);flex-wrap:wrap">
    <!-- Subject info -->
    <div style="flex:1;min-width:160px">
      <div style="font-weight:600;font-size:.84rem;line-height:1.3;word-break:break-word">${esc(c.subject_name)}</div>
      <div style="font-size:.70rem;color:var(--ink-soft);margin-top:1px">
        ${[c.subject_code, c.section].filter(Boolean).map(esc).join(' &middot; ')}
        &nbsp;&middot;&nbsp; ${c.student_count} student${c.student_count!=1?'s':''}
        &nbsp;&middot;&nbsp; Passing ${(+c.passing_grade).toFixed(0)}
      </div>
    </div>
    <!-- Semester badge -->
    ${sem}
    <!-- Report buttons -->
    <div style="display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0">
      ${reportBtns}
    </div>
  </div>`;
}

async function viewTeacher(tid, name, email) {
  currentTeacherId = tid;
  currentTeacherName = name;
  currentTeacherClasses = [];
  document.getElementById('teacherListWrap').style.display = 'none';
  document.getElementById('teacherDetail').style.display = '';
  document.getElementById('detailTitle').textContent = name;
  document.getElementById('detailMeta').textContent = email;
  document.getElementById('classSearchBox').value = '';
  document.getElementById('classResultCount').textContent = '';
  document.getElementById('detailClasses').innerHTML =
    '<div class="empty"><div class="spinner"></div></div>';
  const r = await fetch(`api/admin.php?action=teacher_classes&teacher_id=${tid}`).then(r=>r.json());
  currentTeacherClasses = r.classes || [];
  renderDetailClasses(currentTeacherClasses);
}

function searchClasses(q) {
  document.getElementById('classSearchBox').value = q;
  if (!q.trim()) { renderDetailClasses(currentTeacherClasses); return; }
  const lc = q.toLowerCase();
  const filtered = currentTeacherClasses.filter(c =>
    (c.subject_name  || '').toLowerCase().includes(lc) ||
    (c.subject_code  || '').toLowerCase().includes(lc) ||
    (c.section       || '').toLowerCase().includes(lc)
  );
  renderDetailClasses(filtered, true);
}

function renderDetailClasses(classes, isFiltered = false) {
  const countEl = document.getElementById('classResultCount');
  if (!classes.length) {
    document.getElementById('detailClasses').innerHTML =
      `<div class="empty" style="grid-column:1/-1"><div class="big">${isFiltered ? 'No classes match that search' : 'No classes yet'}</div></div>`;
    countEl.textContent = isFiltered ? '0 results' : '';
    return;
  }

  if (isFiltered) {
    countEl.textContent = `${classes.length} result${classes.length !== 1 ? 's' : ''}`;
    // Flat list when searching — skip the A.Y. folder grouping
    document.getElementById('detailClasses').innerHTML =
      `<div style="grid-column:1/-1;border:1px solid var(--line);border-radius:10px;overflow:hidden">
        ${classes.map((c, i) => renderClassRow(c, i % 2 === 1)).join('')}
       </div>`;
    return;
  }

  countEl.textContent = `${classes.length} class${classes.length !== 1 ? 'es' : ''}`;
  // Group by school_year, sorted most-recent first
  const grouped = {};
  for (const c of classes) {
    const ay = c.school_year || 'Unknown';
    if (!grouped[ay]) grouped[ay] = [];
    grouped[ay].push(c);
  }
  const sortedYears = Object.keys(grouped).sort((a, b) => {
    const ya = parseInt(a) || 0, yb = parseInt(b) || 0;
    return yb - ya;
  });

  // Render as folders — first year open, rest collapsed
  document.getElementById('detailClasses').innerHTML = sortedYears.map((ay, idx) => {
    const items = grouped[ay];
    const count = items.length;
    const isFirst = idx === 0;
    return `
    <div style="margin-bottom:12px;grid-column:1/-1">
      <div onclick="toggleFolder(this)"
           style="display:flex;align-items:center;gap:10px;padding:11px 16px;
                  background:var(--ink);color:var(--paper);
                  border-radius:10px 10px 0 0;cursor:pointer;user-select:none;transition:background .15s">
        <span style="font-size:1.05rem">&#128193;</span>
        <span style="font-weight:700;font-size:.95rem;flex:1">A.Y. ${esc(ay)}</span>
        <span style="font-size:.78rem;opacity:.65">${count} class${count!==1?'es':''}</span>
        <span class="fold-icon" style="font-size:.75rem;opacity:.7;min-width:12px">${isFirst?'▼':'▶'}</span>
      </div>
      <div style="display:${isFirst?'':'none'};border:1px solid var(--line);border-top:none;
                  border-radius:0 0 10px 10px;overflow:hidden">
        ${items.map((c, i) => renderClassRow(c, i % 2 === 1)).join('')}
      </div>
    </div>`;
  }).join('');
}

function closeDetail() {
  currentTeacherId = null;
  currentTeacherClasses = [];
  document.getElementById('classSearchBox').value = '';
  document.getElementById('classResultCount').textContent = '';
  document.getElementById('teacherDetail').style.display = 'none';
  document.getElementById('teacherListWrap').style.display = '';
}

// ---- Delete Teacher ----
function openDeleteTeacher() {
  if (!currentTeacherId) return;
  document.getElementById('delTeacherFor').textContent =
    'Account to delete: ' + currentTeacherName;
  document.getElementById('del_admin_pass').value = '';
  document.getElementById('delTeacherErr').style.display = 'none';
  document.getElementById('deleteTeacherModal').classList.add('show');
  setTimeout(() => document.getElementById('del_admin_pass').focus(), 80);
}

async function commitDeleteTeacher() {
  const pass = document.getElementById('del_admin_pass').value;
  const err  = document.getElementById('delTeacherErr');
  err.style.display = 'none';
  if (!pass.trim()) { err.textContent = 'Password is required.'; err.style.display=''; return; }

  const r = await fetch('api/admin.php?action=delete_teacher', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ teacher_id: currentTeacherId, admin_password: pass })
  }).then(r=>r.json());

  if (r.ok) {
    closeModal('deleteTeacherModal');
    closeDetail();
    toast(currentTeacherName + ' account deleted');
    loadTeachers();
  } else {
    err.textContent = r.error || 'Failed to delete account';
    err.style.display = '';
  }
}
function openResetPassword() {
  if (!currentTeacherId) return;
  document.getElementById('resetPwFor').textContent =
    'Setting a new password for: ' + currentTeacherName;
  document.getElementById('rp_pass').value  = '';
  document.getElementById('rp_pass2').value = '';
  document.getElementById('rpErr').style.display = 'none';
  document.getElementById('resetPwModal').classList.add('show');
  setTimeout(() => document.getElementById('rp_pass').focus(), 80);
}

async function commitResetPassword() {
  const pw  = document.getElementById('rp_pass').value;
  const pw2 = document.getElementById('rp_pass2').value;
  const err = document.getElementById('rpErr');
  err.style.display = 'none';
  if (pw.length < 6) { err.textContent = 'Password must be at least 6 characters.'; err.style.display=''; return; }
  if (pw !== pw2)    { err.textContent = 'Passwords do not match.'; err.style.display=''; return; }

  const r = await fetch('api/admin.php?action=reset_teacher_password', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ teacher_id: currentTeacherId, password: pw })
  }).then(r=>r.json());

  if (r.ok) {
    closeModal('resetPwModal');
    toast('Password updated for ' + currentTeacherName);
  } else {
    err.textContent = r.error || 'Failed to update password';
    err.style.display = '';
  }
}

loadTeachers();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body></html>
