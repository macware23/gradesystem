<?php
/**
 * GradeFlow - Analysis API
 * Builds student grade payload and runs the offline Python analysis engine.
 */
ob_start();  // prevent PHP notices from corrupting JSON
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';
require_once __DIR__ . '/../includes/python_helper.php';

header('Content-Type: application/json');
ob_end_clean();
require_login();

$studentId = (int)($_GET['student_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM students WHERE id=?');
$stmt->execute([$studentId]);
$student = $stmt->fetch();
if (!$student || !owns_class((int)$student['class_id'])) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not authorized']); exit;
}
$classId = (int)$student['class_id'];
$cs      = class_settings($classId);
$terms   = class_terms($classId);
$g       = compute_final_grade($studentId, $classId);

// Build per-criterion entries using transmuted averages
$criteria = [];
foreach ($terms as $t) {
    $tc = compute_term($studentId, $classId, $t, $cs);
    foreach ($tc['criteria'] as $name => $cc) {
        $criteria[] = [
            'term'   => $t,
            'name'   => $name,
            'weight' => $cc['weight'],
            'pct'    => $cc['average'] !== null ? $cc['average'] / 100.0 : null,
        ];
    }
}

$payload = [
    'student'  => ['name' => $student['first_name'] . ' ' . $student['last_name'], 'passing_grade' => $cs['passing']],
    'terms'    => $g['terms'],
    'final'    => $g['final'],
    'criteria' => $criteria,
];

$result = run_analysis($payload);

// run_analysis returns either the analysis result OR an error array
if (isset($result['error'])) {
    echo json_encode([
        'ok'           => false,
        'error'        => $result['error'],
        'install_hint' => $result['install_hint'] ?? '',
    ]);
    exit;
}

echo json_encode([
    'ok'       => true,
    'student'  => ['id' => $studentId, 'name' => $payload['student']['name']],
    'grades'   => ['terms' => $payload['terms'], 'final' => $payload['final']],
    'analysis' => $result,
]);
