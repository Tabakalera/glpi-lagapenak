<?php

class PluginLagapenakLoan extends CommonDBTM {

    static $rightname = 'plugin_lagapenak_loan';


    const STATUS_PENDING     = 1;  // Pendiente   — reserva creada, aún no entregada
    const STATUS_IN_PROGRESS = 5;  // En curso    — algunos activos entregados, otros pendientes
    const STATUS_DELIVERED   = 2;  // Entregado   — todos los activos entregados
    const STATUS_RETURNED    = 3;  // Devuelto    — todos los activos devueltos
    const STATUS_CANCELLED   = 4;  // Cancelado

    const DATE_REQUEST_NONE     = 0;  // Sin solicitud pendiente
    const DATE_REQUEST_PENDING  = 1;  // Solicitud enviada, pendiente de aprobación
    const DATE_REQUEST_REJECTED = 2;  // Solicitud rechazada por el supervisor

    static function getTypeName($nb = 0) {
        return _n('Loan', 'Loans', $nb, 'lagapenak');
    }

    static function getMenuName() {
        return 'Lagapenak';
    }

    static function getMenuContent() {
        $menu = [];
        $menu['title'] = __('Lagapenak - Loans', 'lagapenak');
        $menu['page']  = '/plugins/lagapenak/front/loan.php';
        $menu['icon']  = 'fas fa-box-open';
        $menu['links']['add']    = '/plugins/lagapenak/front/loan.form.php';
        $menu['links']['search'] = '/plugins/lagapenak/front/loan.php';
        $menu['links']['lists']  = '/front/savedsearch.php?action=search&itemtype=PluginLagapenakLoan';
        $menu['links']['<i class="fas fa-calendar-alt"></i>'] = '/plugins/lagapenak/front/calendar.php';
        return $menu;
    }

    static function getFormURL($full = true) {
        return plugin_lagapenak_web_dir($full) . '/front/loan.form.php';
    }

    /**
     * DB-based permission check — works for both central and helpdesk (Self-Service) users.
     * Session::haveRight() filters out plugin rights for helpdesk profiles, so we query
     * glpi_profilerights directly to get the current value regardless of session state.
     */
    static function hasPluginRight(string $right_name, int $flag): bool {
        global $DB;
        $profile_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if (!$profile_id) return false;
        foreach ($DB->request([
            'SELECT' => ['rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['profiles_id' => $profile_id, 'name' => $right_name],
        ]) as $r) {
            return ((int)$r['rights'] & $flag) > 0;
        }
        return false;
    }

    static function canView(): bool {
        return self::hasPluginRight(self::$rightname, READ);
    }

    static function canCreate(): bool {
        return self::hasPluginRight(self::$rightname, CREATE);
    }

    static function canSupervise() {
        return self::hasPluginRight(self::$rightname, UPDATE);
    }

    /**
     * Supervisor of this particular loan: READ + UPDATE rights AND access to the loan's entity.
     * canSupervise() alone ignores entities, so a supervisor of entity A could manage loans of B.
     */
    static function canManageLoan(PluginLagapenakLoan $loan): bool {
        return self::canView()
            && self::canSupervise()
            && isset($loan->fields['entities_id'])
            && Session::haveAccessToEntity((int) $loan->fields['entities_id']);
    }

    static function isRequester(PluginLagapenakLoan $loan): bool {
        return (int) ($loan->fields['users_id'] ?? 0) === (int) ($_SESSION['glpiID'] ?? -1);
    }

    /**
     * A request nobody has acted on yet: still pending, no asset delivered/returned/with incident,
     * no signing link issued, no date request and no comment from anyone else.
     * Only such a request can be withdrawn (deleted) by its own requester.
     */
    static function isUntouchedRequest(PluginLagapenakLoan $loan): bool {
        global $DB;
        $id = (int) ($loan->fields['id'] ?? 0);
        if ((int) ($loan->fields['status'] ?? 0) !== self::STATUS_PENDING
            || !empty($loan->fields['sign_token'])
            || (int) ($loan->fields['date_request_status'] ?? 0) !== self::DATE_REQUEST_NONE
            || !empty($loan->fields['requested_date_end'])
        ) {
            return false;
        }
        $touched_items = countElementsInTable('glpi_plugin_lagapenak_loanitems', [
            'loans_id' => $id,
            'NOT'      => ['status' => PluginLagapenakLoanItem::STATUS_PENDING],
        ]);
        $others_comments = countElementsInTable('glpi_plugin_lagapenak_loancomments', [
            'loans_id' => $id,
            'NOT'      => ['users_id' => (int) ($loan->fields['users_id'] ?? 0)],
        ]);
        return $touched_items === 0 && $others_comments === 0;
    }

    /**
     * Single deletion rule for every route (loan form, GLPI massive actions, API…):
     * - a signed loan is never deleted;
     * - the requester may withdraw their own untouched request (READ + CREATE, entity access), no PURGE needed;
     * - anybody else needs READ + UPDATE + PURGE and access to the loan's entity.
     */
    static function canDeleteLoan(PluginLagapenakLoan $loan): bool {
        if (empty($loan->fields['id']) || self::isSigned($loan->fields)) {
            return false;
        }
        if (self::isRequester($loan)) {
            return self::canView() && self::canCreate()
                && Session::haveAccessToEntity((int) $loan->fields['entities_id'])
                && self::isUntouchedRequest($loan);
        }
        return self::hasPluginRight(self::$rightname, PURGE) && self::canManageLoan($loan);
    }

    /**
     * Server-side checks for creating a loan (or moving one to another entity / recipient).
     * Returns an error message, or '' when allowed.
     */
    static function checkEntityAndRecipient(int $entities_id, int $users_id_destinatario): string {
        if (!Session::haveAccessToEntity($entities_id)) {
            return __('You do not have access to this entity.', 'lagapenak');
        }
        $supervisor_ids = array_column(self::getSupervisorsForEntity($entities_id), 'id');
        if (!in_array($users_id_destinatario, $supervisor_ids, true)) {
            return __('The recipient must be a supervisor of the selected entity.', 'lagapenak');
        }
        return '';
    }

    /** Fields written only by the signing workflow (albaran.php / sign.php), never by the loan form. */
    static function getSignatureFields(): array {
        return [
            'signature_data', 'signature_name', 'signature_date', 'has_albaran',
            'albaran_passport', 'albaran_project', 'sign_token', 'sign_token_expires',
        ];
    }

    static function isSigned(array $fields): bool {
        return !empty($fields['signature_data']);
    }

    /**
     * Email addresses linked to a loan, as [lower-cased address => role label]: every GLPI address of
     * the requester and of the recipient (supervisor), and the beneficiary's address.
     * Non-supervisors may only send the delivery note to these.
     */
    static function getLinkedEmails(PluginLagapenakLoan $loan): array {
        $linked = [];
        $add = function (string $email, string $role) use (&$linked) {
            $email = strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($linked[$email])) {
                $linked[$email] = $role;
            }
        };
        foreach (UserEmail::getAllForUser((int) ($loan->fields['users_id'] ?? 0)) as $email) {
            $add($email, __('Requester', 'lagapenak'));
        }
        $add((string) ($loan->fields['beneficiary_email'] ?? ''), __('Beneficiary', 'lagapenak'));
        foreach (UserEmail::getAllForUser((int) ($loan->fields['users_id_destinatario'] ?? 0)) as $email) {
            $add($email, __('Recipient', 'lagapenak'));
        }
        return $linked;
    }

    /**
     * Address of the person who signs: the beneficiary when the loan names one, otherwise the requester
     * (who then picks the material up). '' when the signer has no known address.
     */
    static function getSignerEmail(PluginLagapenakLoan $loan): string {
        if (trim($loan->fields['beneficiary_name'] ?? '') !== '' || trim($loan->fields['beneficiary_email'] ?? '') !== '') {
            $email = strtolower(trim($loan->fields['beneficiary_email'] ?? ''));
        } else {
            $email = strtolower(trim((string) UserEmail::getDefaultForUser((int) ($loan->fields['users_id'] ?? 0))));
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Audit trail of every delivery-note document sent by hand (GLPI event log: Administration > Logs).
     * Records who sent what to which address and, for an address not linked to the loan, the reason.
     */
    static function logDocumentSend(int $loans_id, string $what, string $to, string $reason = ''): void {
        $message = sprintf('%s: %s → %s', $_SESSION['glpiname'] ?? '?', $what, $to !== '' ? $to : __('shown on screen', 'lagapenak'));
        if ($reason !== '') {
            $message .= ' · ' . sprintf(__('exceptional recipient, reason: %s', 'lagapenak'), $reason);
        }
        \Glpi\Event::log($loans_id, 'PluginLagapenakLoan', 3, 'lagapenak', $message);
    }

    /** The canvas sends a PNG data URL; anything else (or an oversized payload) is rejected. */
    static function isValidSignatureImage(string $data): bool {
        return strlen($data) <= 2 * 1024 * 1024
            && preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $data) === 1;
    }

    /**
     * Record the signature of a delivery note, once.
     * The UPDATE only matches a loan that is not signed yet, so a repeated submit, a second tab or an
     * old signing link can never replace a closed signature. Any outstanding signing link is voided.
     *
     * @return bool true when this call signed the loan
     */
    static function recordSignature(int $loans_id, string $data, string $name, string $passport, string $project): bool {
        global $DB;
        $DB->update('glpi_plugin_lagapenak_loans', [
            'signature_data'     => $data,
            'signature_name'     => $name,
            'signature_date'     => date('Y-m-d H:i:s'),
            'has_albaran'        => 1,
            'albaran_passport'   => $passport,
            'albaran_project'    => $project,
            'sign_token'         => null,
            'sign_token_expires' => null,
        ], [
            'id' => $loans_id,
            'OR' => [['signature_data' => null], ['signature_data' => '']],
        ]);
        return $DB->affectedRows() === 1;
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
        $result = $DB->doQuery("
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

    function canViewItem(): bool {
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

        // Fields managed by the plugin workflows (signature, albarán, date requests) are never
        // taken from the creation form, otherwise a loan could be created already "signed".
        foreach (array_merge(self::getSignatureFields(), [
            'requested_date_end', 'date_request_status', 'date_request_apply_items', 'date_request_notes',
        ]) as $field) {
            unset($input[$field]);
        }
        // Only supervisors may choose the initial status
        if (!self::canSupervise()) {
            $input['status'] = self::STATUS_PENDING;
        }
        // Public identifier for the integration API, never taken from the form
        $input['uuid'] = self::newUuid();
        return self::sanitizeDates($input);
    }

    /**
     * Random UUID (version 4), lower case.
     */
    static function newUuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * GLPI asks these before deleting through its own routes (massive actions, API): same rule as the form.
     * The table has no is_deleted column, so "delete" is always a purge.
     */
    function canDeleteItem(): bool {
        return self::canDeleteLoan($this);
    }

    function canPurgeItem(): bool {
        return self::canDeleteLoan($this);
    }

    /**
     * Last check before any deletion, whatever the route. A signed delivery note is evidence of the
     * handover: nobody can delete it. The loan itself keeps working (deliveries, returns, extensions,
     * comments). 1.7.0 adds cancellation and kept signed versions.
     */
    function pre_deleteItem() {
        if (self::isSigned($this->fields)) {
            Session::addMessageAfterRedirect(
                __('A loan with a signed delivery note cannot be deleted: it is kept as the record of the handover.', 'lagapenak'),
                false,
                ERROR
            );
            return false;
        }
        if (!self::canDeleteLoan($this)) {
            Session::addMessageAfterRedirect(
                __('You cannot delete this loan. A request can only be withdrawn by its requester while nobody has acted on it.', 'lagapenak'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    /**
     * GLPI massive "update" applies a field to many loans without the loan form's checks (recipient,
     * entity, date conflicts). Only fields with no such rules may be changed in bulk; the fields printed
     * on the delivery note only while it is not signed.
     */
    function canMassiveAction($action, $field, $value) {
        if (in_array($field, ['observaciones', 'field_3', 'field_4', 'field_5'], true)) {
            return parent::canMassiveAction($action, $field, $value);
        }
        if (in_array($field, ['field_1', 'field_2'], true)) {
            return !self::isSigned($this->fields) && parent::canMassiveAction($action, $field, $value);
        }
        return false;
    }

    function getForbiddenStandardMassiveAction() {
        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'add_transfer_list'; // moving loans between entities goes through the loan form
        return $forbidden;
    }

    function prepareInputForUpdate($input) {
        // Solicitante and the public identifier cannot be changed after creation
        unset($input['users_id'], $input['uuid']);
        // A signature is only ever written by the signing workflow, which updates the row directly.
        // Without this, saving the loan form could rewrite or back-date a signed delivery note.
        foreach (self::getSignatureFields() as $field) {
            unset($input[$field]);
        }
        // Entity and recipient rules hold for every update route, not only the loan form
        $new_entity = (int) ($input['entities_id'] ?? $this->fields['entities_id'] ?? 0);
        $new_dest   = (int) ($input['users_id_destinatario'] ?? $this->fields['users_id_destinatario'] ?? 0);
        if ($new_entity !== (int) ($this->fields['entities_id'] ?? 0)
            || $new_dest !== (int) ($this->fields['users_id_destinatario'] ?? 0)
        ) {
            $error = self::checkEntityAndRecipient($new_entity, $new_dest);
            if ($error !== '') {
                Session::addMessageAfterRedirect($error, false, ERROR);
                return false;
            }
        }
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
            'field_4' => 'Arloa',
            'field_5' => 'Departamentua',
        ];
    }

    static function getStatusName($status) {
        $statuses = [
            self::STATUS_PENDING     => __('Pending', 'lagapenak'),
            self::STATUS_IN_PROGRESS => __('In progress', 'lagapenak'),
            self::STATUS_DELIVERED   => __('Delivered', 'lagapenak'),
            self::STATUS_RETURNED    => __('Returned', 'lagapenak'),
            self::STATUS_CANCELLED   => __('Cancelled', 'lagapenak'),
        ];
        return $statuses[$status] ?? __('Unknown', 'lagapenak');
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
            'name'          => __('Name / Reference', 'lagapenak'),
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
            'name'          => __('Status', 'lagapenak'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'        => 14,
            'table'     => 'glpi_entities',
            'field'     => 'completename',
            'linkfield' => 'entities_id',
            'name'      => __('Entity', 'lagapenak'),
            'datatype'  => 'dropdown',
            'massiveaction' => false, // checked again by canMassiveAction()
        ];

        $tab[] = [
            'id'        => 4,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id',
            'name'      => __('Requester', 'lagapenak'),
            'datatype'  => 'dropdown',
            'massiveaction' => false, // checked again by canMassiveAction()
        ];

        $tab[] = [
            'id'        => 5,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id_destinatario',
            'name'      => __('Recipient', 'lagapenak'),
            'datatype'  => 'dropdown',
            'massiveaction' => false, // checked again by canMassiveAction()
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'fecha_inicio',
            'name'     => __('Start date', 'lagapenak'),
            'datatype' => 'datetime',
            'massiveaction' => false, // checked again by canMassiveAction()
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'fecha_fin',
            'name'     => __('End date', 'lagapenak'),
            'datatype' => 'datetime',
            'massiveaction' => false, // checked again by canMassiveAction()
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'observaciones',
            'name'     => __('Notes', 'lagapenak'),
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

        $tab[] = [
            'id'       => 22,
            'table'    => $this->getTable(),
            'field'    => 'beneficiary_name',
            'name'     => __('Full name', 'lagapenak'),
            'datatype' => 'string',
            'massiveaction' => false, // beneficiary data: loan form only
        ];

        $tab[] = [
            'id'       => 23,
            'table'    => $this->getTable(),
            'field'    => 'beneficiary_dni',
            'name'     => __('Passport / ID', 'lagapenak'),
            'datatype' => 'string',
            'massiveaction' => false, // beneficiary data: loan form only
        ];

        $tab[] = [
            'id'       => 24,
            'table'    => $this->getTable(),
            'field'    => 'beneficiary_email',
            'name'     => __('Email address', 'lagapenak'),
            'datatype' => 'string',
            'massiveaction' => false, // beneficiary data: loan form only
        ];

        $t = $this->getTable();
        $tab[] = [
            'id'            => 21,
            'table'         => $this->getTable(),
            'field'         => '_albaran',
            'name'          => __('Delivery note', 'lagapenak'),
            'computation'   => "IF(`{$t}`.`has_albaran` = 1, `{$t}`.`id`, NULL)",
            'datatype'      => 'specific',
            'nosort'        => true,
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 25,
            'table'         => $this->getTable(),
            'field'         => '_signed',
            'name'          => __('Signed', 'lagapenak'),
            'computation'   => "IF(`{$t}`.`signature_data` IS NULL OR `{$t}`.`signature_data` = '', 0, 1)",
            'datatype'      => 'bool',
            'nosort'        => true,
            'massiveaction' => false,
        ];

        // Assets: subquery returns "Tipo~Nombre||Tipo~Nombre" → LIKE search works on real names
        $tab[] = [
            'id'            => 20,
            'table'         => $this->getTable(),
            'field'         => '_activos',
            'name'          => __('Assets', 'lagapenak'),
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
                $url = plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $loan_id;
                return '<a href="' . $url . '" target="_blank" title="' . __('View signed delivery note', 'lagapenak') . '">'
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
                self::STATUS_PENDING     => __('Pending', 'lagapenak'),
                self::STATUS_IN_PROGRESS => __('In progress', 'lagapenak'),
                self::STATUS_DELIVERED   => __('Delivered', 'lagapenak'),
                self::STATUS_RETURNED    => __('Returned', 'lagapenak'),
                self::STATUS_CANCELLED   => __('Cancelled', 'lagapenak'),
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

        // ── Row 1: Name + Status ───────────────────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-8">';
        echo '<label class="form-label fw-bold">' . __('Name / Reference', 'lagapenak') . ' <span class="text-danger">*</span></label>';
        echo '<input type="text" class="form-control" name="name"
                     value="' . htmlspecialchars($loan['name'] ?? '') . '">';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-bold">' . __('Status', 'lagapenak') . '</label>';
        $cur_status = (int) ($loan['status'] ?? self::STATUS_PENDING);
        echo '<div class="d-flex align-items-center gap-2 mt-1">';
        echo self::getStatusBadge($cur_status);
        echo '<small class="text-muted"><i class="fas fa-sync-alt me-1"></i>' . __('Automatic', 'lagapenak') . '</small>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        // ── Row 1b: Entity ──────────────────────────────────────────────
        $supervisor_url = plugin_lagapenak_web_dir() . '/ajax/entity_supervisor.php';
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . __('Entity', 'lagapenak') . ' <span class="text-danger">*</span></label>';
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

        // ── Row 2: Requester + Recipient ────────────────────────────────
        echo '<div class="row g-3 mb-3">';

        $sol_id = (int)($ID > 0 ? ($loan['users_id'] ?? 0) : ($_SESSION['glpiID'] ?? 0));
        $sol_user = new User();
        $sol_name = $sol_user->getFromDB($sol_id) ? htmlspecialchars($sol_user->getName()) : '—';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . __('Requester', 'lagapenak') . '</label>';
        echo '<input type="hidden" name="users_id" value="' . $sol_id . '">';
        echo '<p class="form-control-plaintext mb-0">' . $sol_name . '</p>';
        echo '</div>';

        $dest_entity  = (int)($loan['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $supervisors  = self::getSupervisorsForEntity($dest_entity);
        $current_dest = (int)($loan['users_id_destinatario'] ?? 0);
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . __('Recipient', 'lagapenak') . ' <span class="text-danger">*</span></label>';
        echo '<select name="users_id_destinatario" id="dest_select" class="form-select">';
        echo '<option value="">-----</option>';
        foreach ($supervisors as $sup) {
            $sel = ($current_dest === $sup['id']) ? ' selected' : '';
            echo '<option value="' . $sup['id'] . '"' . $sel . '>' . htmlspecialchars($sup['name']) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div>';

        // ── Row 3: Start date + End date ────────────────────────────────
        echo '<div class="row g-3 mb-3">';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . __('Start date', 'lagapenak') . ' <span class="text-danger">*</span></label>';
        Html::showDateTimeField('fecha_inicio', [
            'value'      => $loan['fecha_inicio'] ?? '',
            'maybeempty' => true,
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-bold">' . __('End date', 'lagapenak') . ' <span class="text-danger">*</span></label>';
        Html::showDateTimeField('fecha_fin', [
            'value'      => $loan['fecha_fin'] ?? '',
            'maybeempty' => true,
        ]);
        echo '</div>';

        echo '</div>';

        // ── Beneficiario / Persona que recoge ──────────────────────────────
        $ben_name  = $loan['beneficiary_name']  ?? '';
        $ben_email = $loan['beneficiary_email'] ?? '';
        $ben_dni   = $loan['beneficiary_dni']   ?? '';

        if ($ID <= 0) {
            // New form: pre-fill from requester's GLPI data
            $req = new User();
            if ($req->getFromDB((int)($_SESSION['glpiID'] ?? 0))) {
                if (empty($ben_name)) {
                    $rn = trim(($req->fields['realname'] ?? '') . ' ' . ($req->fields['firstname'] ?? ''));
                    $ben_name = $rn ?: ($req->fields['name'] ?? '');
                }
                if (empty($ben_email)) {
                    $ben_email = UserEmail::getDefaultForUser($req->getID()) ?: '';
                }
            }
        }

        echo '<div class="row g-3 mb-1 mt-1">';
        echo '<div class="col-12">';
        echo '<p class="mb-0 fw-bold text-secondary" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.6px;">';
        echo '<i class="fas fa-user me-1"></i>' . __('Beneficiary', 'lagapenak') . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-5">';
        echo '<label class="form-label fw-bold">' . __('Full name', 'lagapenak') . '</label>';
        echo '<input type="text" class="form-control" name="beneficiary_name" value="' . htmlspecialchars($ben_name) . '">';
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-bold">' . __('Email address', 'lagapenak') . '</label>';
        echo '<input type="email" class="form-control" name="beneficiary_email" value="' . htmlspecialchars($ben_email) . '">';
        echo '</div>';
        echo '<div class="col-md-3">';
        echo '<label class="form-label fw-bold">' . __('Passport / ID', 'lagapenak') . '</label>';
        echo '<input type="text" class="form-control" name="beneficiary_dni" value="' . htmlspecialchars($ben_dni) . '">';
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
        echo '<i class="fas ' . $chevron_class . ' me-1" id="extra-fields-icon"></i>' . __('Additional fields', 'lagapenak');
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
        echo '<label class="form-label fw-bold">' . __('Notes', 'lagapenak') . '</label>';
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
        echo '<div class="col-md-8"><label class="form-label fw-bold">' . __('Name / Reference', 'lagapenak') . '</label>';
        echo '<p class="form-control-plaintext">' . $str('name') . '</p></div>';
        echo '<div class="col-md-4"><label class="form-label fw-bold">' . __('Status', 'lagapenak') . '</label>';
        echo '<p class="mt-1">' . self::getStatusBadge((int)($loan['status'] ?? self::STATUS_PENDING)) . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        $eid = (int)($loan['entities_id'] ?? 0);
        $entity_name = Dropdown::getDropdownName('glpi_entities', $eid);
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . __('Entity', 'lagapenak') . '</label>';
        // getDropdownName already returns HTML-ready content (uses &#62; for >)
        echo '<p class="form-control-plaintext">' . $entity_name . '</p></div>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . __('Requester', 'lagapenak') . '</label>';
        echo '<p class="form-control-plaintext">' . $user_name($loan['users_id'] ?? 0) . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . __('Recipient', 'lagapenak') . '</label>';
        echo '<p class="form-control-plaintext">' . $user_name($loan['users_id_destinatario'] ?? 0) . '</p></div>';
        echo '</div>';

        $fi = Html::convDateTime($loan['fecha_inicio'] ?? '') ?: '<span class="text-muted">—</span>';
        $ff = Html::convDateTime($loan['fecha_fin']    ?? '') ?: '<span class="text-muted">—</span>';
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . __('Start date', 'lagapenak') . '</label>';
        echo '<p class="form-control-plaintext">' . $fi . '</p></div>';
        echo '<div class="col-md-6"><label class="form-label fw-bold">' . __('End date', 'lagapenak') . '</label>';
        echo '<p class="form-control-plaintext">' . $ff . '</p></div>';
        echo '</div>';

        // ── Beneficiary ──────────────────────────────────────────────────────
        $ben_name_ro  = trim($loan['beneficiary_name']  ?? '');
        $ben_email_ro = trim($loan['beneficiary_email'] ?? '');
        $ben_dni_ro   = trim($loan['beneficiary_dni']   ?? '');
        if ($ben_name_ro || $ben_email_ro || $ben_dni_ro) {
            echo '<div class="row g-3 mb-1">';
            echo '<div class="col-12"><h6 class="fw-bold mb-0"><i class="fas fa-user me-2"></i>' . __('Beneficiary', 'lagapenak') . '</h6></div>';
            echo '</div>';
            echo '<div class="row g-3 mb-3">';
            echo '<div class="col-md-5"><label class="form-label fw-bold">' . __('Full name', 'lagapenak') . '</label>';
            echo '<p class="form-control-plaintext">' . (empty($ben_name_ro) ? '<span class="text-muted">—</span>' : htmlspecialchars($ben_name_ro)) . '</p></div>';
            echo '<div class="col-md-4"><label class="form-label fw-bold">' . __('Email address', 'lagapenak') . '</label>';
            echo '<p class="form-control-plaintext">' . (empty($ben_email_ro) ? '<span class="text-muted">—</span>' : htmlspecialchars($ben_email_ro)) . '</p></div>';
            echo '<div class="col-md-3"><label class="form-label fw-bold">' . __('Passport / ID', 'lagapenak') . '</label>';
            echo '<p class="form-control-plaintext">' . (empty($ben_dni_ro) ? '<span class="text-muted">—</span>' : htmlspecialchars($ben_dni_ro)) . '</p></div>';
            echo '</div>';
        }

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
        echo '<div class="col-md-12"><label class="form-label fw-bold">' . __('Notes', 'lagapenak') . '</label>';
        $obs = trim($loan['observaciones'] ?? '');
        echo '<p class="form-control-plaintext">'
            . (empty($obs) ? '<span class="text-muted">—</span>' : nl2br(htmlspecialchars($obs)))
            . '</p></div>';
        echo '</div>';
    }

    // ── Borrado en cascada ────────────────────────────────────────────────────

    public function cleanDBonPurge() {
        global $DB;
        $DB->delete('glpi_plugin_lagapenak_loanitems',    ['loans_id' => $this->getID()]);
        $DB->delete('glpi_plugin_lagapenak_loancomments', ['loans_id' => $this->getID()]);
        // Links are kept as tombstones so that Kide-Artean learns the loan is gone
        if ($DB->tableExists('glpi_plugin_lagapenak_loanlinks')) {
            PluginLagapenakLoanLink::markLoanDeleted($this->getID());
        }
    }

    // ── Cron: recordatorio de devolución ─────────────────────────────────────

    public static function cronInfo(string $name): array {
        if ($name === 'ApiLogClean') {
            return ['description' => __('Deletes integration API log entries older than the retention period', 'lagapenak')];
        }
        return ['description' => __('Sends return reminders for loans due in 2 days', 'lagapenak')];
    }

    /**
     * Daily. Keeps the integration API log for the configured number of days (365 by default).
     */
    public static function cronApiLogClean(CronTask $task): int {
        global $DB;
        if (!$DB->tableExists('glpi_plugin_lagapenak_apilogs')) {
            return 0;
        }
        $days = PluginLagapenakApiClient::getConfig()['log_retention_days'];
        $DB->delete('glpi_plugin_lagapenak_apilogs', [
            'date' => ['<', date('Y-m-d H:i:s', time() - $days * DAY_TIMESTAMP)],
        ]);
        $task->addVolume($DB->affectedRows());
        return 1;
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

        $result = $DB->doQuery(
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
