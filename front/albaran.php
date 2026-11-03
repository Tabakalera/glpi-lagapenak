<?php

// Load GLPI for session / DB / classes — standalone HTML page (no Html::header)
include('../../../inc/includes.php');

include_once __DIR__ . '/../inc/albaran_pdf.php';

Session::checkLoginUser();

$ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($ID <= 0) {
    Html::redirect(plugin_lagapenak_web_dir() . '/front/loan.php');
}

$loan = new PluginLagapenakLoan();
if (!$loan->getFromDB($ID)) {
    Html::redirect(plugin_lagapenak_web_dir() . '/front/loan.php');
}

// Access check: supervisor always allowed; others need the albaran right AND
// must be the requester or recipient of this specific loan.
$_alb_uid        = (int)($_SESSION['glpiID'] ?? 0);
$_alb_is_req     = (int)($loan->fields['users_id'] ?? 0) === $_alb_uid;
$_alb_is_dest    = (int)($loan->fields['users_id_destinatario'] ?? 0) === $_alb_uid;
$_alb_supervise  = PluginLagapenakLoan::canManageLoan($loan);
$_alb_has_right  = PluginLagapenakLoan::hasPluginRight('plugin_lagapenak_albaran', READ);
$_alb_allowed    = $_alb_supervise   // canManageLoan(): READ + UPDATE + entity
    || (PluginLagapenakLoan::canView() && $_alb_has_right && ($_alb_is_req || $_alb_is_dest));

if (!$_alb_allowed) {
    Html::redirect(plugin_lagapenak_web_dir() . '/front/loan.php');
}

// ── CAMPOS ADICIONALES DEL ALBARÁN ──────────────────────────────────────────────
// Configura qué campos field_N del préstamo aparecen en el albarán (HTML y PDF).
// Los labels se obtienen automáticamente de PluginLagapenakLoan::getFieldLabels().
// Pon [] para no mostrar ninguno.
$albaran_loan_fields = ['field_1', 'field_2']; // Convocatoria + Proyecto

global $CFG_GLPI;

$condiciones  = plugin_lagapenak_get_condiciones();
$header_fs    = plugin_lagapenak_find_img('albaran_header');
$footer_fs    = plugin_lagapenak_find_img('albaran_footer');
$header_web   = plugin_lagapenak_img_web('albaran_header');
$footer_web   = plugin_lagapenak_img_web('albaran_footer');

// A cancelled request is never signed and gets no signing link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_signature']) || isset($_POST['action_gen_token']))
    && PluginLagapenakLoan::isCancelled($loan->fields)) {
    Session::addMessageAfterRedirect(__('The loan is cancelled: it can no longer be changed.', 'lagapenak'), false, ERROR);
    Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . (int) $loan->getID());
}

// ── POST: save signature ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    $sig_data     = $_POST['signature_data']          ?? '';
    $sig_name     = trim($_UPOST['signature_name']    ?? ($_POST['signature_name']    ?? ''));
    $sig_passport = trim($_UPOST['albaran_passport']  ?? ($_POST['albaran_passport']  ?? ''));
    $sig_project  = trim($_UPOST['albaran_project']   ?? ($_POST['albaran_project']   ?? ''));
    $sig_result   = 'saved';
    if (PluginLagapenakLoan::isSigned($loan->fields)) {
        // A closed signature is never replaced (corrections go through annulment + new version)
        $sig_result = 'already_signed';
    } elseif (PluginLagapenakLoan::isValidSignatureImage($sig_data) && $sig_name !== '') {
        if (PluginLagapenakLoan::recordSignature($ID, $sig_data, $sig_name, $sig_passport, $sig_project)) {
            plugin_lagapenak_send_signed_albaran($ID);
        } else {
            $sig_result = 'already_signed'; // signed meanwhile (other tab, link)
        }
    } else {
        $sig_result = 'sign_invalid';
    }
    Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&' . $sig_result . '=1');
}

// ── Load display data ────────────────────────────────────────────────────────────
$items          = PluginLagapenakLoanItem::getItemsForLoan($ID);
$already_signed = !empty($loan->fields['signature_data']);
$plugin_web     = plugin_lagapenak_web_dir();

$destinatario   = plugin_lagapenak_albaran_user_name($loan->fields['users_id_destinatario']);
$fa_css         = plugin_lagapenak_fontawesome_css_url();
$csrf_token     = Session::getNewCSRFToken();

$disp_name     = htmlspecialchars($loan->fields['signature_name']   ?? '');
$disp_passport = htmlspecialchars($loan->fields['albaran_passport'] ?? '');
$disp_project  = htmlspecialchars($loan->fields['albaran_project']  ?? '');
$disp_sig_date = $loan->fields['signature_date']
    ? plugin_lagapenak_date_es($loan->fields['signature_date']) : '—';

$fecha_recogida   = $loan->fields['fecha_inicio'] ? Html::convDate($loan->fields['fecha_inicio']) : '—';
$fecha_devolucion = $loan->fields['fecha_fin']    ? Html::convDate($loan->fields['fecha_fin'])    : '—';

// Beneficiary fields from loan (pre-filled when loan was created/edited)
$ben_name  = htmlspecialchars(trim($loan->fields['beneficiary_name']  ?? ''));
$ben_email_raw = trim($loan->fields['beneficiary_email'] ?? '');
$ben_email = htmlspecialchars($ben_email_raw);
$ben_dni   = htmlspecialchars(trim($loan->fields['beneficiary_dni']   ?? ''));

// Allowed recipients, shown in the sending dialogs (the handlers above check them again)
$alb_linked_emails = PluginLagapenakLoan::getLinkedEmails($loan);
$alb_signer_email  = PluginLagapenakLoan::getSignerEmail($loan);

// Pre-fill email modal: beneficiary email first, then requester email as fallback
$dest_email_prefill = $ben_email_raw;
if (!$dest_email_prefill && $loan->fields['users_id']) {
    $dest_email_prefill = UserEmail::getDefaultForUser($loan->fields['users_id']) ?: '';
}

// Pre-fill signing form from beneficiary fields (fallback to previously signed values)
$sign_name    = trim($loan->fields['beneficiary_name']  ?? '') ?: ($destinatario !== '—' ? $destinatario : ($loan->fields['signature_name']   ?? ''));
$sign_dni     = trim($loan->fields['beneficiary_dni']   ?? '') ?: ($loan->fields['albaran_passport']  ?? '');
$sign_project = trim($loan->fields['field_2']           ?? '') ?: ($loan->fields['albaran_project']   ?? '');

// Loan field labels (for additional fields in document)
$_alb_field_labels = PluginLagapenakLoan::getFieldLabels();

// ── Action: PDF download ──────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'pdf') {
    if (!$already_signed) {
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID);
    }
    $pdf      = plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs, $albaran_loan_fields);
    $filename = 'albaran_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $loan->fields['name'] ?: 'loan') . '_' . $ID . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ── Action: Generate public signing token and send by email ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_gen_token'])) {
    global $DB, $CFG_GLPI;
    if ($already_signed) {
        // No signing link for a delivery note that is already signed
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&already_signed=1');
    }
    // A signing link only goes to the authorized signer (beneficiary, or the requester when there is
    // none). Showing it on screen, to hand it over by other means, is reserved to the loan's supervisors.
    $signer_email   = PluginLagapenakLoan::getSignerEmail($loan);
    $show_on_screen = !empty($_POST['show_link']);
    $posted_email   = strtolower(trim($_POST['sign_to_email'] ?? ''));
    if ($show_on_screen && !$_alb_supervise) {
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&send_denied=1');
    }
    if (!$show_on_screen && $signer_email === '') {
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&no_signer=1');
    }
    if (!$show_on_screen && $posted_email !== '' && $posted_email !== $signer_email) {
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&send_denied=1');
    }
    $to_email = $show_on_screen ? '' : $signer_email;
    $token    = bin2hex(random_bytes(32));
    $expires  = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $DB->update('glpi_plugin_lagapenak_loans', [
        'sign_token'         => $token,
        'sign_token_expires' => $expires,
    ], ['id' => $ID]);
    $sign_url  = plugin_lagapenak_web_dir(true) . '/front/sign.php?token=' . $token;
    $loan_name = $loan->fields['name'] ?: __('Loan', 'lagapenak') . ' #' . $ID;

    if ($to_email && filter_var($to_email, FILTER_VALIDATE_EMAIL) && class_exists('GLPIMailer')) {
        $from_email = $CFG_GLPI['admin_email']      ?? '';
        $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Lagapenak';
        $subject    = sprintf(__('Please sign the delivery note — %s', 'lagapenak'), $loan_name);
        $body_line  = sprintf(
            __('You have been asked to sign the delivery note for loan <strong>%s</strong>. Please click the button below to sign. The link will expire in 48 hours.', 'lagapenak'),
            htmlspecialchars($loan_name)
        );
        $btn_label  = __('Sign delivery note', 'lagapenak');
        $body = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;">
  <p>{$body_line}</p>
  <p style="margin-top:24px;">
    <a href="{$sign_url}" style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
      {$btn_label}
    </a>
  </p>
</body></html>
HTML;
        // Redirect outside the try: in GLPI 11 Html::redirect() throws an exception
        $sign_result = 'sign_sent';
        try {
            plugin_lagapenak_send_mail([
                'to_email'   => $to_email,
                'from_email' => $from_email,
                'from_name'  => $from_name,
                'subject'    => $subject,
                'html'       => $body,
                'text'       => strip_tags($body_line) . "\n\n" . $sign_url,
            ]);
        } catch (\Throwable $e) {
            plugin_lagapenak_log_error('[lagapenak] Error sending sign link email: ' . $e->getMessage());
            $sign_result = 'sign_error';
        }
        if ($sign_result === 'sign_sent') {
            PluginLagapenakLoan::logDocumentSend($ID, __('Signing link', 'lagapenak'), $to_email);
        }
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&' . $sign_result . '=1');
    }
    // Supervisor asked to see the link — show it once (kept out of the URL, browser history and access logs)
    PluginLagapenakLoan::logDocumentSend($ID, __('Signing link', 'lagapenak'), '');
    $_SESSION['plugin_lagapenak_sign_link'][$ID] = $sign_url;
    Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&sign_link=1');
}

// ── Action: Send by email ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_email'])) {
    // Recipients: an address linked to the loan; any other address only by a supervisor of the loan's
    // entity, with a stated reason that goes to the audit log
    $to_email    = strtolower(trim($_POST['to_email'] ?? ''));
    $other_email = strtolower(trim($_POST['to_email_other'] ?? ''));
    if ($other_email !== '') {
        $to_email = $other_email;
    }
    $send_reason = trim(str_replace(['<', '>'], '', strip_tags($_UPOST['send_reason'] ?? ($_POST['send_reason'] ?? ''))));
    $send_reason = mb_substr($send_reason, 0, 250);
    $exceptional = !isset(PluginLagapenakLoan::getLinkedEmails($loan)[$to_email]);
    if ($exceptional && (!$_alb_supervise || mb_strlen($send_reason) < 10)) {
        Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&send_denied=1');
    }
    if ($to_email && filter_var($to_email, FILTER_VALIDATE_EMAIL) && $already_signed) {
        $pdf         = plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs, $albaran_loan_fields);
        $pdf_content = $pdf->Output('', 'S');
        $filename    = 'albaran_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $loan->fields['name'] ?: 'loan') . '_' . $ID . '.pdf';
        $loan_name   = $loan->fields['name'] ?: __('Loan', 'lagapenak') . ' #' . $ID;

        global $CFG_GLPI;
        $from_email = $CFG_GLPI['admin_email']      ?? '';
        $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Lagapenak';

        if (class_exists('GLPIMailer')) {
            $body_line = sprintf(
                __('Please find attached the delivery note for loan <strong>%s</strong>.', 'lagapenak'),
                htmlspecialchars($loan_name)
            );
            // Redirect outside the try: in GLPI 11 Html::redirect() throws an exception
            $email_result = 'email_sent';
            try {
                plugin_lagapenak_send_mail([
                    'to_email'    => $to_email,
                    'from_email'  => $from_email,
                    'from_name'   => $from_name,
                    'subject'     => sprintf(__('Delivery note — %s', 'lagapenak'), $loan_name),
                    'html'        => '<p>' . $body_line . '</p>',
                    'text'        => strip_tags($body_line),
                    'attachments' => [[$pdf_content, $filename, 'application/pdf']],
                ]);
            } catch (\Throwable $e) {
                plugin_lagapenak_log_error('[lagapenak] Error sending albaran email: ' . $e->getMessage());
                $email_result = 'email_error';
            }
            if ($email_result === 'email_sent') {
                PluginLagapenakLoan::logDocumentSend(
                    $ID, __('Signed delivery note', 'lagapenak'), $to_email, $exceptional ? $send_reason : ''
                );
            }
            Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID . '&' . $email_result . '=1');
        }
    }
    Html::redirect(plugin_lagapenak_web_dir() . '/front/albaran.php?id=' . $ID);
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
            <i class="fas fa-file-pdf me-1"></i><?= __('Download PDF', 'lagapenak') ?>
        </a>
        <button type="button" class="btn btn-sm btn-outline-primary"
                data-bs-toggle="modal" data-bs-target="#emailModal">
            <i class="fas fa-envelope me-1"></i><?= __('Send by email', 'lagapenak') ?>
        </button>
        <?php endif; ?>
        <?php if (!$already_signed): ?>
        <button type="button" class="btn btn-sm btn-outline-info"
                data-bs-toggle="modal" data-bs-target="#signLinkModal">
            <i class="fas fa-share-alt me-1"></i><?= __('Send signing link', 'lagapenak') ?>
        </button>
        <?php endif; ?>
    </div>

    <?php $sign_link_once = $_SESSION['plugin_lagapenak_sign_link'][$ID] ?? '';
          unset($_SESSION['plugin_lagapenak_sign_link'][$ID]); ?>
    <?php if (isset($_GET['sign_link']) && $sign_link_once !== '' && !$already_signed): ?>
    <div class="alert alert-info no-print">
        <strong><i class="fas fa-share-alt me-2"></i><?= __('Signing link generated (valid 48 hours)', 'lagapenak') ?>:</strong>
        <div class="mt-2 d-flex gap-2 align-items-center flex-wrap">
            <input type="text" class="form-control form-control-sm" id="sign-url-input"
                   value="<?= htmlspecialchars($sign_link_once) ?>" readonly style="max-width:520px;">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="navigator.clipboard.writeText(document.getElementById('sign-url-input').value);this.innerHTML='<i class=\'fas fa-check me-1\'></i><?= __('Copied', 'lagapenak') ?>'">
                <i class="fas fa-copy me-1"></i><?= __('Copy', 'lagapenak') ?>
            </button>
        </div>
        <div class="form-text mt-1"><?= __('Share this link with the person who needs to sign. No GLPI login required.', 'lagapenak') ?></div>
    </div>
    <?php elseif (isset($_GET['saved'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i><?= __('Signature saved successfully.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['already_signed'])): ?>
    <div class="alert alert-warning no-print">
        <i class="fas fa-lock me-2"></i><?= __('This delivery note is already signed. A signed delivery note cannot be signed again or replaced.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['send_denied'])): ?>
    <div class="alert alert-danger no-print">
        <i class="fas fa-ban me-2"></i><?= __('Not sent: the delivery note can only be sent to addresses linked to the loan, and the signing link only to the signer. A supervisor may use another address by stating the reason.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['no_signer'])): ?>
    <div class="alert alert-warning no-print">
        <i class="fas fa-exclamation-triangle me-2"></i><?= __('The signer has no email address: add the beneficiary\'s email to the loan.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['sign_invalid'])): ?>
    <div class="alert alert-danger no-print">
        <i class="fas fa-exclamation-circle me-2"></i><?= __('The signature could not be saved: a name and a drawn signature are required.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['email_sent'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i><?= __('Delivery note sent successfully by email.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['sign_sent'])): ?>
    <div class="alert alert-success no-print">
        <i class="fas fa-check-circle me-2"></i><?= __('Signing link sent by email successfully.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['sign_error'])): ?>
    <div class="alert alert-danger no-print">
        <i class="fas fa-exclamation-circle me-2"></i><?= __('Error sending signing link. Please check the email settings in GLPI.', 'lagapenak') ?>
    </div>
    <?php elseif (isset($_GET['email_error'])): ?>
    <div class="alert alert-danger no-print">
        <i class="fas fa-exclamation-circle me-2"></i><?= __('Error sending email. Please check the email settings in GLPI.', 'lagapenak') ?>
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
                <?= __('MATERIAL HANDOVER', 'lagapenak') ?>
            </h5>

            <!-- ── SIGNED: show stored values ── -->
            <?php if ($already_signed): ?>
            <div class="field-row"><span class="field-lbl"><?= __('Full name', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_name ?></span></div>
            <div class="field-row"><span class="field-lbl"><?= __('Passport / ID', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_passport ?: '&nbsp;' ?></span></div>
            <div class="field-row"><span class="field-lbl"><?= __('Project', 'lagapenak') ?>:</span><span class="field-val"><?= $disp_project ?: '&nbsp;' ?></span></div>
            <?php if ($ben_email): ?>
            <div class="field-row"><span class="field-lbl"><?= __('Email address', 'lagapenak') ?>:</span><span class="field-val"><?= $ben_email ?></span></div>
            <?php endif; ?>
            <?php foreach ($albaran_loan_fields as $_af_key): ?>
            <?php $_af_val = htmlspecialchars(trim($loan->fields[$_af_key] ?? '')); if (!$_af_val) continue; ?>
            <div class="field-row"><span class="field-lbl"><?= htmlspecialchars($_alb_field_labels[$_af_key] ?? $_af_key) ?>:</span><span class="field-val"><?= $_af_val ?></span></div>
            <?php endforeach; ?>
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
                <?= __('The material user agrees to comply with the following rules of use:', 'lagapenak') ?>
            </p>
            <ul class="cond-list ps-4">
                <?php foreach ($condiciones as $c): ?>
                <li><?= htmlspecialchars($c) ?></li>
                <?php endforeach; ?>
            </ul>

            <p class="mt-4" style="font-size:.9rem;"><?= __('The material user confirms acceptance of these rules,', 'lagapenak') ?></p>

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
            <?php else: ?>
            <div id="sign-form-wrapper">

                <!-- Sign form -->
                <form method="POST" action="" id="sign-form" class="mt-3">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="save_signature" value="1">
                    <input type="hidden" name="signature_data" id="signature_data_input">

                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold"><?= __('Full name', 'lagapenak') ?> <span class="text-danger">*</span></label>
                            <input type="text" name="signature_name" class="form-control"
                                   value="<?= htmlspecialchars($sign_name) ?>"
                                   required placeholder="<?= __('Full name', 'lagapenak') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><?= __('Passport / ID', 'lagapenak') ?></label>
                            <input type="text" name="albaran_passport" class="form-control"
                                   value="<?= htmlspecialchars($sign_dni) ?>" placeholder="<?= __('Number', 'lagapenak') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?= __('Project', 'lagapenak') ?></label>
                            <input type="text" name="albaran_project" class="form-control"
                                   value="<?= htmlspecialchars($sign_project) ?>" placeholder="<?= __('Project name', 'lagapenak') ?>">
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
            <?php endif; ?>

        </div><!-- /doc-body -->

        <!-- Footer image (optional) -->
        <?php if ($footer_web): ?>
        <div style="padding:8px 20px 16px;">
            <img src="<?= htmlspecialchars($footer_web) ?>" alt="" style="display:block;width:100%;height:auto;">
        </div>
        <?php endif; ?>

    </div><!-- /doc-card -->
</div><!-- /container -->

<!-- Sign link modal -->
<div class="modal fade no-print" id="signLinkModal" tabindex="-1" aria-labelledby="signLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="signLinkModalLabel">
                    <i class="fas fa-share-alt me-2"></i><?= __('Send signing link by email', 'lagapenak') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
                <input type="hidden" name="action_gen_token" value="1">
                <div class="modal-body">
                    <?php if ($alb_signer_email !== ''): ?>
                    <label class="form-label fw-semibold"><?= __('The link will be sent to the signer', 'lagapenak') ?></label>
                    <input type="email" name="sign_to_email" class="form-control" readonly
                           value="<?= htmlspecialchars($alb_signer_email) ?>">
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i><?= __('The signer has no email address: add the beneficiary\'s email to the loan.', 'lagapenak') ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-text mt-2">
                        <i class="fas fa-clock me-1"></i><?= __('The link will be valid for 48 hours and can only be used once.', 'lagapenak') ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('Cancel', 'lagapenak') ?></button>
                    <?php if ($_alb_supervise): ?>
                    <button type="submit" name="show_link" value="1" class="btn btn-outline-secondary" formnovalidate>
                        <i class="fas fa-eye me-1"></i><?= __('Show link on screen', 'lagapenak') ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($alb_signer_email !== ''): ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i><?= __('Send', 'lagapenak') ?>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Email modal -->
<div class="modal fade no-print" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">
                    <i class="fas fa-envelope me-2"></i><?= __('Send delivery note by email', 'lagapenak') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">
                <input type="hidden" name="action_email" value="1">
                <div class="modal-body">
                    <label class="form-label fw-semibold">
                        <?= __('Email address', 'lagapenak') ?> <span class="text-danger">*</span>
                    </label>
                    <select name="to_email" class="form-select">
                        <?php foreach ($alb_linked_emails as $_le_email => $_le_role): ?>
                        <option value="<?= htmlspecialchars($_le_email) ?>"<?= $_le_email === strtolower($dest_email_prefill) ? ' selected' : '' ?>>
                            <?= htmlspecialchars($_le_role . ' — ' . $_le_email) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($_alb_supervise): ?>
                    <div class="mt-3 p-2 border rounded bg-light">
                        <label class="form-label fw-semibold mb-1"><?= __('Other address (exceptional)', 'lagapenak') ?></label>
                        <input type="email" name="to_email_other" class="form-control mb-2" placeholder="recipient@example.com">
                        <label class="form-label fw-semibold mb-1"><?= __('Reason (required for another address, recorded in the log)', 'lagapenak') ?></label>
                        <textarea name="send_reason" class="form-control" rows="2" maxlength="250"></textarea>
                    </div>
                    <?php endif; ?>
                    <div class="form-text mt-2">
                        <i class="fas fa-paperclip me-1"></i><?= __('The delivery note will be attached as a PDF.', 'lagapenak') ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('Cancel', 'lagapenak') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i><?= __('Send', 'lagapenak') ?>
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
})();
</script>
</body>
</html>
