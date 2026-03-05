<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// Bootstrap default display columns once (no-op if already set)
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

$tab        = isset($_GET['tab']) ? $_GET['tab'] : 'prestamos';
$plugin_web = Plugin::getWebDir('lagapenak', true);
$avail_url  = $plugin_web . '/ajax/availability.php';
$loan_form  = $plugin_web . '/front/loan.form.php';
$cal_url    = $plugin_web . '/front/calendar.php';

Html::header('Lagapenak - Préstamos', $_SERVER['PHP_SELF'], 'tools', 'PluginLagapenakLoan');

// ── Tab navigation ────────────────────────────────────────────────────────────
$tabs = [
    'prestamos'      => ['icon' => 'fa-list',         'label' => 'Préstamos'],
    'activos'        => ['icon' => 'fa-box',           'label' => 'Listado por activos'],
    'disponibilidad' => ['icon' => 'fa-check-circle',  'label' => 'Disponibilidad'],
];
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
    // Bootstrap default display columns for LoanItem search
    $needed_li = [2, 1, 3, 6, 7, 8, 4, 5]; // Tipo, Activo, Estado, Préstamo, Inicio, Fin, F.entrega, F.devolución
    foreach ($needed_li as $rank => $num) {
        if (!countElementsInTable('glpi_displaypreferences', [
            'itemtype' => 'PluginLagapenakLoanItem',
            'num'      => $num,
            'users_id' => 0,
        ])) {
            $dp = new DisplayPreference();
            $dp->add([
                'itemtype' => 'PluginLagapenakLoanItem',
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ]);
        }
    }
    Search::show('PluginLagapenakLoanItem');

// ── TAB 3: Disponibilidad ─────────────────────────────────────────────────────
} elseif ($tab === 'disponibilidad') {
    $default_start = date('Y-m-d') . 'T00:00';
    $default_end   = date('Y-m-d', strtotime('+30 days')) . 'T23:59';
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

            <div id="avail-empty-filter" class="text-center text-muted py-3" style="display:none;">
                Ningún activo coincide con los filtros aplicados.
            </div>

            <table class="table table-sm table-bordered table-hover" id="avail-table" style="display:none;">
                <thead class="table-light">
                    <tr>
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
    document.getElementById('avail-form').addEventListener('submit', function(e) {
        e.preventDefault();
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

    // Auto-search on page load with default dates
    document.getElementById('avail-form').dispatchEvent(new Event('submit'));
    </script>
    <?php
}

Html::footer();
