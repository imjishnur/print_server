<?php
/**
 * BIZPOZ Print Server - Interactive Test Dashboard
 * Test BILL and KOT payloads as v1 (text, printer_load.php) or v2 (image, printer_load_v2.php).
 * v2 renders the payload in the browser with assets/receipt-renderer.js (same renderer as the POS)
 * and sends the image exactly like POS v3 does. Every test print shows up on /logs.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // text preview of a v1 payload (nothing is printed)
    if (($_POST['action'] ?? '') === 'preview_v1') {
        require_once __DIR__ . '/include/print_preview.php';
        $d = json_decode($_POST['payload'] ?? '', true);
        if (!is_array($d)) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            exit;
        }
        echo json_encode(['success' => true, 'text' => v1Preview($d, strtoupper($d['print_type'] ?? 'BILL'))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // v2: image + printer fields, posted the same way as POS v3 -> pass straight through
    if (!empty($_POST['image_base64'])) {
        include __DIR__ . '/restpos_printer_server.php';
        exit;
    }

    // v1: JSON payload as content_data
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIZPOZ Print Server - Test Print</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, Arial, sans-serif; }
        body { background: #0f172a; color: #f8fafc; padding: 24px; min-height: 100vh; }
        a { color: #38bdf8; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #334155; }
        .header h1 { font-size: 24px; font-weight: 700; color: #38bdf8; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
        .card { background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 20px; }
        .group { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
        .group > span { font-size: 13px; color: #94a3b8; font-weight: 600; min-width: 70px; }
        .tab-btn { background: #334155; color: #94a3b8; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; }
        .tab-btn.active { background: #0284c7; color: white; }
        .tab-btn.active.v2 { background: #7c3aed; }
        .tab-btn:hover:not(.active) { background: #475569; color: #f8fafc; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #cbd5e1; font-size: 14px; }
        .ck { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; margin: 0; cursor: pointer; }
        textarea { width: 100%; height: 360px; background: #090d16; color: #38bdf8; border: 1px solid #334155; border-radius: 8px; padding: 14px; font-family: Consolas, "Courier New", monospace; font-size: 13px; line-height: 1.5; outline: none; resize: vertical; }
        textarea:focus { border-color: #38bdf8; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .btn { background: #10b981; color: white; border: none; padding: 11px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { background: #059669; }
        .btn-secondary { background: #475569; }
        .btn-secondary:hover { background: #64748b; }
        .hint { font-size: 12px; color: #94a3b8; margin-top: 10px; line-height: 1.5; }
        .result-box { margin-top: 14px; background: #090d16; border-radius: 8px; border: 1px solid #334155; padding: 14px; font-family: monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; display: none; }
        .result-box.success { border-color: #10b981; color: #34d399; }
        .result-box.error { border-color: #ef4444; color: #f87171; }
        .preview h2 { font-size: 15px; margin-bottom: 10px; color: #cbd5e1; }
        .paper { background: #fff; color: #000; border-radius: 4px; min-height: 120px; max-height: 75vh; overflow: auto; }
        .paper img { width: 100%; display: block; }
        .paper pre { font-family: Consolas, "Courier New", monospace; font-size: 10.5px; line-height: 1.35; padding: 10px; white-space: pre; }
        .paper .empty { color: #64748b; padding: 40px 16px; text-align: center; font-size: 13px; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🖨️ Test Print</h1>
        <div class="nav">
            <a class="tab-btn" href="./" style="text-decoration:none">Console</a>
            <a class="tab-btn" href="logs/" style="text-decoration:none">🧾 Print logs</a>
        </div>
    </div>

    <div class="layout">
        <div class="card">
            <div class="group">
                <span>Mode</span>
                <button class="tab-btn mode-btn active" data-mode="v1">v1 · Text (ESC/POS)</button>
                <button class="tab-btn mode-btn v2" data-mode="v2">v2 · Image</button>
            </div>
            <div class="group">
                <span>Payload</span>
                <button class="tab-btn type-btn active" data-type="BILL">🧾 BILL</button>
                <button class="tab-btn type-btn" data-type="KOT">👨‍🍳 KOT</button>
            </div>
            <div class="group" id="drawerGroup">
                <span>Drawer</span>
                <label class="ck"><input type="checkbox" id="drawer" checked> Open cash drawer</label>
            </div>

            <label for="payloadText">JSON payload (edit the printer, items, totals…)</label>
            <textarea id="payloadText" spellcheck="false"></textarea>

            <div class="actions">
                <button class="btn" id="btnSend">🚀 Send test print</button>
                <button class="btn btn-secondary" id="btnPreview">👁 Preview</button>
                <button class="btn btn-secondary" id="btnFormat">✨ Format JSON</button>
            </div>
            <p class="hint" id="modeHint"></p>
            <div id="resultBox" class="result-box"></div>
        </div>

        <div class="card preview">
            <h2 id="previewTitle">Preview</h2>
            <div class="paper" id="paper"><div class="empty">Press Preview to see what will print.</div></div>
        </div>
    </div>
</div>

<script src="assets/receipt-renderer.js"></script>
<script src="assets/sample-payloads.js"></script>
<script>
    // Samples in the exact format the POS sends (assets/sample-payloads.js).
    // Printer: "type": "windows" prints to the share name in "path"; "type": "network" uses "printer_ip_address" + "printer_port".
    const params = new URLSearchParams(location.search);
    const printerParam = params.get("printer");
    const samplePayloads = JSON.parse(JSON.stringify(window.BIZPOZ_SAMPLES || {}));
    if (printerParam) {
        Object.values(samplePayloads).forEach(p => { p.type = "windows"; p.path = printerParam; p.printer_name = printerParam; p.printer_ip_address = ""; });
    }
    let mode = 'v1';
    let type = 'BILL';
    const $ = (id) => document.getElementById(id);

    function setActive(selector, attr, value) {
        document.querySelectorAll(selector).forEach(b => b.classList.toggle('active', b.dataset[attr] === value));
    }

    function loadPayload(t) {
        type = t;
        setActive('.type-btn', 'type', t);
        $('payloadText').value = JSON.stringify(samplePayloads[t], null, 4);
        $('drawer').checked = (t === 'BILL');
        clearPreview();
    }

    function setMode(m) {
        mode = m;
        setActive('.mode-btn', 'mode', m);
        $('modeHint').innerHTML = m === 'v1'
            ? 'v1 sends the JSON as <b>content_data</b>; the server prints the <b>items / totals / payments</b> text (Arabic is turned into small images by the server).'
            : 'v2 draws the receipt in this browser from <b>items_list / totals_data / payments_data</b> (same renderer as the POS) and sends it as <b>image_base64</b>, exactly like POS v3.';
        clearPreview();
    }

    function clearPreview() {
        $('previewTitle').textContent = 'Preview · ' + mode + ' · ' + type;
        $('paper').innerHTML = '<div class="empty">Press Preview to see what will print.</div>';
    }

    function readPayload() {
        try {
            const p = JSON.parse($('payloadText').value);
            p.print_type = p.print_type || type;
            if (mode === 'v1') {
                p.open_cash_drawer_when_printing_invoice = $('drawer').checked ? 'Yes' : 'No';
            }
            return p;
        } catch (e) {
            showResult(false, '❌ Invalid JSON: ' + e.message);
            return null;
        }
    }

    function showResult(ok, text) {
        const box = $('resultBox');
        box.style.display = 'block';
        box.className = 'result-box ' + (ok ? 'success' : 'error');
        box.innerText = text;
    }

    function renderV2(p) {
        if (typeof window.renderArabicReceiptCanvas !== 'function') {
            return Promise.reject(new Error('assets/receipt-renderer.js is missing'));
        }
        return window.renderArabicReceiptCanvas(p, p.print_type === 'KOT' ? 'KOT' : 'INVOICE');
    }

    async function preview() {
        const p = readPayload();
        if (!p) return;
        $('previewTitle').textContent = 'Preview · ' + mode + ' · ' + p.print_type;
        $('paper').innerHTML = '<div class="empty">Rendering…</div>';
        try {
            if (mode === 'v2') {
                const b64 = await renderV2(p);
                $('paper').innerHTML = '<img alt="v2 receipt" src="data:image/png;base64,' + b64 + '">';
                return b64;
            }
            const fd = new FormData();
            fd.append('action', 'preview_v1');
            fd.append('payload', JSON.stringify(p));
            const r = await (await fetch('test.php', { method: 'POST', body: fd })).json();
            const pre = document.createElement('pre');
            pre.textContent = r.success ? r.text : (r.error || 'Preview failed');
            $('paper').innerHTML = '';
            $('paper').appendChild(pre);
        } catch (e) {
            $('paper').innerHTML = '<div class="empty">Preview failed: ' + e.message + '</div>';
        }
    }

    async function sendPrint() {
        const p = readPayload();
        if (!p) return;
        showResult(true, '⏳ Sending ' + mode + ' ' + p.print_type + ' to the print server…');

        const fd = new FormData();
        try {
            if (mode === 'v2') {
                const b64 = await preview();
                if (!b64) throw new Error('could not render the image');
                // same fields as POS v3 (printBillSilentlyV2 / printKotSilentlyV2)
                fd.append('image_base64', b64);
                fd.append('print_type', p.print_type);
                fd.append('print_server_version', 'v2');
                fd.append('printer_name', p.printer_name || p.path || 'POS-80C');
                fd.append('path', p.path || 'POS-80C');
                if (p.type === 'network') {
                    fd.append('printer_ip', p.printer_ip_address || '');
                    fd.append('printer_port', p.printer_port || 9100);
                }
                fd.append('connection_type', p.type === 'network' ? 'socket' : 'windows');
                fd.append('sale_no_p', p.sale_no_p || '');
                if (p.print_type !== 'KOT') {
                    fd.append('open_cash_drawer', $('drawer').checked ? 'Yes' : 'No');
                }
            } else {
                await preview();
                fd.append('payload', JSON.stringify(p));
            }
            const text = await (await fetch('test.php', { method: 'POST', body: fd })).text();
            let ok = false;
            try { ok = !!JSON.parse(text).success; } catch (e) {}
            showResult(ok, (ok ? '✅ PRINTED' : '⚠️ Server response') + '\n' + text + '\n\nSee it on Print logs.');
        } catch (err) {
            showResult(false, '❌ ' + err.message);
        }
    }

    document.querySelectorAll('.mode-btn').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));
    document.querySelectorAll('.type-btn').forEach(b => b.addEventListener('click', () => loadPayload(b.dataset.type)));
    $('btnSend').addEventListener('click', sendPrint);
    $('btnPreview').addEventListener('click', preview);
    $('btnFormat').addEventListener('click', () => {
        const p = readPayload();
        if (p) $('payloadText').value = JSON.stringify(JSON.parse($('payloadText').value), null, 4);
    });

    setMode(params.get('mode') === 'v2' ? 'v2' : 'v1');
    loadPayload(params.get('type') === 'KOT' ? 'KOT' : 'BILL');
</script>
</body>
</html>
