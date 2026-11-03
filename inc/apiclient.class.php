<?php

/**
 * Server-to-server clients of the integration API (G1).
 *
 * A token is "lgk_<prefix>_<secret>": the prefix finds the client, only the SHA-256 of the whole token is
 * stored and the clear token is shown once. Rotation keeps the previous token valid for a grace period;
 * revocation clears both. Each client has scopes, a list of GLPI entities (optionally with sub-entities)
 * and a list of allowed source IPs (an empty list allows nobody).
 */
class PluginLagapenakApiClient {

    const TABLE = 'glpi_plugin_lagapenak_apiclients';

    // G1 is read-only. loans:read: loans, material, states, staff and operational fields. loans:read:personal:
    // also the beneficiary's name and email and the signer's name (only with loans:read).
    const SCOPES = ['loans:read', 'loans:read:personal'];
    const SCOPE_PERSONAL = 'loans:read:personal';

    const MAX_VALIDITY_DAYS = 366;

    static function getConfig(): array {
        $c = Config::getConfigurationValues('plugin:lagapenak', [
            'api_enabled', 'api_require_https', 'api_trusted_proxies', 'api_rate_per_minute', 'api_log_retention_days',
            'api_sync_from',
        ]);
        return [
            'enabled'            => (int) ($c['api_enabled'] ?? 0) === 1,
            'require_https'      => (int) ($c['api_require_https'] ?? 1) !== 0,
            'trusted_proxies'    => self::parseList($c['api_trusted_proxies'] ?? ''),
            'rate_per_minute'    => max(1, (int) ($c['api_rate_per_minute'] ?? 120)),
            'log_retention_days' => max(30, (int) ($c['api_log_retention_days'] ?? 365)),
            // First day of the change feed (G1: 2026-01-01); older loans stay reachable by number
            'sync_from'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($c['api_sync_from'] ?? '')) ? $c['api_sync_from'] : '2026-01-01',
        ];
    }

    static function parseList(?string $text): array {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $text))));
    }

    static function getById(int $id): ?array {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]]) as $row) {
            return $row;
        }
        return null;
    }

    static function getAll(): array {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABLE, 'ORDER' => 'name']), false);
    }

    /**
     * Client matching a presented token, or null. Constant-time comparison of the hashes.
     *
     * @return array|null client row plus 'using_previous_token' => bool
     */
    static function authenticate(string $token): ?array {
        global $DB;
        if (!preg_match('/^lgk_([a-f0-9]{8})_[A-Za-z0-9]{40}$/', $token, $m)) {
            return null;
        }
        $hash = hash('sha256', $token);
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['OR' => ['token_prefix' => $m[1], 'prev_token_prefix' => $m[1]]],
        ]) as $c) {
            $now = time();
            if (!(int) $c['is_active'] || empty($c['expires_at']) || strtotime($c['expires_at']) <= $now) {
                return null;
            }
            if ($c['token_prefix'] === $m[1] && !empty($c['token_hash']) && hash_equals($c['token_hash'], $hash)) {
                $c['using_previous_token'] = false;
                return $c;
            }
            if ($c['prev_token_prefix'] === $m[1] && !empty($c['prev_token_hash'])
                && !empty($c['prev_token_expires_at']) && strtotime($c['prev_token_expires_at']) > $now
                && hash_equals($c['prev_token_hash'], $hash)
            ) {
                $c['using_previous_token'] = true;
                return $c;
            }
        }
        return null;
    }

    static function getScopes(array $client): array {
        return array_values(array_intersect(self::SCOPES, explode(' ', (string) $client['scopes'])));
    }

    /**
     * Entity ids the client may see, recomputed on every request.
     */
    static function getEntityIds(array $client): array {
        $ids = [];
        foreach (json_decode((string) $client['entities'], true) ?: [] as $id) {
            $id = (int) $id;
            $ids[$id] = $id;
            if ((int) $client['is_recursive']) {
                foreach (getSonsOf('glpi_entities', $id) as $son) {
                    $ids[(int) $son] = (int) $son;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Opaque version of what the client may see: its effective entities (recomputed from the GLPI entity
     * tree on every request, so a moved or removed sub-entity counts too) and its scopes. Any change gives a
     * new value; the consumer then reconciles what it knows (contract x-sync). Reveals no loan data.
     */
    static function scopeVersion(array $client, array $entity_ids): string {
        $ids = array_map('intval', $entity_ids);
        sort($ids);
        return 'sv1-' . substr(hash('sha256', implode(',', $ids) . '|' . implode(' ', self::getScopes($client))), 0, 16);
    }

    static function isIpAllowed(array $client, string $ip): bool {
        foreach (self::parseList($client['allowed_ips'] ?? '') as $rule) {
            if (self::ipMatches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4/IPv6 address against an address or a CIDR range.
     */
    static function ipMatches(string $ip, string $rule): bool {
        $bits = null;
        if (strpos($rule, '/') !== false) {
            [$rule, $bits] = explode('/', $rule, 2);
            if (!ctype_digit($bits)) {
                return false;
            }
            $bits = (int) $bits;
        }
        $a = @inet_pton($ip);
        $b = @inet_pton($rule);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $max  = strlen($a) * 8;
        $bits = $bits === null ? $max : min($bits, $max);
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    static function newToken(): array {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $secret   = '';
        for ($i = 0; $i < 40; $i++) {
            $secret .= $alphabet[random_int(0, 61)];
        }
        $prefix = bin2hex(random_bytes(4));
        $token  = 'lgk_' . $prefix . '_' . $secret;
        return ['token' => $token, 'prefix' => $prefix, 'hash' => hash('sha256', $token)];
    }

    /**
     * Validated client fields from the admin form, or a list of error messages.
     *
     * @return array{0:array,1:string[]}
     */
    static function validateInput(array $in): array {
        $errors = [];
        $name   = trim((string) ($in['name'] ?? ''));
        $source = trim((string) ($in['source'] ?? ''));
        // Plain characters only: the name is stored and shown as is on GLPI 10 and 11
        if (!preg_match('/^[\p{L}\p{N} ._()-]{1,100}$/u', $name)) {
            $errors[] = __('Name is required: up to 100 letters, digits, spaces and . _ ( ) -', 'lagapenak');
        }
        if (!preg_match('/^[a-z0-9-]{2,32}$/', $source)) {
            $errors[] = __('Source must be 2-32 lower-case letters, digits or dashes.', 'lagapenak');
        }
        $scopes = array_values(array_intersect(self::SCOPES, (array) ($in['scopes'] ?? [])));
        if (!$scopes) {
            $errors[] = __('Choose at least one scope.', 'lagapenak');
        }
        $entities = array_values(array_unique(array_map('intval', (array) ($in['entities'] ?? []))));
        $entities = array_values(array_filter($entities, function ($id) {
            return countElementsInTable('glpi_entities', ['id' => $id]) > 0;
        }));
        if (!$entities) {
            $errors[] = __('Choose at least one entity.', 'lagapenak');
        }
        $ips = self::parseList($in['allowed_ips'] ?? '');
        foreach ($ips as $rule) {
            $addr = explode('/', $rule, 2)[0];
            if (@inet_pton($addr) === false) {
                $errors[] = sprintf(__('Invalid IP address or range: %s', 'lagapenak'), $rule);
            }
        }
        if (!$ips) {
            $errors[] = __('Enter at least one allowed IP address.', 'lagapenak');
        }
        $expires = trim((string) ($in['expires_at'] ?? ''));
        $ts      = strtotime($expires . ' 23:59:59');
        if ($expires === '' || $ts === false || $ts <= time() || $ts > time() + self::MAX_VALIDITY_DAYS * DAY_TIMESTAMP) {
            $errors[] = __('Expiry date must be in the future and within 12 months.', 'lagapenak');
        }

        return [[
            'name'         => $name,
            'source'       => $source,
            'scopes'       => implode(' ', $scopes),
            'entities'     => json_encode($entities),
            'is_recursive' => !empty($in['is_recursive']) ? 1 : 0,
            'allowed_ips'  => implode("\n", $ips),
            'is_active'    => !empty($in['is_active']) ? 1 : 0,
            'expires_at'   => $ts ? date('Y-m-d H:i:s', $ts) : null,
        ], $errors];
    }

    /**
     * @return string the clear token, to be shown once
     */
    static function create(array $fields): string {
        global $DB;
        $t   = self::newToken();
        $now = date('Y-m-d H:i:s');
        $DB->insert(self::TABLE, $fields + [
            'token_prefix'  => $t['prefix'],
            'token_hash'    => $t['hash'],
            'scope_mod'     => $now,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        return $t['token'];
    }

    static function update(int $id, array $fields): void {
        global $DB;
        $old = self::getById($id);
        if (!$old) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        // The source identifies the consumer: it never changes after creation
        $fields['source'] = $old['source'];
        // A change of what the client may see sends every loan again on its next sync
        if ($old['entities'] !== $fields['entities'] || (int) $old['is_recursive'] !== (int) $fields['is_recursive']
            || (int) $old['is_active'] !== (int) $fields['is_active'] || $old['scopes'] !== $fields['scopes']
        ) {
            $fields['scope_mod'] = $now;
        }
        $DB->update(self::TABLE, $fields + ['date_mod' => $now], ['id' => $id]);
    }

    /**
     * New token; the current one keeps working for $grace_days (0 = stops now).
     */
    static function rotate(int $id, int $grace_days): ?string {
        global $DB;
        $c = self::getById($id);
        if (!$c) {
            return null;
        }
        $t     = self::newToken();
        $grace = max(0, min(30, $grace_days));
        $DB->update(self::TABLE, [
            'prev_token_prefix'     => $grace ? $c['token_prefix'] : null,
            'prev_token_hash'       => $grace ? $c['token_hash'] : null,
            'prev_token_expires_at' => $grace ? date('Y-m-d H:i:s', time() + $grace * DAY_TIMESTAMP) : null,
            'token_prefix'          => $t['prefix'],
            'token_hash'            => $t['hash'],
            'date_mod'              => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        return $t['token'];
    }

    /**
     * Immediate revocation: both tokens stop working and the client is deactivated.
     */
    static function revoke(int $id): void {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, [
            'token_prefix'          => null,
            'token_hash'            => null,
            'prev_token_prefix'     => null,
            'prev_token_hash'       => null,
            'prev_token_expires_at' => null,
            'is_active'             => 0,
            'scope_mod'             => $now,
            'date_mod'              => $now,
        ], ['id' => $id]);
    }

    static function touch(int $id): void {
        global $DB;
        $DB->update(self::TABLE, ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    }
}
