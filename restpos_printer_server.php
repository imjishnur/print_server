<?php
/**
 * BIZPOZ Print Server - Entry point
 * Receives POST from POS, handles CORS, delegates to printer_load.php (v1) or printer_load_v2.php (v2)
 * Also provides printer detection API via action=get_printers
 */

// Error reporting
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CORS & SSL / HTTPS Compatibility Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -------------------------------------------------------------
// Helper Functions for Server & Printer Detection
// -------------------------------------------------------------
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

// -------------------------------------------------------------
// Action API: Detect / Get Installed Printers
// -------------------------------------------------------------
$reqAction = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($reqAction === 'get_printers' || $reqAction === 'detect_printers') {
    header('Content-Type: application/json');
    $detected = getConnectedPrinters();
    echo json_encode([
        'success' => true,
        'server_ip' => getServerIP(),
        'count' => count($detected),
        'printers' => $detected
    ]);
    exit;
}

// Get raw input and POST data
$rawInput = file_get_contents('php://input');
$postData = $_POST;

// Decode content_data - may arrive as string or array
$contentData = null;
if (!empty($postData['content_data'])) {
    $raw = $postData['content_data'];
    if (is_string($raw)) {
        $contentData = json_decode($raw, true);
    } else {
        $contentData = $raw;
    }
} elseif (!empty($rawInput)) {
    $contentData = json_decode($rawInput, true);
}

// Check for V2 Image-Based Payload
$imageBase64 = $postData['image_base64'] ?? ($contentData['image_base64'] ?? null);
if (!empty($imageBase64)) {
    $_image_base64 = $imageBase64;
    $_print_data   = $contentData ?: $postData;
    $_print_type   = $postData['print_type'] ?? ($_print_data['print_type'] ?? 'BILL');
    require_once __DIR__ . '/include/print_logger.php';
    plog_begin('v2', $_print_type, array_merge($postData, (array) $contentData), $_image_base64);
    include __DIR__ . '/include/printer_load_v2.php';
    exit;
}

if ($contentData === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No content_data or image_base64 received']);
    exit;
}

// Delegate to printer_load.php for actual ESC/POS printing (v1)
$_print_data = $contentData;
$_print_type = $postData['print_type'] ?? ($contentData['print_type'] ?? 'KOT');

require_once __DIR__ . '/include/print_logger.php';
plog_begin('v1', $_print_type, $_print_data);
include __DIR__ . '/include/printer_load.php';
