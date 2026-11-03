<?php

/**
 * Loan events the integration API cannot read from the loan row itself: a purged loan no longer exists and
 * a loan moved to another entity may leave a client's scope. One row per event, no personal data.
 */
class PluginLagapenakLoanEvent {

    const TABLE = 'glpi_plugin_lagapenak_loanevents';

    const DELETED        = 'deleted';
    const ENTITY_CHANGED = 'entity_changed';

    static function record(string $event, array $loan, int $entity_from, int $entity_to, ?string $material = null): void {
        global $DB;
        if (!$DB->tableExists(self::TABLE) || empty($loan['uuid'])) {
            return;
        }
        $DB->insert(self::TABLE, [
            'loans_id'          => (int) $loan['id'],
            'loan_uuid'         => $loan['uuid'],
            'event'             => $event,
            'entities_id_from'  => $entity_from,
            'entities_id_to'    => $entity_to,
            'material_status'   => $material,
            'date'              => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Deletion of a loan whose entity the client may see, or null.
     */
    static function findDeletion(string $loan_uuid, array $entities): ?array {
        global $DB;
        if (!$entities) {
            return null;
        }
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['loan_uuid' => $loan_uuid, 'event' => self::DELETED, 'entities_id_from' => $entities],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }
}
