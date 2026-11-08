<?php
/**
 * BIZPOZ Print Server - text preview of a v1 print job (used by /logs and test.php)
 */

/** Remove ESC/POS control codes so v1 text reads like the paper. */
function plain($s)
{
    $s = (string) $s;
    $s = preg_replace('/[\x1B\x1D][\x20-\x7E]?[\x00-\x09\x0B-\x1F]?/', '', $s);
    return preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $s);
}

/** Text preview of a v1 job, in the order the v1 loader prints it. */
function v1Preview($d, $type)
{
    $w = (int) ($d['characters_per_line'] ?? 48) ?: 48;
    $center = function ($t) use ($w) {
        $t = trim(plain($t));
        $pad = max(0, intdiv($w - mb_strlen($t), 2));
        return str_repeat(' ', $pad) . $t;
    };
    $row = function ($l, $r) use ($w) {
        $l = plain($l);
        $r = plain($r);
        return $l . str_repeat(' ', max(1, $w - mb_strlen($l) - mb_strlen($r))) . $r;
    };
    $line = str_repeat('-', $w);
    $out = [];

    if (!empty($d['store_name'])) $out[] = $center($d['store_name']);
    if ($type !== 'KOT') {
        foreach (explode("\n", plain($d['address'] ?? '')) as $a) if (trim($a) !== '') $out[] = $center($a);
        if (!empty($d['phone'])) $out[] = $center('Mob : ' . $d['phone']);
    }
    // bills: printer_load.php prints a title only when the POS sends doc_type (BILL / INVOICE)
    $docType = strtoupper((string) ($d['doc_type'] ?? ''));
    $isBillJob = $type === 'BILL' || $type === 'INVOICE';
    if (!$isBillJob) {
        $out[] = $center('*** ' . $type . ' ***');
    } elseif ($docType === 'BILL' || $docType === 'INVOICE') {
        $out[] = $line;
        $out[] = $center('*** ' . $docType . ' ***');
        $out[] = $center($docType === 'BILL' ? 'فاتورة مبدئية' : 'فاتورة');
    }
    $out[] = $line;
    if (!empty($d['sale_no_p'])) {
        $label = !$isBillJob ? 'Order No: ' : ($docType === 'BILL' ? 'Bill #   : ' : 'Invoice #: ');
        $out[] = $row($label . $d['sale_no_p'], trim(($d['date'] ?? '') . ' ' . ($d['time_inv'] ?? '')));
    }
    if (!empty($d['customer_table'])) $out[] = 'Table    : ' . $d['customer_table'];
    if (!empty($d['waiter_name'])) $out[] = 'Waiter   : ' . $d['waiter_name'];
    if (!empty($d['customer_name'])) $out[] = 'Customer : ' . plain($d['customer_name']);
    $out[] = $line;
    foreach (['items', 'totals', 'payments'] as $k) {
        if (!empty($d[$k])) $out[] = rtrim(plain($d[$k]), "\n");
    }
    $payStatus = (string) ($d['payment_status'] ?? '');
    if ($isBillJob && ($docType === 'BILL' || ($docType === 'INVOICE' && $payStatus !== ''))) {
        $out[] = $line;
        $out[] = $center($docType === 'BILL' ? 'NOT PAID / غير مدفوع'
            : (['paid' => 'PAID / مدفوع', 'partial' => 'PARTIALLY PAID / مدفوع جزئياً'][$payStatus] ?? 'UNPAID / غير مدفوع'));
    }
    if (!empty($d['invoice_footer'])) {
        $out[] = $line;
        foreach (explode("\n", plain($d['invoice_footer'])) as $f) $out[] = $center($f);
    }
    if ($isBillJob) {
        $out[] = str_repeat('=', $w);
        $out[] = $center('Powered by bizpoz.co');
    }
    return implode("\n", $out);
}

