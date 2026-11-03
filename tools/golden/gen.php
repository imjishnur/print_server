<?php
/**
 * Generates the expected ("golden") ESC/POS bytes with the real PHP print server,
 * so escpos_test.go can check the Go port is byte-identical.
 *
 *   php tools/golden/gen.php C:\laragon\www\print_server
 *
 * The print server is not modified: patched copies of printer_load.php and
 * printer_load_v2.php are written to a temp folder and write their bytes to a file
 * instead of sending them to a printer.
 */

$root = dirname(__DIR__, 2);
$testdata = $root . '/testdata';

if (($argv[1] ?? '') === '--one') {
    runOne($argv[2], $argv[3], $argv[4], $argv[5]);
    exit;
}

$printServer = rtrim($argv[1] ?? 'C:/laragon/www/print_server', '/\\');
$include = $printServer . '/include';
if (!file_exists($include . '/printer_load.php')) {
    fwrite(STDERR, "printer_load.php not found in $include\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/printserver-golden';
@mkdir($tmp, 0777, true);
$lit = var_export(str_replace('\\', '/', $include), true);
$litRoot = var_export(str_replace('\\', '/', $printServer), true);

// v1: dump $commands where printer_load.php starts sending to the printer
$v1 = file_get_contents($include . '/printer_load.php');
$v1 = str_replace(['dirname(__FILE__)', 'dirname(__DIR__)', '__DIR__'], [$lit, $litRoot, $lit], $v1);
$v1 = preg_replace('/^(\s*)\$printed = false;/m', '$1file_put_contents(getenv("GOLDEN_OUT"), $commands); return;', $v1, -1, $count);
if ($count < 3) {
    fwrite(STDERR, "could not patch printer_load.php ($count)\n");
    exit(1);
}
file_put_contents($tmp . '/printer_load.php', $v1);

// v2: dump before "3. Deliver commands to printer"
$v2 = file_get_contents($include . '/printer_load_v2.php');
$v2 = str_replace('// 3. Deliver commands to printer', 'file_put_contents(getenv("GOLDEN_OUT"), $commands); return;', $v2, $count);
if ($count !== 1) {
    fwrite(STDERR, "could not patch printer_load_v2.php\n");
    exit(1);
}
file_put_contents($tmp . '/printer_load_v2.php', $v2);

ensureV2Images($testdata . '/v2');

foreach (glob($testdata . '/v1/*.json') as $json) {
    $name = basename($json, '.json');
    $type = strtoupper(explode('-', $name)[0]);
    $out = $testdata . '/v1/' . $name . '.bin';
    generate($tmp . '/printer_load.php', $json, $type, $out);
}
foreach (glob($testdata . '/v2/*.json') as $json) {
    $name = basename($json, '.json');
    $out = $testdata . '/v2/' . $name . '.bin';
    generate($tmp . '/printer_load_v2.php', $json, 'v2', $out);
}

function generate($loader, $json, $type, $out)
{
    // One PHP process per sample: printer_load.php declares functions
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --one '
        . escapeshellarg($loader) . ' ' . escapeshellarg($json) . ' ' . escapeshellarg($type) . ' ' . escapeshellarg($out);
    @unlink($out);
    exec($cmd, $lines, $code);
    printf("%-28s %s\n", basename($out), file_exists($out) ? filesize($out) . ' bytes' : 'FAILED ' . implode(' ', $lines));
}

function runOne($loader, $json, $type, $out)
{
    putenv('GOLDEN_OUT=' . $out);
    $sample = json_decode(file_get_contents($json), true);
    ob_start();
    if ($type === 'v2') {
        $_image_base64 = base64_encode(file_get_contents(dirname($json) . '/' . $sample['image']));
        $_print_data = $sample;
        $_print_type = $sample['print_type'] ?? 'BILL';
    } else {
        $_print_data = $sample;
        $_print_type = $type;
    }
    include $loader;
    ob_end_clean();
}

// Test receipts drawn with GD: text, lines and grey levels around the 185 threshold
function ensureV2Images($dir)
{
    $font = 'C:/Windows/Fonts/arial.ttf';
    foreach (['receipt-576.png' => [576, 700], 'receipt-400.png' => [400, 300]] as $file => [$w, $h]) {
        if (file_exists("$dir/$file")) {
            continue;
        }
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $black = imagecolorallocate($im, 0, 0, 0);
        for ($i = 0; $i < 12; $i++) {
            imagettftext($im, 18, 0, 12, 40 + $i * 34, $black, $font, "Line $i  Chicken Biryani  2 x 180.00");
        }
        for ($g = 0; $g < 256; $g += 4) {
            $c = imagecolorallocate($im, $g, $g, $g);
            imagefilledrectangle($im, (int) ($g * ($w - 20) / 256) + 10, $h - 60, (int) (($g + 4) * ($w - 20) / 256) + 10, $h - 20, $c);
        }
        imageline($im, 10, $h - 80, $w - 10, $h - 80, $black);
        imagepng($im, "$dir/$file");
        imagedestroy($im);
    }
}
