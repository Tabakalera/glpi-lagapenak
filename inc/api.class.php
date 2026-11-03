<?php

/**
 * Integration API G1 (contract: docs/api/openapi-g1.yaml).
 *
 * Read-only access to loans in the client's entities and creation/removal of links with Kide-Artean
 * objects. Never exposes loan names, free texts, people, ID numbers, signatures or documents (G1 · D4).
 * Every request is logged without bodies.
 */
class PluginLagapenakApi {

    const API_VERSION   = '1.0';
    const PAGE_DEFAULT  = 50;
    const PAGE_MAX      = 100;
    const UUID_RE       = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
    const AUTH_FAILURES = 20;   // failed authentications per IP within AUTH_WINDOW before 429
    const AUTH_WINDOW   = 300;
    const SETTLE_SECONDS = 2;   // changes younger than this are not returned yet (concurrent writes)

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
        'residency_conflict'        => 'The loan is already linked to another residency',
        'parent_residency_mismatch' => 'The assignment is not under the residency linked to this loan',
        'dependent_links'           => 'The residency link has active assignment links',
        'unsupported_media_type'    => 'Unsupported media type',
        'validation_failed'         => 'Validation failed',
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
    private array $entities = [];
    private string $code = '';
    private int $count = 0;
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
        $this->query = substr(preg_replace('#[^A-Za-z0-9=&_.:%+-]#', '?', (string) ($_SERVER['QUERY_STRING'] ?? '')), 0, 255);

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
            ['#^/v1/loans$#', ['GET' => 'listLoans'], 'loans:read'],
            ["#^/v1/loans/$u$#", ['GET' => 'getLoan'], 'loans:read'],
            ["#^/v1/loans/$u/links/([a-z]+)/$u$#", ['PUT' => 'putLink', 'DELETE' => 'deleteLink'], 'links:write'],
            ['#^/v1/links$#', ['GET' => 'listLinks'], 'links:read'],
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
        return $this->ok([
            'api_version'    => self::API_VERSION,
            'plugin_version' => PLUGIN_LAGAPENAK_VERSION,
            'glpi_version'   => GLPI_VERSION,
            'server_time'    => self::iso(date('Y-m-d H:i:s')),
            'client'         => [
                'name'                   => $this->client['name'],
                'source'                 => $this->client['source'],
                'scopes'                 => PluginLagapenakApiClient::getScopes($this->client),
                'expires_at'             => self::iso($this->client['expires_at']),
                'token_rotation_pending' => (bool) $this->client['using_previous_token'],
            ],
            'limits'         => [
                'page_size_max'       => self::PAGE_MAX,
                'requests_per_minute' => $this->config['rate_per_minute'],
            ],
        ]);
    }

    private function listLoans(): array {
        $modes = array_values(array_filter([
            isset($_GET['number']) ? 'number' : null,
            (isset($_GET['link_type']) || isset($_GET['link_id'])) ? 'link' : null,
            isset($_GET['changed_since']) ? 'changes' : null,
        ]));
        if (count($modes) !== 1) {
            return $this->problem(400, 'invalid_request', 'Use exactly one of: number, link_type+link_id, changed_since.');
        }
        if ($modes[0] === 'number') {
            return $this->searchByNumber();
        }
        if ($modes[0] === 'link') {
            return $this->listByLink();
        }
        return $this->listLoanChanges();
    }

    private function searchByNumber(): array {
        $number = (string) $_GET['number'];
        if (!ctype_digit($number) || (int) $number < 1) {
            return $this->problem(400, 'invalid_request', 'number must be a positive integer.');
        }
        if ($this->acting_user === null) {
            return $this->problem(400, 'invalid_request', 'X-Acting-User is required to search by number.');
        }
        $rows = $this->loadLoans('l.`id` = ' . (int) $number);
        return $this->ok(['data' => $this->serializeLoans($rows)]);
    }

    private function listByLink(): array {
        $type = (string) ($_GET['link_type'] ?? '');
        $id   = (string) ($_GET['link_id'] ?? '');
        if (!in_array($type, PluginLagapenakLoanLink::getObjectTypes(), true) || !preg_match(self::UUID_RE, $id)) {
            return $this->problem(400, 'invalid_request', 'link_type must be residency or assignment and link_id a UUID.');
        }
        $limit = $this->limit();
        if ($limit === null) {
            return $this->problem(400, 'invalid_request', 'limit must be between 1 and ' . self::PAGE_MAX . '.');
        }
        $after = 0;
        if (isset($_GET['page_token'])) {
            $decoded = self::b64dec((string) $_GET['page_token']);
            if ($decoded === null || !preg_match('/^p\|(\d+)$/', $decoded, $m)) {
                return $this->problem(400, 'invalid_request', 'Invalid page_token.');
            }
            $after = (int) $m[1];
        }

        $links = $this->select(
            "SELECT k.*, l.`entities_id` AS loan_entity, l.`id` AS loan_exists
             FROM `glpi_plugin_lagapenak_loanlinks` k
             LEFT JOIN `glpi_plugin_lagapenak_loans` l ON l.`id` = k.`loans_id`
             WHERE k.`source` = " . $this->q($this->client['source']) . "
               AND k.`object_type` = " . $this->q($type) . "
               AND k.`object_id` = " . $this->q(strtolower($id)) . "
               AND k.`state` <> 'removed' AND k.`id` > $after
             ORDER BY k.`id` LIMIT " . ($limit + 1)
        );
        $more  = count($links) > $limit;
        $links = array_slice($links, 0, $limit);

        $visible = [];
        foreach ($links as $k) {
            if ($this->linkState($k) === PluginLagapenakLoanLink::STATE_ACTIVE) {
                $visible[] = (int) $k['loans_id'];
            }
        }
        $loans = [];
        foreach ($visible ? $this->serializeLoans($this->loadLoans('l.`id` IN (' . implode(',', $visible) . ')')) : [] as $loan) {
            $loans[$loan['number']] = $loan;
        }
        $data = [];
        foreach ($links as $k) {
            $data[] = [
                'link' => $this->serializeLink($k),
                'loan' => $loans[(int) $k['loans_id']] ?? null,
            ];
        }
        $this->count = count($data);
        return $this->ok([
            'data'            => $data,
            'next_page_token' => $more ? self::b64enc('p|' . end($links)['id']) : null,
        ]);
    }

    private function listLoanChanges(): array {
        $cursor = self::parseCursor((string) $_GET['changed_since']);
        $limit  = $this->limit();
        if ($cursor === null || $limit === null) {
            return $this->problem(400, 'invalid_request', 'Invalid changed_since or limit.');
        }
        [$since, $after] = $cursor;
        $rows = [];
        if ($this->entities) {
            $src  = $this->q($this->client['source']);
            $rows = $this->select(
                'SELECT * FROM (SELECT ' . self::LOAN_COLUMNS . ', ' . $this->loanChangedAt() . " AS changed_at
                   FROM `glpi_plugin_lagapenak_loans` l
                   WHERE l.`entities_id` IN (" . implode(',', $this->entities) . ")
                     AND EXISTS (SELECT 1 FROM `glpi_plugin_lagapenak_loanlinks` k
                                 WHERE k.`loans_id` = l.`id` AND k.`source` = $src AND k.`state` = 'active')
                 ) t
                 WHERE (t.changed_at > " . $this->q($since) . ' OR (t.changed_at = ' . $this->q($since) . " AND t.id > $after))
                   AND t.changed_at <= " . $this->q(self::settled()) . '
                 ORDER BY t.changed_at, t.id LIMIT ' . ($limit + 1)
            );
        }
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);
        return $this->ok([
            'data'       => $this->serializeLoans($rows),
            'next_since' => $last ? self::cursor($last['changed_at'], (int) $last['id']) : self::cursor($since, $after),
            'has_more'   => $more,
        ]);
    }

    private function getLoan(string $uuid): array {
        $loan = $this->findLoan($uuid);
        if (!is_array($loan)) {
            return $loan === 410 ? $this->problem(410, 'loan_deleted') : $this->problem(404, 'not_found');
        }
        return $this->ok($this->serializeLoans([$loan])[0]);
    }

    private function putLink(string $uuid, string $type, string $object_id): array {
        if ($this->acting_user === null) {
            return $this->problem(400, 'invalid_request', 'X-Acting-User is required.');
        }
        if (!in_array($type, PluginLagapenakLoanLink::getObjectTypes(), true)) {
            return $this->problem(404, 'not_found');
        }
        if (!preg_match(self::UUID_RE, $object_id)) {
            return $this->problem(400, 'invalid_request', 'object_id must be a UUID.');
        }
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) !== '' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            return $this->problem(415, 'unsupported_media_type');
        }
        $body = trim($raw) === '' ? [] : json_decode($raw, true);
        if (!is_array($body) || (trim($raw) !== '' && substr(ltrim($raw), 0, 1) !== '{')) {
            return $this->problem(422, 'validation_failed', 'The body must be a JSON object.');
        }
        $errors = [];
        foreach (array_diff(array_keys($body), ['label', 'parent_residency_id']) as $field) {
            $errors[] = ['field' => (string) $field, 'message' => 'Unknown field.'];
        }
        $label = $body['label'] ?? '';
        if (!is_string($label) || strlen($label) > 40 || !preg_match('#^[A-Za-z0-9._/-]*$#', $label)) {
            $errors[] = ['field' => 'label', 'message' => 'At most 40 characters: letters, digits, . _ / -'];
        }
        $parent = $body['parent_residency_id'] ?? null;
        if ($type === PluginLagapenakLoanLink::TYPE_ASSIGNMENT) {
            if (!is_string($parent) || !preg_match(self::UUID_RE, $parent)) {
                $errors[] = ['field' => 'parent_residency_id', 'message' => 'Required UUID for an assignment link.'];
            }
        } elseif ($parent !== null) {
            $errors[] = ['field' => 'parent_residency_id', 'message' => 'Not allowed for a residency link.'];
        }
        if ($errors) {
            return $this->problem(422, 'validation_failed', null, [], $errors);
        }

        $loan = $this->findLoan($uuid);
        if (!is_array($loan)) {
            return $loan === 410 ? $this->problem(410, 'loan_deleted') : $this->problem(404, 'not_found');
        }
        [$status, $result] = PluginLagapenakLoanLink::put(
            $loan, $this->client, $type, strtolower($object_id),
            is_string($parent) ? strtolower($parent) : null, (string) $label, $this->acting_user
        );
        if (is_string($result)) {
            return $this->problem($status, $result);
        }
        $result['loan_entity'] = $loan['entities_id'];
        $result['loan_exists'] = $loan['id'];
        return $this->ok($this->serializeLink($result), $status);
    }

    private function deleteLink(string $uuid, string $type, string $object_id): array {
        if ($this->acting_user === null) {
            return $this->problem(400, 'invalid_request', 'X-Acting-User is required.');
        }
        if (!preg_match(self::UUID_RE, $uuid) || !in_array($type, PluginLagapenakLoanLink::getObjectTypes(), true)
            || !preg_match(self::UUID_RE, $object_id)
        ) {
            return $this->problem(404, 'not_found');
        }
        $link = PluginLagapenakLoanLink::findByLoanUuid(strtolower($uuid), $this->client['source'], $type, strtolower($object_id));
        if (!$link) {
            return $this->problem(404, 'not_found');
        }
        [$status, $result] = PluginLagapenakLoanLink::remove($link, $this->acting_user);
        if (is_string($result)) {
            return $this->problem($status, $result);
        }
        $loan = $this->select('SELECT `id`, `entities_id` FROM `glpi_plugin_lagapenak_loans` WHERE `id` = ' . (int) $result['loans_id']);
        $result['loan_exists'] = $loan[0]['id'] ?? null;
        $result['loan_entity'] = $loan[0]['entities_id'] ?? null;
        return $this->ok($this->serializeLink($result), $status);
    }

    private function listLinks(): array {
        if (!isset($_GET['changed_since'])) {
            return $this->problem(400, 'invalid_request', 'changed_since is required.');
        }
        $cursor = self::parseCursor((string) $_GET['changed_since']);
        $limit  = $this->limit();
        if ($cursor === null || $limit === null) {
            return $this->problem(400, 'invalid_request', 'Invalid changed_since or limit.');
        }
        [$since, $after] = $cursor;
        // A link "changes" with itself, with its loan (entity moves) and with the client's authorisations
        $scope_mod = $this->q((string) ($this->client['scope_mod'] ?: '1970-01-02 00:00:00'));
        $rows = $this->select(
            "SELECT * FROM (SELECT k.*, l.`entities_id` AS loan_entity, l.`id` AS loan_exists,
                    GREATEST(COALESCE(k.`date_mod`, k.`date_creation`),
                             COALESCE(l.`date_mod`, k.`date_mod`, k.`date_creation`), $scope_mod) AS changed_at
               FROM `glpi_plugin_lagapenak_loanlinks` k
               LEFT JOIN `glpi_plugin_lagapenak_loans` l ON l.`id` = k.`loans_id`
               WHERE k.`source` = " . $this->q($this->client['source']) . "
             ) t
             WHERE (t.changed_at > " . $this->q($since) . ' OR (t.changed_at = ' . $this->q($since) . " AND t.id > $after))
               AND t.changed_at <= " . $this->q(self::settled()) . '
             ORDER BY t.changed_at, t.id LIMIT ' . ($limit + 1)
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = end($rows);
        $data = [];
        foreach ($rows as $k) {
            $data[] = $this->serializeLink($k, $k['changed_at']);
        }
        $this->count = count($data);
        return $this->ok([
            'data'       => $data,
            'next_since' => $last ? self::cursor($last['changed_at'], (int) $last['id']) : self::cursor($since, $after),
            'has_more'   => $more,
        ]);
    }

    // ── Data ────────────────────────────────────────────────────────────────

    // Only what the contract exposes: no name, texts, people, signature image or ID numbers
    const LOAN_COLUMNS = "l.`id`, l.`uuid`, l.`entities_id`, l.`status`, l.`fecha_inicio`, l.`fecha_fin`,
        l.`date_request_status`, l.`requested_date_end`, l.`signature_date`,
        (l.`signature_data` IS NOT NULL AND l.`signature_data` <> '') AS is_signed,
        l.`date_creation`, l.`date_mod`";

    private function loanChangedAt(): string {
        $src = $this->q($this->client['source']);
        $min = "'1970-01-02 00:00:00'";
        return "GREATEST(
            COALESCE(l.`date_mod`, l.`date_creation`, $min),
            COALESCE(l.`signature_date`, $min),
            COALESCE((SELECT MAX(i.`date_mod`) FROM `glpi_plugin_lagapenak_loanitems` i WHERE i.`loans_id` = l.`id`), $min),
            COALESCE((SELECT MAX(k.`date_mod`) FROM `glpi_plugin_lagapenak_loanlinks` k
                      WHERE k.`loans_id` = l.`id` AND k.`source` = $src), $min))";
    }

    /**
     * Loans matching a condition, restricted to the client's entities.
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
     * Loan row in scope, 410 if this client's links show it was purged, 404 otherwise.
     *
     * @return array|int
     */
    private function findLoan(string $uuid) {
        if (!preg_match(self::UUID_RE, $uuid)) {
            return 404;
        }
        $uuid = strtolower($uuid);
        $rows = $this->loadLoans('l.`uuid` = ' . $this->q($uuid));
        if ($rows) {
            return $rows[0];
        }
        if (countElementsInTable('glpi_plugin_lagapenak_loans', ['uuid' => $uuid]) === 0
            && PluginLagapenakLoanLink::hasTombstone($uuid, $this->client['source'])
        ) {
            return 410;
        }
        return 404;
    }

    private function serializeLoans(array $rows): array {
        if (!$rows) {
            return [];
        }
        $ids   = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $items = [];
        foreach ($this->select(
            'SELECT `loans_id`, `itemtype`, `items_id`, `status`, `date_checkout`, `date_checkin`
             FROM `glpi_plugin_lagapenak_loanitems` WHERE `loans_id` IN (' . implode(',', $ids) . ') ORDER BY `id`'
        ) as $it) {
            $items[(int) $it['loans_id']][] = $it;
        }
        $assets = $this->assetLabels(array_merge([], ...array_values($items)));

        $out = [];
        foreach ($rows as $l) {
            $list    = [];
            $summary = ['total' => 0, 'pending' => 0, 'delivered' => 0, 'returned' => 0, 'returned_with_incident' => 0];
            foreach ($items[(int) $l['id']] ?? [] as $it) {
                $status = self::ITEM_STATUS[(int) $it['status']] ?? 'pending';
                $summary['total']++;
                $summary[$status]++;
                $asset  = $assets[$it['itemtype']][(int) $it['items_id']] ?? null;
                $list[] = [
                    'type'             => $it['itemtype'],
                    'name'             => $asset['name'] ?? ($it['itemtype'] . ' #' . (int) $it['items_id']),
                    'inventory_number' => ($asset['otherserial'] ?? '') !== '' ? $asset['otherserial'] : null,
                    'status'           => $status,
                    'period'           => ['start' => self::iso($it['date_checkout']), 'end' => self::iso($it['date_checkin'])],
                ];
            }
            $request = null;
            if ((int) $l['date_request_status'] === PluginLagapenakLoan::DATE_REQUEST_PENDING) {
                $request = ['status' => 'pending', 'requested_end' => self::iso($l['requested_date_end'])];
            } elseif ((int) $l['date_request_status'] === PluginLagapenakLoan::DATE_REQUEST_REJECTED) {
                $request = ['status' => 'rejected', 'requested_end' => self::iso($l['requested_date_end'])];
            }
            $links = [];
            foreach (PluginLagapenakLoanLink::getForLoan((int) $l['id'], $this->client['source']) as $k) {
                $k['loan_exists'] = $l['id'];
                $k['loan_entity'] = $l['entities_id'];
                $links[] = $this->serializeLink($k);
            }
            $signed = (bool) $l['is_signed'];
            $out[]  = [
                'uuid'              => $l['uuid'],
                'number'            => (int) $l['id'],
                'glpi_url'          => plugin_lagapenak_web_dir(true) . '/front/loan.form.php?id=' . (int) $l['id'],
                'status'            => self::LOAN_STATUS[(int) $l['status']] ?? 'pending',
                'period'            => ['start' => self::iso($l['fecha_inicio']), 'end' => self::iso($l['fecha_fin'])],
                'extension_request' => $request,
                'delivery_note'     => ['signed' => $signed, 'signed_at' => $signed ? self::iso($l['signature_date']) : null],
                'items_summary'     => $summary,
                'items'             => $list,
                'links'             => $links,
                'created_at'        => self::iso($l['date_creation']),
                'changed_at'        => self::iso($l['changed_at']),
            ];
        }
        $this->count = count($out);
        return $out;
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
            if ($DB->fieldExists($table, 'otherserial')) {
                $fields[] = 'otherserial';
            }
            foreach ($DB->request(['SELECT' => $fields, 'FROM' => $table, 'WHERE' => ['id' => array_values($ids)]]) as $a) {
                $out[$itemtype][(int) $a['id']] = $a;
            }
        }
        return $out;
    }

    /**
     * Effective state: an active link whose loan is outside the client's entities is "unavailable".
     */
    private function linkState(array $k): string {
        if ($k['state'] === PluginLagapenakLoanLink::STATE_ACTIVE
            && (empty($k['loan_exists']) || !in_array((int) $k['loan_entity'], $this->entities, true))
        ) {
            return PluginLagapenakLoanLink::STATE_UNAVAILABLE;
        }
        return $k['state'];
    }

    private function serializeLink(array $k, ?string $changed_at = null): array {
        return [
            'loan_uuid'           => $k['loan_uuid'],
            'loan_number'         => (int) $k['loan_number'],
            'object_type'         => $k['object_type'],
            'object_id'           => $k['object_id'],
            'parent_residency_id' => $k['parent_residency_id'],
            'label'               => $k['label'],
            'state'               => $this->linkState($k),
            'created_at'          => self::iso($k['date_creation']),
            'created_by'          => $k['created_by'],
            'removed_at'          => self::iso($k['date_removed']),
            'removed_by'          => $k['removed_by'],
            'changed_at'          => self::iso($changed_at ?? ($k['date_mod'] ?: $k['date_creation'])),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

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

    private static function cursor(string $ts, int $id): string {
        return self::b64enc('c|' . $ts . '|' . $id);
    }

    /**
     * Opaque cursor from a previous page, or an RFC 3339 date to start from.
     *
     * @return array{0:string,1:int}|null [local timestamp, last id]
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
            return [$ts < '1970-01-02 00:00:00' ? '1970-01-02 00:00:00' : $ts, 0];
        }
        $decoded = self::b64dec($value);
        if ($decoded !== null && preg_match('/^c\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $m)) {
            return [$m[1], (int) $m[2]];
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
