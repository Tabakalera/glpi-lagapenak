<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

Html::requireJs('fullcalendar');

Html::header(__('Lagapenak - Loan Calendar', 'lagapenak'), $_SERVER['PHP_SELF'], 'tools', 'PluginLagapenakLoan');

$plugin_web      = Plugin::getWebDir('lagapenak', true);
$ajax_events_url = $plugin_web . '/ajax/calendar_events.php';
$ajax_assets_url = $plugin_web . '/ajax/asset_list.php';
$list_url        = $plugin_web . '/front/loan.php';
$add_url         = $plugin_web . '/front/loan.form.php';

$fc_css    = $CFG_GLPI['root_doc'] . '/public/lib/fullcalendar.css';
$fc_locale = $CFG_GLPI['root_doc'] . '/public/lib/fullcalendar/core/locales/es.min.js';
?>

<link rel="stylesheet" href="<?= $fc_css ?>">
<style>
.fc-event { cursor: pointer; }
#asset-select-wrapper { min-width: 260px; }
</style>

<div class="container-fluid mt-3">

    <!-- Toolbar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">
            <i class="fas fa-calendar-alt me-2"></i><?= __('Loan Calendar', 'lagapenak') ?>
        </h3>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <div class="d-flex gap-1 flex-wrap">
                <span class="badge" style="background:#ffc107;color:#000;"><?= __('Pending',     'lagapenak') ?></span>
                <span class="badge" style="background:#0dcaf0;color:#000;"><?= __('In progress', 'lagapenak') ?></span>
                <span class="badge" style="background:#0d6efd;color:#fff;"><?= __('Delivered',   'lagapenak') ?></span>
                <span class="badge" style="background:#198754;color:#fff;"><?= __('Returned',    'lagapenak') ?></span>
            </div>
            <a href="<?= $list_url ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-list me-1"></i><?= __('List', 'lagapenak') ?>
            </a>
            <button class="btn btn-sm btn-outline-primary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#ics-panel">
                <i class="fas fa-calendar-plus me-1"></i><?= __('Subscribe to calendar', 'lagapenak') ?>
            </button>
        </div>
    </div>

    <!-- iCal subscription panel -->
    <?php
    $cfg = Config::getConfigurationValues('plugin:lagapenak');
    $ics_token = $cfg['ics_token'] ?? '';
    if (!$ics_token) {
        $ics_token = bin2hex(random_bytes(20));
        Config::setConfigurationValues('plugin:lagapenak', ['ics_token' => $ics_token]);
    }
    // Use url_base explicitly to guarantee absolute URL for calendar clients
    $url_base   = rtrim($CFG_GLPI['url_base'] ?? '', '/');
    $ics_path   = '/' . ltrim(Plugin::getWebDir('lagapenak', false), '/') . '/front/loan.ics.php?token=' . urlencode($ics_token);
    $url_loans  = $url_base . $ics_path . '&type=loans';
    $url_assets = $url_base . $ics_path . '&type=assets';
    ?>
    <div class="collapse mb-3" id="ics-panel">
        <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:16px 20px;">
            <p class="mb-2" style="font-size:.875rem;font-weight:600;">
                <?= __('Copy the URL and paste it into your calendar client:', 'lagapenak') ?>
            </p>
            <p class="mb-3" style="font-size:.8rem;color:#6c757d;">
                <?= __('Google Calendar → "Other calendars" (+) → "Add by URL"', 'lagapenak') ?><br>
                <?= __('Outlook → "Add calendar" → "From Internet"', 'lagapenak') ?>
            </p>
            <div class="mb-3">
                <div style="font-size:.8rem;font-weight:600;margin-bottom:4px;"><?= __('By loan:', 'lagapenak') ?></div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" style="flex:1;font-family:monospace;font-size:.75rem;padding:4px 8px;border:1px solid #ced4da;border-radius:4px;background:#fff;"
                           id="ics-url-loans" value="<?= htmlspecialchars($url_loans) ?>" readonly>
                    <button style="white-space:nowrap;padding:4px 10px;border:1px solid #ced4da;border-radius:4px;background:#fff;cursor:pointer;font-size:.8rem;"
                            data-copied="<?= __('✓ Copied', 'lagapenak') ?>"
                            onclick="navigator.clipboard.writeText(document.getElementById('ics-url-loans').value);this.textContent=this.dataset.copied">
                        <?= __('Copy', 'lagapenak') ?>
                    </button>
                </div>
            </div>
            <div>
                <div style="font-size:.8rem;font-weight:600;margin-bottom:4px;"><?= __('By asset:', 'lagapenak') ?></div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" style="flex:1;font-family:monospace;font-size:.75rem;padding:4px 8px;border:1px solid #ced4da;border-radius:4px;background:#fff;"
                           id="ics-url-assets" value="<?= htmlspecialchars($url_assets) ?>" readonly>
                    <button style="white-space:nowrap;padding:4px 10px;border:1px solid #ced4da;border-radius:4px;background:#fff;cursor:pointer;font-size:.8rem;"
                            data-copied="<?= __('✓ Copied', 'lagapenak') ?>"
                            onclick="navigator.clipboard.writeText(document.getElementById('ics-url-assets').value);this.textContent=this.dataset.copied">
                        <?= __('Copy', 'lagapenak') ?>
                    </button>
                </div>
            </div>
            <p style="margin-top:10px;margin-bottom:0;font-size:.75rem;color:#6c757d;">
                <i class="fas fa-lock me-1"></i><?= __('The URL includes a secret token. Do not share it publicly.', 'lagapenak') ?>
            </p>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="cal-tabs">
        <li class="nav-item">
            <button class="nav-link active" data-tab="by-loan">
                <i class="fas fa-calendar me-1"></i><?= __('By loan', 'lagapenak') ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-tab="by-asset">
                <i class="fas fa-box me-1"></i><?= __('By asset', 'lagapenak') ?>
            </button>
        </li>
    </ul>

    <!-- Tab: Por préstamo -->
    <div id="tab-by-loan">
        <div class="card">
            <div class="card-body p-3">
                <div id="cal-loan"></div>
            </div>
        </div>
    </div>

    <!-- Tab: Por activo -->
    <div id="tab-by-asset" style="display:none;">
        <div class="card">
            <div class="card-body p-3">
                <!-- Asset selector -->
                <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                    <label class="fw-bold mb-0">
                        <i class="fas fa-box me-1"></i><?= __('Asset:', 'lagapenak') ?>
                    </label>
                    <div id="asset-select-wrapper">
                        <select id="asset-select" class="form-select form-select-sm">
                            <option value=""><?= __('— Loading assets… —', 'lagapenak') ?></option>
                        </select>
                    </div>
                    <span class="text-muted" style="font-size:.82rem;">
                        <i class="fas fa-info-circle me-1"></i><?= __('Select an asset to view its availability', 'lagapenak') ?>
                    </span>
                </div>
                <!-- Calendar for selected asset -->
                <div id="cal-asset-empty" class="text-center text-muted py-4" style="display:none;">
                    <?= __('Select an asset to view its bookings.', 'lagapenak') ?>
                </div>
                <div id="cal-asset"></div>
            </div>
        </div>
    </div>

    <!-- Tooltip -->
    <div id="lagapenak-cal-tooltip" style="
        display:none; position:fixed; z-index:9999;
        background:#333; color:#fff; border-radius:6px;
        padding:8px 12px; font-size:0.85rem; max-width:280px;
        pointer-events:none; box-shadow:0 2px 8px rgba(0,0,0,.4); line-height:1.5;">
    </div>

</div>

<script src="<?= $fc_locale ?>"></script>
<script>
/* i18n strings from PHP */
var I18N = {
    errorLoadingEvents: <?= json_encode(__('Error loading events.', 'lagapenak')) ?>,
    selectAsset:        <?= json_encode(__('— Select an asset —', 'lagapenak')) ?>,
    noAssetsWithLoans:  <?= json_encode(__('No assets with loans', 'lagapenak')) ?>,
    errorLoadingAssets: <?= json_encode(__('Error loading assets', 'lagapenak')) ?>
};
</script>
<script>
/* ============================================================
   TOOLTIP
   ============================================================ */
var tooltip = document.getElementById('lagapenak-cal-tooltip');

function showTooltip(html) { tooltip.innerHTML = html; tooltip.style.display = 'block'; }
function hideTooltip()     { tooltip.style.display = 'none'; }

document.addEventListener('mousemove', function(e) {
    if (tooltip.style.display !== 'block') return;
    var x = e.clientX + 14, y = e.clientY + 14;
    if (x + tooltip.offsetWidth  > window.innerWidth)  x = e.clientX - tooltip.offsetWidth  - 10;
    if (y + tooltip.offsetHeight > window.innerHeight) y = e.clientY - tooltip.offsetHeight - 10;
    tooltip.style.left = x + 'px';
    tooltip.style.top  = y + 'px';
});

/* ============================================================
   COMMON CALENDAR OPTIONS
   ============================================================ */
var BASE_URL = '<?= addslashes($ajax_events_url) ?>';
var LOAN_URL = '<?= addslashes($plugin_web) ?>/front/loan.form.php';

function makeCalendarOptions(extraEvents, withTimeViews) {
    return {
        plugins:     ['dayGrid', 'timeGrid', 'list', 'interaction'],
        locale:      'es',
        timeZone:    'local',
        defaultView: 'dayGridMonth',
        header: {
            left:   'prev,next today',
            center: 'title',
            right:  withTimeViews ? 'dayGridMonth,timeGridWeek,listMonth'
                                  : 'dayGridMonth,listMonth'
        },
        buttonText: {
            month:   'Mes',
            week:    'Semana',
            day:     'Día',
            list:    'Lista',
            today:   'Hoy',
        },
        height:       'auto',
        weekNumbers:  true,
        nowIndicator: true,
        eventLimit:   4,
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },

        events: extraEvents,

        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) window.location.href = info.event.url;
        },

        eventMouseEnter: function(info) {
            var p = info.event.extendedProps;
            var h = '<strong>' + esc(info.event.title) + '</strong>';
            if (p.status)    h += '<br>📋 ' + esc(p.status);
            if (p.requester) h += '<br>👤 ' + esc(p.requester);
            if (p.assets)    h += '<br>📦 ' + esc(p.assets);
            var s = info.event.start;
            var e = info.event.end;
            if (s) h += '<br>📅 ' + fmtDt(s) + (e ? ' → ' + fmtDt(e) : '');
            showTooltip(h);
        },
        eventMouseLeave: hideTooltip,
    };
}

/* ============================================================
   TAB 1 — Por préstamo (all loans, all-day events)
   ============================================================ */
var loanCalendar = null;

function initLoanCalendar() {
    var opts = makeCalendarOptions({
        url:    BASE_URL,
        method: 'GET',
        failure: function() { alert(I18N.errorLoadingEvents); }
    }, false);
    loanCalendar = new FullCalendar.Calendar(document.getElementById('cal-loan'), opts);
    loanCalendar.render();
}

/* ============================================================
   TAB 2 — Por activo (one asset at a time, time-precise events)
   ============================================================ */
var assetCalendar  = null;
var assetCalLoaded = false;
var currentAssetItemtype = '';
var currentAssetItemsId  = 0;

function initAssetCalendar() {
    document.getElementById('cal-asset-empty').style.display = '';
    document.getElementById('cal-asset').style.display       = 'none';

    var opts = makeCalendarOptions(function(info, success, failure) {
        if (!currentAssetItemtype || !currentAssetItemsId) {
            success([]);
            return;
        }
        fetch(BASE_URL
            + '?itemtype=' + encodeURIComponent(currentAssetItemtype)
            + '&items_id=' + encodeURIComponent(currentAssetItemsId)
            + '&start='    + encodeURIComponent(info.startStr)
            + '&end='      + encodeURIComponent(info.endStr)
        )
        .then(function(r) { return r.json(); })
        .then(success)
        .catch(failure);
    }, true);

    opts.defaultView = 'dayGridMonth';

    assetCalendar = new FullCalendar.Calendar(document.getElementById('cal-asset'), opts);
    assetCalendar.render();
    assetCalLoaded = true;
}

function reloadAssetCalendar() {
    if (!assetCalendar) return;
    var empty = document.getElementById('cal-asset-empty');
    var calEl = document.getElementById('cal-asset');
    if (!currentAssetItemtype || !currentAssetItemsId) {
        empty.style.display  = '';
        calEl.style.display  = 'none';
    } else {
        empty.style.display = 'none';
        calEl.style.display = '';
        assetCalendar.refetchEvents();
    }
}

/* ============================================================
   ASSET SELECTOR — load options and wire change event
   ============================================================ */
function loadAssetList() {
    fetch('<?= addslashes($ajax_assets_url) ?>')
        .then(function(r) { return r.json(); })
        .then(function(assets) {
            var sel = document.getElementById('asset-select');
            sel.innerHTML = '<option value="">' + I18N.selectAsset + '</option>';
            assets.forEach(function(a) {
                var opt = document.createElement('option');
                opt.value       = a.itemtype + '::' + a.items_id;
                opt.textContent = a.label;
                sel.appendChild(opt);
            });
            if (assets.length === 0) {
                sel.innerHTML = '<option value="">' + I18N.noAssetsWithLoans + '</option>';
            }
        })
        .catch(function() {
            document.getElementById('asset-select').innerHTML =
                '<option value="">' + I18N.errorLoadingAssets + '</option>';
        });
}

document.getElementById('asset-select').addEventListener('change', function() {
    var val = this.value;
    if (val) {
        var parts = val.split('::');
        currentAssetItemtype = parts[0];
        currentAssetItemsId  = parseInt(parts[1], 10);
    } else {
        currentAssetItemtype = '';
        currentAssetItemsId  = 0;
    }
    reloadAssetCalendar();
});

/* ============================================================
   TAB SWITCHING
   ============================================================ */
document.querySelectorAll('#cal-tabs .nav-link').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#cal-tabs .nav-link').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');

        var tab = btn.dataset.tab;
        document.getElementById('tab-by-loan').style.display  = tab === 'by-loan'  ? '' : 'none';
        document.getElementById('tab-by-asset').style.display = tab === 'by-asset' ? '' : 'none';

        if (tab === 'by-asset' && !assetCalLoaded) {
            initAssetCalendar();
            loadAssetList();
        }
        // Re-render to fix sizing after display:none → visible
        if (tab === 'by-loan'  && loanCalendar)  loanCalendar.render();
        if (tab === 'by-asset' && assetCalendar) assetCalendar.render();
    });
});

/* ============================================================
   INIT — render loan calendar (default tab)
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {
    initLoanCalendar();
});

/* ============================================================
   UTILS
   ============================================================ */
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDt(d) {
    if (!d) return '';
    var dt = d instanceof Date ? d : new Date(d);
    var pad = function(n) { return String(n).padStart(2,'0'); };
    return dt.getFullYear() + '-' + pad(dt.getMonth()+1) + '-' + pad(dt.getDate())
         + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
}
</script>

<?php
Html::footer();
