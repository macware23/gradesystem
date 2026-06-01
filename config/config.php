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
    }
    return $pdo;
}
