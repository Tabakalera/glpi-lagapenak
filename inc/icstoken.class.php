<?php

/**
 * Personal secret tokens for the iCal feed (front/loan.ics.php), one per user.
 *
 * What a feed shows is decided on every request from the token owner's current rights and entities
 * (getFeedScope), never from what they could see when the token was created:
 * - the loans they requested, if they have READ in some profile;
 * - the loans of the entities where they have READ + UPDATE (supervision).
 * A token stops working when it is regenerated or revoked, and when the account is disabled, deleted
 * or outside its validity dates; it gives nothing once the user has no READ right left.
 *
 * Until 1.6.0 there was also one general token (plugin config "ics_token") with every loan of every
 * entity. It is retired: the feed answers it with a notice and no loan data. The value stays in the
 * config only so that it can be recognised; uninstalling the plugin removes it.
 */
class PluginLagapenakIcsToken {

    const TABLE = 'glpi_plugin_lagapenak_icstokens';

    private static function newToken(): string {
        return bin2hex(random_bytes(20));
    }

    static function getUserToken(int $users_id): string {
        global $DB;
        foreach ($DB->request(['SELECT' => ['token'], 'FROM' => self::TABLE, 'WHERE' => ['users_id' => $users_id]]) as $row) {
            return $row['token'];
        }
        return self::regenerateUserToken($users_id);
    }

    static function hasUserToken(int $users_id): bool {
        return countElementsInTable(self::TABLE, ['users_id' => $users_id]) > 0;
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

    /** The user's subscription URL stops working; a new one is only created if they ask for it. */
    static function revokeUserToken(int $users_id): void {
        global $DB;
        $DB->delete(self::TABLE, ['users_id' => $users_id]);
    }

    /** True for the retired general token of 1.6.0. */
    static function isRetiredGeneralToken(string $token): bool {
        $cfg = Config::getConfigurationValues('plugin:lagapenak', ['ics_token']);
        return !empty($cfg['ics_token']) && hash_equals((string) $cfg['ics_token'], $token);
    }

    /** users_id of a personal token, or null. */
    static function resolve(string $token): ?int {
        global $DB;

        // Strict format check (also keeps values such as "NULL" away from the query builder)
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => self::TABLE, 'WHERE' => ['token' => $token]]) as $row) {
            return (int) $row['users_id'];
        }
        return null;
    }

    /**
     * Current feed scope of a user, from all their profile assignments (a subscribed calendar has no
     * active profile or entity): null when the account cannot be used or has no READ right at all.
     *
     * @return array{users_id: int, entities: int[]}|null
     */
    static function getFeedScope(int $users_id): ?array {
        global $DB;

        $user = new User();
        if (!$user->getFromDB($users_id) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        if ((!empty($user->fields['begin_date']) && $user->fields['begin_date'] > $now)
            || (!empty($user->fields['end_date']) && $user->fields['end_date'] < $now)
        ) {
            return null;
        }

        $has_read = false;
        $entities = [];
        foreach ($DB->request([
            'SELECT'     => ['pu.entities_id', 'pu.is_recursive', 'pr.rights'],
            'FROM'       => 'glpi_profiles_users AS pu',
            'INNER JOIN' => [
                'glpi_profilerights AS pr' => ['ON' => ['pu' => 'profiles_id', 'pr' => 'profiles_id']],
            ],
            'WHERE'      => ['pu.users_id' => $users_id, 'pr.name' => PluginLagapenakLoan::$rightname],
        ]) as $row) {
            $rights = (int) $row['rights'];
            if (!($rights & READ)) {
                continue;
            }
            $has_read = true;
            if ($rights & UPDATE) {
                $entity     = (int) $row['entities_id'];
                $entities[] = $entity;
                if ($row['is_recursive']) {
                    $entities = array_merge($entities, array_map('intval', array_keys(getSonsOf('glpi_entities', $entity))));
                }
            }
        }
        if (!$has_read) {
            return null;
        }
        return ['users_id' => $users_id, 'entities' => array_values(array_unique($entities))];
    }
}
