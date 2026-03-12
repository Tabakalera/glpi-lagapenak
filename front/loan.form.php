<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// NOTE: CSRF is validated automatically by GLPI's inc/includes.php for all POST requests.
// Do NOT call Session::checkCSRF() here — it would fail because the token is already
// consumed by the time our handler code runs.

$loan          = new PluginLagapenakLoan();
$ID            = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$form_error    = '';
$can_supervise = PluginLagapenakLoan::canSupervise();

// Gate: creating a new loan requires CREATE right; viewing an existing one requires READ.
if ($ID === 0 && !PluginLagapenakLoan::canCreate()) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}
if ($ID > 0 && !PluginLagapenakLoan::canView() && !$can_supervise) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// ── Handle loan CRUD ────────────────────────────────────────────────────────
if (isset($_POST['save_loan'])) {
    $is_new = ($ID == 0);
    if (!$is_new && !$can_supervise) {
        Html::back(); // only supervisor can edit existing loans
    } else {
        if (empty(trim($_POST['name'] ?? ''))) {
            $form_error = 'El campo Nombre / Referencia es obligatorio.';
        } elseif (!isset($_POST['entities_id']) || $_POST['entities_id'] === '') {
            $form_error = 'La Entidad es obligatoria.';
        } elseif (empty($_POST['users_id_destinatario']) || (int)$_POST['users_id_destinatario'] === 0) {
            $form_error = 'El Destinatario es obligatorio.';
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
}

if (isset($_POST['delete_loan'])) {
    if (!$can_supervise) { Html::back(); }
    $loan->delete(['id' => $ID]);
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// ── Handle loan item CRUD ────────────────────────────────────────────────────
if (isset($_POST['add_loanitem'])) {
    $li_loans_id = (int) ($_POST['loans_id'] ?? 0);
    $li_loan     = new PluginLagapenakLoan();
    $li_loan->getFromDB($li_loans_id);
    $li_status = (int) ($li_loan->fields['status'] ?? 0);

    // Supervisor always allowed; the loan's own requester can add items while still Pendiente
    $li_is_owner = ((int) ($li_loan->fields['users_id'] ?? 0) === (int) ($_SESSION['glpiID'] ?? -1));
    $can_add = $can_supervise ||
               ($li_status === PluginLagapenakLoan::STATUS_PENDING && $li_is_owner);
    if (!$can_add) { Html::back(); }

    $li_itemtype = $_POST['itemtype'] ?? '';
    $li_items_id = (int) ($_POST['items_id'] ?? 0);

    // Use the individual item dates if filled; fall back to the loan's period
    $li_checkout = !empty($_POST['item_date_checkout']) ? $_POST['item_date_checkout'] : ($li_loan->fields['fecha_inicio'] ?? null);
    $li_checkin  = !empty($_POST['item_date_checkin'])  ? $_POST['item_date_checkin']  : ($li_loan->fields['fecha_fin']    ?? null);

    $conflicts = PluginLagapenakLoanItem::getConflictingLoans(
        $li_itemtype, $li_items_id, $li_loans_id,
        $li_checkout, $li_checkin
    );

    if (!empty($conflicts)) {
        $conflict_list = implode(', ', array_map(function($c) {
            // Show effective item dates (item-level if set, else loan dates)
            $fi = $c['eff_start'] ? Html::convDateTime($c['eff_start']) : '—';
            $ff = $c['eff_end']   ? Html::convDateTime($c['eff_end'])   : '—';
            return '<strong>#' . $c['id'] . ' ' . htmlspecialchars($c['name']) . '</strong>'
                 . ' (' . PluginLagapenakLoan::getStatusName($c['status']) . ')'
                 . ' · ' . $fi . ' → ' . $ff;
        }, $conflicts));
        Session::addMessageAfterRedirect(
            'El activo ya está reservado en otro préstamo con fechas solapadas: ' . $conflict_list,
            false,
            ERROR
        );
    } else {
        $added = PluginLagapenakLoanItem::addItem(
            $li_loans_id, $li_itemtype, $li_items_id,
            $_POST['item_date_checkout'] ?? null,
            $_POST['item_date_checkin']  ?? null
        );
        if ($added === false) {
            Session::addMessageAfterRedirect(
                'Este activo ya está añadido a este préstamo. Para cambiar sus fechas usa el botón ✏️ de edición.',
                false,
                WARNING
            );
        } else {
            PluginLagapenakLoanItem::syncLoanStatus($li_loans_id);
        }
    }
    Html::back();
}

if (isset($_POST['remove_loanitem'])) {
    $rm_loan   = new PluginLagapenakLoan();
    $rm_loan->getFromDB($ID);
    $rm_status = (int) ($rm_loan->fields['status'] ?? 0);

    $rm_is_owner = ((int) ($rm_loan->fields['users_id'] ?? 0) === (int) ($_SESSION['glpiID'] ?? -1));
    $can_remove = $can_supervise ||
                  ($rm_status === PluginLagapenakLoan::STATUS_PENDING && $rm_is_owner);
    if (!$can_remove) { Html::back(); }

    PluginLagapenakLoanItem::removeItem((int) ($_POST['loanitem_id'] ?? 0));
    PluginLagapenakLoanItem::syncLoanStatus($ID);
    Html::back();
}

if (isset($_POST['update_loanitem'])) {
    if (!$can_supervise) { Html::back(); }
    PluginLagapenakLoanItem::updateItem(
        (int) ($_POST['loanitem_id'] ?? 0),
        (int) ($_POST['item_status'] ?? 1),
        $_POST['item_date_checkout'] ?? '',
        $_POST['item_date_checkin']  ?? ''
    );
    PluginLagapenakLoanItem::syncLoanStatus($ID);
    Html::redirect(htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID);
}

if (isset($_POST['bulk_update_items'])) {
    if (!$can_supervise) { Html::back(); }
    $bulk_status   = (int) ($_POST['bulk_status'] ?? 0);
    $bulk_loan     = new PluginLagapenakLoan();
    $bulk_loan->getFromDB($ID);
    $default_checkout = $bulk_loan->fields['fecha_inicio'] ?? '';
    $default_checkin  = $bulk_loan->fields['fecha_fin']    ?? '';
    foreach (PluginLagapenakLoanItem::getItemsForLoan($ID) as $item) {
        $current = (int) $item['status'];
        if ($bulk_status === PluginLagapenakLoanItem::STATUS_DELIVERED
            && $current === PluginLagapenakLoanItem::STATUS_PENDING) {
            PluginLagapenakLoanItem::updateItem(
                $item['id'], $bulk_status,
                $item['date_checkout'] ?: $default_checkout,
                $item['date_checkin']  ?: $default_checkin
            );
        } elseif ($bulk_status === PluginLagapenakLoanItem::STATUS_RETURNED
            && $current === PluginLagapenakLoanItem::STATUS_DELIVERED) {
            PluginLagapenakLoanItem::updateItem(
                $item['id'], $bulk_status,
                $item['date_checkout'] ?: $default_checkout,
                $item['date_checkin']  ?: $default_checkin
            );
        }
    }
    PluginLagapenakLoanItem::syncLoanStatus($ID);
    Html::redirect(htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID);
}

// ── Load existing record ────────────────────────────────────────────────────
if ($ID > 0) {
    if (!$loan->getFromDB($ID)) {
        Html::displayRightError();
        Html::footer();
        exit;
    }
    // Permission check: supervisors see all loans; non-supervisors only their own.
    // We do NOT call canView() here because Self-Service profiles may lack the READ
    // right while still being allowed to see their own loans.
    if (!$can_supervise
        && (int)($loan->fields['users_id'] ?? 0) !== (int)($_SESSION['glpiID'] ?? 0)
    ) {
        Html::displayRightError();
        Html::footer();
        exit;
    }
}

$title     = $ID > 0 ? 'Préstamo #' . $ID : 'Nueva solicitud';
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

// ── Main card ────────────────────────────────────────────────────────────────
$card_title = $can_supervise
    ? ($ID > 0 ? 'Editar préstamo #' . $ID : 'Nuevo préstamo')
    : ($ID > 0 ? 'Mi solicitud #' . $ID   : 'Nueva solicitud');

echo '<div class="card">';
echo '<div class="card-header"><h4 class="mb-0">' . htmlspecialchars($card_title) . '</h4></div>';
echo '<div class="card-body">';

if ($form_error) {
    echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>' . htmlspecialchars($form_error) . '</div>';
}

if ($can_supervise || $ID == 0) {
    // ── Editable form ────────────────────────────────────────────────────────
    echo '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF'])
         . ($ID > 0 ? '?id=' . $ID : '') . '">';
    echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';

    $loan->renderFields($ID);

    echo '<div class="d-flex gap-2 mt-3">';
    echo '<button type="submit" name="save_loan" class="btn btn-primary">';
    echo '<i class="fas fa-save me-1"></i>' . ($ID > 0 ? 'Guardar cambios' : 'Enviar solicitud');
    echo '</button>';
    echo '<a href="' . Plugin::getWebDir('lagapenak') . '/front/loan.php" class="btn btn-secondary">';
    echo '<i class="fas fa-arrow-left me-1"></i>Volver';
    echo '</a>';
    $_lf_uid     = (int)($_SESSION['glpiID'] ?? 0);
    $_lf_is_req  = (int)($loan->fields['users_id'] ?? 0) === $_lf_uid;
    $_lf_is_dest = (int)($loan->fields['users_id_destinatario'] ?? 0) === $_lf_uid;
    $can_albaran = $can_supervise
        || (PluginLagapenakLoan::hasPluginRight('plugin_lagapenak_albaran', READ)
            && ($_lf_is_req || $_lf_is_dest));
    if ($ID > 0 && $can_albaran) {
        $albaran_url = Plugin::getWebDir('lagapenak', true) . '/front/albaran.php?id=' . $ID;
        echo '<a href="' . $albaran_url . '" class="btn btn-outline-primary" target="_blank">';
        echo '<i class="fas fa-file-signature me-1"></i>Albarán</a>';
    }
    if ($ID > 0 && $can_supervise) {
        echo '<button type="submit" name="delete_loan" class="btn btn-danger ms-auto"
                      onclick="return confirm(\'¿Eliminar este préstamo?\')">';
        echo '<i class="fas fa-trash me-1"></i>Eliminar';
        echo '</button>';
    }
    echo '</div>';
    echo '</form>';

} else {
    // ── Read-only view (requester with existing loan) ─────────────────────────
    echo '<div class="alert alert-info py-2 mb-3">';
    echo '<i class="fas fa-info-circle me-2"></i>';
    echo 'Su solicitud ha sido registrada. El supervisor gestionará los activos y el estado del préstamo.';
    echo '</div>';
    $loan->renderReadOnly($ID);
    echo '<div class="mt-3">';
    echo '<a href="' . Plugin::getWebDir('lagapenak') . '/front/loan.php" class="btn btn-secondary">';
    echo '<i class="fas fa-arrow-left me-1"></i>Volver';
    echo '</a>';
    echo '</div>';
}

echo '</div>'; // card-body
echo '</div>'; // card

// ── Assets section (only for saved loans) ───────────────────────────────────
if ($ID > 0) {

    $asset_types   = PluginLagapenakLoanItem::getAssetTypes();
    $current_items = PluginLagapenakLoanItem::getItemsForLoan($ID);
    $loan_status   = (int) ($loan->fields['status'] ?? PluginLagapenakLoan::STATUS_PENDING);

    // The loan's own requester can add/remove items while the loan is still Pendiente
    $is_loan_owner = ((int) ($loan->fields['users_id'] ?? 0) === (int) ($_SESSION['glpiID'] ?? -1));
    $is_requester_can_modify = !$can_supervise &&
                               ($loan_status === PluginLagapenakLoan::STATUS_PENDING) &&
                               $is_loan_owner;

    $show_actions_col = $can_supervise || $is_requester_can_modify;

    $add_type = (isset($_GET['add_type']) && array_key_exists($_GET['add_type'], $asset_types))
        ? $_GET['add_type']
        : 'Computer';

    $has_pending   = $can_supervise && count(array_filter($current_items, fn($i) => (int)$i['status'] === PluginLagapenakLoanItem::STATUS_PENDING))   > 0;
    $has_delivered = $can_supervise && count(array_filter($current_items, fn($i) => (int)$i['status'] === PluginLagapenakLoanItem::STATUS_DELIVERED)) > 0;
    $base_url      = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID;

    echo '<div class="card mt-4">';
    echo '<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">';
    echo '<h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Activos del préstamo</h5>';
    echo '<div class="d-flex align-items-center gap-2">';
    echo '<span class="badge bg-primary">' . count($current_items) . ' activo(s)</span>';
    if ($has_pending) {
        echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
        echo '<input type="hidden" name="bulk_update_items" value="1">';
        echo '<input type="hidden" name="bulk_status" value="' . PluginLagapenakLoanItem::STATUS_DELIVERED . '">';
        echo '<button type="submit" class="btn btn-sm btn-success" onclick="return confirm(\'¿Marcar todos los activos pendientes como Entregado?\')">';
        echo '<i class="fas fa-arrow-right me-1"></i>Todo entregado</button>';
        echo '</form>';
    }
    if ($has_delivered) {
        echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
        echo '<input type="hidden" name="bulk_update_items" value="1">';
        echo '<input type="hidden" name="bulk_status" value="' . PluginLagapenakLoanItem::STATUS_RETURNED . '">';
        echo '<button type="submit" class="btn btn-sm btn-primary" onclick="return confirm(\'¿Marcar todos los activos entregados como Devuelto?\')">';
        echo '<i class="fas fa-arrow-left me-1"></i>Todo devuelto</button>';
        echo '</form>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="card-body">';

    // ── Current items table ───────────────────────────────────────────────────
    $colspan = $show_actions_col ? 7 : 6;
    echo '<table class="table table-sm table-bordered table-hover mb-3">';
    echo '<thead class="table-light"><tr>';
    echo '<th style="width:36px">#</th>';
    echo '<th>Tipo</th><th>Activo</th>';
    echo '<th>Entrega</th>';
    echo '<th>Devolución</th>';
    echo '<th>Estado</th>';
    if ($show_actions_col) {
        echo '<th style="width:140px"></th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';

    if (empty($current_items)) {
        echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-3">';
        echo '<i class="fas fa-inbox me-1"></i>No hay activos asignados todavía.';
        echo '</td></tr>';
    } else {
        foreach ($current_items as $item) {
            $item_name  = PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id']);
            $item_st    = (int) $item['status'];
            $base_url   = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID;

            echo '<tr>';
            echo '<td class="text-muted small align-middle">' . $item['id'] . '</td>';
            echo '<td class="align-middle">' . PluginLagapenakLoanItem::getTypeLabel($item['itemtype']) . '</td>';
            echo '<td class="align-middle"><strong>' . htmlspecialchars($item_name) . '</strong></td>';
            echo '<td class="text-nowrap align-middle small">' . (Html::convDateTime($item['date_checkout']) ?: '<span class="text-muted">—</span>') . '</td>';
            echo '<td class="text-nowrap align-middle small">' . (Html::convDateTime($item['date_checkin'])  ?: '<span class="text-muted">—</span>') . '</td>';

            // ── Status column (badge only) ────────────────────────────────────
            echo '<td class="align-middle">';
            echo PluginLagapenakLoanItem::getStatusBadge($item_st);
            echo '</td>';

            // ── Actions column ─────────────────────────────────────────────────
            if ($can_supervise) {
                echo '<td class="align-middle text-nowrap">';

                // Contextual status-change button
                if ($item_st === PluginLagapenakLoanItem::STATUS_PENDING) {
                    echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
                    echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
                    echo '<input type="hidden" name="update_loanitem" value="1">';
                    echo '<input type="hidden" name="loanitem_id" value="' . $item['id'] . '">';
                    echo '<input type="hidden" name="item_date_checkout" value="' . htmlspecialchars($item['date_checkout'] ?? '') . '">';
                    echo '<input type="hidden" name="item_date_checkin"  value="' . htmlspecialchars($item['date_checkin']  ?? '') . '">';
                    echo '<button type="submit" name="item_status" value="' . PluginLagapenakLoanItem::STATUS_DELIVERED . '"'
                       . ' class="btn btn-sm btn-success" title="Marcar como Entregado">'
                       . '<i class="fas fa-arrow-right"></i></button>';
                    echo '</form> ';
                } elseif ($item_st === PluginLagapenakLoanItem::STATUS_DELIVERED) {
                    echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
                    echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
                    echo '<input type="hidden" name="update_loanitem" value="1">';
                    echo '<input type="hidden" name="loanitem_id" value="' . $item['id'] . '">';
                    echo '<input type="hidden" name="item_date_checkout" value="' . htmlspecialchars($item['date_checkout'] ?? '') . '">';
                    echo '<input type="hidden" name="item_date_checkin"  value="' . htmlspecialchars($item['date_checkin']  ?? '') . '">';
                    echo '<button type="submit" name="item_status" value="' . PluginLagapenakLoanItem::STATUS_RETURNED . '"'
                       . ' class="btn btn-sm btn-primary" title="Marcar como Devuelto">'
                       . '<i class="fas fa-arrow-left"></i></button>';
                    echo ' <button type="submit" name="item_status" value="' . PluginLagapenakLoanItem::STATUS_INCIDENT . '"'
                       . ' class="btn btn-sm btn-outline-danger" title="Registrar incidencia">'
                       . '<i class="fas fa-exclamation-triangle"></i></button>';
                    echo '</form> ';
                }

                // Calendar (edit dates) + remove
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
            } elseif ($is_requester_can_modify) {
                // Requester can remove items while loan is Pendiente
                echo '<td class="align-middle">';
                echo '<form method="POST" action="' . $base_url . '" class="d-inline">';
                echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
                echo '<input type="hidden" name="loanitem_id" value="' . $item['id'] . '">';
                echo '<button type="submit" name="remove_loanitem" class="btn btn-sm btn-outline-danger"'
                   . ' title="Quitar" onclick="return confirm(\'¿Quitar este activo?\')">'
                   . '<i class="fas fa-times"></i></button>';
                echo '</form>';
                echo '</td>';
            }

            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    // ── Date edit panel (supervisor only) ─────────────────────────────────────
    if ($can_supervise && $edit_item > 0) {
        $editing_item = null;
        foreach ($current_items as $ci) {
            if ((int) $ci['id'] === $edit_item) { $editing_item = $ci; break; }
        }
        if ($editing_item) {
            $ei_label = htmlspecialchars(PluginLagapenakLoanItem::getItemName($editing_item['itemtype'], $editing_item['items_id']));
            $base_url = htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID;
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

    // ── Add item (supervisor, or requester while loan is Pendiente) ────────────
    if ($can_supervise || $is_requester_can_modify) {
        echo '<hr>';
        echo '<h6 class="mb-3"><i class="fas fa-plus-circle me-1"></i>Añadir activo</h6>';

        $default_checkout = $loan->fields['fecha_inicio'] ?? '';
        $default_checkin  = $loan->fields['fecha_fin']    ?? '';

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

        $reservable_ids = PluginLagapenakLoanItem::getReservableIds($add_type);
        $loan_entity    = (int)($loan->fields['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
        echo '<form method="POST" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . $ID . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
        echo '<input type="hidden" name="loans_id" value="' . $ID . '">';
        echo '<input type="hidden" name="itemtype" value="' . htmlspecialchars($add_type) . '">';

        if ($can_supervise) {
            // Supervisor: full row with date fields
            echo '<div class="row g-2 align-items-end">';

            echo '<div class="col-md-4">';
            echo '<label class="form-label fw-bold">Activo</label>';
            if ($reservable_ids === null || count($reservable_ids) > 0) {
                $opts = ['name' => 'items_id', 'display_emptychoice' => true, 'entity' => $loan_entity];
                if ($reservable_ids !== null) { $opts['condition'] = ['id' => $reservable_ids]; }
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

            echo '<div class="col-md-3"><label class="form-label fw-bold">Fecha entrega</label>';
            Html::showDateTimeField('item_date_checkout', ['value' => $default_checkout, 'maybeempty' => true]);
            echo '</div>';

            echo '<div class="col-md-3"><label class="form-label fw-bold">Fecha devolución</label>';
            Html::showDateTimeField('item_date_checkin', ['value' => $default_checkin, 'maybeempty' => true]);
            echo '</div>';

            echo '<div class="col-md-2">';
            if ($reservable_ids === null || count($reservable_ids) > 0) {
                echo '<button type="submit" name="add_loanitem" class="btn btn-success w-100">';
                echo '<i class="fas fa-plus me-1"></i>Añadir</button>';
            }
            echo '</div>';

            echo '</div>'; // row
        } else {
            // Requester: simplified — only asset dropdown + add button (no dates)
            echo '<div class="row g-2 align-items-end">';

            echo '<div class="col-md-6">';
            echo '<label class="form-label fw-bold">Activo</label>';
            if ($reservable_ids === null || count($reservable_ids) > 0) {
                $opts = ['name' => 'items_id', 'display_emptychoice' => true, 'entity' => $loan_entity];
                if ($reservable_ids !== null) { $opts['condition'] = ['id' => $reservable_ids]; }
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

            echo '<div class="col-md-2">';
            if ($reservable_ids === null || count($reservable_ids) > 0) {
                echo '<button type="submit" name="add_loanitem" class="btn btn-success w-100">';
                echo '<i class="fas fa-plus me-1"></i>Añadir</button>';
            }
            echo '</div>';

            echo '</div>'; // row
        }

        echo '</form>';
    }

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
