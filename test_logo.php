<?php
/**
 * BIZPOZ Print Server - Test Logo Only
 * Sends ONLY the logo image to the thermal printer for testing.
 * 
 * Usage:
 *   POST /print_server/test_logo.php  with JSON or form data containing printer config
 *   GET  /print_server/test_logo.php  shows a simple test page
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// GET  → Show test page UI
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Logo Print</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; font-family: 'Segoe UI', sans-serif; color: #e0e0e0; padding: 20px; min-height: 100vh; }
        .container { max-width: 500px; margin: 0 auto; }
        h2 { color: #00d4ff; text-align: center; margin-bottom: 8px; font-size: 22px; }
        .subtitle { color: #666; text-align: center; font-size: 13px; margin-bottom: 24px; }
        .card { background: #1a1a2e; border: 1px solid #2a2a4a; border-radius: 12px; padding: 24px; }
        .logo-preview { text-align: center; margin-bottom: 20px; }
        .logo-preview img { max-width: 200px; border-radius: 8px; background: #fff; padding: 8px; }
        .logo-preview p { color: #888; font-size: 12px; margin-top: 6px; }
        label { display: block; color: #888; font-size: 12px; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select { width: 100%; padding: 12px; background: #0f0f1a; border: 1px solid #2a2a4a; border-radius: 8px; color: #fff; font-size: 14px; margin-bottom: 16px; outline: none; transition: border-color 0.2s; }
        input:focus, select:focus { border-color: #00d4ff; }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .btn { width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: linear-gradient(135deg, #00d4ff, #0099cc); color: #000; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(0,212,255,0.3); }
        .btn-primary:active { transform: translateY(0); }
        .output { margin-top: 16px; padding: 14px; border-radius: 8px; font-size: 13px; font-family: monospace; white-space: pre-wrap; word-break: break-all; display: none; }
        .output.success { background: #0a2e1a; border: 1px solid #28a745; color: #4ade80; }
        .output.error { background: #2e0a0a; border: 1px solid #dc3545; color: #f87171; }
        .output.loading { background: #1a1a2e; border: 1px solid #00d4ff; color: #00d4ff; }
        .info { color: #555; font-size: 11px; text-align: center; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🖨️ Test Logo Print</h2>
        <p class="subtitle">Send only the logo image to your thermal printer</p>

        <div class="card">
            <div class="logo-preview">
                <img src="logo/logo.png" alt="Current Logo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%2260%22><text x=%2210%22 y=%2240%22 fill=%22%23999%22>No Logo</text></svg>'">
                <p>Current logo: logo/logo.png</p>
            </div>

            <label>Connection Type</label>
            <select id="connType" onchange="toggleFields()">
                <option value="php_print_server">Network (IP Socket)</option>
                <option value="windows">Windows Share</option>
            </select>

            <div id="networkFields">
                <div class="row">
                    <div>
                        <label>Printer IP</label>
                        <input type="text" id="printerIp" placeholder="192.168.18.130" value="192.168.18.130">
                    </div>
                    <div>
                        <label>Port</label>
                        <input type="number" id="printerPort" placeholder="9100" value="9100">
                    </div>
                </div>
            </div>

            <div id="windowsFields" style="display:none;">
                <label>Printer Share Path</label>
                <input type="text" id="printerPath" placeholder="LAN 80MM">
            </div>

            <label>Logo Path (optional, default: logo/logo.png)</label>
            <input type="text" id="logoPath" placeholder="logo/logo.png" value="">

            <button class="btn btn-primary" onclick="testLogo()">🖼️ Print Logo Only</button>

            <div class="output" id="output"></div>
        </div>
        <p class="info">This sends only the logo image to the printer — no text, no receipt.</p>
    </div>

    <script>
        function toggleFields() {
            const type = document.getElementById('connType').value;
            document.getElementById('networkFields').style.display = type === 'php_print_server' ? 'block' : 'none';
            document.getElementById('windowsFields').style.display = type === 'windows' ? 'block' : 'none';
        }

        async function testLogo() {
            const out = document.getElementById('output');
            out.style.display = 'block';
            out.className = 'output loading';
            out.textContent = '⏳ Sending logo to printer...';

            const connType = document.getElementById('connType').value;
            const payload = {
                connection_type: connType,
                type: connType === 'windows' ? 'windows' : 'network',
                printer_ip_address: document.getElementById('printerIp').value.trim(),
                printer_port: parseInt(document.getElementById('printerPort').value) || 9100,
                path: document.getElementById('printerPath').value.trim(),
                logo_path: document.getElementById('logoPath').value.trim()
            };

            try {
                const res = await fetch('test_logo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await res.json();

                if (result.success) {
                    out.className = 'output success';
                    out.textContent = '✅ Logo sent successfully!\n\n' + JSON.stringify(result, null, 2);
                } else {
                    out.className = 'output error';
                    out.textContent = '❌ Failed: ' + (result.error || 'Unknown error') + '\n\n' + JSON.stringify(result, null, 2);
                }
            } catch (e) {
                out.className = 'output error';
                out.textContent = '❌ Error: ' + e.message;
            }
        }
    </script>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// POST → Print the logo to the thermal printer
// ============================================================
header('Content-Type: application/json');

// Parse input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    // Try form data
    $data = $_POST;
    if (!empty($data['content_data'])) {
        $data = is_string($data['content_data']) ? json_decode($data['content_data'], true) : $data['content_data'];
    }
}

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit;
}

// Printer config
$connectionType = $data['connection_type'] ?? 'php_print_server';
$printerIp      = $data['printer_ip_address'] ?? '';
$printerPort    = !empty($data['printer_port']) ? (int) $data['printer_port'] : 9100;
$printerPath    = $data['path'] ?? '';
$type           = $data['type'] ?? 'windows';
$logoPathInput  = $data['logo_path'] ?? '';

// ── Resolve logo file path ──────────────────────────────────
$logoPath = '';

// Check user-specified path first
if (!empty($logoPathInput)) {
    // Absolute path
    if (file_exists($logoPathInput)) {
        $logoPath = $logoPathInput;
    }
    // Relative to print_server root
    elseif (file_exists(__DIR__ . '/' . $logoPathInput)) {
        $logoPath = __DIR__ . '/' . $logoPathInput;
    }
}

// Fallback defaults
if (empty($logoPath)) {
    $fallbacks = [
        __DIR__ . '/logo/logo.png',
        __DIR__ . '/logo/logo.jpg',
    ];
    foreach ($fallbacks as $fb) {
        if (file_exists($fb)) {
            $logoPath = $fb;
            break;
        }
    }
}

if (empty($logoPath) || !file_exists($logoPath)) {
    echo json_encode(['success' => false, 'error' => 'Logo file not found. Tried: ' . ($logoPathInput ?: 'logo/logo.png')]);
    exit;
}

// ── Load & prepare logo image via GD ────────────────────────
if (!extension_loaded('gd')) {
    echo json_encode(['success' => false, 'error' => 'PHP GD extension not loaded']);
    exit;
}

$info = @getimagesize($logoPath);
if (!$info) {
    echo json_encode(['success' => false, 'error' => 'Cannot read image: ' . basename($logoPath)]);
    exit;
}

$origW = $info[0];
$origH = $info[1];
$mime  = $info['mime'];

$srcIm = null;
if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
    $srcIm = @imagecreatefromjpeg($logoPath);
} elseif ($mime === 'image/png') {
    $srcIm = @imagecreatefrompng($logoPath);
} elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
    $srcIm = @imagecreatefromwebp($logoPath);
}

if (!$srcIm) {
    echo json_encode(['success' => false, 'error' => 'Failed to load image with GD']);
    exit;
}

// Resize to fit 80mm printer (~384 dots at 203dpi)
$maxWidth = 384;
if ($origW > $maxWidth) {
    $newW = $maxWidth;
    $newH = (int) round(($origH / $origW) * $maxWidth);
} else {
    $newW = $origW;
    $newH = $origH;
}

// Create resized image with white background
$resized = imagecreatetruecolor($newW, $newH);
$white   = imagecolorallocate($resized, 255, 255, 255);
imagefill($resized, 0, 0, $white);
imagecopyresampled($resized, $srcIm, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
imagedestroy($srcIm);

// ── Convert to ESC/POS raster bitmap ────────────────────────
// Ensure width is multiple of 8 for bitmap byte alignment
$bmpW = (int)(ceil($newW / 8) * 8);
$bmpH = $newH;
$bytesPerRow = $bmpW / 8;

// Build raster data: 1 = black dot, 0 = white dot (monochrome threshold)
$rasterData = '';
for ($y = 0; $y < $bmpH; $y++) {
    for ($byteX = 0; $byteX < $bytesPerRow; $byteX++) {
        $byte = 0;
        for ($bit = 0; $bit < 8; $bit++) {
            $px = $byteX * 8 + $bit;
            if ($px < $newW) {
                $rgb = imagecolorat($resized, $px, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                if ($gray < 128) {
                    $byte |= (1 << (7 - $bit)); // black dot
                }
            }
        }
        $rasterData .= chr($byte);
    }
}
imagedestroy($resized);

// ── Build ESC/POS commands ──────────────────────────────────
$esc = "\x1B";
$gs  = "\x1D";

$commands = '';

// Initialize printer
$commands .= $esc . "@";

// Center alignment
$commands .= $esc . "a\x01";

// GS v 0 — raster bit image command
// Format: GS v 0 m xL xH yL yH [data]
// m = 0 (normal), xL xH = bytes per row, yL yH = rows
$xL = $bytesPerRow & 0xFF;
$xH = ($bytesPerRow >> 8) & 0xFF;
$yL = $bmpH & 0xFF;
$yH = ($bmpH >> 8) & 0xFF;

$commands .= $gs . "v0" . chr(0) . chr($xL) . chr($xH) . chr($yL) . chr($yH);
$commands .= $rasterData;

// Feed some lines after logo
$commands .= "\n\n\n";

// Cut paper
$commands .= $gs . "V" . chr(0);

// ── Send to printer ─────────────────────────────────────────
$printed  = false;
$errorMsg = '';
$method   = '';

// Network printer via IP socket
if (!empty($printerIp) && $connectionType === 'php_print_server') {
    $socket = @fsockopen($printerIp, $printerPort, $errno, $errstr, 5);
    if ($socket) {
        fwrite($socket, $commands);
        fclose($socket);
        echo json_encode([
            'success'    => true,
            'message'    => 'Logo printed successfully',
            'method'     => 'socket',
            'printer_ip' => $printerIp,
            'port'       => $printerPort,
            'logo'       => basename($logoPath),
            'image_size' => "{$newW}x{$bmpH}px",
            'data_bytes' => strlen($commands)
        ]);
        exit;
    } else {
        $errorMsg = "Socket connection failed: $errstr (errno: $errno)";
    }
}

// Windows share
if (!empty($printerPath) && ($type === 'windows' || $connectionType === 'windows')) {
    $sharePath = '\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath);
    $result = @file_put_contents($sharePath, $commands);
    if ($result !== false) {
        echo json_encode([
            'success'    => true,
            'message'    => 'Logo printed successfully',
            'method'     => 'windows_share',
            'path'       => $printerPath,
            'logo'       => basename($logoPath),
            'image_size' => "{$newW}x{$bmpH}px",
            'data_bytes' => strlen($commands)
        ]);
        exit;
    } else {
        $errorMsg = "Windows share write failed to: $sharePath";
    }
}

echo json_encode([
    'success' => false,
    'error'   => $errorMsg ?: 'No valid printer connection configured',
    'config'  => [
        'connection_type' => $connectionType,
        'printer_ip'      => $printerIp,
        'port'            => $printerPort,
        'path'            => $printerPath,
        'type'            => $type
    ]
]);
