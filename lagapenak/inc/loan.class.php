<?php

class PluginLagapenakLoan extends CommonDBTM {

    static $rightname = 'plugin_lagapenak_loan';

    const STATUS_PENDING   = 1;  // Pendiente  — reserva creada, aún no entregada
    const STATUS_DELIVERED = 2;  // Entregado  — activos entregados al solicitante
    const STATUS_RETURNED  = 3;  // Devuelto   — todos los activos devueltos
    const STATUS_CANCELLED = 4;  // Cancelado

    static function getTypeName($nb = 0) {
        return _n('Préstamo', 'Préstamos', $nb, 'lagapenak');
    }

    static function getMenuName() {
        return 'Lagapenak';
    }

    static function getMenuContent() {
        $menu = [];
        $menu['title'] = 'Lagapenak - Préstamos';
        $menu['page']  = '/plugins/lagapenak/front/loan.php';
        $menu['icon']  = 'fas fa-box-open';
        $menu['links']['add']    = '/plugins/lagapenak/front/loan.form.php';
        $menu['links']['search'] = '/plugins/lagapenak/front/loan.php';
        return $menu;
    }

    static function canView() {
        return true;
    }

    static function canCreate() {
        return true;
    }

    /**
     * Convert empty datetime strings to NULL before insert/update.
     * MySQL DATETIME columns do not accept empty strings.
     */
    private static function sanitizeDates(array $input): array {
        foreach (['fecha_inicio', 'fecha_fin'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                unset($input[$field]); // let DB use column default (NULL)
            }
        }
        return $input;
    }

    function prepareInputForAdd($input) {
        return self::sanitizeDates($input);
    }

    function prepareInputForUpdate($input) {
        return self::sanitizeDates($input);
    }

    /**
     * Labels for the 5 generic fields.
     * Change these defaults here or implement a config page later.
     */
    static function getFieldLabels() {
        return [
            'field_1' => 'Convocatoria',
            'field_2' => 'Proyecto',
            'field_3' => 'Ubicación',
            'field_4' => 'Referencia',
            'field_5' => 'Categoría',
        ];
    }

    static function getStatusName($status) {
        $statuses = [
            self::STATUS_PENDING   => 'Pendiente',
            self::STATUS_DELIVERED => 'Entregado',
            self::STATUS_RETURNED  => 'Devuelto',
            self::STATUS_CANCELLED => 'Cancelado',
        ];
        return $statuses[$status] ?? 'Desconocido';
    }

    static function getStatusBadge($status) {
        $classes = [
            self::STATUS_PENDING   => 'bg-warning text-dark',
            self::STATUS_DELIVERED => 'bg-primary',
            self::STATUS_RETURNED  => 'bg-success',
            self::STATUS_CANCELLED => 'bg-danger',
        ];
        $class = $classes[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . '">' . self::getStatusName($status) . '</span>';
    }

    static function getAllLoans() {
        global $DB;

        $loans  = [];
        $result = $DB->query(
            "SELECT l.*,
                    u1.name AS solicitante_name, u1.firstname AS solicitante_firstname,
                    u2.name AS dest_name, u2.firstname AS dest_firstname
             FROM `glpi_plugin_lagapenak_loans` l
             LEFT JOIN `glpi_users` u1 ON u1.id = l.users_id
             LEFT JOIN `glpi_users` u2 ON u2.id = l.users_id_destinatario
             ORDER BY l.id DESC"
        );
        while ($row = $DB->fetchAssoc($result)) {
            $loans[] = $row;
        }
        return $loans;
    }

    /**
     * Renders only the form fields — NO <form> tag, NO buttons.
     * The caller (loan.form.php) wraps this in its own <form>.
     */
    function renderFields($ID) {
        $loan   = ($ID > 0) ? $this->fields : [];
        $labels = self::getFieldLabels();

        // ── Row 1: Nombre + Estado ──────────────────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-8">';
        echo '<label class="form-label fw-bold">Nombre / Referencia <span class="text-danger">*</span></label>';
        echo '<input type="text" class="form-control" name="name"
                     value="' . htmlspecialchars($loan['name'] ?? '') . '">';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-bold">Estado</label>';
        echo '<select class="form-select" name="status">';
        foreach ([
            self::STATUS_PENDING   => 'Pendiente',
            self::STATUS_DELIVERED => 'Entregado',
            self::STATUS_RETURNED  => 'Devuelto',
            self::STATUS_CANCELLED => 'Cancelado',
        ] as $val => $lbl) {
            $sel = (isset($loan['status']) && $loan['status'] == $val) ? 'selected' : '';
            echo '<option value="' . $val . '" ' . $sel . '>' . $lbl . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div>';

        // ── Row 2: Solicitante + Destinatario ───────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Solicitante</label>';
        User::dropdown([
            'name'   => 'users_id',
            'value'  => $loan['users_id'] ?? 0,
            'entity' => $_SESSION['glpiactive_entity'] ?? 0,
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Destinatario</label>';
        User::dropdown([
            'name'   => 'users_id_destinatario',
            'value'  => $loan['users_id_destinatario'] ?? 0,
            'entity' => $_SESSION['glpiactive_entity'] ?? 0,
        ]);
        echo '</div>';

        echo '</div>';

        // ── Row 3: Fecha inicio + Fecha fin ─────────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Fecha inicio</label>';
        Html::showDateTimeField('fecha_inicio', [
            'value'      => $loan['fecha_inicio'] ?? '',
            'maybeempty' => true,
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Fecha fin</label>';
        Html::showDateTimeField('fecha_fin', [
            'value'      => $loan['fecha_fin'] ?? '',
            'maybeempty' => true,
        ]);
        echo '</div>';

        echo '</div>';

        // ── Rows 4-5: field_1 … field_4 ─────────────────────────────────
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_1']) . '</label>';
        echo '<input type="text" class="form-control" name="field_1"
                     value="' . htmlspecialchars($loan['field_1'] ?? '') . '">';
        echo '</div>';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_2']) . '</label>';
        echo '<input type="text" class="form-control" name="field_2"
                     value="' . htmlspecialchars($loan['field_2'] ?? '') . '">';
        echo '</div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_3']) . '</label>';
        echo '<input type="text" class="form-control" name="field_3"
                     value="' . htmlspecialchars($loan['field_3'] ?? '') . '">';
        echo '</div>';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_4']) . '</label>';
        echo '<input type="text" class="form-control" name="field_4"
                     value="' . htmlspecialchars($loan['field_4'] ?? '') . '">';
        echo '</div>';
        echo '</div>';

        // ── Row 6: field_5 ──────────────────────────────────────────────
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_5']) . '</label>';
        echo '<input type="text" class="form-control" name="field_5"
                     value="' . htmlspecialchars($loan['field_5'] ?? '') . '">';
        echo '</div>';
        echo '</div>';

        // ── Row 7: Observaciones ────────────────────────────────────────
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-12">';
        echo '<label class="form-label fw-bold">Observaciones</label>';
        echo '<textarea class="form-control" name="observaciones" rows="3">'
            . htmlspecialchars($loan['observaciones'] ?? '') . '</textarea>';
        echo '</div>';
        echo '</div>';
    }
}
