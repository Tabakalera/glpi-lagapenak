<?php

/**
 * Beneficiary identification for the G1 integration (rule G1-ID01).
 *
 * The beneficiary is the person who receives the material: not the GLPI requester (usually a Tabakalera
 * worker) and not necessarily who signs the delivery note. The API never sends the beneficiary's email or
 * ID number: it sends HMAC-SHA256 fingerprints computed with a key that belongs to the integration only,
 * so that Kide-Artean, which holds the same key, can compare them with its own people.
 *
 * Normalisation "g1-v1" (shared with Kide-Artean, test vectors in docs/api/g1-hmac-vectors.json):
 * - email: strip ASCII whitespace at both ends, lower-case A-Z only, then validate with EMAIL_RE.
 * - DNI/NIE: upper-case a-z, remove spaces, dots, dashes, underscores and slashes; DNI = 1-8 digits + letter,
 *   left-padded to 8 digits; NIE = X/Y/Z + 1-7 digits + letter, left-padded to 7 digits; the control letter
 *   must match. Anything else is "other" (passports and foreign documents): never fingerprinted.
 * - message = "lagapenak-g1|v1|<email|tax_id>|<normalised value>", output = lower-case hex.
 */
class PluginLagapenakBeneficiary {

    const NORMALIZATION = 'g1-v1';
    const ALGORITHM     = 'HMAC-SHA256';

    const STATUS_VALID   = 'valid';
    const STATUS_MISSING = 'missing';
    const STATUS_INVALID = 'invalid';
    const STATUS_OTHER   = 'other';   // tax id only: not a DNI/NIE (passport, foreign document)

    const EMAIL_RE = '/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/';
    const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    const CONFIG_KEYS     = 'api_hmac_keys';      // encrypted by GLPI (secured_configs hook)
    const CONFIG_KEYS_MOD = 'api_hmac_keys_mod';  // when the key set changed: every loan is sent again

    /**
     * @return array{0:string,1:?string} [status, normalised email]
     */
    /**
     * GLPI 10 keeps request input and stored text HTML-encoded; GLPI 11 keeps it as typed.
     */
    static function raw(?string $value): string {
        $value = (string) $value;
        return class_exists('Glpi\Toolbox\Sanitizer') ? (string) \Glpi\Toolbox\Sanitizer::unsanitize($value) : $value;
    }

    static function normalizeEmail(?string $email): array {
        $e = strtr(trim(self::raw($email)," \t\n\r\0\x0B"), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
        if ($e === '') {
            return [self::STATUS_MISSING, null];
        }
        if (strlen($e) > 254 || !preg_match(self::EMAIL_RE, $e)) {
            return [self::STATUS_INVALID, null];
        }
        return [self::STATUS_VALID, $e];
    }

    /**
     * @return array{0:string,1:?string} [status, normalised DNI/NIE]
     */
    static function normalizeTaxId(?string $value): array {
        $v = preg_replace('#[ \t\n\r\f\v._/-]+#', '', strtr(self::raw($value), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        if ($v === '') {
            return [self::STATUS_MISSING, null];
        }
        if (preg_match('/^([0-9]{1,8})([A-Z]?)$/', $v, $m)) {
            $number = str_pad($m[1], 8, '0', STR_PAD_LEFT);
            $canon  = $number . self::DNI_LETTERS[(int) $number % 23];
        } elseif (preg_match('/^([XYZ])([0-9]{1,7})([A-Z]?)$/', $v, $m)) {
            $digits = str_pad($m[2], 7, '0', STR_PAD_LEFT);
            $canon  = $m[1] . $digits . self::DNI_LETTERS[(int) (strpos('XYZ', $m[1]) . $digits) % 23];
            $m[2]   = $m[3];
        } else {
            return [self::STATUS_OTHER, null];
        }
        // Looks like a DNI/NIE: the control letter is required and must be right
        return $m[2] !== '' && substr($canon, -1) === $m[2]
            ? [self::STATUS_VALID, $canon]
            : [self::STATUS_INVALID, null];
    }

    static function fingerprint(string $type, string $normalized, string $key): string {
        return hash_hmac('sha256', 'lagapenak-g1|v1|' . $type . '|' . $normalized, $key);
    }

    /**
     * Message for the loan form, or '' when the beneficiary has at least one valid identifier and no
     * malformed one. A foreign document is accepted but does not count as identifier (an email is needed).
     */
    static function checkForForm(?string $email, ?string $tax_id): string {
        [$es] = self::normalizeEmail($email);
        [$ts] = self::normalizeTaxId($tax_id);
        if ($es === self::STATUS_INVALID) {
            return __("The beneficiary's email address is not valid.", 'lagapenak');
        }
        if ($ts === self::STATUS_INVALID) {
            return __("The beneficiary's DNI/NIE is not valid: check the number and the letter.", 'lagapenak');
        }
        if ($es !== self::STATUS_VALID && $ts !== self::STATUS_VALID) {
            return $ts === self::STATUS_OTHER
                ? __('With a passport or foreign document, the beneficiary\'s email address is required.', 'lagapenak')
                : __('Enter the beneficiary\'s email address or DNI/NIE (at least one).', 'lagapenak');
        }
        return '';
    }

    // ── Integration keys ────────────────────────────────────────────────────

    /**
     * Stored keys: [['kid' => .., 'key' => base64, 'state' => active|previous, 'created' => .., 'retire_at' => ..]]
     */
    static function getStoredKeys(): array {
        // setConfigurationValues() encrypts secured values, but getConfigurationValues() does not decrypt them
        $c = Config::getConfigurationValues('plugin:lagapenak', [self::CONFIG_KEYS]);
        $stored = (string) ($c[self::CONFIG_KEYS] ?? '');
        if ($stored === '') {
            return [];
        }
        $keys = json_decode((string) (new GLPIKey())->decrypt($stored), true);
        return is_array($keys) ? $keys : [];
    }

    /**
     * Keys used to fingerprint now: the active one and, during the transition, the previous one.
     */
    static function getUsableKeys(): array {
        $now = date('Y-m-d H:i:s');
        return array_values(array_filter(self::getStoredKeys(), function ($k) use ($now) {
            return $k['state'] === 'active' || ($k['state'] === 'previous' && (string) $k['retire_at'] > $now);
        }));
    }

    static function getKeysChangedAt(): ?string {
        $c = Config::getConfigurationValues('plugin:lagapenak', [self::CONFIG_KEYS_MOD]);
        return ($c[self::CONFIG_KEYS_MOD] ?? '') !== '' ? $c[self::CONFIG_KEYS_MOD] : null;
    }

    /**
     * New active key; the current one stays usable for $transition_days (0 = retired now).
     *
     * @return array{kid:string,key:string} the new key, shown once to the administrator
     */
    static function rotateKey(int $transition_days): array {
        $now  = date('Y-m-d H:i:s');
        $kept = [];
        foreach (self::getUsableKeys() as $k) {
            if ($k['state'] === 'active' && $transition_days > 0) {
                $k['state']     = 'previous';
                $k['retire_at'] = date('Y-m-d H:i:s', time() + min(90, $transition_days) * DAY_TIMESTAMP);
                $kept[] = $k;
            }
        }
        $new = [
            'kid'       => 'g1-' . date('Ymd') . '-' . bin2hex(random_bytes(2)),
            'key'       => base64_encode(random_bytes(32)),
            'state'     => 'active',
            'created'   => $now,
            'retire_at' => null,
        ];
        $kept[] = $new;
        self::saveKeys($kept);
        return ['kid' => $new['kid'], 'key' => $new['key']];
    }

    static function retirePreviousKeys(): void {
        self::saveKeys(array_values(array_filter(self::getStoredKeys(), function ($k) {
            return $k['state'] === 'active';
        })));
    }

    private static function saveKeys(array $keys): void {
        Config::setConfigurationValues('plugin:lagapenak', [
            self::CONFIG_KEYS     => json_encode($keys),
            self::CONFIG_KEYS_MOD => date('Y-m-d H:i:s'),
        ]);
    }

    // ── API block ───────────────────────────────────────────────────────────

    /**
     * Identification block of a loan for the API: statuses and fingerprints, never the values.
     *
     * @param array    $loan             row with beneficiary_email, beneficiary_dni, beneficiary_validated
     * @param string[] $requester_emails normalised GLPI addresses of the requester
     * @param array    $keys             getUsableKeys()
     */
    static function describe(array $loan, array $requester_emails, array $keys): array {
        [$es, $email] = self::normalizeEmail($loan['beneficiary_email'] ?? '');
        [$ts, $tax]   = self::normalizeTaxId($loan['beneficiary_dni'] ?? '');
        $fingerprints = [];
        foreach ($keys as $k) {
            $raw = base64_decode((string) $k['key'], true);
            if ($raw === false || ($email === null && $tax === null)) {
                continue;
            }
            $fingerprints[] = [
                'kid'    => $k['kid'],
                'email'  => $email !== null ? self::fingerprint('email', $email, $raw) : null,
                'tax_id' => $tax !== null ? self::fingerprint('tax_id', $tax, $raw) : null,
            ];
        }
        return [
            'email_status'            => $es,
            'tax_id_status'           => $ts,
            'matches_requester_email' => $email !== null && in_array($email, $requester_emails, true),
            'checked_on_entry'        => (int) ($loan['beneficiary_validated'] ?? 0) === 1,
            'fingerprints'            => $fingerprints,
        ];
    }
}
