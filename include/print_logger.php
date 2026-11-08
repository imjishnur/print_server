<?php
/**
 * BIZPOZ Print Server - Print job logger
 *
 * Saves every print job (v1 text and v2 image) so it can be previewed on /logs:
 *   logs/data/YYYY-MM-DD.jsonl   one JSON line per job (time, version, type, printer, result, drawer)
 *   logs/data/jobs/<id>.png      v2: the exact image that was sent to the printer
 *   logs/data/jobs/<id>.json     v1: the print data (rendered as a text preview on /logs)
 *
 * The result (success / method / error) is read from the JSON the loader echoes, in a shutdown
 * function, because the loaders end with exit. Logging never blocks or breaks a print.
 */

function plog_config()
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        $cfg = is_file($file) ? (array) include $file : [];
    }
    return $cfg;
}

function plog_dir()
{
    return __DIR__ . '/../logs/data';
}

/**
 * Start logging a job. Call before including printer_load.php / printer_load_v2.php.
 *
 * @param string      $version     'v1' or 'v2'
 * @param string      $printType   BILL / KOT / SHIFT ...
 * @param array       $data        print data (content_data or POST fields)
 * @param string|null $imageBase64 v2 image
 */
function plog_begin($version, $printType, $data, $imageBase64 = null)
{
    $cfg = plog_config();
    if (isset($cfg['logs_enabled']) && !$cfg['logs_enabled']) {
        return;
    }

    try {
        $dir = plog_dir();
        $jobs = $dir . '/jobs';
        if (!is_dir($jobs) && !@mkdir($jobs, 0777, true)) {
            return;
        }

        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $data = is_array($data) ? $data : [];
        $preview = '';

        if ($version === 'v2' && !empty($imageBase64)) {
            $png = base64_decode(str_replace(' ', '+', preg_replace('#^data:image/\w+;base64,#i', '', $imageBase64)));
            if ($png && @file_put_contents("$jobs/$id.png", $png) !== false) {
                $preview = "$id.png";
            }
        } else {
            $stored = $data;
            unset($stored['logo_base64']); // large and not needed for the text preview
            if (@file_put_contents("$jobs/$id.json", json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
                $preview = "$id.json";
            }
        }

        $entry = [
            'id'        => $id,
            'time'      => date('Y-m-d H:i:s'),
            'version'   => $version,
            'type'      => strtoupper((string) $printType),
            'order'     => (string) ($data['sale_no_p'] ?? ''),
            'printer'   => (string) ($data['printer_name'] ?? ($data['path'] ?? '')),
            'target'    => (string) (($data['printer_ip'] ?? '') ?: (($data['type'] ?? '') === 'network' ? ($data['printer_ip_address'] ?? '') : ($data['path'] ?? ''))),
            'drawer_requested' => (string) ($data['open_cash_drawer'] ?? ($data['open_cash_drawer_when_printing_invoice'] ?? '')),
            'client'    => $_SERVER['REMOTE_ADDR'] ?? '',
            'preview'   => $preview,
        ];

        $GLOBALS['__plog_entry'] = $entry;
        $GLOBALS['__plog_start'] = microtime(true);
        ob_start();
        register_shutdown_function('plog_finish');
    } catch (\Throwable $e) {
        // never let logging stop a print
    }
}

/** Shutdown: read the loader's JSON reply, write the log line, then send the reply on. */
function plog_finish()
{
    $entry = $GLOBALS['__plog_entry'] ?? null;
    if (!$entry) {
        return;
    }
    $out = ob_get_level() > 0 ? ob_get_contents() : '';

    try {
        $res = json_decode((string) $out, true);
        $entry['success'] = is_array($res) ? !empty($res['success']) : false;
        $entry['method']  = is_array($res) ? (string) ($res['method'] ?? '') : '';
        $entry['error']   = is_array($res) ? (string) ($res['error'] ?? '') : ($out === '' ? 'No reply from loader' : 'Invalid reply');
        if (is_array($res) && !empty($res['path']) && $entry['target'] === '') {
            $entry['target'] = (string) $res['path'];
        }
        // the loaders decide the drawer in $openDrawer (v2: bool, v1: 'Yes' / 'No' with the same rules as printer_load.php)
        if ($entry['success'] && array_key_exists('openDrawer', $GLOBALS)) {
            $d = $GLOBALS['openDrawer'];
            if ($entry['version'] === 'v2') {
                $opened = (bool) $d;
            } elseif ($entry['type'] === 'BILL' || $entry['type'] === 'INVOICE') {
                $opened = $d !== false && $d !== '0' && strcasecmp((string) $d, 'No') !== 0;
            } else {
                $opened = strcasecmp((string) $d, 'Yes') === 0 || $d === true || $d === 1 || $d === '1';
            }
            $entry['drawer'] = $opened ? 'opened' : 'closed';
        }
        $entry['ms'] = (int) round((microtime(true) - ($GLOBALS['__plog_start'] ?? microtime(true))) * 1000);

        $dir = plog_dir();
        $dayFile = $dir . '/' . date('Y-m-d') . '.jsonl';
        $newDay = !is_file($dayFile);
        @file_put_contents($dayFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        if ($newDay) {
            plog_purge();
        }
    } catch (\Throwable $e) {
        // ignore
    }

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

/** Delete logs older than config log_days (default 7). Runs once a day, on the first job. */
function plog_purge()
{
    $cfg = plog_config();
    $days = max(1, (int) ($cfg['log_days'] ?? 7));
    $cutoff = date('Y-m-d', strtotime("-$days days"));
    $dir = plog_dir();

    foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
        if (basename($f, '.jsonl') < $cutoff) {
            @unlink($f);
        }
    }
    $cutoffId = str_replace('-', '', $cutoff);
    foreach (glob($dir . '/jobs/*') ?: [] as $f) {
        if (substr(basename($f), 0, 8) < $cutoffId) {
            @unlink($f);
        }
    }
}
