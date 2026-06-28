<?php
/**
 * GradeFlow - Auth helpers (teacher + admin roles)
 */
require_once __DIR__ . '/../config/config.php';

function start_session() {
    if (session_status() === PHP_SESSION_NONE) session_start();
}
function current_teacher_id(): ?int { start_session(); return $_SESSION['teacher_id'] ?? null; }
function current_role(): string      { start_session(); return $_SESSION['role'] ?? 'teacher'; }
function is_admin(): bool            { return current_role() === 'admin'; }
function is_chair(): bool            { return current_role() === 'chair'; }
function is_teacher(): bool          { return current_role() === 'teacher'; }

/** Return college/department of current user (populated on login). */
function current_college(): string    { start_session(); return $_SESSION['college']    ?? ''; }
function current_department(): string { start_session(); return $_SESSION['department'] ?? ''; }

/** All college+department pairs assigned to this chair (from chair_assignments table). */
function current_assignments(): array {
    start_session();
    if (isset($_SESSION['chair_assignments'])) return $_SESSION['chair_assignments'];
    if (!is_chair() || !current_teacher_id()) return [];
    $stmt = db()->prepare('SELECT college, department FROM chair_assignments WHERE chair_id=?');
    $stmt->execute([current_teacher_id()]);
    $rows = $stmt->fetchAll(\PDO::FETCH_NUM) ?: [];
    // Also include the primary college/department on the teachers row (backwards compat)
    $primary = [current_college(), current_department()];
    if ($primary[0] !== '' || $primary[1] !== '') {
        $rows[] = $primary;
    }
    // Deduplicate
    $seen = []; $unique = [];
    foreach ($rows as $r) {
        $k = $r[0].'|'.$r[1];
        if (!isset($seen[$k])) { $seen[$k]=true; $unique[] = $r; }
    }
    $_SESSION['chair_assignments'] = $unique;
    return $unique;
}

function require_login() {
    if (!current_teacher_id()) { header('Location: index.php'); exit; }
}
function require_teacher() {
    require_login();
    if (!is_teacher()) {
        header('Location: ' . (is_admin() ? 'admin.php' : 'chair.php'));
        exit;
    }
}
function require_admin() {
    require_login();
    if (!is_admin()) { header('Location: ' . (is_chair() ? 'chair.php' : 'dashboard.php')); exit; }
}
function require_chair() {
    require_login();
    if (!is_chair()) { header('Location: ' . (is_admin() ? 'admin.php' : 'dashboard.php')); exit; }
}

function login_user(string $email, string $password): array {
    $stmt = db()->prepare('SELECT id, password_hash, role, full_name, approved, college, department FROM teachers WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $role = $row['role'] ?? 'teacher';
        // Teachers must be approved; admins and chairs always pass
        if ($role === 'teacher' && empty($row['approved'])) {
            return ['ok' => false, 'pending' => true];
        }
        start_session();
        $_SESSION['teacher_id'] = (int)$row['id'];
        $_SESSION['role']       = $role;
        $_SESSION['full_name']  = $row['full_name'];
        $_SESSION['college']    = $row['college']    ?? '';
        $_SESSION['department'] = $row['department'] ?? '';
        unset($_SESSION['chair_assignments']); // force refresh on next request
        return ['ok' => true, 'role' => $role];
    }
    return ['ok' => false];
}

/** Backwards-compatible alias used by some internal calls. */
function login_teacher(string $email, string $password): bool {
    return login_user($email, $password)['ok'];
}

/**
 * Register a new teacher or admin account.
 * Handles the case where the `role` column may not yet exist on older
 * installations (safe fallback: inserts without role, defaults to 'teacher').
 */
function register_teacher(string $name, string $email, string $password, string $role = 'teacher',
                          string $college = '', string $department = ''): array {
    if (strlen($password) < 6) return [false, 'Password must be at least 6 characters.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Please enter a valid email address.'];
    $name = trim($name);
    if ($name === '') return [false, 'Full name is required.'];

    $pdo = db();
    $chk = $pdo->prepare('SELECT id FROM teachers WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch()) return [false, 'An account with that email already exists.'];

    $hash       = password_hash($password, PASSWORD_DEFAULT);
    $actualRole = in_array($role, ['teacher','admin','chair']) ? $role : 'teacher';
    // Admins and chairs are pre-approved; teachers require admin approval
    $approved   = ($actualRole === 'teacher') ? 0 : 1;

    $stmt = $pdo->prepare(
        'INSERT INTO teachers (full_name, email, password_hash, role, approved, college, department)
         VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$name, $email, $hash, $actualRole, $approved,
                    trim($college), trim($department)]);

    $msg = $actualRole === 'teacher'
        ? 'Registration successful! Your account is pending admin approval.'
        : 'Account created successfully.';
    return [true, $msg];
}

/** Check whether a column exists in a table (cached per request). */
function _column_exists(string $table, string $column): bool {
    static $cache = [];
    $key = "$table.$column";
    if (!isset($cache[$key])) {
        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = ?
                   AND COLUMN_NAME  = ?"
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

function logout() { start_session(); $_SESSION = []; session_destroy(); }

/** A teacher owns a class. Admin can access any. Chair can read any class of supervised faculty. */
function owns_class(int $classId): bool {
    if (!current_teacher_id()) return false;
    if (is_admin()) {
        $s = db()->prepare('SELECT 1 FROM classes WHERE id=?');
        $s->execute([$classId]); return (bool)$s->fetchColumn();
    }
    if (is_chair()) {
        $assignments = current_assignments();
        if (!$assignments) return false;
        // Single query with OR per assignment instead of one query per assignment
        $clauses = implode(' OR ', array_fill(0, count($assignments), '(t.college=? AND t.department=?)'));
        $params  = [$classId];
        foreach ($assignments as [$col, $dept]) { $params[] = $col; $params[] = $dept; }
        $s = db()->prepare(
            "SELECT 1 FROM classes c JOIN teachers t ON t.id=c.teacher_id
             WHERE c.id=? AND t.role='teacher' AND ($clauses)");
        $s->execute($params);
        return (bool)$s->fetchColumn();
    }
    $s = db()->prepare('SELECT 1 FROM classes WHERE id=? AND teacher_id=?');
    $s->execute([$classId, current_teacher_id()]); return (bool)$s->fetchColumn();
}

/** Check if a specific teacher is supervised by the current chair. */
function chair_supervises_teacher(int $teacherId): bool {
    if (!is_chair()) return false;
    $assignments = current_assignments();
    if (!$assignments) return false;
    $s = db()->prepare('SELECT college, department FROM teachers WHERE id=? AND role="teacher"');
    $s->execute([$teacherId]);
    $t = $s->fetch();
    if (!$t) return false;
    foreach ($assignments as [$col, $dept]) {
        if ($t['college'] === $col && $t['department'] === $dept) return true;
    }
    return false;
}

/** Can write (edit grades, criteria, students)? Only the owning teacher. */
function can_write_class(int $classId): bool {
    if (is_admin() || is_chair()) return false;
    return owns_class($classId);
}

/**
 * Load settings for the current context.
 * GLOBAL keys (school_name, logo, subtitle) come from school_settings.
 * PERSONAL keys (colors, fonts) come from user_settings for the current user.
 * Personal settings overlay global ones, so the same key can be global
 * (e.g. a default accent color for the school) or personal.
 *
 * Returns default values for any keys not yet customised.
 */
function school_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // System defaults (used when a user has not saved their own preferences yet)
    static $personalDefaults = [
        'web_accent'     => '#c97b1f',
        'web_ink'        => '#1d2433',
        'web_text_color' => '#1d2433',
        'web_font'       => 'Outfit',
        // Extended web color controls
        'web_nav_text'   => '#f5f0e6',   // topbar text/links (light on dark bg)
        'web_paper'      => '#f5f0e6',   // page background
        'web_card'       => '#fffdf8',   // card/panel background
        'web_line'       => '#d9cfba',   // borders and dividers
        'web_muted'      => '#495066',   // secondary/muted text
        'web_link'       => '#c97b1f',   // hyperlink color (defaults to accent)
        // Gradebook highlight colors
        'hl_grade'           => '', 'hl_grade_txt'       => '',
        'hl_equiv'           => '#d8ecdf', 'hl_equiv_txt'  => '#155724',
        'hl_final'           => '', 'hl_final_txt'        => '#ffffff',
        'hl_fail_bg'         => '#f8d7da', 'hl_fail_txt'   => '#721c24',
        'hl_term_col'        => '', 'hl_term_col_txt'     => '',
        'hl_remarks_pass'    => '#d4edda', 'hl_remarks_pass_txt' => '#155724',
        'hl_remarks_fail'    => '#f8d7da', 'hl_remarks_fail_txt' => '#721c24',
        'hl_remarks_inc'     => '#fff3cd', 'hl_remarks_inc_txt'  => '#856404',
        'hl_ai_btn'          => '', 'hl_ai_btn_txt'       => '#ffffff',
        'tbl_header'         => '', 'tbl_header_txt'      => '',
        'tbl_sub_header'     => '', 'tbl_sub_header_txt'  => '',
        'tbl_avg_header'     => '', 'tbl_avg_header_txt'  => '',
        'tbl_row_odd'        => '', 'tbl_row_even'        => '',
        'tbl_row_odd_txt'    => '', 'tbl_row_even_txt'    => '',
        'tbl_name_bg'        => '', 'tbl_name_txt'        => '',
        'tbl_name_even_bg'   => '', 'tbl_computed_bg'     => '',
        'tbl_wt_color'       => '', 'tbl_ai_cell_bg'      => '',
        'tbl_ai_cell_txt'    => '',
        'tbl_raw_bg'         => '', 'tbl_raw_txt'         => '',
        'tbl_avg_bg'         => '', 'tbl_avg_txt'         => '',
        'tbl_ws_bg'          => '', 'tbl_ws_txt'          => '',
        'pdf_header_bg'  => '29,36,51',
        'pdf_accent_rgb' => '201,123,31',
        'pdf_pass_rgb'   => '47,125,84',
        'pdf_fail_rgb'   => '178,59,59',
        'pdf_text_rgb'   => '0,0,0',
        'pdf_title_font' => 'Times',
        'pdf_body_font'  => 'Helvetica',
        'pdf_paper'      => 'folio',
        'pdf_font_size'  => '8',
        'pdf_term_paper' => 'folio-L',
        'pdf_final_paper'=> 'folio-P',
        // Per-area text/fill colors
        'pdf_hdr_txt'    => '245,240,230',
        'pdf_subtitle_txt'=> '201,123,31',
        'pdf_title_txt'  => '245,240,230',
        'pdf_subj_txt'   => '0,0,0',
        'pdf_info_txt'   => '60,60,80',
        'pdf_th_bg'      => '29,36,51',
        'pdf_th_txt'     => '245,240,230',
        'pdf_th2_bg'     => '45,65,45',
        'pdf_th2_txt'    => '245,240,230',
        'pdf_row_odd'    => '255,255,255',
        'pdf_row_even'   => '248,246,240',
        'pdf_border'     => '200,193,180',
        'pdf_equiv'      => '216,236,223',
        'pdf_equiv_txt'  => '30,100,60',
        'pdf_ws'         => '240,217,181',
        'pdf_ws_txt'     => '100,70,10',
        'pdf_grade'      => '240,217,181',
        'pdf_grade_txt'  => '80,50,10',
        'pdf_inc'        => '220,220,220',
        'pdf_inc_txt'    => '80,80,80',
        'pdf_term_col'   => '255,255,255',
        'pdf_term_col_txt'=> '0,0,0',
        'pdf_final_col'  => '255,255,255',
        'pdf_final_col_txt'=> '0,0,0',
        'pdf_pass_txt'   => '255,255,255',
        'pdf_fail_txt'   => '255,255,255',
        'pdf_footer_txt' => '160,160,160',
        'pdf_sig_txt'    => '0,0,0',
        'pdf_page_bg'    => '255,255,255',
        // Report header customization (per-teacher)
        'pdf_schedule'           => '',
        'pdf_teacher_logo_path'  => '',
        // Header line font settings
        'pdf_hdr_sem_font'  => 'Helvetica', 'pdf_hdr_sem_size'  => '9',   'pdf_hdr_sem_style'  => '',
        'pdf_hdr_lbl_font'  => 'Times',     'pdf_hdr_lbl_size'  => '11',  'pdf_hdr_lbl_style'  => 'B',
        'pdf_hdr_crs_font'  => 'Helvetica', 'pdf_hdr_crs_size'  => '9.5', 'pdf_hdr_crs_style'  => '',
        'pdf_hdr_sec_font'  => 'Helvetica', 'pdf_hdr_sec_size'  => '9.5', 'pdf_hdr_sec_style'  => 'B',
        'pdf_hdr_sch_font'  => 'Helvetica', 'pdf_hdr_sch_size'  => '9',   'pdf_hdr_sch_style'  => 'B',
        // AI Intervention Report colors — all text black by default
        'ai_hdr_bg'          => '29,36,51',
        'ai_hdr_school_txt'  => '0,0,0',
        'ai_hdr_addr_txt'    => '0,0,0',
        'ai_hdr_title_txt'   => '0,0,0',
        'ai_section_bg'      => '29,36,51',
        'ai_section_txt'     => '0,0,0',
        'ai_stats_bg'        => '245,243,237',
        'ai_stats_val_txt'   => '0,0,0',
        'ai_stats_lbl_txt'   => '0,0,0',
        'ai_body_txt'        => '0,0,0',
        'ai_info_txt'        => '0,0,0',
        'ai_footer_txt'      => '120,120,120',
        'ai_accent_rgb'      => '201,123,31',
        'ai_page_bg'         => '255,255,255',
    ];
    $out = $personalDefaults;   // start with defaults
    try {
        // 1. Load global settings (school name, logo, subtitle)
        $rows = db()->query('SELECT setting_key, setting_val FROM school_settings')->fetchAll();
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_val'];
        // 2. Overlay with this user's personal settings (if logged in)
        $tid = current_teacher_id();
        if ($tid) {
            $stmt = db()->prepare('SELECT setting_key, setting_val FROM user_settings WHERE teacher_id=?');
            $stmt->execute([$tid]);
            foreach ($stmt->fetchAll() as $r) $out[$r['setting_key']] = $r['setting_val'];
        }
    } catch (\Throwable $e) { /* table may not exist yet on first install */ }
    $cache = $out;
    return $cache;
}
