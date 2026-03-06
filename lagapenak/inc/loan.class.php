<?php

class PluginLagapenakLoan extends CommonDBTM {

    static $rightname = 'plugin_lagapenak_loan';

    const STATUS_PENDING     = 1;  // Pendiente   — reserva creada, aún no entregada
    const STATUS_IN_PROGRESS = 5;  // En curso    — algunos activos entregados, otros pendientes
    const STATUS_DELIVERED   = 2;  // Entregado   — todos los activos entregados
    const STATUS_RETURNED    = 3;  // Devuelto    — todos los activos devueltos
    const STATUS_CANCELLED   = 4;  // Cancelado

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
        $menu['links']['lists']  = '/front/savedsearch.php?action=search&itemtype=PluginLagapenakLoan';
        $menu['links']['<i class="fas fa-calendar-alt"></i>'] = '/plugins/lagapenak/front/calendar.php';
        return $menu;
    }

    static function getFormURL($full = true) {
        return Plugin::getWebDir('lagapenak', true, $full) . '/front/loan.form.php';
    }

    static function canView() {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate() {
        return Session::haveRight(self::$rightname, CREATE);
    }

    static function canSupervise() {
        return Session::haveRight(self::$rightname, UPDATE);
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
            'field_5' => 'Sortzailearen izena',
        ];
    }

    static function getStatusName($status) {
        $statuses = [
            self::STATUS_PENDING     => 'Pendiente',
            self::STATUS_IN_PROGRESS => 'En curso',
            self::STATUS_DELIVERED   => 'Entregado',
            self::STATUS_RETURNED    => 'Devuelto',
            self::STATUS_CANCELLED   => 'Cancelado',
        ];
        return $statuses[$status] ?? 'Desconocido';
    }

    static function getStatusBadge($status) {
        $classes = [
            self::STATUS_PENDING     => 'bg-warning text-dark',
            self::STATUS_IN_PROGRESS => 'bg-info text-dark',
            self::STATUS_DELIVERED   => 'bg-primary',
            self::STATUS_RETURNED    => 'bg-success',
            self::STATUS_CANCELLED   => 'bg-danger',
        ];
        $class = $classes[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . '">' . self::getStatusName($status) . '</span>';
    }

    // ── Native GLPI search ───────────────────────────────────────────────────

    function rawSearchOptions() {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => 'Nombre / Referencia',
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => 'ID',
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 3,
            'table'         => $this->getTable(),
            'field'         => 'status',
            'name'          => 'Estado',
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'        => 4,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id',
            'name'      => 'Solicitante',
            'datatype'  => 'dropdown',
        ];

        $tab[] = [
            'id'        => 5,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id_destinatario',
            'name'      => 'Destinatario',
            'datatype'  => 'dropdown',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'fecha_inicio',
            'name'     => 'Fecha inicio',
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'fecha_fin',
            'name'     => 'Fecha fin',
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'observaciones',
            'name'     => 'Observaciones',
            'datatype' => 'text',
        ];

        $labels = self::getFieldLabels();
        foreach ([9 => 'field_1', 10 => 'field_2', 11 => 'field_3', 12 => 'field_4', 13 => 'field_5'] as $id => $col) {
            $tab[] = [
                'id'       => $id,
                'table'    => $this->getTable(),
                'field'    => $col,
                'name'     => $labels[$col],
                'datatype' => 'string',
            ];
        }

        $t = $this->getTable();
        $tab[] = [
            'id'            => 21,
            'table'         => $this->getTable(),
            'field'         => '_albaran',
            'name'          => 'Albarán',
            'computation'   => "IF(`{$t}`.`has_albaran` = 1, `{$t}`.`id`, NULL)",
            'datatype'      => 'specific',
            'nosort'        => true,
            'massiveaction' => false,
        ];

        // Activos: subquery returns "Tipo~Nombre||Tipo~Nombre" → LIKE search works on real names
        $tab[] = [
            'id'            => 20,
            'table'         => $this->getTable(),
            'field'         => '_activos',
            'name'          => 'Activos',
            'computation'   => '(SELECT GROUP_CONCAT(
                                     CONCAT(li.itemtype, \'~\',
                                         COALESCE(
                                             CASE li.itemtype
                                                 WHEN \'Computer\'        THEN (SELECT c.name  FROM `glpi_computers`        c  WHERE c.id  = li.items_id)
                                                 WHEN \'Monitor\'         THEN (SELECT m.name  FROM `glpi_monitors`         m  WHERE m.id  = li.items_id)
                                                 WHEN \'Peripheral\'      THEN (SELECT p.name  FROM `glpi_peripherals`      p  WHERE p.id  = li.items_id)
                                                 WHEN \'Phone\'           THEN (SELECT ph.name FROM `glpi_phones`           ph WHERE ph.id = li.items_id)
                                                 WHEN \'Printer\'         THEN (SELECT pr.name FROM `glpi_printers`         pr WHERE pr.id = li.items_id)
                                                 WHEN \'NetworkEquipment\' THEN (SELECT ne.name FROM `glpi_networkequipments` ne WHERE ne.id = li.items_id)
                                             END, \'?\')
                                     )
                                     ORDER BY li.id SEPARATOR \'||\')
                                 FROM `glpi_plugin_lagapenak_loanitems` li
                                 WHERE li.loans_id = `' . $this->getTable() . '`.`id`)',
            'datatype'      => 'specific',
            'massiveaction' => false,
            'nosort'        => true,
        ];

        return $tab;
    }

    static function getSpecificValueToDisplay($field, $values, array $options = []) {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            return self::getStatusBadge($values[$field] ?? 0);
        }

        if ($field === '_albaran') {
            $loan_id = (int) ($values[$field] ?? 0);
            if ($loan_id > 0) {
                $url = Plugin::getWebDir('lagapenak', true) . '/front/albaran.php?id=' . $loan_id;
                return '<a href="' . $url . '" target="_blank" title="Ver albarán firmado">'
                     . '<i class="fas fa-check-circle text-success fa-lg"></i></a>';
            }
            return '';
        }

        if ($field === '_activos') {
            $raw = $values[$field] ?? '';
            if (empty($raw)) {
                return '<span class="text-muted">—</span>';
            }
            $parts  = explode('||', $raw);
            $labels = [];
            foreach ($parts as $part) {
                [$itemtype, $name] = array_pad(explode('~', $part, 2), 2, '?');
                $labels[] = PluginLagapenakLoanItem::getTypeIcon($itemtype) . ' ' . htmlspecialchars($name);
            }
            return implode('<br>', $labels);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
        if ($field === 'status') {
            $options['display'] = false;
            return Dropdown::showFromArray($name, [
                self::STATUS_PENDING     => 'Pendiente',
                self::STATUS_IN_PROGRESS => 'En curso',
                self::STATUS_DELIVERED   => 'Entregado',
                self::STATUS_RETURNED    => 'Devuelto',
                self::STATUS_CANCELLED   => 'Cancelado',
            ], $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    static function getAllLoans($filters = []) {
        global $DB;

        $where = [];

        if (!empty($filters['status'])) {
            $where[] = 'l.`status` = ' . (int) $filters['status'];
        }
        // Date overlap: loan period intersects [desde, hasta]
        if (!empty($filters['fecha_desde'])) {
            $where[] = "l.`fecha_fin` >= '" . $DB->escape($filters['fecha_desde']) . " 00:00:00'";
        }
        if (!empty($filters['fecha_hasta'])) {
            $where[] = "l.`fecha_inicio` <= '" . $DB->escape($filters['fecha_hasta']) . " 23:59:59'";
        }

        $where_sql = empty($where) ? '1=1' : implode(' AND ', $where);

        $loans  = [];
        $result = $DB->query(
            "SELECT l.*,
                    u1.name AS solicitante_name, u1.firstname AS solicitante_firstname,
                    u2.name AS dest_name, u2.firstname AS dest_firstname
             FROM `glpi_plugin_lagapenak_loans` l
             LEFT JOIN `glpi_users` u1 ON u1.id = l.users_id
             LEFT JOIN `glpi_users` u2 ON u2.id = l.users_id_destinatario
             WHERE {$where_sql}
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
        $cur_status = (int) ($loan['status'] ?? self::STATUS_PENDING);
        echo '<div class="d-flex align-items-center gap-2 mt-1">';
        echo self::getStatusBadge($cur_status);
        echo '<small class="text-muted"><i class="fas fa-sync-alt me-1"></i>Automático</small>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        // ── Row 2: Solicitante + Destinatario ───────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Solicitante</label>';
        User::dropdown([
            'name'   => 'users_id',
            'value'  => $loan['users_id'] ?? ($_SESSION['glpiID'] ?? 0),
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
        echo '<label class="form-label fw-bold">Fecha inicio <span class="text-danger">*</span></label>';
        Html::showDateTimeField('fecha_inicio', [
            'value'      => $loan['fecha_inicio'] ?? '',
            'maybeempty' => true,
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Fecha fin <span class="text-danger">*</span></label>';
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

    /**
     * Renders loan fields as read-only plain text (for non-supervisor view of existing loans).
     */
    function renderReadOnly($ID) {
        $loan   = $ID > 0 ? $this->fields : [];
        $labels = self::getFieldLabels();

        $str = function($key) use ($loan) {
            $v = trim($loan[$key] ?? '');
            return empty($v) ? '<span class="text-muted">—</span>' : htmlspecialchars($v);
        };

        $user_name = function($uid) {
            if (empty($uid)) { return '<span class="text-muted">—</span>'; }
            $u = new User();
            return $u->getFromDB((int)$uid) ? htmlspecialchars($u->getName()) : '<span class="text-muted">—</span>';
        };

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-8"><label class="form-label fw-bold">Nombre / Referencia</label>';
        echo '<p class="form-control-plaintext">' . $str('name') . '</p></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">Estado</label>';
        echo '<p class="mt-1">' . self::getStatusBadge((int)($loan['status'] ?? self::STATUS_PENDING)) . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">Solicitante</label>';
        echo '<p class="form-control-plaintext">' . $user_name($loan['users_id'] ?? 0) . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">Destinatario</label>';
        echo '<p class="form-control-plaintext">' . $user_name($loan['users_id_destinatario'] ?? 0) . '</p></div>';
        echo '</div>';

        $fi = Html::convDateTime($loan['fecha_inicio'] ?? '') ?: '<span class="text-muted">—</span>';
        $ff = Html::convDateTime($loan['fecha_fin']    ?? '') ?: '<span class="text-muted">—</span>';
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">Fecha inicio</label>';
        echo '<p class="form-control-plaintext">' . $fi . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">Fecha fin</label>';
        echo '<p class="form-control-plaintext">' . $ff . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . htmlspecialchars($labels['field_1']) . '</label>';
        echo '<p class="form-control-plaintext">' . $str('field_1') . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . htmlspecialchars($labels['field_2']) . '</label>';
        echo '<p class="form-control-plaintext">' . $str('field_2') . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . htmlspecialchars($labels['field_3']) . '</label>';
        echo '<p class="form-control-plaintext">' . $str('field_3') . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . htmlspecialchars($labels['field_4']) . '</label>';
        echo '<p class="form-control-plaintext">' . $str('field_4') . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . htmlspecialchars($labels['field_5']) . '</label>';
        echo '<p class="form-control-plaintext">' . $str('field_5') . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-12"><label class="form-label fw-bold">Observaciones</label>';
        $obs = trim($loan['observaciones'] ?? '');
        echo '<p class="form-control-plaintext">'
            . (empty($obs) ? '<span class="text-muted">—</span>' : nl2br(htmlspecialchars($obs)))
            . '</p></div>';
        echo '</div>';
    }
}
