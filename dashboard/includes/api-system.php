<?php

// System actions: open_root, open_terminal, shutdown, open_logs, get_logs

if (!isset($_GET['action'])) {
    return;
}

$action = $_GET['action'];

// Handle open_root action (open the web root in Windows File Explorer)
if ($action === 'open_root') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'open_root');
    if ($isWindows) {
        pclose(popen('explorer.exe ' . escapeshellarg($docRoot), 'r'));
    }
    respond_json(200, array('ok' => true));
}

// Handle open_terminal action (open a shell window via the servisan CLI)
if ($action === 'open_terminal') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'open_terminal');
    if ($isWindows) {
        // `servisan shell` sets its own working directory and PATH, so the
        // command stays a single, fully escaped executable invocation.
        $exe = get_servisan_cmd();
        pclose(popen('start "" ' . $exe . ' shell', 'r'));
    }
    respond_json(200, array('ok' => true));
}

// Handle shutdown action (gracefully stop all services and terminate background servisan process)
if ($action === 'shutdown') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'shutdown');
    if ($isWindows) {
        $exe = get_servisan_cmd();
        // Execute graceful stop followed by tray taskkill in a detached process with a 1s pause
        // so PHP has ample time to flush the HTTP 200 response cleanly to the client before exiting.
        $cmd = 'cmd /c "start /b "" cmd /c (timeout /t 1 /nobreak >nul & ' . $exe . ' stop & taskkill /F /IM servisan.exe >nul 2>&1)"';
        @pclose(@popen($cmd, 'r'));
    }
    respond_json(200, array(
        'ok' => true,
        'message' => 'Servisan services and tray process are shutting down.',
    ));
}

// Handle open_logs action (open logs folder in Windows File Explorer)
if ($action === 'open_logs') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'open_logs');
    if ($isWindows) {
        $logsDir = get_servisan_home() . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
        }
        pclose(popen('explorer.exe ' . escapeshellarg($logsDir), 'r'));
    }
    respond_json(200, array('ok' => true));
}

// Handle get_logs action (return last 50 lines in JSON)
if ($action === 'get_logs') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'get_logs');
    $type = isset($_GET['type']) ? $_GET['type'] : 'php';
    $logsDir = get_servisan_home() . DIRECTORY_SEPARATOR . 'logs';
    switch ($type) {
        case 'mysql':
            $filename = 'mysql.log';
            break;
        case 'servisan':
            $filename = 'servisan.log';
            break;
        default:
            $filename = 'php.log';
            break;
    }
    $content = tail_file($logsDir . DIRECTORY_SEPARATOR . $filename, 50);
    respond_json(200, array(
        'ok' => true,
        'type' => $type,
        'filename' => $filename,
        'content' => $content,
    ));
}
