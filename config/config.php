<?php
/**
 * GradeFlow — Database configuration
 * ----------------------------------
 * Edit these values to match your XAMPP/WAMP/server setup.
 * Defaults work with a standard XAMPP install (root / no password).
 */

// ---- Edit these for your environment ----
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'gradeflow');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default is empty
define('DB_PORT', '3306');

// Python executable path.
// On Windows XAMPP: try 'python' (default), or 'py', or the full path like 'C:\\Python311\\python.exe'
// On Mac/Linux:     'python3' or 'python'
// Leave as 'auto' to let GradeFlow detect the right one automatically (recommended).
define('PYTHON_BIN', 'auto');

// ---- Connection helper (PDO) ----
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT .
               ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        // Create performance indexes once. The flag file prevents re-running on every request.
        $flag = __DIR__ . '/../.db_indexes_ok';
        if (!file_exists($flag)) {
            foreach ([
                'ALTER TABLE classes      ADD INDEX idx_cls_teacher  (teacher_id, is_archived)',
                'ALTER TABLE classes      ADD INDEX idx_cls_sort      (teacher_id, sort_order)',
                'ALTER TABLE criteria     ADD INDEX idx_crit_class    (class_id, term)',
                'ALTER TABLE activities   ADD INDEX idx_act_crit      (criterion_id, sort_order)',
                'ALTER TABLE students     ADD INDEX idx_stu_class     (class_id, sort_order)',
                'ALTER TABLE scores       ADD INDEX idx_sc_student    (student_id)',
                'ALTER TABLE scores       ADD INDEX idx_sc_activity   (activity_id)',
                'ALTER TABLE teachers     ADD INDEX idx_tch_college   (college, department)',
                'ALTER TABLE user_settings ADD INDEX idx_us_teacher   (teacher_id)',
            ] as $sql) {
                try { $pdo->exec($sql); } catch (\Throwable $e) { /* already exists */ }
            }
            file_put_contents($flag, date('c'));
        }
    }
    return $pdo;
}
