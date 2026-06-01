<?php
/**
 * GradeFlow — CSV/Excel Grade Export
 * type=final  → all term grades + final grade per student
 * type=term   → per-criterion breakdown (raw, equivalent, avg, ws) + term grade
 * Restricted to the owning teacher only (not admin/chair).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';
require_login();

$classId = (int)($_GET['class_id'] ?? 0);
if (!can_write_class($classId)) {
    http_response_code(403);
    die('Not authorized — teacher account required');
}

$type = $_GET['type'] ?? 'final';
$term = $_GET['term'] ?? '';

$cls = db()->prepare(
    'SELECT c.*, t.full_name AS teacher_name
     FROM classes c JOIN teachers t ON t.id = c.teacher_id WHERE c.id = ?'
);
$cls->execute([$classId]);
$class = $cls->fetch();
if (!$class) { http_response_code(404); die('Class not found'); }

$cs         = class_settings($classId);
$terms      = class_terms($classId);
$ss         = school_settings();
$schoolName = $ss['school_name'] ?? '';
date_default_timezone_set('Asia/Manila');

$stQ = db()->prepare('SELECT * FROM students WHERE class_id = ? ORDER BY last_name, first_name');
$stQ->execute([$classId]);
$students = $stQ->fetchAll();

$subjectLabel = $class['subject_name'] . ($class['section'] ? ' (' . $class['section'] . ')' : '');
$safeName     = preg_replace('/[^A-Za-z0-9]+/', '_', $subjectLabel);

if ($type === 'term' && in_array($term, $terms)) {
    $filename = $safeName . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $term) . '_Grades.csv';
} else {
    $filename = $safeName . '_Final_Grades.csv';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens with correct encoding

$infoParts = array_filter([
    $class['subject_code'] ? 'Code: ' . $class['subject_code'] : '',
    $class['school_year']  ? 'S.Y.: ' . $class['school_year']  : '',
    'Instructor: ' . $class['teacher_name'],
]);
$infoLine = implode('     ', $infoParts);

// ================================================================
// FINAL GRADE REPORT
// ================================================================
if ($type === 'final') {
    fputcsv($out, [$schoolName ?: 'GradeFlow']);
    fputcsv($out, [$subjectLabel]);
    fputcsv($out, [$infoLine]);
    fputcsv($out, ['Final Grade Report']);
    fputcsv($out, ['Generated: ' . date('F j, Y  g:i A')]);
    fputcsv($out, []);

    $header = ['#', 'Student No.', 'Student Name'];
    foreach ($terms as $t) $header[] = strtoupper($t) . ' GRADE';
    $header[] = 'FINAL GRADE';
    $header[] = 'REMARKS';
    fputcsv($out, $header);

    $computed = bulk_compute_class($classId);
    $i = 1; $sum = 0; $cnt = 0; $passN = 0;
    foreach ($students as $s) {
        $sid   = (int)$s['id'];
        $g     = $computed[$sid] ?? ['terms' => [], 'final' => null, 'passes' => null];
        $final = $g['final'];
        $pass  = $g['passes'];
        $row   = [$i++, $s['student_no'], $s['last_name'] . ', ' . $s['first_name']];
        foreach ($terms as $t) {
            $tg    = $g['terms'][$t] ?? null;
            $row[] = $tg !== null ? $tg : number_format($cs['zero_equiv'], 0);
        }
        $row[] = $final !== null ? $final : '';
        $row[] = $pass === true ? 'PASSED' : ($pass === false ? 'FAILED' : 'INC');
        fputcsv($out, $row);
        if ($final !== null) { $sum += $final; $cnt++; if ($pass) $passN++; }
    }

    fputcsv($out, []);
    fputcsv($out, ['CLASS SUMMARY']);
    $avg  = $cnt ? number_format($sum / $cnt, 0) : 'N/A';
    $rate = $cnt ? number_format(100 * $passN / $cnt, 1) . '%' : 'N/A';
    fputcsv($out, [
        'Total Students: ' . count($students),
        'Graded: ' . $cnt,
        'Class Average: ' . $avg,
        'Passing Rate: ' . $rate,
        'Passed: ' . $passN,
        'Failed: ' . ($cnt - $passN),
    ]);
    fputcsv($out, []);
    fputcsv($out, ['Submitted by: ' . strtoupper($class['teacher_name'])]);
    fputcsv($out, ['Submitted on: ' . date('F j, Y')]);

// ================================================================
// TERM GRADE REPORT
// ================================================================
} elseif ($type === 'term') {
    if (!in_array($term, $terms)) { fclose($out); die('Invalid term'); }

    // Batch-load criteria and activities for this term
    $crQ = db()->prepare('SELECT * FROM criteria WHERE class_id = ? AND term = ? ORDER BY sort_order, id');
    $crQ->execute([$classId, $term]);
    $criteria = $crQ->fetchAll();

    if ($criteria) {
        $cids    = array_column($criteria, 'id');
        $ph      = implode(',', array_fill(0, count($cids), '?'));
        $actStmt = db()->prepare(
            "SELECT * FROM activities WHERE criterion_id IN ($ph) ORDER BY criterion_id, sort_order, id"
        );
        $actStmt->execute($cids);
        $actsByCrit = [];
        foreach ($actStmt->fetchAll() as $a) $actsByCrit[(int)$a['criterion_id']][] = $a;
        foreach ($criteria as &$c) $c['acts'] = $actsByCrit[(int)$c['id']] ?? [];
        unset($c);
    } else {
        foreach ($criteria as &$c) $c['acts'] = [];
        unset($c);
    }

    // Batch-load all scores for this class
    $scQ = db()->prepare(
        'SELECT s.student_id, s.activity_id, s.raw_score
         FROM scores s JOIN students st ON st.id = s.student_id
         WHERE st.class_id = ?'
    );
    $scQ->execute([$classId]);
    $scoreMap = [];
    foreach ($scQ->fetchAll() as $r) $scoreMap[$r['student_id']][$r['activity_id']] = $r['raw_score'];

    // Bulk compute for criterion averages and weighted scores
    $computed = bulk_compute_class($classId);

    // Metadata
    fputcsv($out, [$schoolName ?: 'GradeFlow']);
    fputcsv($out, [$subjectLabel . ' — ' . $term . ' Grade Report']);
    fputcsv($out, [$infoLine]);
    fputcsv($out, ['Generated: ' . date('F j, Y  g:i A')]);
    fputcsv($out, []);

    // Three header rows mirror the PDF term report layout
    // Row 1: criterion group labels (padded to span their columns)
    // Row 2: activity labels + AVG + WS sub-headers
    // Row 3: perfect-score sub-labels
    $hdr1 = ['#', 'Student No.', 'Student Name'];
    $hdr2 = ['', '', ''];
    $hdr3 = ['', '', ''];
    foreach ($criteria as $c) {
        $spanCols = count($c['acts']) * 2 + 2; // (raw+eq) per activity, then avg, ws
        $hdr1[]   = $c['name'] . ' (' . number_format($c['weight'], 0) . '%)';
        for ($x = 1; $x < $spanCols; $x++) $hdr1[] = '';
        foreach ($c['acts'] as $a) {
            $hdr2[] = $a['label'];
            $hdr2[] = 'Equivalent';
            $hdr3[] = '/' . (int)$a['perfect_score'];
            $hdr3[] = '';
        }
        $hdr2[] = 'AVG';
        $hdr2[] = 'WS (' . number_format($c['weight'], 0) . '%)';
        $hdr3[] = '';
        $hdr3[] = '';
    }
    $hdr1[] = 'TERM GRADE';
    $hdr2[] = '';
    $hdr3[] = '';
    fputcsv($out, $hdr1);
    fputcsv($out, $hdr2);
    fputcsv($out, $hdr3);

    // Data rows
    $i = 1; $sum = 0; $cnt = 0; $passN = 0;
    foreach ($students as $s) {
        $sid     = (int)$s['id'];
        $g       = $computed[$sid] ?? ['detail' => [], 'terms' => []];
        $tDetail = $g['detail'][$term] ?? [];
        $row     = [$i++, $s['student_no'], $s['last_name'] . ', ' . $s['first_name']];
        foreach ($criteria as $c) {
            $cc = $tDetail[$c['name']] ?? ['average' => null, 'ws' => null];
            foreach ($c['acts'] as $a) {
                $raw   = $scoreMap[$sid][$a['id']] ?? null;
                $row[] = $raw !== null ? number_format((float)$raw, 0) : '';
                if ($raw !== null) {
                    $eq    = mags_equivalent(
                        (float)$a['perfect_score'], (float)$raw, $cs['cutoff'], $cs['zero_equiv']
                    );
                    $row[] = number_format($eq, 0);
                } else {
                    $row[] = '';
                }
            }
            $row[] = $cc['average'] !== null ? number_format($cc['average'], 2) : '';
            $row[] = $cc['ws']      !== null ? number_format($cc['ws'],      2) : '';
        }
        $grade = $g['terms'][$term] ?? null;
        $row[] = $grade !== null ? $grade : '';
        fputcsv($out, $row);
        if ($grade !== null) { $sum += $grade; $cnt++; if ($grade >= $cs['passing']) $passN++; }
    }

    fputcsv($out, []);
    fputcsv($out, ['CLASS SUMMARY']);
    $avg  = $cnt ? number_format($sum / $cnt, 0) : 'N/A';
    $rate = $cnt ? number_format(100 * $passN / $cnt, 1) . '%' : 'N/A';
    fputcsv($out, [
        'Total Students: ' . count($students),
        'Graded: ' . $cnt,
        'Class Average: ' . $avg,
        'Passing Rate: ' . $rate,
        'Passed: ' . $passN,
        'Failed: ' . ($cnt - $passN),
    ]);
    fputcsv($out, []);
    fputcsv($out, ['Submitted by: ' . strtoupper($class['teacher_name'])]);
    fputcsv($out, ['Submitted on: ' . date('F j, Y')]);
}

fclose($out);
