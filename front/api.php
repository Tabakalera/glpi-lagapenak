<?php

/**
 * Integration API G1 (Kide-Artean): front/api.php/v1/...
 * Contract: docs/api/openapi-g1.yaml. Authentication by bearer token, never by GLPI session.
 */

// GLPI 10: no session cookie for API calls (GLPI 11 declares this path stateless, see plugin_lagapenak_boot)
ini_set('session.use_cookies', '0');

include('../../../inc/includes.php');

PluginLagapenakApi::run();
