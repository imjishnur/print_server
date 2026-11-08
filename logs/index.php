<?php
/**
 * BIZPOZ Print Server - Print logs
 * Every job sent to restpos_printer_server.php (v1 text and v2 image) with a preview of what printed.
 * Data: logs/data/YYYY-MM-DD.jsonl + logs/data/jobs/<id>.png|json (written by include/print_logger.php)
 */

$dataDir = __DIR__ . '/data';
$cfg = is_file(__DIR__ . '/../config.php') ? (array) include __DIR__ . '/../config.php' : [];

// available days, newest first
$days = [];
foreach (glob($dataDir . '/*.jsonl') ?: [] as $f) {
    $days[] = basename($f, '.jsonl');
}
rsort($days);

$day = $_GET['day'] ?? ($days[0] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}

// clear one day
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    $clear = $_POST['day'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clear)) {
        @unlink("$dataDir/$clear.jsonl");
        foreach (glob("$dataDir/jobs/" . str_replace('-', '', $clear) . '-*') ?: [] as $f) {
            @unlink($f);
        }
    }
    header('Location: ./');
    exit;
}

// read jobs of the day, newest first
$jobs = [];
$file = "$dataDir/$day.jsonl";
if (is_file($file)) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $j = json_decode($line, true);
        if (is_array($j)) {
            $jobs[] = $j;
        }
    }
}
$jobs = array_reverse($jobs);

$stats = ['total' => count($jobs), 'v1' => 0, 'v2' => 0, 'failed' => 0, 'drawer' => 0];
foreach ($jobs as $j) {
    $stats[$j['version'] === 'v2' ? 'v2' : 'v1']++;
    if (empty($j['success'])) $stats['failed']++;
    if (($j['drawer'] ?? '') === 'opened') $stats['drawer']++;
}

$limit = 200;
$shown = array_slice($jobs, 0, $limit);

require_once __DIR__ . '/../include/print_preview.php';

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$autoRefresh = isset($_GET['live']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Logs - BIZPOZ Print Server</title>
    <?php if ($autoRefresh): ?><meta http-equiv="refresh" content="5"><?php endif; ?>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; min-height: 100vh; }
        a { color: #38bdf8; }
        .wrap { max-width: 1400px; margin: 0 auto; }
        .head { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; padding-bottom: 14px; border-bottom: 1px solid #334155; margin-bottom: 16px; }
        .head h1 { font-size: 22px; color: #38bdf8; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { background: #334155; color: #e2e8f0; border: 0; padding: 8px 14px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: #475569; }
        .btn.on { background: #0284c7; color: #fff; }
        .btn.danger { background: #7f1d1d; }
        .bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        select, input[type=search] { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; border-radius: 8px; padding: 8px 10px; font: inherit; font-size: 13px; }
        .stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .stat { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 10px 16px; min-width: 110px; }
        .stat b { display: block; font-size: 22px; }
        .stat span { font-size: 12px; color: #94a3b8; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }
        .job { background: #1e293b; border: 1px solid #334155; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
        .job.fail { border-color: #b91c1c; }
        .meta { padding: 10px 12px; font-size: 12px; display: flex; flex-direction: column; gap: 6px; border-bottom: 1px solid #334155; }
        .row1 { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .tag { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #334155; }
        .v1 { background: #475569; } .v2 { background: #7c3aed; color: #fff; }
        .ok { background: #065f46; color: #6ee7b7; } .bad { background: #7f1d1d; color: #fecaca; }
        .drw { background: #78350f; color: #fde68a; }
        .muted { color: #94a3b8; }
        .err { color: #fca5a5; }
        .paper { background: #fff; color: #000; margin: 10px; border-radius: 4px; max-height: 520px; overflow: auto; cursor: zoom-in; }
        .paper img { width: 100%; display: block; }
        .paper pre { font-family: Consolas, "Courier New", monospace; font-size: 10.5px; line-height: 1.35; padding: 10px; white-space: pre; }
        .empty { text-align: center; padding: 60px 20px; color: #94a3b8; background: #1e293b; border-radius: 12px; border: 1px dashed #334155; }
        .modal { position: fixed; inset: 0; background: rgba(2,6,23,.85); display: none; align-items: flex-start; justify-content: center; overflow: auto; padding: 30px 10px; z-index: 10; }
        .modal.open { display: flex; }
        .modal .paper { max-height: none; width: 100%; max-width: 600px; cursor: zoom-out; }
        .modal .paper pre { font-size: 13px; }
        @media (max-width: 600px) { body { padding: 12px; } .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>🧾 Print Logs</h1>
        <div class="nav">
            <a class="btn" href="../">Console</a>
            <a class="btn" href="../test.php">Test print</a>
            <a class="btn <?= $autoRefresh ? 'on' : '' ?>" href="?day=<?= $h($day) ?><?= $autoRefresh ? '' : '&live=1' ?>"><?= $autoRefresh ? '● Live (5s)' : 'Live refresh' ?></a>
        </div>
    </div>

    <div class="bar">
        <form method="get" style="display:flex;gap:8px;align-items:center">
            <?php if ($autoRefresh): ?><input type="hidden" name="live" value="1"><?php endif; ?>
            <select name="day" onchange="this.form.submit()" aria-label="Day">
                <?php if (!in_array($day, $days, true)): ?><option value="<?= $h($day) ?>"><?= $h($day) ?></option><?php endif; ?>
                <?php foreach ($days as $d): ?>
                    <option value="<?= $h($d) ?>" <?= $d === $day ? 'selected' : '' ?>><?= $h($d) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <span class="nav" id="filters">
            <button class="btn on" data-f="all">All</button>
            <button class="btn" data-f="v1">v1</button>
            <button class="btn" data-f="v2">v2</button>
            <button class="btn" data-f="BILL">Bills</button>
            <button class="btn" data-f="KOT">KOT</button>
            <button class="btn" data-f="fail">Failed</button>
        </span>
        <input type="search" id="q" placeholder="Search order / printer" aria-label="Search">
        <?php if ($jobs): ?>
        <form method="post" onsubmit="return confirm('Delete all logs of <?= $h($day) ?>?')" style="margin-left:auto">
            <input type="hidden" name="action" value="clear"><input type="hidden" name="day" value="<?= $h($day) ?>">
            <button class="btn danger" type="submit">Clear day</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="stats">
        <div class="stat"><b><?= $stats['total'] ?></b><span>Jobs</span></div>
        <div class="stat"><b><?= $stats['v1'] ?></b><span>v1 (text)</span></div>
        <div class="stat"><b><?= $stats['v2'] ?></b><span>v2 (image)</span></div>
        <div class="stat"><b style="color:<?= $stats['failed'] ? '#f87171' : 'inherit' ?>"><?= $stats['failed'] ?></b><span>Failed</span></div>
        <div class="stat"><b><?= $stats['drawer'] ?></b><span>Drawer opened</span></div>
    </div>

    <?php if (!$jobs): ?>
        <div class="empty">
            No print jobs logged on <?= $h($day) ?>.<br><br>
            Print a bill from the POS or send one from <a href="../test.php">Test print</a>.
            <?php if (isset($cfg['logs_enabled']) && !$cfg['logs_enabled']): ?><br><br><span class="err">Logging is off: set 'logs_enabled' => true in config.php.</span><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid">
        <?php foreach ($shown as $j):
            $type = $j['type'] ?? '';
            $ok = !empty($j['success']);
            $prev = $j['preview'] ?? '';
            $search = strtolower(($j['order'] ?? '') . ' ' . ($j['printer'] ?? '') . ' ' . ($j['target'] ?? ''));
        ?>
            <div class="job <?= $ok ? '' : 'fail' ?>" data-v="<?= $h($j['version']) ?>" data-t="<?= $h($type) ?>" data-ok="<?= $ok ? 1 : 0 ?>" data-s="<?= $h($search) ?>">
                <div class="meta">
                    <div class="row1">
                        <span class="tag <?= $j['version'] === 'v2' ? 'v2' : 'v1' ?>"><?= $h($j['version']) ?></span>
                        <span class="tag"><?= $h($type) ?></span>
                        <span class="tag <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Printed' : 'Failed' ?></span>
                        <?php if (($j['drawer'] ?? '') === 'opened'): ?><span class="tag drw">Drawer opened</span><?php endif; ?>
                        <span class="muted" style="margin-left:auto"><?= $h(substr($j['time'] ?? '', 11)) ?></span>
                    </div>
                    <div><?= $j['order'] !== '' ? '<b>' . $h($j['order']) . '</b> · ' : '' ?><?= $h($j['printer'] ?: '-') ?><?= ($j['target'] ?? '') !== '' && $j['target'] !== $j['printer'] ? ' → ' . $h($j['target']) : '' ?></div>
                    <div class="muted"><?= $h($j['method'] ?: '-') ?><?= isset($j['ms']) ? ' · ' . (int) $j['ms'] . ' ms' : '' ?> · from <?= $h($j['client'] ?? '') ?><?= ($j['method'] ?? '') === 'popup_fallback' || ($j['method'] ?? '') === 'popup' ? ' · <span class="err">went to fallback POS-80C</span>' : '' ?></div>
                    <?php if (!$ok && !empty($j['error'])): ?><div class="err"><?= $h($j['error']) ?></div><?php endif; ?>
                </div>
                <?php if ($prev && is_file("$dataDir/jobs/$prev")): ?>
                    <div class="paper" onclick="zoom(this)">
                        <?php if (substr($prev, -4) === '.png'): ?>
                            <img src="data/jobs/<?= $h($prev) ?>" alt="Printed image" loading="lazy">
                        <?php else:
                            $d = json_decode((string) file_get_contents("$dataDir/jobs/$prev"), true) ?: []; ?>
                            <pre><?= $h(v1Preview($d, $type)) ?></pre>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="muted" style="padding:14px">No preview saved.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if (count($jobs) > $limit): ?><p class="muted" style="margin-top:12px">Showing the latest <?= $limit ?> of <?= count($jobs) ?> jobs.</p><?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal" id="modal" onclick="this.classList.remove('open')"></div>

<script>
    var filter = 'all';
    function apply() {
        var q = document.getElementById('q').value.trim().toLowerCase();
        document.querySelectorAll('.job').forEach(function (el) {
            var f = filter === 'all'
                || (filter === 'v1' && el.dataset.v === 'v1')
                || (filter === 'v2' && el.dataset.v === 'v2')
                || (filter === 'BILL' && (el.dataset.t === 'BILL' || el.dataset.t === 'INVOICE'))
                || (filter === 'KOT' && el.dataset.t === 'KOT')
                || (filter === 'fail' && el.dataset.ok === '0');
            el.style.display = f && (!q || el.dataset.s.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    document.querySelectorAll('#filters .btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#filters .btn').forEach(function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            filter = b.dataset.f;
            apply();
        });
    });
    document.getElementById('q').addEventListener('input', apply);
    function zoom(el) {
        var m = document.getElementById('modal');
        m.innerHTML = '';
        var c = el.cloneNode(true);
        c.onclick = null;
        m.appendChild(c);
        m.classList.add('open');
    }
</script>
</body>
</html>
