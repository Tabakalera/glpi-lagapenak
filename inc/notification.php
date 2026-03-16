<?php

/**
 * Sends an email to the recipient when a new loan is created.
 * Called from the item_add hook of PluginLagapenakLoan.
 */
function plugin_lagapenak_notify_loan_created($item) {
    $uid = $item->fields['users_id_destinatario'] ?? 0;
    if (!$uid) {
        return;
    }

    // Recipient email
    $email = UserEmail::getDefaultForUser($uid);
    if (!$email) {
        return;
    }

    // Recipient name
    $user = new User();
    $display_name = '';
    if ($user->getFromDB($uid)) {
        $display_name = trim(($user->fields['realname'] ?? '') . ' ' . ($user->fields['firstname'] ?? ''));
        if (!$display_name) $display_name = $user->fields['name'];
    }

    // Dates
    $fecha_rec = !empty($item->fields['fecha_inicio'])
        ? Html::convDate($item->fields['fecha_inicio']) : '—';
    $fecha_dev = !empty($item->fields['fecha_fin'])
        ? Html::convDate($item->fields['fecha_fin']) : '—';

    // Loan link
    $loan_url  = Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php?id=' . (int) $item->getID();
    $loan_name = $item->fields['name'] ?: __('Loan #', 'lagapenak') . $item->getID();

    global $CFG_GLPI;
    $from_email = $CFG_GLPI['admin_email']      ?? '';
    $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Lagapenak';

    // ── Subject ─────────────────────────────────────────────────────────────
    $subject = sprintf(__('New material loan — %s', 'lagapenak'), $loan_name);

    // ── Greeting ────────────────────────────────────────────────────────────
    $saludo = $display_name
        ? sprintf(__('Hello, %s,', 'lagapenak'), htmlspecialchars($display_name))
        : __('Hello,', 'lagapenak');

    $p1  = __('A new material loan has been registered in your name.', 'lagapenak');
    $lbl_loan   = __('Loan', 'lagapenak');
    $lbl_pickup = __('Pickup date', 'lagapenak');
    $lbl_return = __('Return date', 'lagapenak');
    $btn_view   = __('View loan', 'lagapenak');
    $p_footer   = sprintf(__('This message was generated automatically by the %s loan management system.', 'lagapenak'), htmlspecialchars($from_name));

    // ── HTML body ────────────────────────────────────────────────────────────
    $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;">
  <p>{$saludo}</p>
  <p>{$p1}</p>

  <table style="border-collapse:collapse;margin:16px 0;">
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">{$lbl_loan}:</td>
      <td style="padding:4px 0;">{$loan_name}</td>
    </tr>
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">{$lbl_pickup}:</td>
      <td style="padding:4px 0;">{$fecha_rec}</td>
    </tr>
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">{$lbl_return}:</td>
      <td style="padding:4px 0;">{$fecha_dev}</td>
    </tr>
  </table>

  <p style="margin-top:24px;">
    <a href="{$loan_url}" style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
      {$btn_view}
    </a>
  </p>

  <p style="margin-top:32px;font-size:12px;color:#888;">
    {$p_footer}
  </p>
</body>
</html>
HTML;

    // ── Send ─────────────────────────────────────────────────────────────────
    if (!class_exists('GLPIMailer')) {
        return;
    }

    try {
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
        Toolbox::logError('[lagapenak] Error sending loan notification: ' . $e->getMessage());
    }
}

/**
 * Sends a return reminder to the loan requester.
 * Called from the cronLoanReminder cron task.
 */
function plugin_lagapenak_send_loan_reminder(array $loan_row): void {
    $uid = (int) ($loan_row['users_id'] ?? 0);
    if (!$uid) return;

    $email = UserEmail::getDefaultForUser($uid);
    if (!$email) return;

    $user = new User();
    $display_name = '';
    if ($user->getFromDB($uid)) {
        $display_name = trim(($user->fields['realname'] ?? '') . ' ' . ($user->fields['firstname'] ?? ''));
        if (!$display_name) $display_name = $user->fields['name'];
    }

    $fecha_dev = !empty($loan_row['fecha_fin'])
        ? Html::convDateTime($loan_row['fecha_fin']) : '—';
    $loan_name = $loan_row['name'] ?: __('Loan #', 'lagapenak') . $loan_row['id'];
    $loan_url  = Plugin::getWebDir('lagapenak', true) . '/front/loan.form.php?id=' . (int)$loan_row['id'];

    global $CFG_GLPI;
    $from_email = $CFG_GLPI['admin_email']      ?? '';
    $from_name  = $CFG_GLPI['admin_email_name'] ?? 'Lagapenak';

    $subject = sprintf(__('Return reminder — %s', 'lagapenak'), $loan_name);
    $saludo  = $display_name
        ? sprintf(__('Hello, %s,', 'lagapenak'), htmlspecialchars($display_name))
        : __('Hello,', 'lagapenak');

    $p1     = __('This is a reminder that the following material loan is due soon. Please prepare the return of the items.', 'lagapenak');
    $lbl_loan   = __('Loan', 'lagapenak');
    $lbl_return = __('Return date', 'lagapenak');
    $btn_view   = __('View loan', 'lagapenak');
    $p_footer   = sprintf(__('This message was generated automatically by the %s loan management system.', 'lagapenak'), htmlspecialchars($from_name));

    $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#222;line-height:1.6;">
  <p>{$saludo}</p>
  <p>{$p1}</p>

  <table style="border-collapse:collapse;margin:16px 0;">
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">{$lbl_loan}:</td>
      <td style="padding:4px 0;">{$loan_name}</td>
    </tr>
    <tr>
      <td style="font-weight:bold;padding:4px 16px 4px 0;">{$lbl_return}:</td>
      <td style="padding:4px 0;color:#c0392b;font-weight:bold;">{$fecha_dev}</td>
    </tr>
  </table>

  <p style="margin-top:24px;">
    <a href="{$loan_url}" style="background:#1a73e8;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
      {$btn_view}
    </a>
  </p>

  <p style="margin-top:32px;font-size:12px;color:#888;">
    {$p_footer}
  </p>
</body>
</html>
HTML;

    if (!class_exists('GLPIMailer')) return;

    try {
        $mail = new GLPIMailer();
        if ($from_email) $mail->setFrom($from_email, $from_name);
        $mail->addAddress($email, $display_name);
        $mail->addReplyTo($from_email ?: $email, $from_name);
        $mail->Subject  = $subject;
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->Body     = $body;
        $mail->AltBody  = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        $mail->send();
    } catch (\Exception $e) {
        Toolbox::logError('[lagapenak] Error sending return reminder: ' . $e->getMessage());
    }
}
