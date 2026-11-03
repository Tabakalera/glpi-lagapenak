<?php

function plugin_lagapenak_install() {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    if (!$DB->tableExists('glpi_plugin_lagapenak_loans')) {
        $query = "CREATE TABLE `glpi_plugin_lagapenak_loans` (
            `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`                  VARCHAR(255) NOT NULL DEFAULT '',
            `entities_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id`              INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_destinatario` INT UNSIGNED NOT NULL DEFAULT 0,
            `fecha_inicio`          TIMESTAMP NULL DEFAULT NULL,
            `fecha_fin`             TIMESTAMP NULL DEFAULT NULL,
            `status`                TINYINT NOT NULL DEFAULT 1,
            `observaciones`         TEXT,
            `field_1`               VARCHAR(255) NOT NULL DEFAULT '',
            `field_2`               VARCHAR(255) NOT NULL DEFAULT '',
            `field_3`               VARCHAR(255) NOT NULL DEFAULT '',
            `field_4`               VARCHAR(255) NOT NULL DEFAULT '',
            `field_5`               VARCHAR(255) NOT NULL DEFAULT '',
            `tickets_id`            INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation`         TIMESTAMP NULL DEFAULT NULL,
            `date_mod`              TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `users_id_destinatario` (`users_id_destinatario`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        plugin_lagapenak_query_or_fail($query);
    }

    if (!$DB->tableExists('glpi_plugin_lagapenak_loanitems')) {
        $query = "CREATE TABLE `glpi_plugin_lagapenak_loanitems` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `loans_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype`      VARCHAR(100) NOT NULL DEFAULT '',
            `items_id`      INT UNSIGNED NOT NULL DEFAULT 0,
            `status`        TINYINT NOT NULL DEFAULT 1,
            `date_checkout` TIMESTAMP NULL DEFAULT NULL,
            `date_checkin`  TIMESTAMP NULL DEFAULT NULL,
            `notes`         TEXT,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod`      TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `loans_id` (`loans_id`),
            KEY `itemtype` (`itemtype`),
            KEY `items_id` (`items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";
        plugin_lagapenak_query_or_fail($query);
    }

    // Upgrade: add signature + albaran + beneficiary + signing-token columns if not present
    foreach ([
        'signature_data'    => "LONGTEXT NULL DEFAULT NULL",
        'signature_name'    => "VARCHAR(255) NOT NULL DEFAULT ''",
        'signature_date'    => "TIMESTAMP NULL DEFAULT NULL",
        'has_albaran'       => "TINYINT(1) NOT NULL DEFAULT 0",
        'albaran_passport'  => "VARCHAR(100) NOT NULL DEFAULT ''",
        'albaran_project'   => "VARCHAR(255) NOT NULL DEFAULT ''",
        'beneficiary_name'  => "VARCHAR(255) NOT NULL DEFAULT ''",
        'beneficiary_email' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'beneficiary_dni'   => "VARCHAR(100) NOT NULL DEFAULT ''",
        'sign_token'          => "VARCHAR(64) NULL DEFAULT NULL",
        'sign_token_expires'  => "TIMESTAMP NULL DEFAULT NULL",
        'requested_date_end'       => "TIMESTAMP NULL DEFAULT NULL",
        'date_request_status'      => "TINYINT NOT NULL DEFAULT 0",
        'date_request_apply_items' => "TINYINT NOT NULL DEFAULT 0",
        'date_request_notes'       => "TEXT NULL DEFAULT NULL",
    ] as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_lagapenak_loans', $col)) {
            plugin_lagapenak_query_or_fail(
                "ALTER TABLE `glpi_plugin_lagapenak_loans` ADD COLUMN `$col` $def"
            );
        }
    }

    // Fix: convert any DATETIME columns to TIMESTAMP (GLPI standard)
    foreach (['sign_token_expires', 'requested_date_end'] as $col) {
        if ($DB->fieldExists('glpi_plugin_lagapenak_loans', $col)) {
            plugin_lagapenak_query_or_fail(
                "ALTER TABLE `glpi_plugin_lagapenak_loans` MODIFY COLUMN `$col` TIMESTAMP NULL DEFAULT NULL"
            );
        }
    }

    // Comments table
    if (!$DB->tableExists('glpi_plugin_lagapenak_loancomments')) {
        plugin_lagapenak_query_or_fail(
            "CREATE TABLE `glpi_plugin_lagapenak_loancomments` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `loans_id`    INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id`    INT UNSIGNED NOT NULL DEFAULT 0,
                `comment`     TEXT NOT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `loans_id` (`loans_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    }

    // Personal iCal tokens (one per user)
    if (!$DB->tableExists('glpi_plugin_lagapenak_icstokens')) {
        plugin_lagapenak_query_or_fail(
            "CREATE TABLE `glpi_plugin_lagapenak_icstokens` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `token`         VARCHAR(64) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_id` (`users_id`),
                UNIQUE KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    }

    plugin_lagapenak_install_api();

    // Only add missing rights: ProfileRight::addProfileRights() inserts blindly and, on GLPI 11,
    // the duplicate-key error on plugin update is fatal.
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
        if (!countElementsInTable('glpi_profilerights', [
            'profiles_id' => $profile['id'],
            'name'        => 'plugin_lagapenak_loan',
        ])) {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profile['id'],
                'name'        => 'plugin_lagapenak_loan',
                'rights'      => 0,
            ]);
        }
    }

    // Default display columns for loan list (users_id=0 = global default)
    // 3=Estado, 4=Solicitante, 5=Destinatario, 6=F.Inicio, 7=F.Fin
    plugin_lagapenak_set_display_prefs();

    // The general iCal token of 1.6.0 (every loan of every entity) is retired: no new one is created.
    // An existing value is kept only so that front/loan.ics.php can recognise the old URL and answer it
    // with a notice instead of loans. Personal tokens (glpi_plugin_lagapenak_icstokens) replace it.

    return true;
}

/**
 * G1 integration API (Kide-Artean): loan UUIDs, beneficiary check flag, cancellation record, loan events,
 * API clients and API log.
 * Idempotent: only creates what is missing. The API stays disabled until a Super-Admin enables it.
 */
function plugin_lagapenak_install_api() {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    // Stable public identifier of each loan (the numeric id is sequential and internal)
    if (!$DB->fieldExists('glpi_plugin_lagapenak_loans', 'uuid')) {
        plugin_lagapenak_query_or_fail(
            "ALTER TABLE `glpi_plugin_lagapenak_loans` ADD COLUMN `uuid` CHAR(36) NULL DEFAULT NULL"
        );
    }
    // Backfill before the unique key; uuid is not part of date_mod on purpose (it is not a change)
    foreach ($DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_lagapenak_loans',
        'WHERE'  => ['OR' => [['uuid' => null], ['uuid' => '']]],
    ]) as $row) {
        $DB->update('glpi_plugin_lagapenak_loans', ['uuid' => PluginLagapenakLoan::newUuid()], ['id' => $row['id']]);
    }
    if (!isIndex('glpi_plugin_lagapenak_loans', 'uuid')) {
        plugin_lagapenak_query_or_fail(
            "ALTER TABLE `glpi_plugin_lagapenak_loans` ADD UNIQUE KEY `uuid` (`uuid`)"
        );
    }

    // G1-ID01: beneficiary entered and checked with the current rules (0 = created before them)
    // Cancellation: who, when and why (status CANCELLED alone existed before, without record)
    foreach ([
        'beneficiary_validated' => "TINYINT(1) NOT NULL DEFAULT 0",
        'cancel_date'           => "TIMESTAMP NULL DEFAULT NULL",
        'cancel_users_id'       => "INT UNSIGNED NOT NULL DEFAULT 0",
        'cancel_reason'         => "TEXT NULL DEFAULT NULL",
    ] as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_lagapenak_loans', $col)) {
            plugin_lagapenak_query_or_fail("ALTER TABLE `glpi_plugin_lagapenak_loans` ADD COLUMN `$col` $def");
        }
    }

    // Deletions and entity moves, for the API change feed (no personal data)
    if (!$DB->tableExists('glpi_plugin_lagapenak_loanevents')) {
        plugin_lagapenak_query_or_fail(
            "CREATE TABLE `glpi_plugin_lagapenak_loanevents` (
                `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `loans_id`         INT UNSIGNED NOT NULL DEFAULT 0,
                `loan_uuid`        CHAR(36) NOT NULL DEFAULT '',
                `event`            VARCHAR(20) NOT NULL DEFAULT '',
                `entities_id_from` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id_to`   INT UNSIGNED NOT NULL DEFAULT 0,
                `material_status`  VARCHAR(24) NULL DEFAULT NULL,
                `date`             TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `loan_uuid` (`loan_uuid`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    }

    if (!$DB->tableExists('glpi_plugin_lagapenak_apiclients')) {
        plugin_lagapenak_query_or_fail(
            "CREATE TABLE `glpi_plugin_lagapenak_apiclients` (
                `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`                  VARCHAR(100) NOT NULL DEFAULT '',
                `source`                VARCHAR(32) NOT NULL DEFAULT '',
                `token_prefix`          VARCHAR(16) NULL DEFAULT NULL,
                `token_hash`            CHAR(64) NULL DEFAULT NULL,
                `prev_token_prefix`     VARCHAR(16) NULL DEFAULT NULL,
                `prev_token_hash`       CHAR(64) NULL DEFAULT NULL,
                `prev_token_expires_at` TIMESTAMP NULL DEFAULT NULL,
                `scopes`                VARCHAR(255) NOT NULL DEFAULT '',
                `entities`              TEXT NULL DEFAULT NULL,
                `is_recursive`          TINYINT(1) NOT NULL DEFAULT 0,
                `allowed_ips`           TEXT NULL DEFAULT NULL,
                `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
                `expires_at`            TIMESTAMP NULL DEFAULT NULL,
                `last_used_at`          TIMESTAMP NULL DEFAULT NULL,
                `scope_mod`             TIMESTAMP NULL DEFAULT NULL,
                `users_id`              INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation`         TIMESTAMP NULL DEFAULT NULL,
                `date_mod`              TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token_prefix` (`token_prefix`),
                UNIQUE KEY `prev_token_prefix` (`prev_token_prefix`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    }

    // Who called what and when; never request or response bodies
    if (!$DB->tableExists('glpi_plugin_lagapenak_apilogs')) {
        plugin_lagapenak_query_or_fail(
            "CREATE TABLE `glpi_plugin_lagapenak_apilogs` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `apiclients_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date`          TIMESTAMP NULL DEFAULT NULL,
                `method`        VARCHAR(8) NOT NULL DEFAULT '',
                `route`         VARCHAR(255) NOT NULL DEFAULT '',
                `query`         VARCHAR(255) NOT NULL DEFAULT '',
                `status`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `code`          VARCHAR(40) NOT NULL DEFAULT '',
                `result_count`  INT UNSIGNED NOT NULL DEFAULT 0,
                `ip`            VARCHAR(45) NOT NULL DEFAULT '',
                `request_id`    VARCHAR(64) NOT NULL DEFAULT '',
                `acting_user`   CHAR(36) NULL DEFAULT NULL,
                `personal_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `client_date` (`apiclients_id`, `date`),
                KEY `ip_date` (`ip`, `date`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}"
        );
    }

    // How many loans of each response carried personal data (scope loans:read:personal); never the data
    if (!$DB->fieldExists('glpi_plugin_lagapenak_apilogs', 'personal_count')) {
        plugin_lagapenak_query_or_fail(
            "ALTER TABLE `glpi_plugin_lagapenak_apilogs` ADD COLUMN `personal_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `acting_user`"
        );
    }

    $defaults = [
        'api_enabled'            => 0,
        'api_require_https'      => 1,
        'api_trusted_proxies'    => '',
        'api_rate_per_minute'    => 120,
        'api_log_retention_days' => 365,
        'api_sync_from'          => '2026-01-01',
    ];
    $current = Config::getConfigurationValues('plugin:lagapenak', array_keys($defaults));
    $missing = array_diff_key($defaults, $current);
    if ($missing) {
        Config::setConfigurationValues('plugin:lagapenak', $missing);
    }
}

/**
 * Insert default display preferences for the loan list if not already set.
 * Safe to call multiple times (checks existence first).
 */
function plugin_lagapenak_set_display_prefs() {
    $columns = [3, 4, 5, 6, 7]; // search option IDs (name=1 is always shown)
    $dp = new DisplayPreference();
    foreach ($columns as $rank => $num) {
        if (!countElementsInTable('glpi_displaypreferences', [
            'itemtype' => 'PluginLagapenakLoan',
            'num'      => $num,
            'users_id' => 0,
        ])) {
            $dp->add([
                'itemtype' => 'PluginLagapenakLoan',
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ]);
        }
    }
}

/**
 * Uninstall keeps the loan records: loans (with their signed delivery notes), asset lines and comments
 * are evidence of handovers and must not disappear with a click. Reinstalling the plugin finds them
 * again (install only creates what is missing). Deleting them for good is a manual, documented step
 * (README, "Uninstalling"). Personal iCal tokens, API clients and the integration keys are credentials, not
 * records: they are removed. The loan event log and the API log are records and are kept, like the loans.
 */
function plugin_lagapenak_uninstall() {
    global $DB;

    foreach ([
        'glpi_plugin_lagapenak_icstokens',
        'glpi_plugin_lagapenak_apiclients',
    ] as $table) {
        if ($DB->tableExists($table)) {
            plugin_lagapenak_query_or_fail("DROP TABLE `$table`");
        }
    }

    // The general iCal token is a credential too; a reinstall generates a new one
    Config::deleteConfigurationValues('plugin:lagapenak', ['ics_token']);

    // A reinstall starts with the API disabled again
    Config::deleteConfigurationValues('plugin:lagapenak', [
        'api_enabled', 'api_require_https', 'api_trusted_proxies', 'api_rate_per_minute', 'api_log_retention_days',
        'api_sync_from', 'api_hmac_keys', 'api_hmac_keys_mod',
    ]);

    // Remove display preferences
    $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginLagapenakLoan']);

    ProfileRight::deleteProfileRights(['plugin_lagapenak_loan']);

    return true;
}
