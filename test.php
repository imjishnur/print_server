<?php
/**
 * BIZPOZ Print Server v2 - Interactive Test Dashboard
 * Test BILL, INVOICE, and KOT print payloads interactively via browser.
 */

// If POST request sent to this script via AJAX/Form, proxy to restpos_printer_server.php
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

    ob_start();
    include __DIR__ . '/restpos_printer_server.php';
    $output = ob_get_clean();
    echo $output;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BIZPOZ Print Server v2 - Test Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #0f172a; color: #f8fafc; padding: 24px; min-height: 100vh; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #334155; }
        .header h1 { font-size: 24px; font-weight: 700; color: #38bdf8; display: flex; align-items: center; gap: 10px; }
        .badge { background: #0284c7; color: white; font-size: 12px; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
        .card { background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); }
        .tabs { display: flex; gap: 10px; margin-bottom: 16px; }
        .tab-btn { background: #334155; color: #94a3b8; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .tab-btn.active { background: #0284c7; color: white; }
        .tab-btn:hover:not(.active) { background: #475569; color: #f8fafc; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #cbd5e1; font-size: 14px; }
        textarea { width: 100%; height: 260px; background: #090d16; color: #38bdf8; border: 1px solid #334155; border-radius: 8px; padding: 14px; font-family: 'Consolas', 'Courier New', monospace; font-size: 13px; line-height: 1.5; outline: none; resize: vertical; }
        textarea:focus { border-color: #38bdf8; box-shadow: 0 0 0 2px rgba(56,189,248,0.2); }
        .actions { display: flex; gap: 12px; margin-top: 16px; }
        .btn { background: #10b981; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px; }
        .btn:hover { background: #059669; transform: translateY(-1px); }
        .btn-secondary { background: #475569; }
        .btn-secondary:hover { background: #64748b; }
        .result-box { margin-top: 16px; background: #090d16; border-radius: 8px; border: 1px solid #334155; padding: 16px; font-family: monospace; font-size: 13px; min-height: 80px; display: none; }
        .result-box.success { border-color: #10b981; color: #34d399; }
        .result-box.error { border-color: #ef4444; color: #f87171; }
        .quick-info { font-size: 13px; color: #94a3b8; margin-top: 8px; display: flex; gap: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🖨️ BIZPOZ Thermal Print Server v2</h1>
            <span class="badge">Version 2.0 (80mmESC/POS)</span>
        </div>

        <div class="card">
            <div class="tabs">
                <button class="tab-btn active" onclick="loadPayload('BILL')">🧾 BILL / Invoice Payload</button>
                <button class="tab-btn" onclick="loadPayload('KOT')">👨‍🍳 KOT Payload</button>
            </div>

            <label for="payloadText">JSON Print Payload (Copy/Paste or Edit):</label>
            <textarea id="payloadText" spellcheck="false"></textarea>

            <div class="quick-info">
                <span>• Paper: 80mm (48 chars)</span>
                <span>• Connection: Windows Share / Socket 9100</span>
                <span>• Drawer: Auto Pulse (Yes/No)</span>
            </div>

            <div class="actions">
                <button class="btn" onclick="sendPrint()">🚀 Send Print Request Now</button>
                <button class="btn btn-secondary" onclick="formatJson()">✨ Format JSON</button>
            </div>

            <div id="resultBox" class="result-box"></div>
        </div>
    </div>

    <script>
        const samplePayloads = {
            BILL: {
                "type": "windows",
                "connection_type": "php_print_server",
                "printer_ip_address": "192.168.18.130",
                "printer_port": 9100,
                "path": "LAN 80MM",
                "printer_name": "BILL",
                "store_name": "BizPoz",
                "sale_no_p": "ORD-0118",
                "date": "10/08/2026",
                "time_inv": "10:06 AM",
                "customer_name": "Walk-In",
                "items": "Item                   Rate    Qty     Total\n------------------------------------------------\nBurger Deluxe - برجر ديلوكس\n  [Test1tyoe] [+ b -برجر ديلوكس]\nAED100.00 x1                           AED100.00\n------------------------------------------------\n",
                "totals": "Total Qty:                                     1\nSub Total:                             AED100.00\nTax (0%):                                AED0.00\n",
                "payments": "TOTAL:                                 AED100.00\nPaid:                                    AED0.00\nBalance DUE:                           AED100.00\n",
                "invoice_footer": "Thanks for visiting!",
                "open_cash_drawer_when_printing_invoice": "Yes",
                "print_type": "BILL"
            },
            KOT: {
                "type": "windows",
                "connection_type": "php_print_server",
                "printer_ip_address": "192.168.18.130",
                "printer_port": 9100,
                "path": "LAN 80MM",
                "printer_name": "BILL",
                "store_name": "BizPoz",
                "sale_no_p": "ORD-0118",
                "date": "10/08/2026",
                "time_inv": "10:06 AM",
                "customer_name": "Walk-In",
                "items": "#   Item                                   Qty  \n------------------------------------------------\n1   Burger Deluxe - برجر ديلوكس            1    \n    [Test1tyoe] [+ b -برجر ديلوكس]\n------------------------------------------------\nTotal Items:                               1\n",
                "print_type": "KOT"
            }
        };

        function loadPayload(type) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('payloadText').value = JSON.stringify(samplePayloads[type], null, 4);
        }

        function formatJson() {
            try {
                const text = document.getElementById('payloadText').value;
                const parsed = JSON.parse(text);
                document.getElementById('payloadText').value = JSON.stringify(parsed, null, 4);
            } catch (e) {
                alert("Invalid JSON text: " + e.message);
            }
        }

        async function sendPrint() {
            const resultBox = document.getElementById('resultBox');
            resultBox.style.display = 'block';
            resultBox.className = 'result-box';
            resultBox.innerText = '⏳ Sending print job to printer server...';

            const payloadText = document.getElementById('payloadText').value;
            try {
                JSON.parse(payloadText); // validate
            } catch (e) {
                resultBox.className = 'result-box error';
                resultBox.innerText = '❌ Error: Invalid JSON in textarea. Please check formatting.';
                return;
            }

            const formData = new FormData();
            formData.append('payload', payloadText);

            try {
                const response = await fetch('test.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.text();
                resultBox.style.display = 'block';
                if (data.includes('"success":true')) {
                    resultBox.className = 'result-box success';
                    resultBox.innerText = '✅ PRINT SUCCESSFUL!\nResponse: ' + data;
                } else {
                    resultBox.className = 'result-box error';
                    resultBox.innerText = '⚠️ Server Response: ' + data;
                }
            } catch (err) {
                resultBox.className = 'result-box error';
                resultBox.innerText = '❌ Network Error: ' + err.message;
            }
        }

        // Initialize default BILL payload
        loadPayload('BILL');
    </script>
</body>
</html>
