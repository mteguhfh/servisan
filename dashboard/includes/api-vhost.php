<?php

// Virtual Hosts actions: vhost_list, vhost_detect, vhost_add, vhost_remove, open_project_folder, open_project_terminal

if (!isset($_GET['action'])) {
    return;
}

$action = $_GET['action'];

// Names and domains reach `servisan vhost ...` as argv and the hosts file,
// so allowlist them instead of relying on escaping.
if (!function_exists('valid_vhost_name')) {
    function valid_vhost_name($name) {
        return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) === 1;
    }
}

if (!function_exists('valid_vhost_domain')) {
    function valid_vhost_domain($domain) {
        return is_string($domain)
            && strlen($domain) <= 253
            && preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $domain) === 1
            && strpos($domain, '..') === false;
    }
}

// Handle vhost_list action (list registered virtual hosts)
if ($action === 'vhost_list') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'vhost_list');

    $result = run_servisan_cli($docRoot, array('vhost', 'list', '--json'));
    $vhosts = json_decode($result['stdout'], true);
    respond_json(200, array(
        'ok' => is_array($vhosts),
        'vhosts' => is_array($vhosts) ? $vhosts : array(),
        'port' => isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 8080,
        'raw' => $result['output'],
    ));
}

// Handle vhost_detect action (inspect folder, detect framework and suggest domain)
if ($action === 'vhost_detect') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'vhost_detect');

    $path = isset($_GET['path']) ? trim((string) $_GET['path']) : '';
    if ($path === '' || !is_dir($path)) {
        respond_json(400, array('ok' => false, 'error' => 'Path folder tidak ditemukan atau bukan direktori.'));
    }
    $publicDir = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'public';
    $hasPublic = is_dir($publicDir);
    $isLaravel = file_exists(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'artisan');
    $hasWp = file_exists(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'wp-config.php');

    $docRootVhost = $hasPublic ? $publicDir : $path;
    $framework = 'Standard PHP (root)';
    if ($isLaravel) {
        $framework = 'Laravel (public/)';
    } elseif ($hasPublic) {
        $framework = 'Modern Framework (public/)';
    } elseif ($hasWp) {
        $framework = 'WordPress (root)';
    }

    $rawName = basename(rtrim($path, '/\\'));
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($rawName));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'project';
    }

    respond_json(200, array(
        'ok' => true,
        'path' => $path,
        'name' => $slug,
        'domain' => $slug . '.test',
        'doc_root' => $docRootVhost,
        'framework' => $framework,
    ));
}

// Handle vhost_add action
if ($action === 'vhost_add') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'vhost_add');

    $path = isset($_POST['path']) ? trim((string) $_POST['path']) : '';
    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    $domain = isset($_POST['domain']) ? trim((string) $_POST['domain']) : '';
    if ($path === '') {
        respond_json(400, array('ok' => false, 'error' => 'Path project tidak boleh kosong.'));
    }
    if (!valid_project_path($path)) {
        respond_json(400, array('ok' => false, 'error' => 'Path project tidak valid: ' . $path));
    }
    if ($name !== '' && !valid_vhost_name($name)) {
        respond_json(400, array('ok' => false, 'error' => 'Nama vhost tidak valid: ' . $name));
    }
    if ($domain !== '' && !valid_vhost_domain($domain)) {
        respond_json(400, array('ok' => false, 'error' => 'Domain tidak valid: ' . $domain));
    }

    $args = array('vhost', 'add', $path);
    if ($name !== '') {
        $args[] = '--name';
        $args[] = $name;
    }
    if ($domain !== '') {
        $args[] = '--domain';
        $args[] = $domain;
    }

    $result = run_servisan_cli($docRoot, $args);
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Gagal menambah vhost (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array('ok' => true, 'output' => $result['output']));
}

// Handle vhost_remove action
if ($action === 'vhost_remove') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'vhost_remove');

    $target = isset($_POST['target']) ? trim((string) $_POST['target']) : '';
    if ($target === '') {
        respond_json(400, array('ok' => false, 'error' => 'Target vhost tidak boleh kosong.'));
    }
    if (!valid_vhost_name($target) && !valid_vhost_domain($target)) {
        respond_json(400, array('ok' => false, 'error' => 'Target vhost tidak valid: ' . $target));
    }

    $result = run_servisan_cli($docRoot, array('vhost', 'delete', $target));
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Gagal menghapus vhost (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array('ok' => true, 'output' => $result['output']));
}

// Handle open_project_folder action (open folder in Windows File Explorer)
if ($action === 'open_project_folder') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'open_project_folder');

    $path = isset($_POST['path']) ? trim((string) $_POST['path']) : '';
    if ($path !== '' && !valid_project_path($path)) {
        respond_json(400, array('ok' => false, 'error' => 'Path project tidak valid: ' . $path));
    }
    if ($path !== '' && $isWindows) {
        pclose(popen('explorer.exe ' . escapeshellarg($path), 'r'));
    }
    respond_json(200, array('ok' => true));
}

// Handle open_project_terminal action (open terminal with Servisan environment at folder)
if ($action === 'open_project_terminal') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'open_project_terminal');

    $path = isset($_POST['path']) ? trim((string) $_POST['path']) : '';
    if ($path !== '' && !valid_project_path($path)) {
        respond_json(400, array('ok' => false, 'error' => 'Path project tidak valid: ' . $path));
    }
    if ($path !== '' && $isWindows) {
        // valid_project_path() rejects quotes and cmd metacharacters, so the
        // path and the executable stay literal arguments to `cd /d` and the
        // shell command. No user text is concatenated unescaped.
        $cmd = 'start "" cmd /k cd /d ' . escapeshellarg($path) . ' && ' . get_servisan_cmd() . ' shell';
        pclose(popen($cmd, 'r'));
    }
    respond_json(200, array('ok' => true));
}
