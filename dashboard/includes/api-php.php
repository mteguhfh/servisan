<?php

// PHP Version Manager actions: php_list, php_switch, php_install, php_install_status, php_delete

if (!isset($_GET['action'])) {
    return;
}

$action = $_GET['action'];

// Versions reach `servisan php ...` as argv, so keep them to digits and dots.
// That blocks flag injection such as `--switch` or `..\..\bin`.
if (!function_exists('valid_php_version')) {
    function valid_php_version($version) {
        return is_string($version) && preg_match('/^[0-9]+(\.[0-9]+){0,3}$/', $version) === 1;
    }
}

// Handle php_list action (list installed versions and download targets via servisan php list --json)
if ($action === 'php_list') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'php_list');

    $result = run_servisan_cli($docRoot, array('php', 'list', '--json'));
    $data = json_decode($result['stdout'], true);
    respond_json(200, array(
        'ok' => is_array($data),
        'installed' => (is_array($data) && isset($data['installed']) && is_array($data['installed'])) ? $data['installed'] : array(),
        'available' => (is_array($data) && isset($data['available']) && is_array($data['available'])) ? $data['available'] : array(),
        'active_php_version' => PHP_VERSION,
        'raw' => $result['output'],
    ));
}

// Handle php_switch action
if ($action === 'php_switch') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'php_switch');

    $target = isset($_POST['target']) ? trim((string) $_POST['target']) : '';
    if ($target === '') {
        respond_json(400, array('ok' => false, 'error' => 'No target PHP version specified'));
    }
    if (!valid_php_version($target)) {
        respond_json(400, array('ok' => false, 'error' => 'Versi PHP tidak valid: ' . $target));
    }
    $result = run_servisan_cli($docRoot, array('php', 'switch', $target));
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Gagal mengganti versi PHP (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array('ok' => true, 'output' => $result['output']));
}

// Handle php_install action
if ($action === 'php_install') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'php_install');

    $version = isset($_POST['version']) ? trim((string) $_POST['version']) : '';
    $switch = !empty($_POST['switch']) && $_POST['switch'] !== 'false' && $_POST['switch'] !== '0';
    if ($version === '') {
        respond_json(400, array('ok' => false, 'error' => 'No version specified'));
    }
    if (!valid_php_version($version)) {
        respond_json(400, array('ok' => false, 'error' => 'Versi PHP tidak valid: ' . $version));
    }
    $args = array('php', 'install', $version);
    if ($switch) {
        $args[] = '--switch';
    }
    $result = run_servisan_cli($docRoot, $args);
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Gagal menginstal PHP (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array('ok' => true, 'output' => $result['output']));
}

// Handle php_install_status action (poll real-time download and extraction progress)
if ($action === 'php_install_status') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'php_install_status');

    $version = isset($_GET['version']) ? trim((string) $_GET['version']) : '';
    $tmpDir = dirname($docRoot) . DIRECTORY_SEPARATOR . 'tmp';

    // Look for any active download zip files
    $zipFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'php-*-download.zip');
    $bytes = 0;
    $activeFile = null;
    if (!empty($zipFiles)) {
        $activeFile = $zipFiles[0];
        clearstatcache(true, $activeFile);
        $bytes = (int) @filesize($activeFile);
    }

    // Check status file if present
    $statusFile = $tmpDir . DIRECTORY_SEPARATOR . 'php-install.status';
    $statusContent = '';
    if (file_exists($statusFile)) {
        $statusContent = trim((string) @file_get_contents($statusFile));
    }

    // Official x64 zip is approximately 35 MB
    $estimatedTotal = 36700160;

    $stage = 'connecting';
    if ($bytes > 0) {
        $stage = 'downloading';
    }
    if (substr($statusContent, 0, 10) === 'extracting') {
        $stage = 'extracting';
    } elseif (substr($statusContent, 0, 11) === 'configuring') {
        $stage = 'configuring';
    } elseif (substr($statusContent, 0, 4) === 'done') {
        $stage = 'done';
    }

    respond_json(200, array(
        'ok' => true,
        'stage' => $stage,
        'bytes' => $bytes,
        'estimated_total' => $estimatedTotal,
        'status_raw' => $statusContent,
        'version' => $version,
        'file' => $activeFile ? basename($activeFile) : null,
    ));
}

// Handle php_delete action (uninstall and remove an inactive PHP runtime from bin/php/*)
if ($action === 'php_delete') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'php_delete');

    $target = isset($_POST['target']) ? trim((string) $_POST['target']) : '';
    if ($target === '') {
        respond_json(400, array('ok' => false, 'error' => 'No target specified'));
    }
    if (!valid_php_version($target)) {
        respond_json(400, array('ok' => false, 'error' => 'Versi PHP tidak valid: ' . $target));
    }
    $result = run_servisan_cli($docRoot, array('php', 'remove', $target));
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Gagal menghapus PHP (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array('ok' => true, 'output' => $result['output']));
}
