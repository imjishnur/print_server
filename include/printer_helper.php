    <?php
    if(! function_exists('product_name')) {
        function product_name($name, $size = 0) {
            if (!$size) { $size = 42; }
            return character_limiter($name, ($size-5));
        }
    }

    if(! function_exists('drawLine')) {
        function drawLine($size) {
            $line = '';
            for ($i = 1; $i <= $size; $i++) {
                $line .= '-';
            }
            return $line."\n";
        }
    }

    if(! function_exists('printLine')) {
        function printLine($str, $size, $sep = ":", $space = NULL) {
            $size = $space ? $space : $size;
            $lenght = strlen($str);
            list($first, $second) = explode(":", $str, 2);
            $line = $first . ($sep == ":" ? $sep : '');
            for ($i = 1; $i < ($size - $lenght); $i++) {
                $line .= ' ';
            }
            $line .= ($sep != ":" ? $sep : '') . $second;
            return $line;
        }
    }

    if(! function_exists('printText')) {
        function printText($text, $size) {
            $line = wordwrap($text, $size, "\\n");
            return $line;
        }
    }

    if(! function_exists('taxLine')) {
        function taxLine($name, $code, $qty, $amt, $tax, $size) {
            return printLine(printLine(printLine(printLine($name . ':' . $code, 16, '') . ':' . $qty, 22, '') . ':' . $amt, 33, '') . ':' . $tax, $size, '');
        }
    }

    if ( ! function_exists('character_limiter')) {
        function character_limiter($str, $n = 500, $end_char = '&#8230;') {
            if (mb_strlen($str) < $n) {
                return $str;
            }
            $str = preg_replace('/ {2,}/', ' ', str_replace(array("\r", "\n", "\t", "\x0B", "\x0C"), ' ', $str));
            if (mb_strlen($str) <= $n) {
                return $str;
            }

            $out = '';
            foreach (explode(' ', trim($str)) as $val) {
                $out .= $val.' ';
                if (mb_strlen($out) >= $n) {
                    $out = trim($out);
                    return (mb_strlen($out) === mb_strlen($str)) ? $out : $out.$end_char;
                }
            }
        }
    }

    if ( ! function_exists('word_wrap')) {
        function word_wrap($str, $charlim = 76) {
            is_numeric($charlim) OR $charlim = 76;
            $str = preg_replace('| +|', ' ', $str);
            if (strpos($str, "\r") !== FALSE) {
                $str = str_replace(array("\r\n", "\r"), "\n", $str);
            }
            $unwrap = array();
            if (preg_match_all('|\{unwrap\}(.+?)\{/unwrap\}|s', $str, $matches)) {
                for ($i = 0, $c = count($matches[0]); $i < $c; $i++)
                {
                    $unwrap[] = $matches[1][$i];
                    $str = str_replace($matches[0][$i], '{{unwrapped'.$i.'}}', $str);
                }
            }

            $str = wordwrap($str, $charlim, "\n", FALSE);
            $output = '';
            foreach (explode("\n", $str) as $line) {
                if (mb_strlen($line) <= $charlim) {
                    $output .= $line."\n";
                    continue;
                }
                $temp = '';
                while (mb_strlen($line) > $charlim) {
                    if (preg_match('!\[url.+\]|://|www\.!', $line)) {
                        break;
                    }
                    $temp .= mb_substr($line, 0, $charlim - 1);
                    $line = mb_substr($line, $charlim - 1);
                }
                if ($temp !== '') {
                    $output .= $temp."\n".$line."\n";
                } else {
                    $output .= $line."\n";
                }
            }

            if (count($unwrap) > 0) {
                foreach ($unwrap as $key => $val) {
                    $output = str_replace('{{unwrapped'.$key.'}}', $val, $output);
                }
            }

            return $output;
        }
    }

    if (! function_exists('shape_arabic_text')) {
        function shape_arabic_text($text, $maxChars = 40) {
            if (empty($text) || !preg_match('/\p{Arabic}/u', $text)) {
                return $text;
            }
            static $arabic = null;
            if ($arabic === null) {
                $arabicFile = dirname(__FILE__) . '/../I18N/Arabic.php';
                if (file_exists($arabicFile)) {
                    require_once $arabicFile;
                    $arabic = new I18N_Arabic('Glyphs');
                }
            }
            if (!$arabic) {
                return $text;
            }
            // Shape only Arabic word tokens to preserve English, numbers, brackets & column alignment
            return preg_replace_callback('/\p{Arabic}+/u', function($m) use ($arabic) {
                return $arabic->utf8Glyphs($m[0]);
            }, $text);
        }
    }

    if (! function_exists('encode_arabic_escpos')) {
        function encode_arabic_escpos($text, $maxChars = 40) {
            if (!preg_match('/\p{Arabic}/u', $text)) {
                return $text;
            }
            $shaped = shape_arabic_text($text, $maxChars);
            return iconv('UTF-8', 'Windows-1256//IGNORE', $shaped);
        }
    }

    if (! function_exists('process_arabic_lines')) {
        function process_arabic_lines($text, $maxChars = 40) {
            if (empty($text)) {
                return '';
            }
            if (!preg_match('/\p{Arabic}/u', $text)) {
                return $text;
            }
            $lines = explode("\n", str_replace("\r", "", $text));
            $processed = [];
            foreach ($lines as $line) {
                if (preg_match('/\p{Arabic}/u', $line)) {
                    $processed[] = shape_arabic_text($line, $maxChars);
                } else {
                    $processed[] = $line;
                }
            }
            return implode("\n", $processed);
        }
    }

    if (! function_exists('generate_receipt_image_from_text')) {
        function generate_receipt_image_from_text($text, $fontSize = 16, $width = 576) {
            $fontFile = dirname(__FILE__) . '/../I18N/Arabic/Examples/GD/ae_AlHor.ttf';
            if (!file_exists($fontFile) || !extension_loaded('gd')) {
                return false;
            }

            $arabicFile = dirname(__FILE__) . '/../I18N/Arabic.php';
            if (file_exists($arabicFile)) {
                require_once $arabicFile;
                $arabic = new I18N_Arabic('Glyphs');
            } else {
                $arabic = null;
            }

            $rawLines = explode("\n", str_replace("\r", "", $text));
            $shapedLines = [];
            foreach ($rawLines as $line) {
                if ($arabic && preg_match('/\p{Arabic}/u', $line)) {
                    $shapedLines[] = $arabic->utf8Glyphs($line, 45);
                } else {
                    $shapedLines[] = $line;
                }
            }

            $lineHeight = (int)($fontSize * 1.6);
            $padding = 20;
            $height = count($shapedLines) * $lineHeight + ($padding * 2);

            $im = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($im, 255, 255, 255);
            $black = imagecolorallocate($im, 0, 0, 0);
            imagefill($im, 0, 0, $white);

            $y = $padding + $fontSize;
            foreach ($shapedLines as $line) {
                if (trim($line) !== '') {
                    imagettftext($im, $fontSize, 0, 15, $y, $black, $fontFile, $line);
                }
                $y += $lineHeight;
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'pos_rcpt_') . '.png';
            imagepng($im, $tmpPath);
            imagedestroy($im);
            return $tmpPath;
        }
    }

    if (! function_exists('generate_receipt_image_from_data')) {
        function generate_receipt_image_from_data($data, $width = 576) {
            $fontFile = dirname(__FILE__) . '/../I18N/Arabic/Examples/GD/ae_AlHor.ttf';
            if (!file_exists($fontFile) || !extension_loaded('gd')) {
                return false;
            }

            $arabicFile = dirname(__FILE__) . '/../I18N/Arabic.php';
            $arabic = null;
            if (file_exists($arabicFile)) {
                require_once $arabicFile;
                $arabic = new I18N_Arabic('Glyphs');
            }

            $renderLine = function($text) use ($arabic) {
                if ($arabic && preg_match('/\p{Arabic}/u', $text)) {
                    return $arabic->utf8Glyphs($text, 45);
                }
                return $text;
            };

            $lines = [];

            // Store Name
            $storeName = is_object($data) ? ($data->store_name ?? 'My Shop') : ($data['store_name'] ?? 'My Shop');
            if ($storeName) {
                $lines[] = ['text' => $renderLine($storeName), 'align' => 'center', 'size' => 20];
            }

            // Address & Phone
            $address = is_object($data) ? ($data->address ?? '') : ($data['address'] ?? '');
            if ($address) {
                $addrLines = explode("\n", str_replace("\r", "", $address));
                foreach ($addrLines as $al) {
                    if (trim($al)) {
                        $lines[] = ['text' => $renderLine(trim($al)), 'align' => 'center', 'size' => 13];
                    }
                }
            }
            $phone = is_object($data) ? ($data->phone ?? '') : ($data['phone'] ?? '');
            if ($phone) {
                $lines[] = ['text' => 'Mob : ' . $phone, 'align' => 'center', 'size' => 13];
            }

            $lines[] = ['text' => '========================================', 'align' => 'center', 'size' => 13];

            // Order Info
            $saleType = is_object($data) ? ($data->sale_type ?? '') : ($data['sale_type'] ?? '');
            if ($saleType) {
                $lines[] = ['text' => $renderLine("Order Type: " . $saleType), 'align' => 'center', 'size' => 14];
            }
            $saleNo = is_object($data) ? ($data->sale_no_p ?? '') : ($data['sale_no_p'] ?? '');
            if ($saleNo) {
                $lines[] = ['text' => "Bill / Order #: " . $saleNo, 'align' => 'center', 'size' => 15];
            }
            $date = is_object($data) ? ($data->date ?? '') : ($data['date'] ?? '');
            $time = is_object($data) ? ($data->time_inv ?? '') : ($data['time_inv'] ?? '');
            if ($date || $time) {
                $lines[] = ['text' => "Date: " . trim($date . ' ' . $time), 'align' => 'left', 'size' => 13];
            }

            $salesAssociate = is_object($data) ? ($data->sales_associate ?? '') : ($data['sales_associate'] ?? '');
            if ($salesAssociate) {
                $lines[] = ['text' => $renderLine("Sales Associate: " . $salesAssociate), 'align' => 'left', 'size' => 13];
            }

            $customerName = is_object($data) ? ($data->customer_name ?? '') : ($data['customer_name'] ?? '');
            if ($customerName) {
                $lines[] = ['text' => $renderLine("Customer: " . $customerName), 'align' => 'left', 'size' => 13];
            }

            $customerTable = is_object($data) ? ($data->customer_table ?? '') : ($data['customer_table'] ?? '');
            if ($customerTable) {
                $lines[] = ['text' => $renderLine("Table: " . $customerTable), 'align' => 'left', 'size' => 13];
            }

            $lines[] = ['text' => '----------------------------------------', 'align' => 'center', 'size' => 13];

            // Items
            $items = is_object($data) ? ($data->items ?? '') : ($data['items'] ?? '');
            if ($items) {
                $itemLines = explode("\n", str_replace("\r", "", $items));
                foreach ($itemLines as $il) {
                    if (trim($il) !== '') {
                        $lines[] = ['text' => $renderLine($il), 'align' => 'left', 'size' => 13];
                    }
                }
                $lines[] = ['text' => '----------------------------------------', 'align' => 'center', 'size' => 13];
            }

            // Totals
            $totals = is_object($data) ? ($data->totals ?? '') : ($data['totals'] ?? '');
            if ($totals) {
                $totalLines = explode("\n", str_replace("\r", "", $totals));
                foreach ($totalLines as $tl) {
                    if (trim($tl) !== '') {
                        $lines[] = ['text' => $renderLine($tl), 'align' => 'left', 'size' => 13];
                    }
                }
                $lines[] = ['text' => '----------------------------------------', 'align' => 'center', 'size' => 13];
            }

            // Footer
            $footer = is_object($data) ? ($data->invoice_footer ?? '') : ($data['invoice_footer'] ?? '');
            if ($footer) {
                $lines[] = ['text' => $renderLine($footer), 'align' => 'center', 'size' => 13];
                $lines[] = ['text' => '========================================', 'align' => 'center', 'size' => 13];
            }
            $lines[] = ['text' => 'Powered by BIZPOZ', 'align' => 'center', 'size' => 12];

            // Check logo image
            if (file_exists(dirname(__FILE__) . '/default_logo.php')) {
                require_once dirname(__FILE__) . '/default_logo.php';
            }
            $logoInput = is_object($data) ? ($data->logo_base64 ?? ($data->logo ?? '')) : ($data['logo_base64'] ?? ($data['logo'] ?? ''));
            if (empty($logoInput) && !empty($GLOBALS['DEFAULT_BASE64_LOGO'])) {
                $logoInput = $GLOBALS['DEFAULT_BASE64_LOGO'];
            }
            $logoGd = null;
            $logoW = 0;
            $logoH = 0;
            $logoTemp = null;

            try {
                $logoPath = '';
                if (!empty($logoInput) && is_string($logoInput)) {
                    $trimmed = trim($logoInput);
                    if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $trimmed, $matches)) {
                        $decoded = base64_decode($matches[2]);
                        if ($decoded) {
                            $tmp = tempnam(sys_get_temp_dir(), 'glogo_') . '.png';
                            file_put_contents($tmp, $decoded);
                            $logoPath = $tmp;
                            $logoTemp = $tmp;
                        }
                    } elseif (preg_match('/^[a-zA-Z0-9+\/=\s]+$/', $trimmed) && strlen($trimmed) > 100) {
                        $decoded = base64_decode($trimmed);
                        if ($decoded) {
                            $tmp = tempnam(sys_get_temp_dir(), 'glogo_') . '.png';
                            file_put_contents($tmp, $decoded);
                            $logoPath = $tmp;
                            $logoTemp = $tmp;
                        }
                    } elseif (file_exists($trimmed)) {
                        $logoPath = $trimmed;
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
                    $info = @getimagesize($logoPath);
                    if ($info) {
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

                        if ($srcIm) {
                            $maxLogoW = (int)($width * 0.6); // 60% of printable area
                            if ($origW > $maxLogoW) {
                                $logoW = $maxLogoW;
                                $logoH = (int)round(($origH / $origW) * $maxLogoW);
                            } else {
                                $logoW = $origW;
                                $logoH = $origH;
                            }
                            $logoGd = imagecreatetruecolor($logoW, $logoH);
                            $whiteBg = imagecolorallocate($logoGd, 255, 255, 255);
                            imagefill($logoGd, 0, 0, $whiteBg);
                            imagecopyresampled($logoGd, $srcIm, 0, 0, 0, 0, $logoW, $logoH, $origW, $origH);
                            imagedestroy($srcIm);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore logo failure
            }

            // Calculate height
            $totalHeight = 40;
            if ($logoGd && $logoH > 0) {
                $totalHeight += $logoH + 15;
            }

            foreach ($lines as $line) {
                $fontSize = $line['size'] ?? 13;
                $totalHeight += (int)($fontSize * 1.6);
            }

            $im = imagecreatetruecolor($width, $totalHeight);
            $white = imagecolorallocate($im, 255, 255, 255);
            $black = imagecolorallocate($im, 0, 0, 0);
            imagefill($im, 0, 0, $white);

            $y = 25;

            // Render logo if present
            if ($logoGd && $logoW > 0 && $logoH > 0) {
                $logoX = (int)(($width - $logoW) / 2);
                imagecopy($im, $logoGd, $logoX, $y, 0, 0, $logoW, $logoH);
                imagedestroy($logoGd);
                $y += $logoH + 15;
            }

            if ($logoTemp && file_exists($logoTemp)) {
                @unlink($logoTemp);
            }

            foreach ($lines as $line) {
                $fontSize = $line['size'] ?? 13;
                $textStr = $line['text'];
                $align = $line['align'] ?? 'left';

                $bbox = imagettfbbox($fontSize, 0, $fontFile, $textStr);
                $textWidth = abs($bbox[2] - $bbox[0]);

                if ($align === 'center') {
                    $x = (int)(($width - $textWidth) / 2);
                    if ($x < 10) $x = 10;
                } else {
                    $x = 20;
                }

                imagettftext($im, $fontSize, 0, $x, $y, $black, $fontFile, $textStr);
                $y += (int)($fontSize * 1.6);
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'pos_rcpt_') . '.png';
            imagepng($im, $tmpPath);
            imagedestroy($im);
            return $tmpPath;
        }
    }