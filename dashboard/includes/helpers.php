<?php

if (!function_exists('get_servisan_home')) {
    function get_servisan_home() {
        $env = getenv('SERVISAN_HOME');
        if ($env && is_dir($env)) {
            return rtrim($env, '/\\');
        }
        $home = dirname(dirname(__DIR__));
        if (file_exists($home . DIRECTORY_SEPARATOR . 'servisan.exe') || file_exists($home . DIRECTORY_SEPARATOR . 'Cargo.toml') || is_dir($home . DIRECTORY_SEPARATOR . 'bin')) {
            return $home;
        }
        return dirname(__DIR__);
    }
}

if (!function_exists('servisan_executable')) {
    // Raw path to the CLI. Shell call sites must quote it themselves; argv
    // call sites pass it to proc_open() as-is.
    function servisan_executable($docRoot = null) {
        $rootDir = get_servisan_home();
        $candidates = array(
            $rootDir . DIRECTORY_SEPARATOR . 'servisan.exe',
            $rootDir . DIRECTORY_SEPARATOR . 'target' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'servisan.exe',
            $rootDir . DIRECTORY_SEPARATOR . 'target' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'servisan.exe',
        );
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return 'servisan';
    }
}

if (!function_exists('get_servisan_cmd')) {
    function get_servisan_cmd($docRoot = null) {
        $exe = servisan_executable($docRoot);
        return $exe === 'servisan' ? 'servisan' : escapeshellarg($exe);
    }
}

if (!function_exists('respond_json')) {
    function respond_json($status, array $payload) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}

if (!function_exists('require_action_allowed')) {
    /**
     * Gate a dashboard action behind loopback access, the manager token and,
     * for state-changing actions, POST.
     */
    function require_action_allowed($isLoopback, $expectedToken, $requiresPost, $action) {
        if (!$isLoopback) {
            respond_json(403, array(
                'ok' => false,
                'error' => 'Dashboard actions are only available from this computer (127.0.0.1 or ::1).',
            ));
        }
        if ($expectedToken === '') {
            respond_json(403, array(
                'ok' => false,
                'error' => 'Dashboard actions need the Servisan manager. Start Servisan and open the dashboard from the tray.',
            ));
        }
        if ($requiresPost && strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) !== 'POST') {
            respond_json(405, array(
                'ok' => false,
                'error' => $action . ' must be requested with POST.',
            ));
        }
        $sent = (string) (isset($_SERVER['HTTP_X_SERVISAN_TOKEN']) ? $_SERVER['HTTP_X_SERVISAN_TOKEN'] : '');
        if ($sent === '' || !hash_equals($expectedToken, $sent)) {
            respond_json(403, array(
                'ok' => false,
                'error' => 'Dashboard token missing or invalid; reload the dashboard and try again.',
            ));
        }
    }
}

if (!function_exists('valid_database_name')) {
    /**
     * Reject names that would be read as CLI flags. Escaping alone does not
     * help here because the shell removes the quotes before `servisan` parses
     * argv. The allowlist also avoids the characters `escapeshellarg()`
     * mangles on Windows (`"`, `%`, `!`).
     */
    function valid_database_name($name) {
        return is_string($name) && preg_match('/^[A-Za-z0-9_$][A-Za-z0-9_$ .-]{0,63}$/', $name) === 1;
    }
}

if (!function_exists('normalize_source_path')) {
    /**
     * Trim a pasted folder path and drop trailing separators. A trailing `\`
     * would escape the closing quote of `escapeshellarg()` on Windows.
     */
    function normalize_source_path($path) {
        $path = trim((string) $path);
        if (strlen($path) > 3) {
            $path = rtrim($path, "\\/");
        }
        return $path;
    }
}

if (!function_exists('valid_source_path')) {
    function valid_source_path($path) {
        return is_string($path)
            && $path !== ''
            && strlen($path) <= 260
            && substr($path, -1) !== '\\'
            && substr($path, -1) !== '/'
            && preg_match('/^[^\x00-\x1f]+$/', $path) === 1
            && is_dir($path);
    }
}

if (!function_exists('valid_project_path')) {
    /**
     * Paths handed to `explorer`/`start` end up inside a cmd.exe string, so
     * quotes and cmd metacharacters are rejected instead of escaped.
     * `escapeshellarg()` is not enough on Windows (`"` becomes `\"`).
     */
    function valid_project_path($path) {
        return valid_source_path($path)
            && preg_match('/["&|<>^\r\n\t]/', $path) !== 1;
    }
}

if (!function_exists('run_process_array')) {
    /**
     * Run a command without a shell. An argument array goes straight to the
     * OS, so database names and folders can never be read as shell syntax.
     *
     * stderr goes to a temp file instead of a second pipe: reading one pipe
     * while the child blocks on a full second pipe would deadlock.
     *
     * @return array{stdout: string, stderr: string, output: string, exit_code: int}
     */
    function run_process_array(array $command) {
        if (!function_exists('proc_open')) {
            return array('stdout' => '', 'stderr' => '', 'output' => 'Fungsi proc_open tidak tersedia di PHP ini.', 'exit_code' => -1);
        }

        $stderrFile = tempnam(sys_get_temp_dir(), 'servisan-stderr-');
        if ($stderrFile === false) {
            return array('stdout' => '', 'stderr' => '', 'output' => 'Gagal membuat file sementara.', 'exit_code' => -1);
        }

        $descriptors = array(
            1 => array('pipe', 'w'),
            2 => array('file', $stderrFile, 'w'),
        );
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($stderrFile);
            return array('stdout' => '', 'stderr' => '', 'output' => 'Gagal menjalankan perintah.', 'exit_code' => -1);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        $stderr = (string) @file_get_contents($stderrFile);
        @unlink($stderrFile);

        return array(
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => trim($stdout . $stderr),
            'exit_code' => $exitCode,
        );
    }
}

if (!function_exists('run_servisan_cli')) {
    function run_servisan_cli($docRoot, array $args) {
        return run_process_array(array_merge(array(servisan_executable($docRoot)), $args));
    }
}

if (!function_exists('check_mysql')) {
    function check_mysql($host, $port, $user, $pass) {
        if (class_exists('mysqli')) {
            // PHP 8.1+ throws on connection failure instead of setting connect_errno.
            try {
                $conn = @new mysqli($host, $user, $pass, null, $port);
                if ($conn->connect_errno) {
                    return array(false, 'mysqli: ' . $conn->connect_error);
                }
                $version = (string) $conn->server_info;
                $conn->close();
                return array(true, $version);
            } catch (\Exception $e) {
                return array(false, 'mysqli: ' . $e->getMessage());
            }
        }

        if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
            try {
                $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass);
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                return array(true, $version);
            } catch (PDOException $e) {
                return array(false, 'PDO: ' . $e->getMessage());
            }
        }

        return array(false, 'no mysqli or pdo_mysql extension loaded');
    }
}

if (!function_exists('tail_file')) {
    function tail_file($filepath, $lines = 50) {
        if (!file_exists($filepath)) {
            return "Log file not found: " . basename($filepath);
        }
        $file = @fopen($filepath, "r");
        if (!$file) {
            return "Cannot open log file: " . basename($filepath);
        }
        $buffer = array();
        while (($line = fgets($file)) !== false) {
            $buffer[] = $line;
            if (count($buffer) > $lines) {
                array_shift($buffer);
            }
        }
        fclose($file);
        if (empty($buffer)) {
            return "Log file is currently empty (" . basename($filepath) . ").";
        }
        return implode("", $buffer);
    }
}
