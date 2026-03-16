<?php

// Load GLPI for session / DB / classes — standalone HTML page (no Html::header)
include('../../../inc/includes.php');

// Custom TCPDF subclass — renders footer image on every page
if (!class_exists('PluginLagapenakAlbaranPDF')) {
    class PluginLagapenakAlbaranPDF extends TCPDF {
        public $alb_footer_image = null;
        public $alb_footer_h     = 20; // mm

        public function Footer() {
            if ($this->alb_footer_image && file_exists($this->alb_footer_image)) {
                $pg_h = $this->getPageHeight();
                $this->Image(
                    $this->alb_footer_image,
                    20,
                    $pg_h - 4 - $this->alb_footer_h,
                    170,
                    $this->alb_footer_h
                );
            }
        }
    }
}

Session::checkLoginUser();

$ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($ID <= 0) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

$loan = new PluginLagapenakLoan();
if (!$loan->getFromDB($ID)) {
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/loan.php');
}

// Access check: supervisor always allowed; others need the albaran right AND
// must be the requester or recipient of this specific loan.
$_alb_uid        = (int)($_SESSION['glpiID'] ?? 0);
$_alb_is_req     = (int)($loan->fields['users_id'] ?? 0) === $_alb_uid;
$_alb_is_dest    = (int)($loan->fields['users_id_destinatario'] ?? 0) === $_alb_uid;
$_alb_supervise  = PluginLagapenakLoan::canSupervise();
$_alb_has_right  = PluginLagapenakLoan::hasPluginRight('plugin_lagapenak_albaran', READ);
$_alb_allowed    = $_alb_supervise || ($_alb_has_right && ($_alb_is_req || $_alb_is_dest));

if (!$_alb_allowed) {
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

// ── PDF GENERATOR ────────────────────────────────────────────────────────────────
function plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs) {

    // Pre-compute footer height from image aspect ratio
    $footer_h_mm = 0;
    if ($footer_fs) {
        $fi = @getimagesize($footer_fs);
        if ($fi && $fi[0] > 0) {
            $footer_h_mm = min(round(170 * ($fi[1] / $fi[0])), 25);
        } else {
            $footer_h_mm = 18;
        }
    }
    $foot_margin     = $footer_h_mm + 5;  // footer area height at bottom of page
    $autobreak_margin = $foot_margin + 5; // content stops this far from bottom

    $pdf = new PluginLagapenakAlbaranPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('Lagapenak');
    $pdf->SetTitle('Cesión de Material #' . $loan->getID());
    $pdf->setPrintHeader(false);
    $pdf->SetMargins(20, 15, 20);
    $pdf->SetAutoPageBreak(true, $autobreak_margin);

    // Attach footer image to every page via custom Footer() method
    if ($footer_fs) {
        $pdf->setPrintFooter(true);
        $pdf->SetFooterMargin($foot_margin);
        $pdf->alb_footer_image = $footer_fs;
        $pdf->alb_footer_h     = $footer_h_mm;
    } else {
        $pdf->setPrintFooter(false);
    }

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $y_cursor = 12;

    // Header image — small logo, LEFT side only (max 90 mm wide, max 20 mm tall)
    if ($header_fs) {
        $hi      = @getimagesize($header_fs);
        $max_w   = 90;
        $max_h   = 20;
        $hh_mm   = $max_h;
        $hw_mm   = $max_w;
        if ($hi && $hi[0] > 0 && $hi[1] > 0) {
            $aspect  = $hi[0] / $hi[1]; // w/h
            $calc_w  = $hh_mm * $aspect;
            if ($calc_w > $max_w) {
                $hh_mm = $max_w / $aspect;
                $hw_mm = $max_w;
            } else {
                $hw_mm = $calc_w;
            }
        }
        $pdf->Image($header_fs, 20, $y_cursor, $hw_mm, $hh_mm);
        $y_cursor += $hh_mm + 6;
    }
    $pdf->SetY($y_cursor);

    // Prepare data
    $name     = $loan->fields['signature_name']   ?? '';
    $passport = $loan->fields['albaran_passport'] ?? '';
    $project  = $loan->fields['albaran_project']  ?? '';
    $fecha_rec = !empty($loan->fields['fecha_inicio']) ? Html::convDate($loan->fields['fecha_inicio']) : '—';
    $fecha_dev = !empty($loan->fields['fecha_fin'])    ? Html::convDate($loan->fields['fecha_fin'])    : '—';
    $sig_date_txt = !empty($loan->fields['signature_date'])
        ? plugin_lagapenak_date_es($loan->fields['signature_date']) : '';

    // Items list
    $items_li = '';
    foreach ($items as $item) {
        $n = htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id']));
        $items_li .= "<li>1x {$n}</li>";
    }
    if (!$items_li) $items_li = '<li>—</li>';

    // Conditions list
    $cond_li = '';
    foreach ($condiciones as $c) {
        $cond_li .= '<li style="margin-bottom:2px;">' . htmlspecialchars($c) . '</li>';
    }

    $lbl = 'font-weight:bold;width:48mm;';
    $html = '
<h3 style="font-size:12pt;letter-spacing:1px;text-transform:uppercase;margin:0 0 8px 0;">CESIÓN DE MATERIAL</h3>
<table style="width:100%;font-size:10pt;margin-bottom:5px;">
  <tr><td style="' . $lbl . '">Nombre completo:</td><td>' . htmlspecialchars($name) . '</td></tr>
  <tr><td style="' . $lbl . '">DNI / Pasaporte:</td><td>' . htmlspecialchars($passport) . '</td></tr>
  <tr><td style="' . $lbl . '">Proyecto:</td><td>' . htmlspecialchars($project) . '</td></tr>
</table>
<p style="font-weight:bold;margin:5px 0 2px;font-size:10pt;">Material / equipos:</p>
<ul style="font-size:10pt;margin:0 0 5px 0;">' . $items_li . '</ul>
<table style="width:100%;font-size:10pt;margin-bottom:5px;">
  <tr><td style="' . $lbl . '">Fecha de recogida:</td><td>' . htmlspecialchars($fecha_rec) . '</td></tr>
  <tr><td style="' . $lbl . '">Fecha de devolución:</td><td>' . htmlspecialchars($fecha_dev) . '</td></tr>
</table>
<hr/>
<p style="font-size:8.5pt;">El usuario del material se compromete a cumplir las siguientes normas de uso y funcionamiento de Tabakalera:</p>
<ol style="font-size:8.5pt;">' . $cond_li . '</ol>
<p style="font-size:8.5pt;">El usuario del material confirma aceptar estas normas,</p>';

    // $reseth=false so GetY() returns position AFTER the rendered content
    $pdf->writeHTML($html, true, false, false, false, '');

    // Signature image + name + date
    if (!empty($loan->fields['signature_data'])) {
        $sig_y      = $pdf->GetY() + 4;
        $sig_data   = $loan->fields['signature_data'];
        $sig_binary = base64_decode(substr($sig_data, strpos($sig_data, ',') + 1));

        // Add white background to transparent canvas PNG so it renders correctly
        $sig_gd = @imagecreatefromstring($sig_binary);
        if ($sig_gd) {
            $white = imagecreatetruecolor(imagesx($sig_gd), imagesy($sig_gd));
            imagefill($white, 0, 0, imagecolorallocate($white, 255, 255, 255));
            imagecopy($white, $sig_gd, 0, 0, 0, 0, imagesx($sig_gd), imagesy($sig_gd));
            ob_start();
            imagepng($white);
            $sig_binary = ob_get_clean();
            imagedestroy($sig_gd);
            imagedestroy($white);
        }

        $pdf->Image('@' . $sig_binary, 20, $sig_y, 70, 25, 'PNG');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(20, $sig_y + 27);
        $pdf->Cell(70, 5, $name, 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(110, $sig_y + 10);
        $pdf->MultiCell(80, 5, "Donostia/San Sebastián, a\n" . $sig_date_txt, 0, 'R');
    }

    return $pdf;
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

// Pre-fill email modal with requester's email (editable)
$dest_email_prefill = '';
if ($loan->fields['users_id']) {
    $dest_email_prefill = UserEmail::getDefaultForUser($loan->fields['users_id']) ?: '';
}

// ── Action: PDF download ──────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'pdf') {
    if (!$already_signed) {
        Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID);
    }
    $pdf      = plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs);
    $filename = 'albaran_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $loan->fields['name'] ?: 'loan') . '_' . $ID . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ── Action: Send by email ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_email'])) {
    $to_email = trim($_POST['to_email'] ?? '');
    if ($to_email && filter_var($to_email, FILTER_VALIDATE_EMAIL) && $already_signed) {
        $pdf         = plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs);
        $pdf_content = $pdf->Output('', 'S');
        $filename    = 'albaran_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $loan->fields['name'] ?: 'loan') . '_' . $ID . '.pdf';
        $loan_name   = $loan->fields['name'] ?: __('Loan', 'lagapenak') . ' #' . $ID;

        global $CFG_GLPI;
        $from_email = $CFG_GLPI['admin_email']      ?? '';
        $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Lagapenak';

        if (class_exists('GLPIMailer')) {
            try {
                $mail = new GLPIMailer();
                if ($from_email) $mail->setFrom($from_email, $from_name);
                $mail->addAddress($to_email);
                $mail->Subject  = sprintf(__('Delivery note — %s', 'lagapenak'), $loan_name);
                $mail->CharSet  = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->isHTML(true);
                $body_line = sprintf(
                    __('Please find attached the delivery note for loan <strong>%s</strong>.', 'lagapenak'),
                    htmlspecialchars($loan_name)
                );
                $mail->Body    = '<p>' . $body_line . '</p>';
                $mail->AltBody = strip_tags($body_line);
                $mail->addStringAttachment($pdf_content, $filename, 'base64', 'application/pdf');
                $mail->send();
                Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID . '&email_sent=1');
            } catch (\Exception $e) {
                Toolbox::logError('[lagapenak] Error sending albaran email: ' . $e->getMessage());
                Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID . '&email_error=1');
            }
            exit;
        }
    }
    Html::redirect(Plugin::getWebDir('lagapenak') . '/front/albaran.php?id=' . $ID);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= __('Delivery note', 'lagapenak') ?> — <?= __('Loan', 'lagapenak') ?> #<?= $ID ?></title>
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
            <i class="fas fa-arrow-left me-1"></i><?= __('Back to loan', 'lagapenak') ?>
        </a>
        <?php if ($already_signed): ?>
        <a href="?action=pdf&id=<?= $ID ?>" class="btn btn-sm btn-outline-success">
            <i class="fas fa-file-pdf me-1"></i>Descargar PDF
        </a>
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#emailModal">
            <i class="fas fa-envelope me-1"></i>Enviar por correo
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i><?= __('Signature saved successfully.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['email_sent'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i>Albarán enviado correctamente por correo.
    </div>
    <?php elseif (isset($_GET['email_error'])): ?>
    <div class="alert alert-danger no-print">
        <i class="fas fa-exclamation-circle me-2"></i>Error al enviar el correo. Revisa la configuración de email en GLPI.
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
            <div class="field-row"><span class="field-lbl"><?= __('Full name', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_name ?></span></div>
            <div class="field-row"><span class="field-lbl"><?= __('Passport / ID', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_passport ?: '&nbsp;' ?></span></div>
            <div class="field-row"><span class="field-lbl"><?= __('Project', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_project ?: '&nbsp;' ?></span></div>
            <?php endif; ?>

            <!-- Material list (always shown) -->
            <div class="field-row mt-1"><span class="field-lbl"><?= __('Material / equipment', 'lagapenak') ?>:</span></div>
            <?php if (empty($items)): ?>
            <div class="ms-3 text-muted small">— <?= __('No assets', 'lagapenak') ?> —</div>
            <?php else: foreach ($items as $item): ?>
            <div class="ms-3" style="font-size:.92rem;">
                -1x <?= htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id'])) ?>
            </div>
            <?php endforeach; endif; ?>

            <!-- Dates (always shown) -->
            <div class="mt-3">
                <div class="field-row"><span class="field-lbl"><?= __('Pickup date', 'lagapenak') ?>:</span><span class="field-val"><?= htmlspecialchars($fecha_recogida) ?></span></div>
                <div class="field-row"><span class="field-lbl"><?= __('Return date', 'lagapenak') ?>:</span><span class="field-val"><?= htmlspecialchars($fecha_devolucion) ?></span></div>
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
                    <i class="fas fa-redo me-1"></i><?= __('Sign again (overwrites existing)', 'lagapenak') ?>
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
                            <label class="form-label fw-semibold"><?= __('Full name', 'lagapenak') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="signature_name" class="form-control"
                                   value="<?= htmlspecialchars($destinatario !== '—' ? $destinatario : ($disp_name ?: '')) ?>"
                                   required placeholder="<?= __('Full name', 'lagapenak') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= __('Passport / ID', 'lagapenak') ?></label>
                            <input type="text" name="albaran_passport" class="form-control"
                                   value="<?= $disp_passport ?>" placeholder="<?= __('Number', 'lagapenak') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= __('Project', 'lagapenak') ?></label>
                            <input type="text" name="albaran_project" class="form-control"
                                   value="<?= $disp_project ?>" placeholder="<?= __('Project name', 'lagapenak') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <?= __('Signature', 'lagapenak') ?> <span class="text-muted fw-normal small">(<?= __('draw with your finger or mouse', 'lagapenak') ?>)</span>
                        </label>
                        <canvas id="sig-canvas" class="sig-canvas" style="max-width:420px;"></canvas>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear">
                                <i class="fas fa-eraser me-1"></i><?= __('Clear', 'lagapenak') ?>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><?= __('Save signature and generate PDF', 'lagapenak') ?>
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

<!-- Email modal -->
<div class="modal fade no-print" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">
                    <i class="fas fa-envelope me-2"></i>Enviar albarán por correo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
                <input type="hidden" name="action_email" value="1">
                <div class="modal-body">
                    <label class="form-label fw-semibold">
                        Correo electrónico <span class="text-danger">*</span>
                    </label>
                    <input type="email" name="to_email" class="form-control" required
                           placeholder="destinatario@ejemplo.com"
                           value="<?= htmlspecialchars($dest_email_prefill) ?>">
                    <div class="form-text mt-2">
                        <i class="fas fa-paperclip me-1"></i>El albarán se adjuntará como PDF al correo.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i>Enviar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
        if (!hasSigned) { e.preventDefault(); alert('<?= __('Please draw your signature before saving.', 'lagapenak') ?>'); return; }
        document.getElementById('signature_data_input').value = canvas.toDataURL('image/png');
    });

    var resignBtn = document.getElementById('btn-resign');
    if (resignBtn) {
        resignBtn.addEventListener('click', function () {
            document.getElementById('sign-form-wrapper').style.display = 'block';
            resignBtn.style.display = 'none';
            resizeCanvas(); // canvas was hidden on load → recalculate dimensions now
        });
    }
})();
</script>
</body>
</html>
