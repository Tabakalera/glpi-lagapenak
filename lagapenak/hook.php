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
        $DB->queryOrDie($query, $DB->error());
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
        $DB->queryOrDie($query, $DB->error());
    }

    // Upgrade: add signature + albaran columns if not present
    foreach ([
        'signature_data' => "LONGTEXT NULL DEFAULT NULL",
        'signature_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'signature_date' => "TIMESTAMP NULL DEFAULT NULL",
        'has_albaran'    => "TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_lagapenak_loans', $col)) {
            $DB->queryOrDie(
                "ALTER TABLE `glpi_plugin_lagapenak_loans` ADD COLUMN `$col` $def",
                $DB->error()
            );
        }
    }

    ProfileRight::addProfileRights(['plugin_lagapenak_loan']);

    // Default display columns for loan list (users_id=0 = global default)
    // 3=Estado, 4=Solicitante, 5=Destinatario, 6=F.Inicio, 7=F.Fin
    plugin_lagapenak_set_display_prefs();

    return true;
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

function plugin_lagapenak_uninstall() {
    global $DB;

    foreach (['glpi_plugin_lagapenak_loans', 'glpi_plugin_lagapenak_loanitems'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->queryOrDie("DROP TABLE `$table`", $DB->error());
        }
    }

    // Remove display preferences
    $DB->delete('glpi_displaypreferences', ['itemtype' => 'PluginLagapenakLoan']);

    ProfileRight::deleteProfileRights(['plugin_lagapenak_loan']);

    return true;
}
