<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$me = db()->prepare('SELECT full_name FROM teachers WHERE id=?');
$me->execute([current_teacher_id()]);
$teacherName = $me->fetchColumn();
$_pageSubtitle = school_settings()['system_subtitle'] ?? 'GradeFlow';

// Helper: renders a compact color picker + RGB text input pair for a PDF color field
function pdfColorField(string $id, string $defaultRgb): string {
    $parts = array_map('trim', explode(',', $defaultRgb));
    $hex = '#' . implode('', array_map(fn($v) => str_pad(dechex((int)$v), 2, '0', STR_PAD_LEFT), $parts));
    return '<div style="display:flex;gap:6px;align-items:center">
      <input type="color" id="'.$id.'_pick" value="'.$hex.'"
        style="width:38px;height:32px;padding:1px;border-radius:6px;cursor:pointer;flex-shrink:0"
        oninput="pdfPickerChanged(\''.$id.'\')">
      <input id="'.$id.'" value="'.$defaultRgb.'" placeholder="'.$defaultRgb.'"
        style="width:92px;font-size:.8rem;padding:5px 7px"
        oninput="pdfRgbChanged(\''.$id.'\')">
    </div>';
}
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Settings — <?= htmlspecialchars($_pageSubtitle) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.font-card {
  border:2px solid var(--line); border-radius:12px; padding:12px 14px;
  cursor:pointer; transition:border-color .15s, background .15s; text-align:center;
}
.font-card:hover { border-color:var(--amber); background:var(--amber-soft); }
.font-card.active { border-color:var(--amber); background:var(--amber-soft); }
.font-card .sample { font-size:1.3rem; line-height:1.3; margin-bottom:4px; }
.font-card .label  { font-size:.78rem; color:var(--ink-soft); }
.pdf-font-card {
  border:2px solid var(--line); border-radius:10px; padding:10px 12px;
  cursor:pointer; transition:border-color .15s; text-align:center; flex:1; min-width:110px;
}
.pdf-font-card:hover { border-color:var(--amber); }
.pdf-font-card.active { border-color:var(--amber); background:var(--amber-soft); }
.pdf-font-card .sample { font-size:1.1rem; margin-bottom:3px; }
/* Header font style toggle buttons */
.hdr-style-grp { display:inline-flex; gap:3px; }
.hdr-style-btn {
  width:28px; height:28px; border:1px solid var(--line); border-radius:5px;
  background:var(--paper-2); cursor:pointer; font-size:.82rem; line-height:1;
  display:inline-flex; align-items:center; justify-content:center;
  transition:background .12s, border-color .12s;
}
.hdr-style-btn:hover { border-color:var(--amber); background:var(--amber-soft); }
.hdr-style-btn.active { background:var(--amber); border-color:var(--amber); color:#fff; font-weight:700; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/topbar.php'; ?>
<div class="wrap" style="max-width:900px">
  <div class="page-head">
    <div><h1>Settings</h1>
      <div class="sub">Customize school branding, fonts, colors, and report appearance.</div>
    </div>
  </div>

  <!-- ─── Faculty / Chair Profile ─── -->
  <?php if (!is_admin()): ?>
  <div class="card" style="margin-bottom:20px" id="profileCard">
    <h2 style="margin:0 0 4px">&#128100; My Profile</h2>
    <p class="muted" style="font-size:.85rem;margin:0 0 18px">Update your personal information. Your name appears on all PDF reports.</p>
    <div class="row">
      <div class="field" style="flex:2">
        <label>Complete Name *</label>
        <input id="prof_name" placeholder="Full name as it appears on reports">
      </div>
      <div class="field" style="flex:2">
        <label>Email Address *</label>
        <input id="prof_email" type="email" placeholder="your@email.com">
      </div>
    </div>

    <?php if (!is_chair()): ?>
    <div class="row">
      <div class="field" style="flex:2">
        <label>College Assigned</label>
        <select id="prof_college" onchange="loadDeptDropdown('prof_college','prof_dept',this.value)">
          <option value="">— Select College —</option>
        </select>
      </div>
      <div class="field" style="flex:2">
        <label>Department / Program Assigned</label>
        <select id="prof_dept">
          <option value="">— Select Department —</option>
        </select>
      </div>
    </div>
    <?php else: ?>
    <input type="hidden" id="prof_college" value="">
    <input type="hidden" id="prof_dept" value="">
    <?php endif; ?>

    <div style="border-top:1px solid var(--line);padding-top:16px;margin-top:4px">
      <div style="font-weight:600;font-size:.9rem;margin-bottom:12px">
        Change Password
        <span class="muted" style="font-size:.82rem;font-weight:400">&nbsp;— leave blank to keep current</span>
      </div>
      <div class="row">
        <div class="field">
          <label>Current Password</label>
          <input id="prof_cur_pw" type="password" autocomplete="current-password" placeholder="Required to change password">
        </div>
        <div class="field">
          <label>New Password</label>
          <input id="prof_new_pw" type="password" autocomplete="new-password" placeholder="Min 6 characters">
        </div>
        <div class="field">
          <label>Confirm New Password</label>
          <input id="prof_new_pw2" type="password" autocomplete="new-password" placeholder="Repeat new password">
        </div>
      </div>
    </div>
    <div id="prof_err" style="display:none;color:var(--red);font-size:.85rem;padding:8px 12px;background:#fdecea;border-radius:8px;margin-bottom:10px"></div>
    <div id="prof_ok"  style="display:none;color:#155724;font-size:.85rem;padding:8px 12px;background:#d4edda;border-radius:8px;margin-bottom:10px"></div>
    <div style="display:flex;justify-content:flex-end">
      <button class="btn btn-primary" onclick="saveProfile()">Save Profile</button>
    </div>
  </div>

  <!-- ─── Report Header Settings (teachers & chairs only) ─── -->
  <?php if (!is_admin()): ?>
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--amber)" id="reportHeaderCard">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
      <h2 style="margin:0">&#128196; Report Header Settings</h2>
      <span class="pill pill-amber">&#128100; Your personal settings</span>
    </div>
    <p class="muted" style="font-size:.88rem;margin:0 0 18px">
      Customize the header that appears on all your PDF grade reports.
      Adjust the font styles for each header line.
      The report logo is managed by the administrator and applies to all accounts.
      The schedule of class is set individually on each class (New Class / Edit Class).
    </p>

    <!-- Header font styles -->
    <div style="margin-top:20px;margin-bottom:8px;font-size:.88rem;font-weight:600;color:var(--ink)">
      Header Font Styles
    </div>
    <p class="muted" style="font-size:.82rem;margin:0 0 10px">
      Set the font family, size, and style for each line of the report header.
    </p>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.82rem">
        <thead>
          <tr style="background:var(--paper-2)">
            <th style="padding:7px 10px;text-align:left;border:1px solid var(--line);white-space:nowrap;font-weight:600">Header Line</th>
            <th style="padding:7px 10px;text-align:left;border:1px solid var(--line);font-weight:600">Font Family</th>
            <th style="padding:7px 8px;text-align:center;border:1px solid var(--line);font-weight:600">Size (pt)</th>
            <th style="padding:7px 10px;text-align:center;border:1px solid var(--line);font-weight:600">Style</th>
          </tr>
        </thead>
        <tbody>
<?php
$hdrLines = [
  ['id'=>'hdr_sem',   'label'=>'Semester / S.Y.',  'defFont'=>'Helvetica','defSize'=>'9',   'defStyle'=>''  ],
  ['id'=>'hdr_lbl',   'label'=>'Grade Label',       'defFont'=>'Times',    'defSize'=>'11',  'defStyle'=>'B' ],
  ['id'=>'hdr_crs',   'label'=>'Course Name',       'defFont'=>'Helvetica','defSize'=>'9.5', 'defStyle'=>''  ],
  ['id'=>'hdr_sec',   'label'=>'Section',           'defFont'=>'Helvetica','defSize'=>'9.5', 'defStyle'=>'B' ],
  ['id'=>'hdr_sch',   'label'=>'Schedule',          'defFont'=>'Helvetica','defSize'=>'9',   'defStyle'=>'B' ],
];
foreach ($hdrLines as $hl):
?>
          <tr>
            <td style="padding:6px 10px;border:1px solid var(--line);white-space:nowrap;color:var(--ink-soft)"><?= $hl['label'] ?></td>
            <td style="padding:5px 8px;border:1px solid var(--line)">
              <select id="<?= $hl['id'] ?>_font" style="width:130px;font-size:.81rem;padding:4px 6px">
                <option value="Helvetica">Helvetica</option>
                <option value="Times">Times New Roman</option>
                <option value="Courier">Courier</option>
              </select>
            </td>
            <td style="padding:5px 8px;border:1px solid var(--line);text-align:center">
              <input type="number" id="<?= $hl['id'] ?>_size" value="<?= $hl['defSize'] ?>"
                min="6" max="24" step="0.5" style="width:58px;text-align:center;font-size:.81rem;padding:4px 6px">
            </td>
            <td style="padding:5px 10px;border:1px solid var(--line);text-align:center">
              <div class="hdr-style-grp" id="<?= $hl['id'] ?>_style_grp">
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="B"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><strong>B</strong></button>
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="I"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><em>I</em></button>
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="U"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><u>U</u></button>
              </div>
              <input type="hidden" id="<?= $hl['id'] ?>_style" value="<?= $hl['defStyle'] ?>">
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Header preview (static) -->
    <div style="margin-top:18px;margin-bottom:4px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Header Layout Preview
    </div>
    <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;
                font-family:Arial,sans-serif;max-width:420px;background:#fff">
      <div style="padding:10px 16px;text-align:center;border-bottom:2px solid #c97b1f">
        <div style="width:38px;height:38px;border-radius:50%;background:var(--line);
                    display:inline-flex;align-items:center;justify-content:center;
                    font-size:.7rem;color:var(--ink-soft);margin-bottom:4px">LOGO</div>
        <div style="font-weight:700;font-size:.9rem">School Name</div>
        <div style="font-size:.75rem;color:#c97b1f">Department / Address</div>
      </div>
      <div style="padding:8px 16px;text-align:center;border-bottom:1px solid var(--line)">
        <div style="font-size:.75rem;color:#555">2nd Semester, A.Y. 2025-2026</div>
        <div style="font-weight:700;font-size:.82rem">FINAL CLASS GRADE</div>
        <div style="font-size:.77rem">Introduction to Computing</div>
        <div style="font-size:.77rem;font-weight:600">BSIS-1A</div>
        <div style="font-size:.75rem;font-weight:600" id="previewSchedule">MWF 7:30-8:30, TTH 7:30-9:00</div>
      </div>
      <div style="padding:4px 16px;font-size:.7rem;color:#aaa;text-align:center">
        Instructor &nbsp;·&nbsp; Passed &nbsp;·&nbsp; Failed
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-primary" onclick="saveHeaderSettings()">Save Header Settings</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if (is_chair()): ?>
  <!-- ── Chair: My College & Department Assignments (read-only) ── -->
  <div class="card" style="margin-bottom:20px" id="chairAssignCard">
    <h2 style="margin:0 0 4px">&#127979; My College &amp; Department Assignments</h2>
    <p class="muted" style="font-size:.85rem;margin:0 0 14px">
      Colleges and departments assigned to your account by the administrator.
      Contact your admin to update these assignments.
    </p>
    <div id="ownAssignmentRows"></div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <!-- ---- College & Department Management (admin only) ---- -->
  <?php if (is_admin()): ?>
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--blue)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <h2 style="margin:0">&#127979; Colleges &amp; Departments</h2>
      <span class="pill pill-blue">&#127758; Used as dropdowns across the system</span>
    </div>
    <p class="muted" style="margin-top:0;font-size:.88rem">
      Define colleges and their departments here. Faculty and Program Chairs will select from these lists instead of typing free text, ensuring consistent data.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" id="cdManagerGrid">
      <!-- Left: college list -->
      <div>
        <div style="font-weight:700;font-size:.88rem;margin-bottom:8px;color:var(--ink)">Colleges</div>
        <div id="collegeList" style="border:1px solid var(--line);border-radius:8px;
             min-height:60px;margin-bottom:10px;overflow:hidden"></div>
        <div style="display:flex;gap:6px">
          <input id="cd_new_college" placeholder="New college name…" style="flex:1">
          <button class="btn btn-primary btn-sm" onclick="saveCollege()">Add</button>
        </div>
      </div>
      <!-- Right: department list (for selected college) -->
      <div>
        <div style="font-weight:700;font-size:.88rem;margin-bottom:8px;color:var(--ink)"
             id="deptHeader">Select a college to manage its departments</div>
        <div id="deptList" style="border:1px solid var(--line);border-radius:8px;
             min-height:60px;margin-bottom:10px;overflow:hidden;background:var(--paper-2)">
          <div class="muted" style="padding:14px;font-size:.84rem;text-align:center">
            ← Click a college first
          </div>
        </div>
        <div id="deptAddRow" style="display:none;flex-wrap:nowrap;gap:6px">
          <input id="cd_new_dept" placeholder="New department / program…" style="flex:1">
          <button class="btn btn-primary btn-sm" onclick="saveDept()">Add</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if (is_admin()): ?>
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--blue)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <h2 style="margin:0">&#127EB; School Information</h2>
      <span class="pill pill-blue">&#127758; Shared by all accounts</span>
    </div>
    <p class="muted" style="margin-top:0;font-size:.88rem">
      These settings are <strong>universal</strong> — changing them here updates them for every teacher
      and admin in the system. Only the school name, address, logo, and subtitle are shared.
    </p>
    <div class="row">
      <div class="field" style="flex:2">
        <label>School Name <span class="muted">(shown in header and reports)</span></label>
        <input id="s_name" placeholder="e.g. Carlos Hilado Memorial State University">
      </div>
      <div class="field" style="flex:3">
        <label>Department / Address <span class="muted">(second line in reports)</span></label>
        <input id="s_addr" placeholder="e.g. College of Engineering, Talisay City">
      </div>
    </div>
    <div class="field">
      <label>System Subtitle <span class="muted">(shown on login page and in report footer)</span></label>
      <input id="s_subtitle" placeholder="e.g. GradeFlow Grading System" style="max-width:440px">
    </div>
  </div>

  <!-- ---- System Logo (navigation bar - admin only) ---- -->
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--blue)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <h2 style="margin:0">&#127968; System Logo</h2>
      <span class="pill pill-blue">&#127758; Shared by all accounts</span>
    </div>
    <p class="muted" style="margin-top:0;font-size:.88rem">
      Appears in the <strong>navigation bar</strong> for all users. This is separate from the PDF report logo.
    </p>
    <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap">
      <div id="logoPreview" style="width:120px;height:120px;border:2px dashed var(--line);
           border-radius:12px;display:flex;align-items:center;justify-content:center;
           background:var(--paper-2);overflow:hidden;flex-shrink:0">
        <span class="muted" style="font-size:.8rem;text-align:center;padding:8px">No logo yet</span>
      </div>
      <div style="flex:1;min-width:220px">
        <p class="muted" style="margin-top:0;font-size:.85rem">PNG, JPG, or SVG. Max 2MB.
          Displayed in the top navigation bar across all pages for every account.</p>
        <input type="file" id="logoInput" accept="image/*" style="margin-bottom:12px">
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary" onclick="uploadLogo()">Upload Logo</button>
          <button class="btn btn-ghost"   onclick="deleteLogo()">Remove Logo</button>
        </div>
        <div id="logoMsg" style="margin-top:8px"></div>
      </div>
    </div>
  </div>

  <!-- ---- Report Header Settings (admin only — global, applies to all PDF reports) ---- -->
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--blue)" id="adminReportHeaderCard">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
      <h2 style="margin:0">&#128196; Report Header Settings</h2>
      <span class="pill pill-blue">&#127758; Shared by all accounts</span>
    </div>
    <p class="muted" style="font-size:.88rem;margin:0 0 18px">
      The report logo and header font styles set here are <strong>universally applied</strong> to all
      PDF grade reports across every faculty and program chair account.
    </p>

    <!-- Report Logo (PDF reports only — separate from system/nav logo) -->
    <div style="margin-bottom:24px">
      <label style="font-weight:600;display:block;margin-bottom:8px">
        Report Logo
        <span class="muted" style="font-weight:400;font-size:.82rem;margin-left:6px">— appears at the top of every PDF grade report</span>
      </label>
      <div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap">
        <div id="reportLogoPreview" style="width:120px;height:120px;border:2px dashed var(--line);
             border-radius:12px;display:flex;align-items:center;justify-content:center;
             background:var(--paper-2);overflow:hidden;flex-shrink:0">
          <span class="muted" style="font-size:.8rem;text-align:center;padding:8px">No report logo</span>
        </div>
        <div style="flex:1;min-width:220px">
          <p class="muted" style="margin-top:0;font-size:.85rem">PNG, JPG, or SVG. Max 2MB.
            Used only in PDF report headers. If none is set, the system logo is used as fallback.</p>
          <input type="file" id="reportLogoInput" accept="image/*" style="margin-bottom:12px">
          <div style="display:flex;gap:8px">
            <button class="btn btn-primary" onclick="uploadReportLogo()">Upload Report Logo</button>
            <button class="btn btn-ghost"   onclick="deleteReportLogo()">Remove</button>
          </div>
          <div id="reportLogoMsg" style="margin-top:8px"></div>
        </div>
      </div>
    </div>

    <!-- Header Font Styles (global defaults for all accounts) -->
    <div style="margin-bottom:8px;font-size:.88rem;font-weight:600;color:var(--ink)">
      Header Font Styles
    </div>
    <p class="muted" style="font-size:.82rem;margin:0 0 10px">
      Set the font family, size, and style for each line of the report header. These defaults apply to all accounts.
    </p>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.82rem">
        <thead>
          <tr style="background:var(--paper-2)">
            <th style="padding:7px 10px;text-align:left;border:1px solid var(--line);white-space:nowrap;font-weight:600">Header Line</th>
            <th style="padding:7px 10px;text-align:left;border:1px solid var(--line);font-weight:600">Font Family</th>
            <th style="padding:7px 8px;text-align:center;border:1px solid var(--line);font-weight:600">Size (pt)</th>
            <th style="padding:7px 10px;text-align:center;border:1px solid var(--line);font-weight:600">Style</th>
          </tr>
        </thead>
        <tbody>
<?php
$hdrLines = [
  ['id'=>'hdr_sem', 'label'=>'Semester / S.Y.',  'defFont'=>'Helvetica','defSize'=>'9',   'defStyle'=>''  ],
  ['id'=>'hdr_lbl', 'label'=>'Grade Label',       'defFont'=>'Times',    'defSize'=>'11',  'defStyle'=>'B' ],
  ['id'=>'hdr_crs', 'label'=>'Course Name',       'defFont'=>'Helvetica','defSize'=>'9.5', 'defStyle'=>''  ],
  ['id'=>'hdr_sec', 'label'=>'Section',           'defFont'=>'Helvetica','defSize'=>'9.5', 'defStyle'=>'B' ],
  ['id'=>'hdr_sch', 'label'=>'Schedule',          'defFont'=>'Helvetica','defSize'=>'9',   'defStyle'=>'B' ],
];
foreach ($hdrLines as $hl):
?>
          <tr>
            <td style="padding:6px 10px;border:1px solid var(--line);white-space:nowrap;color:var(--ink-soft)"><?= $hl['label'] ?></td>
            <td style="padding:5px 8px;border:1px solid var(--line)">
              <select id="<?= $hl['id'] ?>_font" style="width:130px;font-size:.81rem;padding:4px 6px">
                <option value="Helvetica">Helvetica</option>
                <option value="Times">Times New Roman</option>
                <option value="Courier">Courier</option>
              </select>
            </td>
            <td style="padding:5px 8px;border:1px solid var(--line);text-align:center">
              <input type="number" id="<?= $hl['id'] ?>_size" value="<?= $hl['defSize'] ?>"
                min="6" max="24" step="0.5" style="width:58px;text-align:center;font-size:.81rem;padding:4px 6px">
            </td>
            <td style="padding:5px 10px;border:1px solid var(--line);text-align:center">
              <div class="hdr-style-grp" id="<?= $hl['id'] ?>_style_grp">
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="B"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><strong>B</strong></button>
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="I"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><em>I</em></button>
                <button type="button" class="hdr-style-btn" data-for="<?= $hl['id'] ?>_style" data-s="U"
                  onclick="toggleHdrStyle('<?= $hl['id'] ?>_style',this)"><u>U</u></button>
              </div>
              <input type="hidden" id="<?= $hl['id'] ?>_style" value="<?= $hl['defStyle'] ?>">
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Header Layout Preview -->
    <div style="margin-top:18px;margin-bottom:4px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Header Layout Preview
    </div>
    <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;
                font-family:Arial,sans-serif;max-width:420px;background:#fff">
      <div style="padding:10px 16px;text-align:center;border-bottom:2px solid #c97b1f">
        <div style="width:38px;height:38px;border-radius:50%;background:var(--line);
                    display:inline-flex;align-items:center;justify-content:center;
                    font-size:.7rem;color:var(--ink-soft);margin-bottom:4px">LOGO</div>
        <div style="font-weight:700;font-size:.9rem">School Name</div>
        <div style="font-size:.75rem;color:#c97b1f">Department / Address</div>
      </div>
      <div style="padding:8px 16px;text-align:center;border-bottom:1px solid var(--line)">
        <div style="font-size:.75rem;color:#555">2nd Semester, A.Y. 2025-2026</div>
        <div style="font-weight:700;font-size:.82rem">FINAL CLASS GRADE</div>
        <div style="font-size:.77rem">Introduction to Computing</div>
        <div style="font-size:.77rem;font-weight:600">BSIS-1A</div>
        <div style="font-size:.75rem;font-weight:600">MWF 7:30-8:30, TTH 7:30-9:00</div>
      </div>
      <div style="padding:4px 16px;font-size:.7rem;color:#aaa;text-align:center">
        Instructor &nbsp;·&nbsp; Passed &nbsp;·&nbsp; Failed
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-primary" onclick="saveAdminHeaderSettings()">Save Header Settings</button>
    </div>
  </div>
  <?php endif; ?>

<!-- ---- Admin Accounts (admin only) ---- -->
  <?php if (is_admin()): ?>
  <div class="card" style="margin-bottom:20px">
    <h2 style="margin-top:0">&#128274; Admin Accounts</h2>
    <p class="muted">Additional admin accounts can be created here. Admins can view and print all teachers' records but cannot modify grades.</p>
    <div class="row" style="align-items:flex-end;flex-wrap:wrap">
      <div class="field" style="flex:2"><label>Full Name</label><input id="adm_name" placeholder="Admin Name"></div>
      <div class="field" style="flex:2"><label>Email</label><input id="adm_email" type="email" placeholder="admin@school.edu"></div>
      <div class="field" style="flex:2"><label>Password</label><input id="adm_pass" type="password" placeholder="Min 6 chars"></div>
      <div class="field" style="flex:0 0 auto;padding-top:0">
        <label style="visibility:hidden;display:block;margin-bottom:6px">.</label>
        <button class="btn btn-primary" onclick="createAdmin()">Create Admin</button>
      </div>
    </div>
    <div id="adminList" style="margin-top:16px"></div>
  </div>

  <!-- ---- My Admin Account ---- -->
  <div class="card" style="margin-bottom:20px">
    <h2 style="margin-top:0">&#128100; My Admin Account</h2>
    <p class="muted" style="margin-bottom:14px">Edit your own name, email, or password. Other admin accounts cannot be edited here.</p>
    <div class="row">
      <div class="field" style="flex:2"><label>Full Name *</label><input id="sa_name" placeholder="Your full name"></div>
      <div class="field" style="flex:2"><label>Email *</label><input id="sa_email" type="email" placeholder="your@email.com"></div>
    </div>
    <div style="font-weight:600;font-size:.9rem;margin-bottom:10px">
      Change Password <span class="muted" style="font-weight:400;font-size:.82rem">— leave blank to keep current</span>
    </div>
    <div class="row">
      <div class="field"><label>Current Password</label><input id="sa_cur_pw" type="password" autocomplete="current-password" placeholder="Required to change password"></div>
      <div class="field"><label>New Password</label><input id="sa_new_pw" type="password" autocomplete="new-password" placeholder="Min 6 characters"></div>
      <div class="field"><label>Confirm New Password</label><input id="sa_new_pw2" type="password" autocomplete="new-password" placeholder="Repeat new password"></div>
    </div>
    <div id="sa_err" style="display:none;color:var(--red);font-size:.85rem;padding:8px 12px;background:#fdecea;border-radius:8px;margin-bottom:10px"></div>
    <div id="sa_ok"  style="display:none;color:#155724;font-size:.85rem;padding:8px 12px;background:#d4edda;border-radius:8px;margin-bottom:10px"></div>
    <div style="display:flex;justify-content:flex-end">
      <button class="btn btn-primary" onclick="saveAdminSelf()">Save My Account</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ---- Web Theme (PERSONAL) ---- -->
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--amber)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
      <h2 style="margin:0">&#127775; Web Theme</h2>
      <span class="pill pill-amber">&#128100; Your personal settings</span>
    </div>
    <p class="muted" style="font-size:.88rem;margin-bottom:14px">
      These are <strong>your own</strong> preferences — changing them only affects what you see
      when logged in. Other teachers keep their own colours and fonts.
    </p>

    <!-- Web font -->
    <div style="margin-bottom:18px">
      <label style="margin-bottom:10px;display:block">Font Family <span class="muted">(applied across the whole web system)</span></label>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px" id="webFontCards"></div>
    </div>

    <!-- Web colors — organized by area -->
    <div style="margin-bottom:6px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Navigation Bar
    </div>
    <div class="row" style="margin-bottom:14px">
      <div class="field">
        <label>Nav Background <span class="muted">(top bar)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_ink" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_ink_hex" placeholder="#1d2433" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Nav Text &amp; Links <span class="muted">(on the bar)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_nav_text" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_nav_text_hex" placeholder="#f5f0e6" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Accent Color <span class="muted">(buttons, highlights)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_accent" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_accent_hex" placeholder="#c97b1f" style="width:100px;font-size:.85rem">
        </div>
      </div>
    </div>

    <div style="margin-bottom:6px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Page &amp; Content
    </div>
    <div class="row" style="margin-bottom:14px">
      <div class="field">
        <label>Body Text <span class="muted">(main content)</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_text" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_text_hex" placeholder="#1d2433" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Muted / Secondary Text</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_muted" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_muted_hex" placeholder="#495066" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Link Color</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_link" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_link_hex" placeholder="#c97b1f" style="width:100px;font-size:.85rem">
        </div>
      </div>
    </div>

    <div style="margin-bottom:6px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Surfaces &amp; Borders
    </div>
    <div class="row" style="margin-bottom:16px">
      <div class="field">
        <label>Page Background</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_paper" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_paper_hex" placeholder="#f5f0e6" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Card Background</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_card" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_card_hex" placeholder="#fffdf8" style="width:100px;font-size:.85rem">
        </div>
      </div>
      <div class="field">
        <label>Border / Line Color</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" id="s_line" style="width:44px;height:36px;padding:2px;border-radius:7px;cursor:pointer">
          <input id="s_line_hex" placeholder="#d9cfba" style="width:100px;font-size:.85rem">
        </div>
      </div>
    </div>

    <div style="margin-bottom:6px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Gradebook Table Colors
    </div>
    <p class="muted" style="font-size:.8rem;margin-bottom:16px">
      Every color in the Term Grade and Final Grade tables is customizable. Use Table Presets for one-click themes.
    </p>

    <?php
    // Helper to render a color picker row item
    function tblPicker($pid,$hid,$cv,$lbl,$ph) {
      echo '<div class="field"><label style="font-size:.76rem;margin-bottom:3px">'.$lbl.'</label>';
      echo '<div style="display:flex;gap:5px;align-items:center">';
      echo '<input type="color" id="'.$pid.'" style="width:38px;height:32px;padding:2px;border-radius:5px;cursor:pointer">';
      echo '<input id="'.$hid.'" placeholder="'.$ph.'" style="width:82px;font-size:.78rem">';
      echo '</div></div>';
    }
    ?>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Column Headers</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_tbl_header','s_tbl_header_hex','--tbl-header','Criterion BG','#1d2433'); ?>
      <?php tblPicker('s_tbl_header_txt','s_tbl_header_txt_hex','--tbl-header-txt','Criterion Text','#f5f0e6'); ?>
      <?php tblPicker('s_tbl_sub_header','s_tbl_sub_header_hex','--tbl-sub-header','Activity BG','#2c3e2e'); ?>
      <?php tblPicker('s_tbl_sub_header_txt','s_tbl_sub_header_txt_hex','--tbl-sub-header-txt','Activity Text','#f5f0e6'); ?>
      <?php tblPicker('s_tbl_avg_header','s_tbl_avg_header_hex','--tbl-avg-header','AVG/WS Header BG','#c97b1f'); ?>
      <?php tblPicker('s_tbl_avg_header_txt','s_tbl_avg_header_txt_hex','--tbl-avg-header-txt','AVG/WS Header Text','#ffffff'); ?>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Data Rows</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_tbl_row_odd','s_tbl_row_odd_hex','--tbl-row-odd','Odd Row BG','#ffffff'); ?>
      <?php tblPicker('s_tbl_row_odd_txt','s_tbl_row_odd_txt_hex','--tbl-row-odd-txt','Odd Row Text','#1d2433'); ?>
      <?php tblPicker('s_tbl_row_even','s_tbl_row_even_hex','--tbl-row-even','Even Row BG','#f7f4ee'); ?>
      <?php tblPicker('s_tbl_row_even_txt','s_tbl_row_even_txt_hex','--tbl-row-even-txt','Even Row Text','#1d2433'); ?>
      <?php tblPicker('s_tbl_computed_bg','s_tbl_computed_bg_hex','--tbl-computed-bg','Computed Cell BG','#efe8d8'); ?>
      <?php tblPicker('s_tbl_raw_bg','s_tbl_raw_bg_hex','--tbl-raw-bg','Raw Score BG','transparent'); ?>
      <?php tblPicker('s_tbl_raw_txt','s_tbl_raw_txt_hex','--tbl-raw-txt','Raw Score Text','#1d2433'); ?>
      <?php tblPicker('s_tbl_avg_bg','s_tbl_avg_bg_hex','--tbl-avg-bg','AVG Cell BG','transparent'); ?>
      <?php tblPicker('s_tbl_avg_txt','s_tbl_avg_txt_hex','--tbl-avg-txt','AVG Cell Text','#1d2433'); ?>
      <?php tblPicker('s_tbl_ws_bg','s_tbl_ws_bg_hex','--tbl-ws-bg','WS Cell BG','transparent'); ?>
      <?php tblPicker('s_tbl_ws_txt','s_tbl_ws_txt_hex','--tbl-ws-txt','WS Cell Text','#1d2433'); ?>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Student Column &amp; Activity Labels</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_tbl_name_bg','s_tbl_name_bg_hex','--tbl-name-bg','Student Col BG','#efe8d8'); ?>
      <?php tblPicker('s_tbl_name_txt','s_tbl_name_txt_hex','--tbl-name-txt','Student Col Text','#1d2433'); ?>
      <?php tblPicker('s_tbl_name_even_bg','s_tbl_name_even_bg_hex','--tbl-name-even-bg','Student Col Even BG','#ece4d2'); ?>
      <?php tblPicker('s_tbl_wt_color','s_tbl_wt_color_hex','--tbl-wt-color','Score Label &amp; Icon Color',''  ); ?>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">AI Insight Column</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_tbl_ai_cell_bg','s_tbl_ai_cell_bg_hex','--tbl-ai-cell-bg','AI Cell Fill','transparent'); ?>
      <?php tblPicker('s_tbl_ai_cell_txt','s_tbl_ai_cell_txt_hex','--tbl-ai-cell-txt','AI Cell Text','#1d2433'); ?>
      <?php tblPicker('s_hl_ai_btn','s_hl_ai_btn_hex','--hl-ai-btn','Analyze Btn BG','#c97b1f'); ?>
      <?php tblPicker('s_hl_ai_btn_txt','s_hl_ai_btn_txt_hex','--hl-ai-btn-txt','Analyze Btn Text','#ffffff'); ?>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Highlight Cells</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_hl_equiv','s_hl_equiv_hex','--hl-equiv','Equiv BG','#d8ecdf'); ?>
      <?php tblPicker('s_hl_equiv_txt','s_hl_equiv_txt_hex','--hl-equiv-txt','Equiv Text','#155724'); ?>
      <?php tblPicker('s_hl_grade','s_hl_grade_hex','--hl-grade','Term Grade BG','#f0d9b5'); ?>
      <?php tblPicker('s_hl_grade_txt','s_hl_grade_txt_hex','--hl-grade-txt','Term Grade Text','#1d2433'); ?>
      <?php tblPicker('s_hl_final','s_hl_final_hex','--hl-final','Final Hdr BG','#c97b1f'); ?>
      <?php tblPicker('s_hl_final_txt','s_hl_final_txt_hex','--hl-final-txt','Final Hdr Text','#ffffff'); ?>
      <?php tblPicker('s_hl_term_col','s_hl_term_col_hex','--hl-term-col','Term Col BG','#f0d9b5'); ?>
      <?php tblPicker('s_hl_term_col_txt','s_hl_term_col_txt_hex','--hl-term-col-txt','Term Col Text','#1d2433'); ?>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--red)">Failing Grade</div>
    <div class="row" style="margin-bottom:12px">
      <?php tblPicker('s_hl_fail_bg','s_hl_fail_bg_hex','--hl-fail-bg','Fail BG','#f8d7da'); ?>
      <?php tblPicker('s_hl_fail_txt','s_hl_fail_txt_hex','--hl-fail-txt','Fail Text','#721c24'); ?>
      <div class="field"><label style="font-size:.76rem;opacity:0">.</label>
        <div style="padding:6px 12px;background:var(--hl-fail-bg);color:var(--hl-fail-txt);font-weight:700;border-radius:5px" id="failPreview">74 — Below Passing</div>
      </div>
    </div>

    <div style="margin:0 0 5px;font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-soft)">Remarks &amp; AI Button</div>
    <div class="row" style="margin-bottom:18px">
      <?php tblPicker('s_hl_remarks_pass','s_hl_remarks_pass_hex','--hl-remarks-pass','Passed BG','#d4edda'); ?>
      <?php tblPicker('s_hl_remarks_pass_txt','s_hl_remarks_pass_txt_hex','--hl-remarks-pass-txt','Passed Text','#155724'); ?>
      <?php tblPicker('s_hl_remarks_fail','s_hl_remarks_fail_hex','--hl-remarks-fail','Failed BG','#f8d7da'); ?>
      <?php tblPicker('s_hl_remarks_fail_txt','s_hl_remarks_fail_txt_hex','--hl-remarks-fail-txt','Failed Text','#721c24'); ?>
      <?php tblPicker('s_hl_remarks_inc','s_hl_remarks_inc_hex','--hl-remarks-inc','Inc BG','#fff3cd'); ?>
      <?php tblPicker('s_hl_remarks_inc_txt','s_hl_remarks_inc_txt_hex','--hl-remarks-inc-txt','Inc Text','#856404'); ?>
      <?php tblPicker('s_hl_ai_btn','s_hl_ai_btn_hex','--hl-ai-btn','Analyze Btn BG','#c97b1f'); ?>
      <?php tblPicker('s_hl_ai_btn_txt','s_hl_ai_btn_txt_hex','--hl-ai-btn-txt','Analyze Btn Text','#ffffff'); ?>
    </div>

    <!-- Live Grade Table Preview -->
    <div style="margin-bottom:8px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Live Table Preview <span class="muted" style="font-weight:400;font-size:.78rem;margin-left:6px">— updates instantly as you change colors above</span>
    </div>
    <div style="overflow:auto;border:1px solid var(--line);border-radius:10px;margin-bottom:16px">
      <table id="liveGradePreview" style="border-collapse:collapse;width:100%;font-size:.82rem;table-layout:auto">
        <thead>
          <tr id="lpRow1">
            <th rowspan="2" id="lpNameHdr" style="text-align:left;padding:8px 10px;border:1px solid;min-width:140px">Student</th>
            <th colspan="4" id="lpCritHdr" style="padding:6px 10px;border:1px solid;text-align:center">QUIZ<span style="display:block;font-size:.7rem;font-weight:400">40%</span></th>
            <th rowspan="2" id="lpGradeHdr" style="padding:6px 10px;border:1px solid;text-align:center;min-width:72px">MIDTERM<br>GRADE</th>
          </tr>
          <tr id="lpRow2">
            <th id="lpActHdr1" style="padding:5px 8px;border:1px solid;text-align:center">Q1<span id="lpWt1" style="display:block;font-size:.68rem;font-weight:400">/25 📋</span></th>
            <th id="lpActHdr2" style="padding:5px 8px;border:1px solid;text-align:center">Q2<span id="lpWt2" style="display:block;font-size:.68rem;font-weight:400">/25 📋</span></th>
            <th id="lpAvgHdr" style="padding:5px 8px;border:1px solid;text-align:center">AVG</th>
            <th id="lpWsHdr" style="padding:5px 8px;border:1px solid;text-align:center">WS</th>
          </tr>
        </thead>
        <tbody id="lpBody">
          <tr id="lpOddRow">
            <td id="lpNameOdd" style="padding:7px 10px;border:1px solid;font-weight:500">SANTOS, Juan</td>
            <td id="lpRaw1" style="padding:7px 8px;border:1px solid;text-align:center">22</td>
            <td id="lpEquiv1" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">94</td>
            <td id="lpAvg1" style="padding:7px 8px;border:1px solid;text-align:center">94.00</td>
            <td id="lpWs1" style="padding:7px 8px;border:1px solid;text-align:center">37.60</td>
            <td id="lpPassGrade" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">88</td>
          </tr>
          <tr id="lpEvenRow">
            <td id="lpNameEven" style="padding:7px 10px;border:1px solid;font-weight:500">DELA CRUZ, Maria</td>
            <td id="lpRaw2" style="padding:7px 8px;border:1px solid;text-align:center">14</td>
            <td id="lpEquiv2" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">76</td>
            <td id="lpAvg2" style="padding:7px 8px;border:1px solid;text-align:center">76.00</td>
            <td id="lpWs2" style="padding:7px 8px;border:1px solid;text-align:center">30.40</td>
            <td id="lpFailGrade" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">69</td>
          </tr>
          <tr id="lpOddRow2">
            <td id="lpNameOdd2" style="padding:7px 10px;border:1px solid;font-weight:500">REYES, Carlos</td>
            <td id="lpRaw3" style="padding:7px 8px;border:1px solid;text-align:center">23</td>
            <td id="lpEquiv3" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">96</td>
            <td id="lpAvg3" style="padding:7px 8px;border:1px solid;text-align:center">96.00</td>
            <td id="lpWs3" style="padding:7px 8px;border:1px solid;text-align:center">38.40</td>
            <td id="lpPassGrade2" style="padding:7px 8px;border:1px solid;text-align:center;font-weight:700">92</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Professional Table Presets -->
    <div style="margin-bottom:8px;font-size:.82rem;font-weight:600;color:var(--ink-soft)">
      Table Presets <span class="muted" style="font-weight:400;font-size:.78rem;margin-left:6px">— fill all table colors at once</span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px" id="tablePresets"></div>

    <!-- Live preview strip -->
    <div id="themePreviewStrip" style="border-radius:10px;overflow:hidden;margin-bottom:16px;
         border:1px solid var(--line)">
      <div id="tpNav" style="padding:10px 16px;display:flex;align-items:center;gap:12px">
        <span id="tpMark" style="width:28px;height:28px;border-radius:50%;display:inline-flex;
          align-items:center;justify-content:center;font-weight:700;font-size:.9rem">G</span>
        <span id="tpSchool" style="font-weight:600;font-size:.95rem">School Name</span>
        <span style="flex:1"></span>
        <span id="tpNavLink" style="font-size:.85rem">My Classes</span>
        <span id="tpNavBtn" style="font-size:.78rem;border:1px solid;padding:3px 10px;border-radius:6px">Sign out</span>
      </div>
      <div id="tpBody" style="padding:14px 16px">
        <div id="tpCard" style="border-radius:8px;padding:12px 14px;border:1px solid;margin-bottom:8px">
          <div id="tpHeading" style="font-weight:700;margin-bottom:4px">Data Structures · BSIS-2A</div>
          <div id="tpMutedTxt" style="font-size:.82rem;margin-bottom:8px">Midterm, Endterm · Passing 75 · 2026-2027</div>
          <div style="display:flex;gap:6px">
            <span id="tpBtn" style="font-size:.78rem;padding:4px 12px;border-radius:6px;font-weight:600">Open Gradebook</span>
            <a id="tpLink" style="font-size:.78rem;padding:4px 12px">Edit</a>
          </div>
        </div>
        <div id="tpMutedLine" style="font-size:.78rem">Muted text — secondary information appears like this</div>
      </div>
    </div>

    <!-- Color presets -->
    <div style="margin-top:4px">
      <label style="font-weight:600">Quick Presets
        <span class="muted" style="font-weight:400;font-size:.8rem;margin-left:6px">— fills all web colors at once</span>
      </label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px" id="presets"></div>
    </div>
  </div>

  <!-- ---- PDF Report Appearance (PERSONAL) ---- -->
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--amber)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
      <h2 style="margin:0">&#128196; PDF Report Appearance</h2>
      <span class="pill pill-amber">&#128100; Your personal settings</span>
    </div>
    <p class="muted" style="font-size:.88rem;margin-bottom:14px">
      Controls fonts and colors in <strong>your</strong> generated PDF reports. Other teachers' reports
      use their own settings.
    </p>

    <!-- PDF fonts -->
    <div style="margin-bottom:18px">
      <label style="margin-bottom:10px;display:block">Report Fonts <span class="muted">(FPDF built-in fonts only)</span></label>
      <div class="row" style="gap:16px">
        <div style="flex:1">
          <div class="muted" style="font-size:.82rem;margin-bottom:8px">Title Font <span style="font-size:.75rem">(subject name, headings)</span></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap" id="pdfTitleFontCards"></div>
        </div>
        <div style="flex:1">
          <div class="muted" style="font-size:.82rem;margin-bottom:8px">Body Font <span style="font-size:.75rem">(table data, labels)</span></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap" id="pdfBodyFontCards"></div>
        </div>
      </div>
    </div>

    <!-- Paper size + font size -->
    <div class="row" style="margin-bottom:16px">
      <div class="field">
        <label>Term Report Paper</label>
        <select id="p_term_paper">
          <option value="folio-L">Folio 8.5×13 · Landscape ✓ recommended</option>
          <option value="folio-P">Folio 8.5×13 · Portrait</option>
          <option value="legal-L">Legal 8.5×14 · Landscape</option>
          <option value="legal-P">Legal 8.5×14 · Portrait</option>
          <option value="letter-L">Letter 8.5×11 · Landscape</option>
          <option value="letter-P">Letter 8.5×11 · Portrait</option>
          <option value="a4-L">A4 · Landscape</option>
          <option value="a4-P">A4 · Portrait</option>
        </select>
        <div class="muted" style="font-size:.8rem;margin-top:4px">Used for Midterm, Endterm, etc. reports with full activity columns.</div>
      </div>
      <div class="field">
        <label>Final Grade Report Paper</label>
        <select id="p_final_paper">
          <option value="folio-P">Folio 8.5×13 · Portrait ✓ recommended</option>
          <option value="folio-L">Folio 8.5×13 · Landscape</option>
          <option value="legal-P">Legal 8.5×14 · Portrait</option>
          <option value="legal-L">Legal 8.5×14 · Landscape</option>
          <option value="letter-P">Letter 8.5×11 · Portrait</option>
          <option value="letter-L">Letter 8.5×11 · Landscape</option>
          <option value="a4-P">A4 · Portrait</option>
          <option value="a4-L">A4 · Landscape</option>
        </select>
        <div class="muted" style="font-size:.8rem;margin-top:4px">Used for the Final Grade summary report.</div>
      </div>
      <div class="field" style="flex:.55">
        <label>Font Size <span class="muted">(6–11pt)</span></label>
        <div style="display:flex;gap:10px;align-items:center">
          <input type="range" id="p_fsize_range" min="6" max="11" step="0.5" style="flex:1;accent-color:var(--amber)"
            oninput="document.getElementById('p_fsize_num').value=this.value">
          <input type="number" id="p_fsize_num" min="6" max="11" step="0.5" style="width:60px"
            oninput="document.getElementById('p_fsize_range').value=this.value">
          <span class="muted">pt</span>
        </div>
        <div class="muted" style="font-size:.8rem;margin-top:4px">Reduce to 7pt or 6.5pt to fit wider tables.</div>
      </div>
    </div>

    <!-- ── PDF Color Controls: every distinct area ── -->
    <div style="margin-bottom:6px;font-size:.9rem;font-weight:700">Report Color Controls</div>
    <p class="muted" style="font-size:.82rem;margin-bottom:16px">
      Every part of the document is individually adjustable.
      Use the color picker <span style="font-size:1rem">🎨</span> or type an R,G,B value directly.
      The live preview updates as you change each color.
    </p>

    <!-- Section: Header Bar -->
    <div style="margin-bottom:14px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Header Bar
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Background</label><?php echo pdfColorField('p_hdr','29,36,51'); ?></div>
        <div class="field"><label style="font-size:.82rem">School Name Text</label><?php echo pdfColorField('p_hdr_txt','245,240,230'); ?></div>
        <div class="field"><label style="font-size:.82rem">Subtitle / Address</label><?php echo pdfColorField('p_subtitle_txt','201,123,31'); ?></div>
        <div class="field"><label style="font-size:.82rem">Report Title Text</label><?php echo pdfColorField('p_title_txt','245,240,230'); ?></div>
      </div>
    </div>

    <!-- Section: Class Info Block -->
    <div style="margin-bottom:14px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Class Info Block (below header)
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Subject Name</label><?php echo pdfColorField('p_subj_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.82rem">Info Row Text</label><?php echo pdfColorField('p_info_txt','60,60,80'); ?></div>
        <div class="field"><label style="font-size:.82rem">Accent Rule Line</label><?php echo pdfColorField('p_acc','201,123,31'); ?></div>
      </div>
    </div>

    <!-- Section: Table Headers -->
    <div style="margin-bottom:14px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Table Headers
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Header Background</label><?php echo pdfColorField('p_th_bg','29,36,51'); ?></div>
        <div class="field"><label style="font-size:.82rem">Header Text</label><?php echo pdfColorField('p_th_txt','245,240,230'); ?></div>
        <div class="field"><label style="font-size:.82rem">Sub-header Row</label><?php echo pdfColorField('p_th2_bg','45,65,45'); ?></div>
        <div class="field"><label style="font-size:.82rem">Sub-header Text</label><?php echo pdfColorField('p_th2_txt','245,240,230'); ?></div>
      </div>
    </div>

    <!-- Section: Data Rows -->
    <div style="margin-bottom:14px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Data Rows
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Data Text</label><?php echo pdfColorField('p_text','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.82rem">Odd Row Background</label><?php echo pdfColorField('p_row_odd','255,255,255'); ?></div>
        <div class="field"><label style="font-size:.82rem">Even Row Background</label><?php echo pdfColorField('p_row_even','248,246,240'); ?></div>
        <div class="field"><label style="font-size:.82rem">Border / Lines</label><?php echo pdfColorField('p_border','200,193,180'); ?></div>
      </div>
    </div>

    <!-- Section: Special Cells -->
    <div style="margin-bottom:14px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Special Cells
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Equiv Cell Fill</label><?php echo pdfColorField('p_equiv','216,236,223'); ?></div>
        <div class="field"><label style="font-size:.82rem">Equiv Cell Text</label><?php echo pdfColorField('p_equiv_txt','30,100,60'); ?></div>
        <div class="field"><label style="font-size:.82rem">WS Cell Fill</label><?php echo pdfColorField('p_ws','240,217,181'); ?></div>
        <div class="field"><label style="font-size:.82rem">WS Cell Text</label><?php echo pdfColorField('p_ws_txt','100,70,10'); ?></div>
      </div>
      <div class="row" style="gap:10px;margin-top:8px">
        <div class="field"><label style="font-size:.82rem">Term Grade Fill</label><?php echo pdfColorField('p_grade','240,217,181'); ?></div>
        <div class="field"><label style="font-size:.82rem">Term Grade Text</label><?php echo pdfColorField('p_grade_txt','80,50,10'); ?></div>
        <div class="field"><label style="font-size:.82rem">Passed Cell Fill</label><?php echo pdfColorField('p_pass','47,125,84'); ?></div>
        <div class="field"><label style="font-size:.82rem">Passed Cell Text</label><?php echo pdfColorField('p_pass_txt','255,255,255'); ?></div>
      </div>
      <div class="row" style="gap:10px;margin-top:8px">
        <div class="field"><label style="font-size:.82rem">Failed Cell Fill</label><?php echo pdfColorField('p_fail','178,59,59'); ?></div>
        <div class="field"><label style="font-size:.82rem">Failed Cell Text</label><?php echo pdfColorField('p_fail_txt','255,255,255'); ?></div>
        <div class="field"><label style="font-size:.82rem">INC / No Grade Fill</label><?php echo pdfColorField('p_inc','220,220,220'); ?></div>
        <div class="field"><label style="font-size:.82rem">INC / No Grade Text</label><?php echo pdfColorField('p_inc_txt','80,80,80'); ?></div>
      </div>
    </div>

    <!-- Section: Final Grade Report Colors -->
    <div style="margin-bottom:18px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Final Grade Report — Grade Columns
      </div>
      <p class="muted" style="font-size:.78rem;margin:0 0 10px">
        Colors for the Midterm/Endterm columns and the Final Grade column in the Final Grade Report.
        Failed grades always use the red fill above. These apply only to <strong>passing</strong> grade cells.
      </p>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Term Col Pass Fill</label><?php echo pdfColorField('p_term_col','255,255,255'); ?></div>
        <div class="field"><label style="font-size:.82rem">Term Col Pass Text</label><?php echo pdfColorField('p_term_col_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.82rem">Final Grade Pass Fill</label><?php echo pdfColorField('p_final_col','255,255,255'); ?></div>
        <div class="field"><label style="font-size:.82rem">Final Grade Pass Text</label><?php echo pdfColorField('p_final_col_txt','0,0,0'); ?></div>
      </div>
    </div>

    <!-- Section: Footer & Signature -->
    <div style="margin-bottom:18px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        Footer &amp; Signature
      </div>
      <div class="row" style="gap:10px">
        <div class="field"><label style="font-size:.82rem">Footer Text</label><?php echo pdfColorField('p_footer_txt','160,160,160'); ?></div>
        <div class="field"><label style="font-size:.82rem">Signature Text</label><?php echo pdfColorField('p_sig_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.82rem">Page Background</label><?php echo pdfColorField('p_page_bg','255,255,255'); ?></div>
      </div>
    </div>

    <!-- Live PDF mini-preview — two panels side by side -->
    <div style="margin-bottom:18px">
      <div style="font-size:.82rem;font-weight:700;color:var(--ink-soft);margin-bottom:8px">Live Preview</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">

        <!-- ── Panel 1: Term Grade Report ── -->
        <div>
          <div style="font-size:.74rem;color:var(--ink-soft);margin-bottom:4px;text-align:center">Term Grade Report</div>
          <div id="pdfMiniPreview" style="border:1px solid var(--line);border-radius:8px;overflow:hidden;
               font-family:Arial,sans-serif;font-size:10px;box-shadow:0 2px 6px rgba(0,0,0,.08)">
            <!-- Header -->
            <div id="pmp_hdr" style="padding:6px 10px;display:flex;justify-content:space-between;align-items:center">
              <div>
                <div id="pmp_school" style="font-weight:700;font-size:10px">School Name</div>
                <div id="pmp_sub" style="font-size:8px;margin-top:1px">Department / Address Line</div>
              </div>
              <div id="pmp_rptTitle" style="font-weight:700;font-size:9px">Midterm Report</div>
            </div>
            <div id="pmp_accentLine" style="height:2px"></div>
            <div id="pmp_classInfo" style="padding:4px 10px;background:#fff">
              <div id="pmp_subjName" style="font-weight:700;font-size:9px;margin-bottom:1px">Data Structures (BSIS-2A)</div>
              <div id="pmp_infoRow" style="font-size:8px">ITEDAT &nbsp;·&nbsp; 2026-2027 &nbsp;·&nbsp; Passing: 75</div>
            </div>
            <!-- Term table: shows GRADE column -->
            <div id="pmp_th_term" style="display:grid;grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr;gap:0">
              <div id="pmp_thT1" style="padding:4px 6px;font-weight:700;font-size:8px">Student</div>
              <div id="pmp_thT2" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">Q1</div>
              <div id="pmp_thT3" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">Eq</div>
              <div id="pmp_thT4" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">AVG</div>
              <div id="pmp_thT5" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">WS</div>
              <div id="pmp_thGrade" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">GRADE</div>
            </div>
            <div id="pmp_termRow1" style="display:grid;grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr">
              <div id="pmp_tName1" style="padding:3px 6px;font-size:8px">SANTOS, Juan</div>
              <div id="pmp_tRaw1" style="padding:3px 4px;text-align:center;font-size:8px">23</div>
              <div id="pmp_tEq1" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">96</div>
              <div id="pmp_tAvg1" style="padding:3px 4px;text-align:center;font-size:8px">94.50</div>
              <div id="pmp_tWs1" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">9.45</div>
              <div id="pmp_termGradePass" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">88</div>
            </div>
            <div id="pmp_termRow2" style="display:grid;grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr">
              <div id="pmp_tName2" style="padding:3px 6px;font-size:8px">DELA CRUZ, Maria</div>
              <div id="pmp_tRaw2" style="padding:3px 4px;text-align:center;font-size:8px">14</div>
              <div id="pmp_tEq2" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">76</div>
              <div id="pmp_tAvg2" style="padding:3px 4px;text-align:center;font-size:8px">76.00</div>
              <div id="pmp_tWs2" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">7.60</div>
              <div id="pmp_termGradeFail" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">72</div>
            </div>
            <div id="pmp_termRow3" style="display:grid;grid-template-columns:2.5fr 1fr 1fr 1fr 1fr 1fr">
              <div id="pmp_tName3" style="padding:3px 6px;font-size:8px">REYES, Carlos</div>
              <div id="pmp_tRaw3" style="padding:3px 4px;text-align:center;font-size:8px">25</div>
              <div id="pmp_tEq3" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">100</div>
              <div id="pmp_tAvg3" style="padding:3px 4px;text-align:center;font-size:8px">97.50</div>
              <div id="pmp_tWs3" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">9.75</div>
              <div id="pmp_termGradePass2" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">95</div>
            </div>
            <div id="pmp_footer" style="padding:3px 10px;text-align:center;font-size:7px;border-top:1px solid #eee">
              GradeFlow &nbsp;·&nbsp; Generated Today &nbsp;·&nbsp; Page 1/1
            </div>
          </div>
        </div>

        <!-- ── Panel 2: Final Grade Report ── -->
        <div>
          <div style="font-size:.74rem;color:var(--ink-soft);margin-bottom:4px;text-align:center">Final Grade Report</div>
          <div id="pdfFinalPreview" style="border:1px solid var(--line);border-radius:8px;overflow:hidden;
               font-family:Arial,sans-serif;font-size:10px;box-shadow:0 2px 6px rgba(0,0,0,.08)">
            <div id="pmp_fhdr" style="padding:6px 10px;display:flex;justify-content:space-between;align-items:center">
              <div>
                <div id="pmp_fschool" style="font-weight:700;font-size:10px">School Name</div>
                <div id="pmp_fsub" style="font-size:8px;margin-top:1px">Department / Address Line</div>
              </div>
              <div id="pmp_frptTitle" style="font-weight:700;font-size:9px">Final Grade Report</div>
            </div>
            <div id="pmp_faccentLine" style="height:2px"></div>
            <div style="padding:4px 10px;background:#fff">
              <div id="pmp_fsubjName" style="font-weight:700;font-size:9px;margin-bottom:1px">Data Structures (BSIS-2A)</div>
              <div id="pmp_finfoRow" style="font-size:8px">ITEDAT &nbsp;·&nbsp; 2026-2027 &nbsp;·&nbsp; Passing: 75</div>
            </div>
            <div id="pmp_fth" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:0">
              <div id="pmp_fth1" style="padding:4px 6px;font-weight:700;font-size:8px">Student Name</div>
              <div id="pmp_fth2" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">Midterm</div>
              <div id="pmp_fth3" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">Endterm</div>
              <div id="pmp_fth4" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">FINAL</div>
              <div id="pmp_fth5" style="padding:4px 4px;text-align:center;font-weight:700;font-size:8px">Remarks</div>
            </div>
            <div id="pmp_row1" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr">
              <div id="pmp_fname1" style="padding:3px 6px;font-size:8px">ACAS, Angel Nicole</div>
              <div id="pmp_fmid1" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">86</div>
              <div id="pmp_fend1" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">76</div>
              <div id="pmp_gradePass" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">81</div>
              <div id="pmp_fremark1" style="padding:3px 4px;text-align:center;font-size:8px">PASSED</div>
            </div>
            <div id="pmp_row2" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr">
              <div id="pmp_fname2" style="padding:3px 6px;font-size:8px">ALBELA, Christine</div>
              <div id="pmp_fmid2" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">82</div>
              <div id="pmp_fend2_fail" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">69</div>
              <div id="pmp_gradeFail" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">71</div>
              <div id="pmp_fremark2" style="padding:3px 4px;text-align:center;font-size:8px">FAILED</div>
            </div>
            <div id="pmp_row3" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr">
              <div id="pmp_fname3" style="padding:3px 6px;font-size:8px">REYES, Carlos</div>
              <div id="pmp_fmid3" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">95</div>
              <div id="pmp_fend3" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">78</div>
              <div id="pmp_gradePass2" style="padding:3px 4px;text-align:center;font-size:8px;font-weight:700">87</div>
              <div id="pmp_fremark3" style="padding:3px 4px;text-align:center;font-size:8px">PASSED</div>
            </div>
            <div id="pmp_ffooter" style="padding:3px 10px;text-align:center;font-size:7px;border-top:1px solid #eee">
              GradeFlow &nbsp;·&nbsp; Generated Today &nbsp;·&nbsp; Page 1/1
            </div>
          </div>
        </div>

        <!-- ── Panel 3: AI Intervention Report ── -->
        <div>
          <div style="font-size:.74rem;color:var(--ink-soft);margin-bottom:4px;text-align:center">AI Intervention Report</div>
          <div id="pdfAiPreview" style="border:1px solid var(--line);border-radius:8px;overflow:hidden;
               font-family:Arial,sans-serif;font-size:10px;box-shadow:0 2px 6px rgba(0,0,0,.08)">
            <div id="aip_hdr" style="padding:6px 10px;display:flex;justify-content:space-between;align-items:flex-start">
              <div>
                <div id="aip_school" style="font-weight:700;font-size:10px">School Name</div>
                <div id="aip_addr" style="font-size:8px;margin-top:1px">Department / Address</div>
              </div>
              <div id="aip_title" style="font-weight:700;font-size:8px;text-align:right">AI Intervention<br>Report</div>
            </div>
            <div id="aip_accent" style="height:2px"></div>
            <div id="aip_body" style="padding:5px 10px">
              <div id="aip_rptTitle" style="font-weight:700;font-size:9px;margin-bottom:2px">Data Structures — Midterm Intervention</div>
              <div id="aip_info" style="font-size:8px;margin-bottom:4px">Code: ITEDAT &nbsp;·&nbsp; Instructor: Teacher Name</div>
              <!-- Stats bar -->
              <div id="aip_stats" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:2px;margin-bottom:4px;border-radius:4px;overflow:hidden">
                <div id="aip_sv1" style="padding:4px 2px;text-align:center;font-weight:700;font-size:9px">78.5</div>
                <div id="aip_sv2" style="padding:4px 2px;text-align:center;font-weight:700;font-size:9px">14</div>
                <div id="aip_sv3" style="padding:4px 2px;text-align:center;font-weight:700;font-size:9px">5</div>
                <div id="aip_sv4" style="padding:4px 2px;text-align:center;font-weight:700;font-size:9px">3</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:2px;margin-bottom:6px">
                <div id="aip_sl1" style="text-align:center;font-size:7px">Class Avg</div>
                <div id="aip_sl2" style="text-align:center;font-size:7px">Passing</div>
                <div id="aip_sl3" style="text-align:center;font-size:7px">Failing</div>
                <div id="aip_sl4" style="text-align:center;font-size:7px">At Risk</div>
              </div>
              <!-- Section title -->
              <div id="aip_section" style="padding:3px 6px;font-weight:700;font-size:8px;margin-bottom:4px;border-radius:2px">
                INDIVIDUAL STUDENT INTERVENTION PLANS
              </div>
              <!-- Sample student row -->
              <div style="background:#fde8e8;border-radius:3px;display:flex;justify-content:space-between;padding:3px 6px;margin-bottom:2px">
                <div id="aip_stname" style="font-size:8px;font-weight:700">SANTOS, Juan M.</div>
                <div id="aip_stgrade" style="font-size:8px">Grade: 68</div>
                <div style="font-size:8px;font-weight:700;color:#c83232">HIGH RISK (82)</div>
              </div>
              <div id="aip_action" style="font-size:7.5px;padding:2px 6px;margin-bottom:4px">1. Review Exam EX1 — scored below 60%</div>
            </div>
            <div id="aip_footer" style="padding:3px 10px;text-align:center;font-size:7px;border-top:1px solid #eee">
              GradeFlow AI Analysis &nbsp;·&nbsp; Generated Today &nbsp;·&nbsp; Page 1/1
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── AI Intervention Report Settings ── -->
    <div style="margin-bottom:18px;padding:12px 14px;background:var(--paper-2);border-radius:10px;border:1px solid var(--line)">
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">
        &#129302; AI Intervention Report Appearance
      </div>
      <p class="muted" style="font-size:.78rem;margin:0 0 12px">Colors and fonts for the AI Intervention PDF. Uses the same Title/Body font as the grade reports above.</p>

      <div style="font-size:.75rem;font-weight:600;color:var(--ink-soft);margin-bottom:6px">Header Bar</div>
      <div class="row" style="gap:10px;margin-bottom:12px">
        <div class="field"><label style="font-size:.78rem">Header BG</label><?php echo pdfColorField('ai_hdr_bg','29,36,51'); ?></div>
        <div class="field"><label style="font-size:.78rem">School Name Text</label><?php echo pdfColorField('ai_hdr_school_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Address / Subtitle</label><?php echo pdfColorField('ai_hdr_addr_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Report Title Text</label><?php echo pdfColorField('ai_hdr_title_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Accent Line</label><?php echo pdfColorField('ai_accent_rgb','201,123,31'); ?></div>
      </div>

      <div style="font-size:.75rem;font-weight:600;color:var(--ink-soft);margin-bottom:6px">Body &amp; Info</div>
      <div class="row" style="gap:10px;margin-bottom:12px">
        <div class="field"><label style="font-size:.78rem">Body Text</label><?php echo pdfColorField('ai_body_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Info Row Text</label><?php echo pdfColorField('ai_info_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Report Title Text</label><?php echo pdfColorField('ai_body_txt','0,0,0'); ?></div>
      </div>

      <div style="font-size:.75rem;font-weight:600;color:var(--ink-soft);margin-bottom:6px">Statistics Box</div>
      <div class="row" style="gap:10px;margin-bottom:12px">
        <div class="field"><label style="font-size:.78rem">Stats Box Fill</label><?php echo pdfColorField('ai_stats_bg','245,243,237'); ?></div>
        <div class="field"><label style="font-size:.78rem">Stats Value Text</label><?php echo pdfColorField('ai_stats_val_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Stats Label Text</label><?php echo pdfColorField('ai_stats_lbl_txt','0,0,0'); ?></div>
      </div>

      <div style="font-size:.75rem;font-weight:600;color:var(--ink-soft);margin-bottom:6px">Section Headers &amp; Footer</div>
      <div class="row" style="gap:10px;margin-bottom:6px">
        <div class="field"><label style="font-size:.78rem">Section Header BG</label><?php echo pdfColorField('ai_section_bg','29,36,51'); ?></div>
        <div class="field"><label style="font-size:.78rem">Section Header Text</label><?php echo pdfColorField('ai_section_txt','0,0,0'); ?></div>
        <div class="field"><label style="font-size:.78rem">Footer Text</label><?php echo pdfColorField('ai_footer_txt','120,120,120'); ?></div>
        <div class="field"><label style="font-size:.78rem">Page Background</label><?php echo pdfColorField('ai_page_bg','255,255,255'); ?></div>
      </div>
    </div>

    <!-- PDF Preset Themes -->
    <div style="margin-top:6px">
      <label style="display:block;margin-bottom:10px;font-weight:600">
        Quick Theme Presets
        <span class="muted" style="font-weight:400;font-size:.82rem;margin-left:6px">— fills all report colors at once</span>
      </label>
      <div id="pdfPresets" style="display:flex;flex-direction:column;gap:14px"></div>
    </div>

    <div class="help-note" style="margin-top:14px">
      Convert any color: <em>google "hex to rgb [your color]"</em>.
    </div>
  </div>

  <?php if (is_admin()): ?>
  <!-- ---- Program Chair Management ---- -->
  <div class="card" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px">
      <div>
        <h2 style="margin:0 0 4px">&#128101; Program Chairs</h2>
        <p class="muted" style="margin:0;font-size:.85rem">Program Chairs can view and print class records of faculty under their assigned college and department. Only admins can create, edit or delete chair accounts.</p>
      </div>
      <button class="btn btn-primary btn-sm" style="flex-shrink:0" onclick="openChairForm()">+ Add Chair</button>
    </div>

    <!-- Inline add/edit form (hidden by default) -->
    <div id="chairFormWrap" style="display:none;background:var(--paper-2);border-radius:10px;padding:16px;margin-bottom:16px;border:1px solid var(--line)">
      <div style="font-weight:700;font-size:.9rem;margin-bottom:12px" id="chairFormTitle">Add Program Chair</div>
      <input type="hidden" id="cf_id">
      <div class="row">
        <div class="field" style="flex:2"><label>Full Name *</label><input id="cf_name" placeholder="Complete name"></div>
        <div class="field" style="flex:2"><label>Email *</label><input id="cf_email" type="email" placeholder="chair@school.edu"></div>
      </div>
      <!-- Multi-assignment rows -->
      <div style="font-weight:600;font-size:.88rem;margin-bottom:8px;color:var(--ink)">
        College &amp; Department Assignments
        <span class="muted" style="font-weight:400;font-size:.8rem;margin-left:6px">— Chair can supervise multiple departments</span>
      </div>
      <div id="cfAssignmentRows"></div>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-bottom:12px"
        onclick="addAssignmentRow()">+ Add Assignment</button>

      <div class="field">
        <label id="cf_pw_label">Password *</label>
        <input id="cf_pw" type="password" autocomplete="new-password" placeholder="Min 6 characters" style="max-width:280px">
        <div class="muted" style="font-size:.78rem;margin-top:3px;display:none" id="cf_pw_hint">Leave blank to keep current password</div>
      </div>
      <div id="cf_err" style="display:none;color:var(--red);font-size:.85rem;padding:8px 12px;background:#fdecea;border-radius:8px;margin-bottom:10px"></div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-sm" onclick="saveChair()">Save Chair</button>
        <button class="btn btn-ghost btn-sm" onclick="closeChairForm()">Cancel</button>
      </div>
    </div>

    <!-- Chair list -->
    <div id="chairList">
      <div class="muted" style="font-size:.85rem">Loading…</div>
    </div>
  </div>

  <?php endif; ?>

  <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:4px;padding-bottom:40px;flex-wrap:wrap">
    <button class="btn btn-ghost" onclick="resetPersonal()" title="Remove all your personal colour and font customisations">
      &#8635; Reset My Personal Settings
    </button>
    <div style="display:flex;gap:10px">
      <button class="btn btn-ghost" onclick="loadSettings()">↺ Reload</button>
      <button class="btn btn-primary" onclick="saveSettings()">Save All Settings</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
// ---- Font options (system stacks guarantee visual difference even offline) ----
const WEB_FONTS = [
  { key:'Outfit',          label:'Outfit',       note:'Modern (default)',  google:'Outfit:wght@300;400;500;600;700',       css:"'Outfit','Trebuchet MS',sans-serif",          sample:'Aa Bb' },
  { key:'Inter',           label:'Inter',        note:'Clean minimal',     google:'Inter:wght@300;400;500;700',            css:"'Inter','Segoe UI',sans-serif",              sample:'Aa Bb' },
  { key:'Lato',            label:'Lato',         note:'Friendly',          google:'Lato:wght@300;400;700',                 css:"'Lato','Helvetica Neue',sans-serif",         sample:'Aa Bb' },
  { key:'Poppins',         label:'Poppins',      note:'Geometric round',   google:'Poppins:wght@300;400;500;600;700',      css:"'Poppins',sans-serif",                       sample:'Aa Bb' },
  { key:'Nunito',          label:'Nunito',       note:'Soft & round',      google:'Nunito:wght@300;400;600;700',           css:"'Nunito',sans-serif",                        sample:'Aa Bb' },
  { key:'Raleway',         label:'Raleway',      note:'Elegant headers',   google:'Raleway:wght@300;400;500;700',          css:"'Raleway',sans-serif",                       sample:'Aa Bb' },
  { key:'Montserrat',      label:'Montserrat',   note:'Bold geometric',    google:'Montserrat:wght@300;400;500;700',       css:"'Montserrat',sans-serif",                    sample:'Aa Bb' },
  { key:'Open Sans',       label:'Open Sans',    note:'Versatile',         google:'Open+Sans:wght@300;400;600;700',        css:"'Open Sans','Arial',sans-serif",             sample:'Aa Bb' },
  { key:'Source Sans 3',   label:'Source Sans',  note:'Readable body',     google:'Source+Sans+3:wght@300;400;600;700',   css:"'Source Sans 3','Helvetica Neue',sans-serif", sample:'Aa Bb' },
  { key:'Merriweather',    label:'Merriweather', note:'Classic serif',     google:'Merriweather:wght@300;400;700',         css:"'Merriweather','Georgia',serif",             sample:'Aa Bb' },
  { key:'Playfair Display',label:'Playfair',     note:'Elegant serif',     google:'Playfair+Display:wght@400;500;700',     css:"'Playfair Display','Georgia',serif",         sample:'Aa Bb' },
  { key:'Roboto',          label:'Roboto',       note:'Google style',      google:'Roboto:wght@300;400;500;700',           css:"'Roboto','Helvetica Neue',sans-serif",       sample:'Aa Bb' },
  { key:'Roboto Slab',     label:'Roboto Slab',  note:'Slab serif',        google:'Roboto+Slab:wght@300;400;700',          css:"'Roboto Slab','Georgia',serif",              sample:'Aa Bb' },
  { key:'PT Sans',         label:'PT Sans',      note:'Humanist',          google:'PT+Sans:wght@400;700',                  css:"'PT Sans','Helvetica Neue',sans-serif",      sample:'Aa Bb' },
  { key:'Georgia',         label:'Georgia',      note:'System serif',      google:null,                                    css:"'Georgia','Times New Roman',serif",          sample:'Aa Bb' },
  { key:'Courier New',     label:'Courier',      note:'Monospace',         google:null,                                    css:"'Courier New','Courier',monospace",           sample:'Aa Bb' },
  { key:'system',          label:'System UI',    note:'Native OS font',    google:null,                                    css:"system-ui,-apple-system,'Segoe UI',sans-serif", sample:'Aa Bb' },
];

// ---- Color presets — web theme (all fields) ----
const PRESETS_STANDARD = [
  { label:'Amber',      accent:'#c97b1f', ink:'#1d2433', navTxt:'#f5f0e6', text:'#1d2433', muted:'#495066', link:'#c97b1f', paper:'#f5f0e6', card:'#fffdf8', line:'#d9cfba' },
  { label:'Navy',       accent:'#2f5d8f', ink:'#1a2744', navTxt:'#e8eef8', text:'#1a2744', muted:'#4a5875', link:'#2f5d8f', paper:'#f0f3f8', card:'#fafcff', line:'#cdd6e8' },
  { label:'Forest',     accent:'#2f7d54', ink:'#1a3020', navTxt:'#e0f0e8', text:'#1a3020', muted:'#445850', link:'#2f7d54', paper:'#f0f5f1', card:'#fafdf8', line:'#c8dace' },
  { label:'Maroon',     accent:'#8b2020', ink:'#2d1010', navTxt:'#f5e8e8', text:'#2d1010', muted:'#6a4040', link:'#8b2020', paper:'#faf2f2', card:'#fff8f8', line:'#e0c8c8' },
  { label:'Slate',      accent:'#5b6e8a', ink:'#2c3545', navTxt:'#e8ecf2', text:'#2c3545', muted:'#5a6478', link:'#5b6e8a', paper:'#f2f4f7', card:'#fafbfd', line:'#d0d6e0' },
  { label:'Violet',     accent:'#6b4fbb', ink:'#1e1535', navTxt:'#ede8f8', text:'#1e1535', muted:'#5a4878', link:'#6b4fbb', paper:'#f4f0fc', card:'#fdfaff', line:'#d8d0ec' },
  { label:'Pure White', accent:'#444444', ink:'#ffffff', navTxt:'#111111', text:'#111111', muted:'#666666', link:'#333333', paper:'#ffffff', card:'#ffffff', line:'#cccccc',
    _tablePreset:'PureWhite' },
];
const PRESETS_PASTEL = [
  { label:'Sky',        accent:'#5b8dcd', ink:'#1e3a5f', navTxt:'#e8f2fc', text:'#1e3a5f', muted:'#4a6080', link:'#5b8dcd', paper:'#f0f5fc', card:'#fafcff', line:'#c8d8ee' },
  { label:'Sage',       accent:'#5aa882', ink:'#1a3d2c', navTxt:'#e0f5ec', text:'#1a3d2c', muted:'#406855', link:'#5aa882', paper:'#f0f8f4', card:'#fafdf8', line:'#c0ddd0' },
  { label:'Rose',       accent:'#c47490', ink:'#4a1f30', navTxt:'#fce8f0', text:'#4a1f30', muted:'#7a4858', link:'#c47490', paper:'#fdf0f5', card:'#fff8fb', line:'#e8c8d4' },
  { label:'Lavender',   accent:'#8b72c8', ink:'#2d1854', navTxt:'#ede8fc', text:'#2d1854', muted:'#6850a0', link:'#8b72c8', paper:'#f4f0fc', card:'#fdfaff', line:'#d8ccea' },
  { label:'Coral',      accent:'#d4724a', ink:'#5a1f0a', navTxt:'#fce8e0', text:'#5a1f0a', muted:'#8a4830', link:'#d4724a', paper:'#fdf2ee', card:'#fffaf8', line:'#e8ccc0' },
  { label:'Teal',       accent:'#3a9fa8', ink:'#0e3338', navTxt:'#e0f5f8', text:'#0e3338', muted:'#306870', link:'#3a9fa8', paper:'#eef8f8', card:'#fafefe', line:'#b8dce0' },
  { label:'Dusty Blue', accent:'#6b93b8', ink:'#243550', navTxt:'#e8eff8', text:'#243550', muted:'#4a6080', link:'#6b93b8', paper:'#f0f4f8', card:'#fafcff', line:'#c8d5e4' },
  { label:'Olive',      accent:'#7e9a3a', ink:'#2a3210', navTxt:'#eaf0e0', text:'#2a3210', muted:'#506030', link:'#7e9a3a', paper:'#f2f5ec', card:'#fafdf5', line:'#ccd8b8' },
  { label:'Peach',      accent:'#d48e6a', ink:'#5a2d10', navTxt:'#fce8e0', text:'#5a2d10', muted:'#8a5838', link:'#d48e6a', paper:'#fdf4ee', card:'#fffbf8', line:'#e8d0c0' },
  { label:'Lilac',      accent:'#a87ec8', ink:'#35184a', navTxt:'#f0e8f8', text:'#35184a', muted:'#706088', link:'#a87ec8', paper:'#f8f0fc', card:'#fdfaff', line:'#ddd0ec' },
  { label:'Mint',       accent:'#4aab8e', ink:'#103828', navTxt:'#e0f5ee', text:'#103828', muted:'#387060', link:'#4aab8e', paper:'#eef8f4', card:'#fafdf8', line:'#bce0d4' },
  { label:'Sand',       accent:'#c8a45a', ink:'#3d2e0a', navTxt:'#f8f0e0', text:'#3d2e0a', muted:'#705e38', link:'#c8a45a', paper:'#faf5e8', card:'#fdfaf2', line:'#e0d0a8' },
];

const PDF_FONTS = [
  { key:'Helvetica', label:'Helvetica',      sample:'Aa Bb Cc', note:'Clean sans-serif — recommended for body',  family:'Arial,Helvetica,sans-serif' },
  { key:'Times',     label:'Times New Roman',sample:'Aa Bb Cc', note:'Classic serif — good for formal reports',  family:'Georgia,serif' },
  { key:'Courier',   label:'Courier',        sample:'Aa Bb Cc', note:'Monospace — for structured data',         family:'Courier New,monospace' },
];

// ---- PDF Report Theme Presets ----
// Each preset: { label, desc, hdr, acc, pass, fail, text }
// All values are RGB strings for the FPDF color fields.
const PDF_THEMES = {
  dark: [
    {
      label:'Classic Dark',  desc:'Default professional look — dark navy header',
      hdr:'29,36,51',   acc:'201,123,31', pass:'47,125,84',  fail:'178,59,59',  text:'0,0,0',
      swatch:'#1d2433',  accentSwatch:'#c97b1f',
    },
    {
      label:'Deep Forest',   desc:'Rich dark green — great for science subjects',
      hdr:'24,52,38',   acc:'195,140,40', pass:'40,110,65',  fail:'180,55,55',  text:'0,0,0',
      swatch:'#183426',  accentSwatch:'#c38c28',
    },
    {
      label:'Midnight Blue', desc:'Deep blue header with gold accent',
      hdr:'18,32,68',   acc:'190,155,40', pass:'44,115,78',  fail:'180,55,55',  text:'0,0,0',
      swatch:'#122044',  accentSwatch:'#be9b28',
    },
    {
      label:'Charcoal',      desc:'Neutral dark grey — versatile and clean',
      hdr:'45,45,50',   acc:'160,110,50', pass:'55,120,75',  fail:'175,60,60',  text:'0,0,0',
      swatch:'#2d2d32',  accentSwatch:'#a06e32',
    },
    {
      label:'Maroon & Gold', desc:'School colors — formal and traditional',
      hdr:'80,20,20',   acc:'200,160,40', pass:'50,110,65',  fail:'170,50,50',  text:'0,0,0',
      swatch:'#501414',  accentSwatch:'#c8a028',
    },
  ],
  light: [
    {
      label:'Clean White',   desc:'Light grey header — easy to read on any printer',
      hdr:'220,222,228', acc:'100,110,180', pass:'60,140,90',  fail:'190,65,65',  text:'20,20,40',
      swatch:'#dcdee4',  accentSwatch:'#6470b4',
    },
    {
      label:'Warm Parchment',desc:'Warm off-white — soft and professional',
      hdr:'235,228,210', acc:'140,100,40',  pass:'65,130,80',  fail:'185,65,65',  text:'30,25,15',
      swatch:'#ebe4d2',  accentSwatch:'#8c6428',
    },
    {
      label:'Sky Blue',      desc:'Light blue header — calm and academic',
      hdr:'195,215,238', acc:'45,95,160',   pass:'50,130,80',  fail:'185,65,65',  text:'20,30,60',
      swatch:'#c3d7ee',  accentSwatch:'#2d5fa0',
    },
    {
      label:'Sage Green',    desc:'Soft green header — natural and readable',
      hdr:'195,220,205', acc:'50,110,70',   pass:'45,125,75',  fail:'185,65,65',  text:'20,45,30',
      swatch:'#c3dcd0',  accentSwatch:'#326e46',
    },
    {
      label:'Lavender',      desc:'Soft purple — elegant and distinct',
      hdr:'215,210,238', acc:'95,75,165',   pass:'55,125,80',  fail:'185,65,65',  text:'30,20,60',
      swatch:'#d7d2ee',  accentSwatch:'#5f4ba5',
    },
    {
      label:'Warm Coral',    desc:'Warm rose header — friendly and modern',
      hdr:'238,210,205', acc:'175,70,60',   pass:'55,125,80',  fail:'185,65,65',  text:'50,20,15',
      swatch:'#eed2cd',  accentSwatch:'#af463c',
    },
  ],
  pastel: [
    {
      label:'Pastel Mint',   desc:'Soft mint green — fresh and light',
      hdr:'180,225,210', acc:'50,140,105',  pass:'45,130,85',  fail:'200,80,75',  text:'20,50,40',
      swatch:'#b4e1d2',  accentSwatch:'#328c69',
    },
    {
      label:'Pastel Sky',    desc:'Baby blue — airy and calm',
      hdr:'185,215,240', acc:'60,110,175',  pass:'50,130,85',  fail:'200,80,75',  text:'20,35,65',
      swatch:'#b9d7f0',  accentSwatch:'#3c6eaf',
    },
    {
      label:'Pastel Rose',   desc:'Soft pink — warm and approachable',
      hdr:'242,205,210', acc:'190,85,100',  pass:'55,125,80',  fail:'195,70,70',  text:'55,20,30',
      swatch:'#f2cdd2',  accentSwatch:'#be5564',
    },
    {
      label:'Pastel Lilac',  desc:'Soft purple — gentle and refined',
      hdr:'220,210,238', acc:'110,90,175',  pass:'55,125,80',  fail:'195,70,70',  text:'35,20,65',
      swatch:'#dcd2ee',  accentSwatch:'#6e5aaf',
    },
    {
      label:'Pastel Peach',  desc:'Warm peach — inviting and soft',
      hdr:'245,220,200', acc:'190,115,55',  pass:'55,125,80',  fail:'195,70,70',  text:'55,30,10',
      swatch:'#f5dcc8',  accentSwatch:'#be7337',
    },
    {
      label:'Pastel Butter', desc:'Soft yellow — bright and cheerful',
      hdr:'245,238,190', acc:'160,135,30',  pass:'55,125,80',  fail:'195,70,70',  text:'45,40,10',
      swatch:'#f5eebe',  accentSwatch:'#a0871e',
    },
  ],
  bw: [
    {
      label:'Clean Black & White',
      desc:'No fill anywhere — black text, gray borders, red only for failing grades',
      hdr:'80,80,80',        acc:'80,80,80',        pass:'255,255,255',  fail:'248,215,218',  text:'0,0,0',
      swatch:'#505050',      accentSwatch:'#505050',
      sub:'110,110,110',     subTxt:'255,255,255',
      rowOdd:'255,255,255',  rowEven:'255,255,255', border:'150,150,150',
      equiv:'255,255,255',   equivTxt:'0,0,0',
      ws:'255,255,255',      wsTxt:'0,0,0',
      grade:'255,255,255',   gradeTxt:'0,0,0',
      passTxt:'0,0,0',       failTxt:'114,28,36',
      inc:'255,255,255',     incTxt:'0,0,0',
    },
    {
      label:'Pure White',
      desc:'White headers, black text, light gray borders — no fill anywhere except fail grade',
      hdr:'255,255,255',     acc:'68,68,68',        pass:'255,255,255',  fail:'253,232,234',  text:'0,0,0',
      swatch:'#ffffff',      accentSwatch:'#444444',
      sub:'245,245,245',     subTxt:'17,17,17',
      rowOdd:'255,255,255',  rowEven:'250,250,250', border:'180,180,180',
      equiv:'255,255,255',   equivTxt:'17,17,17',
      ws:'255,255,255',      wsTxt:'17,17,17',
      grade:'255,255,255',   gradeTxt:'17,17,17',
      passTxt:'17,17,17',    failTxt:'160,0,0',
      inc:'255,255,255',     incTxt:'100,100,100',
    },
    {
      label:'Ink & Paper',
      desc:'Pure black header, white body, gray borders — classic academic look',
      hdr:'0,0,0',           acc:'0,0,0',           pass:'255,255,255',  fail:'248,215,218',  text:'0,0,0',
      swatch:'#000000',      accentSwatch:'#444444',
      sub:'50,50,50',        subTxt:'255,255,255',
      rowOdd:'255,255,255',  rowEven:'248,248,248', border:'120,120,120',
      equiv:'255,255,255',   equivTxt:'0,0,0',
      ws:'255,255,255',      wsTxt:'0,0,0',
      grade:'255,255,255',   gradeTxt:'0,0,0',
      passTxt:'0,0,0',       failTxt:'114,28,36',
      inc:'255,255,255',     incTxt:'0,0,0',
    },
  ],
};

function buildPdfPresets() {
  const groups = [
    { key:'dark',   label:'Dark Themes',   note:'Bold headers — best for formal printed reports' },
    { key:'light',  label:'Light Themes',  note:'Light headers — saves ink, easy on screen preview' },
    { key:'pastel', label:'Pastel Themes', note:'Soft pastel headers — gentle, colorful, modern' },
    { key:'bw',     label:'Plain B&W',     note:'All black & white — most ink-efficient, professional' },
  ];
  document.getElementById('pdfPresets').innerHTML = groups.map(g => {
    if (!PDF_THEMES[g.key]) return '';
    const cards = PDF_THEMES[g.key].map(t => {
      const tJson = JSON.stringify(t).replace(/"/g,'&quot;');
      return `
      <div onclick="applyPdfPresetFull(JSON.parse(this.dataset.t))" data-t="${tJson}"
        style="cursor:pointer;border:2px solid var(--line);border-radius:10px;overflow:hidden;
               transition:border-color .15s,transform .1s;flex:1;min-width:105px;max-width:160px"
        onmouseover="this.style.borderColor='var(--amber)';this.style.transform='scale(1.03)'"
        onmouseout="this.style.borderColor='var(--line)';this.style.transform=''"
        title="${t.desc}">
        <div style="height:46px;background:rgb(${t.hdr});display:flex;align-items:center;
                    justify-content:space-between;padding:0 8px;gap:4px">
          <div style="font-size:.7rem;font-weight:700;color:rgba(255,255,255,.9);
                      text-shadow:0 1px 2px rgba(0,0,0,.3);flex:1;white-space:nowrap;
                      overflow:hidden;text-overflow:ellipsis">${t.label}</div>
          <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0">
            <div style="width:22px;height:6px;border-radius:2px;background:rgb(${t.pass})"></div>
            <div style="width:22px;height:6px;border-radius:2px;background:rgb(${t.fail})"></div>
          </div>
        </div>
        <div style="height:5px;background:rgb(${t.acc})"></div>
        <div style="padding:6px 8px;background:#fff;font-size:.72rem;color:#555;line-height:1.3">
          ${t.desc}
        </div>
      </div>`;
    }).join('');
    return `<div>
      <div style="font-size:.8rem;font-weight:700;color:var(--ink-soft);margin-bottom:8px;
                  display:flex;align-items:center;gap:8px">
        ${g.label}
        <span style="font-weight:400;color:var(--ink-soft);font-size:.75rem">${g.note}</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">${cards}</div>
    </div>`;
  }).join('');
}

function applyPdfPreset(hdr, acc, pass, fail, text) {
  applyPdfPresetFull({hdr, acc, pass, fail, text});
}
function applyPdfPresetFull(t) {
  const hdr = t.hdr, acc = t.acc, pass = t.pass, fail = t.fail, text = t.text;
  setPdfColor('p_hdr',  hdr);  setPdfColor('p_acc', acc);
  setPdfColor('p_pass', pass); setPdfColor('p_fail', fail);
  setPdfColor('p_text', text); setPdfColor('p_sig_txt', text);
  setPdfColor('p_subj_txt', text);
  // Auto-detect header text: white bg gets dark text; dark bg gets light text
  const brightness = hdr.split(',').map(Number).reduce((a,b)=>a+b,0)/3;
  const isDark = brightness < 200;
  const hdrTxt = t.subTxt || (isDark ? '245,240,230' : '17,17,17');
  setPdfColor('p_hdr_txt',   hdrTxt);
  setPdfColor('p_title_txt', hdrTxt);
  setPdfColor('p_th_bg',  hdr); setPdfColor('p_th_txt', hdrTxt);
  const sub = t.sub || hdr.split(',').map(n=>Math.min(255,Math.max(0,parseInt(n)+(isDark?36:-20))).toString()).join(',');
  const subTxt = t.subTxt || hdrTxt;
  setPdfColor('p_th2_bg', sub); setPdfColor('p_th2_txt', subTxt);
  setPdfColor('p_subtitle_txt', acc);
  // Extended fields
  if (t.rowOdd)    setPdfColor('p_row_odd',   t.rowOdd);
  if (t.rowEven)   setPdfColor('p_row_even',  t.rowEven);
  if (t.border)    setPdfColor('p_border',    t.border);
  if (t.equiv)     setPdfColor('p_equiv',     t.equiv);
  if (t.equivTxt)  setPdfColor('p_equiv_txt', t.equivTxt);
  if (t.ws)        setPdfColor('p_ws',        t.ws);
  if (t.wsTxt)     setPdfColor('p_ws_txt',    t.wsTxt);
  if (t.grade)     setPdfColor('p_grade',     t.grade);
  if (t.gradeTxt)  setPdfColor('p_grade_txt', t.gradeTxt);
  // Term and final col colors for final report
  if (t.termCol)    setPdfColor('p_term_col',     t.termCol);
  if (t.termColTxt) setPdfColor('p_term_col_txt', t.termColTxt);
  if (t.finalCol)   setPdfColor('p_final_col',    t.finalCol);
  if (t.finalColTxt)setPdfColor('p_final_col_txt',t.finalColTxt);
  // pass/fail text: respect explicit values, else auto-pick for contrast
  setPdfColor('p_pass_txt', t.passTxt || (brightness<200 ? '255,255,255' : '0,0,0'));
  setPdfColor('p_fail_txt', t.failTxt || '114,28,36');
  if (t.inc)       setPdfColor('p_inc',       t.inc);
  if (t.incTxt)    setPdfColor('p_inc_txt',   t.incTxt);
  updatePdfPreview();
  toast('PDF theme applied — click Save to keep it');
}

buildPdfPresets();
let curWebFont = 'Outfit', curPdfTitle = 'Times', curPdfBody = 'Helvetica';

// Build web font picker (uses system stack for immediate visual preview even offline)
document.getElementById('webFontCards').innerHTML = WEB_FONTS.map(f => {
  const safeCss = f.css.replace(/'/g, '&apos;');
  return `<div class="font-card" id="wf_${f.key}"
    onclick="selectWebFont('${f.key}','${f.css.replace(/'/g,"\\'")}','${f.google||''}')"
    style="font-family:${f.css}">
    <div class="sample">${f.sample}</div>
    <div style="font-size:.9rem;font-weight:600">${f.label}</div>
    <div class="label">${f.note}</div>
  </div>`;
}).join('');

// Build PDF font pickers
const pdfFontHtml = (idPfx, fn) => PDF_FONTS.map(f => `
  <div class="pdf-font-card" id="${idPfx}_${f.key}" onclick="${fn}('${f.key}')">
    <div class="sample" style="font-family:${f.family||'sans-serif'}">${f.sample}</div>
    <div style="font-size:.82rem;font-weight:600">${f.label}</div>
    <div style="font-size:.72rem;color:var(--ink-soft)">${f.note}</div>
  </div>`).join('');
document.getElementById('pdfTitleFontCards').innerHTML = pdfFontHtml('ptf','selectPdfTitleFont');
document.getElementById('pdfBodyFontCards').innerHTML  = pdfFontHtml('pbf','selectPdfBodyFont');

// ---- Professional table color presets (full-coverage — all 30 color fields) ----
const TABLE_PRESETS = [
  // Each preset defines ALL color fields. Fields: hdr/hdrTxt=criterion header, sub/subTxt=activity row,
  // avgHdr/avgHdrTxt=AVG-WS header, odd/even=row bg, rawBg/rawTxt=score cell, avgBg/wsBg=computed cells,
  // equiv/equivTxt=equiv cell, grade/gradeTxt=term grade pass, final/finalTxt=final hdr,
  // termCol/termColTxt=term cols on final sheet, failBg/failTxt=failing grade,
  // rmkPass/rmkFail/rmkInc + txt=remarks, aiBtn/aiBtnTxt=analyze button
  { label:'Classic',
    hdr:'#1d2433',hdrTxt:'#f5f0e6',sub:'#2e4030',subTxt:'#f5f0e6',avgHdr:'#c97b1f',avgHdrTxt:'#ffffff',
    odd:'#ffffff',even:'#f5f2eb',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#d8ecdf',equivTxt:'#155724',grade:'#f0d9b5',gradeTxt:'#3d2a00',final:'#c97b1f',finalTxt:'#ffffff',
    termCol:'#f0d9b5',termColTxt:'#3d2a00',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#d4edda',rmkPassTxt:'#155724',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#c97b1f',aiBtnTxt:'#ffffff' },
  { label:'Ocean',
    hdr:'#1a3a4a',hdrTxt:'#e0f0f8',sub:'#1e4a5a',subTxt:'#e0f0f8',avgHdr:'#2e7d9a',avgHdrTxt:'#ffffff',
    odd:'#f5fafc',even:'#e4f0f6',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#b8e8d8',equivTxt:'#0a4030',grade:'#b8dce8',gradeTxt:'#0a2030',final:'#2e7d9a',finalTxt:'#ffffff',
    termCol:'#b8dce8',termColTxt:'#0a2030',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#c8e8d4',rmkPassTxt:'#0a4020',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#2e7d9a',aiBtnTxt:'#ffffff' },
  { label:'Forest',
    hdr:'#1e3a28',hdrTxt:'#e0f0e8',sub:'#284832',subTxt:'#e0f0e8',avgHdr:'#2e7d32',avgHdrTxt:'#ffffff',
    odd:'#f5faf5',even:'#e8f4ea',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#a5d6a7',equivTxt:'#1a3820',grade:'#c8e6c9',gradeTxt:'#1a3820',final:'#2e7d32',finalTxt:'#ffffff',
    termCol:'#c8e6c9',termColTxt:'#1a3820',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#c8e6c9',rmkPassTxt:'#1a3820',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#2e7d32',aiBtnTxt:'#ffffff' },
  { label:'Slate',
    hdr:'#2c3545',hdrTxt:'#e8ecf2',sub:'#3a4355',subTxt:'#e8ecf2',avgHdr:'#5b6e8a',avgHdrTxt:'#ffffff',
    odd:'#f8f9fb',even:'#edf0f5',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#c8e0d8',equivTxt:'#1a3030',grade:'#d0d8e8',gradeTxt:'#1a2030',final:'#5b6e8a',finalTxt:'#ffffff',
    termCol:'#d0d8e8',termColTxt:'#1a2030',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#c8e0d4',rmkPassTxt:'#1a3030',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#5b6e8a',aiBtnTxt:'#ffffff' },
  { label:'Maroon',
    hdr:'#3d1515',hdrTxt:'#fce8e8',sub:'#4d2020',subTxt:'#fce8e8',avgHdr:'#8b2020',avgHdrTxt:'#ffffff',
    odd:'#fff8f8',even:'#f9eeee',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#d8edd8',equivTxt:'#1a3820',grade:'#f5d0d0',gradeTxt:'#3d1515',final:'#8b2020',finalTxt:'#ffffff',
    termCol:'#f5d0d0',termColTxt:'#3d1515',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#d4edda',rmkPassTxt:'#155724',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#8b2020',aiBtnTxt:'#ffffff' },
  { label:'Warm Sand',
    hdr:'#3d2e0a',hdrTxt:'#f8f0e0',sub:'#4d3a10',subTxt:'#f8f0e0',avgHdr:'#c8a45a',avgHdrTxt:'#ffffff',
    odd:'#fffdf8',even:'#f7f2e5',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#d8e8cc',equivTxt:'#1a3010',grade:'#f0d9a0',gradeTxt:'#3d2a00',final:'#c8a45a',finalTxt:'#ffffff',
    termCol:'#f0d9a0',termColTxt:'#3d2a00',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#d4edda',rmkPassTxt:'#155724',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#c8a45a',aiBtnTxt:'#ffffff' },
  { label:'Midnight',
    hdr:'#12122a',hdrTxt:'#d0d0f0',sub:'#1e1e3a',subTxt:'#d0d0f0',avgHdr:'#4040a0',avgHdrTxt:'#ffffff',
    odd:'#f8f8ff',even:'#ededfa',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#c8e0d8',equivTxt:'#0a2030',grade:'#d4d0f0',gradeTxt:'#12122a',final:'#4040a0',finalTxt:'#ffffff',
    termCol:'#d4d0f0',termColTxt:'#12122a',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#c8e0d4',rmkPassTxt:'#0a2030',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#4040a0',aiBtnTxt:'#ffffff' },
  { label:'Teal',
    hdr:'#0e3338',hdrTxt:'#e0f5f8',sub:'#184048',subTxt:'#e0f5f8',avgHdr:'#3a9fa8',avgHdrTxt:'#ffffff',
    odd:'#f5feff',even:'#e8f8f8',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#b8e8cc',equivTxt:'#083828',grade:'#b8e0e4',gradeTxt:'#0a3038',final:'#3a9fa8',finalTxt:'#ffffff',
    termCol:'#b8e0e4',termColTxt:'#0a3038',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#b8e8cc',rmkPassTxt:'#083828',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#3a9fa8',aiBtnTxt:'#ffffff' },
  { label:'Lavender',
    hdr:'#2d1854',hdrTxt:'#ede8fc',sub:'#3d2864',subTxt:'#ede8fc',avgHdr:'#6b4fbb',avgHdrTxt:'#ffffff',
    odd:'#fdfaff',even:'#f3eefc',rawBg:'',rawTxt:'',avgBg:'',wsBg:'',
    equiv:'#c8e4d4',equivTxt:'#083828',grade:'#d8d0f0',gradeTxt:'#2d1854',final:'#6b4fbb',finalTxt:'#ffffff',
    termCol:'#d8d0f0',termColTxt:'#2d1854',failBg:'#f8d7da',failTxt:'#721c24',
    rmkPass:'#c8e4d4',rmkPassTxt:'#083828',rmkFail:'#f8d7da',rmkFailTxt:'#721c24',
    rmkInc:'#fff3cd',rmkIncTxt:'#856404',aiBtn:'#6b4fbb',aiBtnTxt:'#ffffff' },
  // ── Plain Black & White ── no fill anywhere, gray headers, red ONLY for failing grade
  { label:'Clean B&W',
    hdr:'#555555',hdrTxt:'#ffffff',sub:'#777777',subTxt:'#ffffff',avgHdr:'#777777',avgHdrTxt:'#ffffff',
    odd:'#ffffff',even:'#ffffff',rawBg:'#ffffff',rawTxt:'#000000',avgBg:'#ffffff',wsBg:'#ffffff',
    equiv:'#ffffff',equivTxt:'#000000',grade:'#ffffff',gradeTxt:'#000000',final:'#555555',finalTxt:'#ffffff',
    termCol:'#ffffff',termColTxt:'#000000',failBg:'#fde8ea',failTxt:'#a00000',
    rmkPass:'#ffffff',rmkPassTxt:'#000000',rmkFail:'#fde8ea',rmkFailTxt:'#a00000',
    rmkInc:'#ffffff',rmkIncTxt:'#555555',aiBtn:'#555555',aiBtnTxt:'#ffffff' },
  { label:'Ink & Paper',
    hdr:'#000000',hdrTxt:'#ffffff',sub:'#333333',subTxt:'#ffffff',avgHdr:'#444444',avgHdrTxt:'#ffffff',
    odd:'#ffffff',even:'#f8f8f8',rawBg:'',rawTxt:'#000000',avgBg:'',wsBg:'',
    equiv:'#f0f0f0',equivTxt:'#000000',grade:'#f0f0f0',gradeTxt:'#000000',final:'#000000',finalTxt:'#ffffff',
    termCol:'#f0f0f0',termColTxt:'#000000',failBg:'#fde8ea',failTxt:'#a00000',
    rmkPass:'#f0f0f0',rmkPassTxt:'#000000',rmkFail:'#fde8ea',rmkFailTxt:'#a00000',
    rmkInc:'#f0f0f0',rmkIncTxt:'#555555',aiBtn:'#444444',aiBtnTxt:'#ffffff' },
  // ── Pure White — no fill anywhere including nav; black text; gray borders; red for fail only ──
  { label:'Pure White',
    hdr:'#ffffff',hdrTxt:'#111111',sub:'#f0f0f0',subTxt:'#111111',avgHdr:'#f0f0f0',avgHdrTxt:'#111111',
    odd:'#ffffff',even:'#fafafa',rawBg:'#ffffff',rawTxt:'#111111',avgBg:'#ffffff',wsBg:'#ffffff',
    equiv:'#ffffff',equivTxt:'#111111',grade:'#ffffff',gradeTxt:'#111111',final:'#ffffff',finalTxt:'#111111',
    termCol:'#ffffff',termColTxt:'#111111',failBg:'#fde8ea',failTxt:'#a00000',
    rmkPass:'#ffffff',rmkPassTxt:'#111111',rmkFail:'#fde8ea',rmkFailTxt:'#a00000',
    rmkInc:'#ffffff',rmkIncTxt:'#666666',aiBtn:'#444444',aiBtnTxt:'#ffffff',
    nameBg:'#ffffff',nameTxt:'#111111',nameEvenBg:'#fafafa',computedBg:'#ffffff',
    wtColor:'#888888',aiCellBg:'#ffffff',aiCellTxt:'#111111',rowOddTxt:'#111111',rowEvenTxt:'#111111' },
];

// Build table preset buttons
document.getElementById('tablePresets').innerHTML = TABLE_PRESETS.map(p =>
  `<button class="btn btn-ghost btn-sm" style="border-left:4px solid ${p.hdr};font-size:.78rem;padding:4px 10px"
    onclick="applyTablePreset(${JSON.stringify(p).replace(/"/g,'&quot;')})">${p.label}</button>`
).join('');

function applyTablePreset(p) {
  if (typeof p === 'string') p = JSON.parse(p);
  const sc = (pick, hex, cssVar, val) => { if (val !== undefined && document.getElementById(pick)) syncColorField(pick, hex, cssVar, val); };
  sc('s_tbl_header','s_tbl_header_hex','--tbl-header',p.hdr);
  sc('s_tbl_header_txt','s_tbl_header_txt_hex','--tbl-header-txt',p.hdrTxt);
  sc('s_tbl_sub_header','s_tbl_sub_header_hex','--tbl-sub-header',p.sub);
  sc('s_tbl_sub_header_txt','s_tbl_sub_header_txt_hex','--tbl-sub-header-txt',p.subTxt);
  sc('s_tbl_avg_header','s_tbl_avg_header_hex','--tbl-avg-header',p.avgHdr);
  sc('s_tbl_avg_header_txt','s_tbl_avg_header_txt_hex','--tbl-avg-header-txt',p.avgHdrTxt);
  sc('s_tbl_row_odd','s_tbl_row_odd_hex','--tbl-row-odd',p.odd);
  sc('s_tbl_row_even','s_tbl_row_even_hex','--tbl-row-even',p.even);
  if (p.rawBg  !== undefined) sc('s_tbl_raw_bg','s_tbl_raw_bg_hex','--tbl-raw-bg',p.rawBg);
  if (p.rawTxt !== undefined) sc('s_tbl_raw_txt','s_tbl_raw_txt_hex','--tbl-raw-txt',p.rawTxt);
  if (p.avgBg  !== undefined) sc('s_tbl_avg_bg','s_tbl_avg_bg_hex','--tbl-avg-bg',p.avgBg);
  if (p.wsBg   !== undefined) sc('s_tbl_ws_bg','s_tbl_ws_bg_hex','--tbl-ws-bg',p.wsBg);
  sc('s_hl_equiv','s_hl_equiv_hex','--hl-equiv',p.equiv);
  sc('s_hl_equiv_txt','s_hl_equiv_txt_hex','--hl-equiv-txt',p.equivTxt);
  sc('s_hl_grade','s_hl_grade_hex','--hl-grade',p.grade);
  sc('s_hl_grade_txt','s_hl_grade_txt_hex','--hl-grade-txt',p.gradeTxt);
  sc('s_hl_final','s_hl_final_hex','--hl-final',p.final);
  sc('s_hl_final_txt','s_hl_final_txt_hex','--hl-final-txt',p.finalTxt);
  sc('s_hl_term_col','s_hl_term_col_hex','--hl-term-col',p.termCol);
  sc('s_hl_term_col_txt','s_hl_term_col_txt_hex','--hl-term-col-txt',p.termColTxt);
  sc('s_hl_fail_bg','s_hl_fail_bg_hex','--hl-fail-bg',p.failBg);
  sc('s_hl_fail_txt','s_hl_fail_txt_hex','--hl-fail-txt',p.failTxt);
  sc('s_hl_remarks_pass','s_hl_remarks_pass_hex','--hl-remarks-pass',p.rmkPass);
  sc('s_hl_remarks_pass_txt','s_hl_remarks_pass_txt_hex','--hl-remarks-pass-txt',p.rmkPassTxt);
  sc('s_hl_remarks_fail','s_hl_remarks_fail_hex','--hl-remarks-fail',p.rmkFail);
  sc('s_hl_remarks_fail_txt','s_hl_remarks_fail_txt_hex','--hl-remarks-fail-txt',p.rmkFailTxt);
  sc('s_hl_remarks_inc','s_hl_remarks_inc_hex','--hl-remarks-inc',p.rmkInc);
  sc('s_hl_remarks_inc_txt','s_hl_remarks_inc_txt_hex','--hl-remarks-inc-txt',p.rmkIncTxt);
  sc('s_hl_ai_btn','s_hl_ai_btn_hex','--hl-ai-btn',p.aiBtn);
  sc('s_hl_ai_btn_txt','s_hl_ai_btn_txt_hex','--hl-ai-btn-txt',p.aiBtnTxt);
  // Student column & activity labels
  if (p.nameBg)       sc('s_tbl_name_bg',      's_tbl_name_bg_hex',      '--tbl-name-bg',      p.nameBg);
  if (p.nameTxt)      sc('s_tbl_name_txt',      's_tbl_name_txt_hex',     '--tbl-name-txt',     p.nameTxt);
  if (p.nameEvenBg)   sc('s_tbl_name_even_bg',  's_tbl_name_even_bg_hex', '--tbl-name-even-bg', p.nameEvenBg);
  if (p.computedBg !== undefined) sc('s_tbl_computed_bg','s_tbl_computed_bg_hex','--tbl-computed-bg', p.computedBg);
  if (p.wtColor)      sc('s_tbl_wt_color',      's_tbl_wt_color_hex',     '--tbl-wt-color',     p.wtColor);
  // AI cell
  if (p.aiCellBg !== undefined) sc('s_tbl_ai_cell_bg','s_tbl_ai_cell_bg_hex','--tbl-ai-cell-bg', p.aiCellBg);
  if (p.aiCellTxt)    sc('s_tbl_ai_cell_txt',   's_tbl_ai_cell_txt_hex',  '--tbl-ai-cell-txt',  p.aiCellTxt);
  // Row text
  if (p.rowOddTxt)    sc('s_tbl_row_odd_txt',   's_tbl_row_odd_txt_hex',  '--tbl-row-odd-txt',  p.rowOddTxt);
  if (p.rowEvenTxt)   sc('s_tbl_row_even_txt',  's_tbl_row_even_txt_hex', '--tbl-row-even-txt', p.rowEvenTxt);
  toast('Table theme "' + p.label + '" applied — click Save');
}
document.getElementById('presets').innerHTML =
  '<div style="width:100%;margin-bottom:6px;font-size:.8rem;color:var(--ink-soft);font-weight:600">Standard</div>' +
  PRESETS_STANDARD.map(p =>
    `<button class="btn btn-ghost btn-sm" onclick="applyPreset(${JSON.stringify(p).replace(/"/g,'&quot;')})"
      style="border-left:4px solid ${p.ink};padding-left:8px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${p.accent};margin-right:4px"></span>
      ${p.label}</button>`).join('') +
  '<div style="width:100%;margin:10px 0 6px;font-size:.8rem;color:var(--ink-soft);font-weight:600">Pastel</div>' +
  PRESETS_PASTEL.map(p =>
    `<button class="btn btn-ghost btn-sm" onclick="applyPreset(${JSON.stringify(p).replace(/"/g,'&quot;')})"
      style="border-left:4px solid ${p.ink};background:${p.accent}18;padding-left:8px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${p.accent};margin-right:4px"></span>
      ${p.label}</button>`).join('');

// ---- Apply a full web theme preset ----
function applyPreset(p) {
  if (typeof p === 'string') p = JSON.parse(p);
  syncColorField('s_accent',   's_accent_hex',   '--amber',    p.accent);
  syncColorField('s_ink',      's_ink_hex',       '--ink',      p.ink);
  syncColorField('s_nav_text', 's_nav_text_hex',  null,         p.navTxt  || '#f5f0e6');
  syncColorField('s_text',     's_text_hex',       null,         p.text    || p.ink);
  syncColorField('s_muted',    's_muted_hex',      '--ink-soft', p.muted   || '#495066');
  syncColorField('s_link',     's_link_hex',       null,         p.link    || p.accent);
  syncColorField('s_paper',    's_paper_hex',      '--paper',    p.paper   || '#f5f0e6');
  syncColorField('s_card',     's_card_hex',       '--card',     p.card    || '#fffdf8');
  syncColorField('s_line',     's_line_hex',       '--line',     p.line    || '#d9cfba');
  // hl_final follows accent color
  syncColorField('s_hl_final', 's_hl_final_hex',  '--hl-final', p.accent);
  // If this web preset has a matching table preset, apply it too
  if (p._tablePreset) {
    const tp = TABLE_PRESETS.find(t => t.label === p._tablePreset);
    if (tp) applyTablePreset(tp);
  }
  // Sync matching PDF colors
  if (document.getElementById('p_acc')) document.getElementById('p_acc').value = hexToRgb(p.accent);
  if (document.getElementById('p_hdr')) document.getElementById('p_hdr').value = hexToRgb(p.ink);
  updateThemePreview();
  toast('Theme "' + p.label + '" applied — click Save to keep it');
}

// ---- Font selection ----
function selectWebFont(key, css, googleFamily) {
  curWebFont = key;
  document.querySelectorAll('.font-card').forEach(el => el.classList.remove('active'));
  document.getElementById('wf_' + key)?.classList.add('active');
  // Dynamically load Google Font if needed (progressive enhancement for online users)
  const oldLink = document.getElementById('gfont-preview');
  if (oldLink) oldLink.remove();
  if (googleFamily) {
    const link = document.createElement('link');
    link.id = 'gfont-preview'; link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(googleFamily) + '&display=swap';
    document.head.appendChild(link);
  }
  // Apply immediately via inline style (overrides stylesheet rules)
  document.body.style.setProperty('font-family', css, 'important');
  document.querySelectorAll('input,select,textarea,button,h1,h2,h3,label').forEach(el => {
    el.style.setProperty('font-family', css, 'important');
  });
}
function selectPdfTitleFont(key) {
  curPdfTitle = key;
  document.querySelectorAll('[id^="ptf_"]').forEach(el => el.classList.remove('active'));
  document.getElementById('ptf_' + key)?.classList.add('active');
}
function selectPdfBodyFont(key) {
  curPdfBody = key;
  document.querySelectorAll('[id^="pbf_"]').forEach(el => el.classList.remove('active'));
  document.getElementById('pbf_' + key)?.classList.add('active');
}

// ---- Color helpers ----
function syncColor(pickId, hexId, val) {
  const hex = val.startsWith('#') ? val : rgbToHex(val);
  document.getElementById(pickId).value = hex;
  document.getElementById(hexId).value = hex;
}
function rgbToHex(rgb) {
  const p = rgb.split(',').map(v=>parseInt(v.trim()));
  if (p.length===3 && p.every(n=>!isNaN(n)))
    return '#' + p.map(n=>n.toString(16).padStart(2,'0')).join('');
  return '#000000';
}
function hexToRgb(hex) {
  const r=parseInt(hex.slice(1,3),16), g=parseInt(hex.slice(3,5),16), b=parseInt(hex.slice(5,7),16);
  return isNaN(r) ? '0,0,0' : `${r},${g},${b}`;
}

// ---- Color pickers: id prefix → CSS variable → hex input id ----
const COLOR_FIELDS = [
  // Web theme
  { pick:'s_accent',   hex:'s_accent_hex',   cssVar:'--amber',    save:'web_accent'    },
  { pick:'s_ink',      hex:'s_ink_hex',       cssVar:'--ink',      save:'web_ink'       },
  { pick:'s_nav_text', hex:'s_nav_text_hex',  cssVar:null,         save:'web_nav_text'  },
  { pick:'s_text',     hex:'s_text_hex',      cssVar:null,         save:'web_text_color'},
  { pick:'s_muted',    hex:'s_muted_hex',     cssVar:'--ink-soft', save:'web_muted'     },
  { pick:'s_link',     hex:'s_link_hex',      cssVar:null,         save:'web_link'      },
  { pick:'s_paper',    hex:'s_paper_hex',     cssVar:'--paper',    save:'web_paper'     },
  { pick:'s_card',     hex:'s_card_hex',      cssVar:'--card',     save:'web_card'      },
  { pick:'s_line',     hex:'s_line_hex',      cssVar:'--line',     save:'web_line'      },
  // Table headers
  { pick:'s_tbl_header',          hex:'s_tbl_header_hex',          cssVar:'--tbl-header',         save:'tbl_header'         },
  { pick:'s_tbl_header_txt',      hex:'s_tbl_header_txt_hex',      cssVar:'--tbl-header-txt',     save:'tbl_header_txt'     },
  { pick:'s_tbl_sub_header',      hex:'s_tbl_sub_header_hex',      cssVar:'--tbl-sub-header',     save:'tbl_sub_header'     },
  { pick:'s_tbl_sub_header_txt',  hex:'s_tbl_sub_header_txt_hex',  cssVar:'--tbl-sub-header-txt', save:'tbl_sub_header_txt' },
  { pick:'s_tbl_avg_header',      hex:'s_tbl_avg_header_hex',      cssVar:'--tbl-avg-header',     save:'tbl_avg_header'     },
  { pick:'s_tbl_avg_header_txt',  hex:'s_tbl_avg_header_txt_hex',  cssVar:'--tbl-avg-header-txt', save:'tbl_avg_header_txt' },
  // Table rows & data cells
  { pick:'s_tbl_row_odd',     hex:'s_tbl_row_odd_hex',     cssVar:'--tbl-row-odd',      save:'tbl_row_odd'     },
  { pick:'s_tbl_row_odd_txt', hex:'s_tbl_row_odd_txt_hex', cssVar:'--tbl-row-odd-txt',  save:'tbl_row_odd_txt' },
  { pick:'s_tbl_row_even',    hex:'s_tbl_row_even_hex',    cssVar:'--tbl-row-even',     save:'tbl_row_even'    },
  { pick:'s_tbl_row_even_txt',hex:'s_tbl_row_even_txt_hex',cssVar:'--tbl-row-even-txt', save:'tbl_row_even_txt'},
  { pick:'s_tbl_computed_bg', hex:'s_tbl_computed_bg_hex', cssVar:'--tbl-computed-bg',  save:'tbl_computed_bg' },
  { pick:'s_tbl_raw_bg',      hex:'s_tbl_raw_bg_hex',      cssVar:'--tbl-raw-bg',       save:'tbl_raw_bg'      },
  { pick:'s_tbl_raw_txt',     hex:'s_tbl_raw_txt_hex',     cssVar:'--tbl-raw-txt',      save:'tbl_raw_txt'     },
  { pick:'s_tbl_avg_bg',      hex:'s_tbl_avg_bg_hex',      cssVar:'--tbl-avg-bg',       save:'tbl_avg_bg'      },
  { pick:'s_tbl_avg_txt',     hex:'s_tbl_avg_txt_hex',     cssVar:'--tbl-avg-txt',      save:'tbl_avg_txt'     },
  { pick:'s_tbl_ws_bg',       hex:'s_tbl_ws_bg_hex',       cssVar:'--tbl-ws-bg',        save:'tbl_ws_bg'       },
  { pick:'s_tbl_ws_txt',      hex:'s_tbl_ws_txt_hex',      cssVar:'--tbl-ws-txt',       save:'tbl_ws_txt'      },
  // Student column & activity labels
  { pick:'s_tbl_name_bg',      hex:'s_tbl_name_bg_hex',      cssVar:'--tbl-name-bg',      save:'tbl_name_bg'      },
  { pick:'s_tbl_name_txt',     hex:'s_tbl_name_txt_hex',     cssVar:'--tbl-name-txt',     save:'tbl_name_txt'     },
  { pick:'s_tbl_name_even_bg', hex:'s_tbl_name_even_bg_hex', cssVar:'--tbl-name-even-bg', save:'tbl_name_even_bg' },
  { pick:'s_tbl_computed_bg',  hex:'s_tbl_computed_bg_hex',  cssVar:'--tbl-computed-bg',  save:'tbl_computed_bg'  },
  { pick:'s_tbl_wt_color',     hex:'s_tbl_wt_color_hex',     cssVar:'--tbl-wt-color',     save:'tbl_wt_color'     },
  // AI cell
  { pick:'s_tbl_ai_cell_bg',   hex:'s_tbl_ai_cell_bg_hex',   cssVar:'--tbl-ai-cell-bg',   save:'tbl_ai_cell_bg'   },
  { pick:'s_tbl_ai_cell_txt',  hex:'s_tbl_ai_cell_txt_hex',  cssVar:'--tbl-ai-cell-txt',  save:'tbl_ai_cell_txt'  },
  // Highlight cells
  { pick:'s_hl_equiv',     hex:'s_hl_equiv_hex',     cssVar:'--hl-equiv',     save:'hl_equiv'     },
  { pick:'s_hl_equiv_txt', hex:'s_hl_equiv_txt_hex', cssVar:'--hl-equiv-txt', save:'hl_equiv_txt' },
  { pick:'s_hl_grade',     hex:'s_hl_grade_hex',     cssVar:'--hl-grade',     save:'hl_grade'     },
  { pick:'s_hl_grade_txt', hex:'s_hl_grade_txt_hex', cssVar:'--hl-grade-txt', save:'hl_grade_txt' },
  { pick:'s_hl_final',     hex:'s_hl_final_hex',     cssVar:'--hl-final',     save:'hl_final'     },
  { pick:'s_hl_final_txt', hex:'s_hl_final_txt_hex', cssVar:'--hl-final-txt', save:'hl_final_txt' },
  { pick:'s_hl_term_col',     hex:'s_hl_term_col_hex',     cssVar:'--hl-term-col',     save:'hl_term_col'     },
  { pick:'s_hl_term_col_txt', hex:'s_hl_term_col_txt_hex', cssVar:'--hl-term-col-txt', save:'hl_term_col_txt' },
  // Failing grade
  { pick:'s_hl_fail_bg',  hex:'s_hl_fail_bg_hex',  cssVar:'--hl-fail-bg',  save:'hl_fail_bg'  },
  { pick:'s_hl_fail_txt', hex:'s_hl_fail_txt_hex', cssVar:'--hl-fail-txt', save:'hl_fail_txt' },
  // Remarks & AI
  { pick:'s_hl_remarks_pass',     hex:'s_hl_remarks_pass_hex',     cssVar:'--hl-remarks-pass',     save:'hl_remarks_pass'     },
  { pick:'s_hl_remarks_pass_txt', hex:'s_hl_remarks_pass_txt_hex', cssVar:'--hl-remarks-pass-txt', save:'hl_remarks_pass_txt' },
  { pick:'s_hl_remarks_fail',     hex:'s_hl_remarks_fail_hex',     cssVar:'--hl-remarks-fail',     save:'hl_remarks_fail'     },
  { pick:'s_hl_remarks_fail_txt', hex:'s_hl_remarks_fail_txt_hex', cssVar:'--hl-remarks-fail-txt', save:'hl_remarks_fail_txt' },
  { pick:'s_hl_remarks_inc',      hex:'s_hl_remarks_inc_hex',      cssVar:'--hl-remarks-inc',      save:'hl_remarks_inc'      },
  { pick:'s_hl_remarks_inc_txt',  hex:'s_hl_remarks_inc_txt_hex',  cssVar:'--hl-remarks-inc-txt',  save:'hl_remarks_inc_txt'  },
  { pick:'s_hl_ai_btn',     hex:'s_hl_ai_btn_hex',     cssVar:'--hl-ai-btn',     save:'hl_ai_btn'     },
  { pick:'s_hl_ai_btn_txt', hex:'s_hl_ai_btn_txt_hex', cssVar:'--hl-ai-btn-txt', save:'hl_ai_btn_txt' },
];

function syncColorField(pickId, hexId, cssVar, val) {
  const pickEl = document.getElementById(pickId);
  const hexEl  = document.getElementById(hexId);
  if (!pickEl || !hexEl) return;   // element not in DOM — skip silently
  const hex = /^#[0-9a-fA-F]{6}$/.test(val) ? val : rgbToHex(val);
  pickEl.value = hex;
  hexEl.value  = hex;
  if (cssVar) document.documentElement.style.setProperty(cssVar, hex);
  updateThemePreview();
  updateGradePreview();
}

COLOR_FIELDS.forEach(f => {
  const pickEl = document.getElementById(f.pick);
  const hexEl  = document.getElementById(f.hex);
  if (!pickEl || !hexEl) return;   // element not rendered — skip silently
  pickEl.addEventListener('input', e => {
    hexEl.value = e.target.value;
    if (f.cssVar) document.documentElement.style.setProperty(f.cssVar, e.target.value);
    updateThemePreview();
    updateGradePreview();
  });
  hexEl.addEventListener('change', e => {
    if (/^#[0-9a-fA-F]{6}$/.test(e.target.value)) {
      pickEl.value = e.target.value;
      if (f.cssVar) document.documentElement.style.setProperty(f.cssVar, e.target.value);
      updateThemePreview();
      updateGradePreview();
    }
  });
});

// ---- Live theme preview ----
function updateThemePreview() {
  const nav     = document.getElementById('s_ink_hex').value     || '#1d2433';
  const navTxt  = document.getElementById('s_nav_text_hex').value || '#f5f0e6';
  const accent  = document.getElementById('s_accent_hex').value  || '#c97b1f';
  const bodyTxt = document.getElementById('s_text_hex').value    || '#1d2433';
  const muted   = document.getElementById('s_muted_hex').value   || '#495066';
  const link    = document.getElementById('s_link_hex').value    || accent;
  const paper   = document.getElementById('s_paper_hex').value   || '#f5f0e6';
  const card    = document.getElementById('s_card_hex').value    || '#fffdf8';
  const line    = document.getElementById('s_line_hex').value    || '#d9cfba';

  const el = id => document.getElementById(id);
  el('tpNav').style.background  = nav;
  el('tpMark').style.background = accent;
  el('tpMark').style.color      = navTxt;
  el('tpSchool').style.color    = navTxt;
  el('tpNavLink').style.color   = navTxt;
  el('tpNavBtn').style.color    = navTxt;
  el('tpNavBtn').style.borderColor = navTxt + '55';
  el('tpBody').style.background = paper;
  el('tpBody').style.color      = bodyTxt;
  el('tpCard').style.background = card;
  el('tpCard').style.borderColor = line;
  el('tpHeading').style.color   = bodyTxt;
  el('tpMutedTxt').style.color  = muted;
  el('tpMutedLine').style.color = muted;
  el('tpBtn').style.background  = accent;
  el('tpBtn').style.color       = '#fff';
  el('tpLink').style.color      = link;
}
// ---- PDF color picker helpers ----
function rgbToHexPdf(rgb) {
  const p = String(rgb).split(',').map(v => parseInt(v.trim()));
  if (p.length===3 && p.every(n=>!isNaN(n)))
    return '#'+p.map(n=>Math.max(0,Math.min(255,n)).toString(16).padStart(2,'0')).join('');
  return '#000000';
}
function hexToRgbPdf(hex) {
  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return '0,0,0';
  return [1,3,5].map(i=>parseInt(hex.slice(i,i+2),16)).join(',');
}
function pdfPickerChanged(id) {
  const hex = document.getElementById(id+'_pick').value;
  document.getElementById(id).value = hexToRgbPdf(hex);
  updatePdfPreview();
}
function pdfRgbChanged(id) {
  const rgb = document.getElementById(id).value;
  const hex = rgbToHexPdf(rgb);
  document.getElementById(id+'_pick').value = hex;
  updatePdfPreview();
}

// All PDF color fields with their IDs and defaults
const PDF_COLOR_FIELDS = [
  ['p_hdr','29,36,51'],['p_hdr_txt','245,240,230'],['p_subtitle_txt','201,123,31'],['p_title_txt','245,240,230'],
  ['p_subj_txt','0,0,0'],['p_info_txt','60,60,80'],['p_acc','201,123,31'],
  ['p_th_bg','29,36,51'],['p_th_txt','245,240,230'],['p_th2_bg','45,65,45'],['p_th2_txt','245,240,230'],
  ['p_text','0,0,0'],['p_row_odd','255,255,255'],['p_row_even','248,246,240'],['p_border','200,193,180'],
  ['p_equiv','216,236,223'],['p_equiv_txt','30,100,60'],['p_ws','240,217,181'],['p_ws_txt','100,70,10'],
  ['p_grade','240,217,181'],['p_grade_txt','80,50,10'],
  ['p_pass','47,125,84'],['p_pass_txt','255,255,255'],['p_fail','178,59,59'],['p_fail_txt','255,255,255'],
  ['p_inc','220,220,220'],['p_inc_txt','80,80,80'],
  ['p_term_col','255,255,255'],['p_term_col_txt','0,0,0'],
  ['p_final_col','255,255,255'],['p_final_col_txt','0,0,0'],
  ['p_footer_txt','160,160,160'],['p_sig_txt','0,0,0'],['p_page_bg','255,255,255'],
  // AI Intervention Report
  ['ai_hdr_bg','29,36,51'],['ai_hdr_school_txt','0,0,0'],['ai_hdr_addr_txt','0,0,0'],
  ['ai_hdr_title_txt','0,0,0'],['ai_accent_rgb','201,123,31'],
  ['ai_section_bg','29,36,51'],['ai_section_txt','0,0,0'],
  ['ai_stats_bg','245,243,237'],['ai_stats_val_txt','0,0,0'],['ai_stats_lbl_txt','0,0,0'],
  ['ai_body_txt','0,0,0'],['ai_info_txt','0,0,0'],
  ['ai_footer_txt','120,120,120'],['ai_page_bg','255,255,255'],
];

function setPdfColor(id, rgb) {
  const el = document.getElementById(id); if (!el) return;
  el.value = rgb || '';
  const pick = document.getElementById(id+'_pick');
  if (pick) pick.value = rgbToHexPdf(rgb || '0,0,0');
}

function updatePdfPreview() {
  const g = id => (document.getElementById(id)?.value || '');
  const rgb = id => { const v=g(id); const p=v.split(',').map(n=>parseInt(n.trim())||0); return `rgb(${p[0]||0},${p[1]||0},${p[2]||0})`; };
  const el  = id => document.getElementById(id);

  if (!el('pmp_hdr')) return;
  // ── Helper that applies same header style to both panels ──
  function applyHdr(hdrId, schoolId, subId, titleId, accentId) {
    const h = el(hdrId); if (!h) return;
    h.style.background = rgb('p_hdr');
    const sc = el(schoolId); if(sc){sc.style.color=rgb('p_title_txt');}
    const su = el(subId);    if(su){su.style.color=rgb('p_subtitle_txt');}
    const ti = el(titleId);  if(ti){ti.style.color=rgb('p_title_txt');}
    const ac = el(accentId); if(ac){ac.style.background=rgb('p_acc');}
  }
  applyHdr('pmp_hdr',   'pmp_school',  'pmp_sub',   'pmp_rptTitle',   'pmp_accentLine');
  applyHdr('pmp_fhdr',  'pmp_fschool', 'pmp_fsub',  'pmp_frptTitle',  'pmp_faccentLine');

  // Class info rows
  const ci = el('pmp_classInfo'); if(ci) ci.style.background = rgb('p_page_bg');
  ['pmp_subjName','pmp_infoRow','pmp_fsubjName','pmp_finfoRow'].forEach(id=>{
    const e=el(id); if(e) e.style.color = rgb('p_text');
  });

  // ── Term table header ──
  ['pmp_thT1','pmp_thT2','pmp_thT3','pmp_thT4','pmp_thT5','pmp_thGrade'].forEach(id=>{
    const e=el(id); if(!e) return; e.style.background=rgb('p_th_bg'); e.style.color=rgb('p_th_txt');
  });

  // ── Final table header ──
  ['pmp_fth1','pmp_fth2','pmp_fth3','pmp_fth4','pmp_fth5'].forEach(id=>{
    const e=el(id); if(!e) return; e.style.background=rgb('p_th_bg'); e.style.color=rgb('p_th_txt');
  });

  // ── Term rows ──
  const termRows = [['pmp_termRow1','pmp_termGradePass','pass'],
                    ['pmp_termRow2','pmp_termGradeFail','fail'],
                    ['pmp_termRow3','pmp_termGradePass2','pass']];
  termRows.forEach(([rowId,gradeId,outcome],idx)=>{
    const row = el(rowId); if(!row) return;
    const bg = idx%2===0 ? rgb('p_row_odd') : rgb('p_row_even');
    row.style.background = bg;
    row.querySelectorAll('div').forEach(d=>{ d.style.color=rgb('p_text'); d.style.background=bg; });
    const gc = el(gradeId); if(!gc) return;
    if (outcome==='fail') {
      gc.style.background = rgb('p_fail'); gc.style.color = rgb('p_fail_txt');
    } else {
      gc.style.background = rgb('p_grade'); gc.style.color = rgb('p_grade_txt');
    }
  });

  // ── Final rows ──
  const finalRows = [
    { rowId:'pmp_row1', gradeId:'pmp_gradePass',  termIds:['pmp_fmid1','pmp_fend1'], failTerms:[], outcome:'pass', idx:0 },
    { rowId:'pmp_row2', gradeId:'pmp_gradeFail',  termIds:['pmp_fmid2'], failTerms:['pmp_fend2_fail'], outcome:'fail', idx:1 },
    { rowId:'pmp_row3', gradeId:'pmp_gradePass2', termIds:['pmp_fmid3','pmp_fend3'], failTerms:[], outcome:'pass', idx:2 },
  ];
  finalRows.forEach(({rowId,gradeId,termIds,failTerms,outcome,idx})=>{
    const row = el(rowId); if(!row) return;
    const bg = idx%2===0 ? rgb('p_row_odd') : rgb('p_row_even');
    row.style.background = bg;
    row.querySelectorAll('div').forEach(d=>{ d.style.color=rgb('p_text'); d.style.background=bg; });
    // Term grade columns — use p_term_col for passing, p_fail for failing
    termIds.forEach(id=>{ const e=el(id); if(e){e.style.background=rgb('p_term_col');e.style.color=rgb('p_term_col_txt');} });
    failTerms.forEach(id=>{ const e=el(id); if(e){e.style.background=rgb('p_fail');e.style.color=rgb('p_fail_txt');} });
    // Final grade column
    const gc = el(gradeId); if(!gc) return;
    if (outcome==='fail') {
      gc.style.background = rgb('p_fail'); gc.style.color = rgb('p_fail_txt');
    } else {
      gc.style.background = rgb('p_final_col'); gc.style.color = rgb('p_final_col_txt');
    }
  });

  // ── Footers ──
  ['pmp_footer','pmp_ffooter'].forEach(id=>{
    const e=el(id); if(e){e.style.color=rgb('p_footer_txt'); e.style.background=rgb('p_page_bg');}
  });

  // ── Background of both grade preview containers ──
  document.getElementById('pdfMiniPreview').style.background = rgb('p_page_bg');
  const fp = document.getElementById('pdfFinalPreview');
  if (fp) fp.style.background = rgb('p_page_bg');

  // ── AI Intervention Report preview ──
  const ap = el('pdfAiPreview'); if (!ap) return;
  ap.style.background = rgb('ai_page_bg');
  const aph = el('aip_hdr'); if(aph) aph.style.background = rgb('ai_hdr_bg');
  const aps = el('aip_school'); if(aps){aps.style.color=rgb('ai_hdr_school_txt');}
  const apa = el('aip_addr');   if(apa){apa.style.color=rgb('ai_hdr_addr_txt');}
  const apt = el('aip_title');  if(apt){apt.style.color=rgb('ai_hdr_title_txt');}
  const apac= el('aip_accent'); if(apac){apac.style.background=rgb('ai_accent_rgb');}
  const apbd= el('aip_body');   if(apbd){apbd.style.background=rgb('ai_page_bg');}
  const aprt= el('aip_rptTitle');if(aprt){aprt.style.color=rgb('ai_body_txt');}
  const apif= el('aip_info');    if(apif){apif.style.color=rgb('ai_info_txt');}
  ['aip_sv1','aip_sv2','aip_sv3','aip_sv4'].forEach(id=>{
    const e=el(id); if(e){e.style.background=rgb('ai_stats_bg');e.style.color=rgb('ai_stats_val_txt');}
  });
  ['aip_sl1','aip_sl2','aip_sl3','aip_sl4'].forEach(id=>{
    const e=el(id); if(e){e.style.color=rgb('ai_stats_lbl_txt');}
  });
  const apse= el('aip_section'); if(apse){apse.style.background=rgb('ai_section_bg');apse.style.color=rgb('ai_section_txt');}
  const apst= el('aip_stname'); if(apst){apst.style.color=rgb('ai_body_txt');}
  const apac2=el('aip_action'); if(apac2){apac2.style.color=rgb('ai_body_txt');}
  const apft= el('aip_footer'); if(apft){apft.style.color=rgb('ai_footer_txt');apft.style.background=rgb('ai_page_bg');}
}
// Init preview on page load
setTimeout(updatePdfPreview, 200);

// ---- Live Grade Table Preview ----
function cv(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
      || document.documentElement.style.getPropertyValue(name).trim();
}
function g(id) { return document.getElementById(id); }

function updateGradePreview() {
  // Read current CSS var values
  const hdr       = cv('--tbl-header')         || '#1d2433';
  const hdrTxt    = cv('--tbl-header-txt')      || '#f5f0e6';
  const sub       = cv('--tbl-sub-header')      || hdr;
  const subTxt    = cv('--tbl-sub-header-txt')  || hdrTxt;
  const avgHdr    = cv('--tbl-avg-header')      || '#c97b1f';
  const avgHdrTxt = cv('--tbl-avg-header-txt')  || '#fff';
  const odd       = cv('--tbl-row-odd')         || '#ffffff';
  const even      = cv('--tbl-row-even')        || '#f7f4ee';
  const oddTxt    = cv('--tbl-row-odd-txt')     || '#1d2433';
  const evenTxt   = cv('--tbl-row-even-txt')    || '#1d2433';
  const nameBg    = cv('--tbl-name-bg')         || cv('--paper-2') || '#efe8d8';
  const nameTxt   = cv('--tbl-name-txt')        || '#1d2433';
  const nameEven  = cv('--tbl-name-even-bg')    || cv('--tbl-row-even-computed') || '#ece4d2';
  const equiv     = cv('--hl-equiv')            || '#d8ecdf';
  const equivTxt  = cv('--hl-equiv-txt')        || '#155724';
  const grade     = cv('--hl-grade')            || cv('--amber-soft') || '#f0d9b5';
  const gradeTxt  = cv('--hl-grade-txt')        || '#1d2433';
  const failBg    = cv('--hl-fail-bg')          || '#f8d7da';
  const failTxt   = cv('--hl-fail-txt')         || '#721c24';
  const wtColor   = cv('--tbl-wt-color')        || cv('--amber-soft') || '#f0d9b5';
  const avgBg     = cv('--tbl-avg-bg')          || 'transparent';
  const wsBg      = cv('--tbl-ws-bg')           || 'transparent';
  const line      = cv('--line')                || '#d9cfba';

  // Apply to preview table cells
  const applyCell = (id, bg, color, border) => {
    const el = g(id); if (!el) return;
    if (bg)     el.style.background = bg;
    if (color)  el.style.color = color;
    if (border) el.style.borderColor = line;
  };

  // Headers
  [g('lpNameHdr')].forEach(el => { if(el){ el.style.background=hdr; el.style.color=hdrTxt; el.style.borderColor=line; }});
  g('lpCritHdr').style.background=hdr; g('lpCritHdr').style.color=hdrTxt; g('lpCritHdr').style.borderColor=line;
  g('lpGradeHdr').style.background=hdr; g('lpGradeHdr').style.color=hdrTxt; g('lpGradeHdr').style.borderColor=line;
  [g('lpActHdr1'),g('lpActHdr2')].forEach(el=>{if(el){el.style.background=sub;el.style.color=subTxt;el.style.borderColor=line;}});
  [g('lpAvgHdr'),g('lpWsHdr')].forEach(el=>{if(el){el.style.background=avgHdr;el.style.color=avgHdrTxt;el.style.borderColor=line;}});
  [g('lpWt1'),g('lpWt2')].forEach(el=>{if(el) el.style.color=wtColor;});

  // Odd rows
  [g('lpRaw1'),g('lpRaw3')].forEach(el=>{if(el){el.style.background=odd;el.style.color=oddTxt;el.style.borderColor=line;}});
  [g('lpAvg1'),g('lpAvg3')].forEach(el=>{if(el){el.style.background=avgBg;el.style.color=oddTxt;el.style.borderColor=line;}});
  [g('lpWs1'),g('lpWs3')].forEach(el=>{if(el){el.style.background=wsBg;el.style.color=oddTxt;el.style.borderColor=line;}});
  g('lpNameOdd').style.background=nameBg; g('lpNameOdd').style.color=nameTxt; g('lpNameOdd').style.borderColor=line;
  g('lpNameOdd2').style.background=nameBg; g('lpNameOdd2').style.color=nameTxt; g('lpNameOdd2').style.borderColor=line;

  // Even rows
  [g('lpRaw2')].forEach(el=>{if(el){el.style.background=even;el.style.color=evenTxt;el.style.borderColor=line;}});
  [g('lpAvg2')].forEach(el=>{if(el){el.style.background=avgBg;el.style.color=evenTxt;el.style.borderColor=line;}});
  [g('lpWs2')].forEach(el=>{if(el){el.style.background=wsBg;el.style.color=evenTxt;el.style.borderColor=line;}});
  g('lpNameEven').style.background=nameEven; g('lpNameEven').style.color=nameTxt; g('lpNameEven').style.borderColor=line;

  // Equiv cells
  [g('lpEquiv1'),g('lpEquiv2'),g('lpEquiv3')].forEach(el=>{if(el){el.style.background=equiv;el.style.color=equivTxt;el.style.borderColor=line;}});

  // Grade cells — pass vs fail
  [g('lpPassGrade'),g('lpPassGrade2')].forEach(el=>{if(el){el.style.background=grade;el.style.color=gradeTxt;el.style.borderColor=line;}});
  g('lpFailGrade').style.background=failBg; g('lpFailGrade').style.color=failTxt; g('lpFailGrade').style.borderColor=line;

  // Table border
  g('liveGradePreview').style.borderColor=line;
}
setTimeout(updateGradePreview, 300);

// ── Faculty / Chair Profile ─────────────────────────────────────────
async function loadProfile() {
  const card = document.getElementById('profileCard');
  if (!card) return;
  // 'colleges' action uses require_login() — accessible to teachers and chairs
  // 'colleges_full' uses require_admin() — only used in the admin manager
  const cr = await fetch('api/settings.php?action=colleges').then(r=>r.json());
  const colleges = cr.colleges || [];

  // Seed _allColleges for assignment row dropdowns.
  // For chairs we need the departments too — fetch each one lazily when a row is added.
  // For the manager (admin) _allColleges is re-seeded by loadCollegesManager().
  if (!_allColleges.length) {
    // Build minimal structure compatible with addAssignmentRow / addOwnAssignmentRow
    _allColleges = colleges.map(c => ({id: c.id, name: c.name, departments: []}));
  }

  const colSel = document.getElementById('prof_college');
  // Only populate if it's a real <select> (not the hidden input for chairs)
  if (colSel && colSel.tagName === 'SELECT') {
    colSel.innerHTML = '<option value="">— Select College —</option>' +
      colleges.map(c=>`<option value="${esc(c.name)}">${esc(c.name)}</option>`).join('');
  }

  // Now load saved profile
  const r = await fetch('api/data.php?action=my_profile').then(r=>r.json());
  if (!r.ok || !r.profile) return;
  const p = r.profile;
  document.getElementById('prof_name').value  = p.full_name || '';
  document.getElementById('prof_email').value = p.email     || '';

  if (colSel && colSel.tagName === 'SELECT' && p.college) {
    colSel.value = p.college;
    // Load departments then set saved dept
    const deptSel = document.getElementById('prof_dept');
    if (deptSel) {
      deptSel.dataset.current = p.department || '';
      await loadDeptDropdown('prof_college','prof_dept', p.college);
    }
  }
}

async function saveProfile() {
  const err = document.getElementById('prof_err');
  const ok  = document.getElementById('prof_ok');
  err.style.display = 'none'; ok.style.display = 'none';
  const name  = document.getElementById('prof_name').value.trim();
  const email = document.getElementById('prof_email').value.trim();
  const col   = document.getElementById('prof_college')?.value || '';
  const dept  = document.getElementById('prof_dept')?.value    || '';
  const curPw = document.getElementById('prof_cur_pw').value;
  const newPw = document.getElementById('prof_new_pw').value;
  const newPw2= document.getElementById('prof_new_pw2').value;
  if (!name || !email) { err.textContent='Name and email are required'; err.style.display=''; return; }
  if (newPw && newPw !== newPw2) { err.textContent='New passwords do not match'; err.style.display=''; return; }
  if (newPw && !curPw) { err.textContent='Enter your current password to change it'; err.style.display=''; return; }
  const action = <?= is_chair() ? "'edit_own_chair'" : "'update_profile'" ?>;
  const apiUrl = <?= is_chair() ? "'api/admin.php?action=edit_own_chair'" : "'api/data.php?action=update_profile'" ?>;
  const res = await fetch(apiUrl, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({full_name:name,email,college:col,department:dept,
      current_password:curPw,new_password:newPw})
  }).then(r=>r.json());
  if (res.ok) {
    ok.textContent = 'Profile saved successfully.'; ok.style.display='';
    document.getElementById('prof_cur_pw').value = '';
    document.getElementById('prof_new_pw').value = '';
    document.getElementById('prof_new_pw2').value= '';
  } else {
    err.textContent = res.error || 'Failed to save profile'; err.style.display='';
  }
}
loadProfile();

// ── Chair: read-only assignment table ────────────────────────────────
<?php if (is_chair()): ?>
async function loadOwnAssignments() {
  const wrap = document.getElementById('ownAssignmentRows');
  if (!wrap) return;
  const r = await fetch('api/admin.php?action=get_chair_assignments&chair_id=<?= current_teacher_id() ?>')
    .then(r => r.json());
  const asgns = r.assignments || [];
  if (!asgns.length) {
    wrap.innerHTML = '<p class="muted" style="font-size:.85rem;margin:0">No assignments yet. Contact your administrator.</p>';
    return;
  }
  wrap.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:.9rem">
      <thead>
        <tr style="border-bottom:2px solid var(--line)">
          <th style="text-align:left;padding:8px 12px;color:var(--ink-soft);font-weight:600;width:50%">College</th>
          <th style="text-align:left;padding:8px 12px;color:var(--ink-soft);font-weight:600;width:50%">Department / Program</th>
        </tr>
      </thead>
      <tbody>
        ${asgns.map((a,i)=>`
          <tr style="border-bottom:1px solid var(--line);background:${i%2?'var(--paper-2)':''}">
            <td style="padding:10px 12px;font-weight:500">${esc(a.college||'—')}</td>
            <td style="padding:10px 12px">${esc(a.department||'—')}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}
loadOwnAssignments();
<?php endif; ?>

// ── College / Department management (admin) ──────────────────────────
let _allColleges   = [];   // [{id, name, departments:[]}]
let _selCollegeId  = null; // currently selected in the manager
let _editCollegeId = null;
let _editDeptId    = null;

async function loadCollegesManager() {
  const el = document.getElementById('collegeList');
  if (!el) return;
  const r = await fetch('api/settings.php?action=colleges_full').then(r=>r.json());
  _allColleges = r.colleges || [];
  renderCollegeList();
  // Also populate profile dropdowns
  await loadCollegeDropdowns();
}

function renderCollegeList() {
  const el = document.getElementById('collegeList'); if(!el) return;
  if (!_allColleges.length) {
    el.innerHTML = '<div class="muted" style="padding:12px;font-size:.84rem;text-align:center">No colleges yet</div>';
    return;
  }
  el.innerHTML = _allColleges.map(c => `
    <div style="display:flex;align-items:center;gap:6px;padding:8px 10px;
                border-bottom:1px solid var(--line);cursor:pointer;
                background:${_selCollegeId===c.id?'var(--amber-soft)':''};"
         onclick="selectCollege(${c.id})">
      <span style="flex:1;font-size:.88rem;font-weight:${_selCollegeId===c.id?'700':'400'}">${esc(c.name)}</span>
      <span class="muted" style="font-size:.78rem">${c.departments.length} dept${c.departments.length!==1?'s':''}</span>
      <button class="btn btn-ghost btn-sm" style="padding:2px 7px;font-size:.75rem"
        onclick="event.stopPropagation();deleteCollege(${c.id},'${esc(c.name)}')">✕</button>
    </div>`).join('');
}

async function saveCollege() {
  const inp = document.getElementById('cd_new_college');
  const name = inp.value.trim(); if(!name) return;
  const r = await fetch('api/settings.php?action=college_save',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})
  }).then(r=>r.json());
  if(r.ok){inp.value=''; await loadCollegesManager(); toast('College added');}
  else toast(r.error||'Failed');
}
async function deleteCollege(id, name) {
  showConfirm({
    title: 'Delete College',
    message: `Delete college "${name}"?\nAll its departments will also be deleted.`,
    confirmText: 'Delete', danger: true,
    onConfirm: async () => {
  const r = await fetch('api/settings.php?action=college_delete',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})
  }).then(r=>r.json());
  if(r.ok){if(_selCollegeId===id){_selCollegeId=null;renderDeptList();}await loadCollegesManager();toast('Deleted');}
  else toast(r.error||'Failed');
    }
  });
}

function selectCollege(id) {
  _selCollegeId = id;
  renderCollegeList();
  renderDeptList();
  document.getElementById('deptAddRow').style.display = 'flex';
}

function renderDeptList() {
  const el = document.getElementById('deptList'); if(!el) return;
  const hdr = document.getElementById('deptHeader');
  const col = _allColleges.find(c=>c.id===_selCollegeId);
  if (!col) {
    hdr.textContent = 'Select a college to manage its departments';
    el.innerHTML = '<div class="muted" style="padding:14px;font-size:.84rem;text-align:center">← Click a college first</div>';
    return;
  }
  hdr.textContent = col.name + ' — Departments';
  if (!col.departments.length) {
    el.innerHTML = '<div class="muted" style="padding:12px;font-size:.84rem;text-align:center">No departments yet</div>';
    return;
  }
  el.innerHTML = col.departments.map(d => `
    <div style="display:flex;align-items:center;gap:6px;padding:8px 10px;border-bottom:1px solid var(--line)">
      <span style="flex:1;font-size:.88rem">${esc(d.name)}</span>
      <button class="btn btn-ghost btn-sm" style="padding:2px 7px;font-size:.75rem"
        onclick="deleteDept(${d.id},'${esc(d.name)}')">✕</button>
    </div>`).join('');
}

async function saveDept() {
  if(!_selCollegeId){toast('Select a college first');return;}
  const inp = document.getElementById('cd_new_dept');
  const name = inp.value.trim(); if(!name) return;
  const r = await fetch('api/settings.php?action=department_save',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({college_id:_selCollegeId,name})
  }).then(r=>r.json());
  if(r.ok){inp.value=''; await loadCollegesManager(); renderDeptList(); toast('Department added');}
  else toast(r.error||'Failed');
}
async function deleteDept(id, name) {
  showConfirm({
    title: 'Delete Department',
    message: `Delete department "${name}"?`,
    confirmText: 'Delete', danger: true,
    onConfirm: async () => {
  const r = await fetch('api/settings.php?action=department_delete',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})
  }).then(r=>r.json());
  if(r.ok){await loadCollegesManager(); renderDeptList(); toast('Deleted');}
  else toast(r.error||'Failed');
    }
  });
}

// ── College/Dept dropdowns for profile and chair form ────────────────
async function loadCollegeDropdowns() {
  const r = await fetch('api/settings.php?action=colleges').then(r=>r.json());
  _allColleges = _allColleges.length ? _allColleges : r.colleges || [];
  // Update all college selects on the page
  document.querySelectorAll('select[id^="prof_college"], select[id^="asgn_col_"]').forEach(sel => {
    const cur = sel.value;
    sel.innerHTML = '<option value="">— Select College —</option>' +
      (r.colleges||[]).map(c=>`<option value="${esc(c.name)}">${esc(c.name)}</option>`).join('');
    if (cur) sel.value = cur;
  });
}

async function loadDeptDropdown(colSelectId, deptSelectId, collegeName) {
  const deptSel = document.getElementById(deptSelectId); if(!deptSel) return;
  deptSel.innerHTML = '<option value="">Loading…</option>';
  if (!collegeName) { deptSel.innerHTML = '<option value="">— Select Department —</option>'; return; }
  // Find college id from name
  const col = _allColleges.find(c=>c.name===collegeName);
  const cid = col?.id;
  if (!cid) { deptSel.innerHTML = '<option value="">— Select Department —</option>'; return; }
  const r = await fetch(`api/settings.php?action=departments&college_id=${cid}`).then(r=>r.json());
  const cur = deptSel.dataset.current || '';
  deptSel.innerHTML = '<option value="">— Select Department —</option>' +
    (r.departments||[]).map(d=>`<option value="${esc(d.name)}">${esc(d.name)}</option>`).join('');
  if (cur) deptSel.value = cur;
  delete deptSel.dataset.current;
}

// ── Multi-assignment rows for Chair form ─────────────────────────────
let _assignmentRowCount = 0;

function addAssignmentRow(college='', dept='') {
  const n = ++_assignmentRowCount;
  const wrap = document.getElementById('cfAssignmentRows'); if(!wrap) return;
  const div = document.createElement('div');
  div.id = `asgn_row_${n}`;
  div.style.cssText = 'display:flex;gap:10px;align-items:flex-end;margin-bottom:10px';
  div.innerHTML = `
    <div class="field" style="flex:2;min-width:160px;margin-bottom:0">
      <label style="font-size:.82rem">College</label>
      <select id="asgn_col_${n}" onchange="loadAsgnDept(${n},this.value)">
        <option value="">— Select College —</option>
        ${_allColleges.map(c=>`<option value="${esc(c.name)}"${c.name===college?'selected':''}>${esc(c.name)}</option>`).join('')}
      </select>
    </div>
    <div class="field" style="flex:2;min-width:160px;margin-bottom:0">
      <label style="font-size:.82rem">Department / Program</label>
      <select id="asgn_dept_${n}">
        <option value="">— Select Department —</option>
      </select>
    </div>
    <button type="button" class="btn btn-ghost btn-sm"
      style="flex-shrink:0;padding:7px 10px;color:var(--red);align-self:flex-end;margin-bottom:0"
      title="Remove this assignment"
      onclick="removeAssignmentRow(${n})">&#10005;</button>`;
  wrap.appendChild(div);
  // Load departments for pre-filled college
  const sel = document.getElementById(`asgn_dept_${n}`);
  if (dept) sel.dataset.current = dept;
  if (college) loadAsgnDept(n, college);
}

async function loadAsgnDept(n, collegeName) {
  const sel = document.getElementById(`asgn_dept_${n}`); if(!sel) return;
  const cur = sel.dataset.current || sel.value || '';
  sel.innerHTML = '<option value="">Loading…</option>';
  if (!collegeName) { sel.innerHTML = '<option value="">— Select Department —</option>'; return; }
  const col = _allColleges.find(c=>c.name===collegeName);
  if (!col) { sel.innerHTML = '<option value="">— Select Department —</option>'; return; }
  const r = await fetch(`api/settings.php?action=departments&college_id=${col.id}`).then(r=>r.json());
  sel.innerHTML = '<option value="">— Select Department —</option>' +
    (r.departments||[]).map(d=>`<option value="${esc(d.name)}">${esc(d.name)}</option>`).join('');
  if (cur) { sel.value = cur; delete sel.dataset.current; }
}

function removeAssignmentRow(n) {
  const el = document.getElementById(`asgn_row_${n}`);
  if (el) el.remove();
}

function getAssignments() {
  const rows = document.querySelectorAll('[id^="asgn_row_"]');
  return [...rows].map(row => {
    const n    = row.id.replace('asgn_row_','');
    const col  = document.getElementById(`asgn_col_${n}`)?.value  || '';
    const dept = document.getElementById(`asgn_dept_${n}`)?.value || '';
    return {college: col, department: dept};
  }).filter(a => a.college || a.department);
}

// ── Admin self-edit (own account only) ──────────────────────────────
async function loadAdminSelf() {
  if (!document.getElementById('sa_name')) return;  // not shown for non-admins
  const r = await fetch('api/data.php?action=my_profile').then(r=>r.json());
  if (r.profile) {
    document.getElementById('sa_name').value  = r.profile.full_name || '';
    document.getElementById('sa_email').value = r.profile.email     || '';
  }
}
async function saveAdminSelf() {
  const err = document.getElementById('sa_err');
  const ok  = document.getElementById('sa_ok');
  err.style.display='none'; ok.style.display='none';
  const name  = document.getElementById('sa_name').value.trim();
  const email = document.getElementById('sa_email').value.trim();
  const curPw = document.getElementById('sa_cur_pw').value;
  const newPw = document.getElementById('sa_new_pw').value;
  const newPw2= document.getElementById('sa_new_pw2').value;
  if (!name||!email){err.textContent='Name and email are required';err.style.display='';return;}
  if (newPw&&newPw!==newPw2){err.textContent='New passwords do not match';err.style.display='';return;}
  if (newPw&&!curPw){err.textContent='Enter current password to change it';err.style.display='';return;}
  const r = await fetch('api/admin.php?action=edit_own_admin',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({full_name:name, email, current_password:curPw, new_password:newPw})
  }).then(r=>r.json());
  if (r.ok) {
    ok.textContent='Account updated successfully.'; ok.style.display='';
    document.getElementById('sa_cur_pw').value='';
    document.getElementById('sa_new_pw').value='';
    document.getElementById('sa_new_pw2').value='';
  } else { err.textContent=r.error||'Failed to update account'; err.style.display=''; }
}
<?php if (is_admin()): ?>
loadAdminSelf();
loadCollegesManager();
<?php endif; ?>

// ── Program Chair management ─────────────────────────────────────────
let _editChairId = null;

async function loadChairs() {
  const el = document.getElementById('chairList');
  if (!el) return;  // not shown for non-admins
  const r = await fetch('api/admin.php?action=chairs').then(r=>r.json());
  if (!r.chairs||!r.chairs.length) {
    el.innerHTML='<div class="muted" style="font-size:.85rem">No program chairs yet. Click + Add Chair to create one.</div>';
    return;
  }
  el.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:.88rem;margin-top:4px">
      <thead>
        <tr style="border-bottom:2px solid var(--line)">
          <th style="text-align:left;padding:7px 10px;color:var(--ink-soft);font-weight:600">Name</th>
          <th style="text-align:left;padding:7px 10px;color:var(--ink-soft);font-weight:600">Email</th>
          <th style="text-align:left;padding:7px 10px;color:var(--ink-soft);font-weight:600">Assignments (College — Department)</th>
          <th style="padding:7px 10px"></th>
        </tr>
      </thead>
      <tbody>
        ${r.chairs.map((c,i)=>`
          <tr style="border-bottom:1px solid var(--line);background:${i%2?'var(--paper-2)':''}">
            <td style="padding:8px 10px;font-weight:600">${esc(c.full_name)}</td>
            <td style="padding:8px 10px;color:var(--ink-soft)">${esc(c.email)}</td>
            <td style="padding:8px 10px">${(c.assignments||[{college:c.college||'',department:c.department||''}])
              .map(a=>`<div style="font-size:.82rem">${esc(a.college||'—')} &mdash; ${esc(a.department||'—')}</div>`)
              .join('')}</td>
            <td style="padding:8px 10px;white-space:nowrap;text-align:right">
              <button class="btn btn-ghost btn-sm" onclick='openEditChair(${JSON.stringify(c)})'>Edit</button>
              <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="deleteChair(${c.id},'${esc(c.full_name)}')">Delete</button>
            </td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

function openChairForm() {
  _editChairId = null;
  _assignmentRowCount = 0;
  document.getElementById('chairFormTitle').textContent = 'Add Program Chair';
  ['cf_name','cf_email','cf_pw'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('cf_id').value = '';
  document.getElementById('cf_pw_label').textContent = 'Password *';
  document.getElementById('cf_pw').placeholder = 'Min 6 characters';
  document.getElementById('cf_pw_hint').style.display = 'none';
  document.getElementById('cf_err').style.display = 'none';
  document.getElementById('cfAssignmentRows').innerHTML = '';
  addAssignmentRow();   // start with one blank row
  document.getElementById('chairFormWrap').style.display = '';
  document.getElementById('cf_name').focus();
}
function openEditChair(c) {
  _editChairId = c.id;
  _assignmentRowCount = 0;
  document.getElementById('chairFormTitle').textContent = 'Edit Program Chair';
  document.getElementById('cf_id').value    = c.id;
  document.getElementById('cf_name').value  = c.full_name || '';
  document.getElementById('cf_email').value = c.email     || '';
  document.getElementById('cf_pw').value    = '';
  document.getElementById('cf_pw_label').textContent = 'New Password';
  document.getElementById('cf_pw').placeholder = 'Leave blank to keep current';
  document.getElementById('cf_pw_hint').style.display = '';
  document.getElementById('cf_err').style.display = 'none';
  // Populate assignment rows
  document.getElementById('cfAssignmentRows').innerHTML = '';
  const assignments = c.assignments && c.assignments.length
    ? c.assignments
    : [{college: c.college||'', department: c.department||''}];
  assignments.forEach(a => addAssignmentRow(a.college||'', a.department||''));
  if (!assignments.length) addAssignmentRow();
  document.getElementById('chairFormWrap').style.display = '';
  document.getElementById('cf_name').focus();
  document.getElementById('chairFormWrap').scrollIntoView({behavior:'smooth',block:'center'});
}
function closeChairForm() {
  document.getElementById('chairFormWrap').style.display = 'none';
  _editChairId = null;
}
async function saveChair() {
  const err = document.getElementById('cf_err'); err.style.display='none';
  const isEdit = !!_editChairId;
  const assignments = getAssignments();
  const body = {
    id:          document.getElementById('cf_id').value,
    full_name:   document.getElementById('cf_name').value.trim(),
    email:       document.getElementById('cf_email').value.trim(),
    assignments: assignments,
    password:    document.getElementById('cf_pw').value,
  };
  if (!body.full_name||!body.email){err.textContent='Name and email are required';err.style.display='';return;}
  if (!isEdit&&!body.password){err.textContent='Password is required for new chair';err.style.display='';return;}
  if (!assignments.length){err.textContent='Add at least one College & Department assignment';err.style.display='';return;}
  const action = isEdit ? 'edit_chair' : 'create_chair';
  const r = await fetch(`api/admin.php?action=${action}`,{
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
  }).then(r=>r.json());
  if (r.ok) { closeChairForm(); toast(isEdit?'Chair updated':'Chair created'); loadChairs(); }
  else { err.textContent=r.error||'Failed'; err.style.display=''; }
}
async function deleteChair(id, name) {
  showConfirm({
    title: 'Delete Program Chair',
    message: `Delete Program Chair account "${name}"?\nThis cannot be undone.`,
    confirmText: 'Delete', danger: true,
    onConfirm: async () => {
  const r = await fetch('api/admin.php?action=delete_chair',{
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id})
  }).then(r=>r.json());
  if (r.ok) { toast('Chair deleted'); loadChairs(); }
  else toast(r.error||'Failed');
    }
  });
}
loadChairs();

// ── Load settings ────────────────────────────────────────────────────
async function loadSettings() {
  const r = await fetch('api/settings.php?action=load').then(r=>r.json());
  const s = r.settings || {};
  if (document.getElementById('s_name'))     document.getElementById('s_name').value     = s.school_name     || '';
  if (document.getElementById('s_addr'))     document.getElementById('s_addr').value     = s.school_address  || '';
  if (document.getElementById('s_subtitle')) document.getElementById('s_subtitle').value = s.system_subtitle || 'GradeFlow Grading System';
  syncColorField('s_accent',   's_accent_hex',   '--amber',    s.web_accent     || '#c97b1f');
  syncColorField('s_ink',      's_ink_hex',       '--ink',      s.web_ink        || '#1d2433');
  syncColorField('s_nav_text', 's_nav_text_hex',  null,         s.web_nav_text   || '#f5f0e6');
  syncColorField('s_text',     's_text_hex',       null,         s.web_text_color || '#1d2433');
  syncColorField('s_muted',    's_muted_hex',      '--ink-soft', s.web_muted      || '#495066');
  syncColorField('s_link',     's_link_hex',       null,         s.web_link       || s.web_accent || '#c97b1f');
  syncColorField('s_paper',    's_paper_hex',      '--paper',    s.web_paper      || '#f5f0e6');
  syncColorField('s_card',     's_card_hex',       '--card',     s.web_card       || '#fffdf8');
  // Load all table color fields from saved settings using COLOR_FIELDS map
  COLOR_FIELDS.forEach(f => {
    if (!document.getElementById(f.pick)) return;  // field not in DOM
    const val = s[f.save] || '';
    if (val) syncColorField(f.pick, f.hex, f.cssVar, val);
  });
  // Explicit defaults for fields that need them
  syncColorField('s_hl_fail_bg',  's_hl_fail_bg_hex',  '--hl-fail-bg',  s.hl_fail_bg  ||'#f8d7da');
  syncColorField('s_hl_fail_txt', 's_hl_fail_txt_hex', '--hl-fail-txt', s.hl_fail_txt ||'#721c24');
  syncColorField('s_hl_equiv',    's_hl_equiv_hex',    '--hl-equiv',    s.hl_equiv    ||'#d8ecdf');
  syncColorField('s_hl_remarks_pass','s_hl_remarks_pass_hex','--hl-remarks-pass', s.hl_remarks_pass||'#d4edda');
  syncColorField('s_hl_remarks_fail','s_hl_remarks_fail_hex','--hl-remarks-fail', s.hl_remarks_fail||'#f8d7da');
  syncColorField('s_hl_remarks_inc', 's_hl_remarks_inc_hex', '--hl-remarks-inc',  s.hl_remarks_inc ||'#fff3cd');
  // PDF color fields
  PDF_COLOR_FIELDS.forEach(([id, def]) => setPdfColor(id, s['pdf_'+id.slice(2)] || def));
  // Backward-compat: pdf_header_bg → p_hdr, pdf_accent_rgb → p_acc
  if (s.pdf_header_bg)  setPdfColor('p_hdr',  s.pdf_header_bg);
  if (s.pdf_accent_rgb) setPdfColor('p_acc',  s.pdf_accent_rgb);
  if (s.pdf_pass_rgb)   setPdfColor('p_pass', s.pdf_pass_rgb);
  if (s.pdf_fail_rgb)   setPdfColor('p_fail', s.pdf_fail_rgb);
  if (s.pdf_text_rgb)   setPdfColor('p_text', s.pdf_text_rgb);
  // Extended per-area colors
  if (s.pdf_hdr_txt)     setPdfColor('p_hdr_txt',     s.pdf_hdr_txt);
  if (s.pdf_subtitle_txt)setPdfColor('p_subtitle_txt', s.pdf_subtitle_txt);
  if (s.pdf_title_txt)   setPdfColor('p_title_txt',   s.pdf_title_txt);
  if (s.pdf_subj_txt)    setPdfColor('p_subj_txt',    s.pdf_subj_txt);
  if (s.pdf_info_txt)    setPdfColor('p_info_txt',    s.pdf_info_txt);
  if (s.pdf_th_bg)       setPdfColor('p_th_bg',       s.pdf_th_bg);
  if (s.pdf_th_txt)      setPdfColor('p_th_txt',      s.pdf_th_txt);
  if (s.pdf_th2_bg)      setPdfColor('p_th2_bg',      s.pdf_th2_bg);
  if (s.pdf_th2_txt)     setPdfColor('p_th2_txt',     s.pdf_th2_txt);
  if (s.pdf_row_odd)     setPdfColor('p_row_odd',     s.pdf_row_odd);
  if (s.pdf_row_even)    setPdfColor('p_row_even',    s.pdf_row_even);
  if (s.pdf_border)      setPdfColor('p_border',      s.pdf_border);
  if (s.pdf_equiv)       setPdfColor('p_equiv',       s.pdf_equiv);
  if (s.pdf_equiv_txt)   setPdfColor('p_equiv_txt',   s.pdf_equiv_txt);
  if (s.pdf_ws)          setPdfColor('p_ws',          s.pdf_ws);
  if (s.pdf_ws_txt)      setPdfColor('p_ws_txt',      s.pdf_ws_txt);
  if (s.pdf_pass_txt)    setPdfColor('p_pass_txt',    s.pdf_pass_txt);
  if (s.pdf_fail_txt)    setPdfColor('p_fail_txt',    s.pdf_fail_txt);
  if (s.pdf_footer_txt)  setPdfColor('p_footer_txt',  s.pdf_footer_txt);
  if (s.pdf_sig_txt)     setPdfColor('p_sig_txt',     s.pdf_sig_txt);
  if (s.pdf_page_bg)     setPdfColor('p_page_bg',     s.pdf_page_bg);
  updatePdfPreview();
  // Paper sizes and font size
  document.getElementById('p_term_paper').value  = s.pdf_term_paper  || 'folio-L';
  document.getElementById('p_final_paper').value = s.pdf_final_paper || 'folio-P';
  const fs = s.pdf_font_size || '8';
  document.getElementById('p_fsize_range').value = fs;
  document.getElementById('p_fsize_num').value   = fs;
  // Font selections
  const wf = WEB_FONTS.find(f => f.key === (s.web_font || 'Outfit')) || WEB_FONTS[0];
  selectWebFont(wf.key, wf.css, wf.google || '');
  selectPdfTitleFont(s.pdf_title_font || 'Times');
  selectPdfBodyFont(s.pdf_body_font   || 'Helvetica');
  // System logo (nav bar)
  if (s.logo_path) {
    const lp = document.getElementById('logoPreview');
    if (lp) lp.innerHTML =
      `<img src="${esc(s.logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
  }
  // Report logo (PDF reports)
  if (s.report_logo_path) {
    const rl = document.getElementById('reportLogoPreview');
    if (rl) rl.innerHTML =
      `<img src="${esc(s.report_logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
  }
  // Teacher personal logo & schedule
  if (document.getElementById('teacherLogoPreview')) {
    if (s.pdf_teacher_logo_path) {
      document.getElementById('teacherLogoPreview').innerHTML =
        `<img src="${esc(s.pdf_teacher_logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
    }
  }
  if (typeof loadHdrFonts === 'function') loadHdrFonts(s);
  <?php if (is_admin()): ?>loadAdmins();<?php endif; ?>
}

// ---- Save settings ----
async function saveSettings() {
  const payload = {
    // Global fields — only present in DOM for admin accounts
    school_name:     document.getElementById('s_name')     ? document.getElementById('s_name').value     : undefined,
    school_address:  document.getElementById('s_addr')     ? document.getElementById('s_addr').value     : undefined,
    system_subtitle: document.getElementById('s_subtitle') ? document.getElementById('s_subtitle').value : undefined,
    // Personal web colors
    web_accent:     document.getElementById('s_accent_hex').value || document.getElementById('s_accent').value,
    web_ink:        document.getElementById('s_ink_hex').value    || document.getElementById('s_ink').value,
    web_nav_text:   document.getElementById('s_nav_text_hex').value || document.getElementById('s_nav_text').value,
    web_text_color: document.getElementById('s_text_hex').value   || document.getElementById('s_text').value,
    web_muted:      document.getElementById('s_muted_hex').value  || document.getElementById('s_muted').value,
    web_link:       document.getElementById('s_link_hex').value   || document.getElementById('s_link').value,
    web_paper:      document.getElementById('s_paper_hex').value  || document.getElementById('s_paper').value,
    web_card:       document.getElementById('s_card_hex').value   || document.getElementById('s_card').value,
    web_line:       document.getElementById('s_line_hex').value   || document.getElementById('s_line').value,
    // Collect all table color fields from COLOR_FIELDS
    ...Object.fromEntries(COLOR_FIELDS.filter(f => f.save && !f.save.startsWith('web_')).map(f => {
      const hexEl = document.getElementById(f.hex);
      const pickEl = document.getElementById(f.pick);
      return [f.save, (hexEl && hexEl.value) || (pickEl && pickEl.value) || ''];
    })),
    web_font:       curWebFont,
    pdf_header_bg:  document.getElementById('p_hdr').value,
    pdf_accent_rgb: document.getElementById('p_acc').value,
    pdf_pass_rgb:   document.getElementById('p_pass').value,
    pdf_fail_rgb:   document.getElementById('p_fail').value,
    pdf_text_rgb:   document.getElementById('p_text').value,
    // Per-area extended colors
    pdf_hdr_txt:     document.getElementById('p_hdr_txt').value,
    pdf_subtitle_txt:document.getElementById('p_subtitle_txt').value,
    pdf_title_txt:   document.getElementById('p_title_txt').value,
    pdf_subj_txt:    document.getElementById('p_subj_txt').value,
    pdf_info_txt:    document.getElementById('p_info_txt').value,
    pdf_th_bg:       document.getElementById('p_th_bg').value,
    pdf_th_txt:      document.getElementById('p_th_txt').value,
    pdf_th2_bg:      document.getElementById('p_th2_bg').value,
    pdf_th2_txt:     document.getElementById('p_th2_txt').value,
    pdf_row_odd:     document.getElementById('p_row_odd').value,
    pdf_row_even:    document.getElementById('p_row_even').value,
    pdf_border:      document.getElementById('p_border').value,
    pdf_equiv:       document.getElementById('p_equiv').value,
    pdf_equiv_txt:   document.getElementById('p_equiv_txt').value,
    pdf_ws:          document.getElementById('p_ws').value,
    pdf_ws_txt:      document.getElementById('p_ws_txt').value,
    pdf_grade:         document.getElementById('p_grade').value,
    pdf_grade_txt:     document.getElementById('p_grade_txt').value,
    pdf_inc:           document.getElementById('p_inc').value,
    pdf_inc_txt:       document.getElementById('p_inc_txt').value,
    pdf_term_col:      document.getElementById('p_term_col').value,
    pdf_term_col_txt:  document.getElementById('p_term_col_txt').value,
    pdf_final_col:     document.getElementById('p_final_col').value,
    pdf_final_col_txt: document.getElementById('p_final_col_txt').value,
    pdf_pass_txt:    document.getElementById('p_pass_txt').value,
    pdf_fail_txt:    document.getElementById('p_fail_txt').value,
    pdf_footer_txt:  document.getElementById('p_footer_txt').value,
    pdf_sig_txt:     document.getElementById('p_sig_txt').value,
    pdf_page_bg:     document.getElementById('p_page_bg').value,
    // AI Intervention Report
    ai_hdr_bg:           document.getElementById('ai_hdr_bg').value,
    ai_hdr_school_txt:   document.getElementById('ai_hdr_school_txt').value,
    ai_hdr_addr_txt:     document.getElementById('ai_hdr_addr_txt').value,
    ai_hdr_title_txt:    document.getElementById('ai_hdr_title_txt').value,
    ai_accent_rgb:       document.getElementById('ai_accent_rgb').value,
    ai_section_bg:       document.getElementById('ai_section_bg').value,
    ai_section_txt:      document.getElementById('ai_section_txt').value,
    ai_stats_bg:         document.getElementById('ai_stats_bg').value,
    ai_stats_val_txt:    document.getElementById('ai_stats_val_txt').value,
    ai_stats_lbl_txt:    document.getElementById('ai_stats_lbl_txt').value,
    ai_body_txt:         document.getElementById('ai_body_txt').value,
    ai_info_txt:         document.getElementById('ai_info_txt').value,
    ai_footer_txt:       document.getElementById('ai_footer_txt').value,
    ai_page_bg:          document.getElementById('ai_page_bg').value,
    pdf_title_font: curPdfTitle,
    pdf_body_font:  curPdfBody,
    pdf_term_paper:  document.getElementById('p_term_paper').value,
    pdf_final_paper: document.getElementById('p_final_paper').value,
    pdf_font_size:  document.getElementById('p_fsize_num').value,
    ...(typeof collectHdrFonts === 'function' ? collectHdrFonts() : {}),
  };
  const r = await fetch('api/settings.php?action=save',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)
  }).then(r=>r.json());
  if (r.ok) {
    toast('Saved \u2014 school info updated for everyone; colours and fonts updated for your account only.');
  } else {
    toast(r.error || 'Error saving settings');
  }
}

async function resetPersonal() {
  showConfirm({
    title: 'Reset Personal Settings',
    message: 'Reset all your personal colour and font settings back to defaults?\n\nThis only affects your account — school name and logo are not changed.',
    confirmText: 'Reset to Defaults', danger: true,
    onConfirm: async () => {
  const r = await fetch('api/settings.php?action=reset_personal',{method:'POST'}).then(r=>r.json());
  toast(r.message || 'Personal settings reset');
  await loadSettings();
    }
  });
}

// ---- Logo ----
async function uploadLogo() {
  const file = document.getElementById('logoInput').files[0];
  if (!file) { toast('Please select an image file first'); return; }
  const fd = new FormData(); fd.append('logo', file);
  const r = await fetch('api/settings.php?action=upload_logo',{method:'POST',body:fd}).then(r=>r.json());
  if (r.ok) {
    document.getElementById('logoPreview').innerHTML =
      `<img src="${esc(r.logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
    document.getElementById('logoMsg').innerHTML = '<span style="color:var(--green)">&#10003; Logo uploaded</span>';
    toast('Logo uploaded');
  } else { toast(r.error||'Upload failed'); }
}
async function deleteLogo() {
  showConfirm({
    title: 'Remove Logo',
    message: 'Remove the school logo?\nThe default icon will be shown instead.',
    confirmText: 'Remove Logo', danger: true,
    onConfirm: async () => {
  await fetch('api/settings.php?action=delete_logo',{method:'POST'});
  document.getElementById('logoPreview').innerHTML =
    '<span class="muted" style="font-size:.8rem;text-align:center;padding:8px">No logo yet</span>';
  document.getElementById('logoMsg').innerHTML = '';
  toast('Logo removed');
    }
  });
}

// ---- Teacher personal logo ----
async function uploadTeacherLogo() {
  const file = document.getElementById('teacherLogoInput').files[0];
  if (!file) { toast('Please select an image file first'); return; }
  const fd = new FormData(); fd.append('logo', file);
  const r = await fetch('api/settings.php?action=upload_teacher_logo',{method:'POST',body:fd}).then(r=>r.json());
  if (r.ok) {
    document.getElementById('teacherLogoPreview').innerHTML =
      `<img src="${esc(r.logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
    document.getElementById('teacherLogoMsg').innerHTML = '<span style="color:var(--green)">&#10003; Logo uploaded</span>';
    toast('Custom logo uploaded');
  } else { toast(r.error||'Upload failed'); }
}
async function deleteTeacherLogo() {
  showConfirm({
    title: 'Remove Custom Logo',
    message: 'Remove your custom report logo?\nYour PDFs will use the school logo instead.',
    confirmText: 'Remove Logo', danger: true,
    onConfirm: async () => {
  await fetch('api/settings.php?action=delete_teacher_logo',{method:'POST'});
  document.getElementById('teacherLogoPreview').innerHTML =
    '<span class="muted" style="font-size:.8rem;text-align:center;padding:8px">No custom logo</span>';
  document.getElementById('teacherLogoMsg').innerHTML = '';
  toast('Custom logo removed');
    }
  });
}

// ── Header font style toggle buttons ──────────────────────────────
function toggleHdrStyle(hiddenId, btn) {
  const el = document.getElementById(hiddenId);
  if (!el) return;
  const s = btn.dataset.s;
  let cur = el.value;
  if (cur.includes(s)) {
    cur = cur.replace(s, '');
    btn.classList.remove('active');
  } else {
    cur = cur + s;
    btn.classList.add('active');
  }
  el.value = cur;
}

// Apply a saved style string to a group (marks buttons active/inactive)
function applyHdrStyle(baseId, styleStr) {
  const el = document.getElementById(baseId + '_style');
  if (!el) return;
  el.value = styleStr || '';
  const grp = document.getElementById(baseId + '_style_grp');
  if (!grp) return;
  grp.querySelectorAll('.hdr-style-btn').forEach(btn => {
    btn.classList.toggle('active', (styleStr || '').includes(btn.dataset.s));
  });
}

const HDR_FONT_IDS = ['hdr_sem','hdr_lbl','hdr_crs','hdr_sec','hdr_sch'];
const HDR_SAVE_KEYS = {
  hdr_sem: ['pdf_hdr_sem_font','pdf_hdr_sem_size','pdf_hdr_sem_style'],
  hdr_lbl: ['pdf_hdr_lbl_font','pdf_hdr_lbl_size','pdf_hdr_lbl_style'],
  hdr_crs: ['pdf_hdr_crs_font','pdf_hdr_crs_size','pdf_hdr_crs_style'],
  hdr_sec: ['pdf_hdr_sec_font','pdf_hdr_sec_size','pdf_hdr_sec_style'],
  hdr_sch: ['pdf_hdr_sch_font','pdf_hdr_sch_size','pdf_hdr_sch_style'],
};

function loadHdrFonts(s) {
  // Apply saved (or default) font settings to each row
  const defs = {
    hdr_sem: ['Helvetica','9',''],
    hdr_lbl: ['Times','11','B'],
    hdr_crs: ['Helvetica','9.5',''],
    hdr_sec: ['Helvetica','9.5','B'],
    hdr_sch: ['Helvetica','9','B'],
  };
  HDR_FONT_IDS.forEach(id => {
    const [fk, sk, stk] = HDR_SAVE_KEYS[id];
    const [df, ds, dst] = defs[id];
    const fontEl = document.getElementById(id + '_font');
    const sizeEl = document.getElementById(id + '_size');
    if (fontEl) fontEl.value = s[fk] || df;
    if (sizeEl) sizeEl.value = s[sk] || ds;
    applyHdrStyle(id, s[stk] !== undefined ? s[stk] : dst);
  });
}

function collectHdrFonts() {
  const out = {};
  HDR_FONT_IDS.forEach(id => {
    const [fk, sk, stk] = HDR_SAVE_KEYS[id];
    const fontEl = document.getElementById(id + '_font');
    const sizeEl = document.getElementById(id + '_size');
    const styleEl = document.getElementById(id + '_style');
    if (fontEl)  out[fk]  = fontEl.value;
    if (sizeEl)  out[sk]  = sizeEl.value;
    if (styleEl) out[stk] = styleEl.value;
  });
  return out;
}

// ---- Save report header settings only ----
async function saveHeaderSettings() {
  const payload = {
    ...(typeof collectHdrFonts === 'function' ? collectHdrFonts() : {}),
  };
  const r = await fetch('api/settings.php?action=save',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)
  }).then(r=>r.json());
  toast(r.ok ? 'Header settings saved' : (r.error || 'Error saving'));
}

<?php if (is_admin()): ?>
async function uploadReportLogo() {
  const file = document.getElementById('reportLogoInput').files[0];
  if (!file) { toast('Please select an image file first'); return; }
  const fd = new FormData(); fd.append('logo', file);
  const r = await fetch('api/settings.php?action=upload_report_logo',{method:'POST',body:fd}).then(r=>r.json());
  if (r.ok) {
    document.getElementById('reportLogoPreview').innerHTML =
      `<img src="${esc(r.logo_path)}?t=${Date.now()}" style="max-width:116px;max-height:116px;object-fit:contain">`;
    document.getElementById('reportLogoMsg').innerHTML = '<span style="color:var(--green)">&#10003; Report logo uploaded</span>';
    toast('Report logo uploaded');
  } else { toast(r.error || 'Upload failed'); }
}
async function deleteReportLogo() {
  showConfirm({
    title: 'Remove Report Logo',
    message: 'Remove the report logo?\nPDFs will fall back to the system logo instead.',
    confirmText: 'Remove', danger: true,
    onConfirm: async () => {
      await fetch('api/settings.php?action=delete_report_logo',{method:'POST'});
      document.getElementById('reportLogoPreview').innerHTML =
        '<span class="muted" style="font-size:.8rem;text-align:center;padding:8px">No report logo</span>';
      document.getElementById('reportLogoMsg').innerHTML = '';
      toast('Report logo removed');
    }
  });
}
async function saveAdminHeaderSettings() {
  const payload = typeof collectHdrFonts === 'function' ? collectHdrFonts() : {};
  const r = await fetch('api/settings.php?action=save_global_header', {
    method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
  }).then(r=>r.json());
  toast(r.ok ? 'Header font settings saved for all accounts' : (r.error || 'Error saving'));
}
async function createAdmin() {
  const name=document.getElementById('adm_name').value.trim();
  const email=document.getElementById('adm_email').value.trim();
  const pass=document.getElementById('adm_pass').value;
  if (!name||!email||!pass){toast('Fill in all fields');return;}
  const r=await fetch('api/admin.php?action=create_admin',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({name,email,password:pass})}).then(r=>r.json());
  toast(r.message||(r.ok?'Admin created':'Error'));
  if(r.ok){['adm_name','adm_email','adm_pass'].forEach(id=>document.getElementById(id).value='');loadAdmins();}
}
async function loadAdmins(){
  const r=await fetch('api/admin.php?action=admins').then(r=>r.json());
  const list=r.admins||[];
  document.getElementById('adminList').innerHTML=list.length
    ?`<table class="sheet" style="width:auto"><thead><tr><th>Name</th><th>Email</th><th>Created</th><th></th></tr></thead><tbody>`+
      list.map(a=>`<tr><td style="padding:7px 12px">${esc(a.full_name)}</td><td style="padding:7px 12px">${esc(a.email)}</td>
        <td style="padding:7px 12px">${esc(a.created_at.slice(0,10))}</td>
        <td><button class="btn btn-ghost btn-sm" onclick="deleteAccount(${a.id})">Remove</button></td></tr>`).join('')+
      '</tbody></table>'
    :'<p class="muted">No other admin accounts yet.</p>';
}
async function deleteAccount(id){
  showConfirm({
    title: 'Delete Admin Account',
    message: 'Delete this admin account?\nThis cannot be undone.',
    confirmText: 'Delete', danger: true,
    onConfirm: async () => {
  const r=await fetch('api/admin.php?action=delete_teacher',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({teacher_id:id})}).then(r=>r.json());
  toast(r.ok?'Deleted':r.error); if(r.ok)loadAdmins();
    }
  });
}
<?php endif; ?>

loadSettings();
</script>
<footer style="display:block;width:100%;text-align:center;font-size:11px;color:#aaa;padding:10px 0 14px;margin-top:16px;letter-spacing:.02em;">Copyright &copy; 2026 Arnel Maghinay. All rights reserved.</footer>
</body></html>
