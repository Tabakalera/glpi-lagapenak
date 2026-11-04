<?php

include('../../../inc/includes.php');

global $DB;

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

function ics_output(string $cal_name, string $filename, array $vevents): void {
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
}

$type = (isset($_GET['type']) && $_GET['type'] === 'assets') ? 'assets' : 'loans';

// ── Auth and scope ───────────────────────────────────────────────────────────
// Scope = the loans the user requested + the loans of the entities they supervise (READ + UPDATE),
// computed on every request: from the token owner's current rights (subscriptions) or from the
// active profile and entities (GLPI session).
$token_provided = isset($_GET['token']) ? trim((string) $_GET['token']) : '';

if ($token_provided !== '') {
    if (PluginLagapenakIcsToken::isRetiredGeneralToken($token_provided)) {
        // The general URL of 1.6.0 gave every loan of every entity: it now only explains itself
        $today = date('Ymd');
        ics_output('Lagapenak', 'prestamos.ics', [implode("\r\n", array_map('ics_fold', [
            'BEGIN:VEVENT',
            'UID:lagapenak-general-url-retired@glpi',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:' . $today,
            'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day')),
            'SUMMARY:' . ics_escape('Este enlace de calendario ya no funciona'),
            'DESCRIPTION:' . ics_escape("El enlace general de Lagapenak se ha retirado.\nSuscríbete con tu enlace personal: Lagapenak > Calendario > Suscribirse al calendario."),
            'END:VEVENT',
        ]))]);
        exit;
    }
    $owner = PluginLagapenakIcsToken::resolve($token_provided);
    $scope = $owner !== null ? PluginLagapenakIcsToken::getFeedScope($owner) : null;
    if ($scope === null) {
        http_response_code(403);
        die('Token inválido.');
    }
} else {
    Session::checkLoginUser();
    if (!PluginLagapenakLoan::canView()) {
        http_response_code(403);
        die('Sin permiso.');
    }
    $scope = [
        'users_id' => (int) Session::getLoginUserID(),
        'entities' => PluginLagapenakLoan::canSupervise()
            ? array_map('intval', $_SESSION['glpiactiveentities'] ?? [])
            : [],
    ];
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

// ── Query loans in scope ──────────────────────────────────────────────────────
$in_scope = [['users_id' => $scope['users_id']]];
if (!empty($scope['entities'])) {
    $in_scope[] = ['entities_id' => $scope['entities']];
}
$loans = $DB->request([
    'FROM'  => 'glpi_plugin_lagapenak_loans',
    'WHERE' => [
        ['status' => ['!=', PluginLagapenakLoan::STATUS_CANCELLED]],
        ['OR' => $in_scope],
    ],
    'ORDER' => 'fecha_inicio ASC',
]);

$plugin_url = plugin_lagapenak_web_dir(true); // absolute URL for external calendar clients

// ── Build VEVENT list ─────────────────────────────────────────────────────────
// Data minimisation: the feed leaves GLPI (calendar apps, phones), so events carry the loan number,
// status and assets only — never the loan name (often a project or a person), people or ID numbers.
$vevents = [];
$now_stamp = gmdate('Ymd\THis\Z');

foreach ($loans as $loan) {
    $lid   = (int) $loan['id'];
    $title = 'Préstamo #' . $lid;
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
            'SUMMARY:'  . ics_escape($title . ' · ' . $status_name),
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
                'DESCRIPTION:' . ics_escape($title . "\nEstado: " . $status_name),
                'URL:'      . $url,
                'END:VEVENT',
            ]));
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────
$supervisor_feed = !empty($scope['entities']);
$cal_name = $type === 'loans'
    ? ($supervisor_feed ? 'Préstamos — Tabakalera' : 'Mis préstamos — Tabakalera')
    : ($supervisor_feed ? 'Activos en préstamo — Tabakalera' : 'Mis activos en préstamo — Tabakalera');
ics_output($cal_name, $type === 'loans' ? 'prestamos.ics' : 'activos_prestamo.ics', $vevents);
