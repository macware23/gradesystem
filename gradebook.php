<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$classId  = (int)($_GET['class_id'] ?? 0);
if (!owns_class($classId)) { header('Location: ' . (is_admin() ? 'admin.php' : (is_chair() ? 'chair.php' : 'dashboard.php'))); exit; }
$readonly = !can_write_class($classId);  // true for admin, chair, and any non-owner
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
<title>Gradebook<?= $readonly ? ' (Read-Only)' : '' ?> — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<?php if ($readonly): ?>
<div style="background:var(--blue-soft);color:var(--blue);padding:10px 28px;font-size:.9rem;font-weight:500;border-bottom:1px solid var(--line)">
  &#128065; Read-only view — grades cannot be modified.
  <?php if (is_chair()): ?>
    <a href="chair.php" style="margin-left:12px">&#8592; Back to Faculty</a>
  <?php elseif (is_admin()): ?>
    <a href="admin.php" style="margin-left:12px">&#8592; Back to Admin</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="wrap" style="max-width:100%">
  <div class="page-head">
    <div>
      <h1 id="className">Gradebook</h1>
      <div class="sub" id="classMeta"></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (!$readonly): ?>
      <button class="btn btn-ghost" onclick="openCriteria()">&#9881; Setup Criteria &amp; Activities</button>
      <button class="btn btn-ghost" onclick="openStudents()">&#43; Students</button>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= is_chair() ? 'chair.php' : 'dashboard.php' ?>">&larr; <?= is_chair() ? 'Faculty' : 'Classes' ?></a>
    </div>
  </div>

  <div class="sheet-toolbar" id="termTabs"></div>

  <div class="card" style="padding:14px">
    <div class="sheet-toolbar">
      <span class="muted" id="transNote"></span>
      <span class="toolbar-spacer" style="flex:1"></span>
      <button class="btn btn-primary btn-sm" id="saveBtn" onclick="saveDirty()" disabled>Save changes</button>
      <button class="btn btn-ghost btn-sm" id="analyzeAllBtn" onclick="analyzeAll()" title="AI analysis for all students">
        &#129504; Analyze All
      </button>
      <div style="position:relative">
        <button class="btn btn-dark btn-sm" onclick="toggleReportMenu(event)">&#8595; PDF &#9662;</button>
        <div id="reportMenu" class="card" style="display:none;position:absolute;right:0;top:110%;z-index:30;padding:10px;min-width:240px;box-shadow:0 8px 24px rgba(0,0,0,.15)"></div>
      </div>
      <?php if (!$readonly): ?>
      <div style="position:relative">
        <button class="btn btn-dark btn-sm" onclick="toggleCsvMenu(event)">&#8595; CSV &#9662;</button>
        <div id="csvMenu" class="card" style="display:none;position:absolute;right:0;top:110%;z-index:30;padding:10px;min-width:220px;box-shadow:0 8px 24px rgba(0,0,0,.15)"></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="sheet-scroll">
      <table class="sheet" id="sheet"><tbody><tr><td class="empty">Loading...</td></tr></tbody></table>
    </div>
    <div class="help-note" style="margin-top:12px">
      Each activity shows <strong>raw</strong> (you type) and its <strong>equivalent</strong> (computed). Scores are capped at each activity's perfect score. AVG = mean of equivalents; WS = AVG &times; weight.
    </div>
  </div>
</div>

<!-- Graded-item delete confirmation modal -->
<div class="modal-backdrop" id="gradedDeleteModal" style="z-index:200">
  <div class="modal" style="max-width:460px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0;color:var(--red)">&#9888; Graded Scores Detected</h2>
      <button class="btn btn-ghost btn-sm" onclick="cancelGradedDelete()">&#10005; Close</button>
    </div>
    <div id="gradedDeleteMsg" style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;
         border-radius:8px;padding:12px 14px;font-size:.88rem;margin-bottom:14px;line-height:1.5"></div>
    <p style="margin:0 0 12px;font-size:.88rem;color:var(--ink-soft)">
      To confirm this deletion, enter your account password below.
    </p>
    <div class="field">
      <label>Your Account Password</label>
      <input type="password" id="gradedDeletePass" placeholder="Enter your password"
             autocomplete="current-password"
             onkeydown="if(event.key==='Enter')confirmGradedDelete()">
    </div>
    <div id="gradedDeleteErr" class="alert alert-error" style="display:none;margin-bottom:4px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button class="btn btn-ghost" onclick="cancelGradedDelete()">Cancel</button>
      <button id="gradedDeleteBtn" class="btn btn-primary"
              style="background:var(--red);border-color:var(--red)"
              onclick="confirmGradedDelete()">&#128465; Delete Anyway</button>
    </div>
  </div>
</div>

<!-- Criteria + Activities modal -->
<div class="modal-backdrop" id="criteriaModal">
  <div class="modal" style="max-width:820px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h2 style="margin:0">Criteria &amp; Activities</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('criteriaModal')">&#10005; Close</button>
    </div>
    <p class="muted">Build each term like your sheet: criteria (Quizzes, Term Exam...) each with a weight, and the individual activities under them (Q1, Q2...) each with its own perfect score.</p>
    <div id="critByTerm"></div>
    <h3 style="margin-top:22px">Term Weights &rarr; Final Grade</h3>
    <div id="termWeightRows" class="row" style="flex-wrap:wrap"></div>
    <div class="help-note" id="critWeightStatus" style="margin-top:10px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button class="btn btn-ghost" onclick="closeModal('criteriaModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveCriteria()">Save Setup</button>
    </div>
  </div>
</div>

<!-- Students modal — manage existing + add new -->
<div class="modal-backdrop" id="studentsModal">
  <div class="modal" style="max-width:700px;max-height:92vh;display:flex;flex-direction:column;gap:0;padding:0;overflow:hidden">

    <!-- Fixed header -->
    <div style="padding:20px 24px 16px;border-bottom:1px solid var(--line);flex-shrink:0">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="margin:0">&#128101; Student List</h2>
        <button class="btn btn-ghost btn-sm" onclick="closeModal('studentsModal')">&#10005; Close</button>
      </div>
    </div>

    <!-- Edit panel — shown when pencil is clicked, hidden otherwise -->
    <div id="studentEditPanel" style="display:none;padding:16px 24px;background:var(--amber-soft);border-bottom:2px solid var(--amber);flex-shrink:0">
      <div style="font-size:.82rem;font-weight:700;color:var(--amber);margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em">
        &#9998; Editing Student <span id="editPanelName" style="font-weight:400;text-transform:none"></span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 140px 1fr;gap:10px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px">Last Name *</label>
          <input id="ef_last" placeholder="Last name"
            style="width:100%;padding:7px 10px;border:1.5px solid var(--amber);border-radius:7px;font-size:.9rem;box-sizing:border-box">
        </div>
        <div>
          <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px">First Name</label>
          <input id="ef_first" placeholder="First name"
            style="width:100%;padding:7px 10px;border:1.5px solid var(--line);border-radius:7px;font-size:.9rem;box-sizing:border-box">
        </div>
        <div>
          <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px">Student No.</label>
          <input id="ef_no" placeholder="2021-00001"
            style="width:100%;padding:7px 10px;border:1.5px solid var(--line);border-radius:7px;font-size:.9rem;box-sizing:border-box">
        </div>
        <div>
          <label style="display:block;font-size:.75rem;font-weight:600;margin-bottom:4px">Email</label>
          <input id="ef_email" placeholder="optional"
            style="width:100%;padding:7px 10px;border:1.5px solid var(--line);border-radius:7px;font-size:.9rem;box-sizing:border-box">
        </div>
      </div>
      <div id="efErr" style="display:none;color:var(--red);font-size:.83rem;margin-bottom:8px;padding:6px 10px;background:#fff0f0;border-radius:6px"></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-sm" id="efSaveBtn" onclick="commitStudentEdit()">&#10003; Save Changes</button>
        <button class="btn btn-ghost btn-sm" onclick="closeStudentEdit()">Cancel</button>
      </div>
    </div>

    <!-- Scrollable student table -->
    <div style="flex:1;overflow-y:auto;min-height:0">
      <table style="width:100%;border-collapse:collapse;font-size:.9rem">
        <thead>
          <tr style="background:var(--ink);color:var(--paper);position:sticky;top:0;z-index:2">
            <th style="padding:9px 12px;text-align:left;font-weight:600;width:100px">Student No.</th>
            <th style="padding:9px 12px;text-align:left;font-weight:600">Last Name</th>
            <th style="padding:9px 12px;text-align:left;font-weight:600">First Name</th>
            <th style="padding:9px 12px;text-align:left;font-weight:600;width:170px">Email</th>
            <th style="padding:9px 8px;width:76px"></th>
          </tr>
        </thead>
        <tbody id="studentMgmtBody">
          <tr><td colspan="5" style="padding:24px;text-align:center;color:var(--ink-soft)">Loading…</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Fixed footer — add students -->
    <div style="border-top:1px solid var(--line);flex-shrink:0">
      <details id="addStudentsDetails" style="padding:0">
        <summary style="cursor:pointer;padding:12px 24px;font-weight:600;font-size:.9rem;list-style:none;
          display:flex;align-items:center;gap:8px;color:var(--amber)">
          <span style="font-size:1.1rem">&#43;</span> Add New Students
        </summary>
        <div style="padding:0 24px 18px">
          <div style="font-size:.83rem;color:var(--ink-soft);margin-bottom:6px">
            One per line: <em>LastName, FirstName</em> &nbsp;or&nbsp; <em>StudentNo, LastName, FirstName</em>
          </div>
          <textarea id="bulkBox" rows="4"
            style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:.88rem;resize:vertical"
            placeholder="Acas, Angel Nicole&#10;Albela, Christine Angel&#10;2025-001, Amarela, Lance"></textarea>
          <button class="btn btn-primary btn-sm" style="margin-top:8px" onclick="bulkAddStudents()">Add Students</button>
        </div>
      </details>
    </div>

  </div>
</div>

<!-- Class-wide Analysis Modal -->
<div class="modal-backdrop" id="classAnalysisModal">
  <div class="modal" style="max-width:860px;max-height:92vh;display:flex;flex-direction:column;padding:0;overflow:hidden">
    <div style="padding:16px 24px 12px;border-bottom:1px solid var(--line);flex-shrink:0;
                display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h2 style="margin:0">&#129504; AI Intervention Analysis</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('classAnalysisModal')">&#10005; Close</button>
    </div>
    <!-- Term scope tabs — built by JS -->
    <div id="analysisTabBar" style="padding:10px 24px 0;border-bottom:1px solid var(--line);
         flex-shrink:0;display:flex;gap:6px;flex-wrap:wrap;background:var(--paper-2)"></div>
    <!-- Content area -->
    <div id="classAnalysisBody" style="flex:1;overflow-y:auto;padding:20px 24px"></div>
  </div>
</div>

<!-- Analysis modal -->
<div class="modal-backdrop" id="analysisModal">
  <div class="modal" style="max-width:640px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0">&#129504; Student Analysis</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('analysisModal')">&#10005; Close</button>
    </div>
    <div id="analysisBody"><div class="empty"><div class="spinner"></div> Analyzing...</div></div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script src="assets/js/gradebook.js"></script>
<script>
const CLASS_ID = <?= $classId ?>;
const READONLY = <?= $readonly ? 'true' : 'false' ?>;
initGradebook();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body>
</html>
