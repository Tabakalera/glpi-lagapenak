<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

Html::header(
    'Lagapenak - Préstamos',
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginLagapenakLoan'
);

$loans = PluginLagapenakLoan::getAllLoans();

echo '<div class="container-fluid mt-3">';

echo '<div class="d-flex justify-content-between align-items-center mb-3">';
echo '<h2><i class="fas fa-box-open me-2"></i>Lagapenak - Préstamos</h2>';
echo '<a href="' . Plugin::getWebDir('lagapenak') . '/front/loan.form.php" class="btn btn-primary">';
echo '<i class="fas fa-plus me-1"></i>Nuevo préstamo';
echo '</a>';
echo '</div>';

echo '<div class="card">';
echo '<div class="card-body p-0">';
echo '<div class="table-responsive">';
echo '<table class="table table-hover table-striped mb-0">';
echo '<thead class="table-dark"><tr>';
echo '<th>#</th>';
echo '<th>Nombre</th>';
echo '<th>Estado</th>';
echo '<th>Solicitante</th>';
echo '<th>Destinatario</th>';
echo '<th>Fecha inicio</th>';
echo '<th>Fecha fin</th>';
echo '<th>Acciones</th>';
echo '</tr></thead>';
echo '<tbody>';

if (empty($loans)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">';
    echo '<i class="fas fa-inbox fa-2x mb-2 d-block"></i>No hay préstamos registrados.';
    echo '</td></tr>';
} else {
    foreach ($loans as $loan) {
        $edit_url     = Plugin::getWebDir('lagapenak') . '/front/loan.form.php?id=' . $loan['id'];
        $solicitante  = trim(($loan['solicitante_firstname'] ?? '') . ' ' . ($loan['solicitante_name'] ?? '')) ?: '-';
        $dest         = trim(($loan['dest_firstname'] ?? '') . ' ' . ($loan['dest_name'] ?? '')) ?: '-';

        echo '<tr>';
        echo '<td>' . $loan['id'] . '</td>';
        echo '<td><a href="' . $edit_url . '">' . htmlspecialchars($loan['name']) . '</a></td>';
        echo '<td>' . PluginLagapenakLoan::getStatusBadge($loan['status']) . '</td>';
        echo '<td>' . htmlspecialchars($solicitante) . '</td>';
        echo '<td>' . htmlspecialchars($dest) . '</td>';
        echo '<td>' . Html::convDateTime($loan['fecha_inicio']) . '</td>';
        echo '<td>' . Html::convDateTime($loan['fecha_fin']) . '</td>';
        echo '<td>';
        echo '<a href="' . $edit_url . '" class="btn btn-sm btn-outline-primary" title="Editar">';
        echo '<i class="fas fa-edit"></i></a>';
        echo '</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '</div>'; // container-fluid

Html::footer();
