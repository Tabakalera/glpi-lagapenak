<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$range_start = isset($_GET['start'])    ? $_GET['start']    : null;
$range_end   = isset($_GET['end'])      ? $_GET['end']      : null;
// Optional asset filter (for "Por activo" view)
$filter_itemtype = isset($_GET['itemtype'])  ? $_GET['itemtype']  : '';
$filter_items_id = isset($_GET['items_id']) ? (int)$_GET['items_id'] : 0;

$where = [['status' => ['!=', PluginLagapenakLoan::STATUS_CANCELLED]]];

if ($range_start) {
    $where[] = ['OR' => [
        ['fecha_fin'    => null],
        ['fecha_fin'    => ['>=', date('Y-m-d H:i:s', strtotime($range_start))]],
    ]];
}
if ($range_end) {
    $where[] = ['OR' => [
        ['fecha_inicio' => null],
        ['fecha_inicio' => ['<=', date('Y-m-d H:i:s', strtotime($range_end))]],
    ]];
}

// If asset filter: find loans that include this asset
$asset_loan_ids = null;
if ($filter_itemtype && $filter_items_id) {
    $asset_loan_ids = [];
    $res = $DB->request([
        'SELECT' => ['loans_id'],
        'FROM'   => 'glpi_plugin_lagapenak_loanitems',
        'WHERE'  => ['itemtype' => $filter_itemtype, 'items_id' => $filter_items_id],
    ]);
    foreach ($res as $r) {
        $asset_loan_ids[] = (int)$r['loans_id'];
    }
    if (empty($asset_loan_ids)) {
        echo json_encode([]);
        exit;
    }
    $where[] = ['id' => $asset_loan_ids];
}

$loans = $DB->request(['FROM' => 'glpi_plugin_lagapenak_loans', 'WHERE' => $where]);

$colors = [
    PluginLagapenakLoan::STATUS_PENDING     => '#ffc107',
    PluginLagapenakLoan::STATUS_IN_PROGRESS => '#0dcaf0',
    PluginLagapenakLoan::STATUS_DELIVERED   => '#0d6efd',
    PluginLagapenakLoan::STATUS_RETURNED    => '#198754',
    PluginLagapenakLoan::STATUS_CANCELLED   => '#6c757d',
];
$dark_text = [PluginLagapenakLoan::STATUS_PENDING, PluginLagapenakLoan::STATUS_IN_PROGRESS];

// GLPI itemtype → table
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
];

$user_cache = [];
$events = [];

foreach ($loans as $loan) {
    $id     = (int)$loan['id'];
    $status = (int)$loan['status'];

    // For timeGrid views: keep datetime precision; for dayGrid: all-day date
    $start_raw = $loan['fecha_inicio'];
    $end_raw   = $loan['fecha_fin'];

    if ($start_raw) {
        $start_dt = new DateTime($start_raw);
    } else {
        $start_dt = null;
    }
    if ($end_raw) {
        $end_dt = new DateTime($end_raw);
    } else {
        $end_dt = null;
    }

    // If asset filter: render as time event (with hours)
    // If global view: render as all-day event (date only, FullCalendar adds +1 day for inclusive end)
    if ($filter_itemtype && $filter_items_id) {
        // Time-precise events for asset view
        $start = $start_dt ? $start_dt->format('Y-m-d\TH:i:s') : null;
        $end   = $end_dt   ? $end_dt->format('Y-m-d\TH:i:s')   : null;
        $all_day = false;
    } else {
        // All-day events for monthly view
        $start = $start_dt ? $start_dt->format('Y-m-d') : null;
        $end   = $end_dt   ? date('Y-m-d', strtotime($end_dt->format('Y-m-d') . ' +1 day')) : null;
        $all_day = true;
    }

    // Collect asset names
    $items = $DB->request([
        'SELECT' => ['itemtype', 'items_id'],
        'FROM'   => 'glpi_plugin_lagapenak_loanitems',
        'WHERE'  => ['loans_id' => $id],
    ]);
    $asset_names = [];
    foreach ($items as $item) {
        $it    = $item['itemtype'];
        $iid   = (int)$item['items_id'];
        $table = $type_table[$it] ?? null;
        if ($table && $iid) {
            $res = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $iid]]);
            foreach ($res as $r) { $asset_names[] = $r['name']; }
        } elseif ($it) {
            $asset_names[] = $it . ' #' . $iid;
        }
    }

    // Requester name
    $uid = (int)($loan['users_id'] ?? 0);
    if ($uid && !isset($user_cache[$uid])) {
        $u = new User();
        $user_cache[$uid] = $u->getFromDB($uid) ? $u->getFriendlyName() : '';
    }
    $requester = $uid ? ($user_cache[$uid] ?? '') : '';

    $events[] = [
        'id'            => $id,
        'title'         => $loan['name'] ?: ('Préstamo #' . $id),
        'start'         => $start,
        'end'           => $end,
        'allDay'        => $all_day,
        'color'         => $colors[$status] ?? '#6c757d',
        'textColor'     => in_array($status, $dark_text) ? '#000' : '#fff',
        'url'           => Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php?id=' . $id,
        'extendedProps' => [
            'status'    => PluginLagapenakLoan::getStatusName($status),
            'requester' => $requester,
            'assets'    => implode(', ', $asset_names),
        ],
    ];
}

echo json_encode($events);
