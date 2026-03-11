<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$can_supervise = PluginLagapenakLoan::canSupervise();
$tab           = isset($_GET['tab']) ? $_GET['tab'] : 'prestamos';
// Non-supervisors cannot access the activos tab
if ($tab === 'activos' && !$can_supervise) {
    $tab = 'prestamos';
}
$plugin_web = Plugin::getWebDir('lagapenak', true);
$avail_url  = $plugin_web . '/ajax/availability.php';
$loan_form  = $plugin_web . '/front/loan.form.php';
$cal_url    = $plugin_web . '/front/calendar.php';

$is_helpdesk = (Session::getCurrentInterface() === 'helpdesk');
if ($is_helpdesk) {
    Html::helpHeader('Lagapenak - Préstamos');
} else {
    Html::header('Lagapenak - Préstamos', $_SERVER['PHP_SELF'], 'tools', 'PluginLagapenakLoan');
}

// Firefox fix: GLPI calls form.submit() programmatically (not via a submit event)
// when its ResultsView finds no pre-rendered results. Programmatic form.submit()
// bypasses all event listeners, so the only reliable intercept is overriding
// HTMLFormElement.prototype.submit before any GLPI module scripts run.
echo '<script>
if(typeof window.hotkeys==="undefined"){window.hotkeys=function(){};window.hotkeys.unbind=function(){};}
if(window.location.search.indexOf("tab=disponibilidad")!==-1){
    window.initFluidSearch=function(){};
    var _origSubmit=HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit=function(){
        if(this.name==="searchformPluginLagapenakLoan")return;
        return _origSubmit.apply(this,arguments);
    };
    document.addEventListener("submit",function(e){
        if(e.target&&e.target.name==="searchformPluginLagapenakLoan"){
            e.preventDefault();e.stopImmediatePropagation();
        }
    },true);
}
</script>';

// ── Summary banner (native GLPI card component) ────────────────────────────────
{
    global $DB;
    $uid        = (int) Session::getLoginUserID();
    $entity_sql = getEntitiesRestrictRequest('AND', 'glpi_plugin_lagapenak_loans', 'entities_id', '', false);
    $user_sql   = $can_supervise ? '' : " AND `users_id` = {$uid}";
    $s1 = PluginLagapenakLoan::STATUS_PENDING;
    $s2 = PluginLagapenakLoan::STATUS_DELIVERED;
    $s3 = PluginLagapenakLoan::STATUS_RETURNED;
    $s4 = PluginLagapenakLoan::STATUS_CANCELLED;
    $s5 = PluginLagapenakLoan::STATUS_IN_PROGRESS;
    $now = $DB->escape(date('Y-m-d H:i:s'));

    $qc = function(string $extra) use ($DB, $entity_sql, $user_sql): int {
        $r = $DB->query("SELECT COUNT(*) AS cpt FROM `glpi_plugin_lagapenak_loans` WHERE 1=1 {$extra} {$user_sql} {$entity_sql}");
        if ($r === false) return 0;
        return (int) ($DB->fetchAssoc($r)['cpt'] ?? 0);
    };
    $r_mine = $DB->query("SELECT COUNT(*) AS cpt FROM `glpi_plugin_lagapenak_loans` WHERE `status` != {$s4} AND `users_id` = {$uid} {$entity_sql}");

    $cnt_total     = $qc(" AND `status` != {$s4}");
    $cnt_mine      = ($r_mine !== false) ? (int) ($DB->fetchAssoc($r_mine)['cpt'] ?? 0) : 0;
    $cnt_active    = $qc(" AND `status` IN ({$s1},{$s5})");
    $cnt_delivered = $qc(" AND `status` = {$s2}");
    $cnt_returned  = $qc(" AND `status` = {$s3}");
    $cnt_overdue   = $qc(" AND `status` IN ({$s1},{$s5},{$s2}) AND `fecha_fin` IS NOT NULL AND `fecha_fin` < '{$now}'");

    $b = $plugin_web . '/front/loan.php?tab=prestamos&search=Search&start=0';
    $f = function(array $criteria) use ($b): string {
        $qs = '';
        foreach ($criteria as $i => $c) {
            foreach ($c as $k => $v) {
                $qs .= '&criteria[' . $i . '][' . $k . ']=' . urlencode((string)$v);
            }
        }
        return $b . $qs;
    };

    $url_all       = $b;
    $url_mine      = $f([['link' => 'AND', 'field' => 4, 'searchtype' => 'equals', 'value' => $uid]]);
    $url_active    = $f([
        ['link' => 'AND', 'field' => 3, 'searchtype' => 'equals', 'value' => $s1],
        ['link' => 'OR',  'field' => 3, 'searchtype' => 'equals', 'value' => $s5],
    ]);
    $url_delivered = $f([['link' => 'AND', 'field' => 3, 'searchtype' => 'equals', 'value' => $s2]]);
    $url_returned  = $f([['link' => 'AND', 'field' => 3, 'searchtype' => 'equals', 'value' => $s3]]);
    $url_overdue   = $f([
        ['link' => 'AND', 'field' => 3,  'searchtype' => 'notequals', 'value' => $s2],
        ['link' => 'AND', 'field' => 3,  'searchtype' => 'notequals', 'value' => $s3],
        ['link' => 'AND', 'field' => 3,  'searchtype' => 'notequals', 'value' => $s4],
        ['link' => 'AND', 'field' => 7,  'searchtype' => 'lessthan',  'value' => date('Y-m-d H:i:s')],
    ]);

    // Same colors as GLPI's native mini_tickets dashboard
    $tile_defs = [
        [$cnt_total,     'Todas',                'fas fa-list',           '#ffd957', $url_all],
        [$cnt_active,    'Pendiente / En curso', 'fas fa-hourglass-half', '#ffcb7d', $url_active],
        [$cnt_delivered, 'Entregados',           'fas fa-truck',          '#6fd169', $url_delivered],
        [$cnt_returned,  'Devueltos',            'fas fa-check-circle',   '#d7d7d7', $url_returned],
    ];
    // "Mis préstamos" only makes sense for supervisors who can see all loans
    if ($can_supervise) {
        array_splice($tile_defs, 1, 0, [[$cnt_mine, 'Mis préstamos', 'fas fa-user', '#6298d5', $url_mine]]);
    }
    if ($cnt_overdue > 0 || $can_supervise) {
        $tile_defs[] = [$cnt_overdue, 'Vencidos', 'fas fa-exclamation-triangle', '#e74c3c', $url_overdue];
    }

    // Render summary tiles — same visual style as GLPI's mini_ticket dashboard tiles
    echo '<div class="d-none d-md-flex flex-wrap mb-2" style="gap:4px;padding:4px 0;">';
    foreach ($tile_defs as [$n, $label, $icon, $color, $url]) {
        $fg = \Toolbox::getFgColor($color);
        echo '<a href="' . htmlspecialchars($url) . '" style="'
           . 'flex:1;min-width:120px;height:106px;position:relative;'
           . 'background:' . $color . ';color:' . $fg . ';'
           . 'border-radius:3px;text-decoration:none;display:block;padding:8px;'
           . 'border:2px solid transparent;transition:border .15s,opacity .15s;"'
           . ' onmouseover="this.style.borderColor=\'' . \Toolbox::getFgColor($color, 30) . '\'"'
           . ' onmouseout="this.style.borderColor=\'transparent\'">';
        echo '<span style="font-size:2.2em;font-weight:600;line-height:1;display:block;">' . $n . '</span>';
        echo '<div style="font-size:.78rem;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
           . htmlspecialchars($label) . '</div>';
        echo '<i class="' . $icon . '" style="position:absolute;top:8px;right:8px;font-size:1.4em;opacity:.6;"></i>';
        echo '</a>';
    }
    echo '</div>';
}

// ── Tab navigation ────────────────────────────────────────────────────────────
$tabs = [
    'prestamos'      => ['icon' => 'fa-list',        'label' => 'Préstamos'],
    'disponibilidad' => ['icon' => 'fa-check-circle', 'label' => 'Disponibilidad'],
];
if ($can_supervise) {
    $tabs = [
        'prestamos'      => ['icon' => 'fa-list',        'label' => 'Préstamos'],
        'activos'        => ['icon' => 'fa-box',          'label' => 'Listado por activos'],
        'disponibilidad' => ['icon' => 'fa-check-circle', 'label' => 'Disponibilidad'],
    ];
}
echo '<ul class="nav nav-tabs px-3 mt-2 mb-0">';
foreach ($tabs as $key => $info) {
    $active = $tab === $key ? 'active' : '';
    echo '<li class="nav-item">';
    echo '<a class="nav-link ' . $active . '" href="' . $plugin_web . '/front/loan.php?tab=' . $key . '">';
    echo '<i class="fas ' . $info['icon'] . ' me-1"></i>' . $info['label'];
    echo '</a></li>';
}
// Calendar shortcut
echo '<li class="nav-item ms-auto">';
echo '<a class="nav-link text-secondary" href="' . $cal_url . '" title="Vista Calendario">';
echo '<i class="fas fa-calendar-alt me-1"></i>Calendario</a>';
echo '</li>';
echo '</ul>';

// ── TAB 1: Préstamos ─────────────────────────────────────────────────────────
if ($tab === 'prestamos') {
    Search::show('PluginLagapenakLoan');

// ── TAB 2: Listado por activos ────────────────────────────────────────────────
} elseif ($tab === 'activos') {
    Search::show('PluginLagapenakLoanItem');

// ── TAB 3: Disponibilidad ─────────────────────────────────────────────────────
// Search::show() must run to prevent GLPI's search JS from auto-navigating away.
// The search output is hidden; only our custom availability form is visible.
} elseif ($tab === 'disponibilidad') {
    echo '<div style="display:none" aria-hidden="true">';
    Search::show('PluginLagapenakLoan');
    echo '</div>';
    $default_start = date('Y-m-d') . 'T12:00';
    $default_end   = date('Y-m-d', strtotime('+1 day')) . 'T12:00';
    $asset_types   = PluginLagapenakLoanItem::getAssetTypes();
    ?>
    <div class="container-fluid mt-3">

        <!-- ── Search form ── -->
        <form id="avail-form" class="row g-2 align-items-end mb-3">
            <div class="col-auto">
                <label class="form-label mb-1 fw-bold">Desde</label>
                <input type="datetime-local" id="avail-start" class="form-control form-control-sm"
                       value="<?= $default_start ?>" required>
            </div>
            <div class="col-auto">
                <label class="form-label mb-1 fw-bold">Hasta</label>
                <input type="datetime-local" id="avail-end" class="form-control form-control-sm"
                       value="<?= $default_end ?>" required>
            </div>
            <div class="col-auto">
                <label class="form-label mb-1 fw-bold">Nombre <span class="text-muted fw-normal">(opcional)</span></label>
                <input type="text" id="avail-asset" class="form-control form-control-sm" placeholder="Filtrar por nombre…" style="min-width:180px;">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-search me-1"></i>Buscar
                </button>
            </div>
        </form>

        <div id="avail-loading" class="text-center py-4" style="display:none;">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>Buscando…
        </div>
        <div id="avail-error" class="alert alert-danger mt-2" style="display:none;"></div>

        <!-- ── Results area (shown after fetch) ── -->
        <div id="avail-results" style="display:none;">

            <!-- Secondary filters + summary bar -->
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                <span id="avail-summary" class="text-muted me-auto" style="font-size:.875rem;"></span>

                <label class="mb-0 fw-bold" style="font-size:.875rem;">Tipo:</label>
                <select id="avail-filter-tipo" class="form-select form-select-sm" style="width:auto;">
                    <option value="">Todos</option>
                    <?php foreach ($asset_types as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="mb-0 fw-bold" style="font-size:.875rem;">Estado:</label>
                <select id="avail-filter-estado" class="form-select form-select-sm" style="width:auto;">
                    <option value="">Todos</option>
                    <option value="free">Solo libres</option>
                    <option value="occupied">Solo ocupados</option>
                </select>

                <label class="mb-0 fw-bold" style="font-size:.875rem;">Por página:</label>
                <select id="avail-per-page" class="form-select form-select-sm" style="width:auto;">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="0">Todos</option>
                </select>
            </div>

            <!-- ── Bulk action bar (visible when ≥1 item selected) ── -->
            <div id="bulk-bar" class="d-flex align-items-center gap-2 flex-wrap p-2 mb-2 border rounded bg-light" style="display:none!important;">
                <span class="fw-bold text-primary" id="bulk-count"></span>
                <!-- Custom searchable loan picker -->
                <div id="bulk-loan-wrapper" style="position:relative;min-width:280px;">
                    <input type="text" id="bulk-loan-search"
                           placeholder="Buscar préstamo…"
                           autocomplete="off"
                           class="form-control form-control-sm"
                           style="color:#212529;background-color:#fff;cursor:pointer;">
                    <div id="bulk-loan-dropdown"
                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                                background:#fff;border:1px solid #ced4da;border-radius:0 0 .25rem .25rem;
                                max-height:220px;overflow-y:auto;box-shadow:0 4px 8px rgba(0,0,0,.15);">
                    </div>
                    <!-- Hidden input keeps selected loan id -->
                    <input type="hidden" id="bulk-loan-select">
                </div>
                <button id="bulk-add-btn" class="btn btn-sm btn-success" disabled>
                    <i class="fas fa-plus me-1"></i>Añadir al préstamo
                </button>
                <button id="bulk-clear-btn" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="fas fa-times me-1"></i>Deseleccionar todo
                </button>
            </div>
            <div id="bulk-result" class="mb-2" style="display:none;"></div>

            <div id="avail-empty-filter" class="text-center text-muted py-3" style="display:none;">
                Ningún activo coincide con los filtros aplicados.
            </div>

            <table class="table table-sm table-bordered table-hover" id="avail-table" style="display:none;">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px;text-align:center;">
                            <input type="checkbox" id="avail-check-all" title="Seleccionar todos los libres de esta página">
                        </th>
                        <th class="avail-sortable" data-col="type_label" style="cursor:pointer;white-space:nowrap;">
                            Tipo <span class="avail-sort-icon text-muted">⇅</span>
                        </th>
                        <th class="avail-sortable" data-col="name" style="cursor:pointer;white-space:nowrap;">
                            Activo <span class="avail-sort-icon text-muted">⇅</span>
                        </th>
                        <th class="avail-sortable" data-col="available" style="cursor:pointer;white-space:nowrap;">
                            Estado <span class="avail-sort-icon text-muted">⇅</span>
                        </th>
                        <th>Préstamos en conflicto</th>
                    </tr>
                </thead>
                <tbody id="avail-tbody"></tbody>
            </table>

            <!-- Pagination -->
            <div class="d-flex align-items-center justify-content-between mt-2" id="avail-pagination-bar">
                <span id="avail-page-info" class="text-muted" style="font-size:.875rem;"></span>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="avail-pagination"></ul>
                </nav>
            </div>
        </div>

    </div>

    <script>
    var AVAIL_URL     = <?= json_encode($avail_url) ?>;
    var LOAN_FORM_URL = <?= json_encode($loan_form) ?>;
    var BULK_ADD_URL  = <?= json_encode($plugin_web . '/ajax/bulk_add.php') ?>;
    var BULK_CSRF     = <?= json_encode(Session::getNewCSRFToken()) ?>;
    var CAN_SUPERVISE = <?= json_encode((bool)$can_supervise) ?>;
    <?php
    // Active loans (Pendiente + En curso) for bulk dropdown
    global $DB;
    $bulk_where = ['status' => [PluginLagapenakLoan::STATUS_PENDING, PluginLagapenakLoan::STATUS_IN_PROGRESS]];
    if (!$can_supervise) {
        $bulk_where['users_id'] = Session::getLoginUserID();
    }
    $bulk_loan_rows = $DB->request([
        'FROM'  => 'glpi_plugin_lagapenak_loans',
        'WHERE' => $bulk_where,
        'ORDER' => ['name ASC'],
    ]);
    $bulk_loans = [];
    foreach ($bulk_loan_rows as $blr) {
        $bulk_loans[] = [
            'id'    => $blr['id'],
            'label' => '#' . $blr['id'] . ' — ' . $blr['name']
                     . ' (' . PluginLagapenakLoan::getStatusName($blr['status']) . ')',
        ];
    }
    ?>
    var BULK_LOANS = <?= json_encode($bulk_loans) ?>;

    // State
    var avAllRows      = [];   // all rows from server
    var avFiltered     = [];   // after client-side filters
    var avPage         = 1;
    var avPerPage      = 20;
    var avSortCol      = 'available'; // default: occupied first
    var avSortDir      = 1;           // 1=asc, -1=desc

    function availEsc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Fetch ────────────────────────────────────────────────────────────────
    function avDoSearch() {
        var start = document.getElementById('avail-start').value;
        var end   = document.getElementById('avail-end').value;
        var asset = document.getElementById('avail-asset').value.trim();
        if (!start || !end) return;

        document.getElementById('avail-results').style.display = 'none';
        document.getElementById('avail-error').style.display   = 'none';
        document.getElementById('avail-loading').style.display = '';

        var url = AVAIL_URL
            + '?fecha_inicio=' + encodeURIComponent(start)
            + '&fecha_fin='    + encodeURIComponent(end);
        if (asset) url += '&asset_name=' + encodeURIComponent(asset);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                document.getElementById('avail-loading').style.display = 'none';
                if (data.error) {
                    document.getElementById('avail-error').textContent = data.error;
                    document.getElementById('avail-error').style.display = '';
                    return;
                }
                avAllRows = data.rows || [];
                avPage    = 1;
                // Reset secondary filters
                document.getElementById('avail-filter-tipo').value   = '';
                document.getElementById('avail-filter-estado').value = '';

                var free  = avAllRows.filter(function(r) { return r.available; }).length;
                var total = avAllRows.length;
                document.getElementById('avail-summary').innerHTML =
                    'Período: <strong>' + availEsc(data.fecha_inicio) + '</strong> → <strong>'
                    + availEsc(data.fecha_fin) + '</strong>'
                    + ' &nbsp;|&nbsp; '
                    + '<span class="text-success fw-bold">' + free + ' libre' + (free !== 1 ? 's' : '') + '</span>'
                    + ' / '
                    + '<span class="text-danger fw-bold">' + (total - free) + ' ocupado' + ((total - free) !== 1 ? 's' : '') + '</span>'
                    + ' de ' + total + ' activos';

                document.getElementById('avail-results').style.display = '';
                avApplyFilters();
            })
            .catch(function() {
                document.getElementById('avail-loading').style.display = 'none';
                document.getElementById('avail-error').textContent = 'Error al consultar disponibilidad.';
                document.getElementById('avail-error').style.display = '';
            });
    }
    document.getElementById('avail-form').addEventListener('submit', function(e) {
        e.preventDefault();
        avDoSearch();
    });

    // ── Secondary filters ────────────────────────────────────────────────────
    document.getElementById('avail-filter-tipo').addEventListener('change',   function() { avPage = 1; avApplyFilters(); });
    document.getElementById('avail-filter-estado').addEventListener('change', function() { avPage = 1; avApplyFilters(); });
    document.getElementById('avail-per-page').addEventListener('change', function() {
        avPerPage = parseInt(this.value) || 0;
        avPage = 1;
        avRender();
    });

    function avApplyFilters() {
        var tipo   = document.getElementById('avail-filter-tipo').value;
        var estado = document.getElementById('avail-filter-estado').value;
        avFiltered = avAllRows.filter(function(r) {
            if (tipo   && r.itemtype !== tipo)            return false;
            if (estado === 'free'     && !r.available)   return false;
            if (estado === 'occupied' && r.available)    return false;
            return true;
        });
        avSort();
        avRender();
    }

    // ── Sorting ──────────────────────────────────────────────────────────────
    document.querySelectorAll('.avail-sortable').forEach(function(th) {
        th.addEventListener('click', function() {
            var col = this.dataset.col;
            if (avSortCol === col) {
                avSortDir = -avSortDir;
            } else {
                avSortCol = col;
                avSortDir = 1;
            }
            // Update icons
            document.querySelectorAll('.avail-sortable').forEach(function(h) {
                h.querySelector('.avail-sort-icon').textContent = '⇅';
                h.querySelector('.avail-sort-icon').className = 'avail-sort-icon text-muted';
            });
            var icon = this.querySelector('.avail-sort-icon');
            icon.textContent = avSortDir === 1 ? '↑' : '↓';
            icon.className = 'avail-sort-icon text-primary';
            avPage = 1;
            avSort();
            avRender();
        });
    });

    function avSort() {
        avFiltered.sort(function(a, b) {
            var va = a[avSortCol], vb = b[avSortCol];
            if (typeof va === 'boolean') { va = va ? 1 : 0; vb = vb ? 1 : 0; }
            if (typeof va === 'string')  { return avSortDir * va.localeCompare(vb); }
            return avSortDir * ((va > vb) ? 1 : (va < vb) ? -1 : 0);
        });
    }

    // ── Render page ──────────────────────────────────────────────────────────
    function avRender() {
        var total    = avFiltered.length;
        var perPage  = avPerPage || total;
        var pages    = perPage ? Math.ceil(total / perPage) : 1;
        if (avPage > pages) avPage = pages || 1;

        var start = (avPage - 1) * perPage;
        var slice = perPage ? avFiltered.slice(start, start + perPage) : avFiltered;

        var empty = document.getElementById('avail-empty-filter');
        var table = document.getElementById('avail-table');

        if (total === 0) {
            empty.style.display = '';
            table.style.display = 'none';
            document.getElementById('avail-pagination-bar').style.display = 'none';
            return;
        }
        empty.style.display = 'none';
        table.style.display = '';

        var tbody = document.getElementById('avail-tbody');
        tbody.innerHTML = '';
        slice.forEach(function(row) {
            var tr = document.createElement('tr');
            if (!row.available) tr.classList.add('table-danger');

            // Checkbox column
            var tdChk = document.createElement('td');
            tdChk.style.textAlign = 'center';
            tdChk.style.verticalAlign = 'middle';
            if (row.available) {
                var chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.className = 'avail-row-chk';
                chk.dataset.itemtype = row.itemtype;
                chk.dataset.itemsId  = row.items_id;
                chk.dataset.name     = row.name;
                if (avSelected[row.itemtype + '|' + row.items_id]) chk.checked = true;
                chk.addEventListener('change', avUpdateSelection);
                tdChk.appendChild(chk);
            }
            tr.appendChild(tdChk);

            var tdType = document.createElement('td');
            tdType.textContent = row.type_label || row.itemtype;
            tr.appendChild(tdType);

            var tdName = document.createElement('td');
            tdName.textContent = row.name;
            tr.appendChild(tdName);

            var tdStatus = document.createElement('td');
            tdStatus.innerHTML = row.available
                ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Libre</span>'
                : '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Ocupado</span>';
            tr.appendChild(tdStatus);

            var tdLoans = document.createElement('td');
            if (row.occupied_loans && row.occupied_loans.length > 0) {
                if (CAN_SUPERVISE) {
                    tdLoans.innerHTML = row.occupied_loans.map(function(l) {
                        var lnk = '<a href="' + availEsc(LOAN_FORM_URL) + '?id=' + parseInt(l.id) + '" target="_blank">'
                            + availEsc(l.loan_name || ('Préstamo #' + l.id)) + '</a>';
                        var dates = (l.eff_start || l.eff_end)
                            ? ' <span class="text-muted" style="font-size:.8rem;">('
                              + availEsc(l.eff_start || '') + (l.eff_end ? ' → ' + availEsc(l.eff_end) : '') + ')</span>'
                            : '';
                        return lnk + dates;
                    }).join('<br>');
                } else {
                    // Non-supervisors only see dates, no loan name or link
                    tdLoans.innerHTML = row.occupied_loans.map(function(l) {
                        return (l.eff_start || l.eff_end)
                            ? '<span class="text-muted" style="font-size:.8rem;">'
                              + availEsc(l.eff_start || '') + (l.eff_end ? ' → ' + availEsc(l.eff_end) : '') + '</span>'
                            : '<span class="text-muted">—</span>';
                    }).join('<br>');
                }
            } else {
                tdLoans.innerHTML = '<span class="text-muted">—</span>';
            }
            tr.appendChild(tdLoans);
            tbody.appendChild(tr);
        });

        // Page info
        var from = total ? start + 1 : 0;
        var to   = Math.min(start + (perPage || total), total);
        document.getElementById('avail-page-info').textContent =
            'Mostrando ' + from + ' - ' + to + ' de ' + total + ' activos';

        // Pagination buttons
        var ul = document.getElementById('avail-pagination');
        ul.innerHTML = '';
        if (pages <= 1) {
            document.getElementById('avail-pagination-bar').style.display = 'none';
            return;
        }
        document.getElementById('avail-pagination-bar').style.display = '';

        function mkLi(label, page, disabled, active) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            var a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.innerHTML = label;
            a.addEventListener('click', function(e) { e.preventDefault(); if (!disabled) { avPage = page; avRender(); } });
            li.appendChild(a);
            ul.appendChild(li);
        }

        mkLi('&laquo;', avPage - 1, avPage === 1, false);
        // Windowed page numbers (max 7 visible)
        var winStart = Math.max(1, avPage - 3);
        var winEnd   = Math.min(pages, avPage + 3);
        if (winStart > 1)     { mkLi('1', 1, false, false); if (winStart > 2) mkLi('…', null, true, false); }
        for (var p = winStart; p <= winEnd; p++) mkLi(p, p, false, p === avPage);
        if (winEnd < pages)   { if (winEnd < pages - 1) mkLi('…', null, true, false); mkLi(pages, pages, false, false); }
        mkLi('&raquo;', avPage + 1, avPage === pages, false);
    }

    // ── Bulk selection ───────────────────────────────────────────────────────
    var avSelected = {}; // key: "itemtype|items_id" → {itemtype, items_id, name}

    // ── Custom searchable loan picker ──────────────────────────────────────
    (function() {
        var searchInput = document.getElementById('bulk-loan-search');
        var dropdown    = document.getElementById('bulk-loan-dropdown');
        var hiddenSel   = document.getElementById('bulk-loan-select');
        var selectedId  = '';
        var selectedLabel = '';

        function itemStyle(el, hover) {
            el.style.padding        = '6px 12px';
            el.style.cursor         = 'pointer';
            el.style.color          = hover ? '#fff' : '#212529';
            el.style.backgroundColor = hover ? '#0d6efd' : '#fff';
            el.style.fontSize       = '.875rem';
        }

        function buildDropdown(filter) {
            dropdown.innerHTML = '';
            var q = (filter || '').toLowerCase();
            var matches = BULK_LOANS.filter(function(l) {
                return q === '' || l.label.toLowerCase().indexOf(q) !== -1;
            });
            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.textContent = 'Sin resultados';
                itemStyle(empty, false);
                empty.style.color = '#6c757d';
                dropdown.appendChild(empty);
            } else {
                matches.forEach(function(l) {
                    var row = document.createElement('div');
                    row.textContent = l.label;
                    row.dataset.id  = l.id;
                    itemStyle(row, false);
                    row.addEventListener('mouseenter', function() { itemStyle(this, true); });
                    row.addEventListener('mouseleave', function() { itemStyle(this, this.dataset.id === selectedId); });
                    row.addEventListener('mousedown', function(e) {
                        e.preventDefault(); // don't blur input first
                        selectedId    = l.id;
                        selectedLabel = l.label;
                        searchInput.value    = l.label;
                        hiddenSel.value      = l.id;
                        hiddenSel.dispatchEvent(new Event('change'));
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(row);
                });
            }
        }

        searchInput.addEventListener('focus', function() {
            buildDropdown(this.value);
            dropdown.style.display = 'block';
        });
        searchInput.addEventListener('input', function() {
            selectedId = ''; selectedLabel = '';
            hiddenSel.value = '';
            hiddenSel.dispatchEvent(new Event('change'));
            buildDropdown(this.value);
            dropdown.style.display = 'block';
        });
        searchInput.addEventListener('blur', function() {
            // restore label if selection exists, else clear
            setTimeout(function() {
                dropdown.style.display = 'none';
                if (!selectedId) { searchInput.value = ''; }
            }, 150);
        });
        // build initially (hidden until focus)
        buildDropdown('');
    })();

    function avUpdateSelection() {
        // Rebuild from all visible checkboxes
        avSelected = {};
        document.querySelectorAll('.avail-row-chk:checked').forEach(function(chk) {
            var key = chk.dataset.itemtype + '|' + chk.dataset.itemsId;
            avSelected[key] = { itemtype: chk.dataset.itemtype, items_id: chk.dataset.itemsId, name: chk.dataset.name };
        });
        avRefreshBulkBar();
    }

    function avRefreshBulkBar() {
        var count  = Object.keys(avSelected).length;
        var bar    = document.getElementById('bulk-bar');
        var countEl = document.getElementById('bulk-count');
        if (count > 0) {
            bar.style.removeProperty('display'); // show (override !important)
            bar.style.display = 'flex';
            countEl.textContent = count + ' activo' + (count !== 1 ? 's' : '') + ' seleccionado' + (count !== 1 ? 's' : '');
            document.getElementById('bulk-add-btn').disabled =
                !document.getElementById('bulk-loan-select').value;
        } else {
            bar.style.display = 'none';
        }
        // Sync select-all checkbox state
        var pageChks = document.querySelectorAll('.avail-row-chk');
        var allChked = pageChks.length > 0 &&
            Array.from(pageChks).every(function(c) { return c.checked; });
        var checkAll = document.getElementById('avail-check-all');
        if (checkAll) {
            checkAll.checked = allChked;
            checkAll.indeterminate = !allChked && count > 0;
        }
    }

    document.getElementById('bulk-loan-select').addEventListener('change', function() {
        document.getElementById('bulk-add-btn').disabled = !this.value;
        avRefreshBulkBar(); // sync button state
    });

    document.getElementById('avail-check-all').addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.avail-row-chk').forEach(function(chk) {
            chk.checked = checked;
        });
        avUpdateSelection();
    });

    document.getElementById('bulk-clear-btn').addEventListener('click', function() {
        document.querySelectorAll('.avail-row-chk').forEach(function(chk) { chk.checked = false; });
        avSelected = {};
        avRefreshBulkBar();
    });

    document.getElementById('bulk-add-btn').addEventListener('click', function() {
        var loans_id = document.getElementById('bulk-loan-select').value;
        if (!loans_id) return;

        var items = Object.values(avSelected);
        var start = document.getElementById('avail-start').value;
        var end   = document.getElementById('avail-end').value;

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Añadiendo…';

        var body = new URLSearchParams();
        body.append('loans_id',     loans_id);
        body.append('items',        JSON.stringify(items));
        body.append('fecha_inicio', start);
        body.append('fecha_fin',    end);

        fetch(BULK_ADD_URL, {
            method: 'POST',
            body: body,
            headers: { 'X-Glpi-Csrf-Token': BULK_CSRF }
        })
            .then(function(r) {
                return r.text().then(function(text) {
                    if (!text.trim()) throw new Error('Respuesta vacía (HTTP ' + r.status + ')');
                    try { return JSON.parse(text); }
                    catch(e) { throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 300)); }
                });
            })
            .then(function(data) {
                document.getElementById('bulk-add-btn').disabled = false;
                document.getElementById('bulk-add-btn').innerHTML =
                    '<i class="fas fa-plus me-1"></i>Añadir al préstamo';

                if (data.error) {
                    avShowBulkResult('danger', '<i class="fas fa-times-circle me-1"></i>' + data.error);
                    return;
                }

                var msg = '';
                if (data.added > 0) {
                    msg += '<span class="text-success"><i class="fas fa-check-circle me-1"></i>'
                         + data.added + ' activo' + (data.added !== 1 ? 's' : '')
                         + ' añadido' + (data.added !== 1 ? 's' : '')
                         + ' correctamente.</span>';
                }
                if (data.conflicts && data.conflicts.length > 0) {
                    msg += (msg ? '<br>' : '') + '<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>'
                         + data.conflicts.length + ' con conflicto: '
                         + data.conflicts.map(function(c) { return '<strong>' + c.name + '</strong>'; }).join(', ')
                         + '</span>';
                }
                if (data.already && data.already.length > 0) {
                    msg += (msg ? '<br>' : '') + '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>'
                         + 'Ya estaban en el préstamo: ' + data.already.join(', ') + '</span>';
                }
                if (!msg) msg = '<span class="text-muted">Sin cambios.</span>';

                avShowBulkResult(data.added > 0 ? 'success' : 'warning', msg);

                // Clear selection and re-search to update availability
                document.querySelectorAll('.avail-row-chk').forEach(function(c) { c.checked = false; });
                avSelected = {};
                avRefreshBulkBar();
                avDoSearch();
            })
            .catch(function(err) {
                document.getElementById('bulk-add-btn').disabled = false;
                document.getElementById('bulk-add-btn').innerHTML = '<i class="fas fa-plus me-1"></i>Añadir al préstamo';
                avShowBulkResult('danger', err && err.message ? err.message : 'Error al comunicarse con el servidor.');
            });
    });

    function avShowBulkResult(type, html) {
        var el = document.getElementById('bulk-result');
        el.className = 'alert alert-' + type + ' py-2 mb-2';
        el.innerHTML = html;
        el.style.display = '';
    }
    </script>
    <?php
}

if ($is_helpdesk) {
    Html::helpFooter();
} else {
    Html::footer();
}
