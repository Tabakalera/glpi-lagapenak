<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin    = isset($_GET['fecha_fin'])    ? trim($_GET['fecha_fin'])    : '';
$asset_filter = isset($_GET['asset_name'])   ? trim($_GET['asset_name'])   : '';

if (empty($fecha_inicio) || empty($fecha_fin)) {
    echo json_encode(['error' => 'Fechas requeridas']);
    exit;
}

// GLPI itemtype → table
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
];

$active_statuses = implode(',', [
    PluginLagapenakLoan::STATUS_PENDING,
    PluginLagapenakLoan::STATUS_IN_PROGRESS,
    PluginLagapenakLoan::STATUS_DELIVERED,
]);

$fi = $DB->escape($fecha_inicio);
$ff = $DB->escape($fecha_fin);

// 1. All distinct assets that have ever appeared in a loan
$result = $DB->query(
    "SELECT DISTINCT li.itemtype, li.items_id
     FROM `glpi_plugin_lagapenak_loanitems` li
     JOIN `glpi_plugin_lagapenak_loans` l ON l.id = li.loans_id
     WHERE l.status != " . (int)PluginLagapenakLoan::STATUS_CANCELLED . "
     ORDER BY li.itemtype, li.items_id"
);

$all_assets = [];
while ($row = $DB->fetchAssoc($result)) {
    $it    = $row['itemtype'];
    $iid   = (int)$row['items_id'];
    $table = $type_table[$it] ?? null;
    $name  = $it . ' #' . $iid;
    if ($table && $iid) {
        $res = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $iid]]);
        foreach ($res as $r) { $name = $r['name']; }
    }

    // Apply optional asset name filter (case-insensitive)
    if ($asset_filter !== '' && stripos($name, $asset_filter) === false) {
        continue;
    }

    $all_assets[] = [
        'itemtype' => $it,
        'items_id' => $iid,
        'name'     => $name,
        'type_label' => PluginLagapenakLoanItem::getTypeLabel($it),
    ];
}

// 2. For each asset, find active loans overlapping the requested period
//    using effective item dates (COALESCE: item date if set, else loan date)
$rows = [];

foreach ($all_assets as $asset) {
    $it  = $DB->escape($asset['itemtype']);
    $iid = (int)$asset['items_id'];

    $conflicts = $DB->query(
        "SELECT l.id, l.name AS loan_name, l.status AS loan_status,
                COALESCE(li.date_checkout, l.fecha_inicio) AS eff_start,
                COALESCE(li.date_checkin,  l.fecha_fin)    AS eff_end
         FROM `glpi_plugin_lagapenak_loanitems` li
         JOIN `glpi_plugin_lagapenak_loans` l ON l.id = li.loans_id
         WHERE li.itemtype = '{$it}'
           AND li.items_id = {$iid}
           AND l.status IN ({$active_statuses})
           AND COALESCE(li.date_checkout, l.fecha_inicio) < '{$ff}'
           AND COALESCE(li.date_checkin,  l.fecha_fin)    > '{$fi}'"
    );

    $occupied_loans = [];
    while ($c = $DB->fetchAssoc($conflicts)) {
        $occupied_loans[] = $c;
    }

    $available = empty($occupied_loans);

    $rows[] = [
        'itemtype'       => $asset['itemtype'],
        'items_id'       => $asset['items_id'],
        'name'           => $asset['name'],
        'type_label'     => $asset['type_label'],
        'available'      => $available,
        'occupied_loans' => $occupied_loans,
    ];
}

// Sort: occupied first so conflicts are visible at the top; then alphabetical
usort($rows, function($a, $b) {
    if ($a['available'] !== $b['available']) {
        return $a['available'] ? 1 : -1; // occupied first
    }
    return strcmp($a['name'], $b['name']);
});

echo json_encode([
    'fecha_inicio' => $fecha_inicio,
    'fecha_fin'    => $fecha_fin,
    'asset_filter' => $asset_filter,
    'rows'         => $rows,
    'loan_form_url' => Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php',
]);
