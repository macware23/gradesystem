<?php
/**
 * GradeFlow - Settings API
 *
 * Global keys  (school_name, logo, subtitle):
 *   stored in school_settings — shared by every account.
 *
 * Personal keys (colors, fonts):
 *   stored in user_settings keyed by teacher_id — each user has their own.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_login();

function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function fail($m,$c=400){ http_response_code($c); out(['ok'=>false,'error'=>$m]); }
function body_json(): array { $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }

$action = $_GET['action'] ?? '';
$pdo    = db();
$tid    = current_teacher_id();

// Keys that belong to each category
const GLOBAL_KEYS = ['school_name','school_address','logo_path','report_logo_path','system_subtitle'];
const USER_KEYS   = [
    'web_accent','web_ink','web_text_color','web_font',
    'web_nav_text','web_paper','web_card','web_line','web_muted','web_link',
    'hl_grade','hl_grade_txt','hl_equiv','hl_equiv_txt',
    'hl_final','hl_final_txt','hl_fail_bg','hl_fail_txt',
    'hl_term_col','hl_term_col_txt',
    'hl_remarks_pass','hl_remarks_pass_txt','hl_remarks_fail','hl_remarks_fail_txt',
    'hl_remarks_inc','hl_remarks_inc_txt','hl_ai_btn','hl_ai_btn_txt',
    'tbl_header','tbl_header_txt','tbl_sub_header','tbl_sub_header_txt',
    'tbl_avg_header','tbl_avg_header_txt',
    'tbl_row_odd','tbl_row_even','tbl_row_odd_txt','tbl_row_even_txt',
    'tbl_name_bg','tbl_name_txt','tbl_name_even_bg','tbl_computed_bg',
    'tbl_wt_color','tbl_ai_cell_bg','tbl_ai_cell_txt',
    'tbl_raw_bg','tbl_raw_txt','tbl_avg_bg','tbl_avg_txt','tbl_ws_bg','tbl_ws_txt',
    'pdf_header_bg','pdf_accent_rgb','pdf_pass_rgb','pdf_fail_rgb',
    'pdf_text_rgb','pdf_title_font','pdf_body_font',
    'pdf_paper','pdf_font_size','pdf_term_paper','pdf_final_paper',
    // Per-area PDF text colors
    'pdf_hdr_txt','pdf_subtitle_txt','pdf_title_txt',
    'pdf_subj_txt','pdf_info_txt',
    'pdf_th_bg','pdf_th_txt','pdf_th2_bg','pdf_th2_txt',
    'pdf_row_odd','pdf_row_even','pdf_border',
    'pdf_equiv','pdf_equiv_txt','pdf_ws','pdf_ws_txt',
    'pdf_grade','pdf_grade_txt','pdf_inc','pdf_inc_txt',
    'pdf_pass_txt','pdf_fail_txt',
    'pdf_term_col','pdf_term_col_txt','pdf_final_col','pdf_final_col_txt',
    'pdf_footer_txt','pdf_sig_txt','pdf_page_bg',
    // AI Intervention Report colors
    'ai_hdr_bg','ai_hdr_school_txt','ai_hdr_addr_txt','ai_hdr_title_txt',
    'ai_section_bg','ai_section_txt',
    'ai_stats_bg','ai_stats_val_txt','ai_stats_lbl_txt',
    'ai_body_txt','ai_info_txt','ai_footer_txt','ai_accent_rgb',
    'ai_page_bg',
    // Report header customization (per-teacher)
    'pdf_schedule',
    'pdf_teacher_logo_path',
    // Header line font settings
    'pdf_hdr_sem_font',  'pdf_hdr_sem_size',  'pdf_hdr_sem_style',
    'pdf_hdr_lbl_font',  'pdf_hdr_lbl_size',  'pdf_hdr_lbl_style',
    'pdf_hdr_crs_font',  'pdf_hdr_crs_size',  'pdf_hdr_crs_style',
    'pdf_hdr_sec_font',  'pdf_hdr_sec_size',  'pdf_hdr_sec_style',
    'pdf_hdr_sch_font',  'pdf_hdr_sch_size',  'pdf_hdr_sch_style',
];

switch ($action) {

// ---- Load: merge global + user settings ----
case 'load':
    out(['ok'=>true, 'settings'=>school_settings()]);

// ---- Save: route each key to the right table ----
case 'save': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];

    // Global keys → school_settings (any logged-in user can update school info)
    $insGlobal = $pdo->prepare(
        'INSERT INTO school_settings (setting_key,setting_val) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)');
    foreach (GLOBAL_KEYS as $key) {
        if (array_key_exists($key, $d)) $insGlobal->execute([$key, $d[$key]]);
    }

    // Personal keys → user_settings for this user only
    $insUser = $pdo->prepare(
        'INSERT INTO user_settings (teacher_id,setting_key,setting_val) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)');
    foreach (USER_KEYS as $key) {
        if (array_key_exists($key, $d)) $insUser->execute([$tid, $key, $d[$key]]);
    }
    out(['ok'=>true]);
}

// ---- Upload logo (global — updates school_settings) ----
case 'upload_logo': {
    if (!isset($_FILES['logo'])) fail('No file uploaded');
    $f = $_FILES['logo'];
    if (!in_array($f['type'], ['image/png','image/jpeg','image/jpg','image/gif','image/svg+xml','image/webp']))
        fail('Only image files allowed (PNG, JPG, GIF, SVG, WEBP)');
    if ($f['size'] > 2 * 1024 * 1024) fail('Logo must be under 2MB');
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = 'logo_' . time() . '.' . $ext;
    $dest = __DIR__ . '/../uploads/' . $name;
    // Delete old logo
    $old = $pdo->query("SELECT setting_val FROM school_settings WHERE setting_key='logo_path'")->fetchColumn();
    if ($old && file_exists(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
    if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Upload failed');
    $path = 'uploads/' . $name;
    $pdo->prepare("INSERT INTO school_settings (setting_key,setting_val) VALUES ('logo_path',?)
                   ON DUPLICATE KEY UPDATE setting_val=?")->execute([$path,$path]);
    out(['ok'=>true, 'logo_path'=>$path]);
}

// ---- Delete logo (global) ----
case 'delete_logo': {
    $old = $pdo->query("SELECT setting_val FROM school_settings WHERE setting_key='logo_path'")->fetchColumn();
    if ($old && file_exists(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
    $pdo->exec("UPDATE school_settings SET setting_val='' WHERE setting_key='logo_path'");
    out(['ok'=>true]);
}

// ---- Upload report logo (global — PDF reports only) ----
case 'upload_report_logo': {
    require_admin();
    if (!isset($_FILES['logo'])) fail('No file uploaded');
    $f = $_FILES['logo'];
    if (!in_array($f['type'], ['image/png','image/jpeg','image/jpg','image/gif','image/svg+xml','image/webp']))
        fail('Only image files allowed (PNG, JPG, GIF, SVG, WEBP)');
    if ($f['size'] > 2 * 1024 * 1024) fail('Logo must be under 2MB');
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = 'report_logo_' . time() . '.' . $ext;
    $dest = __DIR__ . '/../uploads/' . $name;
    $old  = $pdo->query("SELECT setting_val FROM school_settings WHERE setting_key='report_logo_path'")->fetchColumn();
    if ($old && file_exists(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
    if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Upload failed');
    $path = 'uploads/' . $name;
    $pdo->prepare("INSERT INTO school_settings (setting_key,setting_val) VALUES ('report_logo_path',?)
                   ON DUPLICATE KEY UPDATE setting_val=?")->execute([$path,$path]);
    out(['ok'=>true, 'logo_path'=>$path]);
}

// ---- Delete report logo (global) ----
case 'delete_report_logo': {
    require_admin();
    $old = $pdo->query("SELECT setting_val FROM school_settings WHERE setting_key='report_logo_path'")->fetchColumn();
    if ($old && file_exists(__DIR__ . '/../' . $old)) @unlink(__DIR__ . '/../' . $old);
    $pdo->exec("DELETE FROM school_settings WHERE setting_key='report_logo_path'");
    out(['ok'=>true]);
}

// ---- Upload teacher personal logo ----
case 'upload_teacher_logo': {
    if (!isset($_FILES['logo'])) fail('No file uploaded');
    $f = $_FILES['logo'];
    if (!in_array($f['type'], ['image/png','image/jpeg','image/jpg','image/gif','image/svg+xml','image/webp']))
        fail('Only image files allowed (PNG, JPG, GIF, SVG, WEBP)');
    if ($f['size'] > 2 * 1024 * 1024) fail('Logo must be under 2MB');
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = 'teacher_logo_' . $tid . '_' . time() . '.' . $ext;
    $dest = __DIR__ . '/../uploads/' . $name;
    // Delete old teacher logo
    $old = $pdo->prepare("SELECT setting_val FROM user_settings WHERE teacher_id=? AND setting_key='pdf_teacher_logo_path'");
    $old->execute([$tid]);
    $oldPath = $old->fetchColumn();
    if ($oldPath && file_exists(__DIR__ . '/../' . $oldPath)) @unlink(__DIR__ . '/../' . $oldPath);
    if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Upload failed');
    $path = 'uploads/' . $name;
    $pdo->prepare("INSERT INTO user_settings (teacher_id,setting_key,setting_val) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE setting_val=?")->execute([$tid,'pdf_teacher_logo_path',$path,$path]);
    out(['ok'=>true, 'logo_path'=>$path]);
}

// ---- Delete teacher personal logo ----
case 'delete_teacher_logo': {
    $stmt = $pdo->prepare("SELECT setting_val FROM user_settings WHERE teacher_id=? AND setting_key='pdf_teacher_logo_path'");
    $stmt->execute([$tid]);
    $oldPath = $stmt->fetchColumn();
    if ($oldPath && file_exists(__DIR__ . '/../' . $oldPath)) @unlink(__DIR__ . '/../' . $oldPath);
    $pdo->prepare("INSERT INTO user_settings (teacher_id,setting_key,setting_val) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE setting_val=''")->execute([$tid,'pdf_teacher_logo_path','']);
    out(['ok'=>true]);
}

// ---- Save global header font settings (admin only — writes to school_settings) ----
case 'save_global_header': {
    require_admin();
    $d = body_json();
    $hdrFontKeys = [
        'pdf_hdr_sem_font','pdf_hdr_sem_size','pdf_hdr_sem_style',
        'pdf_hdr_lbl_font','pdf_hdr_lbl_size','pdf_hdr_lbl_style',
        'pdf_hdr_crs_font','pdf_hdr_crs_size','pdf_hdr_crs_style',
        'pdf_hdr_sec_font','pdf_hdr_sec_size','pdf_hdr_sec_style',
        'pdf_hdr_sch_font','pdf_hdr_sch_size','pdf_hdr_sch_style',
    ];
    $ins = $pdo->prepare(
        'INSERT INTO school_settings (setting_key,setting_val) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)');
    foreach ($hdrFontKeys as $key) {
        if (array_key_exists($key, $d)) $ins->execute([$key, $d[$key]]);
    }
    out(['ok'=>true]);
}

// ---- Reset personal settings (wipe this user's customisations) ----
case 'reset_personal': {
    $pdo->prepare('DELETE FROM user_settings WHERE teacher_id=?')->execute([$tid]);
    out(['ok'=>true, 'message'=>'Your personal settings have been reset to defaults.']);
}

// ── Colleges list (all logged-in users — for dropdowns) ──────────────
case 'colleges': {
    require_login();
    $rows = db()->query("SELECT id, name FROM colleges ORDER BY sort_order, name")->fetchAll();
    out(['ok'=>true,'colleges'=>$rows]);
}

// ── Departments for a college ────────────────────────────────────────
case 'departments': {
    require_login();
    $cid = (int)($_GET['college_id'] ?? 0);
    $rows = db()->prepare(
        "SELECT id, name FROM departments WHERE college_id=? ORDER BY sort_order, name");
    $rows->execute([$cid]);
    out(['ok'=>true,'departments'=>$rows->fetchAll()]);
}

// ── All colleges + departments for admin management ──────────────────
case 'colleges_full': {
    require_admin();
    $colleges = db()->query("SELECT * FROM colleges ORDER BY sort_order, name")->fetchAll();
    foreach ($colleges as &$col) {
        $d = db()->prepare("SELECT * FROM departments WHERE college_id=? ORDER BY sort_order, name");
        $d->execute([$col['id']]);
        $col['departments'] = $d->fetchAll();
    }
    out(['ok'=>true,'colleges'=>$colleges]);
}

// ── College CRUD ─────────────────────────────────────────────────────
case 'college_save': {
    require_admin();
    $d    = body_json();
    $id   = (int)($d['id']   ?? 0);
    $name = trim($d['name']  ?? '');
    if (!$name) fail('College name is required');
    if ($id) {
        db()->prepare('UPDATE colleges SET name=? WHERE id=?')->execute([$name,$id]);
    } else {
        db()->prepare('INSERT INTO colleges (name) VALUES (?)')->execute([$name]);
        $id = (int)db()->lastInsertId();
    }
    out(['ok'=>true,'id'=>$id]);
}
case 'college_delete': {
    require_admin();
    $d  = body_json();
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('Invalid id');
    db()->prepare('DELETE FROM colleges WHERE id=?')->execute([$id]);
    out(['ok'=>true]);
}

// ── Department CRUD ──────────────────────────────────────────────────
case 'department_save': {
    require_admin();
    $d    = body_json();
    $id   = (int)($d['id']         ?? 0);
    $cid  = (int)($d['college_id'] ?? 0);
    $name = trim($d['name']        ?? '');
    if (!$name || !$cid) fail('College and department name are required');
    if ($id) {
        db()->prepare('UPDATE departments SET name=?,college_id=? WHERE id=?')->execute([$name,$cid,$id]);
    } else {
        db()->prepare('INSERT INTO departments (college_id,name) VALUES (?,?)')->execute([$cid,$name]);
        $id = (int)db()->lastInsertId();
    }
    out(['ok'=>true,'id'=>$id]);
}
case 'department_delete': {
    require_admin();
    $d  = body_json();
    $id = (int)($d['id'] ?? 0);
    if (!$id) fail('Invalid id');
    db()->prepare('DELETE FROM departments WHERE id=?')->execute([$id]);
    out(['ok'=>true]);
}

default: fail('Unknown action');
}
