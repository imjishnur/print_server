<?php
/**
 * BIZPOZ Print Server - Auto-Update Runner
 *
 * Can be triggered three ways:
 *   1. In the browser via AJAX from index.php (normal path, respects the
 *      configured interval).
 *   2. Manually in a browser: check_update.php?force=1
 *   3. From the command line / Windows Task Scheduler:
 *        php check_update.php            (respects interval)
 *        php check_update.php --force    (checks immediately)
 *
 * This never throws to the caller - AutoUpdater::checkAndUpdate() always
 * returns a structured result, success or failure.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/include/auto_updater.php';

$isCli = (php_sapi_name() === 'cli');

// ---------------------------------------------------------------------
// Basic access guard for the web-triggered path: only allow local /
// private-network requests by default, so this endpoint can't be poked
// from the open internet. Adjust here if your setup needs something else.
// ---------------------------------------------------------------------
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || preg_match('/^10\./', $remote)
        || preg_match('/^192\.168\./', $remote)
        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $remote);

    if (!$isPrivate) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden: updates can only be triggered from the local network']);
        exit;
    }
}

$config = @include __DIR__ . '/config.php';
if (!is_array($config)) {
    $result = ['ok' => false, 'status' => 'error', 'error' => 'config.php could not be loaded'];
} else {
    $force = $isCli
        ? in_array('--force', $argv ?? [], true)
        : (($_GET['force'] ?? '') === '1');

    $updater = new AutoUpdater($config['auto_update'] ?? [], __DIR__);
    $result = $updater->checkAndUpdate($force);
}

if ($isCli) {
    $status = $result['status'] ?? ($result['ok'] ? 'ok' : 'error');
    echo "Auto-update check: {$status}\n";
    foreach ($result as $key => $val) {
        if (is_array($val)) {
            echo "  {$key}: " . json_encode($val) . "\n";
        } else {
            echo "  {$key}: {$val}\n";
        }
    }
    exit($result['ok'] ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($result);
