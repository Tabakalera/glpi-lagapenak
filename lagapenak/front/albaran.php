<?php

// Load GLPI for session / DB / classes — but we render our own HTML (no Html::header)
include('../../../inc/includes.php');

Session::checkLoginUser();

$ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($ID <= 0) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

$loan = new PluginLagapenakLoan();
if (!$loan->getFromDB($ID)) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// ── CONDICIONES DE PRÉSTAMO ─────────────────────────────────────────────────
// Para personalizar las condiciones, edita este array.
// Ver README.md → sección "Texto de condiciones del albarán"
$condiciones = [
    'El destinatario se compromete a usar el material prestado de forma adecuada y cuidadosa.',
    'El material debe ser devuelto en el plazo acordado y en el mismo estado en que fue entregado.',
    'Cualquier daño, pérdida o sustracción del material debe ser comunicado inmediatamente al responsable.',
    'El uso del material queda restringido a la finalidad indicada en este préstamo.',
    'El incumplimiento de estas condiciones podrá conllevar la restricción de futuros préstamos.',
];

// ── PDF file path ─────────────────────────────────────────────────────────────
function plugin_lagapenak_albaran_path($id) {
    $dir = GLPI_DOC_DIR . '/plugins/lagapenak';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/albaran_' . (int) $id . '.pdf';
}

function plugin_lagapenak_albaran_user_name($uid) {
    if (!$uid) return '—';
    $u = new User();
    if (!$u->getFromDB($uid)) return '—';
    $name = trim(($u->fields['realname'] ?? '') . ' ' . ($u->fields['firstname'] ?? ''));
    return $name ?: $u->fields['name'];
}

// ── Handle POST: save signature + generate PDF ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    $sig_data = $_POST['signature_data'] ?? '';
    $sig_name = trim($_POST['signature_name'] ?? '');
    if (!empty($sig_data) && !empty($sig_name)) {
        global $DB;
        $DB->update('glpi_plugin_lagapenak_loans', [
            'signature_data' => $sig_data,
            'signature_name' => $sig_name,
            'signature_date' => date('Y-m-d H:i:s'),
            'has_albaran'    => 1,
        ], ['id' => $ID]);

        $loan->getFromDB($ID);
        plugin_lagapenak_albaran_pdf($ID, $loan, $condiciones, plugin_lagapenak_albaran_path($ID));
    }
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID . '&saved=1');
}

// ── Handle GET: download stored PDF ─────────────────────────────────────────
if (isset($_GET['pdf'])) {
    $pdf_file = plugin_lagapenak_albaran_path($ID);
    if (file_exists($pdf_file)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="albaran_prestamo_' . $ID . '.pdf"');
        header('Content-Length: ' . filesize($pdf_file));
        readfile($pdf_file);
    } else {
        // Fallback: regenerate on the fly if file was lost
        $loan->getFromDB($ID);
        plugin_lagapenak_albaran_pdf($ID, $loan, $condiciones, null);
    }
    exit;
}

// ── Load display data ─────────────────────────────────────────────────────────
$items          = PluginLagapenakLoanItem::getItemsForLoan($ID);
$already_signed = !empty($loan->fields['signature_data']);
$pdf_ready      = !empty($loan->fields['has_albaran']) && file_exists(plugin_lagapenak_albaran_path($ID));
$plugin_web     = Plugin::getWebDir('lagapenak', true);

$solicitante  = plugin_lagapenak_albaran_user_name($loan->fields['users_id']);
$destinatario = plugin_lagapenak_albaran_user_name($loan->fields['users_id_destinatario']);

// Font Awesome is bundled with GLPI — derive web path from root_doc
global $CFG_GLPI;
$root_doc = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
$fa_css   = $root_doc . '/public/lib/fortawesome/fontawesome-free/css/all.min.css';

$csrf_token = Session::getNewCSRFToken();

// ── Standalone HTML page ──────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Albarán — Préstamo #<?= $ID ?></title>
    <!-- Bootstrap 5 (CDN — no conflicts with GLPI CSS) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome from GLPI bundle -->
    <link rel="stylesheet" href="<?= htmlspecialchars($fa_css) ?>">
    <style>
        body         { background: #f0f2f5; font-family: system-ui, sans-serif; }
        .doc-card    { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.12); }
        .sig-canvas  { border: 2px solid #ced4da; border-radius: 6px; background: #fff;
                       cursor: crosshair; touch-action: none; display: block; width: 100%; height: 160px; }
        .badge-status { font-size: .8rem; padding: .35em .7em; border-radius: 4px; }
        @media print  { .no-print { display: none !important; } body { background: white; } }
    </style>
</head>
<body>
<div class="container py-4" style="max-width:820px">

    <!-- Toolbar -->
    <div class="d-flex gap-2 mb-3 flex-wrap no-print">
        <a href="<?= $plugin_web ?>/front/loan.form.php?id=<?= $ID ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Volver al préstamo
        </a>
        <?php if ($pdf_ready): ?>
        <a href="?id=<?= $ID ?>&pdf=1" class="btn btn-sm btn-success">
            <i class="fas fa-file-pdf me-1"></i>Descargar PDF
        </a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i>
        Firma guardada y PDF generado. Descárgalo con el botón de arriba.
    </div>
    <?php endif; ?>

    <!-- Main document card -->
    <div class="doc-card p-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h4 class="mb-1 fw-bold">
                    <i class="fas fa-file-signature text-primary me-2"></i>Albarán de entrega
                </h4>
                <span class="text-muted">Préstamo #<?= $ID ?></span>
            </div>
            <?php if ($already_signed): ?>
            <span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Firmado</span>
            <?php endif; ?>
        </div>

        <hr class="my-3">

        <!-- Loan info -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted fw-normal" style="width:120px">Referencia</th>
                        <td class="fw-semibold"><?= htmlspecialchars($loan->fields['name']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Solicitante</th>
                        <td><?= htmlspecialchars($solicitante) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Destinatario</th>
                        <td><?= htmlspecialchars($destinatario) ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted fw-normal" style="width:120px">Estado</th>
                        <td><?= PluginLagapenakLoan::getStatusBadge($loan->fields['status']) ?></td></tr>
                    <tr><th class="text-muted fw-normal">Fecha inicio</th>
                        <td><?= Html::convDateTime($loan->fields['fecha_inicio']) ?: '—' ?></td></tr>
                    <tr><th class="text-muted fw-normal">Fecha fin</th>
                        <td><?= Html::convDateTime($loan->fields['fecha_fin']) ?: '—' ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Items -->
        <h6 class="fw-bold border-bottom pb-2 mb-3">
            <i class="fas fa-boxes me-1 text-primary"></i>Activos entregados
        </h6>
        <table class="table table-sm table-bordered mb-4">
            <thead class="table-light">
                <tr><th>Tipo</th><th>Activo</th><th>Entrega</th><th>Devolución</th></tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="4" class="text-center text-muted py-2">Sin activos</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= PluginLagapenakLoanItem::getTypeLabel($item['itemtype']) ?></td>
                    <td><strong><?= htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id'])) ?></strong></td>
                    <td class="text-nowrap small"><?= Html::convDateTime($item['date_checkout']) ?: '—' ?></td>
                    <td class="text-nowrap small"><?= Html::convDateTime($item['date_checkin'])  ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- Conditions -->
        <h6 class="fw-bold border-bottom pb-2 mb-3">
            <i class="fas fa-file-contract me-1 text-primary"></i>Condiciones de uso
        </h6>
        <div class="mb-4">
            <?php foreach ($condiciones as $i => $c): ?>
            <div class="d-flex gap-2 mb-2">
                <span class="fw-bold flex-shrink-0" style="min-width:22px"><?= $i + 1 ?>.</span>
                <span><?= htmlspecialchars($c) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Signature -->
        <h6 class="fw-bold border-bottom pb-2 mb-3">
            <i class="fas fa-pen-nib me-1 text-primary"></i>Firma del destinatario
        </h6>

        <?php if ($already_signed): ?>
        <div class="mb-3">
            <p class="mb-1"><strong>Firmado por:</strong> <?= htmlspecialchars($loan->fields['signature_name']) ?></p>
            <p class="mb-2 text-muted small">Fecha: <?= Html::convDateTime($loan->fields['signature_date']) ?></p>
            <img src="<?= htmlspecialchars($loan->fields['signature_data']) ?>" alt="Firma"
                 class="border rounded p-1" style="max-width:320px;max-height:130px;background:#fff">
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-3 no-print" id="btn-resign">
            <i class="fas fa-redo me-1"></i>Volver a firmar (sobreescribe el PDF)
        </button>
        <div id="sign-form-wrapper" style="display:none">
        <?php else: ?>
        <div id="sign-form-wrapper">
        <?php endif; ?>

            <form method="POST" action="" id="sign-form">
                <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="save_signature" value="1">
                <input type="hidden" name="signature_data" id="signature_data_input">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Nombre del firmante</label>
                    <input type="text" name="signature_name" class="form-control" style="max-width:340px"
                           value="<?= htmlspecialchars($destinatario !== '—' ? $destinatario : '') ?>"
                           required placeholder="Nombre y apellidos">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Firma <span class="text-muted fw-normal small">(dibuja con el dedo o el ratón)</span>
                    </label>
                    <canvas id="sig-canvas" class="sig-canvas" style="max-width:540px;height:160px"></canvas>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear">
                            <i class="fas fa-eraser me-1"></i>Borrar
                        </button>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Guardar firma y generar PDF
                    </button>
                </div>
            </form>

        </div><!-- /sign-form-wrapper -->

    </div><!-- /doc-card -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var canvas    = document.getElementById('sig-canvas');
    var ctx       = canvas.getContext('2d');
    var drawing   = false;
    var hasSigned = false;

    // Match canvas internal size to its CSS size (important for retina / zoom)
    function resizeCanvas() {
        var rect = canvas.getBoundingClientRect();
        canvas.width  = rect.width;
        canvas.height = rect.height;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    function getPos(e) {
        var r   = canvas.getBoundingClientRect();
        var src = e.touches ? e.touches[0] : e;
        return { x: src.clientX - r.left, y: src.clientY - r.top };
    }

    function startDraw(e) {
        e.preventDefault();
        drawing = true;
        hasSigned = true;
        var p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function draw(e) {
        if (!drawing) return;
        e.preventDefault();
        var p = getPos(e);
        ctx.strokeStyle = '#000';
        ctx.lineWidth   = 2;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }

    function stopDraw() { drawing = false; }

    canvas.addEventListener('mousedown',  startDraw);
    canvas.addEventListener('mousemove',  draw);
    canvas.addEventListener('mouseup',    stopDraw);
    canvas.addEventListener('mouseleave', stopDraw);
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove',  draw,      { passive: false });
    canvas.addEventListener('touchend',   stopDraw);

    document.getElementById('btn-clear').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasSigned = false;
    });

    document.getElementById('sign-form').addEventListener('submit', function (e) {
        if (!hasSigned) {
            e.preventDefault();
            alert('Por favor, dibuja tu firma antes de guardar.');
            return;
        }
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
<?php

// ── PDF generation ─────────────────────────────────────────────────────────────
// $output_path: save to file (mode F) — pass null to stream download (mode D)
function plugin_lagapenak_albaran_pdf($ID, $loan, $condiciones, $output_path = null) {
    if (!class_exists('TCPDF')) {
        $path = GLPI_ROOT . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($path)) {
            require_once($path);
        } else {
            if (!$output_path) { echo 'Error: TCPDF no disponible.'; }
            return;
        }
    }

    $items        = PluginLagapenakLoanItem::getItemsForLoan($ID);
    $solicitante  = plugin_lagapenak_albaran_user_name($loan->fields['users_id']);
    $destinatario = plugin_lagapenak_albaran_user_name($loan->fields['users_id_destinatario']);

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('GLPI Lagapenak');
    $pdf->SetTitle('Albarán #' . $ID);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(20, 15, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // Title
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'Albarán de entrega', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 7, 'Préstamo #' . $ID . ' — ' . $loan->fields['name'], 0, 1, 'C');
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 5, 'Generado el ' . date('d/m/Y H:i'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(5);

    // Loan info
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Datos del préstamo', 0, 1);
    foreach ([
        'Referencia'   => $loan->fields['name'],
        'Solicitante'  => $solicitante,
        'Destinatario' => $destinatario,
        'Estado'       => PluginLagapenakLoan::getStatusName($loan->fields['status']),
        'Fecha inicio' => $loan->fields['fecha_inicio'] ? Html::convDateTime($loan->fields['fecha_inicio']) : '—',
        'Fecha fin'    => $loan->fields['fecha_fin']    ? Html::convDateTime($loan->fields['fecha_fin'])    : '—',
    ] as $label => $value) {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(38, 5.5, $label . ':', 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5.5, $value, 0, 1);
    }
    $pdf->Ln(4);

    // Items table
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Activos entregados', 0, 1);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(35, 6, 'Tipo',       1, 0, 'L', true);
    $pdf->Cell(75, 6, 'Activo',     1, 0, 'L', true);
    $pdf->Cell(35, 6, 'Entrega',    1, 0, 'L', true);
    $pdf->Cell(25, 6, 'Devolución', 1, 1, 'L', true);
    $pdf->SetFont('helvetica', '', 9);
    if (empty($items)) {
        $pdf->Cell(170, 6, 'Sin activos registrados', 1, 1, 'C');
    } else {
        foreach ($items as $item) {
            $tipo    = PluginLagapenakLoanItem::getTypeLabel($item['itemtype']);
            $nombre  = PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id']);
            $entrada = $item['date_checkout'] ? Html::convDateTime($item['date_checkout']) : '—';
            $salida  = $item['date_checkin']  ? Html::convDateTime($item['date_checkin'])  : '—';
            if (mb_strlen($nombre) > 42) { $nombre = mb_substr($nombre, 0, 40) . '…'; }
            $pdf->Cell(35, 6, $tipo,    1, 0);
            $pdf->Cell(75, 6, $nombre,  1, 0);
            $pdf->Cell(35, 6, $entrada, 1, 0);
            $pdf->Cell(25, 6, $salida,  1, 1);
        }
    }
    $pdf->Ln(5);

    // Conditions
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Condiciones de uso', 0, 1);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($condiciones as $i => $c) {
        $pdf->MultiCell(0, 5.5, ($i + 1) . '. ' . $c, 0, 'L');
    }
    $pdf->Ln(5);

    // Signature
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 6, 'Firma del destinatario', 0, 1);

    if (!empty($loan->fields['signature_data'])) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Firmado por: ' . $loan->fields['signature_name'], 0, 1);
        $pdf->Cell(0, 5, 'Fecha firma: ' . Html::convDateTime($loan->fields['signature_date']), 0, 1);
        $pdf->Ln(3);

        $img_data  = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $loan->fields['signature_data']));
        $temp_file = tempnam(sys_get_temp_dir(), 'lagasig_') . '.png';
        file_put_contents($temp_file, $img_data);
        $pdf->Image($temp_file, 20, $pdf->GetY(), 85, 38);
        @unlink($temp_file);
        $pdf->Ln(42);
    } else {
        $y = $pdf->GetY() + 2;
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Rect(20, $y, 80, 35);
        $pdf->SetY($y + 37);
        $pdf->Cell(80, 5, 'Recibido por', 0, 1, 'C');
    }

    if ($output_path) {
        $pdf->Output($output_path, 'F');
    } else {
        $pdf->Output('albaran_prestamo_' . $ID . '.pdf', 'D');
    }
}
