<?php
/**
 * GradeFlow - Installer, Upgrade Runner & First-Time Setup
 * ---------------------------------------------------------
 * Visit this page to:
 *   • Fresh install  : create the database and all tables
 *   • Upgrade        : safely add new columns/tables from newer versions
 *   • First-time setup: create the first Admin account
 *
 * Every migration step is IDEMPOTENT — safe to run multiple times.
 */
require_once __DIR__ . '/config/config.php';

$steps   = [];
$pdo     = null;
$allOk   = false;
$dbReady = false;

function step(string $label, bool $ok, string $detail = ''): void {
    global $steps; $steps[] = compact('label','ok','detail');
}

// ---- 1. PHP version ----
step('PHP ' . PHP_VERSION . ' (need 7.4+)', version_compare(PHP_VERSION, '7.4', '>='));

// ---- 2. PDO MySQL ----
step('PDO MySQL extension', extension_loaded('pdo_mysql'),
     'Enable pdo_mysql in php.ini and restart Apache');

// ---- 3. MySQL connection ----
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    step('MySQL connection', true, DB_HOST . ':' . DB_PORT . ' as ' . DB_USER);
} catch (Throwable $e) {
    step('MySQL connection', false, $e->getMessage());
}

if ($pdo) {
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        step('Database `' . DB_NAME . '`', true, 'Ready');
        $dbReady = true;
    } catch (Throwable $e) { step('Database', false, $e->getMessage()); }
}

$ran = []; $errors = [];
function migrate(PDO $pdo, string $label, string $sql): void {
    global $ran, $errors;
    try { $pdo->exec($sql); $ran[] = $label; }
    catch (PDOException $e) {
        if (!in_array((int)$e->errorInfo[1], [1060,1061,1050,1062]))
            $errors[] = "$label: " . $e->getMessage();
        else $ran[] = "$label (already up to date)";
    }
}

if ($dbReady) {
    // Core tables
    migrate($pdo, 'teachers table', "CREATE TABLE IF NOT EXISTS teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('teacher','admin') NOT NULL DEFAULT 'teacher',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    migrate($pdo, 'classes table', "CREATE TABLE IF NOT EXISTS classes (
        id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL,
        subject_code VARCHAR(50), subject_name VARCHAR(150) NOT NULL,
        section VARCHAR(50), term_system VARCHAR(50) DEFAULT 'Prelim,Midterm,Finals',
        school_year VARCHAR(20), passing_grade DECIMAL(5,2) DEFAULT 75.00,
        cutoff DECIMAL(5,2) DEFAULT 50.00, zero_equiv DECIMAL(5,2) DEFAULT 65.00,
        use_transmutation TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'criteria table', "CREATE TABLE IF NOT EXISTS criteria (
        id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL,
        term VARCHAR(50) NOT NULL DEFAULT 'All', name VARCHAR(120) NOT NULL,
        weight DECIMAL(6,3) NOT NULL, sort_order INT DEFAULT 0,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'activities table', "CREATE TABLE IF NOT EXISTS activities (
        id INT AUTO_INCREMENT PRIMARY KEY, criterion_id INT NOT NULL,
        label VARCHAR(60) NOT NULL, perfect_score DECIMAL(10,2) NOT NULL DEFAULT 100.00,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (criterion_id) REFERENCES criteria(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'term_weights table', "CREATE TABLE IF NOT EXISTS term_weights (
        id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL,
        term VARCHAR(50) NOT NULL, weight DECIMAL(6,3) NOT NULL,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'students table', "CREATE TABLE IF NOT EXISTS students (
        id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL,
        student_no VARCHAR(50), last_name VARCHAR(100) NOT NULL,
        first_name VARCHAR(100) NOT NULL, email VARCHAR(150),
        sort_order INT DEFAULT 0,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'scores table', "CREATE TABLE IF NOT EXISTS scores (
        id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL,
        activity_id INT NOT NULL, raw_score DECIMAL(10,2),
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cell (student_id, activity_id),
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'analysis_cache table', "CREATE TABLE IF NOT EXISTS analysis_cache (
        id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL,
        payload JSON, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'attendance_sessions table', "CREATE TABLE IF NOT EXISTS attendance_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, class_id INT NOT NULL,
        term VARCHAR(50) NOT NULL, session_date DATE, label VARCHAR(80) NOT NULL,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'attendance_records table', "CREATE TABLE IF NOT EXISTS attendance_records (
        id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL,
        student_id INT NOT NULL, status ENUM('P','A','L') NOT NULL DEFAULT 'P',
        UNIQUE KEY uniq_record (session_id, student_id),
        FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'school_settings table', "CREATE TABLE IF NOT EXISTS school_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(80) NOT NULL UNIQUE, setting_val TEXT
    ) ENGINE=InnoDB");

    // Column upgrades for existing installs
    migrate($pdo, 'Upgrade: teachers.role',
        "ALTER TABLE teachers ADD COLUMN role ENUM('teacher','admin') NOT NULL DEFAULT 'teacher'");
    migrate($pdo, 'Upgrade: classes.cutoff',
        "ALTER TABLE classes ADD COLUMN cutoff DECIMAL(5,2) DEFAULT 50.00");
    migrate($pdo, 'Upgrade: classes.zero_equiv',
        "ALTER TABLE classes ADD COLUMN zero_equiv DECIMAL(5,2) DEFAULT 65.00");
    migrate($pdo, 'Upgrade: classes.sort_order',
        "ALTER TABLE classes ADD COLUMN sort_order INT DEFAULT 0");
    // Initialize sort_order for existing classes if not set
    $pdo->exec("UPDATE classes SET sort_order=id WHERE sort_order=0 OR sort_order IS NULL");
    migrate($pdo, 'Upgrade: teachers.approved',
        "ALTER TABLE teachers ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0");
    // Auto-approve all existing teachers and admins so current accounts keep working
    $pdo->exec("UPDATE teachers SET approved=1 WHERE approved=0");
    migrate($pdo, 'Upgrade: classes.semester',
        "ALTER TABLE classes ADD COLUMN semester VARCHAR(20) DEFAULT ''");
    migrate($pdo, 'Upgrade: classes.is_archived',
        "ALTER TABLE classes ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    // Add index to speed up filtering by teacher + archived status
    migrate($pdo, 'Upgrade: classes.idx_teacher_archived',
        "ALTER TABLE classes ADD INDEX idx_teacher_archived (teacher_id, is_archived)");
    migrate($pdo, 'Upgrade: teachers.college',
        "ALTER TABLE teachers ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT ''");
    migrate($pdo, 'Upgrade: teachers.department',
        "ALTER TABLE teachers ADD COLUMN department VARCHAR(150) NOT NULL DEFAULT ''");
    migrate($pdo, 'Upgrade: teachers.role_chair',
        "ALTER TABLE teachers MODIFY COLUMN role ENUM('teacher','admin','chair') NOT NULL DEFAULT 'teacher'");
    // College/Department lookup tables
    migrate($pdo, 'Create colleges table', "CREATE TABLE IF NOT EXISTS colleges (
      id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL UNIQUE,
      sort_order INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    migrate($pdo, 'Create departments table', "CREATE TABLE IF NOT EXISTS departments (
      id INT AUTO_INCREMENT PRIMARY KEY, college_id INT NOT NULL,
      name VARCHAR(200) NOT NULL, sort_order INT DEFAULT 0,
      FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    migrate($pdo, 'Create chair_assignments table', "CREATE TABLE IF NOT EXISTS chair_assignments (
      id INT AUTO_INCREMENT PRIMARY KEY, chair_id INT NOT NULL,
      college VARCHAR(150) NOT NULL, department VARCHAR(150) NOT NULL,
      FOREIGN KEY (chair_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // user_settings table (per-user colors, fonts)
    migrate($pdo, 'user_settings table', "CREATE TABLE IF NOT EXISTS user_settings (
        id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL,
        setting_key VARCHAR(80) NOT NULL, setting_val TEXT,
        UNIQUE KEY uniq_user_setting (teacher_id, setting_key),
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // school_settings = GLOBAL ONLY (school name, logo, subtitle)
    $ins = $pdo->prepare("INSERT IGNORE INTO school_settings (setting_key,setting_val) VALUES (?,?)");
    foreach ([
        'school_name'=>'My School','school_address'=>'',
        'logo_path'=>'','system_subtitle'=>'GradeFlow Grading System',
    ] as $k=>$v) $ins->execute([$k,$v]);
    // Clean out any per-user keys that may have been stored globally in older installs
    $pdo->exec("DELETE FROM school_settings WHERE setting_key IN (
        'web_accent','web_ink','web_text_color','web_font',
        'pdf_header_bg','pdf_accent_rgb','pdf_pass_rgb','pdf_fail_rgb',
        'pdf_text_rgb','pdf_title_font','pdf_body_font'
    )");

    // Uploads directory
    if (!is_dir(__DIR__.'/uploads')) @mkdir(__DIR__.'/uploads', 0755, true);
    step('uploads/ directory', is_dir(__DIR__.'/uploads'));

    step('Database tables & migrations', empty($errors),
         count($ran).' step(s) completed'.($errors?' — ERRORS: '.implode('; ',$errors):''));

    // Python — auto-detect across Windows/Mac/Linux
    $candidates = PHP_OS_FAMILY === 'Windows'
        ? ['python', 'py', 'python3']
        : ['python3', 'python'];
    $pyFound = ''; $pyVer = '';
    foreach ($candidates as $cmd) {
        $out = @shell_exec(escapeshellcmd($cmd) . ' --version 2>&1');
        if ($out && stripos($out, 'python') !== false) { $pyFound = $cmd; $pyVer = trim($out); break; }
    }
    $pyOk = $pyFound !== '';
    step('Python (for AI analysis)', $pyOk,
         $pyOk ? "Found: $pyFound — $pyVer"
               : 'Python not found. Download from python.org and tick "Add Python to PATH". AI analysis will be unavailable until installed.');

    // FPDF
    step('FPDF library', file_exists(__DIR__.'/includes/fpdf.php'));

    // Account summary — always informational, never a failure
    $tCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE role='teacher'")->fetchColumn();
    $aCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE role='admin'")->fetchColumn();
    step("Teacher accounts: $tCount", true,
         $tCount === 0 ? 'No teachers yet — use register.php to create teacher accounts' : '');
    step("Admin accounts: $aCount", true,
         $aCount === 0 ? 'No admin yet — create one in the section below' : $aCount . ' admin account(s) ready');
}

// $allOk = true only when all ENVIRONMENT checks pass.
// Missing admin accounts are NOT an environment failure — they show a setup form instead.
$envSteps = array_filter($steps, fn($s) => !str_starts_with($s['label'], 'Teacher accounts') && !str_starts_with($s['label'], 'Admin accounts'));
$allOk = $dbReady && empty($errors) && !in_array(false, array_column(array_values($envSteps), 'ok'), true);

// ---- Handle admin creation form submission ----
$createMsg = ''; $createOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin']) && $dbReady) {
    // Security guard: install.php can ONLY create the first admin
    $existingAdmins = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE role='admin'")->fetchColumn();
    if ($existingAdmins > 0) {
        $createMsg = 'An admin account already exists. Additional admin accounts can only be created from within the Admin Settings panel after logging in.';
    } else {
    $name  = trim($_POST['admin_name']  ?? '');
    $email = trim($_POST['admin_email'] ?? '');
    $pass  = $_POST['admin_pass']  ?? '';
    $pass2 = $_POST['admin_pass2'] ?? '';

    if (!$name || !$email || !$pass) {
        $createMsg = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createMsg = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 6) {
        $createMsg = 'Password must be at least 6 characters.';
    } elseif ($pass !== $pass2) {
        $createMsg = 'Passwords do not match.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM teachers WHERE email=?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $createMsg = 'An account with that email already exists.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO teachers (full_name,email,password_hash,role) VALUES (?,?,?,?)')
                ->execute([$name, $email, $hash, 'admin']);
            $createOk = true;
            $createMsg = 'Admin account created for ' . htmlspecialchars($email) . '. You can now log in.';
            // recount
            $aCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers WHERE role='admin'")->fetchColumn();
        }
    }
    } // end else (no existing admin)
}
?>
<!doctype html><html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install / Setup — GradeFlow</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-wrap" style="align-items:flex-start;padding:32px 20px;min-height:100vh">
  <div style="width:100%;max-width:640px;margin:0 auto">

    <div style="text-align:center;margin-bottom:24px">
      <div style="font-family:'Fraunces',serif;font-size:2.2rem;font-weight:600">GradeFlow</div>
      <div class="muted">Installer &amp; Upgrade Runner</div>
    </div>

    <div class="help-note" style="margin-bottom:20px">
      Run this page on fresh install <strong>and</strong> every time you update GradeFlow.
      Every step is safe to repeat — it only adds what's missing.
    </div>

    <!-- Environment checks -->
    <div class="card" style="margin-bottom:20px">
      <h2 style="margin-top:0;font-size:1.2rem">Environment &amp; Migration Checks</h2>
      <?php foreach ($steps as $s): ?>
        <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 10px;margin-bottom:6px;
                    background:<?= $s['ok']?'var(--green-soft)':'var(--red-soft)'?>;
                    border-radius:8px;border-left:3px solid <?= $s['ok']?'var(--green)':'var(--red)'?>">
          <span style="font-size:1rem"><?= $s['ok']?'✅':'❌'?></span>
          <div>
            <div style="font-weight:500;color:<?= $s['ok']?'var(--green)':'var(--red)'?>;font-size:.9rem">
              <?= htmlspecialchars($s['label']) ?>
            </div>
            <?php if($s['detail']): ?>
              <div class="muted" style="font-size:.8rem"><?= htmlspecialchars($s['detail']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$allOk): ?>
      <!-- Real environment failure — DB not ready or migrations broken -->
      <div class="card" style="margin-bottom:20px">
        <div class="alert alert-error">
          Some checks failed. Fix the items marked ❌ above, then
          <a href="install.php">refresh this page</a>.
        </div>
        <p class="muted">
          Most issues are solved by editing <strong>config/config.php</strong>
          (database host, username, password, and Python path).
          On XAMPP the defaults are host <code>127.0.0.1</code>, user <code>root</code>,
          empty password.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($dbReady): ?>

      <!-- ============================================================
           ADMIN ACCOUNT SETUP — only when NO admin exists yet
           ============================================================ -->
      <?php if (isset($aCount) && $aCount === 0): ?>
      <div class="card" style="margin-bottom:20px;border:2px solid var(--amber)">
        <h2 style="margin-top:0;color:var(--amber)">&#9888; Create Your Admin Account</h2>
        <p class="muted" style="margin-top:0">
          No admin account exists yet. Create one now to manage teachers and view all records.
          The admin account is separate from teacher accounts — it has read-only access to all classes.
        </p>
        <div class="help-note" style="margin-bottom:14px">
          <strong>Security note:</strong> Only one admin account can be created from this page.
          Additional admin accounts can only be created from within the Admin Settings panel after login.
        </div>
        <?php if ($createMsg): ?>
          <div class="alert <?= $createOk?'alert-ok':'alert-error' ?>"><?= htmlspecialchars($createMsg) ?></div>
        <?php endif; ?>
        <?php if (!$createOk): ?>
        <form method="post">
          <input type="hidden" name="create_admin" value="1">
          <div class="row">
            <div class="field"><label>Full Name *</label>
              <input name="admin_name" required placeholder="e.g. Dr. Santos"
                     value="<?= htmlspecialchars($_POST['admin_name']??'') ?>"></div>
            <div class="field"><label>Email Address *</label>
              <input name="admin_email" type="email" required placeholder="admin@school.edu"
                     value="<?= htmlspecialchars($_POST['admin_email']??'') ?>"></div>
          </div>
          <div class="row">
            <div class="field"><label>Password * <span class="muted">(min 6 chars)</span></label>
              <input name="admin_pass" type="password" required placeholder="••••••••"></div>
            <div class="field"><label>Confirm Password *</label>
              <input name="admin_pass2" type="password" required placeholder="••••••••"></div>
          </div>
          <button class="btn btn-primary" type="submit">Create Admin Account</button>
        </form>
        <?php else: ?>
          <a class="btn btn-primary" href="login.php">Go to Login →</a>
        <?php endif; ?>
      </div>

      <?php else: /* aCount > 0 — admin already exists, just show status */ ?>
      <div class="card" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="flex:1">
            <h2 style="margin:0">&#10003; Admin Account Active</h2>
            <div class="muted" style="font-size:.88rem;margin-top:4px">
              <?= $aCount ?> admin account<?= $aCount>1?'s':'' ?> configured.
              To create additional admin accounts, log in as Admin and go to <strong>Settings</strong>.
            </div>
          </div>
          <span class="pill pill-green"><?= $aCount ?> admin<?= $aCount>1?'s':'' ?></span>
          <a class="btn btn-primary" href="login.php">Go to Login →</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($allOk): ?>
      <!-- Next steps — only when everything is fully green -->
      <div class="card" style="margin-bottom:20px">
        <h2 style="margin-top:0">Quick Links</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn btn-primary" href="login.php">Go to Login →</a>
          <a class="btn btn-ghost"   href="register.php">Register a Teacher Account</a>
          <a class="btn btn-ghost"   href="install.php">Re-run Checks</a>
        </div>
      </div>
      <?php endif; ?>

      <!-- How accounts work — always show when DB ready -->
      <div class="card" style="background:var(--blue-soft);border-color:var(--blue)">
        <h2 style="margin-top:0;color:var(--blue);font-size:1.05rem">How Accounts Work</h2>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <strong>&#128101; Teacher Account</strong>
            <ul class="muted" style="margin:6px 0 0;padding-left:18px;font-size:.88rem;line-height:1.7">
              <li>Created via <a href="register.php">register.php</a></li>
              <li>Manages their own classes</li>
              <li>Enters and edits grades</li>
              <li>Generates PDF reports</li>
              <li>Logs in at <a href="login.php">login.php</a></li>
            </ul>
          </div>
          <div>
            <strong>&#128274; Admin Account</strong>
            <ul class="muted" style="margin:6px 0 0;padding-left:18px;font-size:.88rem;line-height:1.7">
              <li>Created here (install.php)</li>
              <li>Views ALL teachers' classes</li>
              <li>Prints any class's reports</li>
              <li><strong>Cannot edit grades</strong></li>
              <li>Logs in at <a href="login.php">login.php</a> (same page)</li>
            </ul>
          </div>
        </div>
      </div>

    <?php endif; /* dbReady */ ?>

  </div><!-- /max-width -->
</div>
</body></html>
