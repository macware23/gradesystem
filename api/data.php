<?php
/**
 * GradeFlow - Main API (Transmutation model with activities)
 * Single JSON endpoint; actions dispatched by ?action=.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Compress JSON responses — big win on large gradebooks
if (extension_loaded('zlib') && !ob_get_level()) {
    ini_set('zlib.output_compression', '1');
    ini_set('zlib.output_compression_level', '4');
}
require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$tid = current_teacher_id();

function body_json(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail($msg, $code = 400) { http_response_code($code); out(['ok'=>false,'error'=>$msg]); }

/** Recompute everything for one student using the fast per-student path. */
function student_computed(int $sid, int $classId, array $cs, array $terms): array {
    return _student_compute_single($sid, $classId);
}

try {
switch ($action) {

// ---- Full sheet data ----
case 'sheet': {
    $classId = (int)($_GET['class_id'] ?? 0);
    if (!owns_class($classId)) fail('Not authorized', 403);

    $cls = db()->prepare('SELECT * FROM classes WHERE id=?');
    $cls->execute([$classId]);
    $class = $cls->fetch();
    $cs = class_settings($classId);
    $terms = class_terms($classId);

    // criteria + their activities
    $cr = db()->prepare('SELECT * FROM criteria WHERE class_id=? ORDER BY term, sort_order, id');
    $cr->execute([$classId]);
    $criteria = $cr->fetchAll();

    if ($criteria) {
        $cids    = array_column($criteria, 'id');
        $ph      = implode(',', array_fill(0, count($cids), '?'));
        $actStmt = db()->prepare("SELECT * FROM activities WHERE criterion_id IN ($ph) ORDER BY criterion_id, sort_order, id");
        $actStmt->execute($cids);
        $actsByCrit = [];
        foreach ($actStmt->fetchAll() as $a) $actsByCrit[(int)$a['criterion_id']][] = $a;
        foreach ($criteria as &$c) $c['activities'] = $actsByCrit[(int)$c['id']] ?? [];
        unset($c);
    }

    $st = db()->prepare('SELECT * FROM students WHERE class_id=? ORDER BY sort_order, last_name, first_name');
    $st->execute([$classId]);
    $students = $st->fetchAll();

    // scores: student_id => activity_id => raw
    $sc = db()->prepare('SELECT s.student_id, s.activity_id, s.raw_score
                         FROM scores s JOIN students stu ON stu.id=s.student_id
                         WHERE stu.class_id=?');
    $sc->execute([$classId]);
    $scores = [];
    foreach ($sc->fetchAll() as $r) $scores[$r['student_id']][$r['activity_id']] = $r['raw_score'];

    $tw = db()->prepare('SELECT term, weight FROM term_weights WHERE class_id=?');
    $tw->execute([$classId]);
    $termWeights = [];
    foreach ($tw->fetchAll() as $r) $termWeights[$r['term']] = $r['weight'];

    $computed = bulk_compute_class($classId);

    out(['ok'=>true,'class'=>$class,'settings'=>$cs,'terms'=>$terms,
         'criteria'=>$criteria,'students'=>$students,'scores'=>$scores,
         'term_weights'=>$termWeights,'computed'=>$computed]);
}

// ---- Save score cells (by activity), clamped to perfect_score ----
case 'save_scores': {
    $d = body_json();
    $classId = (int)($d['class_id'] ?? 0);
    if (!owns_class($classId)) fail('Not authorized', 403);

    // map activity_id -> perfect_score (for clamping) and validate ownership
    $perf = [];
    $pstmt = db()->query('SELECT a.id, a.perfect_score FROM activities a
                          JOIN criteria c ON c.id=a.criterion_id
                          WHERE c.class_id=' . $classId);
    foreach ($pstmt->fetchAll() as $r) $perf[(int)$r['id']] = (float)$r['perfect_score'];

    $pdo = db(); $pdo->beginTransaction();
    $up = $pdo->prepare('INSERT INTO scores (student_id, activity_id, raw_score) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE raw_score=VALUES(raw_score)');
    $del = $pdo->prepare('DELETE FROM scores WHERE student_id=? AND activity_id=?');
    $count = 0; $clamped = 0;
    foreach (($d['cells'] ?? []) as $c) {
        $sid = (int)$c['student_id']; $aid = (int)$c['activity_id'];
        if (!isset($perf[$aid])) continue;          // activity not in this class
        $val = $c['raw_score'];
        if ($val === '' || $val === null) { $del->execute([$sid,$aid]); }
        else {
            $v = (float)$val;
            if ($v < 0) $v = 0;
            if ($v > $perf[$aid]) { $v = $perf[$aid]; $clamped++; }   // enforce <= perfect
            $up->execute([$sid,$aid,$v]);
        }
        $count++;
    }
    $pdo->commit();

    $cs = class_settings($classId); $terms = class_terms($classId);
    $sids = array_unique(array_map(fn($c)=>(int)$c['student_id'], $d['cells'] ?? []));
    $computed = [];
    foreach ($sids as $sid) $computed[$sid] = student_computed($sid, $classId, $cs, $terms);

    out(['ok'=>true,'saved'=>$count,'clamped'=>$clamped,'computed'=>$computed]);
}

// ---- Save criteria + their activities + term weights (full setup) ----
case 'save_criteria': {
    $d = body_json();
    $classId = (int)($d['class_id'] ?? 0);
    if (!owns_class($classId)) fail('Not authorized', 403);
    $pdo = db(); $pdo->beginTransaction();

    // existing criteria for this class
    $existing = [];
    $ex = $pdo->prepare('SELECT id FROM criteria WHERE class_id=?');
    $ex->execute([$classId]);
    foreach ($ex->fetchAll() as $r) $existing[(int)$r['id']] = true;

    $keepCrit = [];
    $insC = $pdo->prepare('INSERT INTO criteria (class_id,term,name,weight,sort_order) VALUES (?,?,?,?,?)');
    $updC = $pdo->prepare('UPDATE criteria SET term=?,name=?,weight=?,sort_order=? WHERE id=? AND class_id=?');
    $insA = $pdo->prepare('INSERT INTO activities (criterion_id,label,perfect_score,sort_order) VALUES (?,?,?,?)');
    $updA = $pdo->prepare('UPDATE activities SET label=?,perfect_score=?,sort_order=? WHERE id=? AND criterion_id=?');
    $delA = $pdo->prepare('DELETE FROM activities WHERE id=?');
    $delC = $pdo->prepare('DELETE FROM criteria WHERE id=?');

    // Batch-load existing activities for all known incoming criteria (one query instead of N)
    $preloadedActs = [];
    $knownCids = array_values(array_filter(
        array_map(fn($c) => (int)($c['id'] ?? 0), $d['criteria'] ?? []),
        fn($id) => $id > 0 && isset($existing[$id])
    ));
    if ($knownCids) {
        $ph2    = implode(',', array_fill(0, count($knownCids), '?'));
        $eaStmt = $pdo->prepare("SELECT id, criterion_id FROM activities WHERE criterion_id IN ($ph2)");
        $eaStmt->execute($knownCids);
        foreach ($eaStmt->fetchAll() as $r) $preloadedActs[(int)$r['criterion_id']][(int)$r['id']] = true;
    }

    foreach (($d['criteria'] ?? []) as $ci => $c) {
        $name = trim($c['name'] ?? '');
        if ($name === '') continue;
        $term = trim($c['term'] ?? 'All');
        $w = (float)($c['weight'] ?? 0);
        $cid = (int)($c['id'] ?? 0);
        if ($cid && isset($existing[$cid])) { $updC->execute([$term,$name,$w,$ci,$cid,$classId]); }
        else { $insC->execute([$classId,$term,$name,$w,$ci]); $cid=(int)$pdo->lastInsertId(); }
        $keepCrit[$cid] = true;

        $existA = $preloadedActs[$cid] ?? [];
        $keepA = [];
        foreach (($c['activities'] ?? []) as $ai => $a) {
            $label = trim($a['label'] ?? '');
            if ($label === '') continue;
            $perf = (float)($a['perfect_score'] ?? 100);
            $aid = (int)($a['id'] ?? 0);
            if ($aid && isset($existA[$aid])) { $updA->execute([$label,$perf,$ai,$aid,$cid]); }
            else { $insA->execute([$cid,$label,$perf,$ai]); $aid=(int)$pdo->lastInsertId(); }
            $keepA[$aid] = true;
        }
        foreach ($existA as $eid=>$_) if (!isset($keepA[$eid])) $delA->execute([$eid]);
    }
    foreach ($existing as $eid=>$_) if (!isset($keepCrit[$eid])) $delC->execute([$eid]);

    // term weights
    $pdo->prepare('DELETE FROM term_weights WHERE class_id=?')->execute([$classId]);
    $tw = $pdo->prepare('INSERT INTO term_weights (class_id,term,weight) VALUES (?,?,?)');
    foreach (($d['term_weights'] ?? []) as $term=>$w) $tw->execute([$classId,$term,(float)$w]);

    $pdo->commit();
    out(['ok'=>true]);
}

// ---- Students ----
case 'bulk_students': {
    $d = body_json();
    $classId = (int)($d['class_id'] ?? 0);
    if (!owns_class($classId)) fail('Not authorized', 403);
    $pdo = db(); $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO students (class_id,student_no,last_name,first_name,email) VALUES (?,?,?,?,?)');
    // Get all activity IDs so new students start with score=0 (not blank)
    $actStmt = $pdo->query("SELECT a.id FROM activities a JOIN criteria c ON c.id=a.criterion_id WHERE c.class_id=$classId");
    $activityIds = $actStmt->fetchAll(PDO::FETCH_COLUMN);
    $scoreStmt = $pdo->prepare('INSERT IGNORE INTO scores (student_id,activity_id,raw_score) VALUES (?,?,0)');
    $n=0;
    foreach (($d['rows'] ?? []) as $r) {
        $last=trim($r['last_name']??''); $first=trim($r['first_name']??'');
        if ($last==='' && $first==='') continue;
        $stmt->execute([$classId,$r['student_no']??'',$last,$first,$r['email']??'']);
        $newId = (int)$pdo->lastInsertId();
        foreach ($activityIds as $aid) $scoreStmt->execute([$newId, $aid]);
        $n++;
    }
    $pdo->commit();
    out(['ok'=>true,'added'=>$n]);
}

case 'delete_student': {
    $d = body_json(); $sid=(int)($d['student_id']??0);
    $chk=db()->prepare('SELECT class_id FROM students WHERE id=?'); $chk->execute([$sid]);
    $cid=(int)$chk->fetchColumn();
    if (!$cid || !owns_class($cid)) fail('Not authorized',403);
    db()->prepare('DELETE FROM students WHERE id=?')->execute([$sid]);
    out(['ok'=>true]);
}

// ---- Class CRUD ----
case 'save_class': {
    $d = body_json(); $cid=(int)($d['id']??0);
    $fields=[trim($d['subject_name']??''),$d['subject_code']??'',$d['section']??'',
             $d['term_system']??'Prelim,Midterm,Finals',$d['school_year']??'',$d['semester']??'',
             (float)($d['passing_grade']??75),(float)($d['cutoff']??50),
             (float)($d['zero_equiv']??65),(int)($d['use_transmutation']??1)];
    if ($cid) {
        if (!owns_class($cid)) fail('Not authorized',403);
        $stmt=db()->prepare('UPDATE classes SET subject_name=?,subject_code=?,section=?,term_system=?,school_year=?,semester=?,passing_grade=?,cutoff=?,zero_equiv=?,use_transmutation=? WHERE id=?');
        $stmt->execute([...$fields,$cid]); out(['ok'=>true,'id'=>$cid]);
    } else {
        $stmt=db()->prepare('INSERT INTO classes (subject_name,subject_code,section,term_system,school_year,semester,passing_grade,cutoff,zero_equiv,use_transmutation,teacher_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([...$fields,$tid]); out(['ok'=>true,'id'=>db()->lastInsertId()]);
    }
}

case 'delete_class': {
    $d=body_json(); $cid=(int)($d['id']??0);
    if (!owns_class($cid)) fail('Not authorized',403);
    db()->prepare('DELETE FROM classes WHERE id=?')->execute([$cid]);
    out(['ok'=>true]);
}

// ---- Reorder classes (drag-and-drop) ----
case 'reorder_classes': {
    $d = body_json();
    $ids = array_map('intval', $d['ids'] ?? []);
    if (empty($ids)) fail('No IDs provided');
    $pdo = db(); $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE classes SET sort_order=? WHERE id=? AND teacher_id=?');
    foreach ($ids as $pos => $id) $stmt->execute([$pos + 1, $id, $tid]);
    $pdo->commit();
    out(['ok' => true]);
}

// ---- List classes (with sort_order) ----
case 'classes': {
    $stmt = db()->prepare(
        'SELECT c.*,
           (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id) AS student_count
         FROM classes c WHERE c.teacher_id=? ORDER BY c.is_archived ASC, c.sort_order ASC, c.created_at DESC');
    $stmt->execute([$tid]);
    $all = $stmt->fetchAll();
    $active   = array_values(array_filter($all, fn($c) => !$c['is_archived']));
    $archived = array_values(array_filter($all, fn($c) => $c['is_archived']));
    out(['ok'=>true,'classes'=>$active,'archived'=>$archived]);
}

// ---- Archive a class ----
case 'archive_class': {
    $d = body_json();
    $id = (int)($d['id'] ?? 0);
    if (!owns_class($id)) fail('Not authorized', 403);
    db()->prepare('UPDATE classes SET is_archived=1 WHERE id=?')->execute([$id]);
    out(['ok'=>true]);
}

// ---- Unarchive a class ----
case 'unarchive_class': {
    $d = body_json();
    $id = (int)($d['id'] ?? 0);
    if (!owns_class($id)) fail('Not authorized', 403);
    db()->prepare('UPDATE classes SET is_archived=0 WHERE id=?')->execute([$id]);
    out(['ok'=>true]);
}

// ---- Update own profile (name, email, password, college, department) ----
case 'update_profile': {
    $d   = body_json();
    $tid = current_teacher_id();
    if (!$tid) fail('Not logged in', 401);

    $name  = trim($d['full_name']  ?? '');
    $email = trim($d['email']      ?? '');
    $col   = trim($d['college']    ?? '');
    $dept  = trim($d['department'] ?? '');
    $newPw = $d['new_password']    ?? '';
    $curPw = $d['current_password']?? '';

    if (!$name)  fail('Full name is required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email');

    // Check email uniqueness (excluding self)
    $ck = db()->prepare('SELECT id FROM teachers WHERE email=? AND id!=?');
    $ck->execute([$email, $tid]);
    if ($ck->fetch()) fail('Email already in use by another account');

    if ($newPw !== '') {
        if (strlen($newPw) < 6) fail('New password must be at least 6 characters');
        // Verify current password
        $ph = db()->prepare('SELECT password_hash FROM teachers WHERE id=?');
        $ph->execute([$tid]);
        if (!password_verify($curPw, $ph->fetchColumn())) fail('Current password is incorrect');
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        db()->prepare('UPDATE teachers SET full_name=?,email=?,password_hash=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,$hash,$col,$dept,$tid]);
    } else {
        db()->prepare('UPDATE teachers SET full_name=?,email=?,college=?,department=? WHERE id=?')
           ->execute([$name,$email,$col,$dept,$tid]);
    }
    // Refresh session name
    start_session(); $_SESSION['full_name'] = $name;
    out(['ok'=>true]);
}

// ---- Get own profile ----
case 'my_profile': {
    $tid = current_teacher_id();
    if (!$tid) fail('Not logged in', 401);
    $r = db()->prepare('SELECT full_name, email, college, department, role FROM teachers WHERE id=?');
    $r->execute([$tid]);
    out(['ok'=>true, 'profile'=>$r->fetch()]);
}
case 'update_student': {
    $d = body_json();
    $sid = (int)($d['student_id'] ?? 0);
    // verify ownership
    $r = db()->prepare('SELECT class_id FROM students WHERE id=?');
    $r->execute([$sid]); $row = $r->fetch();
    if (!$row || !owns_class((int)$row['class_id'])) fail('Not authorized',403);
    if (!can_write_class((int)$row['class_id'])) fail('Read-only',403);
    $stmt = db()->prepare('UPDATE students SET last_name=?,first_name=?,student_no=?,email=? WHERE id=?');
    $stmt->execute([
        trim($d['last_name']  ?? ''),
        trim($d['first_name'] ?? ''),
        trim($d['student_no'] ?? ''),
        trim($d['email']      ?? ''),
        $sid
    ]);
    out(['ok'=>true]);
}

// ---- Verify current teacher password (used before destructive actions) ----
case 'verify_password': {
    $d = body_json();
    $stmt = db()->prepare('SELECT password_hash FROM teachers WHERE id=?');
    $stmt->execute([current_teacher_id()]);
    $hash = $stmt->fetchColumn();
    $ok = $hash && password_verify($d['password'] ?? '', $hash);
    out(['ok' => $ok, 'message' => $ok ? 'Password verified' : 'Incorrect password']);
}

// ---- Duplicate a criterion (copies structure + activities, no scores) ----
case 'duplicate_criterion': {
    $d = body_json();
    $srcId = (int)($d['criterion_id'] ?? 0);
    // Verify ownership
    $src = db()->prepare('SELECT c.*, cr.class_id FROM criteria c JOIN classes cr ON cr.id=c.class_id WHERE c.id=?');
    $src->execute([$srcId]); $crit = $src->fetch();
    if (!$crit || !owns_class((int)$crit['class_id'])) fail('Not authorized', 403);
    if (!can_write_class((int)$crit['class_id'])) fail('Read-only mode', 403);

    $pdo = db(); $pdo->beginTransaction();
    // Get max sort_order
    $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM criteria WHERE class_id={$crit['class_id']}")->fetchColumn();
    // Insert duplicate criterion
    $ins = $pdo->prepare('INSERT INTO criteria (class_id,term,name,weight,sort_order) VALUES (?,?,?,?,?)');
    $ins->execute([$crit['class_id'], $crit['term'], $crit['name'] . ' (copy)', $crit['weight'], $maxOrd + 1]);
    $newCritId = (int)$pdo->lastInsertId();
    // Duplicate activities
    $acts = $pdo->prepare('SELECT * FROM activities WHERE criterion_id=? ORDER BY sort_order,id');
    $acts->execute([$srcId]);
    $insA = $pdo->prepare('INSERT INTO activities (criterion_id,label,perfect_score,sort_order) VALUES (?,?,?,?)');
    foreach ($acts->fetchAll() as $a) $insA->execute([$newCritId, $a['label'], $a['perfect_score'], $a['sort_order']]);
    $pdo->commit();
    out(['ok' => true, 'new_criterion_id' => $newCritId]);
}

// ---- Duplicate a class (structure only: no students, no scores) ----
case 'duplicate_class': {
    $d = body_json();
    $srcId = (int)($d['class_id'] ?? 0);
    if (!owns_class($srcId) || !can_write_class($srcId)) fail('Not authorized', 403);

    $src = db()->prepare('SELECT * FROM classes WHERE id=?'); $src->execute([$srcId]); $srcClass = $src->fetch();
    if (!$srcClass) fail('Class not found');

    $pdo = db(); $pdo->beginTransaction();
    // Duplicate class row
    $insC = $pdo->prepare('INSERT INTO classes (teacher_id,subject_code,subject_name,section,term_system,school_year,passing_grade,cutoff,zero_equiv,use_transmutation) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insC->execute([
        current_teacher_id(),
        $srcClass['subject_code'],
        $srcClass['subject_name'] . ' (copy)',
        $srcClass['section'],
        $srcClass['term_system'],
        $srcClass['school_year'],
        $srcClass['passing_grade'],
        $srcClass['cutoff'],
        $srcClass['zero_equiv'],
        $srcClass['use_transmutation'],
    ]);
    $newClassId = (int)$pdo->lastInsertId();

    // Duplicate term weights
    $tws = $pdo->prepare('SELECT term,weight FROM term_weights WHERE class_id=?'); $tws->execute([$srcId]);
    $insTW = $pdo->prepare('INSERT INTO term_weights (class_id,term,weight) VALUES (?,?,?)');
    foreach ($tws->fetchAll() as $tw) $insTW->execute([$newClassId, $tw['term'], $tw['weight']]);

    // Duplicate criteria and their activities
    $crits = $pdo->prepare('SELECT * FROM criteria WHERE class_id=? ORDER BY sort_order,id'); $crits->execute([$srcId]);
    $insCA = $pdo->prepare('INSERT INTO criteria (class_id,term,name,weight,sort_order) VALUES (?,?,?,?,?)');
    $insAA = $pdo->prepare('INSERT INTO activities (criterion_id,label,perfect_score,sort_order) VALUES (?,?,?,?)');
    $acts  = $pdo->prepare('SELECT * FROM activities WHERE criterion_id=? ORDER BY sort_order,id');
    foreach ($crits->fetchAll() as $cr) {
        $insCA->execute([$newClassId, $cr['term'], $cr['name'], $cr['weight'], $cr['sort_order']]);
        $newCritId = (int)$pdo->lastInsertId();
        $acts->execute([$cr['id']]);
        foreach ($acts->fetchAll() as $a) $insAA->execute([$newCritId, $a['label'], $a['perfect_score'], $a['sort_order']]);
    }
    $pdo->commit();
    out(['ok' => true, 'new_class_id' => $newClassId, 'name' => $srcClass['subject_name'] . ' (copy)']);
}

default: fail('Unknown action: '.htmlspecialchars($action),404);
}
} catch (Throwable $e) { fail('Server error: '.$e->getMessage(),500); }
