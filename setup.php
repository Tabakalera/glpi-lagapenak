<?php

define('PLUGIN_LAGAPENAK_VERSION', '1.6.1');

include_once __DIR__ . '/inc/compat.php';

// Requerido por GLPI 10 para activar el plugin (GLPI 11 lo ignora)
global $PLUGIN_HOOKS;
$PLUGIN_HOOKS['csrf_compliant']['lagapenak'] = true;
$PLUGIN_HOOKS['menu_toadd']['lagapenak'] = ['tools' => 'PluginLagapenakLoan'];
define('PLUGIN_LAGAPENAK_MIN_GLPI', '10.0.11'); // DBmysql::doQuery() exists since 10.0.11
define('PLUGIN_LAGAPENAK_MAX_GLPI', '11.0.99');

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
    // Asset lines have no entity of their own: restrict them through their loan
    // (own loans for requesters, loans of the active entities for supervisors)
    if ($itemtype === 'PluginLagapenakLoanItem') {
        $scope = PluginLagapenakLoan::canSupervise()
            ? '1=1 ' . getEntitiesRestrictRequest('AND', 'l', 'entities_id', '', false)
            : 'l.`users_id` = ' . (int)($_SESSION['glpiID'] ?? 0);
        return "`glpi_plugin_lagapenak_loanitems`.`loans_id` IN (SELECT l.`id` FROM `glpi_plugin_lagapenak_loans` l WHERE $scope)";
    }
    return '';
}

function plugin_init_lagapenak() {
    Plugin::loadLang('lagapenak');

    Plugin::registerClass('PluginLagapenakLoan');
    Plugin::registerClass('PluginLagapenakProfile', ['addtabon' => ['Profile']]);

    include_once __DIR__ . '/inc/notification.php';

    // GLPI 11: pages reachable without a GLPI session (they check their own token)
    // (GLPI 10.0.x also has a Firewall class, but without this method)
    if (method_exists(\Glpi\Http\Firewall::class, 'addPluginStrategyForLegacyScripts')) {
        foreach (['#^/front/sign\.php#', '#^/front/loan\.ics\.php#'] as $pattern) {
            \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
                'lagapenak',
                $pattern,
                \Glpi\Http\Firewall::STRATEGY_NO_CHECK
            );
        }
    }

    // Register daily cron for return reminders (runs once, safe to call repeatedly)
    CronTask::register('PluginLagapenakLoan', 'LoanReminder', DAY_TIMESTAMP, [
        'comment' => 'Send return reminders for loans due in 2 days',
        'state'   => CronTask::STATE_WAITING,
        'mode'    => CronTask::MODE_INTERNAL,
    ]);

    // Only show the helpdesk sidebar entry to users who have at least READ on the plugin
    if (PluginLagapenakLoan::hasPluginRight('plugin_lagapenak_loan', READ)) {
        global $PLUGIN_HOOKS;
        $PLUGIN_HOOKS['helpdesk_menu_entry']['lagapenak'] = '/plugins/lagapenak/front/loan.php';
    }

    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['item_add']['lagapenak'] = [
        'PluginLagapenakLoan' => 'plugin_lagapenak_notify_loan_created',
    ];
}
