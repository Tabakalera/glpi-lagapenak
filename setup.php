<?php

define('PLUGIN_LAGAPENAK_VERSION', '1.1.0');

// Requerido por GLPI 10 para activar el plugin
global $PLUGIN_HOOKS;
$PLUGIN_HOOKS['csrf_compliant']['lagapenak'] = true;
$PLUGIN_HOOKS['menu_toadd']['lagapenak'] = ['tools' => 'PluginLagapenakLoan'];
// Also show in the simplified (Self-Service) interface
$PLUGIN_HOOKS['helpdesk_menu_entry']['lagapenak'] = '/plugins/lagapenak/front/loan.php';
define('PLUGIN_LAGAPENAK_MIN_GLPI', '10.0.0');
define('PLUGIN_LAGAPENAK_MAX_GLPI', '10.99.99');

function plugin_version_lagapenak() {
    return [
        'name'         => 'Lagapenak - Asset Loan Manager',
        'version'      => PLUGIN_LAGAPENAK_VERSION,
        'author'       => 'Tabakalera',
        'license'      => 'GPL v2+',
        'homepage'     => 'https://github.com/Tabakalera/glpi-lagapenak',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_LAGAPENAK_MIN_GLPI,
                'max' => PLUGIN_LAGAPENAK_MAX_GLPI,
            ],
        ],
        'csrf_compliant' => true,
    ];
}

function plugin_lagapenak_check_prerequisites() {
    if (version_compare(GLPI_VERSION, PLUGIN_LAGAPENAK_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_LAGAPENAK_MAX_GLPI, 'gt')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_LAGAPENAK_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_lagapenak_check_config() {
    return true;
}

/**
 * GLPI search engine hook: restrict loan list for non-supervisors.
 * Called by Search::addDefaultWhere() for plugin item types.
 */
function plugin_lagapenak_addDefaultWhere($itemtype) {
    if ($itemtype === 'PluginLagapenakLoan' && !PluginLagapenakLoan::canSupervise()) {
        return "`glpi_plugin_lagapenak_loans`.`users_id` = " . (int)($_SESSION['glpiID'] ?? 0);
    }
    return '';
}

function plugin_init_lagapenak() {
    Plugin::loadLang('lagapenak');

    Plugin::registerClass('PluginLagapenakLoan');
    Plugin::registerClass('PluginLagapenakProfile', ['addtabon' => ['Profile']]);

    include_once __DIR__ . '/inc/notification.php';

    // Register daily cron for return reminders (runs once, safe to call repeatedly)
    CronTask::register('PluginLagapenakLoan', 'LoanReminder', DAY_TIMESTAMP, [
        'comment' => 'Send return reminders for loans due in 2 days',
        'state'   => CronTask::STATE_WAITING,
        'mode'    => CronTask::MODE_INTERNAL,
    ]);

    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['item_add']['lagapenak'] = [
        'PluginLagapenakLoan' => 'plugin_lagapenak_notify_loan_created',
    ];
}
