<?php
# HitWatch4Lox - Daemon Steuerung
require_once "loxberry_system.php";

$action = $_GET['action'] ?? '';

# Letzten Update-Timestamp + Daemon-Status zurückgeben (für Auto-Refresh in app_status.php)
if ($action === 'check_update') {
    header('Content-Type: application/json');
    $sf    = $lbpdatadir . '/state.json';
    $state = file_exists($sf) ? (json_decode(file_get_contents($sf), true) ?? []) : [];

    $pidfile = $lbplogdir . '/daemon.pid';
    $pid     = file_exists($pidfile) ? trim(file_get_contents($pidfile)) : '';
    $running = false;
    if ($pid && is_numeric($pid)) {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        $running = ($cmdline !== false && (
            strpos($cmdline, 'hitwatch4lox_daemon') !== false ||
            strpos($cmdline, 'hitwatch4lox') !== false
        ));
    }

    echo json_encode([
        'epoch'   => (int)($state['last_check_epoch'] ?? 0),
        'status'  => $state['status'] ?? 'OK',
        'running' => $running,
    ]);
    exit;
}

# Daemon-Steuerung als JSON (für AJAX-Aufrufe ohne Seiten-Redirect)
if ($action === 'restart_json' || $action === 'start_json' || $action === 'stop_json') {
    header('Content-Type: application/json');
    $cmd_action = str_replace('_json', '', $action);
    $daemonscript = $lbhomedir . "/system/daemons/plugins/" . $lbpplugindir;
    if (!file_exists($daemonscript)) {
        echo json_encode(['ok' => false, 'msg' => 'Daemon-Script nicht gefunden.']);
        exit;
    }
    $cmd = "sudo " . escapeshellarg($daemonscript) . " " . escapeshellarg($cmd_action) . " 2>&1";
    shell_exec($cmd);
    echo json_encode(['ok' => true, 'action' => $cmd_action]);
    exit;
}

if (!in_array($action, ['start', 'stop', 'restart'])) {
    header("Location: index.php");
    exit;
}

$daemonscript = $lbhomedir . "/system/daemons/plugins/" . $lbpplugindir;
$cmd = "sudo " . escapeshellarg($daemonscript) . " " . escapeshellarg($action) . " 2>&1";
$out = shell_exec($cmd);
sleep(1);

header("Location: index.php");
exit;
