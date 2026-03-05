<?php

define('PLUGIN_LAGAPENAK_VERSION', '1.0.0');

// Requerido por GLPI 10 para activar el plugin
global $PLUGIN_HOOKS;
$PLUGIN_HOOKS['csrf_compliant']['lagapenak'] = true;
$PLUGIN_HOOKS['menu_toadd']['lagapenak'] = ['tools' => 'PluginLagapenakLoan'];
define('PLUGIN_LAGAPENAK_MIN_GLPI', '10.0.0');
define('PLUGIN_LAGAPENAK_MAX_GLPI', '10.1.0');

function plugin_version_lagapenak() {
    return [
        'name'         => 'Lagapenak - Gestor de Préstamos',
        'version'      => PLUGIN_LAGAPENAK_VERSION,
        'author'       => 'TBK',
        'license'      => 'GPL v2+',
        'homepage'     => '',
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
        echo 'Este plugin requiere GLPI >= ' . PLUGIN_LAGAPENAK_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_lagapenak_check_config() {
    return true;
}
