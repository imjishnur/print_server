<?php
/**
 * BIZPOZ Print Server - Printer Driver
 * Handles KOT and BILL printing via raw ESC/POS commands
 * Expects $_print_data and $_print_type
 */

if (!isset($_print_data)) {
    echo json_encode(['success' => false, 'error' => 'No print data provided']);
    exit;
}

$data = $_print_data;
$printType = $_print_type ?? 'KOT';

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

    // Store name - center, double size
    $commands .= $esc . "a\x01";
    $commands .= $esc . "!" . "\x38"; // double height + bold
    $commands .= $storeName . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Separator line
    $commands .= $esc . "a\x01";
    $commands .= "========================================" . "\x0A";

    // KOT header
    $commands .= $esc . "!" . "\x30"; // double height
    $commands .= "K O T" . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Separator line
    $commands .= "========================================" . "\x0A";

    // Order info - left align
    $commands .= $esc . "a\x00";
    $commands .= "Order #  : " . $orderNo . "\x0A";
    $commands .= "Date     : " . $date . " " . $time . "\x0A";
    if ($customerName)
        $commands .= "Customer : " . $customerName . "\x0A";
    if ($tableName)
        $commands .= "Table    : " . $tableName . "\x0A";

    // Separator
    $commands .= "----------------------------------------" . "\x0A";

    // Items header
    $commands .= str_pad("#", 4) . str_pad("Item", 22) . str_pad("Qty", 5) . "\x0A";
    $commands .= "----------------------------------------" . "\x0A";

    // Parse and print items
    if (is_string($items)) {
        $lines = explode("\n", $items);
        $capture = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\s+/', $line))
                $capture = true;
            if ($capture && $line !== '' && !preg_match('/^-+$/', $line) && stripos($line, 'total') === false) {
                if (preg_match('/^\s*(\d+)\s+(.+?)\s{2,}(\d+)\s*$/', $line, $m)) {
                    $num = str_pad($m[1], 4);
                    $name = str_pad(substr($m[2], 0, 22), 22);
                    $qty = str_pad($m[3], 5);
                    $commands .= $num . $name . $qty . "\x0A";
                } elseif (trim($line)) {
                    $commands .= trim($line) . "\x0A";
                }
            }
        }
    }

    // Separator
    $commands .= "----------------------------------------" . "\x0A";

    // Total - bold, double size
    $commands .= $esc . "!" . "\x08"; // bold
    $commands .= str_pad("Total Items:", 22) . str_pad("1", 5) . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Big order number - center, large
    $commands .= $esc . "a\x01";
    $commands .= $esc . "!" . "\x38"; // double height
    $commands .= "#" . $orderNo . "\x0A";
    $commands .= $esc . "!" . "\x00";

    // Separator
    $commands .= "========================================" . "\x0A";

    // Footer
    $commands .= $esc . "a\x01";
    $commands .= "Powered by BIZPOZ" . "\x0A";

    // Feed and cut
    $commands .= $esc . "d\x03";
    $commands .= $gs . "V\x00"; // cut

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
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'printer_ip' => $printerIp, 'port' => $port, 'method' => 'socket']);
            exit;
        } else {
            $errorMsg = "Socket failed: $errstr ($errno)";
        }
    }

    // Windows share - write to shared printer file
    if (!empty($printerPath) && $type === 'windows') {
        $result = @file_put_contents('\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath), $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'method' => 'windows_share', 'path' => $printerPath]);
            exit;
        }
    }

    // Popup mode - try direct to printer name
    if ($connectionType === 'popup' || empty($connectionType)) {
        // Try printing directly to printer share
        $result = @file_put_contents('\\\\127.0.0.1\\POS-80C', $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => 'KOT', 'method' => 'popup', 'printer' => 'POS-80C']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => $errorMsg ?: 'Could not connect to printer']);
    exit;
}

// ============================================================
// BILL / INVOICE PRINT
// ============================================================
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

    // Store name - center, double size
    $commands .= $esc . "a\x01";
    $commands .= $esc . "!" . "\x38";
    $commands .= $storeName . $nl;
    $commands .= $esc . "!" . "\x00";

    // Bind store address (centered)
    $address = $data['address'] ?? '';
    $phone = $data['phone'] ?? '';
    if ($address) {
        $addressLines = explode("\n", str_replace("\r", "", $address));
        foreach ($addressLines as $addrLine) {
            if (trim($addrLine)) {
                $commands .= trim($addrLine) . $nl;
            }
        }
    }
    if ($phone) {
        $commands .= "Mob : " . $phone . $nl;
    }

    // Separator line
    $commands .= "========================================" . $nl;

    // Order info
    $commands .= $esc . "a\x00";
    $commands .= "Invoice # : " . $orderNo . $nl;
    $commands .= "Date      : " . $date . " " . $time . $nl;
    if ($customerName)
        $commands .= "Customer  : " . $customerName . $nl;

    // Separator line
    $commands .= "========================================" . $nl;

    // Items content - replace \n with \r\n for proper thermal printer output
    $commands .= str_replace("\n", $nl, $items);

    // Totals content
    $commands .= str_replace("\n", $nl, $totals);

    // Payments content
    $commands .= str_replace("\n", $nl, $payments);

    // Footer
    if ($invoiceFooter) {
        $commands .= "----------------------------------------" . "\x0A";
        $commands .= $esc . "a\x01";
        $commands .= $invoiceFooter . "\x0A";
    }

    $commands .= "========================================" . "\x0A";
    $commands .= $esc . "a\x01";
    $commands .= "Powered by BIZPOZ" . "\x0A";

    // Feed and cut
    $commands .= $esc . "d\x03";
    $commands .= $gs . "V\x00";

    $printed = false;
    $errorMsg = '';

    if (!empty($printerIp) && $connectionType === 'php_print_server') {
        $port = !empty($data['printer_port']) ? (int) $data['printer_port'] : 9100;
        $socket = @fsockopen($printerIp, $port, $errno, $errstr, 5);
        if ($socket) {
            fwrite($socket, $commands);
            fclose($socket);
            echo json_encode(['success' => true, 'print_type' => $printType, 'printer_ip' => $printerIp, 'method' => 'socket']);
            exit;
        }
    }

    if (!empty($printerPath) && $type === 'windows') {
        $result = @file_put_contents('\\\\127.0.0.1\\' . str_replace('/', '\\', $printerPath), $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => $printType, 'method' => 'windows_share']);
            exit;
        }
    }

    if ($connectionType === 'popup' || empty($connectionType)) {
        $result = @file_put_contents('\\\\127.0.0.1\\POS-80C', $commands);
        if ($result !== false) {
            echo json_encode(['success' => true, 'print_type' => $printType, 'method' => 'popup']);
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