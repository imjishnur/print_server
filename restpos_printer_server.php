<?php
/**
 * BIZPOZ Print Server - Entry point
 * Receives POST from POS, handles CORS, delegates to printer_load.php
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

if ($contentData === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No content_data received']);
    exit;
}

// Delegate to printer_load.php for actual ESC/POS printing
$_print_data = $contentData;
$_print_type = $postData['print_type'] ?? ($contentData['print_type'] ?? 'KOT');

include __DIR__ . '/include/printer_load.php';