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

    ProfileRight::addProfileRights(['plugin_lagapenak_loan']);

    return true;
}

function plugin_lagapenak_uninstall() {
    global $DB;

    foreach (['glpi_plugin_lagapenak_loans', 'glpi_plugin_lagapenak_loanitems'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->queryOrDie("DROP TABLE `$table`", $DB->error());
        }
    }

    ProfileRight::deleteProfileRights(['plugin_lagapenak_loan']);

    return true;
}
