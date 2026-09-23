<?php

include('../../../inc/includes.php');

global $DB;

// ── Auth: token (para suscripciones externas) o sesión GLPI ──────────────────
$token_provided = isset($_GET['token']) ? trim($_GET['token']) : '';

// $ics_only_user = 0 → every loan (general token / supervisor session);
//                 > 0 → only the loans requested by that user (personal token / non-supervisor session)
if ($token_provided) {
    $ics_scope = PluginLagapenakIcsToken::resolve($token_provided);
    if ($ics_scope === null) {
        http_response_code(403);
        die('Token inválido.');
    }
    $ics_only_user = $ics_scope['users_id'];
} else {
    Session::checkLoginUser();
    $ics_only_user = PluginLagapenakLoan::canSupervise() ? 0 : (int) Session::getLoginUserID();
}

$type = isset($_GET['type']) ? $_GET['type'] : 'loans'; // 'loans' | 'assets'

// ── iCal helpers ─────────────────────────────────────────────────────────────

// Escape text for iCal (commas, semicolons, backslashes, newlines)
function ics_escape($str) {
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace(';',  '\\;',  $str);
    $str = str_replace(',',  '\\,',  $str);
    $str = str_replace("\n", '\\n',  $str);
    return $str;
}

// Fold long lines at 75 octets (iCal spec §3.1)
function ics_fold($line) {
    $out = '';
    while (mb_strlen($line, 'UTF-8') > 75) {
        $out  .= mb_substr($line, 0, 75, 'UTF-8') . "\r\n ";
        $line  = mb_substr($line, 75, null, 'UTF-8');
    }
    return $out . $line;
}

// Format a DB timestamp as iCal all-day date YYYYMMDD
function ics_date($ts_str) {
    return date('Ymd', strtotime($ts_str));
}

// iCal all-day DTEND must be the day AFTER the last day (exclusive)
function ics_date_end_exclusive($ts_str) {
    return date('Ymd', strtotime($ts_str . ' +1 day'));
}

// ── Asset name lookup ─────────────────────────────────────────────────────────
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
];

function ics_asset_name($itemtype, $items_id) {
    global $DB, $type_table;
    $table = $type_table[$itemtype] ?? null;
    if ($table) {
        $res = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => (int)$items_id]]);
        foreach ($res as $r) { return $r['name']; }
    }
    return $itemtype . ' #' . $items_id;
}

// ── Query loans ───────────────────────────────────────────────────────────────
$loans = $DB->request([
    'FROM'  => 'glpi_plugin_lagapenak_loans',
    'WHERE' => array_merge(
        [['status' => ['!=', PluginLagapenakLoan::STATUS_CANCELLED]]],
        $ics_only_user ? [['users_id' => $ics_only_user]] : []
    ),
    'ORDER' => 'fecha_inicio ASC',
]);

$plugin_url = plugin_lagapenak_web_dir(true); // absolute URL for external calendar clients

// ── Build VEVENT list ─────────────────────────────────────────────────────────
$vevents = [];
$now_stamp = gmdate('Ymd\THis\Z');

foreach ($loans as $loan) {
    $lid   = (int) $loan['id'];
    $title = $loan['name'] ?: ('Préstamo #' . $lid);
    $start = $loan['fecha_inicio'];
    $end   = $loan['fecha_fin'];
    if (!$start) continue;

    $url   = $plugin_url . '/front/loan.form.php?id=' . $lid;
    $status_name = PluginLagapenakLoan::getStatusName((int)$loan['status']);

    // Collect assets for this loan
    $items_res = $DB->request([
        'SELECT' => ['itemtype', 'items_id'],
        'FROM'   => 'glpi_plugin_lagapenak_loanitems',
        'WHERE'  => ['loans_id' => $lid],
    ]);
    $asset_names = [];
    $asset_rows  = [];
    foreach ($items_res as $it) {
        $name = ics_asset_name($it['itemtype'], $it['items_id']);
        $asset_names[] = $name;
        $asset_rows[]  = ['name' => $name, 'itemtype' => $it['itemtype'], 'items_id' => $it['items_id']];
    }

    $dtstart = ics_date($start);
    $dtend   = $end ? ics_date_end_exclusive($end) : ics_date($start . ' +1 day');

    if ($type === 'loans') {
        // ── One event per loan ──────────────────────────────────────────────
        $desc_parts = [];
        $desc_parts[] = 'Estado: ' . $status_name;
        if ($asset_names) {
            $desc_parts[] = 'Activos: ' . implode(', ', $asset_names);
        }
        $desc = implode("\n", $desc_parts);

        $vevents[] = implode("\r\n", array_map('ics_fold', [
            'BEGIN:VEVENT',
            'UID:lagapenak-loan-' . $lid . '@glpi',
            'DTSTAMP:' . $now_stamp,
            'DTSTART;VALUE=DATE:' . $dtstart,
            'DTEND;VALUE=DATE:'   . $dtend,
            'SUMMARY:'  . ics_escape($title),
            'DESCRIPTION:' . ics_escape($desc),
            'URL:'      . $url,
            'END:VEVENT',
        ]));

    } else {
        // ── One event per asset per loan ────────────────────────────────────
        foreach ($asset_rows as $ar) {
            $vevents[] = implode("\r\n", array_map('ics_fold', [
                'BEGIN:VEVENT',
                'UID:lagapenak-asset-' . $lid . '-' . $ar['itemtype'] . '-' . $ar['items_id'] . '@glpi',
                'DTSTAMP:' . $now_stamp,
                'DTSTART;VALUE=DATE:' . $dtstart,
                'DTEND;VALUE=DATE:'   . $dtend,
                'SUMMARY:'  . ics_escape($ar['name']),
                'DESCRIPTION:' . ics_escape('Préstamo: ' . $title . "\nEstado: " . $status_name),
                'URL:'      . $url,
                'END:VEVENT',
            ]));
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────
$cal_name = $type === 'loans' ? 'Préstamos Tabakalera' : 'Activos en préstamo — Tabakalera';
if ($ics_only_user) {
    $cal_name = $type === 'loans' ? 'Mis préstamos — Tabakalera' : 'Mis activos en préstamo — Tabakalera';
}
$filename = $type === 'loans' ? 'prestamos.ics' : 'activos_prestamo.ics';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//Tabakalera//GLPI Lagapenak//ES\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo ics_fold("X-WR-CALNAME:" . ics_escape($cal_name)) . "\r\n";
echo "X-WR-TIMEZONE:Europe/Madrid\r\n";

foreach ($vevents as $v) {
    echo $v . "\r\n";
}

echo "END:VCALENDAR\r\n";
