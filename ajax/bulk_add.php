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

// Permission check: supervisors of the loan's entity can add to any of its loans;
// non-supervisors (with CREATE) only to their own loan while still PENDING.
$_ba_uid         = (int)($_SESSION['glpiID'] ?? 0);
$_ba_can_sup     = PluginLagapenakLoan::canManageLoan($loan);
$_ba_is_owner    = (int)($loan->fields['users_id'] ?? 0) === $_ba_uid;
$_ba_loan_status = (int)($loan->fields['status'] ?? 0);
if (PluginLagapenakLoan::isCancelled($loan->fields)) {
    echo json_encode(['error' => 'El préstamo está cancelado']);
    exit;
}
if (!$_ba_can_sup
    && !(PluginLagapenakLoan::canView() && $_ba_is_owner && $_ba_loan_status === PluginLagapenakLoan::STATUS_PENDING
         && PluginLagapenakLoan::canCreate())
) {
    echo json_encode(['error' => 'Sin permisos para modificar este préstamo']);
    exit;
}

$added     = 0;
$conflicts = [];
$already   = [];
$rejected  = 0;

foreach ($items as $item) {
    $itemtype = (string) ($item['itemtype'] ?? '');
    $items_id = (int) ($item['items_id'] ?? 0);
    if (!$itemtype || !$items_id) continue;

    // Validate before resolving any name: a rejected item reveals nothing about the object
    if (PluginLagapenakLoanItem::checkAsset($itemtype, $items_id) !== '') {
        $rejected++;
        continue;
    }

    $name = PluginLagapenakLoanItem::getItemName($itemtype, $items_id);

    $conflict_list = PluginLagapenakLoanItem::getConflictingLoans(
        $itemtype, $items_id, $loans_id, $fecha_inicio, $fecha_fin
    );

    if (!empty($conflict_list)) {
        $loan_names = array_map(function ($c) use ($_ba_can_sup) {
            return PluginLagapenakLoanItem::describeConflictingLoan($c, $_ba_can_sup);
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
    'rejected'  => $rejected,
]);
