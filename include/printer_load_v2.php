<?php
/**
 * BIZPOZ Print Server - V2 High-Definition Raster Image Driver
 * Converts pre-rendered canvas Base64 images directly into ESC/POS raster commands (GS v 0)
 * Slices vertically in 256-line bands to guarantee zero printer buffer overflows
 */

if (!isset($_image_base64) || empty($_image_base64)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No image_base64 provided for v2 print']);
    exit;
}

$printType      = $_print_type ?? 'BILL';
$data           = $_print_data ?? [];
$printerIp      = $data['printer_ip'] ?? ($data['printer_ip_address'] ?? '');
$printerPort    = (int) ($data['printer_port'] ?? 9100);
$printerPath    = $data['path'] ?? ($data['printer_name'] ?? '');
$openDrawer     = ($data['open_cash_drawer'] ?? '') === 'Yes' || $printType === 'BILL' || $printType === 'INVOICE';

// 1. Decode Base64 PNG image
$cleanBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $_image_base64);
$cleanBase64 = str_replace(' ', '+', $cleanBase64);
$pngBytes    = base64_decode($cleanBase64);

if (!$pngBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to decode base64 image']);
    exit;
}

$im = @imagecreatefromstring($pngBytes);
if (!$im) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid image format - cannot parse PNG']);
    exit;
}

$imgW = imagesx($im);
$imgH = imagesy($im);

// Standard 80mm printer width is 576 dots (72 bytes per row)
$targetW = 576;
if ($imgW !== $targetW) {
    // Resample to 576px if canvas was rendered at different width
    $scaledH = (int) round($imgH * ($targetW / $imgW));
    $resampled = imagecreatetruecolor($targetW, $scaledH);
    imagefill($resampled, 0, 0, imagecolorallocate($resampled, 255, 255, 255));
    imagecopyresampled($resampled, $im, 0, 0, 0, 0, $targetW, $scaledH, $imgW, $imgH);
    imagedestroy($im);
    $im   = $resampled;
    $imgW = $targetW;
    $imgH = $scaledH;
}

$bytesPerRow = (int) ceil($imgW / 8); // 72 bytes

// 2. Build ESC/POS raster commands
// Slicing height: 256 rows per GS v 0 packet prevents buffer overflow on all thermal models
$chunkHeight = 256;
$commands    = "\x1B@";          // Initialize printer
$commands   .= "\x1Ba\x01";      // Center alignment

// Open cash drawer for BILL/Invoice
if ($openDrawer) {
    $commands .= "\x1Bp\x00\x19\xFA"; // Pulse 50ms to pin 2
}

for ($y = 0; $y < $imgH; $y += $chunkHeight) {
    $sliceH = min($chunkHeight, $imgH - $y);
    $rasterData = '';

    for ($row = 0; $row < $sliceH; $row++) {
        $currY = $y + $row;
        for ($byteX = 0; $byteX < $bytesPerRow; $byteX++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $px = $byteX * 8 + $bit;
                if ($px < $imgW) {
                    $rgb  = imagecolorat($im, $px, $currY);
                    $r    = ($rgb >> 16) & 0xFF;
                    $g    = ($rgb >> 8) & 0xFF;
                    $b    = $rgb & 0xFF;
                    $gray = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                    // Pixels darker than 185 turn on thermal dots
                    if ($gray < 185) {
                        $byte |= (1 << (7 - $bit));
                    }
                }
            }
            $rasterData .= chr($byte);
        }
    }

    $xL = $bytesPerRow & 0xFF;
    $xH = ($bytesPerRow >> 8) & 0xFF;
    $yL = $sliceH & 0xFF;
    $yH = ($sliceH >> 8) & 0xFF;

    $commands .= "\x1Dv0\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $rasterData;
}

imagedestroy($im);

// Feed & Paper Cut
$commands .= "\x1Bd\x04";          // Feed 4 lines
$commands .= "\x1DV\x41\x00";      // GS V 65 0: Cut paper

// 3. Deliver commands to printer
header('Content-Type: application/json');

// Attempt 1: Network Printer via Socket
if (!empty($printerIp)) {
    $socket = @fsockopen($printerIp, $printerPort, $errno, $errstr, 2);
    if ($socket) {
        fwrite($socket, $commands);
        fclose($socket);
        echo json_encode([
            'success'     => true,
            'v2'          => true,
            'print_type'  => $printType,
            'printer_ip'  => $printerIp,
            'port'        => $printerPort,
            'method'      => 'socket'
        ]);
        exit;
    }
}

// Attempt 2: Windows Shared Printer
if (!empty($printerPath)) {
    $winPath = '\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath);
    $result  = @file_put_contents($winPath, $commands);
    if ($result !== false) {
        echo json_encode([
            'success'    => true,
            'v2'         => true,
            'print_type' => $printType,
            'path'       => $printerPath,
            'method'     => 'windows_share'
        ]);
        exit;
    }
}

// Attempt 3: Default Windows fallback (POS-80C)
$result = @file_put_contents('\\\\127.0.0.1\\POS-80C', $commands);
if ($result !== false) {
    echo json_encode([
        'success'    => true,
        'v2'         => true,
        'print_type' => $printType,
        'path'       => 'POS-80C',
        'method'     => 'popup_fallback'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'v2'      => true,
    'error'   => 'Could not deliver print job to printer (Socket failed, Windows share inaccessible)'
]);
exit;
