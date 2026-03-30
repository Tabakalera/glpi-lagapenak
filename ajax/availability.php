<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$fecha_inicio      = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : '';
$fecha_fin         = isset($_GET['fecha_fin'])    ? trim($_GET['fecha_fin'])    : '';
$asset_filter      = isset($_GET['asset_name'])   ? trim($_GET['asset_name'])   : '';
$filter_item_type  = isset($_GET['item_type'])    ? trim($_GET['item_type'])    : '';
$filter_device_key = isset($_GET['device_type'])  ? trim($_GET['device_type'])  : '';

if (empty($fecha_inicio) || empty($fecha_fin)) {
    echo json_encode(['error' => 'Fechas requeridas']);
    exit;
}

// GLPI itemtype → asset table
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
];

// GLPI itemtype → device type field + type table
$type_field_map = [
    'Computer'         => ['field' => 'computertypes_id',         'table' => 'glpi_computertypes'],
    'Monitor'          => ['field' => 'monitortypes_id',          'table' => 'glpi_monitortypes'],
    'Peripheral'       => ['field' => 'peripheraltypes_id',       'table' => 'glpi_peripheraltypes'],
    'Phone'            => ['field' => 'phonetypes_id',            'table' => 'glpi_phonetypes'],
    'Printer'          => ['field' => 'printertypes_id',          'table' => 'glpi_printertypes'],
    'NetworkEquipment' => ['field' => 'networkequipmenttypes_id', 'table' => 'glpi_networkequipmenttypes'],
];

// Pre-load all device type names to avoid N+1 queries
$device_type_names = [];
foreach ($type_field_map as $it => $tf) {
    $res = $DB->query("SELECT `id`, `name` FROM `{$tf['table']}` WHERE `name` != ''");
    $device_type_names[$it] = [];
    if ($res) {
        while ($r = $DB->fetchAssoc($res)) {
            $device_type_names[$it][(int)$r['id']] = $r['name'];
        }
    }
}

// Parse device_type filter key (format: "Peripheral:5")
$filter_device_itype = '';
$filter_device_id    = 0;
if ($filter_device_key !== '' && strpos($filter_device_key, ':') !== false) {
    [$filter_device_itype, $filter_device_id_str] = explode(':', $filter_device_key, 2);
    $filter_device_id = (int)$filter_device_id_str;
}

// All statuses that create a conflict (RETURNED and CANCELLED do not)
$active_statuses = implode(',', [
    PluginLagapenakLoan::STATUS_PENDING,
    PluginLagapenakLoan::STATUS_IN_PROGRESS,
    PluginLagapenakLoan::STATUS_DELIVERED,
]);
$blocking_statuses = implode(',', [
    PluginLagapenakLoan::STATUS_PENDING,
    PluginLagapenakLoan::STATUS_IN_PROGRESS,
]);

$fi = $DB->escape($fecha_inicio);
$ff = $DB->escape($fecha_fin);

// 1. All assets with "Autorizar reservas" active
// (glpi_reservationitems has no entities_id — entity scoping is via the item itself)
$result = $DB->query(
    "SELECT ri.itemtype, ri.items_id
     FROM `glpi_reservationitems` ri
     WHERE ri.is_active = 1
     ORDER BY ri.itemtype, ri.items_id"
);

$all_assets = [];
while ($row = $DB->fetchAssoc($result)) {
    $it    = $row['itemtype'];
    $iid   = (int)$row['items_id'];
    // Apply category filter
    if ($filter_item_type !== '' && $it !== $filter_item_type) continue;

    $table = $type_table[$it] ?? null;
    $name  = $it . ' #' . $iid;
    $device_type_id_val = 0;
    if ($table && $iid) {
        $select_cols = ['name'];
        if (isset($type_field_map[$it])) {
            $select_cols[] = $type_field_map[$it]['field'];
        }
        $res = $DB->request(['SELECT' => $select_cols, 'FROM' => $table, 'WHERE' => ['id' => $iid, 'is_deleted' => 0]]);
        $found = false;
        foreach ($res as $r) {
            $name  = $r['name'];
            $found = true;
            if (isset($type_field_map[$it])) {
                $device_type_id_val = (int)($r[$type_field_map[$it]['field']] ?? 0);
            }
        }
        if (!$found) continue; // asset deleted or not found — skip it
    }

    // Apply device type filter
    if ($filter_device_key !== '' && ($it !== $filter_device_itype || $device_type_id_val !== $filter_device_id)) continue;

    // Apply optional asset name filter (case-insensitive)
    if ($asset_filter !== '' && stripos($name, $asset_filter) === false) {
        continue;
    }

    $device_type_label = ($device_type_id_val > 0 && isset($device_type_names[$it][$device_type_id_val]))
        ? $device_type_names[$it][$device_type_id_val]
        : '';

    $all_assets[] = [
        'itemtype'          => $it,
        'items_id'          => $iid,
        'name'              => $name,
        'type_label'        => PluginLagapenakLoanItem::getTypeLabel($it),
        'device_type_label' => $device_type_label,
        'device_type_id'    => $device_type_id_val,
    ];
}

// 2. For each asset, find active loans overlapping the requested period
//    using effective item dates (COALESCE: item date if set, else loan date)
$rows = [];

// getEntitiesRestrictRequest returns "AND (...)" fragment ready for raw SQL
$loan_entity_condition = getEntitiesRestrictRequest('AND', 'l', 'entities_id', '', false);

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
           {$loan_entity_condition}
           AND COALESCE(li.date_checkout, l.fecha_inicio) < '{$ff}'
           AND COALESCE(li.date_checkin,  l.fecha_fin)    > '{$fi}'"
    );

    $occupied_loans  = [];
    $has_blocking    = false;
    $has_delivered   = false;
    while ($c = $DB->fetchAssoc($conflicts)) {
        $occupied_loans[] = $c;
        if (in_array((int)$c['loan_status'], [PluginLagapenakLoan::STATUS_PENDING, PluginLagapenakLoan::STATUS_IN_PROGRESS])) {
            $has_blocking = true;
        } elseif ((int)$c['loan_status'] === PluginLagapenakLoan::STATUS_DELIVERED) {
            $has_delivered = true;
        }
    }

    // state: 'free' | 'delivered' | 'occupied'
    // 'delivered' = only in DELIVERED-status loans → can still be requested
    // 'occupied'  = in PENDING or IN_PROGRESS loans → truly blocked
    $state     = $has_blocking ? 'occupied' : ($has_delivered ? 'delivered' : 'free');
    $available = !$has_blocking; // free + delivered are selectable

    $rows[] = [
        'itemtype'          => $asset['itemtype'],
        'items_id'          => $asset['items_id'],
        'name'              => $asset['name'],
        'type_label'        => $asset['type_label'],
        'device_type_label' => $asset['device_type_label'],
        'device_type_id'    => $asset['device_type_id'],
        'available'         => $available,
        'state'             => $state,
        'occupied_loans'    => $occupied_loans,
    ];
}

// Sort: occupied → delivered → free, then alphabetical
$state_order = ['occupied' => 0, 'delivered' => 1, 'free' => 2];
usort($rows, function($a, $b) use ($state_order) {
    $oa = $state_order[$a['state']] ?? 2;
    $ob = $state_order[$b['state']] ?? 2;
    if ($oa !== $ob) return $oa - $ob;
    return strcmp($a['name'], $b['name']);
});

echo json_encode([
    'fecha_inicio'  => $fecha_inicio,
    'fecha_fin'     => $fecha_fin,
    'asset_filter'  => $asset_filter,
    'rows'          => $rows,
    'loan_form_url' => Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php',
]);

