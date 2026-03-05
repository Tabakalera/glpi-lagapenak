<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

// GLPI itemtype → table
$type_table = [
    'Computer'         => 'glpi_computers',
    'Monitor'          => 'glpi_monitors',
    'NetworkEquipment' => 'glpi_networkequipments',
    'Peripheral'       => 'glpi_peripherals',
    'Phone'            => 'glpi_phones',
    'Printer'          => 'glpi_printers',
];

// Raw SQL: all distinct assets in non-cancelled loans
$cancelled = (int) PluginLagapenakLoan::STATUS_CANCELLED;
$result = $DB->query(
    "SELECT DISTINCT li.itemtype, li.items_id
     FROM `glpi_plugin_lagapenak_loanitems` li
     JOIN `glpi_plugin_lagapenak_loans` l ON l.id = li.loans_id
     WHERE l.status != {$cancelled}
     ORDER BY li.itemtype, li.items_id"
);

$assets = [];
while ($row = $DB->fetchAssoc($result)) {
    $it    = $row['itemtype'];
    $iid   = (int)$row['items_id'];
    $table = $type_table[$it] ?? null;
    $name  = $it . ' #' . $iid;

    if ($table && $iid) {
        $res = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $iid]]);
        foreach ($res as $r) {
            $name = $r['name'];
        }
    }

    $assets[] = [
        'itemtype' => $it,
        'items_id' => $iid,
        'name'     => $name,
        'label'    => PluginLagapenakLoanItem::getTypeLabel($it) . ': ' . $name,
    ];
}

usort($assets, fn($a, $b) => strcmp($a['label'], $b['label']));

echo json_encode($assets);
