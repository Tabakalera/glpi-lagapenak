<?php

/**
 * Links between a loan and an object of an external application (G1: Kide-Artean residencies and
 * assignments), created and removed only through the integration API.
 *
 * Rules (G1 · D2): a loan has at most one active residency link; an assignment link needs the active
 * link of its parent residency on the same loan. Removed links keep who and when; links of a purged
 * loan become "loan_deleted" tombstones.
 */
class PluginLagapenakLoanLink {

    const TABLE = 'glpi_plugin_lagapenak_loanlinks';

    const TYPE_RESIDENCY  = 'residency';
    const TYPE_ASSIGNMENT = 'assignment';

    const STATE_ACTIVE       = 'active';
    const STATE_REMOVED      = 'removed';
    const STATE_LOAN_DELETED = 'loan_deleted';
    const STATE_UNAVAILABLE  = 'unavailable'; // computed, never stored

    static function getObjectTypes(): array {
        return [self::TYPE_RESIDENCY, self::TYPE_ASSIGNMENT];
    }

    static function find(int $loans_id, string $source, string $type, string $object_id): ?array {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'loans_id'    => $loans_id,
                'source'      => $source,
                'object_type' => $type,
                'object_id'   => $object_id,
            ],
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Same lookup by loan UUID: also finds tombstones of purged loans.
     */
    static function findByLoanUuid(string $loan_uuid, string $source, string $type, string $object_id): ?array {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'loan_uuid'   => $loan_uuid,
                'source'      => $source,
                'object_type' => $type,
                'object_id'   => $object_id,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    static function hasTombstone(string $loan_uuid, string $source): bool {
        return countElementsInTable(self::TABLE, [
            'loan_uuid' => $loan_uuid,
            'source'    => $source,
            'state'     => self::STATE_LOAN_DELETED,
        ]) > 0;
    }

    /**
     * Links of a loan for a source, all states except removed.
     */
    static function getForLoan(int $loans_id, string $source): array {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'loans_id' => $loans_id,
                'source'   => $source,
                'NOT'      => ['state' => self::STATE_REMOVED],
            ],
            'ORDER' => ['object_type DESC', 'id'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Create or reactivate a link. The loan row is locked so that two concurrent requests cannot both
     * pass the "one residency" check.
     *
     * @return array{0:int,1:array|string} [HTTP status, link row] or [HTTP status, error code]
     */
    static function put(array $loan, array $client, string $type, string $object_id, ?string $parent, string $label, string $acting_user): array {
        global $DB;

        $source = $client['source'];
        $now    = date('Y-m-d H:i:s');

        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `glpi_plugin_lagapenak_loans` WHERE `id` = ' . (int) $loan['id'] . ' FOR UPDATE');

            $existing = self::find((int) $loan['id'], $source, $type, $object_id);
            $active   = array_filter(self::getForLoan((int) $loan['id'], $source), function ($l) {
                return $l['state'] === self::STATE_ACTIVE;
            });

            if ($type === self::TYPE_RESIDENCY) {
                foreach ($active as $l) {
                    if ($l['object_type'] === self::TYPE_RESIDENCY && $l['object_id'] !== $object_id) {
                        $DB->rollBack();
                        return [409, 'residency_conflict'];
                    }
                }
            } else {
                $parent_ok = false;
                foreach ($active as $l) {
                    if ($l['object_type'] === self::TYPE_RESIDENCY && $l['object_id'] === $parent) {
                        $parent_ok = true;
                    }
                }
                // An assignment already linked under another residency is not silently moved
                if (!$parent_ok
                    || ($existing && $existing['state'] === self::STATE_ACTIVE && $existing['parent_residency_id'] !== $parent)
                ) {
                    $DB->rollBack();
                    return [409, 'parent_residency_mismatch'];
                }
            }

            if ($existing && $existing['state'] === self::STATE_ACTIVE) {
                if ($existing['label'] !== $label) {
                    $DB->update(self::TABLE, ['label' => $label, 'date_mod' => $now], ['id' => $existing['id']]);
                }
                $status = 200;
                $id     = (int) $existing['id'];
            } elseif ($existing) {
                $DB->update(self::TABLE, [
                    'state'               => self::STATE_ACTIVE,
                    'parent_residency_id' => $parent,
                    'label'               => $label,
                    'created_by'          => $acting_user,
                    'removed_by'          => null,
                    'apiclients_id'       => (int) $client['id'],
                    'date_creation'       => $now,
                    'date_mod'            => $now,
                    'date_removed'        => null,
                ], ['id' => $existing['id']]);
                $status = 201;
                $id     = (int) $existing['id'];
            } else {
                $DB->insert(self::TABLE, [
                    'loans_id'            => (int) $loan['id'],
                    'loan_uuid'           => $loan['uuid'],
                    'loan_number'         => (int) $loan['id'],
                    'source'              => $source,
                    'object_type'         => $type,
                    'object_id'           => $object_id,
                    'parent_residency_id' => $parent,
                    'label'               => $label,
                    'state'               => self::STATE_ACTIVE,
                    'created_by'          => $acting_user,
                    'apiclients_id'       => (int) $client['id'],
                    'date_creation'       => $now,
                    'date_mod'            => $now,
                ]);
                $status = 201;
                $id     = (int) $DB->insertId();
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return [$status, self::getById($id)];
    }

    /**
     * Mark a link as removed. Idempotent; tombstones are returned untouched.
     *
     * @return array{0:int,1:array|string}
     */
    static function remove(array $link, string $acting_user): array {
        global $DB;

        if ($link['state'] !== self::STATE_ACTIVE) {
            return [200, $link];
        }
        if ($link['object_type'] === self::TYPE_RESIDENCY
            && countElementsInTable(self::TABLE, [
                'loans_id'            => (int) $link['loans_id'],
                'source'              => $link['source'],
                'object_type'         => self::TYPE_ASSIGNMENT,
                'parent_residency_id' => $link['object_id'],
                'state'               => self::STATE_ACTIVE,
            ]) > 0
        ) {
            return [409, 'dependent_links'];
        }
        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, [
            'state'        => self::STATE_REMOVED,
            'removed_by'   => $acting_user,
            'date_removed' => $now,
            'date_mod'     => $now,
        ], ['id' => (int) $link['id'], 'state' => self::STATE_ACTIVE]);

        return [200, self::getById((int) $link['id'])];
    }

    static function markLoanDeleted(int $loans_id): void {
        global $DB;
        $DB->update(self::TABLE, [
            'state'    => self::STATE_LOAN_DELETED,
            'date_mod' => date('Y-m-d H:i:s'),
        ], [
            'loans_id' => $loans_id,
            'NOT'      => ['state' => self::STATE_LOAN_DELETED],
        ]);
    }

    static function getById(int $id): ?array {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }
}
