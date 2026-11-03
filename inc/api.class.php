<?php

/**
 * Integration API G1 (contract: docs/api/openapi-g1.yaml).
 *
 * Read-only query interface for the loans of the client's entities, in two levels: discovery (light change
 * feed with identification fingerprints) and full detail of any loan of those entities, recognised by the
 * consumer or not. Lagapenak does not know residencies or assignments: the consumer identifies the
 * beneficiary and decides what to associate (G1-ID01).
 * Personal data of the beneficiary (name, email) and the signer's name only with scope loans:read:personal;
 * DNI/NIE only as fingerprint. Never: signatures, documents, signing links, observations, notes, comments,
 * incident texts or reasons. Every request is logged without bodies, with how many loans carried personal data.
 */
class PluginLagapenakApi {

    const API_VERSION   = '1.0';
    const PAGE_DEFAULT  = 50;
    const PAGE_MAX      = 100;
    const UUID_RE       = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    const AUTH_FAILURES = 20;   // failed authentications per IP within AUTH_WINDOW before 429
    const AUTH_WINDOW   = 300;
    const SETTLE_SECONDS = 2;   // changes younger than this are not returned yet (concurrent writes)
    const UUIDS_MAX      = 50;  // loans per detail request by UUID

    const LOAN_STATUS = [
        PluginLagapenakLoan::STATUS_PENDING     => 'pending',
        PluginLagapenakLoan::STATUS_IN_PROGRESS => 'partially_delivered',
        PluginLagapenakLoan::STATUS_DELIVERED   => 'delivered',
        PluginLagapenakLoan::STATUS_RETURNED    => 'returned',
        PluginLagapenakLoan::STATUS_CANCELLED   => 'cancelled',
    ];
    const ITEM_STATUS = [
        PluginLagapenakLoanItem::STATUS_PENDING   => 'pending',
        PluginLagapenakLoanItem::STATUS_DELIVERED => 'delivered',
        PluginLagapenakLoanItem::STATUS_RETURNED  => 'returned',
        PluginLagapenakLoanItem::STATUS_INCIDENT  => 'returned_with_incident',
    ];

    const PROBLEM_TITLES = [
        'invalid_request'           => 'Invalid request',
        'unauthorized'              => 'Unauthorized',
        'forbidden_scope'           => 'Missing scope',
        'ip_not_allowed'            => 'Source IP not allowed',
        'https_required'            => 'HTTPS required',
        'not_found'                 => 'Not found',
        'method_not_allowed'        => 'Method not allowed',
        'loan_deleted'              => 'Loan deleted',
        'rate_limited'              => 'Too many requests',
        'internal_error'            => 'Internal error',
    ];

    private float $start;
    private string $method;
    private string $route;
    private string $query;
    private string $ip;
    private bool $https;
    private string $request_id;
    private ?string $acting_user = null;
    private ?array $client = null;
    private ?string $scope_version = null;   // sent on every authenticated response
    private array $entities = [];
    private string $code = '';
    private int $count = 0;
    private int $personal = 0;   // loans of the response that carried personal data
    private array $config;

    public function __construct() {
        $this->start  = microtime(true);
        $this->config = PluginLagapenakApiClient::getConfig();
        // Values taken from the request are reduced to safe characters before they reach the log or a
        // query: GLPI 10 does not escape values passed to $DB->insert()/update()/request().
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->method = in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER';

        // GLPI 11 does not fill PATH_INFO for legacy scripts: take the route from the URI
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $pos  = strpos($path, '/front/api.php');
        $this->route = $pos === false ? '' : substr($path, $pos + strlen('/front/api.php'));
        $this->route = substr(preg_replace('#[^A-Za-z0-9/._-]#', '?', $this->route), 0, 255);
        $this->query = self::loggableQuery();

        $rid = $this->header('X-Request-Id');
        $this->request_id = ($rid !== null && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $rid))
            ? $rid : bin2hex(random_bytes(8));

        [$this->ip, $this->https] = $this->resolveClientAddress();
    }

    /**
     * Entry point of front/api.php.
     */
    public static function run(): void {
        $api = new self();
        try {
            [$status, $body, $headers] = $api->handle();
        } catch (Throwable $e) {
            plugin_lagapenak_log_error('API ' . $api->request_id . ': ' . get_class($e) . ' ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            [$status, $body, $headers] = $api->problem(500, 'internal_error');
        }
        $api->send($status, $body, $headers);
        $api->log($status);
    }

    private function handle(): array {
        if (!$this->config['enabled']) {
            return $this->problem(404, 'not_found');
        }
        if ($this->recentAuthFailures() >= self::AUTH_FAILURES) {
            return $this->problem(429, 'rate_limited', null, ['Retry-After' => (string) self::AUTH_WINDOW]);
        }
        if ($this->config['require_https'] && !$this->https) {
            return $this->problem(403, 'https_required');
        }

        $auth = (string) $this->header('Authorization');
        $client = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? PluginLagapenakApiClient::authenticate($m[1]) : null;
        if (!$client) {
            return $this->problem(401, 'unauthorized', null, ['WWW-Authenticate' => 'Bearer realm="lagapenak"']);
        }
        $this->client = $client;
        if (!PluginLagapenakApiClient::isIpAllowed($client, $this->ip)) {
            return $this->problem(403, 'ip_not_allowed');
        }
        if ($this->recentClientRequests() >= $this->config['rate_per_minute']) {
            return $this->problem(429, 'rate_limited', null, ['Retry-After' => '60']);
        }
        PluginLagapenakApiClient::touch((int) $client['id']);
        $this->entities = PluginLagapenakApiClient::getEntityIds($client);
        $this->scope_version = PluginLagapenakApiClient::scopeVersion($client, $this->entities);

        $acting = $this->header('X-Acting-User');
        if ($acting !== null && $acting !== '') {
            if (!preg_match(self::UUID_RE, $acting)) {
                return $this->problem(400, 'invalid_request', 'X-Acting-User must be a UUID.');
            }
            $this->acting_user = strtolower($acting);
        }

        $r = $this->route;
        $u = '([0-9A-Za-z-]+)';
        $routes = [
            ['#^/v1/meta$#', ['GET' => 'getMeta'], null],
            ['#^/v1/loan-events$#', ['GET' => 'listEvents'], 'loans:read'],
            ['#^/v1/loans$#', ['GET' => 'listLoans'], 'loans:read'],
            ["#^/v1/loans/$u$#", ['GET' => 'getLoan'], 'loans:read'],
        ];
        foreach ($routes as [$pattern, $methods, $scope]) {
            if (!preg_match($pattern, $r, $args)) {
                continue;
            }
            if (!isset($methods[$this->method])) {
                return $this->problem(405, 'method_not_allowed', null, ['Allow' => implode(', ', array_keys($methods))]);
            }
            if ($scope !== null && !in_array($scope, PluginLagapenakApiClient::getScopes($client), true)) {
                return $this->problem(403, 'forbidden_scope', "Scope '$scope' is required.");
            }
            array_shift($args);
            return $this->{$methods[$this->method]}(...$args);
        }
        return $this->problem(404, 'not_found');
    }

    // ── Operations ──────────────────────────────────────────────────────────

    private function getMeta(): array {
        $keys = [];
        $active = null;
        foreach (PluginLagapenakBeneficiary::getUsableKeys() as $k) {
            $keys[] = ['kid' => $k['kid'], 'state' => $k['state'], 'retire_at' => self::iso($k['retire_at'] ?? null)];
            if ($k['state'] === 'active') {
                $active = $k['kid'];
            }
        }
        return $this->ok([
            'api_version'    => self::API_VERSION,
            'plugin_version' => PLUGIN_LAGAPENAK_VERSION,
            'glpi_version'   => GLPI_VERSION,
            'server_time'    => self::iso(date('Y-m-d H:i:s')),
            'client'         => [
                'name'                   => $this->client['name'],
                'scopes'                 => PluginLagapenakApiClient::getScopes($this->client),
                'expires_at'             => self::iso($this->client['expires_at']),
                'token_rotation_pending' => (bool) $this->client['using_previous_token'],
                'scope_changed_at'       => self::iso($this->client['scope_mod']),
                'scope_version'          => $this->scope_version,
            ],
            'identification' => [
                'algorithm'       => PluginLagapenakBeneficiary::ALGORITHM,
                'normalization'   => PluginLagapenakBeneficiary::NORMALIZATION,
                'active_kid'      => $active,
                'keys'            => $keys,
                'keys_changed_at' => self::iso(PluginLagapenakBeneficiary::getKeysChangedAt()),
            ],
            'sync'           => [
                'from'           => $this->config['sync_from'],
                'settle_seconds' => self::SETTLE_SECONDS,
            ],
            'limits'         => [
                'page_size_max'       => self::PAGE_MAX,
                'uuids_max'           => self::UUIDS_MAX,
                'requests_per_minute' => $this->config['rate_per_minute'],
            ],
        ]);
    }

    /**
     * Level 1 (discovery): what changed since the cursor, with identification fingerprints and the minimum
     * needed to decide whether the detail is needed. Loans of the authorised entities within the sync
     * window, deletions and moves out of the authorised entities, in one ordered stream.
     */
    private function listEvents(): array {
        if (!isset($_GET['since'])) {
            return $this->problem(400, 'invalid_request', 'since is required.');
        }
        $cursor = self::parseCursor((string) $_GET['since']);
        $limit  = $this->limit();
        if ($cursor === null || $limit === null) {
            return $this->problem(400, 'invalid_request', 'Invalid since or limit.');
        }
        [$since, $kind, $after] = $cursor;
        $until = $this->q(self::settled());
        $ts    = $this->q($since);
        $rows  = [];

        if ($this->entities) {
            $in   = implode(',', $this->entities);
            $from = $this->q($this->config['sync_from'] . ' 00:00:00');
            // Loans: kind 0
            $after_loan = $kind === 0 ? "(t.changed_at > $ts OR (t.changed_at = $ts AND t.id > $after))" : "t.changed_at > $ts";
            foreach ($this->select(
                'SELECT * FROM (SELECT ' . self::LOAN_COLUMNS . ', ' . $this->loanChangedAt() . " AS changed_at
                   FROM `glpi_plugin_lagapenak_loans` l
                   WHERE l.`entities_id` IN ($in) AND (" . self::syncWindow($from) . ")
                 ) t
                 WHERE $after_loan AND t.changed_at <= $until
                 ORDER BY t.changed_at, t.id LIMIT " . ($limit + 1)
            ) as $l) {
                $rows[] = ['k' => 0, 'id' => (int) $l['id'], 'ts' => $l['changed_at'], 'loan' => $l];
            }
            // Deletions and moves out of the client's entities: kind 1
            $after_event = $kind === 0 ? "e.`date` >= $ts" : "(e.`date` > $ts OR (e.`date` = $ts AND e.`id` > $after))";
            foreach ($this->select(
                "SELECT e.* FROM `glpi_plugin_lagapenak_loanevents` e
                 WHERE ((e.`event` = 'deleted' AND e.`entities_id_from` IN ($in))
                     OR (e.`event` = 'entity_changed' AND e.`entities_id_from` IN ($in) AND e.`entities_id_to` NOT IN ($in)))
                   AND $after_event AND e.`date` <= $until
                 ORDER BY e.`date`, e.`id` LIMIT " . ($limit + 1)
            ) as $e) {
                $rows[] = ['k' => 1, 'id' => (int) $e['id'], 'ts' => $e['date'], 'event' => $e];
            }
        }
        usort($rows, function ($a, $b) {
            return [$a['ts'], $a['k'], $a['id']] <=> [$b['ts'], $b['k'], $b['id']];
        });
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $loans = array_column(array_filter($rows, function ($r) { return $r['k'] === 0; }), 'loan');
        $ctx   = $this->loanContext($loans);
        $data  = [];
        foreach ($rows as $r) {
            if ($r['k'] === 0) {
                $l = $r['loan'];
                $data[] = [
                    'event'           => 'upsert',
                    'loan_uuid'       => $l['uuid'],
                    'number'          => (int) $l['id'],
                    'changed_at'      => self::iso($l['changed_at']),
                    'period'          => ['start' => self::iso($l['fecha_inicio']), 'end' => self::iso($l['fecha_fin'])],
                    'material_status' => $ctx['material'][(int) $l['id']],
                    'beneficiary'     => $ctx['beneficiary'][(int) $l['id']],
                ];
            } else {
                $e = $r['event'];
                $data[] = [
                    'event'                       => $e['event'] === PluginLagapenakLoanEvent::DELETED ? 'deleted' : 'unavailable',
                    'loan_uuid'                   => $e['loan_uuid'],
                    'number'                      => (int) $e['loans_id'],
                    'changed_at'                  => self::iso($e['date']),
                    'material_status_at_deletion' => $e['event'] === PluginLagapenakLoanEvent::DELETED ? $e['material_status'] : null,
                ];
            }
        }
        $last = end($rows);
        $this->count = count($data);
        return $this->ok([
            'data'       => $data,
            'next_since' => $last ? self::cursor($last['ts'], $last['k'], $last['id']) : self::cursor($since, $kind, $after),
            // A change means the client's view changed (entities or scopes): reconcile (contract x-sync)
            'scope_version' => $this->scope_version,
            'has_more'   => $more,
        ]);
    }

    /**
     * Level 2 (detail) by number (a person looking for a loan, any date) or by a list of UUIDs (loans
     * selected after discovery, or reconciliation). Never a full listing.
     */
    private function listLoans(): array {
        $by_number = isset($_GET['number']);
        $by_uuids  = isset($_GET['uuids']);
        if ($by_number === $by_uuids) {
            return $this->problem(400, 'invalid_request', 'Use exactly one of: number, uuids.');
        }
        if ($by_number) {
            $number = (string) $_GET['number'];
            if (!ctype_digit($number) || (int) $number < 1) {
                return $this->problem(400, 'invalid_request', 'number must be a positive integer.');
            }
            if ($this->acting_user === null) {
                return $this->problem(400, 'invalid_request', 'X-Acting-User is required to search by number.');
            }
            return $this->ok(['data' => $this->serializeLoans($this->loadLoans('l.`id` = ' . (int) $number))]);
        }

        $uuids = array_values(array_unique(array_map('strtolower', explode(',', (string) $_GET['uuids']))));
        if (!$uuids || count($uuids) > self::UUIDS_MAX) {
            return $this->problem(400, 'invalid_request', 'uuids: between 1 and ' . self::UUIDS_MAX . ' UUIDs.');
        }
        foreach ($uuids as $u) {
            if (!preg_match(self::UUID_RE, $u)) {
                return $this->problem(400, 'invalid_request', 'uuids must be UUIDs separated by commas.');
            }
        }
        $rows = $this->loadLoans('l.`uuid` IN (' . implode(',', array_map([$this, 'q'], $uuids)) . ')');
        $found = array_column($rows, 'uuid');
        $not_found = [];
        foreach (array_diff($uuids, $found) as $u) {
            $deletion = PluginLagapenakLoanEvent::findDeletion($u, $this->entities);
            $not_found[] = [
                'uuid'                        => $u,
                'reason'                      => $deletion ? 'deleted' : 'not_available',
                'material_status_at_deletion' => $deletion['material_status'] ?? null,
            ];
        }
        return $this->ok(['data' => $this->serializeLoans($rows), 'not_found' => $not_found]);
    }

    private function getLoan(string $uuid): array {
        if (!preg_match(self::UUID_RE, $uuid)) {
            return $this->problem(404, 'not_found');
        }
        $uuid = strtolower($uuid);
        $rows = $this->loadLoans('l.`uuid` = ' . $this->q($uuid));
        if ($rows) {
            return $this->ok($this->serializeLoans($rows)[0]);
        }
        return PluginLagapenakLoanEvent::findDeletion($uuid, $this->entities)
            ? $this->problem(410, 'loan_deleted')
            : $this->problem(404, 'not_found');
    }

    // ── Data ────────────────────────────────────────────────────────────────

    // Only what the contract exposes and what is needed to compute it. The DNI/NIE is read to compute its
    // fingerprint and is never returned; the signature, the signing token, observations, notes and the
    // cancellation reason are not even read.
    const LOAN_COLUMNS = "l.`id`, l.`uuid`, l.`name`, l.`entities_id`, l.`users_id`, l.`users_id_destinatario`, l.`tickets_id`,
        l.`status`, l.`fecha_inicio`, l.`fecha_fin`,
        l.`field_1`, l.`field_2`, l.`field_3`, l.`field_4`, l.`field_5`,
        l.`date_request_status`, l.`requested_date_end`, l.`date_request_apply_items`,
        l.`signature_date`, l.`signature_name`, l.`albaran_project`,
        (l.`signature_data` IS NOT NULL AND l.`signature_data` <> '') AS is_signed,
        (l.`sign_token` IS NOT NULL AND l.`sign_token` <> '') AS has_sign_link, l.`sign_token_expires`,
        l.`cancel_date`, l.`cancel_users_id`,
        l.`beneficiary_name`, l.`beneficiary_email`, l.`beneficiary_dni`, l.`beneficiary_validated`,
        l.`date_creation`, l.`date_mod`";

    // Generic fields of the loan form (PluginLagapenakLoan::getFieldLabels()) under stable names
    const DETAIL_FIELDS = ['field_1' => 'call', 'field_2' => 'project', 'field_3' => 'location', 'field_4' => 'area', 'field_5' => 'department'];

    /**
     * Loans that belong to the change feed: anything that was or is active from the sync date on, so that a
     * loan started before and still running (or returned late) is not lost.
     */
    private static function syncWindow(string $from): string {
        return "l.`fecha_fin` IS NULL OR l.`fecha_fin` >= $from OR l.`fecha_inicio` >= $from
            OR l.`date_creation` >= $from OR l.`date_mod` >= $from OR l.`cancel_date` >= $from
            OR l.`status` IN (" . PluginLagapenakLoan::STATUS_PENDING . ',' . PluginLagapenakLoan::STATUS_IN_PROGRESS . ','
            . PluginLagapenakLoan::STATUS_DELIVERED . ")
            OR EXISTS (SELECT 1 FROM `glpi_plugin_lagapenak_loanitems` iw WHERE iw.`loans_id` = l.`id`
                       AND (iw.`status` = " . PluginLagapenakLoanItem::STATUS_DELIVERED . " OR iw.`date_mod` >= $from))";
    }

    /**
     * Version of a loan as the API sees it: the loan, its lines, the signature, the cancellation, its
     * comments, and also the client's authorisations and the integration keys (a change there sends every
     * loan again).
     */
    private function loanChangedAt(): string {
        $min = "'1970-01-02 00:00:00'";
        $scope = $this->q((string) ($this->client['scope_mod'] ?: '1970-01-02 00:00:00'));
        $keys  = $this->q((string) (PluginLagapenakBeneficiary::getKeysChangedAt() ?: '1970-01-02 00:00:00'));
        return "GREATEST(
            COALESCE(l.`date_mod`, l.`date_creation`, $min),
            COALESCE(l.`signature_date`, $min),
            COALESCE(l.`cancel_date`, $min),
            COALESCE((SELECT MAX(i.`date_mod`) FROM `glpi_plugin_lagapenak_loanitems` i WHERE i.`loans_id` = l.`id`), $min),
            COALESCE((SELECT MAX(c.`date_creation`) FROM `glpi_plugin_lagapenak_loancomments` c WHERE c.`loans_id` = l.`id`), $min),
            $scope, $keys)";
    }

    /**
     * Loans matching a condition, restricted to the client's entities (no date window: the detail of an
     * older loan stays available).
     */
    private function loadLoans(string $where): array {
        if (!$this->entities) {
            return [];
        }
        return $this->select(
            'SELECT ' . self::LOAN_COLUMNS . ', ' . $this->loanChangedAt() . ' AS changed_at
             FROM `glpi_plugin_lagapenak_loans` l
             WHERE (' . $where . ') AND l.`entities_id` IN (' . implode(',', $this->entities) . ')
             ORDER BY l.`id`'
        );
    }

    /**
     * Asset lines, material status and beneficiary block of a set of loans, in three queries.
     */
    private function loanContext(array $rows): array {
        $ctx = ['items' => [], 'material' => [], 'beneficiary' => []];
        if (!$rows) {
            return $ctx;
        }
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        foreach ($this->select(
            'SELECT `id`, `loans_id`, `itemtype`, `items_id`, `status`, `date_checkout`, `date_checkin`, `date_mod`
             FROM `glpi_plugin_lagapenak_loanitems` WHERE `loans_id` IN (' . implode(',', $ids) . ') ORDER BY `id`'
        ) as $it) {
            $ctx['items'][(int) $it['loans_id']][] = $it;
        }
        $requesters = array_unique(array_map(function ($r) { return (int) $r['users_id']; }, $rows));
        $emails = [];
        foreach ($this->select(
            'SELECT `users_id`, `email` FROM `glpi_useremails` WHERE `users_id` IN (' . implode(',', $requesters) . ')'
        ) as $e) {
            [$st, $norm] = PluginLagapenakBeneficiary::normalizeEmail($e['email']);
            if ($norm !== null) {
                $emails[(int) $e['users_id']][] = $norm;
            }
        }
        $keys = PluginLagapenakBeneficiary::getUsableKeys();
        foreach ($rows as $l) {
            $id = (int) $l['id'];
            $ctx['material'][$id] = PluginLagapenakLoan::materialStatus(
                (int) $l['status'], array_column($ctx['items'][$id] ?? [], 'status')
            );
            $ctx['beneficiary'][$id] = PluginLagapenakBeneficiary::describe($l, $emails[(int) $l['users_id']] ?? [], $keys);
        }
        return $ctx;
    }

    private function serializeLoans(array $rows): array {
        $ctx      = $this->loanContext($rows);
        $assets   = $this->assetLabels(array_merge([], ...array_values($ctx['items'])));
        $people   = $this->userNames(array_merge(
            array_column($rows, 'users_id'), array_column($rows, 'users_id_destinatario'), array_column($rows, 'cancel_users_id')
        ));
        $entities = $this->entityNames(array_column($rows, 'entities_id'));
        $comments = $this->commentStats(array_map('intval', array_column($rows, 'id')));
        $personal = in_array(PluginLagapenakApiClient::SCOPE_PERSONAL, PluginLagapenakApiClient::getScopes($this->client), true);
        $person   = function ($id) use ($people) {
            $id = (int) $id;
            return $id > 0 ? ['id' => $id, 'name' => $people[$id] ?? null] : null;
        };
        $out = [];
        foreach ($rows as $l) {
            $id      = (int) $l['id'];
            $list    = [];
            $summary = ['total' => 0, 'pending' => 0, 'delivered' => 0, 'returned' => 0, 'returned_with_incident' => 0];
            foreach ($ctx['items'][$id] ?? [] as $it) {
                $status = self::ITEM_STATUS[(int) $it['status']] ?? 'pending';
                $summary['total']++;
                $summary[$status]++;
                $asset  = $assets[$it['itemtype']][(int) $it['items_id']] ?? null;
                $list[] = [
                    'line_id'          => (int) $it['id'],
                    'type'             => $it['itemtype'],
                    'asset_id'         => (int) $it['items_id'],
                    'name'             => self::text($asset['name'] ?? null) ?? ($it['itemtype'] . ' #' . (int) $it['items_id']),
                    'inventory_number' => self::text($asset['otherserial'] ?? null),
                    'serial'           => self::text($asset['serial'] ?? null),
                    'status'           => $status,
                    'period'           => ['start' => self::iso($it['date_checkout']), 'end' => self::iso($it['date_checkin'])],
                    'changed_at'       => self::iso($it['date_mod']),
                ];
            }
            $request = null;
            $dr = (int) $l['date_request_status'];
            if ($dr === PluginLagapenakLoan::DATE_REQUEST_PENDING || $dr === PluginLagapenakLoan::DATE_REQUEST_REJECTED) {
                $request = [
                    'status'           => $dr === PluginLagapenakLoan::DATE_REQUEST_PENDING ? 'pending' : 'rejected',
                    'requested_end'    => self::iso($l['requested_date_end']),
                    'applies_to_items' => (int) $l['date_request_apply_items'] === 1,
                ];
            }
            $signed    = (bool) $l['is_signed'];
            $cancelled = (int) $l['status'] === PluginLagapenakLoan::STATUS_CANCELLED;
            $link_open = !$signed && !$cancelled && (bool) $l['has_sign_link']
                && ($l['sign_token_expires'] === null || (string) $l['sign_token_expires'] > date('Y-m-d H:i:s'));
            $details = [];
            foreach (self::DETAIL_FIELDS as $col => $key) {
                $details[$key] = self::text($l[$col]);
            }
            $beneficiary = $ctx['beneficiary'][$id];
            // Personal data: only with the personal scope, for any loan of the authorised entities
            $beneficiary['contact'] = $personal
                ? ['name' => self::text($l['beneficiary_name']), 'email' => self::text($l['beneficiary_email'])]
                : null;
            if ($personal) {
                $this->personal++;
            }
            $out[] = [
                'uuid'              => $l['uuid'],
                'number'            => $id,
                'reference'         => self::text($l['name']),
                'glpi_url'          => plugin_lagapenak_web_dir(true) . '/front/loan.form.php?id=' . $id,
                'entity'            => ['id' => (int) $l['entities_id'], 'name' => $entities[(int) $l['entities_id']] ?? null],
                'ticket_id'         => (int) $l['tickets_id'] > 0 ? (int) $l['tickets_id'] : null,
                'status'            => self::LOAN_STATUS[(int) $l['status']] ?? 'pending',
                'material_status'   => $ctx['material'][$id],
                'created_at'        => self::iso($l['date_creation']),
                'period'            => ['start' => self::iso($l['fecha_inicio']), 'end' => self::iso($l['fecha_fin'])],
                'requester'         => $person($l['users_id']),
                'recipient'         => $person($l['users_id_destinatario']),
                'details'           => $details,
                'extension_request' => $request,
                'delivery_note'     => [
                    'signed'       => $signed,
                    'signed_at'    => $signed ? self::iso($l['signature_date']) : null,
                    'signer_name'  => $signed && $personal ? self::text($l['signature_name']) : null,
                    'project'      => self::text($l['albaran_project']),
                    'signing_link' => ['pending' => $link_open, 'expires_at' => $link_open ? self::iso($l['sign_token_expires']) : null],
                ],
                'cancellation'      => $cancelled ? [
                    'cancelled_at' => self::iso($l['cancel_date']),
                    'cancelled_by' => $person($l['cancel_users_id']),
                ] : null,
                'beneficiary'       => $beneficiary,
                'items_summary'     => $summary,
                'items'             => $list,
                'comments'          => $comments[$id] ?? ['count' => 0, 'last_at' => null],
                'changed_at'        => self::iso($l['changed_at']),
            ];
        }
        $this->count = count($out);
        return $out;
    }

    /**
     * Display name of GLPI users (staff: requester, recipient, who cancelled): "Surname Firstname" or login.
     */
    private function userNames(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if ($ids) {
            foreach ($this->select('SELECT `id`, `name`, `realname`, `firstname` FROM `glpi_users` WHERE `id` IN (' . implode(',', $ids) . ')') as $u) {
                $out[(int) $u['id']] = self::text(trim($u['realname'] . ' ' . $u['firstname'])) ?? self::text($u['name']);
            }
        }
        return $out;
    }

    private function entityNames(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        if ($ids) {
            foreach ($this->select('SELECT `id`, `completename` FROM `glpi_entities` WHERE `id` IN (' . implode(',', $ids) . ')') as $e) {
                $out[(int) $e['id']] = self::text($e['completename']);
            }
        }
        return $out;
    }

    /** Number of comments and date of the last one, never their text. */
    private function commentStats(array $ids): array {
        $out = [];
        if ($ids) {
            foreach ($this->select(
                'SELECT `loans_id`, COUNT(*) AS n, MAX(`date_creation`) AS last_at FROM `glpi_plugin_lagapenak_loancomments`
                 WHERE `loans_id` IN (' . implode(',', $ids) . ') GROUP BY `loans_id`'
            ) as $c) {
                $out[(int) $c['loans_id']] = ['count' => (int) $c['n'], 'last_at' => self::iso($c['last_at'])];
            }
        }
        return $out;
    }

    /**
     * Stored text as typed (GLPI 10 keeps it HTML-encoded), without control characters, at most $max
     * characters; null when empty.
     */
    static function text($value, int $max = 255): ?string {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', PluginLagapenakBeneficiary::raw((string) $value)) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /**
     * Name and inventory number of the loaned assets, only for the loanable asset types.
     */
    private function assetLabels(array $items): array {
        global $DB;
        $by_type = [];
        foreach ($items as $it) {
            if (array_key_exists($it['itemtype'], PluginLagapenakLoanItem::getAssetTypes())) {
                $by_type[$it['itemtype']][(int) $it['items_id']] = (int) $it['items_id'];
            }
        }
        $out = [];
        foreach ($by_type as $itemtype => $ids) {
            $table  = getTableForItemType($itemtype);
            $fields = ['id', 'name'];
            foreach (['otherserial', 'serial'] as $f) {
                if ($DB->fieldExists($table, $f)) {
                    $fields[] = $f;
                }
            }
            foreach ($DB->request(['SELECT' => $fields, 'FROM' => $table, 'WHERE' => ['id' => array_values($ids)]]) as $a) {
                $out[$itemtype][(int) $a['id']] = $a;
            }
        }
        return $out;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Query string for the log: only the contract's parameters, and only values with their expected format
     * (anything else a client sends, personal data included, is logged as "?").
     */
    private static function loggableQuery(): string {
        $formats = [
            'since'  => '/^[A-Za-z0-9_:.+-]{1,60}$/',
            'limit'  => '/^\d{1,4}$/',
            'number' => '/^\d{1,10}$/',
            'uuids'  => '/^[0-9a-fA-F,-]{1,2000}$/',
        ];
        $parts = [];
        foreach ($_GET as $name => $value) {
            $name = (string) $name;
            if (!isset($formats[$name])) {
                $parts[] = preg_match('/^[a-z_]{1,20}$/', $name) ? $name . '=?' : '?';
            } elseif (is_string($value) && preg_match($formats[$name], $value)) {
                $parts[] = $name . '=' . $value;
            } else {
                $parts[] = $name . '=?';
            }
        }
        return substr(implode('&', $parts), 0, 255);
    }

    private function select(string $sql): array {
        global $DB;
        $res = $DB->doQuery($sql);
        if ($res === false) {
            throw new RuntimeException('SQL error: ' . $DB->error());
        }
        $rows = [];
        while ($row = $DB->fetchAssoc($res)) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function q(string $value): string {
        global $DB;
        return "'" . $DB->escape($value) . "'";
    }

    private function limit(): ?int {
        if (!isset($_GET['limit'])) {
            return self::PAGE_DEFAULT;
        }
        $l = (string) $_GET['limit'];
        return (ctype_digit($l) && (int) $l >= 1 && (int) $l <= self::PAGE_MAX) ? (int) $l : null;
    }

    static function iso(?string $value): ?string {
        if ($value === null || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        try {
            return (new DateTime($value))->format(DATE_ATOM);
        } catch (Exception $e) {
            return null;
        }
    }

    private static function settled(): string {
        return date('Y-m-d H:i:s', time() - self::SETTLE_SECONDS);
    }

    private static function cursor(string $ts, int $kind, int $id): string {
        return self::b64enc('c2|' . $ts . '|' . $kind . '|' . $id);
    }

    /**
     * Opaque cursor from a previous page, or an RFC 3339 date to start from.
     *
     * @return array{0:string,1:int,2:int}|null [local timestamp, kind of the last row, its id]
     */
    static function parseCursor(string $value): ?array {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            try {
                $d = new DateTime($value);
            } catch (Exception $e) {
                return null;
            }
            $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $ts = $d->format('Y-m-d H:i:s');
            return [$ts < '1970-01-02 00:00:00' ? '1970-01-02 00:00:00' : $ts, 0, 0];
        }
        $decoded = self::b64dec($value);
        if ($decoded !== null && preg_match('/^c2\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([01])\|(\d+)$/', $decoded, $m)) {
            return [$m[1], (int) $m[2], (int) $m[3]];
        }
        return null;
    }

    private static function b64enc(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64dec(string $s): ?string {
        if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/', $s)) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        return $d === false ? null : $d;
    }

    private function header(string $name): ?string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return (string) $v;
                }
            }
        }
        return null;
    }

    /**
     * Client IP and HTTPS flag. Forwarded headers are only trusted from the configured proxies.
     *
     * @return array{0:string,1:bool}
     */
    private function resolveClientAddress(): array {
        $remote = (string) filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $https  =(!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $trusted = function (string $ip): bool {
            foreach ($this->config['trusted_proxies'] as $rule) {
                if (PluginLagapenakApiClient::ipMatches($ip, $rule)) {
                    return true;
                }
            }
            return false;
        };
        if (!$trusted($remote)) {
            return [$remote, $https];
        }
        $ip  = $remote;
        $xff = array_reverse(PluginLagapenakApiClient::parseList(str_replace(',', ' ', (string) $this->header('X-Forwarded-For'))));
        foreach ($xff as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break; // malformed hop: keep the last valid address
            }
            $ip = $hop;
            if (!$trusted($hop)) {
                break;
            }
        }
        $proto = strtolower(trim(explode(',', (string) $this->header('X-Forwarded-Proto'))[0]));
        if ($proto !== '') {
            $https = $proto === 'https';
        }
        return [$ip, $https];
    }

    private function recentAuthFailures(): int {
        return countElementsInTable('glpi_plugin_lagapenak_apilogs', [
            'ip'     => $this->ip,
            'status' => 401,
            'date'   => ['>=', date('Y-m-d H:i:s', time() - self::AUTH_WINDOW)],
        ]);
    }

    private function recentClientRequests(): int {
        return countElementsInTable('glpi_plugin_lagapenak_apilogs', [
            'apiclients_id' => (int) $this->client['id'],
            'date'          => ['>=', date('Y-m-d H:i:s', time() - 60)],
        ]);
    }

    private function ok(array $body, int $status = 200): array {
        return [$status, $body, []];
    }

    private function problem(int $status, string $code, ?string $detail = null, array $headers = [], array $errors = []): array {
        $this->code = $code;
        $body = [
            'type'       => 'urn:lagapenak:problem:' . $code,
            'title'      => self::PROBLEM_TITLES[$code] ?? $code,
            'status'     => $status,
            'code'       => $code,
            'request_id' => $this->request_id,
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }
        if ($errors) {
            $body['errors'] = $errors;
        }
        return [$status, $body, $headers + ['Content-Type' => 'application/problem+json']];
    }

    private function send(int $status, array $body, array $headers): void {
        http_response_code($status);
        $headers += [
            'Content-Type'           => 'application/json',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-Id'           => $this->request_id,
        ];
        if ($this->scope_version !== null) {
            $headers += ['X-Lagapenak-Scope-Version' => $this->scope_version];
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function log(int $status): void {
        global $DB;
        try {
            $DB->insert('glpi_plugin_lagapenak_apilogs', [
                'apiclients_id' => (int) ($this->client['id'] ?? 0),
                'date'          => date('Y-m-d H:i:s'),
                'method'        => substr($this->method, 0, 8),
                'route'         => substr($this->route, 0, 255),
                'query'         => $this->query,
                'status'        => $status,
                'code'          => $this->code,
                'result_count'  => $this->count,
                'personal_count' => $this->personal,
                'ip'            => substr($this->ip, 0, 45),
                'request_id'    => $this->request_id,
                'acting_user'   => $this->acting_user,
                'duration_ms'   => (int) round((microtime(true) - $this->start) * 1000),
            ]);
        } catch (Throwable $e) {
            plugin_lagapenak_log_error('API log: ' . $e->getMessage());
        }
    }
}
