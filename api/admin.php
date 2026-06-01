<?php
/**
 * GradeFlow - Admin API
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();   // all admin API actions require a valid session
header('Content-Type: application/json');

function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail($m,$c=400){ http_response_code($c); out(['ok'=>false,'error'=>$m]); }
function body_json(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/** Replace all assignments for a chair (used by create and edit). */
function _save_chair_assignments(int $chairId, array $assignments): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM chair_assignments WHERE chair_id=?')->execute([$chairId]);
    $stmt = $pdo->prepare('INSERT INTO chair_assignments (chair_id,college,department) VALUES (?,?,?)');
    $seen = [];
    foreach ($assignments as $a) {
        $col  = trim($a['college']    ?? '');
        $dept = trim($a['department'] ?? '');
        if ($col === '' && $dept === '') continue;
        $key = $col.'|'.$dept;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $stmt->execute([$chairId, $col, $dept]);
    }
}

// ── mysqldump auto-detection + PDO fallback ──────────────────────────

function resolve_mysqldump(): ?string {
    static $resolved = null;
    if ($resolved !== null) return $resolved === '' ? null : $resolved;
    $isWin = PHP_OS_FAMILY === 'Windows';
    $candidates = $isWin ? [
        'mysqldump',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp7\\mysql\\bin\\mysqldump.exe',
        'C:\\xampp8\\mysql\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-5.7.33-winx64\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
    ] : [
        'mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
        '/Applications/XAMPP/xamppfiles/bin/mysqldump',
    ];
    // Dynamic scan for Laragon / XAMPP variants on Windows
    if ($isWin) {
        foreach (glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe') ?: [] as $p) $candidates[] = $p;
        foreach (glob('C:\\xampp*\\mysql\\bin\\mysqldump.exe') ?: [] as $p) $candidates[] = $p;
    }
    foreach ($candidates as $bin) {
        $test = @shell_exec(escapeshellcmd($bin) . ' --version 2>&1');
        if ($test && stripos($test, 'mysqldump') !== false) { $resolved = $bin; return $bin; }
    }
    $resolved = ''; return null;
}

function run_mysqldump(): string {
    $bin = resolve_mysqldump();
    if ($bin !== null) {
        $host    = escapeshellarg(DB_HOST);
        $port    = escapeshellarg(DB_PORT);
        $user    = escapeshellarg(DB_USER);
        $dbname  = escapeshellarg(DB_NAME);
        $passArg = DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '';
        $cmd = escapeshellcmd($bin)
             . " --host={$host} --port={$port} --user={$user} {$passArg}"
             . " --single-transaction --add-drop-table --complete-insert"
             . " --skip-comments {$dbname} 2>&1";
        ob_start(); passthru($cmd, $code); $out = ob_get_clean();
        if ($code === 0 && strlen($out) > 100) return $out;
    }
    return pdo_dump();   // fallback: pure PHP/PDO — no external binary needed
}

function pdo_dump(): string {
    $pdo = db();
    $sql = "SET FOREIGN_KEY_CHECKS=0;

";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $qt = "`{$table}`";
        $create = $pdo->query("SHOW CREATE TABLE {$qt}")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS {$qt};
" . $create[1] . ";

";
        $rows = $pdo->query("SELECT * FROM {$qt}")->fetchAll(PDO::FETCH_NUM);
        if ($rows) {
            $cols    = $pdo->query("SHOW COLUMNS FROM {$qt}")->fetchAll(PDO::FETCH_COLUMN);
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            foreach (array_chunk($rows, 500) as $chunk) {
                $vals = array_map(function($row) use ($pdo) {
                    return '(' . implode(', ', array_map(function($v) use ($pdo) {
                        return $v === null ? 'NULL' : (is_numeric($v) ? $v : $pdo->quote($v));
                    }, $row)) . ')';
                }, $chunk);
                $sql .= "INSERT INTO {$qt} ({$colList}) VALUES
" . implode(",
", $vals) . ";
";
            }
            $sql .= "
";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;
";
    return $sql;
}

$action = $_GET['action'] ?? '';
$pdo = db();

switch ($action) {

// List all teachers (excluding admins)
case 'teachers': {
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT t.id, t.full_name, t.email, t.created_at, t.approved,
              COUNT(DISTINCT c.id) AS class_count,
              COUNT(DISTINCT s.id) AS student_count
            FROM teachers t
            LEFT JOIN classes c  ON c.teacher_id = t.id
            LEFT JOIN students s ON s.class_id   = c.id
            WHERE t.role = 'teacher'";
    $params = [];
    if ($q) { $sql .= " AND (t.full_name LIKE ? OR t.email LIKE ?)"; $params = ["%$q%","%$q%"]; }
    $sql .= " GROUP BY t.id ORDER BY t.approved ASC, t.full_name";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $teachers = $stmt->fetchAll();
    $pending = count(array_filter($teachers, fn($t) => !$t['approved']));
    out(['ok'=>true,'teachers'=>$teachers,'pending_count'=>$pending]);
}

// List classes for a specific teacher
case 'teacher_classes': {
    $tid = (int)($_GET['teacher_id'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT c.*,
           (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS student_count
         FROM classes c WHERE c.teacher_id=? ORDER BY c.created_at DESC");
    $stmt->execute([$tid]);
    // Also get teacher info
    $t = $pdo->prepare('SELECT full_name, email FROM teachers WHERE id=?');
    $t->execute([$tid]); $teacher=$t->fetch();
    out(['ok'=>true,'teacher'=>$teacher,'classes'=>$stmt->fetchAll()]);
}

// Create a new admin account (admin-only)
case 'create_admin': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($d['name'] ?? '');
    $email = trim($d['email'] ?? '');
    $pass  = $d['password'] ?? '';
    if (!$name || !$email || strlen($pass) < 6) fail('Name, email, and password (min 6 chars) required');
    [$ok,$msg] = register_teacher($name, $email, $pass, 'admin');
    out(['ok'=>$ok,'message'=>$msg]);
}

// List all admin accounts
case 'admins': {
    $stmt = $pdo->query("SELECT id,full_name,email,created_at FROM teachers WHERE role='admin' ORDER BY full_name");
    out(['ok'=>true,'admins'=>$stmt->fetchAll()]);
}

// Delete a teacher account (admin only, cannot delete self)
case 'delete_teacher': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid  = (int)($d['teacher_id'] ?? 0);
    $pass = trim($d['admin_password'] ?? '');
    if (!$tid)  fail('Teacher ID required');
    if (!$pass) fail('Admin password is required');
    if ($tid === current_teacher_id()) fail('Cannot delete your own account');

    // Verify admin's own password
    $adminRow = $pdo->prepare('SELECT password_hash FROM teachers WHERE id=?');
    $adminRow->execute([current_teacher_id()]); $adminHash = $adminRow->fetchColumn();
    if (!$adminHash || !password_verify($pass, $adminHash)) fail('Incorrect admin password');

    // Check target exists and is a teacher
    $tRow = $pdo->prepare('SELECT role FROM teachers WHERE id=?');
    $tRow->execute([$tid]); $tRole = $tRow->fetchColumn();
    if (!$tRole) fail('Teacher not found');
    if ($tRole === 'admin') fail('Cannot delete an admin account');

    // Block deletion if teacher still has classes
    $classCount = (int)$pdo->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id=?')
        ->execute([$tid]) ? $pdo->query("SELECT COUNT(*) FROM classes WHERE teacher_id=$tid")->fetchColumn() : 0;
    $cStmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id=?');
    $cStmt->execute([$tid]); $classCount = (int)$cStmt->fetchColumn();
    if ($classCount > 0) fail("Cannot delete — this teacher still has $classCount class(es). Remove their classes first.");

    $pdo->prepare('DELETE FROM teachers WHERE id=?')->execute([$tid]);
    out(['ok'=>true]);
}

// Approve a pending teacher
case 'approve_teacher': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($d['teacher_id'] ?? 0);
    if (!$tid) fail('Teacher ID required');
    $pdo->prepare('UPDATE teachers SET approved=1 WHERE id=? AND role="teacher"')->execute([$tid]);
    out(['ok'=>true]);
}

// Reject (delete) a pending teacher account
case 'reject_teacher': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($d['teacher_id'] ?? 0);
    if (!$tid) fail('Teacher ID required');
    // Only allow rejecting unapproved accounts
    $row = $pdo->prepare('SELECT approved FROM teachers WHERE id=? AND role="teacher"');
    $row->execute([$tid]); $r = $row->fetch();
    if (!$r) fail('Teacher not found');
    if ($r['approved']) fail('Cannot reject an already-approved teacher. Use reset password instead.');
    $pdo->prepare('DELETE FROM teachers WHERE id=?')->execute([$tid]);
    out(['ok'=>true]);
}

// Reset a teacher's password (admin only)
case 'reset_teacher_password': {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid  = (int)($d['teacher_id'] ?? 0);
    $pass = $d['password'] ?? '';
    if (!$tid) fail('Teacher ID required');
    if (strlen($pass) < 6) fail('Password must be at least 6 characters');
    // Verify target is a teacher (not an admin)
    $row = $pdo->prepare('SELECT role FROM teachers WHERE id=?'); $row->execute([$tid]);
    $role = $row->fetchColumn();
    if (!$role) fail('Teacher not found');
    if ($role === 'admin') fail('Cannot reset another admin\'s password');
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE teachers SET password_hash=? WHERE id=?')->execute([$hash, $tid]);
    out(['ok'=>true]);
}

// ── Backup: dump DB to file + stream as download ─────────────────────
case 'backup_db': {
    require_admin();
    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

    $filename = 'gradeflow_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . '/' . $filename;

    $sql = run_mysqldump();   // auto-detects mysqldump OR falls back to PDO dump

    $header = "-- GradeFlow Database Backup\n"
            . "-- Created:  " . date('Y-m-d H:i:s') . "\n"
            . "-- Database: " . DB_NAME . "\n"
            . "-- Server:   " . DB_HOST . ":" . DB_PORT . "\n"
            . "-- -------------------------------------------------------\n\n";
    $sql = $header . $sql;

    file_put_contents($filepath, $sql);

    ob_end_clean();   // discard any buffered output before streaming
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-store');
    echo $sql;
    exit;
}

// ── List saved backup files ──────────────────────────────────────────
case 'list_backups': {
    require_admin();
    $backupDir = __DIR__ . '/../backups';
    $files = [];
    if (is_dir($backupDir)) {
        foreach (glob($backupDir . '/gradeflow_backup_*.sql') as $f) {
            $files[] = [
                'name'    => basename($f),
                'size'    => filesize($f),
                'created' => filemtime($f),
            ];
        }
        usort($files, fn($a,$b) => $b['created'] - $a['created']);
    }
    out(['ok'=>true,'backups'=>$files]);
}

// ── Delete a saved backup file ───────────────────────────────────────
case 'delete_backup': {
    require_admin();
    $d    = body_json();
    $name = basename($d['name'] ?? '');    // basename strips any path traversal
    if (!preg_match('/^gradeflow_backup_[\d_\-]+\.sql$/', $name)) fail('Invalid filename');
    $path = __DIR__ . '/../backups/' . $name;
    if (!file_exists($path)) fail('File not found');
    unlink($path);
    out(['ok'=>true]);
}

// ── Restore: verify admin password then import uploaded SQL ──────────
case 'restore_db': {
    require_admin();

    // 1. Verify admin password before anything else
    $password = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT password_hash FROM teachers WHERE id=?');
    $stmt->execute([current_teacher_id()]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($password, $hash)) {
        out(['ok'=>false,'error'=>'Incorrect admin password. Restore cancelled.']);
    }

    // 2. Validate uploaded file
    if (empty($_FILES['sqlfile']['tmp_name'])) fail('No file uploaded');
    $tmp  = $_FILES['sqlfile']['tmp_name'];
    $orig = $_FILES['sqlfile']['name'];
    if (!preg_match('/\.sql$/i', $orig)) out(['ok'=>false,'error'=>'Only .sql files are accepted']);
    $sql  = file_get_contents($tmp);
    if (empty($sql) || strlen($sql) < 100) out(['ok'=>false,'error'=>'File appears empty or invalid']);

    // 3. Auto-backup current DB before overwriting
    $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);
    $safeName  = 'gradeflow_pre_restore_' . date('Y-m-d_H-i-s') . '.sql';
    $preSql = run_mysqldump();
    if ($preSql) file_put_contents($backupDir . '/' . $safeName, $preSql);

    // 4. Split SQL into individual statements and execute
    $pdo = db();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // Split on semicolons that are not inside strings (simple split, works for mysqldump output)
        $statements = array_filter(
            array_map('trim', preg_split('/;\s*\n/', $sql)),
            fn($s) => strlen($s) > 2 && !preg_match('/^--/', $s)
        );
        foreach ($statements as $stmt) {
            if (trim($stmt)) $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (\Throwable $e) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        out(['ok'=>false,'error'=>'Restore failed: ' . $e->getMessage()]);
    }

    out(['ok'=>true,'pre_backup'=>$safeName,
         'message'=>'Database restored successfully. Pre-restore backup saved as: '.$safeName]);
}

// ── Download a previously saved backup ───────────────────────────────
case 'download_backup': {
    require_admin();
    $name = basename($_GET['name'] ?? '');
    if (!preg_match('/^gradeflow_(backup|pre_restore)_[\d_\-]+\.sql$/', $name)) fail('Invalid filename');
    $path = __DIR__ . '/../backups/' . $name;
    if (!file_exists($path)) fail('Backup not found');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

// ── Create Program Chair ─────────────────────────────────────────────
case 'create_chair': {
    require_admin();
    $d = body_json();
    $name  = trim($d['full_name']  ?? '');
    $email = trim($d['email']      ?? '');
    $pass  = trim($d['password']   ?? '');
    $assignments = $d['assignments'] ?? [];   // [{college,department}]
    if (!$name || !$email || !$pass) fail('Name, email and password are required');
    // Use first assignment as the primary college/dept on teachers row
    $col  = trim($assignments[0]['college']    ?? '');
    $dept = trim($assignments[0]['department'] ?? '');
    [$ok, $msg] = register_teacher($name, $email, $pass, 'chair', $col, $dept);
    if (!$ok) fail($msg);
    // Save all assignments
    $newId = (int)db()->lastInsertId();
    _save_chair_assignments($newId, $assignments);
    out(['ok'=>true, 'message'=>$msg]);
}

// ── List chairs ──────────────────────────────────────────────────────
case 'chairs': {
    require_admin();
    $rows = db()->query(
        "SELECT id, full_name, email, college, department, created_at
         FROM teachers WHERE role='chair' ORDER BY full_name"
    )->fetchAll();
    foreach ($rows as &$c) {
        $s = db()->prepare('SELECT college, department FROM chair_assignments WHERE chair_id=? ORDER BY id');
        $s->execute([$c['id']]);
        $a = $s->fetchAll();
        $c['assignments'] = $a ?: [['college'=>$c['college'],'department'=>$c['department']]];
    }
    out(['ok'=>true, 'chairs'=>$rows]);
}

// ── Edit a chair (admin only) ────────────────────────────────────────
case 'edit_chair': {
    require_admin();
    $d    = body_json();
    $id   = (int)($d['id'] ?? 0);
    $name = trim($d['full_name']  ?? '');
    $email= trim($d['email']      ?? '');
    $assignments = $d['assignments'] ?? [];
    $pass = $d['password'] ?? '';
    if (!$id || !$name || !$email) fail('Required fields missing');
    $r = db()->prepare("SELECT id FROM teachers WHERE id=? AND role='chair'");
    $r->execute([$id]); if (!$r->fetch()) fail('Chair not found');
    $ck = db()->prepare('SELECT id FROM teachers WHERE email=? AND id!=?');
    $ck->execute([$email, $id]); if ($ck->fetch()) fail('Email already in use');
    $col  = trim($assignments[0]['college']    ?? '');
    $dept = trim($assignments[0]['department'] ?? '');
    if ($pass !== '') {
        if (strlen($pass) < 6) fail('Password must be at least 6 characters');
        db()->prepare('UPDATE teachers SET full_name=?,email=?,password_hash=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$col,$dept,$id]);
    } else {
        db()->prepare('UPDATE teachers SET full_name=?,email=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,$col,$dept,$id]);
    }
    _save_chair_assignments($id, $assignments);
    out(['ok'=>true]);
}

// ── Get assignments for a specific chair ────────────────────────────
case 'get_chair_assignments': {
    require_login();
    $reqId = (int)($_GET['chair_id'] ?? 0);
    // Chairs can only fetch their own; admins can fetch any
    if (!is_admin()) {
        if (!is_chair() || $reqId !== current_teacher_id()) fail('Not authorized', 403);
    }
    if (!$reqId) fail('Invalid chair_id');
    $r = db()->prepare('SELECT college, department FROM chair_assignments WHERE chair_id=? ORDER BY id');
    $r->execute([$reqId]);
    $rows = $r->fetchAll();
    // Fallback: if no assignments yet, read from teachers row
    if (!$rows) {
        $t = db()->prepare("SELECT college, department FROM teachers WHERE id=? AND role='chair'");
        $t->execute([$reqId]);
        $row = $t->fetch();
        if ($row && ($row['college'] || $row['department'])) {
            $rows = [['college'=>$row['college'], 'department'=>$row['department']]];
        }
    }
    out(['ok'=>true, 'assignments'=>$rows]);
}

// ── Delete a chair ───────────────────────────────────────────────────
case 'delete_chair': {
    require_admin();
    $d  = body_json();
    $id = (int)($d['id'] ?? 0);
    $r  = db()->prepare("SELECT id FROM teachers WHERE id=? AND role='chair'");
    $r->execute([$id]); if (!$r->fetch()) fail('Chair not found');
    db()->prepare('DELETE FROM teachers WHERE id=?')->execute([$id]);
    out(['ok'=>true]);
}

// ── Chair: faculty under ALL their supervision assignments ───────────
case 'chair_faculty': {
    if (!is_chair()) fail('Not authorized', 403);
    $assignments = current_assignments();
    if (!$assignments) out(['ok'=>true,'faculty'=>[]]);

    // Build WHERE clause for all assignments: (college=? AND dept=?) OR ...
    $clauses = array_fill(0, count($assignments), '(t.college=? AND t.department=?)');
    $params  = [];
    foreach ($assignments as [$col, $dept]) { $params[] = $col; $params[] = $dept; }
    $where = implode(' OR ', $clauses);

    $sql = "SELECT t.id, t.full_name, t.email, t.college, t.department, t.approved,
                   COUNT(DISTINCT c.id) AS class_count
            FROM teachers t
            LEFT JOIN classes c ON c.teacher_id=t.id AND c.is_archived=0
            WHERE t.role='teacher' AND ({$where})
            GROUP BY t.id ORDER BY t.full_name";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    out(['ok'=>true, 'faculty'=>$stmt->fetchAll()]);
}

// ── Chair: classes of a specific teacher ────────────────────────────
case 'chair_teacher_classes': {
    if (!is_chair()) fail('Not authorized', 403);
    $tid = (int)($_GET['teacher_id'] ?? 0);
    if (!chair_supervises_teacher($tid)) fail('Not authorized', 403);
    $rows = db()->prepare(
        "SELECT c.*,
                (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS student_count
         FROM classes c WHERE c.teacher_id=? ORDER BY c.is_archived ASC, c.sort_order ASC, c.created_at DESC");
    $rows->execute([$tid]);
    $all      = $rows->fetchAll();
    $active   = array_values(array_filter($all, fn($c)=>!$c['is_archived']));
    $archived = array_values(array_filter($all, fn($c)=> $c['is_archived']));
    out(['ok'=>true,'classes'=>$active,'archived'=>$archived]);
}

// ── Chair: update own assignments ────────────────────────────────────
case 'update_own_assignments': {
    require_chair();
    $d    = body_json();
    $tid  = current_teacher_id();
    $asgns = $d['assignments'] ?? [];
    if (!is_array($asgns) || !count($asgns)) fail('At least one assignment is required');
    _save_chair_assignments($tid, $asgns);
    // Also update the teachers row primary college/dept to first assignment
    $col  = trim($asgns[0]['college']    ?? '');
    $dept = trim($asgns[0]['department'] ?? '');
    db()->prepare('UPDATE teachers SET college=?,department=? WHERE id=?')
       ->execute([$col, $dept, $tid]);
    // Refresh session assignments cache
    start_session();
    unset($_SESSION['chair_assignments']);
    $_SESSION['college']    = $col;
    $_SESSION['department'] = $dept;
    out(['ok'=>true]);
}

// ── Admin self-edit ──────────────────────────────────────────────────
case 'edit_own_admin': {
    require_admin();
    $d    = body_json();
    $tid  = current_teacher_id();
    $name = trim($d['full_name'] ?? '');
    $email= trim($d['email']     ?? '');
    $newPw= $d['new_password']   ?? '';
    $curPw= $d['current_password']?? '';
    if (!$name || !$email) fail('Name and email are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email');
    $ck = db()->prepare('SELECT id FROM teachers WHERE email=? AND id!=?');
    $ck->execute([$email, $tid]); if ($ck->fetch()) fail('Email already in use');
    if ($newPw !== '') {
        if (strlen($newPw) < 6) fail('New password must be at least 6 characters');
        $ph = db()->prepare('SELECT password_hash FROM teachers WHERE id=?');
        $ph->execute([$tid]);
        if (!password_verify($curPw, $ph->fetchColumn())) fail('Current password is incorrect');
        db()->prepare('UPDATE teachers SET full_name=?,email=?,password_hash=? WHERE id=?')
           ->execute([$name,$email,password_hash($newPw,PASSWORD_DEFAULT),$tid]);
    } else {
        db()->prepare('UPDATE teachers SET full_name=?,email=? WHERE id=?')
           ->execute([$name,$email,$tid]);
    }
    start_session(); $_SESSION['full_name'] = $name;
    out(['ok'=>true]);
}

// ── Chair self-edit (own profile only) ──────────────────────────────
case 'edit_own_chair': {
    require_chair();
    $d    = body_json();
    $tid  = current_teacher_id();
    $name = trim($d['full_name']  ?? '');
    $email= trim($d['email']      ?? '');
    $col  = trim($d['college']    ?? '');
    $dept = trim($d['department'] ?? '');
    $newPw= $d['new_password']    ?? '';
    $curPw= $d['current_password']?? '';
    if (!$name || !$email) fail('Name and email are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email');
    $ck = db()->prepare('SELECT id FROM teachers WHERE email=? AND id!=?');
    $ck->execute([$email,$tid]); if ($ck->fetch()) fail('Email already in use');
    if ($newPw !== '') {
        if (strlen($newPw) < 6) fail('New password must be at least 6 characters');
        $ph = db()->prepare('SELECT password_hash FROM teachers WHERE id=?');
        $ph->execute([$tid]);
        if (!password_verify($curPw, $ph->fetchColumn())) fail('Current password is incorrect');
        db()->prepare('UPDATE teachers SET full_name=?,email=?,password_hash=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,password_hash($newPw,PASSWORD_DEFAULT),$col,$dept,$tid]);
    } else {
        db()->prepare('UPDATE teachers SET full_name=?,email=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,$col,$dept,$tid]);
    }
    start_session();
    $_SESSION['full_name']  = $name;
    $_SESSION['college']    = $col;
    $_SESSION['department'] = $dept;
    out(['ok'=>true]);
}

default: fail('Unknown action');
}
