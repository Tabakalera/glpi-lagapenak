<?php

/**
 * Represents one asset within a loan.
 *
 * Item statuses are independent from the parent loan status:
 *   Pendiente  (1) — item reserved, not yet handed over
 *   Entregado  (2) — item physically delivered to borrower
 *   Devuelto   (3) — item returned in good condition
 *   Incidencia (4) — item returned with damage / incident opened
 *
 * The parent loan status is managed by the gestor and reflects the
 * overall state of the whole package, not individual items.
 */
class PluginLagapenakLoanItem extends CommonDBTM {

    static $rightname = 'plugin_lagapenak_loan';

    const STATUS_PENDING   = 1;
    const STATUS_DELIVERED = 2;
    const STATUS_RETURNED  = 3;
    const STATUS_INCIDENT  = 4;

    static function getStatusName($status) {
        $statuses = [
            self::STATUS_PENDING   => 'Pendiente',
            self::STATUS_DELIVERED => 'Entregado',
            self::STATUS_RETURNED  => 'Devuelto',
            self::STATUS_INCIDENT  => 'Incidencia',
        ];
        return $statuses[$status] ?? 'Desconocido';
    }

    static function getStatusBadge($status) {
        $classes = [
            self::STATUS_PENDING   => 'bg-warning text-dark',
            self::STATUS_DELIVERED => 'bg-primary',
            self::STATUS_RETURNED  => 'bg-success',
            self::STATUS_INCIDENT  => 'bg-danger',
        ];
        $class = $classes[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . '">' . self::getStatusName($status) . '</span>';
    }

    // ── Asset types ─────────────────────────────────────────────────────────

    static function getAssetTypes() {
        return [
            'Computer'         => 'Ordenador',
            'Monitor'          => 'Monitor',
            'NetworkEquipment' => 'Equip. de red',
            'Peripheral'       => 'Periférico',
            'Phone'            => 'Teléfono',
            'Printer'          => 'Impresora',
        ];
    }

    static function getTypeLabel($itemtype) {
        return self::getAssetTypes()[$itemtype] ?? $itemtype;
    }

    // ── Reservable filter ────────────────────────────────────────────────────

    /**
     * Returns IDs of assets of $itemtype that the user has marked as
     * "Autorizar reservas" in GLPI's native Reservations tab.
     * Returns null if the glpi_reservationitems table doesn't exist.
     */
    static function getReservableIds($itemtype) {
        global $DB;

        if (!$DB->tableExists('glpi_reservationitems')) {
            return null;
        }

        $ids    = [];
        $result = $DB->query(
            "SELECT `items_id` FROM `glpi_reservationitems`
             WHERE `itemtype` = '" . $DB->escape($itemtype) . "'
               AND `is_active` = 1"
        );
        while ($row = $DB->fetchAssoc($result)) {
            $ids[] = (int) $row['items_id'];
        }
        return $ids; // empty array = no reservable assets of this type
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    static function getItemsForLoan($loans_id) {
        global $DB;

        $items  = [];
        $result = $DB->query(
            "SELECT * FROM `glpi_plugin_lagapenak_loanitems`
             WHERE `loans_id` = " . (int) $loans_id . "
             ORDER BY `id` ASC"
        );
        while ($row = $DB->fetchAssoc($result)) {
            $items[] = $row;
        }
        return $items;
    }

    static function addItem($loans_id, $itemtype, $items_id, $date_checkout = null, $date_checkin = null) {
        global $DB;

        if (empty($itemtype) || $items_id <= 0) {
            return false;
        }

        // Avoid duplicates within the same loan
        $exists = $DB->query(
            "SELECT id FROM `glpi_plugin_lagapenak_loanitems`
             WHERE `loans_id` = " . (int) $loans_id . "
               AND `itemtype` = '" . $DB->escape($itemtype) . "'
               AND `items_id` = " . (int) $items_id
        );
        if ($DB->numrows($exists) > 0) {
            return false;
        }

        $data = [
            'loans_id'      => (int) $loans_id,
            'itemtype'      => $itemtype,
            'items_id'      => (int) $items_id,
            'status'        => self::STATUS_PENDING,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s'),
        ];

        if (!empty($date_checkout)) {
            $data['date_checkout'] = $date_checkout;
        }
        if (!empty($date_checkin)) {
            $data['date_checkin'] = $date_checkin;
        }

        $DB->insert('glpi_plugin_lagapenak_loanitems', $data);
        return true;
    }

    static function removeItem($id) {
        global $DB;
        $DB->delete('glpi_plugin_lagapenak_loanitems', ['id' => (int) $id]);
    }

    static function updateItemStatus($id, $status) {
        global $DB;
        $DB->update('glpi_plugin_lagapenak_loanitems', [
            'status'  => (int) $status,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $id]);
    }

    static function updateItem($id, $status, $date_checkout, $date_checkin) {
        global $DB;
        $DB->update('glpi_plugin_lagapenak_loanitems', [
            'status'        => (int) $status,
            'date_checkout' => !empty($date_checkout) ? $date_checkout : null,
            'date_checkin'  => !empty($date_checkin)  ? $date_checkin  : null,
            'date_mod'      => date('Y-m-d H:i:s'),
        ], ['id' => (int) $id]);
    }

    /**
     * Auto-sync the parent loan status based on its items' statuses.
     * Pending  → all items pending
     * Delivered → at least one delivered or returned, not all closed
     * Returned  → all items returned/incident
     * Cancelled → never auto-set; gestor sets it manually
     */
    static function syncLoanStatus($loans_id) {
        global $DB;

        $loan = new PluginLagapenakLoan();
        if (!$loan->getFromDB((int) $loans_id)) {
            return;
        }
        if ((int) $loan->fields['status'] === PluginLagapenakLoan::STATUS_CANCELLED) {
            return;
        }

        $items = self::getItemsForLoan((int) $loans_id);

        if (empty($items)) {
            $new_status = PluginLagapenakLoan::STATUS_PENDING;
        } else {
            $total    = count($items);
            $pending  = 0;
            $returned = 0;
            foreach ($items as $it) {
                $s = (int) $it['status'];
                if ($s === self::STATUS_PENDING) {
                    $pending++;
                } elseif ($s === self::STATUS_RETURNED || $s === self::STATUS_INCIDENT) {
                    $returned++;
                }
            }
            if ($returned === $total) {
                $new_status = PluginLagapenakLoan::STATUS_RETURNED;   // all closed
            } elseif ($pending === 0) {
                $new_status = PluginLagapenakLoan::STATUS_DELIVERED;   // none pending, some delivered
            } else {
                $new_status = PluginLagapenakLoan::STATUS_PENDING;     // any pending → overall pending
            }
        }

        $DB->update('glpi_plugin_lagapenak_loans', [
            'status'   => $new_status,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $loans_id]);
    }

    static function getItemName($itemtype, $items_id) {
        if (!class_exists($itemtype)) {
            return 'ID: ' . $items_id;
        }
        $obj = new $itemtype();
        if ($obj->getFromDB($items_id)) {
            return $obj->getName();
        }
        return 'ID: ' . $items_id;
    }
}
