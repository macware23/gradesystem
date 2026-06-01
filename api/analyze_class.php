<?php
/**
 * GradeFlow - Class-wide Analysis API
 * Analyses ALL students and produces per-student results + class narrative.
 */
ob_start();   // catch any stray PHP notices that would corrupt JSON
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';
require_once __DIR__ . '/../includes/python_helper.php';

function out_json($d) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode($d); exit; }
function fail_json($m,$c=400) { http_response_code($c); out_json(['ok'=>false,'error'=>$m]); }

require_login();

$classId  = (int)($_GET['class_id'] ?? 0);
$type     = $_GET['type'] ?? 'final';
if (!owns_class($classId)) fail_json('Not authorized', 403);

$cs       = class_settings($classId);
$terms    = class_terms($classId);
$students = db()->query(
    "SELECT * FROM students WHERE class_id=$classId ORDER BY last_name,first_name"
)->fetchAll();

if (!$students) { out_json(['ok'=>true,'results'=>[],'summary'=>[],'narrative'=>'']); }

// ── Analyse each student ─────────────────────────────────────────────
$results   = [];
$batchData = [];

foreach ($students as $stu) {
    $sid = (int)$stu['id'];
    $g   = compute_final_grade($sid, $classId);

    $criteria   = [];
    $scopeTerms = ($type === 'final') ? $terms : [$type];
    foreach ($scopeTerms as $t) {
        $tc = compute_term($sid, $classId, $t, $cs);
        foreach ($tc['criteria'] as $name => $cc) {
            $criteria[] = ['term'=>$t,'name'=>$name,'weight'=>$cc['weight'],
                           'pct'=>$cc['average']!==null ? $cc['average']/100.0 : null];
        }
    }

    $termGrades = ($type === 'final') ? ($g['terms'] ?? []) : [$type => ($g['terms'][$type] ?? null)];
    $finalGrade = ($type === 'final') ? $g['final'] : ($g['terms'][$type] ?? null);

    $payload = [
        'student'     => ['name' => $stu['first_name'].' '.$stu['last_name'],
                           'passing_grade' => $cs['passing']],
        'terms'       => $termGrades,
        'final'       => $finalGrade,
        'criteria'    => $criteria,
        'skip_ollama' => true,   // use fast statistical actions; Ollama runs only for class narrative
    ];

    $res = run_analysis($payload);
    if (isset($res['error'])) {
        fail_json($res['error'] . ($res['install_hint'] ? ' — '.$res['install_hint'] : ''));
    }

    $local = $res['local'];
    $results[] = [
        'student_id'      => $sid,
        'name'            => $stu['last_name'].', '.$stu['first_name'],
        'grade'           => $finalGrade,
        'risk_score'      => $local['risk_score']    ?? 0,
        'risk_level'      => $local['risk_level']    ?? 'low',
        'grade_status'    => $local['grade_status']  ?? 'passing',
        'trend'           => $local['trend']          ?? null,
        'reasons'         => $local['reasons']        ?? [],
        'weak_areas'      => $local['weak_areas']     ?? [],
        'actions'         => $local['actions']        ?? ($local['recommendations'] ?? []),
    ];
    $batchData[] = array_merge($payload, ['risk_level'=>$local['risk_level']??'low',
                                          'risk_score'=>$local['risk_score']??0]);
}

// Sort alphabetically by student last name, then first name
usort($results, function($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

// ── Build summary ────────────────────────────────────────────────────
$passing  = $cs['passing'];
$failing  = array_filter($results, fn($r) => $r['grade'] !== null && $r['grade'] < $passing);
$atRisk   = array_filter($results, fn($r) => $r['risk_level'] !== 'low');
$graded   = array_filter($results, fn($r) => $r['grade'] !== null);
$avg      = count($graded) ? array_sum(array_column(array_values($graded),'grade'))/count($graded) : null;

$weakCount = [];
foreach ($results as $r) {
    foreach ($r['weak_areas'] as $w) {
        $key = ($w['term']??'').':'.($w['name']??'');
        $weakCount[$key] = ($weakCount[$key] ?? 0) + 1;
    }
}
arsort($weakCount);
$topWeak = array_slice(array_keys($weakCount), 0, 5);

$summary = [
    'total'        => count($results),
    'graded'       => count($graded),
    'passing'      => count($graded) - count($failing),
    'failing'      => count($failing),
    'at_risk'      => count($atRisk),
    'avg_grade'    => $avg !== null ? round($avg, 1) : null,
    'top_weak'     => $topWeak,
    'passing_rate' => count($graded) > 0
        ? round((count($graded)-count($failing))/count($graded)*100, 1) : null,
];

// ── Generate class narrative via Python batch call ───────────────────
$narrative = '';
$batchPayload = [
    'batch'          => $batchData,
    'passing_grade'  => $passing,
    'summary'        => $summary,
];
$narRes = run_analysis($batchPayload);
if (isset($narRes['narrative'])) {
    $narrative = $narRes['narrative'];
}

out_json([
    'ok'         => true,
    'type'       => $type,
    'class_id'   => $classId,
    'results'    => $results,
    'summary'    => $summary,
    'narrative'  => $narrative,
    'scope_label'=> ($type === 'final') ? 'Final Grade' : $type,
]);
