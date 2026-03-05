<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// NOTE: CSRF is validated automatically by GLPI's inc/includes.php for all POST requests.
// Do NOT call Session::checkCSRF() here — it would fail because the token is already
// consumed by the time our handler code runs.

$loan        = new PluginLagapenakLoan();
$ID          = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$form_error  = '';

// ── Handle loan CRUD ────────────────────────────────────────────────────────
if (isset($_POST['save_loan'])) {
    // Required field validation
    if (empty(trim($_POST['name'] ?? ''))) {
        $form_error = 'El campo Nombre / Referencia es obligatorio.';
    } elseif (empty($_POST['fecha_inicio']) || empty($_POST['fecha_fin'])) {
        $form_error = 'Las fechas de inicio y fin son obligatorias.';
    } else {
        if ($ID > 0) {
            $_POST['id'] = $ID;
            $loan->update($_POST);
            Html::back();
        } else {
            $new_id = $loan->add($_POST);
            Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.form.php?id=' . (int) $new_id);
        }
    }
}

if (isset($_POST['delete_loan'])) {
    $loan->delete(['id' => $ID]);
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// ── Handle loan item CRUD ───────────────────────────────────────────────────
if (isset($_POST['add_loanitem'])) {
    $li_loans_id = (int) ($_POST['loans_id'] ?? 0);
    PluginLagapenakLoanItem::addItem(
        $li_loans_id,
        $_POST['itemtype'] ?? '',
        (int) ($_POST['items_id'] ?? 0),
        $_POST['item_date_checkout'] ?? null,
        $_POST['item_date_checkin']  ?? null
    );
    PluginLagapenakLoanItem::syncLoanStatus($li_loans_id);
    Html::back();
}

if (isset($_POST['remove_loanitem'])) {
    PluginLagapenakLoanItem::removeItem((int) ($_POST['loanitem_id'] ?? 0));
    PluginLagapenakLoanItem::syncLoanStatus($ID);
    Html::back();
}

if (isset($_POST['update_loanitem'])) {
    PluginLagapenakLoanItem::updateItem(
        (int) ($_POST['loanitem_id'] ?? 0),
        (int) ($_POST['item_status'] ?? 1),
        $_POST['item_date_checkout'] ?? '',
        $_POST['item_date_checkin']  ?? ''
    );
    PluginLagapenakLoanItem::syncLoanStatus($ID);
    Html::redirect(htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID);
}

// ── Load existing record ────────────────────────────────────────────────────
if ($ID > 0) {
    $loan->getFromDB($ID);
}

$title    = $ID > 0 ? 'Editar préstamo #' . $ID : 'Nuevo préstamo';
$edit_item = isset($_GET['edit_item']) ? (int) $_GET['edit_item'] : 0;

Html::header($title, $_SERVER['PHP_SELF'], 'tools', 'PluginLagapenakLoan');

echo '<div class="container-fluid mt-3">';

// Breadcrumb
echo '<nav aria-label="breadcrumb" class="mb-3">';
echo '<ol class="breadcrumb">';
echo '<li class="breadcrumb-item">';
echo '<a href="' . Plugin::getWebDir('lagapenak') . '/front/loan.php">';
echo '<i class="fas fa-box-open me-1"></i>Préstamos</a></li>';
echo '<li class="breadcrumb-item active">' . htmlspecialchars($title) . '</li>';
echo '</ol>';
echo '</nav>';

// ── Main loan form ──────────────────────────────────────────────────────────
echo '<div class="card">';
echo '<div class="card-header"><h4 class="mb-0">' . htmlspecialchars($title) . '</h4></div>';
echo '<div class="card-body">';

if ($form_error) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>' . htmlspecialchars($form_error) . '</div>';
}

echo '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF'])
     . ($ID > 0 ? '?id=' . $ID : '') . '">';
// GLPI validates this token automatically in inc/includes.php before our code runs.
echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';

$loan->renderFields($ID);

echo '<div class="d-flex gap-2 mt-3">';
echo '<button type="submit" name="save_loan" class="btn btn-primary">';
echo '<i class="fas fa-save me-1"></i>' . ($ID > 0 ? 'Guardar cambios' : 'Crear préstamo');
echo '</button>';
echo '<a href="' . Plugin::getWebDir('lagapenak') . '/front/loan.php" class="btn btn-secondary">';
echo '<i class="fas fa-arrow-left me-1"></i>Cancelar';
echo '</a>';
if ($ID > 0) {
    echo '<button type="submit" name="delete_loan" class="btn btn-danger ms-auto"
                  onclick="return confirm(\'¿Eliminar este préstamo?\')">';
    echo '<i class="fas fa-trash me-1"></i>Eliminar';
    echo '</button>';
}
echo '</div>';

echo '</form>';
echo '</div>'; // card-body
echo '</div>'; // card

// ── Assets section (only for saved loans) ───────────────────────────────────
if ($ID > 0) {

    $asset_types   = PluginLagapenakLoanItem::getAssetTypes();
    $current_items = PluginLagapenakLoanItem::getItemsForLoan($ID);

    $add_type = (isset($_GET['add_type']) && array_key_exists($_GET['add_type'], $asset_types))
        ? $_GET['add_type']
        : 'Computer';

    echo '<div class="card mt-4">';
    echo '<div class="card-header d-flex justify-content-between align-items-center">';
    echo '<h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Activos del préstamo</h5>';
    echo '<span class="badge bg-primary">' . count($current_items) . ' activo(s)</span>';
    echo '</div>';
    echo '<div class="card-body">';

    // ── Current items table ───────────────────────────────────────────
    echo '<table class="table table-sm table-bordered table-hover mb-3">';
    echo '<thead class="table-light"><tr>';
    echo '<th style="width:36px">#</th>';
    echo '<th>Tipo</th><th>Activo</th>';
    echo '<th>Entrega</th>';
    echo '<th>Devolución</th>';
    echo '<th>Estado</th>';
    echo '<th style="width:70px"></th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (empty($current_items)) {
        echo '<tr><td colspan="7" class="text-center text-muted py-3">';
        echo '<i class="fas fa-inbox me-1"></i>No hay activos añadidos todavía.';
        echo '</td></tr>';
    } else {
        foreach ($current_items as $item) {
            $item_name = PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id']);
            $base_url  = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID;

            echo '<tr>';
            echo '<td class="text-muted small align-middle">' . $item['id'] . '</td>';
            echo '<td class="align-middle">' . PluginLagapenakLoanItem::getTypeLabel($item['itemtype']) . '</td>';
            echo '<td class="align-middle"><strong>' . htmlspecialchars($item_name) . '</strong></td>';
            echo '<td class="text-nowrap align-middle small">' . (Html::convDateTime($item['date_checkout']) ?: '<span class="text-muted">—</span>') . '</td>';
            echo '<td class="text-nowrap align-middle small">' . (Html::convDateTime($item['date_checkin'])  ?: '<span class="text-muted">—</span>') . '</td>';

            // ── Status quick-buttons (one form, buttons set item_status value) ──
            echo '<td class="align-middle">';
            echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
            echo '<input type="hidden" name="update_loanitem" value="1">';
            echo '<input type="hidden" name="loanitem_id" value="' . $item['id'] . '">';
            echo '<input type="hidden" name="item_date_checkout" value="' . htmlspecialchars($item['date_checkout'] ?? '') . '">';
            echo '<input type="hidden" name="item_date_checkin"  value="' . htmlspecialchars($item['date_checkin']  ?? '') . '">';
            echo '<div class="btn-group btn-group-sm" role="group">';
            foreach ([
                PluginLagapenakLoanItem::STATUS_PENDING   => ['Pend.',  'warning', 'text-dark'],
                PluginLagapenakLoanItem::STATUS_DELIVERED => ['Entg.',  'primary',  ''],
                PluginLagapenakLoanItem::STATUS_RETURNED  => ['Dev.',   'success',  ''],
                PluginLagapenakLoanItem::STATUS_INCIDENT  => ['Inc.',   'danger',   ''],
            ] as $val => [$lbl, $color, $extra]) {
                $active = ((int) $item['status'] === $val);
                $cls    = $active
                    ? 'btn-' . $color . ($extra ? ' ' . $extra : '')
                    : 'btn-outline-' . $color;
                echo '<button type="submit" name="item_status" value="' . $val . '"'
                   . ' class="btn btn-sm ' . $cls . '">' . $lbl . '</button>';
            }
            echo '</div>';
            echo '</form>';
            echo '</td>';

            // ── Actions: edit dates + remove ──────────────────────────
            echo '<td class="align-middle">';
            $is_editing = ($edit_item === (int) $item['id']);
            echo '<a href="' . $base_url . ($is_editing ? '' : '&edit_item=' . $item['id']) . '"'
               . ' class="btn btn-sm ' . ($is_editing ? 'btn-secondary' : 'btn-outline-secondary') . '" title="Editar fechas">'
               . '<i class="fas fa-calendar-alt"></i></a>';
            echo ' ';
            echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
            echo '<input type="hidden" name="loanitem_id" value="' . $item['id'] . '">';
            echo '<button type="submit" name="remove_loanitem" class="btn btn-sm btn-outline-danger"'
               . ' title="Quitar" onclick="return confirm(\'¿Quitar este activo?\')">'
               . '<i class="fas fa-times"></i></button>';
            echo '</form>';
            echo '</td>';

            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    // ── Date edit panel (shown below table when ?edit_item=ID) ────────
    if ($edit_item > 0) {
        $editing_item = null;
        foreach ($current_items as $ci) {
            if ((int) $ci['id'] === $edit_item) { $editing_item = $ci; break; }
        }
        if ($editing_item) {
            $ei_label   = htmlspecialchars(PluginLagapenakLoanItem::getItemName($editing_item['itemtype'], $editing_item['items_id']));
            $base_url   = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID;
            echo '<div class="card border-primary mb-3">';
            echo '<div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">';
            echo '<span><i class="fas fa-calendar-alt me-2"></i>Editar fechas: <strong>' . $ei_label . '</strong></span>';
            echo '<a href="' . $base_url . '" class="btn btn-sm btn-outline-light"><i class="fas fa-times"></i> Cerrar</a>';
            echo '</div>';
            echo '<div class="card-body">';
            echo '<form method="POST" action="' . $base_url . '" class="row g-3 align-items-end">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
            echo '<input type="hidden" name="update_loanitem" value="1">';
            echo '<input type="hidden" name="loanitem_id" value="' . $editing_item['id'] . '">';
            echo '<input type="hidden" name="item_status" value="' . $editing_item['status'] . '">';
            echo '<div class="col-md-4"><label class="form-label fw-bold">Fecha entrega</label>';
            Html::showDateTimeField('item_date_checkout', ['value' => $editing_item['date_checkout'] ?? '', 'maybeempty' => true]);
            echo '</div>';
            echo '<div class="col-md-4"><label class="form-label fw-bold">Fecha devolución</label>';
            Html::showDateTimeField('item_date_checkin', ['value' => $editing_item['date_checkin'] ?? '', 'maybeempty' => true]);
            echo '</div>';
            echo '<div class="col-md-4 d-flex align-items-end">';
            echo '<button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Guardar fechas</button>';
            echo '</div>';
            echo '</form>';
            echo '</div></div>';
        }
    }

    // ── Add item ──────────────────────────────────────────────────────
    echo '<hr>';
    echo '<h6 class="mb-3"><i class="fas fa-plus-circle me-1"></i>Añadir activo</h6>';

    // Dates pre-filled from loan (for defaulting per-item dates)
    $loan_data       = $loan->fields;
    $default_checkout = $loan_data['fecha_inicio'] ?? '';
    $default_checkin  = $loan_data['fecha_fin']    ?? '';

    // Type filter (GET — reloads page)
    echo '<div class="d-flex flex-wrap gap-3 align-items-end mb-2">';
    echo '<div>';
    echo '<label class="form-label mb-1 fw-bold">Tipo de activo</label>';
    echo '<form id="type_filter_form" method="GET" action="">';
    echo '<input type="hidden" name="id" value="' . $ID . '">';
    echo '<select name="add_type" class="form-select"
                  onchange="document.getElementById(\'type_filter_form\').submit()"
                  style="min-width:170px">';
    foreach ($asset_types as $type => $lbl) {
        $sel = ($add_type === $type) ? 'selected' : '';
        echo '<option value="' . $type . '" ' . $sel . '>' . $lbl . '</option>';
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    // Add item POST form
    $reservable_ids = PluginLagapenakLoanItem::getReservableIds($add_type);
    echo '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID . '">';
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
    echo '<input type="hidden" name="loans_id" value="' . $ID . '">';
    echo '<input type="hidden" name="itemtype" value="' . htmlspecialchars($add_type) . '">';

    echo '<div class="row g-2 align-items-end">';

    // Asset selector
    echo '<div class="col-md-4">';
    echo '<label class="form-label fw-bold">Activo</label>';
    if ($reservable_ids === null || count($reservable_ids) > 0) {
        $opts = [
            'name'                => 'items_id',
            'display_emptychoice' => true,
            'entity'              => $_SESSION['glpiactive_entity'] ?? 0,
        ];
        if ($reservable_ids !== null) {
            $opts['condition'] = ['id' => $reservable_ids];
        }
        Dropdown::show($add_type, $opts);
        echo '<small class="text-muted">Solo se muestran activos con "Autorizar reservas" activado.</small>';
    } else {
        echo '<div class="alert alert-warning py-2 mb-0">';
        echo '<i class="fas fa-exclamation-triangle me-1"></i>';
        echo 'No hay activos de tipo <strong>' . PluginLagapenakLoanItem::getTypeLabel($add_type) . '</strong> autorizados para préstamo.';
        echo '<br><small>Para autorizar: abre el activo → pestaña <em>Reservas</em> → activa <em>Autorizar las reservas</em>.</small>';
        echo '</div>';
    }
    echo '</div>';

    // Per-item checkout date (defaults to loan fecha_inicio)
    echo '<div class="col-md-3">';
    echo '<label class="form-label fw-bold">Fecha entrega</label>';
    Html::showDateTimeField('item_date_checkout', [
        'value'      => $default_checkout,
        'maybeempty' => true,
    ]);
    echo '</div>';

    // Per-item checkin date (defaults to loan fecha_fin)
    echo '<div class="col-md-3">';
    echo '<label class="form-label fw-bold">Fecha devolución</label>';
    Html::showDateTimeField('item_date_checkin', [
        'value'      => $default_checkin,
        'maybeempty' => true,
    ]);
    echo '</div>';

    // Add button
    echo '<div class="col-md-2">';
    if ($reservable_ids === null || count($reservable_ids) > 0) {
        echo '<button type="submit" name="add_loanitem" class="btn btn-success w-100">';
        echo '<i class="fas fa-plus me-1"></i>Añadir</button>';
    }
    echo '</div>';

    echo '</div>'; // row
    echo '</form>';

    echo '</div>'; // card-body
    echo '</div>'; // card

} else {
    echo '<div class="alert alert-info mt-3">';
    echo '<i class="fas fa-info-circle me-2"></i>';
    echo 'Guarda el préstamo primero para poder añadir activos.';
    echo '</div>';
}

echo '</div>'; // container-fluid

Html::footer();
