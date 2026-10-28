<?php

// Database Migration actions: migrate_scan, migrate_import

if (!isset($_GET['action'])) {
    return;
}

$action = $_GET['action'];

// Handle migrate_scan action (scan for local server databases via servisan migrate list --json)
if ($action === 'migrate_scan') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, false, 'migrate_scan');

    $from = normalize_source_path(isset($_GET['from']) ? $_GET['from'] : '');
    if ($from !== '' && !valid_source_path($from)) {
        respond_json(400, array('ok' => false, 'error' => 'Folder sumber tidak ditemukan: ' . $from));
    }

    $args = array('migrate', 'list');
    if ($from !== '') {
        $args[] = '--from';
        $args[] = $from;
    }
    $args[] = '--json';

    $result = run_servisan_cli($docRoot, $args);
    $sources = json_decode($result['stdout'], true);
    $payload = array(
        'ok' => is_array($sources),
        'sources' => is_array($sources) ? $sources : array(),
        'servisan_mysql_ok' => isset($mysqlOk) ? $mysqlOk : false,
        'from' => $from,
        'raw' => $result['output'],
        'exit_code' => $result['exit_code'],
    );
    if (!is_array($sources)) {
        $payload['error'] = 'Gagal memindai database: ' . (trim($result['stderr']) !== '' ? trim($result['stderr']) : 'output bukan JSON');
    }
    respond_json(200, $payload);
}

// Handle migrate_import action (import selected databases into Servisan MySQL)
if ($action === 'migrate_import') {
    require_action_allowed($isLoopbackRequest, $dashboardToken, true, 'migrate_import');

    $dbs = isset($_POST['databases']) ? $_POST['databases'] : array();
    if (is_string($dbs)) {
        $dbs = array_filter(array_map('trim', explode(',', $dbs)));
    }
    if (!is_array($dbs)) {
        respond_json(400, array('ok' => false, 'error' => 'Daftar database tidak valid.'));
    }
    foreach ($dbs as $db) {
        if (!is_string($db)) {
            respond_json(400, array('ok' => false, 'error' => 'Daftar database tidak valid.'));
        }
    }
    $dbs = array_values(array_unique(array_map(static function ($db) {
        return trim($db);
    }, $dbs)));
    $dbs = array_values(array_filter($dbs, static function ($db) {
        return $db !== '';
    }));
    if ($dbs === array()) {
        // An empty filter would make the CLI import every database it finds.
        respond_json(400, array('ok' => false, 'error' => 'Pilih setidaknya satu database untuk diimpor.'));
    }
    foreach ($dbs as $db) {
        if (!valid_database_name($db)) {
            respond_json(400, array('ok' => false, 'error' => 'Nama database tidak valid: ' . $db));
        }
    }

    $overwrite = !empty($_POST['overwrite']) && $_POST['overwrite'] !== 'false' && $_POST['overwrite'] !== '0';
    $from = normalize_source_path(isset($_POST['from']) ? $_POST['from'] : '');
    if ($from !== '' && !valid_source_path($from)) {
        respond_json(400, array('ok' => false, 'error' => 'Folder sumber tidak ditemukan: ' . $from));
    }

    $args = array('migrate', 'import');
    if ($from !== '') {
        $args[] = '--from';
        $args[] = $from;
    }
    foreach ($dbs as $db) {
        $args[] = $db;
    }
    if ($overwrite) {
        $args[] = '--overwrite';
    }

    $result = run_servisan_cli($docRoot, $args);
    if ($result['exit_code'] !== 0) {
        respond_json(500, array(
            'ok' => false,
            'error' => 'Impor database gagal (exit code ' . $result['exit_code'] . ').',
            'output' => $result['output'],
        ));
    }
    respond_json(200, array(
        'ok' => true,
        'output' => $result['output'],
    ));
}
