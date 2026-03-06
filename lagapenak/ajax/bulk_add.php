<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$loans_id     = (int) ($_POST['loans_id']     ?? 0);
// GLPI sanitizes $_POST (addslashes), breaking JSON — use raw $_UPOST for JSON fields
$items_json   = $_UPOST['items']         ?? ($_POST['items']         ?? '[]');
$fecha_inicio = trim( $_POST['fecha_inicio']   ?? '');
$fecha_fin    = trim( $_POST['fecha_fin']      ?? '');

if (!$loans_id || empty($fecha_inicio) || empty($fecha_fin)) {
    echo json_encode(['error' => 'Parámetros incompletos']);
    exit;
}

$items = json_decode($items_json, true);
if (!is_array($items) || empty($items)) {
    echo json_encode(['error' => 'Sin activos seleccionados']);
    exit;
}

// Verify the target loan exists
$loan = new PluginLagapenakLoan();
if (!$loan->getFromDB($loans_id)) {
    echo json_encode(['error' => 'Préstamo no encontrado']);
    exit;
}

$added     = 0;
$conflicts = [];
$already   = [];

foreach ($items as $item) {
    $itemtype = $item['itemtype'] ?? '';
    $items_id = (int) ($item['items_id'] ?? 0);
    if (!$itemtype || !$items_id) continue;

    $name = PluginLagapenakLoanItem::getItemName($itemtype, $items_id);

    $conflict_list = PluginLagapenakLoanItem::getConflictingLoans(
        $itemtype, $items_id, $loans_id, $fecha_inicio, $fecha_fin
    );

    if (!empty($conflict_list)) {
        $loan_names = array_map(function ($c) {
            return '#' . $c['id'] . ' ' . htmlspecialchars($c['name']);
        }, $conflict_list);
        $conflicts[] = ['name' => htmlspecialchars($name), 'loans' => $loan_names];
    } else {
        $result = PluginLagapenakLoanItem::addItem(
            $loans_id, $itemtype, $items_id, $fecha_inicio, $fecha_fin
        );
        if ($result === false) {
            $already[] = htmlspecialchars($name); // duplicate in same loan
        } else {
            $added++;
        }
    }
}

if ($added > 0) {
    PluginLagapenakLoanItem::syncLoanStatus($loans_id);
}

echo json_encode([
    'added'     => $added,
    'conflicts' => $conflicts,
    'already'   => $already,
]);
