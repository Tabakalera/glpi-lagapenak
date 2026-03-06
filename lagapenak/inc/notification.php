<?php

/**
 * Envía un email al destinatario cuando se crea un nuevo préstamo.
 * Llamado desde el hook item_add de PluginLagapenakLoan.
 */
function plugin_lagapenak_notify_loan_created($item) {
    $uid = $item->fields['users_id_destinatario'] ?? 0;
    if (!$uid) {
        return;
    }

    // Email del destinatario
    $email = UserEmail::getDefaultForUser($uid);
    if (!$email) {
        return;
    }

    // Nombre del destinatario
    $user = new User();
    $display_name = '';
    if ($user->getFromDB($uid)) {
        $display_name = trim(($user->fields['realname'] ?? '') . ' ' . ($user->fields['firstname'] ?? ''));
        if (!$display_name) $display_name = $user->fields['name'];
    }

    // Fechas
    $fecha_rec = !empty($item->fields['fecha_inicio'])
        ? Html::convDate($item->fields['fecha_inicio']) : '—';
    $fecha_dev = !empty($item->fields['fecha_fin'])
        ? Html::convDate($item->fields['fecha_fin']) : '—';

    // Enlace al préstamo
    $loan_url  = Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php?id=' . (int) $item->getID();
    $loan_name = $item->fields['name'] ?: 'Préstamo #' . $item->getID();

    // ── Asunto ──────────────────────────────────────────────────────────────
    $subject = 'Nuevo préstamo de material — ' . $loan_name;

    // ── Cuerpo HTML ─────────────────────────────────────────────────────────
    $saludo = $display_name ? 'Hola, ' . htmlspecialchars($display_name) . ',' : 'Hola,';

    $body = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;">
  <p>{$saludo}</p>
  <p>Se ha registrado un nuevo préstamo de material a tu nombre en Tabakalera.</p>

  <table style="border-collapse:collapse;margin:16px 0;">
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">Préstamo:</td>
      <td style="padding:4px 0;">{$loan_name}</td>
    </tr>
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">Fecha de recogida:</td>
      <td style="padding:4px 0;">{$fecha_rec}</td>
    </tr>
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">Fecha de devolución:</td>
      <td style="padding:4px 0;">{$fecha_dev}</td>
    </tr>
  </table>

  <p>La recogida y entrega de materiales se realiza en las oficinas de Tabakalera de 9h a 15h.</p>

  <p style="margin-top:24px;">
    <a href="{$loan_url}" style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
      Ver préstamo
    </a>
  </p>

  <p style="margin-top:32px;font-size:12px;color:#888;">
    Este mensaje ha sido generado automáticamente por el sistema de gestión de préstamos de Tabakalera.
  </p>
</body>
</html>
HTML;

    // ── Envío ────────────────────────────────────────────────────────────────
    if (!class_exists('GLPIMailer')) {
        return;
    }

    try {
        global $CFG_GLPI;
        $from_email = $CFG_GLPI['admin_email']      ?? '';
        $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Tabakalera';

        $mail = new GLPIMailer();
        if ($from_email) {
            $mail->setFrom($from_email, $from_name);
        }
        $mail->addAddress($email, $display_name);
        $mail->addReplyTo($from_email ?: $email, $from_name);
        $mail->Subject  = $subject;
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        $mail->send();
    } catch (\Exception $e) {
        Toolbox::logError('[lagapenak] Error al enviar notificación de préstamo: ' . $e->getMessage());
    }
}
