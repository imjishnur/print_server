<?php
/**
 * BIZPOZ Print Server v2 - Dedicated High-Definition Image Entry Point
 * Directly invokes printer_load_v2.php
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
$postData = $_POST;

$contentData = null;
if (!empty($postData['content_data'])) {
    $raw = $postData['content_data'];
    $contentData = is_string($raw) ? json_decode($raw, true) : $raw;
} elseif (!empty($rawInput)) {
    $contentData = json_decode($rawInput, true);
}

$imageBase64 = $postData['image_base64'] ?? ($contentData['image_base64'] ?? null);
if (empty($imageBase64)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No image_base64 received on v2 endpoint']);
    exit;
}

$_image_base64 = $imageBase64;
$_print_data   = $contentData ?: $postData;
$_print_type   = $postData['print_type'] ?? ($_print_data['print_type'] ?? 'BILL');

require_once __DIR__ . '/include/print_logger.php';
plog_begin('v2', $_print_type, array_merge($postData, (array) $contentData), $_image_base64);
include __DIR__ . '/include/printer_load_v2.php';
