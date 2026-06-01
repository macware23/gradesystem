<?php
/**
 * GradeFlow - Python path resolver
 * Tries common Python executable names in order and returns the first one that works.
 * Result is cached per request.
 */

function resolve_python(): string {
    static $resolved = null;
    if ($resolved !== null) return $resolved;

    $configured = PYTHON_BIN;

    // If explicitly set (not 'auto'), use it directly
    if ($configured !== 'auto') {
        $resolved = $configured;
        return $resolved;
    }

    // Auto-detect: try candidates in priority order
    // Windows: 'python', 'py'  |  Mac/Linux: 'python3', 'python'
    $candidates = PHP_OS_FAMILY === 'Windows'
        ? ['python', 'py', 'python3']
        : ['python3', 'python', 'python3.11', 'python3.10', 'python3.9'];

    foreach ($candidates as $cmd) {
        $out = @shell_exec(escapeshellcmd($cmd) . ' --version 2>&1');
        if ($out && stripos($out, 'python') !== false) {
            $resolved = $cmd;
            return $resolved;
        }
    }

    // Nothing found — return 'python3' as the error-triggering default
    // so analyze.php produces a clear "Python not found" message
    $resolved = 'python3';
    return $resolved;
}

/**
 * Run the analysis script and return the JSON result array,
 * or ['error' => ..., 'install_hint' => ...] on failure.
 */
function run_analysis(array $payload): array {
    $python = resolve_python();
    $script = escapeshellarg(__DIR__ . '/../python/analyze.py');
    $cmd    = escapeshellcmd($python) . ' ' . $script;

    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, null);

    if (!is_resource($proc)) {
        return [
            'error'        => "Could not start Python ($python).",
            'install_hint' => _python_install_hint($python),
        ];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);

    if (!$stdout || !trim($stdout)) {
        return [
            'error'        => "Python ran but returned no output. Error: " . ($stderr ?: 'none'),
            'install_hint' => _python_install_hint($python),
        ];
    }

    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        return [
            'error'        => "Python output was not valid JSON. Error: $stderr",
            'install_hint' => _python_install_hint($python),
        ];
    }

    return $result;
}

function _python_install_hint(string $tried): string {
    if (PHP_OS_FAMILY === 'Windows') {
        return "Python is not installed or not in your PATH. "
            . "Download Python from python.org, install it, and tick 'Add Python to PATH' during installation. "
            . "After installing, restart XAMPP/Apache. "
            . "Tried: '$tried'. You can also set PYTHON_BIN manually in config/config.php "
            . "(e.g. 'C:\\\\Python312\\\\python.exe').";
    }
    return "Python 3 is not installed or not in your PATH. "
        . "Install it with: sudo apt install python3  OR  brew install python3. "
        . "Tried: '$tried'. You can also set PYTHON_BIN manually in config/config.php.";
}
