<?php

// Load GLPI for session / DB / classes — standalone HTML page (no Html::header)
include('../../../inc/includes.php');

Session::checkLoginUser();

$ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($ID <= 0) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

$loan = new PluginLagapenakLoan();
if (!$loan->getFromDB($ID) || !$loan->can($ID, READ)) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// ── CONDICIONES ─────────────────────────────────────────────────────────────────
// Para personalizar el texto edita este array. Ver README.md → "Condiciones albarán"
$condiciones = [
    'El uso que se vaya a hacer de los materiales deberá ir acorde con las líneas de trabajo desarrolladas por el centro.',
    'La persona solicitante del material deberá devolver el material en las mismas condiciones en que le fue entregado. En caso de desperfecto el/la solicitante deberá abonar el valor de reposición del elemento o su reparación.',
    'La persona solicitante asumirá cualquier tipo de responsabilidad que pudiera derivarse directa o indirectamente de la actividad que realice con el material prestado.',
    'La recogida y entrega de materiales se hará en las oficinas de Tabakalera de 9h a 15h.',
    'Los materiales fungibles asociados al uso del material correrán por cuenta de la persona usuaria.',
    'La persona solicitante se hará cargo del borrado del material generado en los equipos solicitados. Tabakalera procederá al borrado de las memorias diariamente.',
    'La persona solicitante se compromete a hacer constar la colaboración de Tabakalera, incluyendo su logotipo, en las producciones que deriven del uso del material prestado.',
];

// ── IMAGE HELPERS ────────────────────────────────────────────────────────────────
// Images stored at: lagapenak/pics/albaran_header.png (or .jpg)
//                   lagapenak/pics/albaran_footer.png (or .jpg)
function plugin_lagapenak_find_img($name) {
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        $fs = GLPI_ROOT . '/plugins/lagapenak/pics/' . $name . $ext;
        if (file_exists($fs)) return $fs;
    }
    return null;
}

global $CFG_GLPI;
$root_doc = rtrim($CFG_GLPI['root_doc'] ?? '', '/');

function plugin_lagapenak_img_web($name) {
    global $root_doc;
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        $fs = GLPI_ROOT . '/plugins/lagapenak/pics/' . $name . $ext;
        if (file_exists($fs)) return $root_doc . '/plugins/lagapenak/pics/' . $name . $ext;
    }
    return null;
}

$header_fs  = plugin_lagapenak_find_img('albaran_header');
$footer_fs  = plugin_lagapenak_find_img('albaran_footer');
$header_web = plugin_lagapenak_img_web('albaran_header');
$footer_web = plugin_lagapenak_img_web('albaran_footer');

// ── HELPERS ──────────────────────────────────────────────────────────────────────
function plugin_lagapenak_albaran_user_name($uid) {
    if (!$uid) return '—';
    $u = new User();
    if (!$u->getFromDB($uid)) return '—';
    $name = trim(($u->fields['realname'] ?? '') . ' ' . ($u->fields['firstname'] ?? ''));
    return $name ?: $u->fields['name'];
}

function plugin_lagapenak_date_es($date_str) {
    if (!$date_str) return '—';
    $ts = strtotime($date_str);
    if (!$ts) return $date_str;
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
               'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return intval(date('j', $ts)) . ' de ' . $months[intval(date('n', $ts))] . ' de ' . date('Y', $ts);
}

// ── POST: save signature ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    $sig_data     = $_POST['signature_data']          ?? '';
    $sig_name     = trim($_UPOST['signature_name']    ?? ($_POST['signature_name']    ?? ''));
    $sig_passport = trim($_UPOST['albaran_passport']  ?? ($_POST['albaran_passport']  ?? ''));
    $sig_project  = trim($_UPOST['albaran_project']   ?? ($_POST['albaran_project']   ?? ''));
    if (!empty($sig_data) && !empty($sig_name)) {
        global $DB;
        $DB->update('glpi_plugin_lagapenak_loans', [
            'signature_data'   => $sig_data,
            'signature_name'   => $sig_name,
            'signature_date'   => date('Y-m-d H:i:s'),
            'has_albaran'      => 1,
            'albaran_passport' => $sig_passport,
            'albaran_project'  => $sig_project,
        ], ['id' => $ID]);
    }
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID . '&saved=1');
}

// ── Load display data ────────────────────────────────────────────────────────────
$items          = PluginLagapenakLoanItem::getItemsForLoan($ID);
$already_signed = !empty($loan->fields['signature_data']);
$plugin_web     = Plugin::getWebDir('lagapenak', true);

$destinatario   = plugin_lagapenak_albaran_user_name($loan->fields['users_id_destinatario']);
$fa_css         = $root_doc . '/public/lib/fortawesome/fontawesome-free/css/all.min.css';
$csrf_token     = Session::getNewCSRFToken();

$disp_name     = htmlspecialchars($loan->fields['signature_name']   ?? '');
$disp_passport = htmlspecialchars($loan->fields['albaran_passport'] ?? '');
$disp_project  = htmlspecialchars($loan->fields['albaran_project']  ?? '');
$disp_sig_date = $loan->fields['signature_date']
    ? plugin_lagapenak_date_es($loan->fields['signature_date']) : '—';

$fecha_recogida   = $loan->fields['fecha_inicio'] ? Html::convDate($loan->fields['fecha_inicio']) : '—';
$fecha_devolucion = $loan->fields['fecha_fin']    ? Html::convDate($loan->fields['fecha_fin'])    : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Albarán — Préstamo #<?= $ID ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($fa_css) ?>">
    <style>
        body       { background:#f0f2f5; font-family:system-ui,sans-serif; }
        .doc-card  { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.12); overflow:hidden; }
        .doc-body  { padding:28px 36px 32px; }
        .field-row { display:flex; gap:6px; margin-bottom:5px; align-items:baseline; }
        .field-lbl { font-weight:600; white-space:nowrap; min-width:160px; }
        .field-val { flex:1; min-width:120px; }
        .sig-canvas{ border:2px solid #ced4da; border-radius:6px; background:#fff;
                     cursor:crosshair; touch-action:none; display:block; width:100%; height:150px; }
        .cond-list li { margin-bottom:6px; font-size:.9rem; line-height:1.55; }
        @media print { .no-print { display:none!important; } body { background:white; } }
    </style>
</head>
<body>
<div class="container py-4" style="max-width:860px">

    <!-- Toolbar -->
    <div class="d-flex gap-2 mb-3 flex-wrap no-print">
        <a href="<?= $plugin_web ?>/front/loan.form.php?id=<?= $ID ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Volver al préstamo
        </a>
        <?php if ($already_signed): ?>
        <button type="button" class="btn btn-sm btn-success" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Imprimir / Guardar PDF
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i>Firma guardada correctamente.
    </div>
    <?php endif; ?>

    <!-- Document card -->
    <div class="doc-card">

        <!-- Header image (optional) -->
        <?php if ($header_web): ?>
        <div style="padding:16px 20px 8px;">
            <img src="<?= htmlspecialchars($header_web) ?>" alt="" style="display:block;max-width:260px;max-height:100px;width:auto;height:auto;">
        </div>
        <?php endif; ?>

        <div class="doc-body">

            <!-- Title -->
            <h5 class="fw-bold text-uppercase mb-4" style="letter-spacing:.8px;font-size:1.05rem;">
                CESIÓN DE MATERIAL
            </h5>

            <!-- ── SIGNED: show stored values ── -->
            <?php if ($already_signed): ?>
            <div class="field-row"><span class="field-lbl">Nombre y apellidos:</span><span class="field-val"><?= $disp_name ?></span></div>
            <div class="field-row"><span class="field-lbl">Pasaporte:</span><span class="field-val"><?= $disp_passport ?: '&nbsp;' ?></span></div>
            <div class="field-row"><span class="field-lbl">Proyecto:</span><span class="field-val"><?= $disp_project ?: '&nbsp;' ?></span></div>
            <?php endif; ?>

            <!-- Material list (always shown) -->
            <div class="field-row mt-1"><span class="field-lbl">Material/equipo:</span></div>
            <?php if (empty($items)): ?>
            <div class="ms-3 text-muted small">— Sin activos —</div>
            <?php else: foreach ($items as $item): ?>
            <div class="ms-3" style="font-size:.92rem;">
                -1x <?= htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id'])) ?>
            </div>
            <?php endforeach; endif; ?>

            <!-- Dates (always shown) -->
            <div class="mt-3">
                <div class="field-row"><span class="field-lbl">Fecha de recogida:</span><span class="field-val"><?= htmlspecialchars($fecha_recogida) ?></span></div>
                <div class="field-row"><span class="field-lbl">Fecha de devolución:</span><span class="field-val"><?= htmlspecialchars($fecha_devolucion) ?></span></div>
            </div>

            <hr class="my-4">

            <!-- Conditions -->
            <p style="font-size:.9rem;">
                El usuario del material se compromete a cumplir las siguientes normas de uso y funcionamiento de Tabakalera:
            </p>
            <ul class="cond-list ps-4">
                <?php foreach ($condiciones as $c): ?>
                <li><?= htmlspecialchars($c) ?></li>
                <?php endforeach; ?>
            </ul>

            <p class="mt-4" style="font-size:.9rem;">El usuario del material confirma aceptar estas normas,</p>

            <!-- ── SIGNED: show signature ── -->
            <?php if ($already_signed): ?>
            <div class="row mt-3 align-items-end">
                <div class="col-auto">
                    <img src="<?= htmlspecialchars($loan->fields['signature_data']) ?>"
                         alt="Firma" class="border rounded p-1"
                         style="max-width:280px;max-height:120px;background:#fff;display:block;">
                    <div class="mt-1" style="font-size:.85rem;">
                        <?= $disp_name ?>
                    </div>
                </div>
                <div class="col d-flex justify-content-end align-items-end">
                    <div class="text-end" style="font-size:.9rem;">
                        Donostia/San Sebastián, a<br>
                        <strong><?= htmlspecialchars($disp_sig_date) ?></strong>
                    </div>
                </div>
            </div>
            <div class="mt-3 no-print">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-resign">
                    <i class="fas fa-redo me-1"></i>Volver a firmar (sobreescribe el PDF)
                </button>
            </div>
            <div id="sign-form-wrapper" style="display:none">
            <?php else: ?>
            <div id="sign-form-wrapper">
            <?php endif; ?>

                <!-- Sign form -->
                <form method="POST" action="" id="sign-form" class="mt-3">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="save_signature" value="1">
                    <input type="hidden" name="signature_data" id="signature_data_input">

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Nombre y apellidos <span class="text-danger">*</span></label>
                            <input type="text" name="signature_name" class="form-control"
                                   value="<?= htmlspecialchars($destinatario !== '—' ? $destinatario : ($disp_name ?: '')) ?>"
                                   required placeholder="Nombre y apellidos">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Pasaporte / DNI</label>
                            <input type="text" name="albaran_passport" class="form-control"
                                   value="<?= $disp_passport ?>" placeholder="Número">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Proyecto</label>
                            <input type="text" name="albaran_project" class="form-control"
                                   value="<?= $disp_project ?>" placeholder="Nombre del proyecto">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Firma <span class="text-muted fw-normal small">(dibuja con el dedo o el ratón)</span>
                        </label>
                        <canvas id="sig-canvas" class="sig-canvas" style="max-width:420px;"></canvas>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear">
                                <i class="fas fa-eraser me-1"></i>Borrar
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Guardar firma y generar PDF
                    </button>
                </form>

            </div><!-- /sign-form-wrapper -->

        </div><!-- /doc-body -->

        <!-- Footer image (optional) -->
        <?php if ($footer_web): ?>
        <div style="padding:8px 20px 16px;">
            <img src="<?= htmlspecialchars($footer_web) ?>" alt="" style="display:block;width:100%;height:auto;">
        </div>
        <?php endif; ?>

    </div><!-- /doc-card -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var canvas = document.getElementById('sig-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false, hasSigned = false;

    function resizeCanvas() {
        var rect = canvas.getBoundingClientRect();
        canvas.width  = rect.width;
        canvas.height = rect.height;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    function getPos(e) {
        var r = canvas.getBoundingClientRect(), src = e.touches ? e.touches[0] : e;
        return { x: src.clientX - r.left, y: src.clientY - r.top };
    }
    function startDraw(e) { e.preventDefault(); drawing = true; hasSigned = true;
        var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function draw(e) { if (!drawing) return; e.preventDefault();
        var p = getPos(e);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function stopDraw() { drawing = false; }

    canvas.addEventListener('mousedown',  startDraw);
    canvas.addEventListener('mousemove',  draw);
    canvas.addEventListener('mouseup',    stopDraw);
    canvas.addEventListener('mouseleave', stopDraw);
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove',  draw,      { passive: false });
    canvas.addEventListener('touchend',   stopDraw);

    document.getElementById('btn-clear').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height); hasSigned = false;
    });
    document.getElementById('sign-form').addEventListener('submit', function (e) {
        if (!hasSigned) { e.preventDefault(); alert('Por favor, dibuja tu firma antes de guardar.'); return; }
        document.getElementById('signature_data_input').value = canvas.toDataURL('image/png');
    });

    var resignBtn = document.getElementById('btn-resign');
    if (resignBtn) {
        resignBtn.addEventListener('click', function () {
            document.getElementById('sign-form-wrapper').style.display = 'block';
            resignBtn.style.display = 'none';
        });
    }
})();
</script>
</body>
</html>
