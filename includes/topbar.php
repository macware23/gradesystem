<?php
/** Shared topbar. Caller must have run require_login(). */
$tn       = isset($teacherName) ? $teacherName : ($_SESSION['full_name'] ?? '');
$role     = current_role();
$ss       = school_settings();
$accent   = htmlspecialchars($ss['web_accent']     ?? '#c97b1f');
$ink      = htmlspecialchars($ss['web_ink']        ?? '#1d2433');
$navText  = htmlspecialchars($ss['web_nav_text']   ?? '#f5f0e6');
$paper    = htmlspecialchars($ss['web_paper']      ?? '#f5f0e6');
$card     = htmlspecialchars($ss['web_card']       ?? '#fffdf8');
$line     = htmlspecialchars($ss['web_line']       ?? '#d9cfba');
$muted    = htmlspecialchars($ss['web_muted']      ?? '#495066');
$link     = htmlspecialchars($ss['web_link']       ?? $accent);
// Grab all customizable table/highlight color settings
$hlGradeCustom  = $ss['hl_grade']              ?? null;
$hlGradeTxt     = htmlspecialchars($ss['hl_grade_txt']    ?? '');
$hlEquivCustom  = $ss['hl_equiv']              ?? null;
$hlEquivTxt     = htmlspecialchars($ss['hl_equiv_txt']    ?? '#155724');
$hlFinalCustom  = $ss['hl_final']              ?? null;
$hlFinalTxt     = htmlspecialchars($ss['hl_final_txt']    ?? '#fff');
$hlFailBg       = htmlspecialchars($ss['hl_fail_bg']      ?? '#f8d7da');
$hlFailTxt      = htmlspecialchars($ss['hl_fail_txt']     ?? '#721c24');
$hlTermCol      = htmlspecialchars($ss['hl_term_col']     ?? '');
$hlTermColTxt   = htmlspecialchars($ss['hl_term_col_txt'] ?? '');
$tblHeader      = htmlspecialchars($ss['tbl_header']      ?? '');
$tblHeaderTxt   = htmlspecialchars($ss['tbl_header_txt']  ?? '');
$tblSubHeader   = htmlspecialchars($ss['tbl_sub_header']  ?? '');
$tblSubHeaderTxt= htmlspecialchars($ss['tbl_sub_header_txt'] ?? '');
$tblAvgHeader   = htmlspecialchars($ss['tbl_avg_header']  ?? '');
$tblAvgHeaderTxt= htmlspecialchars($ss['tbl_avg_header_txt'] ?? '');
$tblRowOdd      = htmlspecialchars($ss['tbl_row_odd']     ?? '');
$tblRowEven     = htmlspecialchars($ss['tbl_row_even']    ?? '');
$tblRawBg       = htmlspecialchars($ss['tbl_raw_bg']      ?? '');
$tblNameBg      = htmlspecialchars($ss['tbl_name_bg']     ?? '');
$tblNameTxt     = htmlspecialchars($ss['tbl_name_txt']    ?? '');
$tblNameEvenBg  = htmlspecialchars($ss['tbl_name_even_bg']?? '');
$tblComputedBg  = htmlspecialchars($ss['tbl_computed_bg'] ?? '');
$tblWtColor     = htmlspecialchars($ss['tbl_wt_color']    ?? '');
$tblAiCellBg    = htmlspecialchars($ss['tbl_ai_cell_bg']  ?? '');
$tblAiCellTxt   = htmlspecialchars($ss['tbl_ai_cell_txt'] ?? '');
$tblRowOddTxt   = htmlspecialchars($ss['tbl_row_odd_txt'] ?? '');
$tblRowEvenTxt  = htmlspecialchars($ss['tbl_row_even_txt']?? '');
$tblRawTxt      = htmlspecialchars($ss['tbl_raw_txt']     ?? '');
$tblAvgBg       = htmlspecialchars($ss['tbl_avg_bg']      ?? '');
$tblAvgTxt      = htmlspecialchars($ss['tbl_avg_txt']     ?? '');
$tblWsBg        = htmlspecialchars($ss['tbl_ws_bg']       ?? '');
$tblWsTxt       = htmlspecialchars($ss['tbl_ws_txt']      ?? '');
$hlRmkPass      = htmlspecialchars($ss['hl_remarks_pass'] ?? '#d4edda');
$hlRmkPassTxt   = htmlspecialchars($ss['hl_remarks_pass_txt'] ?? '#155724');
$hlRmkFail      = htmlspecialchars($ss['hl_remarks_fail'] ?? '#f8d7da');
$hlRmkFailTxt   = htmlspecialchars($ss['hl_remarks_fail_txt'] ?? '#721c24');
$hlRmkInc       = htmlspecialchars($ss['hl_remarks_inc']  ?? '#fff3cd');
$hlRmkIncTxt    = htmlspecialchars($ss['hl_remarks_inc_txt'] ?? '#856404');
$hlAiBtn        = htmlspecialchars($ss['hl_ai_btn']       ?? '');
$hlAiBtnTxt     = htmlspecialchars($ss['hl_ai_btn_txt']   ?? '#fff');
$logoPath   = $ss['logo_path']         ?? '';
$schoolName = htmlspecialchars($ss['school_name']      ?? 'GradeFlow');
$subtitle   = htmlspecialchars($ss['system_subtitle']  ?? 'GradeFlow Grading System');

// School Name and Subtitle font settings for the nav bar
$_navBuildCss = function(string $fontKey, string $style, string $size, string $defaultSize): string {
    $map = ['Helvetica'=>'Arial,Helvetica,sans-serif','Times'=>"'Times New Roman',Times,serif",'Courier'=>"'Courier New',Courier,monospace"];
    $css = '';
    if (isset($map[$fontKey])) $css .= 'font-family:'.$map[$fontKey].';';
    $sz = (float)$size;
    $css .= $sz > 0 ? 'font-size:'.$sz.'pt;' : 'font-size:'.$defaultSize.';';
    if (str_contains($style,'B')) $css .= 'font-weight:700;';
    if (str_contains($style,'I')) $css .= 'font-style:italic;';
    if (str_contains($style,'U')) $css .= 'text-decoration:underline;';
    return $css;
};
$_snNavCss = $_navBuildCss(
    $ss['school_name_font']  ?? 'Helvetica',
    $ss['school_name_style'] ?? '',
    $ss['school_name_size']  ?? '0',
    'inherit'
);
$_stNavCss = $_navBuildCss(
    $ss['subtitle_font']  ?? 'Helvetica',
    $ss['subtitle_style'] ?? '',
    $ss['subtitle_size']  ?? '0',
    '.75rem'
);

// Font definition: each option has a Google Font (for online) and system fallbacks (for offline/XAMPP)
// System fallbacks are chosen to look visually distinct so the change is obvious even without internet.
$webFont = $ss['web_font'] ?? 'Outfit';
$fontMap = [
  'Outfit'          => ['google'=>'Outfit:wght@300;400;500;600;700',          'stack'=>"'Outfit','Trebuchet MS',sans-serif"],
  'Inter'           => ['google'=>'Inter:wght@300;400;500;600;700',           'stack'=>"'Inter','Segoe UI',sans-serif"],
  'Lato'            => ['google'=>'Lato:wght@300;400;700',                    'stack'=>"'Lato','Helvetica Neue',sans-serif"],
  'Poppins'         => ['google'=>'Poppins:wght@300;400;500;600;700',         'stack'=>"'Poppins',sans-serif"],
  'Nunito'          => ['google'=>'Nunito:wght@300;400;600;700',              'stack'=>"'Nunito',sans-serif"],
  'Raleway'         => ['google'=>'Raleway:wght@300;400;500;700',             'stack'=>"'Raleway',sans-serif"],
  'Montserrat'      => ['google'=>'Montserrat:wght@300;400;500;700',          'stack'=>"'Montserrat',sans-serif"],
  'Open Sans'       => ['google'=>'Open+Sans:wght@300;400;600;700',           'stack'=>"'Open Sans','Arial',sans-serif"],
  'Source Sans 3'   => ['google'=>'Source+Sans+3:wght@300;400;600;700',       'stack'=>"'Source Sans 3','Helvetica Neue',sans-serif"],
  'Merriweather'    => ['google'=>'Merriweather:wght@300;400;700',            'stack'=>"'Merriweather','Georgia',serif"],
  'Playfair Display'=> ['google'=>'Playfair+Display:wght@400;500;700',        'stack'=>"'Playfair Display','Georgia',serif"],
  'Roboto'          => ['google'=>'Roboto:wght@300;400;500;700',              'stack'=>"'Roboto','Helvetica Neue',sans-serif"],
  'Roboto Slab'     => ['google'=>'Roboto+Slab:wght@300;400;700',             'stack'=>"'Roboto Slab','Georgia',serif"],
  'PT Sans'         => ['google'=>'PT+Sans:wght@400;700',                     'stack'=>"'PT Sans','Helvetica Neue',sans-serif"],
  'Georgia'         => ['google'=>null,                                        'stack'=>"'Georgia','Times New Roman',serif"],
  'Courier New'     => ['google'=>null,                                        'stack'=>"'Courier New','Courier',monospace"],
  'system'          => ['google'=>null,                                        'stack'=>"system-ui,-apple-system,'Segoe UI',sans-serif"],
];
$chosen    = $fontMap[$webFont] ?? $fontMap['Outfit'];
$fontStack = $chosen['stack'];
$googleKey = $chosen['google'];
?>
<style>
:root {
  --amber:      <?= $accent ?>;
  --ink:        <?= $ink ?>;
  --ink-soft:   <?= $muted ?>;
  --paper:      <?= $paper ?>;
  --paper-2:    color-mix(in srgb, <?= $paper ?> 85%, <?= $ink ?> 15%);
  --card:       <?= $card ?>;
  --line:       <?= $line ?>;
  --hl-grade:   <?= $hlGradeCustom ? htmlspecialchars($hlGradeCustom) : 'var(--amber-soft)' ?>;
<?php if ($hlGradeTxt): ?>  --hl-grade-txt:<?= $hlGradeTxt ?>;<?php endif; ?>
  --hl-equiv:   <?= $hlEquivCustom ? htmlspecialchars($hlEquivCustom) : '#d8ecdf' ?>;
<?php if ($hlEquivTxt): ?>  --hl-equiv-txt:<?= $hlEquivTxt ?>;<?php endif; ?>
  --hl-final:   <?= $hlFinalCustom ? htmlspecialchars($hlFinalCustom) : 'var(--amber)' ?>;
<?php if ($hlFinalTxt): ?>  --hl-final-txt:<?= $hlFinalTxt ?>;<?php endif; ?>
  --hl-fail-bg: <?= $hlFailBg ?>;
  --hl-fail-txt:<?= $hlFailTxt ?>;
<?php if ($hlTermCol): ?>  --hl-term-col:    <?= $hlTermCol ?>;<?php endif; ?>
<?php if ($hlTermColTxt): ?>  --hl-term-col-txt:<?= $hlTermColTxt ?>;<?php endif; ?>
<?php if ($tblHeader): ?>  --tbl-header:     <?= $tblHeader ?>;<?php endif; ?>
<?php if ($tblHeaderTxt): ?>  --tbl-header-txt: <?= $tblHeaderTxt ?>;<?php endif; ?>
<?php if ($tblSubHeader): ?>  --tbl-sub-header: <?= $tblSubHeader ?>;<?php endif; ?>
<?php if ($tblSubHeaderTxt): ?>  --tbl-sub-header-txt:<?= $tblSubHeaderTxt ?>;<?php endif; ?>
<?php if ($tblAvgHeader): ?>  --tbl-avg-header: <?= $tblAvgHeader ?>;<?php endif; ?>
<?php if ($tblAvgHeaderTxt): ?>  --tbl-avg-header-txt:<?= $tblAvgHeaderTxt ?>;<?php endif; ?>
<?php if ($tblRowOdd): ?>  --tbl-row-odd:    <?= $tblRowOdd ?>;<?php endif; ?>
<?php if ($tblRowEven): ?>  --tbl-row-even:   <?= $tblRowEven ?>; --tbl-row-even-computed: color-mix(in srgb, <?= $tblRowEven ?> 85%, <?= $ink ?> 15%);<?php endif; ?>
<?php if ($tblRawBg): ?>  --tbl-raw-bg:     <?= $tblRawBg ?>;<?php endif; ?>
<?php if ($tblNameBg): ?>  --tbl-name-bg:    <?= $tblNameBg ?>;<?php endif; ?>
<?php if ($tblNameTxt): ?>  --tbl-name-txt:   <?= $tblNameTxt ?>;<?php endif; ?>
<?php if ($tblNameEvenBg): ?>  --tbl-name-even-bg: <?= $tblNameEvenBg ?>;<?php endif; ?>
<?php if ($tblComputedBg): ?>  --tbl-computed-bg:  <?= $tblComputedBg ?>;<?php endif; ?>
<?php if ($tblWtColor): ?>  --tbl-wt-color:   <?= $tblWtColor ?>;<?php endif; ?>
<?php if ($tblAiCellBg): ?>  --tbl-ai-cell-bg: <?= $tblAiCellBg ?>;<?php endif; ?>
<?php if ($tblAiCellTxt): ?>  --tbl-ai-cell-txt:<?= $tblAiCellTxt ?>;<?php endif; ?>
<?php if ($tblRowOddTxt): ?>  --tbl-row-odd-txt: <?= $tblRowOddTxt ?>;<?php endif; ?>
<?php if ($tblRowEvenTxt): ?>  --tbl-row-even-txt:<?= $tblRowEvenTxt ?>;<?php endif; ?>
<?php if ($tblRawTxt): ?>  --tbl-raw-txt:    <?= $tblRawTxt ?>;<?php endif; ?>
<?php if ($tblAvgBg): ?>  --tbl-avg-bg:     <?= $tblAvgBg ?>;<?php endif; ?>
<?php if ($tblAvgTxt): ?>  --tbl-avg-txt:    <?= $tblAvgTxt ?>;<?php endif; ?>
<?php if ($tblWsBg): ?>  --tbl-ws-bg:      <?= $tblWsBg ?>;<?php endif; ?>
<?php if ($tblWsTxt): ?>  --tbl-ws-txt:     <?= $tblWsTxt ?>;<?php endif; ?>
<?php if ($hlRmkPass): ?>  --hl-remarks-pass:    <?= $hlRmkPass ?>;<?php endif; ?>
<?php if ($hlRmkPassTxt): ?>  --hl-remarks-pass-txt:<?= $hlRmkPassTxt ?>;<?php endif; ?>
<?php if ($hlRmkFail): ?>  --hl-remarks-fail:    <?= $hlRmkFail ?>;<?php endif; ?>
<?php if ($hlRmkFailTxt): ?>  --hl-remarks-fail-txt:<?= $hlRmkFailTxt ?>;<?php endif; ?>
<?php if ($hlRmkInc): ?>  --hl-remarks-inc:     <?= $hlRmkInc ?>;<?php endif; ?>
<?php if ($hlRmkIncTxt): ?>  --hl-remarks-inc-txt: <?= $hlRmkIncTxt ?>;<?php endif; ?>
<?php if ($hlAiBtn): ?>  --hl-ai-btn:    <?= $hlAiBtn ?>;<?php endif; ?>
<?php if ($hlAiBtnTxt): ?>  --hl-ai-btn-txt:<?= $hlAiBtnTxt ?>;<?php endif; ?>
}
body { color: <?= $bodyText ?>; }
body, input, select, textarea, button, h1, h2, h3, label {
  font-family: <?= $fontStack ?> !important;
}
a { color: <?= $link ?>; }
.topbar { background: <?= $ink ?>; }
.topbar .brand, .topbar nav a { color: <?= $navText ?>; }
.topbar .btn-ghost { color: <?= $navText ?>; border-color: color-mix(in srgb,<?= $navText ?> 40%,transparent); }
</style>
<div class="topbar" id="mainTopbar">
  <a href="<?= $role==='admin'?'admin.php':($role==='chair'?'chair.php':'dashboard.php') ?>" class="brand" style="color:inherit;text-decoration:none">
    <?php if ($logoPath && file_exists($logoPath)): ?>
      <img src="<?= htmlspecialchars($logoPath) ?>"
           style="height:26px;border-radius:4px;object-fit:contain;flex-shrink:0" alt="logo">
    <?php else: ?>
      <span class="mark">G</span>
    <?php endif; ?>
    <span class="brand-name" style="<?= htmlspecialchars($_snNavCss) ?>"><?= $schoolName ?></span>
    <?php if ($schoolName !== 'GradeFlow' && $subtitle): ?>
      <span class="brand-sub" style="opacity:.65;<?= htmlspecialchars($_stNavCss) ?>"> &mdash; <?= $subtitle ?></span>
    <?php endif; ?>
  </a>
  <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
  <nav id="mainNav">
    <?php if ($role === 'admin'): ?>
      <a href="admin.php" <?= basename($_SERVER['PHP_SELF'])==='admin.php'?'style="font-weight:700;opacity:1"':'' ?>>Dashboard</a>
      <span class="pill pill-amber" style="font-size:.72rem">ADMIN</span>
      <a href="#" onclick="openBackupModal();return false" style="display:inline-flex;align-items:center;gap:5px">&#128190; Backup</a>
    <?php elseif ($role === 'chair'): ?>
      <?php $cur = basename($_SERVER['PHP_SELF']); ?>
      <a href="chair.php" <?= $cur==='chair.php'?'style="font-weight:700;opacity:1"':'' ?>>&#128101; Faculty</a>
      <span class="pill" style="background:#e8f0fe;color:#1a56db;font-size:.72rem">CHAIR</span>
    <?php else: ?>
      <?php $cur = basename($_SERVER['PHP_SELF']); ?>
      <a href="dashboard.php" <?= $cur==='dashboard.php'?'style="font-weight:700;opacity:1"':'' ?>>My Classes</a>
      <a href="archived.php"  <?= $cur==='archived.php' ?'style="font-weight:700;opacity:1"':'' ?>>&#128196; Archived</a>
    <?php endif; ?>
    <a href="settings.php">&#9881; Settings</a>
    <span style="color:var(--paper-2);font-size:.9rem"><?= htmlspecialchars($tn) ?></span>
    <a href="logout.php" class="btn btn-ghost btn-sm"
       style="color:var(--paper);border-color:rgba(245,240,230,.3)">Sign out</a>
  </nav>
</div>
<script>
(function(){
  var btn = document.getElementById('navToggle');
  var bar = document.getElementById('mainTopbar');
  var nav = document.getElementById('mainNav');
  if (!btn || !bar || !nav) return;

  var BP = 768; // breakpoint in px

  function isMobile() { return window.innerWidth <= BP; }

  function closeNav() {
    nav.style.display = 'none';
    bar.classList.remove('nav-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '&#9776;';
  }

  function openNav() {
    nav.style.display = 'flex';
    nav.style.flexDirection = 'column';
    bar.classList.add('nav-open');
    btn.setAttribute('aria-expanded', 'true');
    btn.innerHTML = '&#10005;';
  }

  function applyLayout() {
    if (isMobile()) {
      btn.style.display = 'flex';
      // Only hide nav if it is not already open
      if (!bar.classList.contains('nav-open')) {
        nav.style.display = 'none';
      }
    } else {
      btn.style.display = 'none';
      // Reset to desktop — let CSS flex row take over
      nav.style.display = '';
      nav.style.flexDirection = '';
      bar.classList.remove('nav-open');
    }
  }

  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    if (bar.classList.contains('nav-open')) { closeNav(); } else { openNav(); }
  });

  // Close on nav link tap (supports full-page navigations)
  nav.addEventListener('click', function(e) {
    if (e.target.tagName === 'A') closeNav();
  });

  // Close when tapping anywhere outside the topbar
  document.addEventListener('click', function(e) {
    if (bar.classList.contains('nav-open') && !bar.contains(e.target)) closeNav();
  });

  // Re-evaluate on resize (e.g. phone rotation)
  window.addEventListener('resize', applyLayout);

  // Run immediately — hides nav on mobile before first paint
  applyLayout();
})();
</script>

<?php if ($role === 'admin'): ?>
<!-- ═══════════════════════════════════════════════════════════════════
     BACKUP & RESTORE MODAL — admin only, injected globally via topbar
     ═══════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="backupModal">
  <div class="modal" style="max-width:680px;padding:24px">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
      <div>
        <h2 style="margin:0 0 3px;font-size:1.15rem">&#128190; Database Backup &amp; Restore</h2>
        <div class="muted" style="font-size:.82rem">Create a full backup or restore from a previous file.</div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('backupModal')" title="Close">&#10005; Close</button>
    </div>

    <!-- Tab bar -->
    <div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:18px">
      <button id="bkTab1" onclick="switchBkTab(1)"
        style="padding:8px 18px;border:none;background:none;cursor:pointer;font-size:.9rem;
               font-weight:700;color:var(--amber);border-bottom:2px solid var(--amber);
               margin-bottom:-2px;transition:color .15s">Backups</button>
      <button id="bkTab2" onclick="switchBkTab(2)"
        style="padding:8px 18px;border:none;background:none;cursor:pointer;font-size:.9rem;
               font-weight:400;color:var(--ink-soft);border-bottom:2px solid transparent;
               margin-bottom:-2px;transition:color .15s">Restore</button>
    </div>

    <!-- Tab 1: Backup -->
    <div id="bkPane1">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <p class="muted" style="margin:0;font-size:.85rem">
          Click Download Backup to create a full database dump and save it to your computer.
          Backups are also stored on the server.
        </p>
        <button class="btn btn-primary btn-sm" onclick="doBackup()" id="backupBtn"
          style="flex-shrink:0;margin-left:14px">&#8659; Download Backup</button>
      </div>

      <!-- Saved backups list -->
      <div style="font-weight:600;font-size:.88rem;margin-bottom:8px;color:var(--ink)">
        Server Backups
        <button class="btn btn-ghost btn-sm" onclick="loadBackupList()"
          style="margin-left:8px;font-size:.78rem">&#8635; Refresh</button>
      </div>
      <div id="backupList" style="max-height:280px;overflow-y:auto;border:1px solid var(--line);
           border-radius:8px;padding:4px 0;background:var(--paper)">
        <div class="muted" style="padding:16px;font-size:.85rem;text-align:center">Loading…</div>
      </div>
    </div>

    <!-- Tab 2: Restore -->
    <div id="bkPane2" style="display:none">
      <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;
                  padding:10px 14px;margin-bottom:14px;font-size:.85rem;color:#856404">
        <strong>&#9888; Warning:</strong> Restoring will overwrite ALL current data.
        A pre-restore backup is created automatically before any data is changed.
      </div>
      <p class="muted" style="font-size:.85rem;margin:0 0 12px">
        Select a <code>.sql</code> backup file from your computer, then confirm with your admin password.
      </p>
      <div class="field">
        <label>Select .sql backup file</label>
        <input type="file" id="restoreFile" accept=".sql"
          style="font-size:.88rem"
          onchange="document.getElementById('restoreBtn').disabled=!this.files.length">
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button class="btn btn-primary" id="restoreBtn" disabled
          onclick="openRestoreConfirm()"
          style="background:var(--red);border-color:var(--red)">&#8679; Restore Database…</button>
      </div>
      <p id="restoreResult" style="display:none;margin:12px 0 0;font-size:.88rem"></p>
    </div>

  </div>
</div>

<!-- Restore password confirm modal (child of backupModal flow) -->
<div class="modal-backdrop" id="restoreModal">
  <div class="modal" style="max-width:440px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h2 style="margin:0;color:var(--red)">&#9888; Confirm Restore</h2>
      <button class="btn btn-ghost btn-sm" onclick="closeModal('restoreModal')">&#10005; Close</button>
    </div>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;
                padding:10px 14px;margin-bottom:14px;font-size:.88rem;color:#856404">
      This will <strong>overwrite all current data</strong>. A pre-restore backup will be
      saved automatically before anything is changed.
    </div>
    <p id="restoreFileName" style="margin:0 0 14px;font-size:.88rem;color:var(--ink-soft)"></p>
    <div class="field">
      <label>Enter your Admin password to confirm</label>
      <input type="password" id="restorePass" placeholder="Admin password"
        autocomplete="current-password"
        onkeydown="if(event.key==='Enter') commitRestore()">
    </div>
    <div id="restoreErr" style="display:none;color:var(--red);font-size:.85rem;
         padding:8px 12px;background:#fdecea;border-radius:8px;margin-bottom:10px"></div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="btn btn-ghost" onclick="closeModal('restoreModal')">Cancel</button>
      <button class="btn btn-primary" id="restoreConfirmBtn"
        style="background:var(--red);border-color:var(--red)"
        onclick="commitRestore()">&#8679; Restore Now</button>
    </div>
  </div>
</div>

<script>
// ── Modal open/close ─────────────────────────────────────────────────
function openBackupModal() {
  document.getElementById('backupModal').classList.add('show');
  switchBkTab(1);
  loadBackupList();
}

// ── Tab switching ────────────────────────────────────────────────────
function switchBkTab(n) {
  document.getElementById('bkPane1').style.display = n === 1 ? '' : 'none';
  document.getElementById('bkPane2').style.display = n === 2 ? '' : 'none';
  [1,2].forEach(i => {
    const t = document.getElementById('bkTab' + i);
    const active = i === n;
    t.style.fontWeight    = active ? '700' : '400';
    t.style.color         = active ? 'var(--amber)'    : 'var(--ink-soft)';
    t.style.borderBottomColor = active ? 'var(--amber)' : 'transparent';
  });
}

// ── Helpers ──────────────────────────────────────────────────────────
function fmtSize(b) {
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
  return (b/1048576).toFixed(1) + ' MB';
}
function fmtDate(ts) {
  return new Date(ts * 1000).toLocaleString();
}

// ── Download Backup ──────────────────────────────────────────────────
async function doBackup() {
  const btn = document.getElementById('backupBtn');
  btn.disabled = true; btn.textContent = '⏳ Creating…';
  try {
    const res = await fetch('api/admin.php?action=backup_db', { method: 'POST' });
    if (!res.ok) { const j = await res.json().catch(()=>({})); toast(j.error||'Backup failed'); return; }
    const blob = await res.blob();
    const cd   = res.headers.get('Content-Disposition') || '';
    const name = cd.match(/filename="([^"]+)"/)?.[1] || 'gradeflow_backup.sql';
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = name; a.click();
    URL.revokeObjectURL(url);
    toast('Backup downloaded: ' + name);
    loadBackupList();
  } finally {
    btn.disabled = false; btn.textContent = '⬇ Download Backup';
  }
}

// ── List server backups ──────────────────────────────────────────────
async function loadBackupList() {
  const el = document.getElementById('backupList');
  if (!el) return;
  el.innerHTML = '<div class="muted" style="padding:14px;font-size:.84rem;text-align:center">Loading…</div>';
  const r = await fetch('api/admin.php?action=list_backups').then(r=>r.json());
  if (!r.backups || !r.backups.length) {
    el.innerHTML = '<div class="muted" style="padding:14px;font-size:.84rem;text-align:center">No saved backups yet. Click Download Backup to create one.</div>';
    return;
  }
  el.innerHTML = r.backups.map((b, i) => `
    <div style="display:flex;align-items:center;gap:10px;padding:9px 14px;
                background:${i%2?'var(--paper-2)':''};font-size:.83rem;flex-wrap:wrap">
      <span style="font-family:monospace;flex:1;min-width:0;word-break:break-all;
                   color:var(--ink)">${esc(b.name)}</span>
      <span class="muted" style="white-space:nowrap">${fmtSize(b.size)}</span>
      <span class="muted" style="white-space:nowrap">${fmtDate(b.created)}</span>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <a class="btn btn-ghost btn-sm" style="font-size:.78rem"
           href="api/admin.php?action=download_backup&name=${encodeURIComponent(b.name)}"
           download="${esc(b.name)}">&#8659;</a>
        <button class="btn btn-ghost btn-sm" style="font-size:.78rem;color:var(--red)"
          onclick="deleteBackup('${esc(b.name)}')">&#128465;</button>
      </div>
    </div>`).join('');
}

async function deleteBackup(name) {
  showConfirm({
    title: 'Delete Backup',
    message: 'Delete backup "' + name + '"?\nThis cannot be undone.',
    confirmText: 'Delete', danger: true,
    onConfirm: async () => {
      const r = await fetch('api/admin.php?action=delete_backup', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({name})
      }).then(r=>r.json());
      if (r.ok) { toast('Backup deleted'); loadBackupList(); }
      else toast(r.error || 'Delete failed');
    }
  });
}

// ── Restore ──────────────────────────────────────────────────────────
function openRestoreConfirm() {
  const file = document.getElementById('restoreFile').files[0];
  if (!file) return;
  document.getElementById('restoreFileName').textContent =
    'File: ' + file.name + ' (' + fmtSize(file.size) + ')';
  document.getElementById('restorePass').value = '';
  document.getElementById('restoreErr').style.display = 'none';
  document.getElementById('restoreModal').classList.add('show');
  setTimeout(() => document.getElementById('restorePass').focus(), 80);
}

async function commitRestore() {
  const pw  = document.getElementById('restorePass').value;
  const err = document.getElementById('restoreErr');
  const btn = document.getElementById('restoreConfirmBtn');
  err.style.display = 'none';
  if (!pw.trim()) { err.textContent = 'Admin password is required.'; err.style.display = ''; return; }
  const file = document.getElementById('restoreFile').files[0];
  if (!file) { err.textContent = 'No file selected.'; err.style.display = ''; return; }
  btn.disabled = true; btn.textContent = '⏳ Restoring…';
  const fd = new FormData();
  fd.append('password', pw);
  fd.append('sqlfile', file);
  try {
    const r = await fetch('api/admin.php?action=restore_db', { method:'POST', body:fd }).then(r=>r.json());
    if (r.ok) {
      closeModal('restoreModal');
      const msg = document.getElementById('restoreResult');
      msg.style.display = ''; msg.style.color = 'var(--green)';
      msg.textContent = '✔ ' + r.message;
      document.getElementById('restoreFile').value = '';
      document.getElementById('restoreBtn').disabled = true;
      toast('Database restored successfully');
      loadBackupList();
    } else {
      err.textContent = r.error || 'Restore failed.';
      err.style.display = '';
    }
  } catch(e) {
    err.textContent = 'Network error during restore.';
    err.style.display = '';
  } finally {
    btn.disabled = false; btn.textContent = '⬆ Restore Now';
  }
}
</script>
<?php endif; ?>
