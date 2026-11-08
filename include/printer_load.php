<?php
/**
 * BIZPOZ Print Server - Printer Driver
 * Handles KOT and BILL printing via raw ESC/POS commands
 * Supports Arabic text by rendering as raster images
 * Expects $_print_data and $_print_type
 */

if (!isset($_print_data)) {
    echo json_encode(['success' => false, 'error' => 'No print data provided']);
    exit;
}

// Include helper functions (Arabic shaping, text utilities)
require_once __DIR__ . '/printer_helper.php';

$data = $_print_data;
$printType = $_print_type ?? 'KOT';

// ============================================================
// PUBLIC GLOBAL CONFIGURATION SETTINGS
// Change these top-level variables directly to control default behavior
// ============================================================
$ENABLE_KOT_LOGO   = false;        // Set to true to print logo on KOT, false to hide logo on KOT
$ENABLE_BILL_LOGO  = true;         // Set to true to print logo on BILL/Invoice, false to hide
$BIG_STORE_NAME    = true;         // Set to true for Large Bold Store Name, false for Normal text
$DEFAULT_LOGO_MODE = 'esc_star';   // 'esc_star' (Universal Bit-Image) or 'gs_v0' (Raster)
// ============================================================

// ============================================================
// ARABIC TEXT HELPER — detect Arabic and render as raster image
// ============================================================

/**
 * Check if a string contains Arabic characters
 */
function hasArabic($text)
{
    return !empty($text) && preg_match('/\p{Arabic}/u', $text);
}

/**
 * Convert a GD image resource to ESC/POS raster bitmap commands (GS v 0)
 */
function imageToRasterCommands($im, $width = null)
{
    if (!$im)
        return '';
    $imgW = imagesx($im);
    $imgH = imagesy($im);

    $bmpW = (int) (ceil($imgW / 8) * 8);
    $bytesPerRow = $bmpW / 8;

    $rasterData = '';
    for ($y = 0; $y < $imgH; $y++) {
        for ($byteX = 0; $byteX < $bytesPerRow; $byteX++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $px = $byteX * 8 + $bit;
                if ($px < $imgW) {
                    $rgb = imagecolorat($im, $px, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $gray = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                    // Threshold at 160 for dark, sharp thermal print characters
                    if ($gray < 160) {
                        $byte |= (1 << (7 - $bit));
                    }
                }
            }
            $rasterData .= chr($byte);
        }
    }

    $gs = "\x1D";
    $xL = $bytesPerRow & 0xFF;
    $xH = ($bytesPerRow >> 8) & 0xFF;
    $yL = $imgH & 0xFF;
    $yH = ($imgH >> 8) & 0xFF;
    return $gs . "v0" . chr(0) . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $rasterData;
}

/**
 * Render a single text line as a raster image for 80mm thermal printer (576px).
 *
 * @param string $text     The text to render
 * @param int    $fontSize Font size in points (default 20pt for 80mm)
 * @param string $align    'left', 'center', or 'right'
 * @param int    $width    Image width in pixels (576px for 80mm printer)
 * @return string ESC/POS raster commands
 */
function renderTextAsImage($text, $fontSize = 20, $align = 'left', $width = 576, $bold = false)
{
    if (!extension_loaded('gd'))
        return '';

    // Try Arabic TTF font first, then fallback
    $fontFile = dirname(__FILE__) . '/../I18N/Arabic/Examples/GD/ae_AlHor.ttf';
    if (!file_exists($fontFile)) {
        // Try system fonts
        $fontFile = 'C:/Windows/Fonts/arial.ttf';
        if (!file_exists($fontFile))
            return '';
    }

    // Shape Arabic text using I18N library
    $shaped = $text;
    if (hasArabic($text)) {
        $shaped = shape_arabic_text($text, 45);
    }

    // Measure text
    $bbox = @imagettfbbox($fontSize, 0, $fontFile, $shaped);
    if (!$bbox)
        return '';
    $textWidth = abs($bbox[2] - $bbox[0]);
    $textHeight = abs($bbox[7] - $bbox[1]);

    $lineHeight = (int) ($fontSize * 1.8);
    $im = imagecreatetruecolor($width, $lineHeight);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);

    // Position text based on alignment
    if ($align === 'center') {
        $x = max(0, (int) (($width - $textWidth) / 2));
    } elseif ($align === 'right') {
        $x = max(0, $width - $textWidth - 10);
    } else {
        $x = 5;
    }
    $y = (int) ($lineHeight * 0.7);

    imagettftext($im, $fontSize, 0, $x, $y, $black, $fontFile, $shaped);

    $cmds = imageToRasterCommands($im);
    imagedestroy($im);
    return $cmds;
}

/**
 * Render multi-line text block as a single raster image for 80mm thermal printer (576px).
 *
 * @param string $text     Multi-line text
 * @param int    $fontSize Font size in points (default 18pt for 80mm)
 * @param string $align    'left', 'center', or 'right'
 * @param int    $width    Width in pixels (576px for 80mm paper)
 * @return string ESC/POS raster commands
 */
function renderMultiLineAsImage($text, $fontSize = 17, $align = 'left', $width = 576)
{
    if (!extension_loaded('gd') || empty($text))
        return '';

    $fontFile = dirname(__FILE__) . '/../I18N/Arabic/Examples/GD/ae_AlHor.ttf';
    if (!file_exists($fontFile)) {
        $fontFile = 'C:/Windows/Fonts/arial.ttf';
        if (!file_exists($fontFile))
            return '';
    }

    $rawLines = explode("\n", str_replace("\r", "", $text));
    $lineHeight = (int) ($fontSize * 1.8);
    $padding = 8;
    $totalHeight = count($rawLines) * $lineHeight + ($padding * 2);

    $im = imagecreatetruecolor($width, $totalHeight);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);

    $y = $padding + $fontSize;
    $rightMargin = $width - 12; // 564px flush right

    foreach ($rawLines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $y += $lineHeight;
            continue;
        }

        // Draw horizontal dash line as clean continuous vector line
        if (preg_match('/^-{3,}$/', $trimmed) || preg_match('/^={3,}$/', $trimmed)) {
            imageline($im, 10, $y - (int) ($fontSize * 0.4), $rightMargin, $y - (int) ($fontSize * 0.4), $black);
            $y += $lineHeight;
            continue;
        }

        // Order Type Header Line: render in bold
        if (preg_match('/^Order Type/i', $trimmed)) {
            $shaped = shape_arabic_text($line, 45);
            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, $shaped);
            imagettftext($im, $fontSize, 0, 11, $y, $black, $fontFile, $shaped);
            imagettftext($im, $fontSize, 0, 10, $y + 1, $black, $fontFile, $shaped);
            $y += $lineHeight;
            continue;
        }

        // Table Header Line: "Item ... Rate ... Qty ... Total"
        if (preg_match('/^Item\s+Rate\s+Qty\s+Total/i', $trimmed)) {
            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, 'Item');

            $bboxRate = @imagettfbbox($fontSize, 0, $fontFile, 'Rate');
            $rateW = $bboxRate ? abs($bboxRate[2] - $bboxRate[0]) : 0;
            imagettftext($im, $fontSize, 0, 310 - (int) ($rateW / 2), $y, $black, $fontFile, 'Rate');

            $bboxQty = @imagettfbbox($fontSize, 0, $fontFile, 'Qty');
            $qtyW = $bboxQty ? abs($bboxQty[2] - $bboxQty[0]) : 0;
            imagettftext($im, $fontSize, 0, 410 - (int) ($qtyW / 2), $y, $black, $fontFile, 'Qty');

            $bboxTotal = @imagettfbbox($fontSize, 0, $fontFile, 'Total');
            $totalW = $bboxTotal ? abs($bboxTotal[2] - $bboxTotal[0]) : 0;
            imagettftext($im, $fontSize, 0, $rightMargin - $totalW, $y, $black, $fontFile, 'Total');

            $y += $lineHeight;
            continue;
        }

        // KOT Table Header Line: "Qty ... Item" (no serial number)
        if (preg_match('/^(#\s+)?(Qty\s+Item|Item\s+Qty)/i', $trimmed) || preg_match('/^Qty\s+Item/i', $trimmed)) {
            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, 'Qty');
            imagettftext($im, $fontSize, 0, 100, $y, $black, $fontFile, 'Item');

            $y += $lineHeight;
            continue;
        }

        // KOT Item Line without Serial No: "Qty   Item Name"
        if (preg_match('/^\s*(\d+)\s{2,}(.+)$/u', $line, $m) && !preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/u', $line)) {
            $qtyStr = trim($m[1]);
            $itemStr = shape_arabic_text(trim($m[2]), 45);

            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, $qtyStr);
            imagettftext($im, $fontSize, 0, 100, $y, $black, $fontFile, $itemStr);

            $y += $lineHeight;
            continue;
        }

        // KOT Item Line with legacy Serial No: "#   Qty   Item Name" (fallback)
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/u', $line, $m)) {
            $qtyStr = trim($m[2]);
            $itemStr = shape_arabic_text(trim($m[3]), 45);

            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, $qtyStr);
            imagettftext($im, $fontSize, 0, 100, $y, $black, $fontFile, $itemStr);

            $y += $lineHeight;
            continue;
        }

        // KOT Indented Modifiers or Notes
        if (preg_match('/^\s{4,}(.+)$/u', $line, $m)) {
            $detailStr = shape_arabic_text(trim($m[1]), 45);
            imagettftext($im, $fontSize, 0, 100, $y, $black, $fontFile, $detailStr);
            $y += $lineHeight;
            continue;
        }

        // Line with Left and Right columns separated by 2+ spaces
        if (preg_match('/^(.+?)\s{2,}(.+?)$/u', $line, $m)) {
            $leftStr = shape_arabic_text(trim($m[1]), 45);
            $rightStr = shape_arabic_text(trim($m[2]), 45);

            // Draw left column
            imagettftext($im, $fontSize, 0, 10, $y, $black, $fontFile, $leftStr);

            // Draw right column flush right against the right paper margin
            $bboxR = @imagettfbbox($fontSize, 0, $fontFile, $rightStr);
            $rightW = $bboxR ? abs($bboxR[2] - $bboxR[0]) : 0;
            imagettftext($im, $fontSize, 0, $rightMargin - $rightW, $y, $black, $fontFile, $rightStr);

            $y += $lineHeight;
            continue;
        }

        // Single column line
        $shaped = shape_arabic_text($line, 45);
        $bbox = @imagettfbbox($fontSize, 0, $fontFile, $shaped);
        $textWidth = $bbox ? abs($bbox[2] - $bbox[0]) : 0;

        if ($align === 'center') {
            $x = max(10, (int) (($width - $textWidth) / 2));
        } elseif ($align === 'right') {
            $x = max(10, $rightMargin - $textWidth);
        } else {
            $x = 10;
        }
        imagettftext($im, $fontSize, 0, $x, $y, $black, $fontFile, $shaped);
        $y += $lineHeight;
    }

    $cmds = imageToRasterCommands($im);
    imagedestroy($im);
    return $cmds;
}

/**
 * Smart text output for ESC/POS:
 * - If text has Arabic → render as raster image
 * - If text is plain → return raw text with line feed
 */
function escText($text, $align = 'left', $fontSize = 18, $isMultiLine = false)
{
    if (empty($text))
        return '';

    // No Arabic → return raw text
    if (!hasArabic($text)) {
        return $text . "\x0A";
    }

    // Has Arabic → render as image
    if ($isMultiLine) {
        return renderMultiLineAsImage($text, $fontSize, $align, 576);
    }
    return renderTextAsImage($text, $fontSize, $align, 576);
}

/**
 * Generate ESC/POS Cash Drawer pulse commands (ESC p m t1 t2)
 *
 * @param int $pin 2 for Pin 2, 5 for Pin 5, 0 for Both (default)
 * @return string ESC/POS binary pulse commands
 */
function openCashDrawer($pin = 0)
{
    $esc = "\x1B";
    if ($pin === 2) {
        return $esc . "p\x00\x19\xFA";
    }
    if ($pin === 5) {
        return $esc . "p\x01\x19\xFA";
    }
    // Return both Pin 2 and Pin 5 for universal printer & drawer compatibility
    return $esc . "p\x00\x19\xFA" . $esc . "p\x01\x19\xFA";
}

// ============================================================
// LOGO HELPER — converts image to ESC/POS raster bitmap commands
// ============================================================
function buildLogoCommands($logoFile = null, $maxWidth = 320, $logoMode = 'esc_star')
{
    // Resolve logo path
    $logoPath = '';
    if (!empty($logoFile) && file_exists($logoFile)) {
        $logoPath = $logoFile;
    } elseif (!empty($logoFile) && file_exists(dirname(__DIR__) . '/' . $logoFile)) {
        $logoPath = dirname(__DIR__) . '/' . $logoFile;
    }
    if (empty($logoPath)) {
        $fallbacks = [
            dirname(__DIR__) . '/logo/logo.png',
            dirname(__DIR__) . '/logo/logo.jpg',
        ];
        foreach ($fallbacks as $fb) {
            if (file_exists($fb)) {
                $logoPath = $fb;
                break;
            }
        }
    }
    if (empty($logoPath) || !file_exists($logoPath) || !extension_loaded('gd')) {
        return '';
    }

    $info = @getimagesize($logoPath);
    if (!$info)
        return '';

    $origW = $info[0];
    $origH = $info[1];
    $mime = $info['mime'];

    $srcIm = null;
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $srcIm = @imagecreatefromjpeg($logoPath);
    } elseif ($mime === 'image/png') {
        $srcIm = @imagecreatefrompng($logoPath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $srcIm = @imagecreatefromwebp($logoPath);
    }
    if (!$srcIm)
        return '';

    // Trim outer black border lines if present on logo edges
    $srcW = imagesx($srcIm);
    $srcH = imagesy($srcIm);
    $minX = 0;
    $minY = 0;
    $maxX = $srcW - 1;
    $maxY = $srcH - 1;

    for ($x = 0; $x < min(10, $srcW); $x++) {
        $blackCount = 0;
        for ($y = 0; $y < $srcH; $y++) {
            $rgb = imagecolorat($srcIm, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r < 100 && $g < 100 && $b < 100)
                $blackCount++;
        }
        if ($blackCount > $srcH * 0.7) {
            $minX = $x + 1;
        } else {
            break;
        }
    }
    for ($y = $srcH - 1; $y > max(0, $srcH - 10); $y--) {
        $blackCount = 0;
        for ($x = 0; $x < $srcW; $x++) {
            $rgb = imagecolorat($srcIm, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r < 100 && $g < 100 && $b < 100)
                $blackCount++;
        }
        if ($blackCount > $srcW * 0.7) {
            $maxY = $y - 1;
        } else {
            break;
        }
    }

    $cropW = max(1, $maxX - $minX + 1);
    $cropH = max(1, $maxY - $minY + 1);

    // Resize to fit printer width
    if ($cropW > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int) round(($cropH / $cropW) * $maxWidth);
    } else {
        $newW = $cropW;
        $newH = $cropH;
    }

    $resized = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
    imagecopyresampled($resized, $srcIm, 0, 0, $minX, $minY, $newW, $newH, $cropW, $cropH);
    imagedestroy($srcIm);

    $esc = "\x1B";
    $gs = "\x1D";
    $cmds = '';
    $cmds .= $esc . "a\x01"; // Center align

    if ($logoMode === 'gs_v0') {
        // Mode 1: GS v 0 (Raster bitmap mode)
        $bmpW = (int) (ceil($newW / 8) * 8);
        $bmpH = $newH;
        $bytesPerRow = $bmpW / 8;

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
                        $gray = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                        if ($gray < 128) {
                            $byte |= (1 << (7 - $bit));
                        }
                    }
                }
                $rasterData .= chr($byte);
            }
        }
        imagedestroy($resized);

        $xL = $bytesPerRow & 0xFF;
        $xH = ($bytesPerRow >> 8) & 0xFF;
        $yL = $bmpH & 0xFF;
        $yH = ($bmpH >> 8) & 0xFF;
        $cmds .= $gs . "v0" . chr(0) . chr($xL) . chr($xH) . chr($yL) . chr($yH);
        $cmds .= $rasterData;
        return $cmds;
    } else {
        // Mode 2: ESC * (Legacy 24-dot Column Bit-Image mode - Universal compatibility)
        $cmds .= $esc . "3\x18"; // Set line spacing to 24 dots (no gap between 24-dot slices)

        for ($y = 0; $y < $newH; $y += 24) {
            $cmds .= $esc . "*\x21"; // ESC * 33 (24-dot double density)
            $nL = $newW & 0xFF;
            $nH = ($newW >> 8) & 0xFF;
            $cmds .= chr($nL) . chr($nH);

            for ($x = 0; $x < $newW; $x++) {
                for ($k = 0; $k < 3; $k++) {
                    $byte = 0;
                    for ($bit = 0; $bit < 8; $bit++) {
                        $pixelY = $y + ($k * 8) + $bit;
                        if ($pixelY < $newH) {
                            $rgb = imagecolorat($resized, $x, $pixelY);
                            $r = ($rgb >> 16) & 0xFF;
                            $g = ($rgb >> 8) & 0xFF;
                            $b = $rgb & 0xFF;
                            $gray = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
                            if ($gray < 128) {
                                $byte |= (1 << (7 - $bit));
                            }
                        }
                    }
                    $cmds .= chr($byte);
                }
            }
            $cmds .= "\x0A"; // Line feed
        }
        imagedestroy($resized);

        $cmds .= $esc . "2"; // Reset line spacing to default
        return $cmds;
    }
}

// ============================================================
// KOT PRINT
// ============================================================
if ($printType === 'KOT') {
    $printerIp = $data['printer_ip_address'] ?? '';
    $printerPath = $data['path'] ?? '';
    $printerName = $data['printer_name'] ?? '';
    $type = $data['type'] ?? 'windows';
    $connectionType = $data['connection_type'] ?? 'popup';
    $items = $data['items'] ?? '';
    $orderNo = $data['sale_no_p'] ?? '';
    $date = $data['date'] ?? '';
    $time = $data['time_inv'] ?? '';
    $customerName = $data['customer_name'] ?? '';
    $tableName = $data['customer_table'] ?? '';
    $storeName = $data['store_name'] ?? 'My Shop';

    // Build ESC/POS commands
    $esc = "\x1B";
    $gs = "\x1D";
    $commands = '';

    // Initialize printer
    $commands .= $esc . "@";

    // Cash drawer open check ('open_cash_drawer_when_printing_invoice' => 'Yes' / 'No')
    $openDrawer = $data['open_cash_drawer_when_printing_invoice'] ?? ($data['open_cash_drawer'] ?? ($data['open_drawer'] ?? 'No'));
    if (strcasecmp((string) $openDrawer, 'Yes') === 0 || $openDrawer === true || $openDrawer === 1 || $openDrawer === '1') {
        $commands .= openCashDrawer(); // pulse cash drawer pin 2 & 5
    }

    // Print logo at top (resolved from public $ENABLE_KOT_LOGO or payload $data)
    $printKotLogo = $data['print_kot_logo'] ?? ($data['kot_logo'] ?? ($data['print_logo'] ?? ($ENABLE_KOT_LOGO ? 'Yes' : 'No')));
    if ($printKotLogo === 'Yes' || $printKotLogo === true || $printKotLogo === 1 || $printKotLogo === '1') {
        $logoMode = $data['logo_mode'] ?? ($DEFAULT_LOGO_MODE ?? 'esc_star');
        $commands .= buildLogoCommands($data['logo_path'] ?? 'logo/logo.png', 320, $logoMode);
    }

    // Store name - center (resolved from public $BIG_STORE_NAME or payload $data)
    $bigName = $data['big_name'] ?? ($data['store_name_big'] ?? ($BIG_STORE_NAME ? 'Yes' : 'No'));
    $commands .= $esc . "a\x01"; // Center align
    if ($bigName === 'Yes' || $bigName === true || $bigName === 1 || $bigName === '1') {
        $commands .= $esc . "!" . "\x38"; // Double height + double width + bold
        $commands .= escText($storeName, 'center', 26);
        $commands .= $esc . "!" . "\x00"; // Reset font size
    } else {
        $commands .= escText($storeName, 'center', 20);
    }

    // Separator line
    $commands .= $esc . "a\x01";
    $commands .= "================================================" . "\x0A";

    // KOT header
    $commands .= $esc . "!" . "\x30"; // double height
    $commands .= "K O T" . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Separator line
    $commands .= "================================================" . "\x0A";

    // Order info - left align
    $commands .= $esc . "a\x00";
    $commands .= escText("Order #  : " . $orderNo, 'left', 14);
    $commands .= escText("Date     : " . $date . " " . $time, 'left', 14);
    if ($customerName)
        $commands .= escText("Customer : " . $customerName, 'left', 14);
    if ($tableName)
        $commands .= escText("Table    : " . $tableName, 'left', 14);

    // Separator
    $commands .= "------------------------------------------------" . "\x0A";

    // Items header
    $commands .= str_pad("Qty", 8) . str_pad("Item", 32) . "\x0A";
    $commands .= "------------------------------------------------" . "\x0A";

    // Parse and print items
    $totalItemsCount = 0;
    if (is_string($items)) {
        $lines = explode("\n", str_replace("\r", "", $items));
        $filteredLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '')
                continue;
            if (preg_match('/^-+$/', $trimmed))
                continue;
            if (preg_match('/^(#|Qty)\s+(Item|Qty)/i', $trimmed))
                continue;
            if (stripos($trimmed, 'total items:') !== false) {
                if (preg_match('/(\d+)\s*$/', $trimmed, $m)) {
                    $totalItemsCount = (int) $m[1];
                }
                continue;
            }
            $filteredLines[] = $line;
        }

        $cleanItemsText = implode("\n", $filteredLines);

        if (hasArabic($cleanItemsText)) {
            // Render clean item lines as 18pt raster image for 80mm printer
            $commands .= renderMultiLineAsImage($cleanItemsText, 18, 'left', 576);
        } else {
            foreach ($filteredLines as $line) {
                $commands .= rtrim($line) . "\x0A";
            }
        }
    }

    // Separator
    $commands .= "------------------------------------------------" . "\x0A";

    // Total - bold, double size
    $displayTotal = $totalItemsCount > 0 ? (string) $totalItemsCount : "1";
    $commands .= $esc . "!" . "\x08"; // bold
    $commands .= str_pad("Total Items:", 22) . str_pad($displayTotal, 5) . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Big order number - center, large
    $commands .= $esc . "a\x01";
    $commands .= $esc . "!" . "\x38"; // double height
    $commands .= "#" . $orderNo . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Separator
    $commands .= "================================================" . "\x0A";

    // Footer
    $commands .= $esc . "a\x01";
    $commands .= "Powered by BIZPOZ" . "\x0A";

    // Feed and cut
    $commands .= $esc . "d\x05";
    $commands .= $gs . "V\x00"; // cut

    // Send to printer
    $printed = false;
    $errorMsg = '';

    // Fast Windows Share printing first for Windows type
    if (!empty($printerPath) && $type === 'windows') {
        $winPath = '\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath);
        $result = @file_put_contents($winPath, $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'method' => 'windows_share', 'path' => $printerPath]);
            exit;
        }
    }

    // Network printer via IP (fast 1-second timeout)
    if (!empty($printerIp)) {
        $port = !empty($data['printer_port']) ? (int) $data['printer_port'] : 9100;
        $socket = @fsockopen($printerIp, $port, $errno, $errstr, 1);
        if ($socket) {
            fwrite($socket, $commands);
            fclose($socket);
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'printer_ip' => $printerIp, 'port' => $port, 'method' => 'socket']);
            exit;
        } else {
            $errorMsg = "Socket failed: $errstr ($errno)";
        }
    }

    // Popup mode fallback
    if ($connectionType === 'popup' || empty($connectionType)) {
        $result = @file_put_contents('\\\\127.0.0.1\\POS-80C', $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'method' => 'popup', 'printer' => 'POS-80C']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => $errorMsg ?: 'Could not connect to printer']);
    exit;
}

// ====================================================================
// BILL / INVOICE PRINT
// ====================================================================
if ($printType === 'BILL' || $printType === 'INVOICE') {
    $printerIp = $data['printer_ip_address'] ?? '';
    $printerPath = $data['path'] ?? '';
    $printerName = $data['printer_name'] ?? '';
    $type = $data['type'] ?? 'windows';
    $connectionType = $data['connection_type'] ?? 'popup';
    $storeName = $data['store_name'] ?? 'My Shop';
    $orderNo = $data['sale_no_p'] ?? '';
    $date = $data['date'] ?? '';
    $time = $data['time_inv'] ?? '';
    $customerName = $data['customer_name'] ?? '';
    $items = $data['items'] ?? '';
    $totals = $data['totals'] ?? '';
    $payments = $data['payments'] ?? '';
    $invoiceFooter = $data['invoice_footer'] ?? '';

    $nl = "\r\n";
    $esc = "\x1B";
    $gs = "\x1D";
    $commands = '';

    // Initialize printer
    $commands .= $esc . "@";

    // Cash drawer open check: default to 'Yes' unless explicitly set to 'No'
    $openDrawer = $data['open_cash_drawer_when_printing_invoice'] ?? ($data['open_cash_drawer'] ?? ($data['open_drawer'] ?? 'Yes'));
    if ($openDrawer !== false && $openDrawer !== '0' && strcasecmp((string) $openDrawer, 'No') !== 0) {
        $commands .= openCashDrawer(); // pulse cash drawer pin 2 & 5
    }

    // Print logo at top (resolved from public $ENABLE_BILL_LOGO or payload $data)
    $printBillLogo = $data['print_bill_logo'] ?? ($data['bill_logo'] ?? ($data['print_logo'] ?? ($ENABLE_BILL_LOGO ? 'Yes' : 'No')));
    if ($printBillLogo !== 'No' && $printBillLogo !== '0' && $printBillLogo !== false) {
        $logoMode = $data['logo_mode'] ?? ($DEFAULT_LOGO_MODE ?? 'esc_star');
        $commands .= buildLogoCommands($data['logo_path'] ?? 'logo/logo.png', 320, $logoMode);
    }

    // Store name - center (resolved from public $BIG_STORE_NAME or payload $data)
    $bigName = $data['big_name'] ?? ($data['store_name_big'] ?? ($BIG_STORE_NAME ? 'Yes' : 'No'));
    $commands .= $esc . "a\x01"; // Center align
    if ($bigName === 'Yes' || $bigName === true || $bigName === 1 || $bigName === '1') {
        $commands .= $esc . "!" . "\x38"; // Double height + double width + bold
        $commands .= escText($storeName, 'center', 26);
        $commands .= $esc . "!" . "\x00"; // Reset font size
    } else {
        $commands .= escText($storeName, 'center', 20);
    }

    // Bind store address (centered)
    $address = $data['address'] ?? '';
    $phone = $data['phone'] ?? '';
    if ($address) {
        if (hasArabic($address)) {
            $commands .= renderMultiLineAsImage($address, 18, 'center', 576);
        } else {
            $addressLines = explode("\n", str_replace("\r", "", $address));
            foreach ($addressLines as $addrLine) {
                if (trim($addrLine)) {
                    $commands .= trim($addrLine) . $nl;
                }
            }
        }
    }
    if ($phone) {
        $commands .= escText("Mob : " . $phone, 'center', 18);
    }

    // Title: the POS sends doc_type BILL (BILL button, before payment) or INVOICE (checkout).
    // Older POS versions send nothing -> no title, as before.
    $docType = strtoupper((string) ($data['doc_type'] ?? ''));
    if ($docType === 'BILL' || $docType === 'INVOICE') {
        $commands .= "------------------------------------------------" . $nl;
        $commands .= $esc . "a\x01" . $esc . "E\x01";
        $commands .= escText('*** ' . $docType . ' ***', 'center', 22);
        $commands .= $esc . "E\x00";
    }

    // Separator line
    $commands .= "================================================" . $nl;

    // Order info
    $commands .= $esc . "a\x00";
    $commands .= escText(($docType === 'BILL' ? "Bill #    : " : "Invoice # : ") . $orderNo, 'left', 18);
    $commands .= escText("Date      : " . $date . " " . $time, 'left', 18);
    if ($customerName)
        $commands .= escText("Customer  : " . $customerName, 'left', 18);

    // Separator line
    $commands .= "================================================" . $nl;

    // Items content
    if (hasArabic($items)) {
        $commands .= renderMultiLineAsImage($items, 18, 'left', 576);
    } else {
        $commands .= str_replace("\n", $nl, $items);
    }

    // Totals content
    if (hasArabic($totals)) {
        $commands .= renderMultiLineAsImage($totals, 18, 'left', 576);
    } else {
        $commands .= str_replace("\n", $nl, $totals);
    }

    // Payments content
    if (hasArabic($payments)) {
        $commands .= renderMultiLineAsImage($payments, 18, 'left', 576);
    } else {
        $commands .= str_replace("\n", $nl, $payments);
    }

    // Payment status (sent by the POS with doc_type): BILL is always NOT PAID
    $payStatus = (string) ($data['payment_status'] ?? '');
    if ($docType === 'BILL' || ($docType === 'INVOICE' && $payStatus !== '')) {
        $statusText = $docType === 'BILL' ? 'NOT PAID / غير مدفوع'
            : (['paid' => 'PAID / مدفوع', 'partial' => 'PARTIALLY PAID / مدفوع جزئياً'][$payStatus] ?? 'UNPAID / غير مدفوع');
        $commands .= "------------------------------------------------" . $nl;
        $commands .= $esc . "a\x01";
        $commands .= escText($statusText, 'center', 22);
        $commands .= $esc . "a\x00";
    }

    // Footer
    if ($invoiceFooter) {
        $commands .= "------------------------------------------------" . "\x0A";
        $commands .= $esc . "a\x01";
        $commands .= escText($invoiceFooter, 'center', 18);
    }

    $commands .= "================================================" . "\x0A";
    $commands .= $esc . "a\x01";
    $commands .= "Powered by bizpoz.co" . "\x0A";

    // Feed and cut
    $commands .= $esc . "d\x05";
    $commands .= $gs . "V\x00";

    $printed = false;
    $errorMsg = '';

    // Fast Windows Share printing first for Windows type
    if (!empty($printerPath) && $type === 'windows') {
        $winPath = '\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath);
        $result = @file_put_contents($winPath, $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => $printType, 'method' => 'windows_share']);
            exit;
        }
    }

    // Network printer via IP (fast 1-second timeout)
    if (!empty($printerIp)) {
        $port = !empty($data['printer_port']) ? (int) $data['printer_port'] : 9100;
        $socket = @fsockopen($printerIp, $port, $errno, $errstr, 1);
        if ($socket) {
            fwrite($socket, $commands);
            fclose($socket);
            echo json_encode(['success' => true, 'print_type' => $printType, 'printer_ip' => $printerIp, 'method' => 'socket']);
            exit;
        }
    }

    // Popup mode fallback
    if ($connectionType === 'popup' || empty($connectionType)) {
        $result = @file_put_contents('\\\\127.0.0.1\\POS-80C', $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => $printType, 'method' => 'popup', 'printer' => 'POS-80C']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Could not connect to printer']);
    exit;
}

// ============================================================
// SHIFT REPORT PRINT
// ============================================================
if ($printType === 'SHIFT') {
    $printerIp = $data['printer_ip_address'] ?? '';
    $printerPath = $data['path'] ?? '';
    $printerName = $data['printer_name'] ?? '';
    $type = $data['type'] ?? 'windows';
    $connectionType = $data['connection_type'] ?? 'popup';
    $storeName = $data['store_name'] ?? 'My Shop';
    $shiftNo = $data['sale_no_p'] ?? '';
    $date = $data['date'] ?? '';
    $time = $data['time_inv'] ?? '';
    $customerName = $data['customer_name'] ?? '';
    $items = $data['items'] ?? '';

    // Already built ESC/POS commands in items
    $commands = $items;

    // Send to printer
    $printed = false;
    $errorMsg = '';

    // Network printer via IP
    if (!empty($printerIp) && $connectionType === 'php_print_server') {
        $port = !empty($data['printer_port']) ? (int) $data['printer_port'] : 9100;
        $socket = @fsockopen($printerIp, $port, $errno, $errstr, 5);
        if ($socket) {
            fwrite($socket, $commands);
            fclose($socket);
            echo json_encode(['success' => true, 'print_type' => 'SHIFT', 'printer_ip' => $printerIp, 'port' => $port, 'method' => 'socket']);
            exit;
        } else {
            $errorMsg = "Socket failed: $errstr ($errno)";
        }
    }

    // Windows share - write to shared printer file
    if (!empty($printerPath) && $type === 'windows') {
        $result = @file_put_contents('\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath), $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'SHIFT', 'method' => 'windows_share', 'path' => $printerPath]);
            exit;
        }
    }

    // Popup mode - try direct to printer name
    if ($connectionType === 'popup' || empty($connectionType)) {
        $printerToUse = !empty($printerPath) ? $printerPath : 'POS-80C';
        $result = @file_put_contents('\\\\127.0.0.1\\' . $printerToUse, $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'SHIFT', 'method' => 'popup', 'printer' => $printerToUse]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => $errorMsg ?: 'Could not connect to printer']);
    exit;
}

// BILL / INVOICE PRINT