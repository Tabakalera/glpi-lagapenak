<?php
// Sonda temporal de pruebas (solo entornos aislados; nunca forma parte del plugin desplegado): la batería G1 la
// copia en front/ durante las pruebas E22–X06 y la retira después. Ejerce, con la sesión del
// usuario que llama, las vías internas que el formulario no cubre. Devuelve JSON.
//   ?op=race&id=N&race=sign|status  carga el préstamo, simula una escritura concurrente y llama a cancel()
//   ?op=purge&id=N                  lo que hace una acción masiva de GLPI: can(PURGE) y delete(purge)
//   ?op=reopen&id=N                 intentos de reapertura por vías internas
include('../../../inc/includes.php');
Session::checkLoginUser();
global $DB;
header('Content-Type: application/json');
$id   = (int) ($_GET['id'] ?? 0);
$loan = new PluginLagapenakLoan();
$loan->getFromDB($id);
$out  = [];
$status = function () use ($DB, $id) {
    foreach ($DB->request(['SELECT' => ['status'], 'FROM' => 'glpi_plugin_lagapenak_loans', 'WHERE' => ['id' => $id]]) as $r) {
        return (int) $r['status'];
    }
    return null;
};
switch ($_GET['op'] ?? '') {
    case 'race':
        // The loan was read before the concurrent write: the in-memory copy is stale
        if (($_GET['race'] ?? '') === 'sign') {
            $DB->update('glpi_plugin_lagapenak_loans', ['signature_data' => 'data:image/png;base64,Q'], ['id' => $id]);
        } else {
            $DB->update('glpi_plugin_lagapenak_loans', ['status' => PluginLagapenakLoan::STATUS_IN_PROGRESS], ['id' => $id]);
        }
        $out = ['cancelled' => $loan->cancel('carrera'), 'status' => $status()];
        break;
    case 'purge':
        $can = $loan->can($id, PURGE);
        $out = ['can_purge' => $can, 'deleted' => $can ? $loan->delete(['id' => $id], true) : false,
                'deleted_anyway' => (new PluginLagapenakLoan())->delete(['id' => $id], true), 'exists' => $status() !== null];
        break;
    case 'reopen':
        $li = new PluginLagapenakLoanItem();
        $line = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_lagapenak_loanitems', 'WHERE' => ['loans_id' => $id]]) as $r) {
            $line = (int) $r['id'];
        }
        PluginLagapenakLoanItem::updateItemStatus($line, PluginLagapenakLoanItem::STATUS_DELIVERED);
        PluginLagapenakLoanItem::updateItem($line, PluginLagapenakLoanItem::STATUS_DELIVERED, null, null);
        PluginLagapenakLoanItem::addItem($id, 'Computer', (int) ($_GET['asset'] ?? 0));
        PluginLagapenakLoanItem::applyLoanDateToAllItems($id, '2030-01-01 00:00:00');
        PluginLagapenakLoanItem::syncLoanStatus($id);
        $out = [
            'line_update_right' => $line ? $li->can($line, UPDATE) : null,
            'loan_update'       => $loan->update(['id' => $id, 'observaciones' => 'reabrir', 'status' => PluginLagapenakLoan::STATUS_PENDING]),
            'massive_field'     => $loan->update(['id' => $id, 'field_3' => 'masivo']),
            'date_request'      => $loan->update(['id' => $id, 'requested_date_end' => '2030-01-01 00:00:00', 'date_request_status' => 1]),
            'signature'         => PluginLagapenakLoan::recordSignature($id, 'data:image/png;base64,Q', 'X', '', ''),
            'status'            => $status(),
            'lines_delivered'   => countElementsInTable('glpi_plugin_lagapenak_loanitems', ['loans_id' => $id, 'status' => PluginLagapenakLoanItem::STATUS_DELIVERED]),
            'lines'             => countElementsInTable('glpi_plugin_lagapenak_loanitems', ['loans_id' => $id]),
            'dates_applied'     => countElementsInTable('glpi_plugin_lagapenak_loanitems', ['loans_id' => $id, 'date_checkin' => '2030-01-01 00:00:00']),
        ];
        break;
}
echo json_encode($out);
