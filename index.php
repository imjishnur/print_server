<?php
/**
 * BIZPOZ Print Server - Console
 * Server status, the URL the POS posts to, setup checks, installed printers (with v1 / v2 test links)
 * and today's print jobs from /logs.
 */

function getServerIP()
{
    $ip = $_SERVER['SERVER_ADDR'] ?? '';
    if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
        $host = gethostname();
        $ip = gethostbyname($host);
    }
    return $ip;
}

function getConnectedPrinters()
{
    $printers = [];
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'powershell -Command "Get-Printer | Select-Object Name, Shared, ShareName, PrinterStatus | ConvertTo-Json"';
        $output = @shell_exec($cmd);
        if ($output) {
            $data = json_decode($output, true);
            if ($data) {
                if (isset($data['Name'])) {
                    $data = [$data];
                }
                foreach ($data as $p) {
                    $printers[] = [
                        'name' => $p['Name'] ?? 'Unknown',
                        'shared' => !empty($p['Shared']),
                        'share_name' => $p['ShareName'] ?? '',
                        'status' => ($p['PrinterStatus'] ?? 0) == 0 ? 'Ready' : 'Normal'
                    ];
                }
            }
        }
    }
    return $printers;
}

// Kept for older tools: POST a JSON payload here to print it as v1 (same as test.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $payloadJson = $_POST['payload'] ?? '';
    if (empty($payloadJson)) {
        echo json_encode(['success' => false, 'error' => 'Payload is empty']);
        exit;
    }
    $_POST['content_data'] = $payloadJson;
    $decoded = json_decode($payloadJson, true);
    $_POST['print_type'] = $decoded['print_type'] ?? 'BILL';
    include __DIR__ . '/restpos_printer_server.php';
    exit;
}

require_once __DIR__ . '/include/print_logger.php';
plog_timezone(); // "today" in local time, same as the logs

$serverIp = getServerIP();
$printers = getConnectedPrinters();
$cfg = is_file(__DIR__ . '/config.php') ? (array) include __DIR__ . '/config.php' : [];

$folder = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/print_server/index.php')), '/');
$postUrl = 'http://' . $serverIp . $folder . '/restpos_printer_server.php';

// today's jobs from the logs
$logDir = __DIR__ . '/logs/data';
$jobs = [];
$todayFile = $logDir . '/' . date('Y-m-d') . '.jsonl';
if (is_file($todayFile)) {
    foreach (file($todayFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $jobs[] = $j;
    }
}
$jobs = array_reverse($jobs);
$st = ['total' => count($jobs), 'v1' => 0, 'v2' => 0, 'failed' => 0, 'drawer' => 0];
foreach ($jobs as $j) {
    $st[$j['version'] === 'v2' ? 'v2' : 'v1']++;
    if (empty($j['success'])) $st['failed']++;
    if (($j['drawer'] ?? '') === 'opened') $st['drawer']++;
}

// setup checks
$logsOn = !isset($cfg['logs_enabled']) || $cfg['logs_enabled'];
$logsWritable = (is_dir($logDir) || @mkdir($logDir, 0777, true)) && is_writable($logDir);
$checks = [
    ['Folder is /print_server', $folder === '/print_server', 'The POS posts to /print_server/ — rename this folder (' . $folder . ') to print_server.'],
    ['PHP GD extension', extension_loaded('gd'), 'Needed for v2 image prints and Arabic in v1. Enable extension=gd in php.ini and restart Apache.'],
    ['Logs folder writable', !$logsOn || $logsWritable, 'logs/data is not writable, print logs are not saved.'],
    ['Print logs on', $logsOn, "Set 'logs_enabled' => true in config.php to see prints on Print logs."],
    ['A shared printer', (bool) array_filter($printers, fn ($p) => $p['shared']), 'Share the receipt printer in Windows (Printer properties > Sharing). The POS prints to its share name.'],
];

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIZPOZ Print Server</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 24px; min-height: 100vh; font-size: 14px; }
        a { color: #38bdf8; }
        .wrap { max-width: 1200px; margin: 0 auto; }
        .head { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; padding-bottom: 16px; border-bottom: 1px solid #334155; margin-bottom: 20px; }
        .head h1 { font-size: 24px; color: #38bdf8; display: flex; gap: 10px; align-items: center; }
        .pill { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: #065f46; color: #6ee7b7; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { background: #334155; color: #e2e8f0; border: 0; padding: 9px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .btn:hover { background: #475569; }
        .btn.primary { background: #0284c7; color: #fff; }
        .btn.v2 { background: #7c3aed; color: #fff; }
        .btn.sm { padding: 6px 10px; font-size: 12px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 18px; }
        .card h2 { font-size: 15px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .kv { display: grid; grid-template-columns: 130px minmax(0, 1fr); gap: 8px 12px; font-size: 13px; }
        .kv span:nth-child(odd) { color: #94a3b8; }
        .kv span:nth-child(even) { word-break: break-all; }
        code { font-family: Consolas, monospace; background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 2px 6px; color: #7dd3fc; font-size: 12.5px; }
        .url { display: flex; gap: 8px; align-items: center; margin-top: 14px; }
        .url code { flex: 1; padding: 9px 10px; overflow-x: auto; white-space: nowrap; }
        .warn { margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: #450a0a; border: 1px solid #7f1d1d; color: #fecaca; font-size: 13px; }
        .checks { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .checks li { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; }
        .checks .i { width: 20px; height: 20px; border-radius: 50%; flex: none; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
        .checks .ok { background: #065f46; color: #6ee7b7; }
        .checks .no { background: #7f1d1d; color: #fecaca; }
        .checks small { display: block; color: #fca5a5; margin-top: 2px; }
        .stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
        .stat { background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 10px; text-align: center; }
        .stat b { display: block; font-size: 20px; }
        .stat span { font-size: 11px; color: #94a3b8; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: #94a3b8; font-weight: 600; font-size: 12px; padding: 8px; border-bottom: 1px solid #334155; }
        td { padding: 9px 8px; border-bottom: 1px solid #263244; vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        .tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #334155; display: inline-block; }
        .t-v1 { background: #475569; } .t-v2 { background: #7c3aed; color: #fff; }
        .t-ok { background: #065f46; color: #6ee7b7; } .t-bad { background: #7f1d1d; color: #fecaca; }
        .muted { color: #94a3b8; }
        .actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
        .scroll { overflow-x: auto; }
        @media (max-width: 640px) { body { padding: 14px; } .stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } .kv { grid-template-columns: 1fr; gap: 2px; } .kv span:nth-child(even) { margin-bottom: 8px; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>🖨️ BIZPOZ Print Server <span class="pill">● Online</span></h1>
        <div class="nav">
            <a class="btn primary" href="test.php">Test print</a>
            <a class="btn" href="logs/">🧾 Print logs</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>🌐 Server</h2>
            <div class="kv">
                <span>Server IP</span><span><?= $h($serverIp) ?></span>
                <span>Host</span><span><?= $h(gethostname()) ?></span>
                <span>Folder</span><span><?= $h($folder) ?></span>
                <span>PHP</span><span><?= $h(PHP_VERSION) ?></span>
                <span>Supports</span><span>v1 text (ESC/POS) and v2 image prints</span>
                <span>Cash drawer</span><span>Pin 2 &amp; 5 · v2 follows the POS setting (open_cash_drawer)</span>
            </div>
            <div class="url">
                <code id="postUrl"><?= $h($postUrl) ?></code>
                <button class="btn sm" type="button" onclick="copyUrl(this)">Copy</button>
            </div>
            <p class="muted" style="font-size:12px;margin-top:6px">POS → Settings → Printers: use the server IP <b><?= $h($serverIp) ?></b> and the printer's share name.</p>
            <?php if ($folder !== '/print_server'): ?>
                <div class="warn">The POS always posts to <b>/print_server/</b>. This copy runs from <b><?= $h($folder) ?></b>, so the POS will not reach it — rename the folder to <b>print_server</b>.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>✅ Setup check</h2>
            <ul class="checks">
                <?php foreach ($checks as [$label, $ok, $fix]): ?>
                    <li>
                        <span class="i <?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✓' : '!' ?></span>
                        <span><?= $h($label) ?><?php if (!$ok): ?><small><?= $h($fix) ?></small><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2>🧾 Today's prints <a class="btn sm" href="logs/">Open print logs</a></h2>
        <div class="stats">
            <div class="stat"><b><?= $st['total'] ?></b><span>Jobs</span></div>
            <div class="stat"><b><?= $st['v1'] ?></b><span>v1 text</span></div>
            <div class="stat"><b><?= $st['v2'] ?></b><span>v2 image</span></div>
            <div class="stat"><b style="color:<?= $st['failed'] ? '#f87171' : 'inherit' ?>"><?= $st['failed'] ?></b><span>Failed</span></div>
            <div class="stat"><b><?= $st['drawer'] ?></b><span>Drawer opened</span></div>
        </div>
        <?php if (!$jobs): ?>
            <p class="muted">No prints yet today<?= $logsOn ? '' : ' (print logs are off in config.php)' ?>.</p>
        <?php else: ?>
            <div class="scroll">
            <table>
                <thead><tr><th>Time</th><th>Version</th><th>Type</th><th>Order</th><th>Printer</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($jobs, 0, 8) as $j): $ok = !empty($j['success']); ?>
                    <tr>
                        <td><?= $h(substr($j['time'] ?? '', 11)) ?></td>
                        <td><span class="tag <?= $j['version'] === 'v2' ? 't-v2' : 't-v1' ?>"><?= $h($j['version']) ?></span></td>
                        <td><?= $h($j['type'] ?? '') ?></td>
                        <td><?= $h($j['order'] ?: '-') ?></td>
                        <td><?= $h($j['printer'] ?: '-') ?></td>
                        <td><span class="tag <?= $ok ? 't-ok' : 't-bad' ?>"><?= $ok ? 'Printed' : 'Failed' ?></span><?php if (!$ok && !empty($j['error'])): ?> <span class="muted"><?= $h($j['error']) ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>🖨️ Installed printers (<?= count($printers) ?>) <span class="muted" style="font-size:12px;font-weight:400">The POS prints to the share name</span></h2>
        <?php if (!$printers): ?>
            <p class="muted">No printers found. Check that the printer is installed in Windows.</p>
        <?php else: ?>
            <div class="scroll">
            <table>
                <thead><tr><th>Printer</th><th>Share name</th><th>Status</th><th style="text-align:right">Test</th></tr></thead>
                <tbody>
                <?php foreach ($printers as $p): $share = $p['share_name']; ?>
                    <tr>
                        <td><?= $h($p['name']) ?></td>
                        <td><?= $p['shared'] && $share !== '' ? '<code>' . $h($share) . '</code>' : '<span class="muted">Not shared</span>' ?></td>
                        <td><span class="tag <?= $p['shared'] ? 't-ok' : '' ?>"><?= $p['shared'] ? $h($p['status']) : 'Not usable by POS' ?></span></td>
                        <td>
                            <?php if ($p['shared'] && $share !== ''): ?>
                                <div class="actions">
                                    <a class="btn sm" href="test.php?mode=v1&amp;printer=<?= rawurlencode($share) ?>">Test v1</a>
                                    <a class="btn sm v2" href="test.php?mode=v2&amp;printer=<?= rawurlencode($share) ?>">Test v2</a>
                                </div>
                            <?php else: ?>
                                <span class="muted" style="display:block;text-align:right;font-size:12px">Share it to test</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function copyUrl(btn) {
        var t = document.getElementById('postUrl').textContent;
        var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(t).then(done);
        } else {
            var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(ta);
        }
    }
</script>
</body>
</html>
