<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

$period_start = sprintf('%04d-%02d-01', $year, $month);
$period_end   = date('Y-m-t', strtotime($period_start));

// ── 1. Loans overlapping this month ──────────────────────────────────────────
$loans_result = $DB->request([
    'FROM'  => 'glpi_plugin_lagapenak_loans',
    'WHERE' => [
        ['status' => ['!=', PluginLagapenakLoan::STATUS_CANCELLED]],
        // fecha_fin IS NULL OR fecha_fin >= period_start
        ['OR' => [
            ['fecha_fin'    => null],
            ['fecha_fin'    => ['>=', $period_start]],
        ]],
        // fecha_inicio IS NULL OR fecha_inicio <= period_end
        ['OR' => [
            ['fecha_inicio' => null],
            ['fecha_inicio' => ['<=', $period_end]],
        ]],
    ],
]);

$loans = [];
foreach ($loans_result as $row) {
    $loans[$row['id']] = $row;
}

if (empty($loans)) {
    echo json_encode([
        'period_start' => $period_start,
        'period_end'   => $period_end,
        'year'  => $year,
        'month' => $month,
        'assets' => [],
    ]);
    exit;
}

$loan_ids = array_keys($loans);

// ── 2. All items for those loans ─────────────────────────────────────────────
$items_result = $DB->request([
    'FROM'  => 'glpi_plugin_lagapenak_loanitems',
    'WHERE' => ['loans_id' => $loan_ids],
]);

// ── 3. Resolve asset names (batch by itemtype) ───────────────────────────────
// Collect ids per itemtype
$by_type = [];
$item_rows = [];
foreach ($items_result as $row) {
    $item_rows[] = $row;
    if ($row['itemtype'] && $row['items_id']) {
        $by_type[$row['itemtype']][] = (int)$row['items_id'];
    }
}

// GLPI itemtype → table map
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
    'SoftwareLicense'  => 'glpi_softwarelicenses',
    'Certificate'      => 'glpi_certificates',
];

$asset_names = []; // [itemtype][items_id] => name
foreach ($by_type as $itemtype => $ids) {
    $table = $type_table[$itemtype] ?? null;
    if (!$table) {
        foreach ($ids as $id) {
            $asset_names[$itemtype][$id] = $itemtype . ' #' . $id;
        }
        continue;
    }
    $res = $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => $table,
        'WHERE'  => ['id' => $ids],
    ]);
    foreach ($res as $r) {
        $asset_names[$itemtype][$r['id']] = $r['name'];
    }
}

// ── 4. Requester names ────────────────────────────────────────────────────────
$user_cache = [];
$get_user_name = function($uid) use (&$user_cache) {
    if (!$uid) return '';
    if (!isset($user_cache[$uid])) {
        $u = new User();
        $user_cache[$uid] = $u->getFromDB($uid) ? $u->getFriendlyName() : '';
    }
    return $user_cache[$uid];
};

// ── 5. Loan status colors ─────────────────────────────────────────────────────
$loan_colors = [
    PluginLagapenakLoan::STATUS_PENDING     => ['bg' => '#ffc107', 'text' => '#000'],
    PluginLagapenakLoan::STATUS_IN_PROGRESS => ['bg' => '#0dcaf0', 'text' => '#000'],
    PluginLagapenakLoan::STATUS_DELIVERED   => ['bg' => '#0d6efd', 'text' => '#fff'],
    PluginLagapenakLoan::STATUS_RETURNED    => ['bg' => '#198754', 'text' => '#fff'],
    PluginLagapenakLoan::STATUS_CANCELLED   => ['bg' => '#6c757d', 'text' => '#fff'],
];

// ── 6. Build assets → loans map ───────────────────────────────────────────────
// Key: "ItemType::items_id"  → unique asset identity
$assets = [];

foreach ($item_rows as $item) {
    $loan = $loans[$item['loans_id']] ?? null;
    if (!$loan) continue;

    $itemtype = $item['itemtype'] ?: 'Unknown';
    $items_id = (int)($item['items_id'] ?? 0);
    $asset_key = $itemtype . '::' . $items_id;

    $name = $asset_names[$itemtype][$items_id]
         ?? ($itemtype . ' #' . $items_id);

    if (!isset($assets[$asset_key])) {
        $assets[$asset_key] = [
            'name'  => $name,
            'type'  => PluginLagapenakLoanItem::getTypeLabel($itemtype),
            'loans' => [],
        ];
    }

    // Clamp loan dates to visible period
    $raw_start = $loan['fecha_inicio'] ?? null;
    $raw_end   = $loan['fecha_fin']    ?? null;
    $start = $raw_start ? max(substr($raw_start, 0, 10), $period_start) : $period_start;
    $end   = $raw_end   ? min(substr($raw_end,   0, 10), $period_end)   : $period_end;

    $loan_status = (int)$loan['status'];
    $color = $loan_colors[$loan_status] ?? ['bg' => '#6c757d', 'text' => '#fff'];
    $requester = $get_user_name((int)($loan['users_id'] ?? 0));

    $assets[$asset_key]['loans'][] = [
        'loan_id'     => (int)$loan['id'],
        'loan_name'   => $loan['name'] ?: ('Préstamo #' . $loan['id']),
        'loan_status' => PluginLagapenakLoan::getStatusName($loan_status),
        'start'       => $start,
        'end'         => $end,
        'color_bg'    => $color['bg'],
        'color_text'  => $color['text'],
        'requester'   => $requester,
    ];
}

// Sort by asset name
uasort($assets, fn($a, $b) => strcmp($a['name'], $b['name']));

echo json_encode([
    'period_start' => $period_start,
    'period_end'   => $period_end,
    'year'  => $year,
    'month' => $month,
    'assets' => array_values($assets),
]);
