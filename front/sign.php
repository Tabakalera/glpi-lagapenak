<?php

/**
 * Public signing page — no GLPI login required.
 * Accessed via a one-time token: sign.php?token=xxxx
 */

include('../../../inc/includes.php');
include_once __DIR__ . '/../inc/albaran_pdf.php';
// NOTE: Session::checkLoginUser() is intentionally NOT called here.

global $DB, $CFG_GLPI;

// ── Validate token ────────────────────────────────────────────────────────────
$token = trim($_GET['token'] ?? ($_POST['sign_token'] ?? ''));
if (!$token || strlen($token) > 64) {
    die('<p style="font-family:sans-serif;padding:40px;">Enlace no válido.</p>');
}

$rows = $DB->request([
    'FROM'  => 'glpi_plugin_lagapenak_loans',
    'WHERE' => ['sign_token' => $token],
    'LIMIT' => 1,
]);
$row = $rows->current();

if (!$row) {
    die('<p style="font-family:sans-serif;padding:40px;">Este enlace no es válido o ya ha sido utilizado.</p>');
}
if (!empty($row['sign_token_expires']) && strtotime($row['sign_token_expires']) < time()) {
    die('<p style="font-family:sans-serif;padding:40px;">Este enlace ha caducado. Solicita uno nuevo al responsable del préstamo.</p>');
}

$loan = new PluginLagapenakLoan();
$loan->getFromDB((int) $row['id']);
$ID = $loan->getID();

// ── POST: save signature ──────────────────────────────────────────────────────
$signed_ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    $sig_data     = $_POST['signature_data']    ?? '';
    $sig_name     = trim($_POST['signature_name']    ?? '');
    $sig_passport = trim($_POST['albaran_passport']  ?? '');
    $sig_project  = trim($_POST['albaran_project']   ?? '');

    if ($sig_data && $sig_name) {
        $DB->update('glpi_plugin_lagapenak_loans', [
            'signature_data'    => $sig_data,
            'signature_name'    => $sig_name,
            'signature_date'    => date('Y-m-d H:i:s'),
            'has_albaran'       => 1,
            'albaran_passport'  => $sig_passport,
            'albaran_project'   => $sig_project,
            'sign_token'        => null,
            'sign_token_expires'=> null,
        ], ['id' => $ID]);
        plugin_lagapenak_send_signed_albaran($ID);
        $signed_ok = true;
    }
}

// ── Load display data ─────────────────────────────────────────────────────────
$items = PluginLagapenakLoanItem::getItemsForLoan($ID);

$loan_name        = htmlspecialchars($loan->fields['name'] ?: 'Préstamo #' . $ID);
$fecha_recogida   = $loan->fields['fecha_inicio'] ? Html::convDate($loan->fields['fecha_inicio']) : '—';
$fecha_devolucion = $loan->fields['fecha_fin']    ? Html::convDate($loan->fields['fecha_fin'])    : '—';

$sign_name    = htmlspecialchars(trim($loan->fields['beneficiary_name'] ?? '') ?: ($loan->fields['signature_name'] ?? ''));
$sign_dni     = htmlspecialchars(trim($loan->fields['beneficiary_dni']  ?? '') ?: ($loan->fields['albaran_passport'] ?? ''));
$sign_project = htmlspecialchars(trim($loan->fields['field_2']          ?? '') ?: ($loan->fields['albaran_project']  ?? ''));

// Header/footer images
$root_doc   = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
function _sign_img_web($name, $root_doc) {
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        $fs = GLPI_ROOT . '/plugins/lagapenak/pics/' . $name . $ext;
        if (file_exists($fs)) return $root_doc . '/plugins/lagapenak/pics/' . $name . $ext;
    }
    return null;
}
$header_web = _sign_img_web('albaran_header', $root_doc);
$footer_web = _sign_img_web('albaran_footer', $root_doc);
$fa_css     = $root_doc . '/public/lib/fortawesome/fontawesome-free/css/all.min.css';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Firma — <?= $loan_name ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($fa_css) ?>">
    <style>
        body       { background:#f0f2f5; font-family:system-ui,sans-serif; }
        .doc-card  { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.12); overflow:hidden; }
        .doc-body  { padding:28px 36px 32px; }
        .field-row { display:flex; gap:6px; margin-bottom:5px; align-items:baseline; }
        .field-lbl { font-weight:600; white-space:nowrap; min-width:160px; }
        .field-val { flex:1; }
        .sig-canvas{ border:2px solid #ced4da; border-radius:6px; background:#fff;
                     cursor:crosshair; touch-action:none; display:block; width:100%; height:150px; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width:720px">

<?php if ($signed_ok): ?>

    <div class="doc-card">
        <div class="doc-body text-center py-5">
            <i class="fas fa-check-circle text-success" style="font-size:3rem;"></i>
            <h4 class="mt-3">Firma guardada correctamente</h4>
            <p class="text-muted">Puedes cerrar esta página.</p>
        </div>
    </div>

<?php else: ?>

    <div class="doc-card">

        <?php if ($header_web): ?>
        <div style="padding:16px 20px 8px;">
            <img src="<?= htmlspecialchars($header_web) ?>" alt=""
                 style="display:block;max-width:260px;max-height:100px;width:auto;height:auto;">
        </div>
        <?php endif; ?>

        <div class="doc-body">

            <h5 class="fw-bold text-uppercase mb-4" style="letter-spacing:.8px;font-size:1.05rem;">
                Cesión de material
            </h5>

            <div class="field-row"><span class="field-lbl">Préstamo:</span><span class="field-val"><?= $loan_name ?></span></div>
            <div class="field-row"><span class="field-lbl">Fecha de recogida:</span><span class="field-val"><?= htmlspecialchars($fecha_recogida) ?></span></div>
            <div class="field-row"><span class="field-lbl">Fecha de devolución:</span><span class="field-val"><?= htmlspecialchars($fecha_devolucion) ?></span></div>

            <div class="field-row mt-2"><span class="field-lbl">Material:</span></div>
            <?php if (empty($items)): ?>
            <div class="ms-3 text-muted small">— Sin activos —</div>
            <?php else: foreach ($items as $item): ?>
            <div class="ms-3" style="font-size:.92rem;">
                -1x <?= htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id'])) ?>
            </div>
            <?php endforeach; endif; ?>

            <hr class="my-4">

            <!-- Conditions -->
            <?php $condiciones = plugin_lagapenak_get_condiciones(); ?>
            <p style="font-size:.9rem;">La persona usuaria del material se compromete a respetar las siguientes normas de uso:</p>
            <ul class="ps-4" style="font-size:.9rem;line-height:1.55;">
                <?php foreach ($condiciones as $c): ?>
                <li style="margin-bottom:6px;"><?= htmlspecialchars($c) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="mt-4" style="font-size:.9rem;">La persona usuaria del material confirma la aceptación de dichas normas,</p>

            <hr class="my-4">

            <form method="POST" action="?token=<?= htmlspecialchars($token) ?>" id="sign-form" class="mt-3">
                <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
                <input type="hidden" name="save_signature" value="1">
                <input type="hidden" name="sign_token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="signature_data" id="signature_data_input">

                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Nombre completo <span class="text-danger">*</span></label>
                        <input type="text" name="signature_name" class="form-control"
                               value="<?= $sign_name ?>" required placeholder="Nombre completo">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">DNI / Pasaporte</label>
                        <input type="text" name="albaran_passport" class="form-control"
                               value="<?= $sign_dni ?>" placeholder="Número">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Proyecto</label>
                        <input type="text" name="albaran_project" class="form-control"
                               value="<?= $sign_project ?>" placeholder="Nombre del proyecto">
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
                    <i class="fas fa-save me-1"></i>Guardar firma
                </button>
            </form>

        </div><!-- /doc-body -->

        <?php if ($footer_web): ?>
        <div style="padding:8px 20px 16px;">
            <img src="<?= htmlspecialchars($footer_web) ?>" alt=""
                 style="display:block;width:100%;height:auto;">
        </div>
        <?php endif; ?>

    </div><!-- /doc-card -->

<?php endif; ?>

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
        if (!hasSigned) { e.preventDefault(); alert('Por favor dibuja tu firma antes de guardar.'); return; }
        document.getElementById('signature_data_input').value = canvas.toDataURL('image/png');
    });
})();
</script>
</body>
</html>
