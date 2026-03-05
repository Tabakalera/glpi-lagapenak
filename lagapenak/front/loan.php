<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// Bootstrap default display columns once (no-op if already set)
// IDs: 3=Estado, 4=Solicitante, 5=Destinatario, 6=F.Inicio, 7=F.Fin, 20=Activos
$needed = [3, 4, 5, 6, 7, 20];
foreach ($needed as $rank => $num) {
    if (!countElementsInTable('glpi_displaypreferences', [
        'itemtype' => 'PluginLagapenakLoan',
        'num'      => $num,
        'users_id' => 0,
    ])) {
        $dp = new DisplayPreference();
        $dp->add([
            'itemtype' => 'PluginLagapenakLoan',
            'num'      => $num,
            'rank'     => $rank + 1,
            'users_id' => 0,
        ]);
    }
}

Html::header('Lagapenak - Préstamos', $_SERVER['PHP_SELF'], 'tools', 'PluginLagapenakLoan');

$calendar_url = Plugin::getWebDir('lagapenak', true) . '/front/calendar.php';
echo '<div class="d-flex justify-content-end mb-2 px-3">';
echo '<a href="' . $calendar_url . '" class="btn btn-sm btn-outline-secondary">';
echo '<i class="fas fa-calendar-alt me-1"></i>Vista Calendario';
echo '</a></div>';

Search::show('PluginLagapenakLoan');

Html::footer();
