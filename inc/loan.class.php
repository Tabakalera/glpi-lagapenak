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

    /**
     * Check a plugin right for the current user's active profile.
     * Queries glpi_profilerights directly to avoid stale session data —
     * GLPI's Session::haveRight() filters plugin rights for helpdesk users,
     * and the session is only refreshed on login/profile switch, so profile
     * changes made after login would not be reflected without a DB query.
     *
     * @param string $right_name  e.g. 'plugin_lagapenak_loan', 'plugin_lagapenak_albaran'
     * @param int    $flag        e.g. READ, CREATE, UPDATE
     */
    static function hasPluginRight(string $right_name, int $flag): bool {
        global $DB;
        $profile_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!$profile_id) return false;
        foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $profile_id, 'name' => $right_name]]) as $r) {
            return ((int)$r['rights'] & $flag) > 0;
        }
        return false;
    }

    static function getMenuContent() {
        $menu = [];
        $menu['title'] = 'Lagapenak - Préstamos';
        $menu['page']  = '/plugins/lagapenak/front/loan.php';
        $menu['icon']  = 'fas fa-box-open';
        if (self::hasPluginRight(self::$rightname, CREATE)) {
            $menu['links']['add'] = '/plugins/lagapenak/front/loan.form.php';
        }
        $menu['links']['search'] = '/plugins/lagapenak/front/loan.php';
        $menu['links']['lists']  = '/front/savedsearch.php?action=search&itemtype=PluginLagapenakLoan';
        $menu['links']['<i class="fas fa-calendar-alt"></i>'] = '/plugins/lagapenak/front/calendar.php';
        return $menu;
    }

    static function getFormURL($full = true) {
        return Plugin::getWebDir('lagapenak', true, $full) . '/front/loan.form.php';
    }

    static function canView() {
        return self::hasPluginRight(self::$rightname, READ);
    }

    static function canCreate() {
        return self::hasPluginRight(self::$rightname, CREATE);
    }

    static function canSupervise() {
        return self::hasPluginRight(self::$rightname, UPDATE);
    }

    /**
     * Returns users with UPDATE right on this plugin for the given entity,
     * including parent entities where the profile is recursive.
     */
    static function getSupervisorsForEntity(int $entities_id): array {
        global $DB;
        $ancestors = array_keys(getAncestorsOf('glpi_entities', $entities_id));
        $ancestor_condition = '';
        if (!empty($ancestors)) {
            $ancestor_list      = implode(',', array_map('intval', $ancestors));
            $ancestor_condition = "OR (pu.entities_id IN ({$ancestor_list}) AND pu.is_recursive = 1)";
        }
        $result = $DB->query("
            SELECT DISTINCT pu.users_id, u.name, u.realname, u.firstname
            FROM glpi_profiles_users pu
            JOIN glpi_profilerights pr ON pr.profiles_id = pu.profiles_id
            JOIN glpi_users u ON u.id = pu.users_id
            WHERE pr.name = 'plugin_lagapenak_loan'
              AND (pr.rights & " . UPDATE . ") > 0
              AND u.is_deleted = 0 AND u.is_active = 1
              AND (pu.entities_id = {$entities_id} {$ancestor_condition})
            ORDER BY u.realname, u.firstname, u.name
        ");
        $users = [];
        while ($row = $DB->fetchAssoc($result)) {
            $name = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            if (!$name) $name = $row['name'];
            $users[] = ['id' => (int)$row['users_id'], 'name' => $name];
        }
        return $users;
    }

    function canViewItem() {
        if (!static::canView()) return false;
        if (!self::canSupervise()) {
            // Non-supervisors can only view their own loans
            return !empty($this->fields)
                && (int)($this->fields['users_id'] ?? 0) === (int)($_SESSION['glpiID'] ?? 0);
        }
        return parent::canViewItem();
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
        // Solicitante is always the logged-in user, not user-supplied
        $input['users_id'] = (int)($_SESSION['glpiID'] ?? 0);
        return self::sanitizeDates($input);
    }

    function prepareInputForUpdate($input) {
        // Solicitante cannot be changed after creation
        unset($input['users_id']);
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
            'id'        => 14,
            'table'     => 'glpi_entities',
            'field'     => 'completename',
            'linkfield' => 'entities_id',
            'name'      => 'Entidad',
            'datatype'  => 'dropdown',
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

        // ── Row 1b: Entidad ─────────────────────────────────────────────
        $supervisor_url = Plugin::getWebDir('lagapenak', true) . '/ajax/entity_supervisor.php';
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Entidad <span class="text-danger">*</span></label>';
        Entity::dropdown([
            'name'  => 'entities_id',
            'value' => $loan['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0),
        ]);
        echo '</div>';
        echo '</div>';
        $is_new = ($ID <= 0) ? 'true' : 'false';
        echo '<script>
(function() {
    var supervisorUrl = ' . json_encode($supervisor_url) . ';
    var isNewForm = ' . $is_new . ';

    function updateDestinatario(entities_id) {
        if (!entities_id) return;
        var $sel = $("#dest_select");
        $.getJSON(supervisorUrl, {entities_id: entities_id}, function(data) {
            var users = data.users || [];
            $sel.html(\'<option value="">-----</option>\');
            $.each(users, function(_, u) {
                $sel.append(new Option(u.name, u.id, false, false));
            });
            if (users.length === 1) {
                $sel.val(users[0].id);
            }
        });
    }

    // Standard change + select2:select (GLPI uses Select2 v4 which may not bubble standard change)
    $(document).on("change", "select[name=\'entities_id\']", function() {
        updateDestinatario($(this).val());
    });
    $(document).on("select2:select", "select[name=\'entities_id\']", function() {
        updateDestinatario($(this).val());
    });

    // On new form load, populate with supervisors of the default entity
    if (isNewForm) {
        setTimeout(function() {
            var e = $("[name=\'entities_id\']").val();
            if (e) updateDestinatario(e);
        }, 300);
    }
})();
</script>';

        // ── Row 2: Solicitante + Destinatario ───────────────────────────
        echo '<div class="row g-3 mb-3">';

        $sol_id = (int)($ID > 0 ? ($loan['users_id'] ?? 0) : ($_SESSION['glpiID'] ?? 0));
        $sol_user = new User();
        $sol_name = $sol_user->getFromDB($sol_id) ? htmlspecialchars($sol_user->getName()) : '—';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Solicitante</label>';
        echo '<input type="hidden" name="users_id" value="' . $sol_id . '">';
        echo '<p class="form-control-plaintext mb-0">' . $sol_name . '</p>';
        echo '</div>';

        $dest_entity  = (int)($loan['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $supervisors  = self::getSupervisorsForEntity($dest_entity);
        $current_dest = (int)($loan['users_id_destinatario'] ?? 0);
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">Destinatario <span class="text-danger">*</span></label>';
        echo '<select name="users_id_destinatario" id="dest_select" class="form-select">';
        echo '<option value="">-----</option>';
        foreach ($supervisors as $sup) {
            $sel = ($current_dest === $sup['id']) ? ' selected' : '';
            echo '<option value="' . $sup['id'] . '"' . $sel . '>' . htmlspecialchars($sup['name']) . '</option>';
        }
        echo '</select>';
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

        // ── Campos adicionales (colapsados por defecto si están vacíos) ──
        $has_extra = array_filter(array_map('trim', [
            $loan['field_1'] ?? '', $loan['field_2'] ?? '', $loan['field_3'] ?? '',
            $loan['field_4'] ?? '', $loan['field_5'] ?? '', $loan['observaciones'] ?? '',
        ]));
        $collapse_class = $has_extra ? 'show' : '';
        $chevron_class  = $has_extra ? 'fa-chevron-up' : 'fa-chevron-down';

        echo '<div class="mb-2">';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary"
                      data-bs-toggle="collapse" data-bs-target="#extra-fields"
                      aria-expanded="' . ($has_extra ? 'true' : 'false') . '">';
        echo '<i class="fas ' . $chevron_class . ' me-1" id="extra-fields-icon"></i>Campos adicionales';
        echo '</button>';
        echo '</div>';

        echo '<div class="collapse ' . $collapse_class . '" id="extra-fields">';

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

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . htmlspecialchars($labels['field_5']) . '</label>';
        echo '<input type="text" class="form-control" name="field_5"
                     value="' . htmlspecialchars($loan['field_5'] ?? '') . '">';
        echo '</div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-12">';
        echo '<label class="form-label fw-bold">Observaciones</label>';
        echo '<textarea class="form-control" name="observaciones" rows="3">'
            . htmlspecialchars($loan['observaciones'] ?? '') . '</textarea>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // #extra-fields collapse
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
        $eid = (int)($loan['entities_id'] ?? 0);
        $entity_name = Dropdown::getDropdownName('glpi_entities', $eid);
        echo '<div class="col-md-6"><label class="form-label fw-bold">Entidad</label>';
        // getDropdownName already returns HTML-ready content (uses &#62; for >)
        echo '<p class="form-control-plaintext">' . $entity_name . '</p></div>';
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

    // ── Cron: recordatorio de devolución ─────────────────────────────────────

    public static function cronInfo(string $name): array {
        return ['description' => 'Envía recordatorios de devolución de préstamos próximos a vencer (2 días antes)'];
    }

    /**
     * Runs daily. Sends a reminder email for loans whose fecha_fin is
     * exactly 2 calendar days away (DATEDIFF = 2), so each loan gets
     * exactly one reminder without needing an extra DB flag.
     */
    public static function cronLoanReminder(CronTask $task): int {
        global $DB;

        $active_statuses = implode(',', [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_DELIVERED,
        ]);

        $result = $DB->query(
            "SELECT l.*
             FROM `glpi_plugin_lagapenak_loans` l
             WHERE l.status IN ({$active_statuses})
               AND l.fecha_fin IS NOT NULL
               AND DATEDIFF(l.fecha_fin, NOW()) = 2"
        );

        if (!$result) {
            return 0;
        }

        $count = 0;
        while ($row = $DB->fetchAssoc($result)) {
            plugin_lagapenak_send_loan_reminder($row);
            $task->addVolume(1);
            $count++;
        }

        return $count > 0 ? 1 : 0;
    }
}
