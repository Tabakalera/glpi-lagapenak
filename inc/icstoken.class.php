<?php

/**
 * Secret tokens for the iCal feed (front/loan.ics.php).
 *
 * - General token (plugin config "ics_token"): every loan. Only shown to supervisors.
 * - Personal token (one per user, table glpi_plugin_lagapenak_icstokens): only the loans
 *   the user requested (same scope as the in-GLPI calendar for non-supervisors).
 */
class PluginLagapenakIcsToken {

    const TABLE = 'glpi_plugin_lagapenak_icstokens';

    private static function newToken(): string {
        return bin2hex(random_bytes(20));
    }

    static function getGeneralToken(): string {
        $cfg   = Config::getConfigurationValues('plugin:lagapenak');
        $token = $cfg['ics_token'] ?? '';
        if (!$token) {
            $token = self::regenerateGeneralToken();
        }
        return $token;
    }

    static function regenerateGeneralToken(): string {
        $token = self::newToken();
        Config::setConfigurationValues('plugin:lagapenak', ['ics_token' => $token]);
        return $token;
    }

    static function getUserToken(int $users_id): string {
        global $DB;
        foreach ($DB->request(['SELECT' => ['token'], 'FROM' => self::TABLE, 'WHERE' => ['users_id' => $users_id]]) as $row) {
            return $row['token'];
        }
        return self::regenerateUserToken($users_id);
    }

    static function regenerateUserToken(int $users_id): string {
        global $DB;
        $token = self::newToken();
        $DB->delete(self::TABLE, ['users_id' => $users_id]);
        $DB->insert(self::TABLE, [
            'users_id'      => $users_id,
            'token'         => $token,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /**
     * What a token gives access to.
     *
     * @return array|null ['users_id' => 0] for the general token (every loan),
     *                    ['users_id' => N] for a personal token, null if invalid.
     */
    static function resolve(string $token): ?array {
        global $DB;

        // Strict format check (also keeps values such as "NULL" away from the query builder)
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        if (hash_equals(self::getGeneralToken(), $token)) {
            return ['users_id' => 0];
        }
        foreach ($DB->request([
            'SELECT' => ['t.users_id'],
            'FROM'   => self::TABLE . ' AS t',
            'INNER JOIN' => [
                'glpi_users AS u' => ['ON' => ['t' => 'users_id', 'u' => 'id']],
            ],
            'WHERE'  => ['t.token' => $token, 'u.is_active' => 1, 'u.is_deleted' => 0],
        ]) as $row) {
            return ['users_id' => (int) $row['users_id']];
        }
        return null;
    }
}
