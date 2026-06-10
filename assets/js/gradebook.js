/* GradeFlow - Gradebook JS (transmutation + attendance + column paste) */

let DATA = null, activeTerm = null, SETTINGS = null;
const dirty = new Map();   // "sid:aid" -> raw value

// Guard: if READONLY is true (admin view), all write operations are blocked
const _readonly = typeof READONLY !== 'undefined' && READONLY;

/* ------------------------------------------------------------------ */
/* Transmutation (mirrors PHP/Python exactly)                          */
/* ------------------------------------------------------------------ */
function magsEquivalent(perfect, raw, cutoff, zeroequiv) {
  if (perfect <= 0) return zeroequiv;
  const mh = (100 - cutoff) / (100 - 75);
  const ml = cutoff / (75 - zeroequiv);
  const p = (raw / perfect) * 100;
  if (p >= cutoff) return 75 + (p - cutoff) / mh;
  if (p > 0)       return zeroequiv + (p / ml);
  return zeroequiv;
}
function equiv(rawStr, perfect) {
  if (rawStr === '' || rawStr === null || rawStr === undefined || isNaN(rawStr)) return null;
  const raw = +rawStr;
  if (SETTINGS.transmute) return Math.round(magsEquivalent(perfect, raw, SETTINGS.cutoff, SETTINGS.zero_equiv) * 100) / 100;
  return perfect > 0 ? Math.round((raw / perfect) * 10000) / 100 : null;
}

/* ------------------------------------------------------------------ */
/* Bootstrap                                                           */
/* ------------------------------------------------------------------ */
async function initGradebook() { await loadSheet(); }

async function loadSheet() {
  const r = await fetch('api/data.php?action=sheet&class_id=' + CLASS_ID);
  DATA = await r.json();
  if (!DATA.ok) { toast(DATA.error); return; }
  SETTINGS = DATA.settings;
  document.getElementById('className').textContent = DATA.class.subject_name;
  // Build labeled meta: Course Code | Course & Section | Academic Year
  const metaParts = [];
  if (DATA.class.subject_code) metaParts.push(`Course Code: ${DATA.class.subject_code}`);
  if (DATA.class.section)      metaParts.push(`Course &amp; Section: ${DATA.class.section}`);
  if (DATA.class.school_year)  metaParts.push(`Academic Year: ${DATA.class.school_year}`);
  if (DATA.class.semester)     metaParts.push(`Semester: ${DATA.class.semester}`);
  document.getElementById('classMeta').innerHTML = metaParts.join('&emsp;&nbsp;');
  document.getElementById('transNote').innerHTML = '';  // stats rebuilt by renderSheet
  if (!activeTerm || (!DATA.terms.includes(activeTerm) && activeTerm !== 'FINAL' && activeTerm !== 'ATTENDANCE'))
    activeTerm = DATA.terms[0] || null;
  renderTermTabs(); renderSheet(); buildReportMenu(); buildCsvMenu();
}

/* ------------------------------------------------------------------ */
/* Term tabs                                                           */
/* ------------------------------------------------------------------ */
function renderTermTabs() {
  const tabs = [...DATA.terms, 'FINAL', 'ATTENDANCE'];
  document.getElementById('termTabs').innerHTML = tabs.map(t => {
    const labels = { FINAL: '&#x2211; Final Grades', ATTENDANCE: '&#x1F4CB; Attendance' };
    const label = labels[t] || esc(t);
    const active = t === activeTerm;
    return `<button class="btn ${active ? 'btn-dark' : 'btn-ghost'} btn-sm" onclick="switchTerm('${t}')">${label}</button>`;
  }).join('');
}

function switchTerm(t) {
  if (dirty.size) {
    showConfirm({
      title: 'Unsaved Changes',
      message: 'You have unsaved score changes.\nSwitch term anyway? Unsaved changes will be lost.',
      confirmText: 'Switch Anyway', danger: true,
      onConfirm: () => {
        dirty.clear(); updateSaveBtn();
        activeTerm = t; renderTermTabs(); renderSheet();
      }
    });
    return;
  }
  dirty.clear(); updateSaveBtn();
  activeTerm = t; renderTermTabs(); renderSheet();
}

function critForTerm(t) { return DATA.criteria.filter(c => c.term === t); }

/* ------------------------------------------------------------------ */
/* Main sheet dispatch                                                  */
/* ------------------------------------------------------------------ */
function renderSheet() {
  const table  = document.getElementById('sheet');
  const saveBtn = document.getElementById('saveBtn');

  // Always clean up the attendance toolbar when switching any tab
  const tb = document.getElementById('attToolbar');
  if (tb && activeTerm !== 'ATTENDANCE') tb.remove();

  if (!DATA.students.length) {
    table.innerHTML = `<tbody><tr><td class="empty" style="padding:40px">
      <div class="big">No students yet</div><div>Click "+ Students" to add your roster.</div></td></tr></tbody>`;
    saveBtn.style.display = 'none';
    return;
  }
  if (activeTerm === 'FINAL')      return renderFinalSheet(table, saveBtn);
  if (activeTerm === 'ATTENDANCE') return renderAttendanceSheet(table, saveBtn);
  return renderGradeSheet(table, saveBtn);
}

/* ------------------------------------------------------------------ */
/* Stats bar — total / passed / failed based on active term grade     */
/* ------------------------------------------------------------------ */
function buildSheetStats() {
  if (!DATA) return '';
  const total   = DATA.students.length;
  const passing = +DATA.class.passing_grade || 75;
  let passed = 0, failed = 0;
  DATA.students.forEach(s => {
    const c = DATA.computed[s.id] || {};
    let grade = null;
    if (activeTerm === 'FINAL') {
      grade = (c.final != null) ? Math.round(+c.final) : null;
    } else if (activeTerm !== 'ATTENDANCE') {
      grade = (c.terms && c.terms[activeTerm] != null)
        ? Math.round(+c.terms[activeTerm]) : null;
    }
    if (grade === null) return;
    if (grade >= passing) passed++; else failed++;
  });
  const graded = passed + failed;
  return '<span class="muted">Total Students: <strong style="color:var(--ink)">' + total + '</strong></span>'
       + '&emsp;<span style="color:var(--green);font-weight:600">Passed: <strong>' + passed + '</strong></span>'
       + '&emsp;<span style="color:var(--red);font-weight:600">Failed: <strong>' + failed + '</strong></span>';
}

/* ------------------------------------------------------------------ */
/* Grade sheet (term view with activities)                             */
/* ------------------------------------------------------------------ */
function renderGradeSheet(table, saveBtn) {
  saveBtn.style.display = _readonly ? 'none' : '';
  const crit = critForTerm(activeTerm);
  if (!crit.length) {
    table.innerHTML = `<tbody><tr><td class="empty" style="padding:40px">
      <div class="big">No criteria for ${esc(activeTerm)}</div>
      <div>Click "Setup Criteria &amp; Activities" to build this term.</div></td></tr></tbody>`;
    return;
  }

  // === HEADER ROW 1: criterion groups ===
  let h1 = `<tr>
    <th class="sticky-col" rowspan="2" style="text-align:left;padding-left:12px;vertical-align:bottom;min-width:160px">Student</th>`;
  crit.forEach(c => {
    const acts = c.activities || [];
    const span = acts.length * 2 + 2;   // raw+equiv per act, +AVG +WS
    h1 += `<th colspan="${span}">${esc(c.name)}<span class="wt">${(+c.weight)}%</span></th>`;
  });
  h1 += `<th rowspan="2" class="computed-h" style="vertical-align:bottom;min-width:72px">${esc(activeTerm)}<br>GRADE</th></tr>`;

  // === HEADER ROW 2: per-activity + AVG + WS ===
  let h2 = '<tr>';
  crit.forEach(c => {
    (c.activities || []).forEach(a => {
      h2 += `<th colspan="2">
        ${esc(a.label)}<span class="wt">/${(+a.perfect_score)}</span>
        ${!_readonly ? `<button class="col-paste-btn" title="Paste column from Excel"
          onclick="openColPaste(event,${a.id},${a.perfect_score},'${esc(a.label)}')">&#x2398;</button>` : ''}
      </th>`;
    });
    h2 += `<th class="computed-h">AVG</th><th class="computed-h">WS</th>`;
  });
  h2 += '</tr>';

  // === BODY ===
  let body = '<tbody>';
  DATA.students.forEach(s => {
    const comp = DATA.computed[s.id] || {};
    const detail = (comp.detail && comp.detail[activeTerm]) || {};
    const tg = comp.terms ? comp.terms[activeTerm] : null;
    body += `<tr data-sid="${s.id}">
      <td class="sticky-col namecell">
        ${esc(s.last_name)}, ${esc(s.first_name)}
        ${!_readonly ? `<button class="btn btn-ghost btn-sm" style="padding:1px 5px;margin-left:2px"
          title="Remove student" onclick="delStudent(${s.id},'${esc(s.last_name)}, ${esc(s.first_name)}')">&times;</button>` : ''}
      </td>`;
    crit.forEach(c => {
      (c.activities || []).forEach(a => {
        // Raw score: resolve both string and integer key variants from PHP JSON
        const sid = s.id, aid = a.id;
        const sScores = DATA.scores[sid] || DATA.scores[+sid] || {};
        const rawStored = (sScores[aid] != null) ? sScores[aid]
                        : (sScores[+aid] != null) ? sScores[+aid] : null;
        // Show 0 as an actual value (not placeholder) so AVG/WS compute immediately
        const rawVal    = rawStored != null ? rawStored : 0;
        const rawDisplay = Math.round(+rawVal);
        const eq = equiv(rawVal, +a.perfect_score);
        const eqDisplay = eq != null ? eq.toFixed(0) : '0';
        body += `<td class="rawcell">
          <input class="cellinput" inputmode="decimal"
            data-sid="${s.id}" data-aid="${a.id}" data-perfect="${a.perfect_score}"
            value="${rawDisplay}" onfocus="this.select()" ${_readonly ? 'readonly style="background:var(--paper-2);cursor:default"' : ''}>
        </td>
        <td class="equivcell hl-equiv-cell" data-equiv="${s.id}:${a.id}">${eqDisplay}</td>`;
      });
      const cd = detail[c.name] || {};
      body += `<td class="computed avg-cell" data-avg="${s.id}:${cssQ(c.name)}">${cd.average != null ? (+cd.average).toFixed(2) : '\u2013'}</td>
               <td class="computed ws-cell"  data-ws="${s.id}:${cssQ(c.name)}">${cd.ws != null ? (+cd.ws).toFixed(2) : '\u2013'}</td>`;
    });
    const passing = +DATA.class.passing_grade || 75;
    const tgVal   = tg != null ? Math.round(+tg) : (SETTINGS.zero_equiv || 65);
    const tgFail  = tgVal < passing;
    body += `<td class="computed termgrade ${tgFail ? 'grade-fail-cell' : 'hl-grade-cell'}" data-termcell="${s.id}">${tgVal}</td></tr>`;
  });
  body += '</tbody>';
  table.innerHTML = '<thead>' + h1 + h2 + '</thead>' + body;
  if (!_readonly) attachCellHandlers();
  // Refresh stats bar
  document.getElementById('transNote').innerHTML = buildSheetStats();
}

/* ------------------------------------------------------------------ */
/* Final grades sheet                                                  */
/* ------------------------------------------------------------------ */
function renderFinalSheet(table, saveBtn) {
  saveBtn.style.display = 'none';
  let head = `<thead><tr>
    <th class="sticky-col" style="text-align:left;padding-left:12px;min-width:160px">Student</th>`;
  DATA.terms.forEach(t => head += `<th class="hl-term-col-header">${esc(t)}</th>`);
  head += `<th class="hl-final-cell">FINAL GRADE</th>
           <th class="hl-remarks-header">Remarks</th>
           <th class="hl-ai-header">AI Insight</th></tr></thead>`;

  let body = '<tbody>';
  const passing = +DATA.class.passing_grade || 75;
  DATA.students.forEach(s => {
    const c = DATA.computed[s.id] || {};
    body += `<tr><td class="sticky-col namecell">${esc(s.last_name)}, ${esc(s.first_name)}</td>`;
    DATA.terms.forEach(t => {
      const g = c.terms ? c.terms[t] : null;
      const gv = g != null ? Math.round(+g) : (SETTINGS.zero_equiv || 65);
      body += `<td class="computed ${gv < passing ? 'grade-fail-cell' : 'term-col-cell'}">${gv}</td>`;
    });
    const f = c.final, p = c.passes;
    const fv = f != null ? Math.round(+f) : (SETTINGS.zero_equiv || 65);
    body += `<td class="computed termgrade ${fv < passing ? 'grade-fail-cell' : 'hl-grade-cell'}" style="font-size:1.1rem;font-weight:800">${fv}</td>`;
    const rmkClass = p === true ? 'remarks-pass' : p === false ? 'remarks-fail' : 'remarks-inc';
    const rmkLabel = p === true ? 'Passed' : p === false ? 'Failed' : 'Inc';
    body += `<td class="computed ${rmkClass}">${rmkLabel}</td>`;
    body += `<td class="computed ai-cell"><button class="btn btn-sm" onclick="analyze(${s.id})">Analyze</button></td></tr>`;
  });
  table.innerHTML = head + body + '</tbody>';
  document.getElementById('transNote').innerHTML = buildSheetStats();
}

/* ------------------------------------------------------------------ */
/* Attendance sheet                                                    */
/* ------------------------------------------------------------------ */
let ATT = { sessions: [], summary: {}, totalSessions: 0 };

async function renderAttendanceSheet(table, saveBtn) {
  saveBtn.style.display = 'none';
  table.innerHTML = '<tbody><tr><td class="empty"><div class="spinner"></div> Loading attendance…</td></tr></tbody>';

  // Load sessions for the first term (or last graded term)
  const term = DATA.terms[DATA.terms.length - 1] || '';
  await refreshAttendance(term, table);
}

async function refreshAttendance(term, table) {
  if (!table) table = document.getElementById('sheet');
  const [sessRes, sumRes] = await Promise.all([
    fetch(`api/attendance.php?action=sessions&class_id=${CLASS_ID}&term=${encodeURIComponent(term)}`).then(r=>r.json()),
    fetch(`api/attendance.php?action=summary&class_id=${CLASS_ID}&term=${encodeURIComponent(term)}`).then(r=>r.json()),
  ]);
  ATT.sessions = sessRes.sessions || [];
  ATT.summary  = sumRes.summary  || {};
  ATT.total    = sumRes.total    || 0;
  ATT.term     = term;
  drawAttendanceTable(table, term);
}

function drawAttendanceTable(table, term) {
  // Term selector tabs within attendance
  const termPicker = DATA.terms.map(t =>
    `<button class="btn ${t===term?'btn-dark':'btn-ghost'} btn-sm" onclick="refreshAttendance('${esc(t)}')">${esc(t)}</button>`
  ).join('');

  const sessions = ATT.sessions;

  // Header
  let head = `<thead><tr>
    <th class="sticky-col" style="text-align:left;padding-left:10px;min-width:160px">Student</th>`;
  sessions.forEach(s => {
    const d = s.session_date ? s.session_date.slice(5) : '';  // MM-DD
    head += `<th class="sess-head" title="${esc(s.label)}${d?' - '+d:''}">
      ${esc(s.label)}${d ? '<br><small style="font-weight:300">'+d+'</small>' : ''}
      <span class="sess-del" onclick="deleteSession(${s.id},'${esc(term)}')" title="Delete session">&#x2715;</span>
    </th>`;
  });
  head += `<th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead>`;

  // Body
  let body = '<tbody>';
  DATA.students.forEach(stu => {
    const sum = ATT.summary[stu.id] || { P:0, A:0, L:0 };
    const rate = ATT.total > 0 ? ((sum.P + sum.L * 0.5) / ATT.total * 100).toFixed(0) + '%' : '—';
    body += `<tr data-sid="${stu.id}">
      <td class="sticky-col namecell">${esc(stu.last_name)}, ${esc(stu.first_name)}</td>`;
    sessions.forEach(sess => {
      const st = (sess.records && sess.records[stu.id]) || 'P';
      body += `<td><button class="att-btn att-${st}"
        data-sessid="${sess.id}" data-sid="${stu.id}" data-status="${st}"
        onclick="cycleAtt(this)">${st}</button></td>`;
    });
    body += `<td class="computed" style="color:var(--green);font-weight:600">${sum.P}</td>
             <td class="computed" style="color:var(--red);font-weight:600">${sum.A}</td>
             <td class="computed" style="color:var(--amber);font-weight:600">${sum.L}</td>
             <td class="computed">${rate}</td></tr>`;
  });
  body += '</tbody>';

  // Toolbar
  const toolbar = `<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
    <div style="display:flex;gap:6px">${termPicker}</div>
    <span style="flex:1"></span>
    <span class="muted">${ATT.total} session${ATT.total!==1?'s':''} recorded</span>
    <button class="btn btn-primary btn-sm" onclick="openAddSession('${esc(term)}')">+ Add Session</button>
  </div>`;

  // Inject toolbar above table
  const wrapper = document.querySelector('.sheet-scroll');
  let toolbarEl = document.getElementById('attToolbar');
  if (!toolbarEl) {
    toolbarEl = document.createElement('div');
    toolbarEl.id = 'attToolbar';
    wrapper.before(toolbarEl);
  }
  toolbarEl.innerHTML = toolbar;

  table.innerHTML = head + body;
}

function cycleAtt(btn) {
  const cycle = { P: 'A', A: 'L', L: 'P' };
  const cur    = btn.dataset.status;
  const next   = cycle[cur] || 'P';
  const sessId = +btn.dataset.sessid;
  const sid    = +btn.dataset.sid;
  btn.textContent    = next;
  btn.dataset.status = next;
  btn.className      = 'att-btn att-' + next;

  // Update in-memory session record so future cycles have the right old value
  const sess = ATT.sessions.find(s => s.id === sessId);
  if (sess?.records) sess.records[sid] = next;

  // Optimistic local summary update — no server round-trip needed for the UI
  const sum = ATT.summary[sid] = ATT.summary[sid] || { P: 0, A: 0, L: 0 };
  sum[cur]  = Math.max(0, (sum[cur]  || 0) - 1);
  sum[next] = (sum[next] || 0) + 1;
  const tr  = btn.closest('tr');
  const rate = ATT.total > 0 ? ((sum.P + sum.L * 0.5) / ATT.total * 100).toFixed(0) + '%' : '—';
  const cells = tr.querySelectorAll('td.computed');
  if (cells.length >= 4) {
    cells[cells.length - 4].textContent = sum.P;
    cells[cells.length - 3].textContent = sum.A;
    cells[cells.length - 2].textContent = sum.L;
    cells[cells.length - 1].textContent = rate;
  }

  // Fire-and-forget persist
  fetch('api/attendance.php?action=save_records', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ session_id: sessId, records: [{ student_id: sid, status: next }] })
  });
}

function openAddSession(term) {
  const label = `Session ${ATT.sessions.length + 1}`;
  const date  = new Date().toISOString().slice(0,10);
  const html  = `<div class="modal-backdrop show" id="sessModal">
    <div class="modal">
      <h2>Add Attendance Session</h2>
      <div class="field"><label>Label</label><input id="sess_label" value="${esc(label)}"></div>
      <div class="field"><label>Date</label><input id="sess_date" type="date" value="${date}"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button class="btn btn-ghost" onclick="document.getElementById('sessModal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="confirmAddSession('${esc(term)}')">Add Session</button>
      </div>
    </div></div>`;
  document.body.insertAdjacentHTML('beforeend',html);
}

async function confirmAddSession(term) {
  const label = document.getElementById('sess_label').value.trim();
  const date  = document.getElementById('sess_date').value;
  if (!label) { toast('Label required'); return; }
  document.getElementById('sessModal').remove();
  const r = await fetch('api/attendance.php?action=save_session',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body: JSON.stringify({class_id:CLASS_ID,term,label,date})
  }).then(r=>r.json());
  if (!r.ok) { toast(r.error||'Error'); return; }
  // initialize all students as P
  const records = DATA.students.map(s=>({student_id:s.id,status:'P'}));
  await fetch('api/attendance.php?action=save_records',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({session_id:r.id,records})
  });
  toast('Session added — default: all Present');
  await refreshAttendance(term);
}

async function deleteSession(sid, term) {
  showConfirm({
    title: 'Delete Attendance Session',
    message: 'Delete this session and all its attendance records?\nThis cannot be undone.',
    confirmText: 'Delete Session', danger: true,
    onConfirm: async () => {
      await fetch('api/attendance.php?action=delete_session',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({session_id:sid})
      });
      toast('Session deleted');
      await refreshAttendance(term);
    }
  });
}

/* ------------------------------------------------------------------ */
/* Cell editing, live transmutation, arrow-key nav                    */
/* ------------------------------------------------------------------ */
function attachCellHandlers() {
  const inputs  = [...document.querySelectorAll('.cellinput')];
  const firstSid = inputs[0]?.dataset.sid;
  const perRow   = firstSid
    ? document.querySelectorAll(`#sheet tbody tr[data-sid="${firstSid}"] .cellinput`).length
    : 1;
  inputs.forEach((inp, i) => {
    inp.addEventListener('input',   () => markDirty(inp));
    inp.addEventListener('keydown', e  => handleKey(e, inp, inputs, i, perRow));
    inp.addEventListener('paste',   e  => handlePaste(e, inp));
  });
}

// Debounce map: sid → pending RAF id
const _previewPending = new Map();

function markDirty(inp) {
  const perfect = +inp.dataset.perfect;
  let v = inp.value.trim();
  if (v !== '' && !isNaN(v)) {
    if (+v < 0)        { v = '0';           inp.value = v; }
    if (+v > perfect)  { v = String(perfect); inp.value = v; toast(`Capped at ${perfect}`); }
  }
  inp.style.color = (v !== '' && isNaN(v)) ? 'var(--red)' : '';
  inp.classList.add('dirty');
  const key = inp.dataset.sid + ':' + inp.dataset.aid;
  dirty.set(key, v);
  // Update equiv cell inline — single targeted query, no reflow
  const eq = equiv(v, perfect);
  const eqEl = document.querySelector(`[data-equiv="${inp.dataset.sid}:${inp.dataset.aid}"]`);
  if (eqEl) { eqEl.textContent = eq != null ? eq.toFixed(0) : '0'; eqEl.classList.add('hl-equiv-cell'); }
  updateSaveBtn();
  // Debounce live preview: coalesce rapid keystrokes into one rAF per student
  const sid = inp.dataset.sid;
  if (_previewPending.has(sid)) cancelAnimationFrame(_previewPending.get(sid));
  _previewPending.set(sid, requestAnimationFrame(() => { _previewPending.delete(sid); liveTermPreview(sid); }));
}

function handleKey(e, inp, inputs, i, perRow) {
  if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); inputs[i + perRow]?.focus(); }
  else if (e.key === 'ArrowUp')  { e.preventDefault(); inputs[i - perRow]?.focus(); }
  else if (e.key === 'ArrowRight' && inp.selectionStart === inp.value.length) { e.preventDefault(); inputs[i + 1]?.focus(); }
  else if (e.key === 'ArrowLeft'  && inp.selectionStart === 0)                { e.preventDefault(); inputs[i - 1]?.focus(); }
}

// Paste a 2D block (tab+newline separated, from Excel) starting at focused cell
function handlePaste(e, startInp) {
  const text = (e.clipboardData || window.clipboardData).getData('text');
  const rows = text.replace(/\r/g, '').split('\n').filter(r => r.trim().length);
  if (rows.length <= 1 && !rows[0]?.includes('\t')) return;  // single cell: normal paste
  e.preventDefault();
  const trs = [...document.querySelectorAll('#sheet tbody tr[data-sid]')];
  const startTrIdx = trs.findIndex(tr => tr.dataset.sid === startInp.dataset.sid);
  const rowInputs  = [...startInp.closest('tr').querySelectorAll('.cellinput')];
  const startCol   = rowInputs.indexOf(startInp);
  let filled = 0;
  rows.forEach((line, ri) => {
    const tr = trs[startTrIdx + ri]; if (!tr) return;
    const cells = [...tr.querySelectorAll('.cellinput')];
    line.split('\t').forEach((val, ci) => {
      const cell = cells[startCol + ci]; if (!cell) return;
      cell.value = val.trim(); markDirty(cell); filled++;
    });
  });
  toast(`Pasted into ${filled} cell${filled !== 1 ? 's' : ''} (${rows.length} row${rows.length !== 1 ? 's' : ''})`);
}

/* ------------------------------------------------------------------ */
/* Column-paste popover (paste an entire Excel column at once)         */
/* ------------------------------------------------------------------ */
let colPastePopEl = null;

function openColPaste(evt, actId, perfect, label) {
  evt.stopPropagation();
  closeColPaste();
  const btn = evt.currentTarget;
  const rect = btn.getBoundingClientRect();
  const pop = document.createElement('div');
  pop.className = 'col-paste-pop';
  pop.id = 'colPastePop';

  // Position just below the button, clamped so it never overflows bottom/right
  const popW = 280, popH = 310;
  let top  = rect.bottom + 6;
  let left = Math.max(8, Math.min(rect.left, window.innerWidth - popW - 8));
  // If not enough space below, flip above the button
  if (top + popH > window.innerHeight - 8) top = Math.max(8, rect.top - popH - 6);
  pop.style.top  = top + 'px';
  pop.style.left = left + 'px';

  pop.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
      <h4 style="margin:0;font-size:.95rem">Paste column: <strong>${esc(label)}</strong> <span class="muted" style="font-size:.8rem">(max ${perfect})</span></h4>
      <button onclick="closeColPaste()" title="Close"
        style="background:none;border:none;cursor:pointer;font-size:1.1rem;line-height:1;
               padding:0 0 0 8px;color:var(--ink-soft);flex-shrink:0" aria-label="Close">&times;</button>
    </div>
    <p class="muted" style="font-size:.82rem;margin:0 0 8px">Copy the score column from Excel,<br>then paste here (Ctrl+V / Cmd+V).<br><span style="font-size:.78rem">Empty or blank lines are treated as <strong>0</strong>.</span></p>
    <textarea id="colPasteBox" placeholder="One score per line&#10;55&#10;72&#10;88&#10;..." autofocus></textarea>
    <div class="row">
      <button class="btn btn-ghost btn-sm" onclick="closeColPaste()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="applyColPaste(${actId},${perfect})">Apply</button>
    </div>`;
  document.body.appendChild(pop);
  colPastePopEl = pop;
  setTimeout(() => pop.querySelector('textarea').focus(), 50);
}

function closeColPaste() {
  if (colPastePopEl) { colPastePopEl.remove(); colPastePopEl = null; }
}

function applyColPaste(actId, perfect) {
  const text = document.getElementById('colPasteBox').value;
  // Split on newlines — keep every line including empty/space-only ones
  // Empty or whitespace-only lines = 0 (preserves per-student row alignment)
  const lines = text.replace(/\r/g, '').split('\n');
  const cells = [...document.querySelectorAll(`.cellinput[data-aid="${actId}"]`)];
  let filled = 0;
  for (let i = 0; i < lines.length; i++) {
    const cell = cells[i];
    if (!cell) break;                          // more lines than students → stop
    const trimmed = lines[i].trim();
    // blank line (empty cell in Excel) → 0; otherwise use trimmed value
    const val = trimmed === '' ? '0' : trimmed;
    cell.value = val;
    markDirty(cell);
    filled++;
  }
  closeColPaste();
  toast(`Filled ${filled} cell${filled !== 1 ? 's' : ''} in column ${actId}`);
}

// Outside-click intentionally removed — modal only closes via Apply / Cancel / × button

/* ------------------------------------------------------------------ */
/* Live term grade preview (client-side, matches server rule)          */
/* ------------------------------------------------------------------ */
function liveTermPreview(sid) {
  const crit = critForTerm(activeTerm);
  let sumWs = 0, wUsed = 0, any = false;
  crit.forEach(c => {
    const acts = c.activities || [];
    if (!acts.length) return;
    const eqs = acts.map(a => {
      const cell = document.querySelector(`.cellinput[data-sid="${sid}"][data-aid="${a.id}"]`);
      const v = cell ? cell.value.trim() : '';
      return (v !== '' && !isNaN(v)) ? equiv(v, +a.perfect_score) : SETTINGS.zero_equiv;
    });
    const avg = eqs.reduce((a, b) => a + b, 0) / eqs.length;
    const ws  = avg * (+c.weight / 100);
    const avgEl = document.querySelector(`[data-avg="${sid}:${cssQ(c.name)}"]`);
    const wsEl  = document.querySelector(`[data-ws="${sid}:${cssQ(c.name)}"]`);
    if (avgEl) avgEl.textContent = avg.toFixed(2);
    if (wsEl)  wsEl.textContent  = ws.toFixed(2);
    sumWs += ws; wUsed += +c.weight; any = true;
  });
  const g = (any && wUsed > 0) ? (wUsed < 100 ? sumWs * (100 / wUsed) : sumWs) : null;
  const tc = document.querySelector(`[data-termcell="${sid}"]`);
  if (tc) {
    const gv = g != null ? Math.round(g) : (SETTINGS.zero_equiv || 65);
    const passing = +DATA.class.passing_grade || 75;
    tc.textContent = gv;
    tc.classList.remove('hl-grade-cell', 'grade-fail-cell');
    tc.classList.add(gv < passing ? 'grade-fail-cell' : 'hl-grade-cell');
    tc.style.background = '';  // let CSS class control color
  }
}

function cssQ(s) { return String(s).replace(/"/g, '\\"'); }

/* ------------------------------------------------------------------ */
/* Save dirty cells                                                    */
/* ------------------------------------------------------------------ */
function updateSaveBtn() {
  const b = document.getElementById('saveBtn');
  b.disabled = dirty.size === 0;
  b.textContent = dirty.size ? `Save ${dirty.size} change${dirty.size > 1 ? 's' : ''}` : 'Save changes';
}

async function saveDirty() {
  if (!dirty.size) return;
  const cells = [];
  for (const [k, v] of dirty) {
    const [student_id, activity_id] = k.split(':');
    cells.push({ student_id, activity_id, raw_score: v });
  }
  const r = await api('save_scores', { class_id: CLASS_ID, cells });
  Object.assign(DATA.computed, r.computed);
  cells.forEach(c => {
    DATA.scores[c.student_id] = DATA.scores[c.student_id] || {};
    if (c.raw_score === '') delete DATA.scores[c.student_id][c.activity_id];
    else DATA.scores[c.student_id][c.activity_id] = c.raw_score;
  });
  dirty.clear();
  document.querySelectorAll('.cellinput.dirty').forEach(i => i.classList.remove('dirty'));
  updateSaveBtn();
  renderSheet();
  toast(`Saved ${r.saved} cell${r.saved !== 1 ? 's' : ''}` + (r.clamped ? ` (${r.clamped} capped to max)` : ''));
}

/* ------------------------------------------------------------------ */
/* Criteria + activities setup modal                                   */
/* ------------------------------------------------------------------ */
function openCriteria() {
  document.getElementById('critByTerm').innerHTML = DATA.terms.map(term => {
    const rows = critForTerm(term).map(cr => critBlock(term, cr)).join('');
    return `<div class="card" style="margin-bottom:14px;padding:14px" data-termblock="${esc(term)}">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">${esc(term)}</h3>
        <button class="btn btn-ghost btn-sm" onclick="addCrit('${esc(term)}')">+ Add criterion</button>
      </div>
      <div data-rows="${esc(term)}" style="margin-top:10px">${rows}</div>
      <div class="muted" data-sum="${esc(term)}" style="margin-top:6px"></div>
    </div>`;
  }).join('');
  document.getElementById('termWeightRows').innerHTML = DATA.terms.map(t => `
    <div class="field"><label>${esc(t)} (%)</label>
    <input type="number" step="1" data-tw="${esc(t)}"
      value="${DATA.term_weights[t] != null ? Math.round(+DATA.term_weights[t]) : Math.round(100 / DATA.terms.length)}" oninput="updateCritStatus()"></div>
  `).join('');
  updateCritStatus();
  document.getElementById('criteriaModal').classList.add('show');
}

function critBlock(term, cr) {
  const acts = (cr?.activities || []).map(a => actRow(a)).join('');
  const dupBtn = cr?.id
    ? `<button class="btn btn-ghost btn-sm" title="Duplicate this criterion (no scores)" onclick="dupCriterion(${cr.id})">⧉ Duplicate</button>`
    : '';
  return `<div class="card" data-critrow style="margin-bottom:10px;padding:12px;background:var(--paper-2)">
    <input type="hidden" data-cid value="${cr?.id || ''}">
    <div class="row" style="align-items:flex-end">
      <div style="flex:2"><label>Criterion name</label>
        <input data-f="name" placeholder="e.g. Quizzes" value="${esc(cr?.name || '')}"></div>
      <div style="flex:1"><label>Weight %</label>
        <input data-f="weight" type="number" step="1" value="${cr?.weight != null ? Math.round(+cr.weight) : ''}" oninput="updateCritStatus()"></div>
      ${dupBtn}
      <button class="btn btn-ghost btn-sm" onclick="this.closest('[data-critrow]').remove();updateCritStatus()">Remove</button>
    </div>
    <div style="margin-top:8px">
      <label>Activities (each with its own perfect score)</label>
      <div data-acts>${acts}</div>
      <button class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="addAct(this)">+ Add activity</button>
    </div>
  </div>`;
}

function actRow(a) {
  return `<div class="row" data-actrow style="margin-bottom:6px;align-items:flex-end">
    <input type="hidden" data-aid value="${a?.id || ''}">
    <div style="flex:1"><input data-af="label" placeholder="Q1" value="${esc(a?.label || '')}"></div>
    <div style="flex:1"><input data-af="perfect" type="number" step="1" placeholder="Perfect score" value="${a?.perfect_score != null ? Math.round(+a.perfect_score) : ''}"></div>
    <button class="btn btn-ghost btn-sm" onclick="this.closest('[data-actrow]').remove()">&times;</button>
  </div>`;
}

async function dupCriterion(critId) {
  const r = await api('duplicate_criterion', {criterion_id: critId});
  if (r.ok) {
    toast('Criterion duplicated (no scores copied)');
    // Save current setup first then reload to show the duplicate
    await saveCriteria();
  }
}

function addCrit(term) {
  document.querySelector(`[data-rows="${cssQ(term)}"]`).insertAdjacentHTML('beforeend', critBlock(term, null));
}
function addAct(btn) { btn.previousElementSibling.insertAdjacentHTML('beforeend', actRow(null)); }

function updateCritStatus() {
  let twSum = 0;
  document.querySelectorAll('[data-tw]').forEach(i => twSum += parseFloat(i.value) || 0);
  DATA.terms.forEach(term => {
    const block = document.querySelector(`[data-termblock="${cssQ(term)}"]`); if (!block) return;
    let sum = 0;
    block.querySelectorAll('[data-critrow]').forEach(r => sum += parseFloat(r.querySelector('[data-f="weight"]').value) || 0);
    const el = block.querySelector(`[data-sum="${cssQ(term)}"]`);
    if (sum === 0) {
      el.innerHTML = '<span class="muted">No criteria added yet</span>';
    } else {
      const ok   = Math.abs(sum - 100) < 0.01;
      const over = sum > 100;
      const color = ok ? 'var(--green)' : 'var(--red)';
      const msg = ok ? ' \u2713 Ready to save'
        : over ? ` \u2014 over by ${Math.round(sum-100)}% (reduce weights to reach 100%)`
        : ` \u2014 ${Math.round(100-sum)}% remaining (add more criteria or increase weights)`;
      el.innerHTML = `Criteria weights: <strong style="color:${color}">${Math.round(sum)}%</strong>`
        + `<span style="color:${color};font-size:.85rem">${msg}</span>`;
    }
  });
  const twOk = Math.abs(twSum - 100) < 0.01;
  document.getElementById('critWeightStatus').innerHTML =
    `Term weights for Final Grade: <strong style="color:${twOk?'var(--green)':'var(--red)'}">${Math.round(twSum)}%</strong>` +
    (twOk ? ' \u2713' : ' (must total 100)');
}

async function saveCriteria() {
  // Validate: each term's criteria must total exactly 100%
  let validationErrors = [];
  DATA.terms.forEach(term => {
    const block = document.querySelector(`[data-termblock="${cssQ(term)}"]`); if (!block) return;
    let sum = 0;
    block.querySelectorAll('[data-critrow]').forEach(r => {
      sum += parseFloat(r.querySelector('[data-f="weight"]').value) || 0;
    });
    if (sum > 0 && Math.abs(sum - 100) > 0.01) {
      validationErrors.push(`${term}: criteria weights total ${Math.round(sum)}% (must be exactly 100%)`);
    }
  });
  if (validationErrors.length) {
    toast('Fix weight totals before saving: ' + validationErrors[0]);
    // Flash the offending status lines red
    updateCritStatus();
    return;
  }

  const criteria = [];
  DATA.terms.forEach(term => {
    const block = document.querySelector(`[data-termblock="${cssQ(term)}"]`); if (!block) return;
    block.querySelectorAll('[data-critrow]').forEach(r => {
      const name = r.querySelector('[data-f="name"]').value.trim(); if (!name) return;
      const activities = [];
      r.querySelectorAll('[data-actrow]').forEach(ar => {
        const label = ar.querySelector('[data-af="label"]').value.trim(); if (!label) return;
        activities.push({ id: ar.querySelector('[data-aid]').value || 0, label, perfect_score: ar.querySelector('[data-af="perfect"]').value || 100 });
      });
      criteria.push({ id: r.querySelector('[data-cid]').value || 0, term, name, weight: r.querySelector('[data-f="weight"]').value || 0, activities });
    });
  });
  const term_weights = {};
  document.querySelectorAll('[data-tw]').forEach(i => term_weights[i.dataset.tw] = i.value || 0);
  await api('save_criteria', { class_id: CLASS_ID, criteria, term_weights });
  closeModal('criteriaModal'); toast('Setup saved'); await loadSheet();
}

/* ------------------------------------------------------------------ */
/* Students                                                            */
/* ------------------------------------------------------------------ */
/* ------------------------------------------------------------------ */
/* Students management                                                 */
/* ------------------------------------------------------------------ */
let _editSel = null;   // id of student being edited

function openStudents() {
  document.getElementById('bulkBox').value = '';
  document.getElementById('addStudentsDetails').removeAttribute('open');
  closeStudentEdit();
  document.getElementById('studentsModal').classList.add('show');
  if (DATA && DATA.students !== undefined) {
    renderStudentMgmtTable();
  } else {
    document.getElementById('studentMgmtBody').innerHTML =
      `<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--ink-soft)">
        <div class="spinner" style="margin:0 auto 8px"></div>Loading…</td></tr>`;
    const wait = setInterval(() => {
      if (DATA && DATA.students !== undefined) { clearInterval(wait); renderStudentMgmtTable(); }
    }, 80);
  }
}

function renderStudentMgmtTable() {
  const tbody = document.getElementById('studentMgmtBody');
  if (!DATA || !DATA.students || !DATA.students.length) {
    tbody.innerHTML = `<tr><td colspan="5"
      style="padding:24px;text-align:center;color:var(--ink-soft)">
      No students yet — use "Add New Students" below.</td></tr>`;
    return;
  }
  tbody.innerHTML = DATA.students.map((s, i) => studentRow(s, i)).join('');
}

function studentRow(s, i) {
  const bg  = i % 2 === 0 ? '#fff' : 'var(--paper-2)';
  const isEditing = _editSel == s.id;
  const editBg = isEditing ? 'var(--amber-soft)' : bg;
  return `<tr id="smr_${s.id}" data-idx="${i}"
    style="background:${editBg};outline:${isEditing?'2px solid var(--amber)':'none'};outline-offset:-1px">
    <td style="padding:9px 12px;font-size:.85rem">${esc(s.student_no || '—')}</td>
    <td style="padding:9px 12px;font-weight:600">${esc(s.last_name)}</td>
    <td style="padding:9px 12px">${esc(s.first_name)}</td>
    <td style="padding:9px 12px;font-size:.82rem;color:var(--ink-soft)">${esc(s.email || '—')}</td>
    <td style="padding:6px 8px;white-space:nowrap;text-align:right">
      <button class="btn btn-ghost btn-sm" style="padding:3px 8px;font-size:.85rem"
        onclick="openStudentEdit(${s.id})" title="Edit this student">&#9998;</button>
      <button class="btn btn-ghost btn-sm" style="padding:3px 8px;font-size:.85rem;color:var(--red)"
        onclick="delStudent(${s.id},'${esc(s.last_name)}, ${esc(s.first_name)}')" title="Remove">&#10005;</button>
    </td>
  </tr>`;
}

function openStudentEdit(id) {
  const s = DATA.students.find(x => x.id == id);
  if (!s) return;
  _editSel = id;

  // Fill the top edit panel
  document.getElementById('ef_last').value  = s.last_name  || '';
  document.getElementById('ef_first').value = s.first_name || '';
  document.getElementById('ef_no').value    = s.student_no || '';
  document.getElementById('ef_email').value = s.email      || '';
  document.getElementById('editPanelName').textContent = '— ' + s.last_name + ', ' + s.first_name;
  document.getElementById('efErr').style.display = 'none';
  document.getElementById('studentEditPanel').style.display = '';

  // Highlight the row and re-render table to show selection state
  renderStudentMgmtTable();

  // Scroll the selected row into view, then focus the first input
  const row = document.getElementById('smr_' + id);
  if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  setTimeout(() => document.getElementById('ef_last').focus(), 50);
}

function closeStudentEdit() {
  _editSel = null;
  const panel = document.getElementById('studentEditPanel');
  if (panel) panel.style.display = 'none';
  const err = document.getElementById('efErr');
  if (err) err.style.display = 'none';
}

async function commitStudentEdit() {
  if (!_editSel) return;
  const id = _editSel;
  const ln = (document.getElementById('ef_last').value || '').trim();
  if (!ln) {
    document.getElementById('efErr').textContent = 'Last name is required.';
    document.getElementById('efErr').style.display = '';
    document.getElementById('ef_last').focus();
    return;
  }
  const btn = document.getElementById('efSaveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  try {
    const payload = {
      student_id: id,
      last_name:  ln,
      first_name: (document.getElementById('ef_first').value || '').trim(),
      student_no: (document.getElementById('ef_no').value    || '').trim(),
      email:      (document.getElementById('ef_email').value  || '').trim(),
    };
    const r = await api('update_student', payload);
    if (r.ok) {
      // Update local DATA without full reload
      const idx = DATA.students.findIndex(x => x.id == id);
      if (idx !== -1) Object.assign(DATA.students[idx], {
        last_name:  payload.last_name,
        first_name: payload.first_name,
        student_no: payload.student_no,
        email:      payload.email,
      });
      closeStudentEdit();
      renderStudentMgmtTable();
      toast('Student updated successfully');
    }
  } catch(e) {
    document.getElementById('efErr').textContent = 'Save failed — please try again.';
    document.getElementById('efErr').style.display = '';
  }
  btn.disabled = false; btn.textContent = '✓ Save Changes';
}

async function bulkAddStudents() {
  const lines = document.getElementById('bulkBox').value.split('\n').map(l => l.trim()).filter(Boolean);
  const rows = lines.map(line => {
    let p = (line.includes('\t') ? line.split('\t') : line.split(',')).map(x => x.trim());
    if (p.length >= 3) return { student_no: p[0], last_name: p[1], first_name: p[2] };
    if (p.length === 2) return { last_name: p[0], first_name: p[1] };
    return { last_name: p[0], first_name: '' };
  });
  const r = await api('bulk_students', { class_id: CLASS_ID, rows });
  toast(`Added ${r.added} student${r.added !== 1 ? 's' : ''}`);
  document.getElementById('bulkBox').value = '';
  document.getElementById('addStudentsDetails').removeAttribute('open');
  await loadSheet();
  renderStudentMgmtTable();
}

async function delStudent(id, name) {
  // Show password confirmation modal before deleting
  return new Promise(resolve => {
    const modal = document.createElement('div');
    modal.className = 'modal-backdrop show';
    modal.id = 'delStudentModal';
    modal.innerHTML = `
      <div class="modal" style="max-width:420px">
        <h2 style="margin-top:0;color:var(--red)">&#9888; Remove Student</h2>
        <p>You are about to permanently remove <strong>${esc(name)}</strong> and all their scores.
           This cannot be undone.</p>
        <div class="field">
          <label>Enter your password to confirm</label>
          <input type="password" id="delPwInput" placeholder="Your account password" autofocus>
        </div>
        <div id="delPwErr" class="alert alert-error" style="display:none;margin-bottom:12px"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button class="btn btn-ghost" onclick="document.getElementById('delStudentModal').remove()">Cancel</button>
          <button class="btn btn-primary" style="background:var(--red);border-color:var(--red)"
            id="delPwBtn" onclick="confirmDelStudent(${id})">Remove Student</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    document.getElementById('delPwInput').addEventListener('keydown', e => {
      if (e.key === 'Enter') document.getElementById('delPwBtn').click();
    });
  });
}

async function confirmDelStudent(id) {
  const pw = document.getElementById('delPwInput').value;
  if (!pw) { showDelErr('Please enter your password.'); return; }
  // Verify password first
  const vr = await fetch('api/data.php?action=verify_password', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({password: pw})
  }).then(r => r.json());
  if (!vr.ok) { showDelErr('Incorrect password. Please try again.'); return; }
  // Password correct — immediately remove student from DOM, DATA, and server
  document.getElementById('delStudentModal').remove();
  const delId = String(id);   // scores/students use string keys from JSON

  // 1. Remove the row from the student management modal table directly
  const mgmtRow = document.getElementById('smr_' + delId);
  if (mgmtRow) mgmtRow.remove();

  // 2. Remove the row from the gradebook sheet directly
  document.querySelectorAll(`tr[data-sid="${delId}"], .sheet-row-${delId}`)
    .forEach(r => r.remove());

  // 3. Update DATA in memory
  if (DATA) {
    if (DATA.students) DATA.students = DATA.students.filter(s => String(s.id) !== delId);
    if (DATA.scores)   { delete DATA.scores[delId]; delete DATA.scores[+delId]; }
    if (DATA.computed) { delete DATA.computed[delId]; delete DATA.computed[+delId]; }
  }

  // 4. If modal tbody now empty, show empty message
  const tbody = document.getElementById('studentMgmtBody');
  if (tbody && !tbody.querySelector('tr[id^="smr_"]')) {
    tbody.innerHTML = `<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--ink-soft)">
      No students yet — use "Add New Students" below.</td></tr>`;
  }

  // 5. Server delete + full reload
  await api('delete_student', {student_id: +delId});
  toast('Student removed');
  await loadSheet();
  renderStudentMgmtTable();
}

function showDelErr(msg) {
  const el = document.getElementById('delPwErr');
  if (el) { el.textContent = msg; el.style.display = ''; }
}

/* ------------------------------------------------------------------ */
/* PDF report menu                                                     */
/* ------------------------------------------------------------------ */
function buildReportMenu() {
  let html = `<div style="font-size:.75rem;font-weight:700;color:var(--ink-soft);letter-spacing:.05em;
    padding:2px 4px 6px;border-bottom:1px solid var(--line);margin-bottom:8px">REPORTS</div>`;

  // Final grade report — no selector needed
  html += `<a class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;margin-bottom:6px;gap:8px"
    href="api/report.php?class_id=${CLASS_ID}&type=final" target="_blank">
    &#128196; Final Grade Report</a>`;

  if (DATA.terms.length) {
    html += `<div style="font-size:.75rem;font-weight:700;color:var(--ink-soft);letter-spacing:.05em;
      padding:6px 4px 6px;border-top:1px solid var(--line);margin-top:4px">TERM REPORTS</div>`;
    DATA.terms.forEach(t => {
      html += `<button class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;margin-bottom:4px;gap:8px"
        onclick="openCriteriaSelector('${esc(t)}');document.getElementById('reportMenu').style.display='none'">
        &#128203; ${esc(t)} — Select Criteria &amp; Print</button>`;
    });

    html += `<div style="font-size:.75rem;font-weight:700;color:var(--ink-soft);letter-spacing:.05em;
      padding:6px 4px 6px;border-top:1px solid var(--line);margin-top:4px">ATTENDANCE</div>`;
    DATA.terms.forEach(t => {
      html += `<a class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;margin-bottom:4px;gap:8px"
        href="api/report.php?class_id=${CLASS_ID}&type=attendance&term=${encodeURIComponent(t)}" target="_blank">
        &#128203; ${esc(t)} Attendance</a>`;
    });
  }

  document.getElementById('reportMenu').innerHTML = html;
}

/** Show a modal for the user to pick which criteria to include in the term report */
async function openCriteriaSelector(term) {
  document.getElementById('reportMenu').style.display = 'none';
  const crits = (DATA.criteria || []).filter(c => c.term === term);
  if (!crits.length) {
    window.open(`api/report.php?class_id=${CLASS_ID}&type=term&term=${encodeURIComponent(term)}`, '_blank');
    return;
  }
  const existing = document.getElementById('critSelectorModal');
  if (existing) existing.remove();

  // Build criteria + activities checklist
  const critRows = crits.map(c => {
    const acts = (c.activities || []);
    const actList = acts.length ? `
      <div style="margin:6px 0 2px 26px;display:flex;flex-direction:column;gap:4px">
        ${acts.map(a => `
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                 padding:4px 8px;border-radius:6px;background:var(--paper);font-size:.83rem">
            <input type="checkbox" class="act-cb" data-crit-id="${c.id}" data-act-id="${a.id}"
              checked style="accent-color:var(--amber)">
            <span>${esc(a.label)}</span>
            <span class="muted" style="font-size:.75rem">/${a.perfect_score}</span>
          </label>`).join('')}
      </div>` : `<div class="muted" style="margin:4px 0 2px 26px;font-size:.8rem">No activities</div>`;

    return `<div style="border:1px solid var(--line);border-radius:8px;overflow:hidden;margin-bottom:8px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
             padding:9px 12px;background:var(--paper-2)">
        <input type="checkbox" class="crit-cb" data-crit-id="${c.id}" checked
          style="width:15px;height:15px;accent-color:var(--amber)"
          onchange="toggleCritActivities(${c.id}, this.checked)">
        <strong>${esc(c.name)}</strong>
        <span class="muted" style="font-size:.82rem;margin-left:4px">${c.weight}%</span>
        <span class="muted" style="font-size:.78rem;margin-left:auto">${acts.length} activit${acts.length===1?'y':'ies'}</span>
      </label>
      ${actList}
    </div>`;
  }).join('');

  const modal = document.createElement('div');
  modal.className = 'modal-backdrop show';
  modal.id = 'critSelectorModal';
  modal.innerHTML = `
    <div class="modal" style="max-width:520px;max-height:85vh;overflow:auto">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h2 style="margin:0">&#128196; ${esc(term)} Report</h2>
        <button class="btn btn-ghost btn-sm"
          onclick="document.getElementById('critSelectorModal').remove()">&#10005; Close</button>
      </div>
      <p class="muted" style="margin-top:-4px;margin-bottom:14px;font-size:.88rem">
        Check the criteria and activities to include. Uncheck any to exclude them from the printout.
      </p>
      <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="toggleAllInSelector(true)">&#10003; Select All</button>
        <button class="btn btn-ghost btn-sm" onclick="toggleAllInSelector(false)">&#9633; Deselect All</button>
      </div>
      <div id="critActList">${critRows}</div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;
           border-top:1px solid var(--line);padding-top:14px">
        <button class="btn btn-ghost" onclick="document.getElementById('critSelectorModal').remove()">Cancel</button>
        <button class="btn btn-primary" onclick="printTermReport('${esc(term)}')">&#128196; Print Report</button>
      </div>
    </div>`;
  document.body.appendChild(modal);
}

function toggleCritActivities(critId, checked) {
  document.querySelectorAll(`.act-cb[data-crit-id="${critId}"]`).forEach(cb => cb.checked = checked);
}

function toggleAllInSelector(checked) {
  document.querySelectorAll('.crit-cb, .act-cb').forEach(cb => cb.checked = checked);
}

function printTermReport(term) {
  const checkedCrits = [...document.querySelectorAll('.crit-cb:checked')].map(cb => cb.dataset.critId);
  const checkedActs  = [...document.querySelectorAll('.act-cb:checked')].map(cb => cb.dataset.actId);
  const allCrits     = [...document.querySelectorAll('.crit-cb')].map(cb => cb.dataset.critId);
  const allActs      = [...document.querySelectorAll('.act-cb')].map(cb => cb.dataset.actId);

  document.getElementById('critSelectorModal').remove();

  let url = `api/report.php?class_id=${CLASS_ID}&type=term&term=${encodeURIComponent(term)}`;
  // Only send filters if something was deselected
  if (checkedCrits.length < allCrits.length && checkedCrits.length > 0)
    url += '&criteria_ids=' + checkedCrits.join(',');
  if (checkedActs.length < allActs.length && checkedActs.length > 0)
    url += '&activity_ids=' + checkedActs.join(',');
  window.open(url, '_blank');
}

/* ------------------------------------------------------------------ */

function toggleReportMenu(e) {
  e.stopPropagation();
  const m = document.getElementById('reportMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', () => { const m = document.getElementById('reportMenu'); if (m) m.style.display = 'none'; });

function buildCsvMenu() {
  const menu = document.getElementById('csvMenu');
  if (!menu) return;
  let html = `<div style="font-size:.75rem;font-weight:700;color:var(--ink-soft);letter-spacing:.05em;
    padding:2px 4px 6px;border-bottom:1px solid var(--line);margin-bottom:8px">CSV / EXCEL EXPORT</div>`;
  html += `<a class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;margin-bottom:4px;gap:8px"
    href="api/export_csv.php?class_id=${CLASS_ID}&type=final">
    &#11015; Final Grades (.csv)</a>`;
  if (DATA.terms && DATA.terms.length) {
    html += `<div style="font-size:.75rem;font-weight:700;color:var(--ink-soft);letter-spacing:.05em;
      padding:6px 4px 6px;border-top:1px solid var(--line);margin-top:4px">TERM GRADES</div>`;
    DATA.terms.forEach(t => {
      html += `<a class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;margin-bottom:4px;gap:8px"
        href="api/export_csv.php?class_id=${CLASS_ID}&type=term&term=${encodeURIComponent(t)}">
        &#11015; ${esc(t)} Grades (.csv)</a>`;
    });
  }
  menu.innerHTML = html;
}

function toggleCsvMenu(e) {
  e.stopPropagation();
  const m = document.getElementById('csvMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', () => { const m = document.getElementById('csvMenu'); if (m) m.style.display = 'none'; });

/* ------------------------------------------------------------------ */
/* Class-wide AI Analysis — per term + final grade tabs               */
/* ------------------------------------------------------------------ */
let _analysisCache = {};   // scope → result, avoids re-running same scope

async function analyzeAll() {
  _analysisCache = {};    // fresh cache each time the modal opens
  document.getElementById('classAnalysisModal').classList.add('show');

  // Build tab bar: one tab per term + one for Final Grade
  const scopes = [...DATA.terms, 'final'];
  document.getElementById('analysisTabBar').innerHTML = scopes.map(s => {
    const label = s === 'final' ? '&#x2211; Final Grade' : esc(s);
    return `<button class="btn btn-ghost btn-sm analysis-scope-tab" data-scope="${esc(s)}"
      style="border-bottom:3px solid transparent;border-radius:6px 6px 0 0;margin-bottom:-1px;padding:7px 14px"
      onclick="loadAnalysisScope('${esc(s)}')">${label}</button>`;
  }).join('');

  // Auto-open the active term tab, or first term, or final
  const defaultScope =
    (activeTerm && activeTerm !== 'FINAL' && activeTerm !== 'ATTENDANCE')
      ? activeTerm
      : (DATA.terms[0] || 'final');
  await loadAnalysisScope(defaultScope);
}

async function loadAnalysisScope(scope) {
  // Highlight the active tab
  document.querySelectorAll('.analysis-scope-tab').forEach(btn => {
    const on = btn.dataset.scope === scope;
    btn.style.borderBottomColor = on ? 'var(--amber)' : 'transparent';
    btn.style.background        = on ? 'var(--paper)'  : '';
    btn.style.fontWeight        = on ? '700' : '400';
    btn.style.color             = on ? 'var(--amber)'  : '';
  });

  const body       = document.getElementById('classAnalysisBody');
  const scopeLabel = scope === 'final' ? 'Final Grade' : scope;

  // Use cached result if available
  if (_analysisCache[scope]) {
    renderClassAnalysis(_analysisCache[scope], scope);
    return;
  }

  body.innerHTML = `<div class="empty"><div class="spinner"></div>
    Analysing ${DATA.students.length} student${DATA.students.length!==1?'s':''} for
    <strong>${esc(scopeLabel)}</strong>… this may take a moment.</div>`;

  try {
    const r    = await fetch(`api/analyze_class.php?class_id=${CLASS_ID}&type=${encodeURIComponent(scope)}`);
    const text = await r.text();
    let j;
    try { j = JSON.parse(text); }
    catch(pe) {
      body.innerHTML = `<div class="alert alert-error">
        Analysis API returned an unexpected response.
        <details style="margin-top:8px"><summary>Technical detail</summary>
        <pre style="font-size:.75rem;overflow:auto;max-height:120px">${esc(text.slice(0,600))}</pre>
        </details></div>`;
      return;
    }
    if (!j.ok) {
      const hint = j.install_hint
        ? `<div class="help-note" style="margin-top:10px"><strong>How to fix:</strong> ${esc(j.install_hint)}</div>` : '';
      body.innerHTML = `<div class="alert alert-error">${esc(j.error||'Analysis failed')}</div>${hint}`;
      return;
    }
    _analysisCache[scope] = j;
    renderClassAnalysis(j, scope);
  } catch(e) {
    body.innerHTML = `<div class="alert alert-error">Could not reach the analysis API: ${esc(String(e))}</div>`;
  }
}


function renderClassAnalysis(j, scope) {
  const s   = j.summary;
  const pct = s.passing_rate !== null ? s.passing_rate + '%' : '—';
  const riskColor = { high:'var(--red)', medium:'var(--amber)', low:'var(--green)' };
  const riskBg    = { high:'#fdecea',    medium:'#fff8e6',      low:'#edf7f1' };

  // ── Summary cards ─────────────────────────────────────────────────
  let html = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:10px">
      <div class="muted" style="font-size:.85rem">
        Scope: <strong>${esc(j.scope_label || scope)}</strong> &nbsp;·&nbsp; ${s.graded} of ${s.total} students graded
      </div>
      <button class="btn btn-dark btn-sm" onclick="printAnalysisReport()">&#128196; Print AI Report (PDF)</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
      <div class="card" style="padding:14px;text-align:center;border-top:3px solid var(--ink)">
        <div style="font-size:2rem;font-weight:700">${s.avg_grade ?? '—'}</div>
        <div class="muted" style="font-size:.8rem">Class Average</div>
      </div>
      <div class="card" style="padding:14px;text-align:center;border-top:3px solid var(--green)">
        <div style="font-size:2rem;font-weight:700;color:var(--green)">${s.passing}</div>
        <div class="muted" style="font-size:.8rem">Passing (${pct})</div>
      </div>
      <div class="card" style="padding:14px;text-align:center;border-top:3px solid var(--red)">
        <div style="font-size:2rem;font-weight:700;color:var(--red)">${s.failing}</div>
        <div class="muted" style="font-size:.8rem">Failing</div>
      </div>
      <div class="card" style="padding:14px;text-align:center;border-top:3px solid var(--amber)">
        <div style="font-size:2rem;font-weight:700;color:var(--amber)">${s.at_risk}</div>
        <div class="muted" style="font-size:.8rem">Need Intervention</div>
      </div>
    </div>`;

  // ── Per-student action items ──────────────────────────────────────
  html += `<h3 style="margin:0 0 10px">📋 Individual Student Action Plans</h3>`;
  j.results.forEach((r, i) => {
    const rc  = riskColor[r.risk_level] || 'var(--green)';
    const rb  = riskBg[r.risk_level]   || '#edf7f1';
    const rowBg = i % 2 === 0 ? '#fff' : 'var(--paper-2)';
    const actions = (r.actions || []);
    const weak    = (r.weak_areas || []).slice(0, 3);

    const weakTags = weak.map(w =>
      `<span style="display:inline-block;padding:1px 8px;margin:1px;border-radius:999px;
        background:${w.pct>=75?'#edf7f1':w.pct>=60?'#fff8e6':'#fdecea'};
        color:${w.pct>=75?'var(--green)':w.pct>=60?'var(--amber)':'var(--red)'};font-size:.75rem">
        ${esc(w.name)} ${w.pct}%</span>`).join('');

    const actList = actions.map((a, ai) =>
      `<li style="margin-bottom:5px;font-size:.83rem;line-height:1.5">
        <span style="font-weight:700;color:var(--amber)">${ai+1}.</span> ${esc(a)}
      </li>`).join('');

    html += `<div style="background:${rowBg};border:1px solid var(--line);border-radius:10px;
               margin-bottom:10px;overflow:hidden">
      <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                  border-bottom:1px solid var(--line);flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <strong style="font-size:.95rem">${esc(r.name)}</strong>
          <span class="muted" style="font-size:.83rem;margin-left:8px">Grade: ${r.grade ?? '—'}</span>
        </div>
        <span style="padding:3px 12px;border-radius:999px;background:${rb};color:${rc};
                     font-size:.75rem;font-weight:700;text-transform:uppercase;flex-shrink:0">
          ${esc(r.risk_level)} · ${r.risk_score}
        </span>
        ${weakTags}
      </div>
      <div style="padding:10px 14px">
        <div style="font-weight:600;font-size:.8rem;color:var(--ink-soft);margin-bottom:6px">
          ✅ WHAT TO DO TO IMPROVE
        </div>
        <ol style="margin:0;padding:0;list-style:none">${actList || '<li style="font-size:.83rem;color:var(--ink-soft)">No specific actions needed — keep up the good work.</li>'}</ol>
      </div>
    </div>`;
  });

  // ── Class narrative / summary ─────────────────────────────────────
  if (j.narrative) {
    html += `<div class="card" style="margin-top:20px;padding:18px 22px;
               border-left:4px solid var(--amber);background:var(--amber-soft)">
      <div style="font-weight:700;font-size:.95rem;margin-bottom:10px">
        🧠 Overall Class Analysis &amp; Teacher Recommendations
      </div>
      <p style="margin:0;line-height:1.7;font-size:.9rem">${esc(j.narrative)}</p>
    </div>`;
  }

  html += `<div class="muted" style="font-size:.75rem;margin-top:12px;text-align:right">
    Offline rule-based analysis · Use as professional decision support only
  </div>`;

  document.getElementById('classAnalysisBody').innerHTML = html;

  // Store for PDF printing — keyed by scope so switching tabs keeps each result
  window._lastAnalysis = j;
  window._lastAnalysisScope = scope;
}

function printAnalysisReport() {
  const j = window._lastAnalysis;
  if (!j) { toast('No analysis data — run Analyze All first'); return; }
  // POST the JSON to the PDF report endpoint via a hidden form
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'api/analysis_report.php?class_id=' + CLASS_ID;
  form.target = '_blank';
  const input = document.createElement('input');
  input.type  = 'hidden';
  input.name  = 'payload';
  input.value = JSON.stringify(j);
  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

/* ------------------------------------------------------------------ */
async function analyze(sid) {
  document.getElementById('analysisModal').classList.add('show');
  document.getElementById('analysisBody').innerHTML = '<div class="empty"><div class="spinner"></div> Analyzing...</div>';
  try {
    const r = await fetch('api/analyze.php?student_id=' + sid);
    const j = await r.json();
    if (!j.ok) {
      const hint = j.install_hint
        ? `<div class="help-note" style="margin-top:12px"><strong>How to fix:</strong> ${esc(j.install_hint)}</div>`
        : '';
      document.getElementById('analysisBody').innerHTML =
        `<h3 style="margin-top:0;color:var(--red)">Analysis engine error</h3>
         <div class="alert alert-error">${esc(j.error || 'Unknown error')}</div>
         ${hint}`;
      return;
    }
    renderAnalysis(j);
  } catch (e) {
    document.getElementById('analysisBody').innerHTML =
      '<div class="alert alert-error">Could not reach the analysis API. Check your server is running.</div>';
  }
}

function renderAnalysis(j) {
  const a  = j.analysis.local;
  const rc = a.risk_level === 'high' ? 'risk-high' : a.risk_level === 'medium' ? 'risk-medium' : 'risk-low';
  const actions = (a.actions || a.recommendations || []);

  const weakHtml = (a.weak_areas || []).map(w => `
    <li style="margin-bottom:8px">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <strong>${esc(w.name)}</strong>
        <span class="muted" style="font-size:.8rem">${esc(w.term)} · ${w.weight}% weight</span>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
        <div style="flex:1;height:7px;background:var(--line);border-radius:4px;overflow:hidden">
          <div style="width:${Math.min(100,w.pct)}%;height:100%;background:${w.pct>=75?'var(--green)':w.pct>=60?'var(--amber)':'var(--red)'};border-radius:4px"></div>
        </div>
        <span style="font-size:.82rem;font-weight:600;min-width:36px;text-align:right">${w.pct}%</span>
      </div>
    </li>`).join('');

  const actHtml = actions.map((act, i) => `
    <li style="margin-bottom:10px;padding:10px 14px;background:${i===0?'var(--amber-soft)':'var(--paper-2)'};
               border-left:3px solid ${i===0?'var(--amber)':'var(--line)'};border-radius:0 8px 8px 0;
               font-size:.88rem;line-height:1.55">
      <span style="font-weight:700;color:var(--amber);margin-right:6px">${i+1}.</span>${esc(act)}
    </li>`).join('');

  document.getElementById('analysisBody').innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:10px;flex-wrap:wrap">
      <h2 style="margin:0">${esc(j.student.name)}</h2>
      <span style="font-size:.82rem;color:var(--ink-soft)">Grade: <strong>${a.reference_grade ?? '—'}</strong></span>
    </div>
    <div class="analysis">
      <div class="risk-banner ${rc}" style="margin-bottom:14px">
        <div class="score">${a.risk_score}</div>
        <div>
          <strong>${a.risk_level.toUpperCase()} intervention priority</strong>
          ${a.trend ? `<span style="font-size:.85rem;margin-left:8px;opacity:.8">· ${esc(a.trend)} trend</span>` : ''}
          ${a.reasons.length ? `<div style="font-size:.83rem;margin-top:4px;opacity:.9">${esc(a.reasons[0])}</div>` : ''}
        </div>
      </div>

      ${weakHtml ? `<div style="margin-bottom:16px">
        <div style="font-weight:700;margin-bottom:8px;font-size:.9rem">📊 Performance Areas (by grade impact)</div>
        <ul style="list-style:none;margin:0;padding:0">${weakHtml}</ul>
      </div>` : ''}

      <div>
        <div style="font-weight:700;margin-bottom:10px;font-size:.9rem">✅ What You Can Do To Improve</div>
        <ol style="list-style:none;margin:0;padding:0">${actHtml}</ol>
      </div>
      <div class="muted" style="font-size:.75rem;margin-top:14px;text-align:right">
        Offline analysis · Use as decision support only
      </div>
    </div>`;
}

window.addEventListener('beforeunload', e => { if (dirty.size) { e.preventDefault(); e.returnValue = ''; } });
