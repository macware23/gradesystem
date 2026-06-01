<?php
/**
 * GradeFlow - Attendance API
 * Actions: sessions, save_session, delete_session,
 *          save_records, summary
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';
header('Content-Type: application/json');
require_login();

$action = $_GET['action'] ?? '';
function body(): array { $d = json_decode(file_get_contents('php://input'), true); return is_array($d) ? $d : []; }
function out($d)  { echo json_encode($d); exit; }
function fail($m, $c=400) { http_response_code($c); out(['ok'=>false,'error'=>$m]); }

try { switch ($action) {

// ---- List sessions for a term ----
case 'sessions': {
    $cid=(int)($_GET['class_id']??0); $term=$_GET['term']??'';
    if (!owns_class($cid)) fail('Not authorized',403);
    $stmt=db()->prepare('SELECT * FROM attendance_sessions WHERE class_id=? AND term=? ORDER BY sort_order,session_date,id');
    $stmt->execute([$cid,$term]);
    $sessions=$stmt->fetchAll();
    // For each session, load student statuses
    $recStmt=db()->prepare('SELECT student_id,status FROM attendance_records WHERE session_id=?');
    foreach($sessions as &$s){
        $recStmt->execute([$s['id']]);
        $recs=[];
        foreach($recStmt->fetchAll() as $r) $recs[$r['student_id']]=$r['status'];
        $s['records']=$recs;
    } unset($s);
    out(['ok'=>true,'sessions'=>$sessions]);
}

// ---- Create / update a session ----
case 'save_session': {
    $d=body(); $cid=(int)($d['class_id']??0);
    if (!owns_class($cid)) fail('Not authorized',403);
    $sid=(int)($d['id']??0);
    if ($sid) {
        db()->prepare('UPDATE attendance_sessions SET label=?,session_date=?,sort_order=? WHERE id=? AND class_id=?')
             ->execute([$d['label']??'',$d['date']??null,(int)($d['sort_order']??0),$sid,$cid]);
        out(['ok'=>true,'id'=>$sid]);
    } else {
        // auto sort_order = max+1
        $max=(int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM attendance_sessions WHERE class_id=$cid")->fetchColumn();
        $stmt=db()->prepare('INSERT INTO attendance_sessions (class_id,term,label,session_date,sort_order) VALUES (?,?,?,?,?)');
        $stmt->execute([$cid,$d['term']??'',$d['label']??'',$d['date']??null,$max+1]);
        out(['ok'=>true,'id'=>db()->lastInsertId()]);
    }
}

// ---- Delete a session ----
case 'delete_session': {
    $d=body(); $sid=(int)($d['session_id']??0);
    $row=db()->prepare('SELECT class_id FROM attendance_sessions WHERE id=?'); $row->execute([$sid]);
    $cid=(int)$row->fetchColumn();
    if (!$cid || !owns_class($cid)) fail('Not authorized',403);
    db()->prepare('DELETE FROM attendance_sessions WHERE id=?')->execute([$sid]);
    out(['ok'=>true]);
}

// ---- Save attendance records for one session ----
// records: [{student_id, status}] — batch upsert
case 'save_records': {
    $d=body(); $sid=(int)($d['session_id']??0);
    $row=db()->prepare('SELECT class_id FROM attendance_sessions WHERE id=?'); $row->execute([$sid]);
    $cid=(int)$row->fetchColumn();
    if (!$cid || !owns_class($cid)) fail('Not authorized',403);
    $pdo=db(); $pdo->beginTransaction();
    $up=$pdo->prepare('INSERT INTO attendance_records (session_id,student_id,status) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE status=VALUES(status)');
    foreach(($d['records']??[]) as $r) {
        $st=in_array($r['status'],['P','A','L'])?$r['status']:'P';
        $up->execute([$sid,(int)$r['student_id'],$st]);
    }
    $pdo->commit();
    out(['ok'=>true]);
}

// ---- Summary: per-student counts per term ----
case 'summary': {
    $cid=(int)($_GET['class_id']??0); $term=$_GET['term']??'';
    if (!owns_class($cid)) fail('Not authorized',403);
    $sessions=db()->prepare('SELECT id FROM attendance_sessions WHERE class_id=? AND term=?');
    $sessions->execute([$cid,$term]);
    $sids=array_column($sessions->fetchAll(),'id');
    $total=count($sids);
    if (!$sids) { out(['ok'=>true,'total'=>0,'summary',[]]); }
    $in=implode(',',array_fill(0,count($sids),'?'));
    $stmt=db()->prepare("SELECT student_id,status,COUNT(*) AS cnt
                         FROM attendance_records WHERE session_id IN ($in)
                         GROUP BY student_id,status");
    $stmt->execute($sids);
    $raw=$stmt->fetchAll();
    $summary=[];
    foreach($raw as $r){
        $sid=(int)$r['student_id'];
        $summary[$sid]=$summary[$sid]??['P'=>0,'A'=>0,'L'=>0];
        $summary[$sid][$r['status']]=(int)$r['cnt'];
    }
    out(['ok'=>true,'total'=>$total,'summary'=>$summary]);
}

default: fail('Unknown action',404);
}} catch(Throwable $e){ fail('Error: '.$e->getMessage(),500); }
