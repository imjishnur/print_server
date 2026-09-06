<?php
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

function print_receipt($data)
{
    //load printer
    if ($data->type == 'network') {
        $connector = new NetworkPrintConnector($data->printer_ip_address, $data->printer_port);
    } elseif ($data->type == 'linux') {
        $connector = new FilePrintConnector($data->path);
    } else {
        $connector = new WindowsPrintConnector($data->path);
    }
    $profile = CapabilityProfile::load($data->profile_);
    $printer = new Printer($connector, $profile);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text(printText("iRestora PLUS", $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    $logo = EscposImage::load(dirname(__FILE__) . "/print_content.png", true);
    $printer->bitImage($logo);
    $printer->setTextSize(1, 1);
    $printer->text(printLine("Total: " . "33", $data->characters_per_line) . "\n");
    $printer->text(drawLine($data->characters_per_line));
    $printer->cut();
    $printer->close();
    //end printing
}
function print_receipt_bill($data)
{
    //load printer
    if ($data->type == 'network') {
        $connector = new NetworkPrintConnector($data->printer_ip_address, $data->printer_port);
    } elseif ($data->type == 'linux') {
        $connector = new FilePrintConnector($data->path);
    } else {
        $connector = new WindowsPrintConnector($data->path);
    }
    $profile = CapabilityProfile::load($data->profile_);
    $printer = new Printer($connector, $profile);

    // Auto-render receipt image if Arabic text is present
    $allText = json_encode($data);
    if (preg_match('/\p{Arabic}/u', $allText) && function_exists('generate_receipt_image_from_data')) {
        $rcptImg = generate_receipt_image_from_data($data);
        if ($rcptImg && file_exists($rcptImg)) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $logo = EscposImage::load($rcptImg, false);
            $printer->bitImage($logo);
            $printer->cut();
            $printer->close();
            @unlink($rcptImg);
            return;
        }
    }

    //start printing
    // Print logo centered
    try {
        if (file_exists(dirname(__FILE__) . '/default_logo.php')) {
            require_once dirname(__FILE__) . '/default_logo.php';
        }
        $logoInput = $data->logo_base64 ?? ($data->logo ?? '');
        if (empty($logoInput) && !empty($GLOBALS['DEFAULT_BASE64_LOGO'])) {
            $logoInput = $GLOBALS['DEFAULT_BASE64_LOGO'];
        }
        $logoPath = '';
        $tempFiles = [];

        if (!empty($logoInput) && is_string($logoInput)) {
            $trimmed = trim($logoInput);
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $trimmed, $matches)) {
                $ext = strtolower($matches[1]);
                $decoded = base64_decode($matches[2]);
                if ($decoded) {
                    $tmp = tempnam(sys_get_temp_dir(), 'pos_logo_') . '.' . ($ext ?: 'png');
                    file_put_contents($tmp, $decoded);
                    $logoPath = $tmp;
                    $tempFiles[] = $tmp;
                }
            } elseif (preg_match('/^[a-zA-Z0-9+\/=\s]+$/', $trimmed) && strlen($trimmed) > 100) {
                $decoded = base64_decode($trimmed);
                if ($decoded) {
                    $tmp = tempnam(sys_get_temp_dir(), 'pos_logo_') . '.png';
                    file_put_contents($tmp, $decoded);
                    $logoPath = $tmp;
                    $tempFiles[] = $tmp;
                }
            } elseif (file_exists($trimmed)) {
                $logoPath = $trimmed;
            } elseif (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                $content = @file_get_contents($trimmed);
                if ($content) {
                    $tmp = tempnam(sys_get_temp_dir(), 'pos_logo_') . '.png';
                    file_put_contents($tmp, $content);
                    $logoPath = $tmp;
                    $tempFiles[] = $tmp;
                }
            }
        }

        if (empty($logoPath) || !file_exists($logoPath)) {
            $fallbacks = [
                dirname(__FILE__) . '/../logo/logo.png',
                dirname(__FILE__) . '/../logo/logo.jpg',
                dirname(__FILE__) . '/logo.png'
            ];
            foreach ($fallbacks as $fb) {
                if (file_exists($fb)) {
                    $logoPath = realpath($fb);
                    break;
                }
            }
        }

        if (!empty($logoPath) && file_exists($logoPath)) {
            $finalPath = $logoPath;
            if (extension_loaded('gd')) {
                $info = @getimagesize($logoPath);
                if ($info) {
                    $origW = $info[0];
                    $origH = $info[1];
                    $mime = $info['mime'];
                    $im = null;
                    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                        $im = @imagecreatefromjpeg($logoPath);
                    } elseif ($mime === 'image/png') {
                        $im = @imagecreatefrompng($logoPath);
                    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                        $im = @imagecreatefromwebp($logoPath);
                    }

                    if ($im) {
                        $maxW = 512;
                        $targetW = $origW;
                        $targetH = $origH;
                        if ($origW > $maxW) {
                            $targetW = $maxW;
                            $targetH = (int)round(($origH / $origW) * $maxW);
                        }
                        $resized = imagecreatetruecolor($targetW, $targetH);
                        $white = imagecolorallocate($resized, 255, 255, 255);
                        imagefill($resized, 0, 0, $white);
                        imagecopyresampled($resized, $im, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
                        imagedestroy($im);

                        $pngTmp = tempnam(sys_get_temp_dir(), 'pos_logo_png_') . '.png';
                        imagepng($resized, $pngTmp);
                        imagedestroy($resized);

                        $finalPath = $pngTmp;
                        $tempFiles[] = $pngTmp;
                    }
                }
            }

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $logo = EscposImage::load($finalPath, false);
            $printer->bitImage($logo);
        }

        foreach ($tempFiles as $tf) {
            if (file_exists($tf)) {
                @unlink($tf);
            }
        }
    } catch (\Exception $e) {
        if (isset($tempFiles)) {
            foreach ($tempFiles as $tf) {
                if (file_exists($tf)) {
                    @unlink($tf);
                }
            }
        }
    }

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text(printText($data->store_name, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    $printer->setTextSize(1, 1);

    // Bind store address
    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->text("Westpack Compound -210\n");
    $printer->text("Street :153  Area 70\n");
    $printer->text("Jeryan Jenaihat\n");
    $printer->text("Doha Qatar\n");
    $printer->text("Mob : 72227985, 72227986\n");

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text("Order Type: " . ($data->sale_type) . "\n");
    $printer->text("Bill No: " . $data->sale_no_p . "\n");
    $printer->feed();
    $printer->setEmphasis(false);
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text("Date: " . ($data->date) . " " . ($data->time_inv) . "\n");
    $printer->text("Sales Associate: " . ($data->sales_associate) . "\n");
    $printer->text("Customer: " . ($data->customer_name) . "\n");
    if ($data->customer_address != NULL && $data->customer_address != "") {
        $printer->text("Address: " . $data->customer_address . "\n");
    }
    if ($data->waiter_name != '') {
        $printer->text("Waiter: " . $data->waiter_name . "\n");
    }

    if ($data->customer_table) {
        $printer->text("Table: " . $data->customer_table . "\n");
    }

    $printer->text($data->items);
    $printer->text(drawLine($data->characters_per_line));
    $printer->text($data->totals);
    $printer->text(drawLine($data->characters_per_line));

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text(printText($data->invoice_footer, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    //$printer->feed(1);
    $printer->cut();
    $printer->close();

}
function print_receipt_bot($data)
{
    //load printer
    if ($data->type == 'network') {
        $connector = new NetworkPrintConnector($data->printer_ip_address, $data->printer_port);
    } elseif ($data->type == 'linux') {
        $connector = new FilePrintConnector($data->path);
    } else {
        $connector = new WindowsPrintConnector($data->path);
    }
    $profile = CapabilityProfile::load($data->profile_);
    $printer = new Printer($connector, $profile);

    // Auto-render receipt image if Arabic text is present
    $allText = json_encode($data);
    if (preg_match('/\p{Arabic}/u', $allText) && function_exists('generate_receipt_image_from_data')) {
        $rcptImg = generate_receipt_image_from_data($data);
        if ($rcptImg && file_exists($rcptImg)) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $logo = EscposImage::load($rcptImg, false);
            $printer->bitImage($logo);
            $printer->cut();
            $printer->close();
            @unlink($rcptImg);
            return;
        }
    }


    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text(printText($data->store_name, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    $printer->setTextSize(1, 1);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text("Order Type: " . ($data->sale_type) . "\n");
    $printer->text("BOT: " . $data->sale_no_p . "\n");
    $printer->feed();
    $printer->setEmphasis(false);
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text("Date: " . ($data->date) . " " . ($data->time_inv) . "\n");
    $printer->text("Customer: " . ($data->customer_name) . "\n");
    if ($data->customer_address != NULL && $data->customer_address != "") {
        $printer->text("Address: " . $data->customer_address . "\n");
    }
    if ($data->waiter_name != '') {
        $printer->text("Waiter: " . $data->waiter_name . "\n");
    }

    if ($data->customer_table) {
        $printer->text("Table: " . $data->customer_table . "\n");
    }

    $printer->text($data->items);
    $printer->text(drawLine($data->characters_per_line));
    $printer->text($data->totals);
    $printer->text(drawLine($data->characters_per_line));

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text(printText($data->invoice_footer, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    //$printer->feed(1);
    $printer->cut();
    $printer->close();

}
function print_receipt_kot($data)
{
    //load printer
    if ($data->type == 'network') {
        $connector = new NetworkPrintConnector($data->printer_ip_address, $data->printer_port);
    } elseif ($data->type == 'linux') {
        $connector = new FilePrintConnector($data->path);
    } else {
        $connector = new WindowsPrintConnector($data->path);
    }
    $profile = CapabilityProfile::load($data->profile_);
    $printer = new Printer($connector, $profile);

    // Auto-render receipt image if Arabic text is present
    $allText = json_encode($data);
    if (preg_match('/\p{Arabic}/u', $allText) && function_exists('generate_receipt_image_from_data')) {
        $rcptImg = generate_receipt_image_from_data($data);
        if ($rcptImg && file_exists($rcptImg)) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $logo = EscposImage::load($rcptImg, false);
            $printer->bitImage($logo);
            $printer->cut();
            $printer->close();
            @unlink($rcptImg);
            return;
        }
    }

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->setTextSize(2, 2);
    $printer->text(printText($data->store_name, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    $printer->setTextSize(1, 1);

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text("Order Type: " . ($data->sale_type) . "\n");
    $printer->text("KOT: " . $data->sale_no_p . "\n");
    $printer->feed();
    $printer->setEmphasis(false);
    $printer->setJustification(Printer::JUSTIFY_LEFT);
    $printer->text("Date: " . ($data->date) . " " . ($data->time_inv) . "\n");
    $printer->text("Customer: " . ($data->customer_name) . "\n");
    if ($data->customer_address != NULL && $data->customer_address != "") {
        $printer->text("Address: " . $data->customer_address . "\n");
    }
    if ($data->waiter_name != '') {
        $printer->text("Waiter: " . $data->waiter_name . "\n");
    }

    if ($data->customer_table) {
        $printer->text("Table: " . $data->customer_table . "\n");
    }

    $printer->text($data->items);
    $printer->text(drawLine($data->characters_per_line));
    $printer->text($data->totals);
    $printer->text(drawLine($data->characters_per_line));

    $printer->setJustification(Printer::JUSTIFY_CENTER);
    $printer->setEmphasis(true);
    $printer->text(printText($data->invoice_footer, $data->characters_per_line) . "\n");
    $printer->setEmphasis(false);
    //$printer->feed(1);
    $printer->cut();
    $printer->close();

}



