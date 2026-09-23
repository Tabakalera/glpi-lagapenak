<?php

/**
 * Compatibility helpers so the plugin runs on both GLPI 10.0.x and GLPI 11.x.
 */

function plugin_lagapenak_is_glpi11(): bool {
    return version_compare(GLPI_VERSION, '11.0.0-dev', '>=');
}

/**
 * Web path of the plugin (replacement for the deprecated Plugin::getWebDir()).
 *
 * @param bool $use_url_base  true = absolute URL (for emails, iCal...), false = path from web root
 */
function plugin_lagapenak_web_dir(bool $use_url_base = false): string {
    global $CFG_GLPI;

    $dir = 'plugins';
    if (defined('GLPI_MARKETPLACE_DIR')) {
        $marketplace = realpath(GLPI_MARKETPLACE_DIR);
        $plugin_dir  = realpath(__DIR__ . '/..');
        if ($marketplace && $plugin_dir && strpos($plugin_dir, $marketplace) === 0) {
            $dir = 'marketplace';
        }
    }

    $root = $use_url_base ? ($CFG_GLPI['url_base'] ?? '') : ($CFG_GLPI['root_doc'] ?? '');
    return rtrim($root, '/') . '/' . $dir . '/lagapenak';
}

/**
 * URL of a file stored in the plugin "public" folder (images...).
 * GLPI 11 serves it without the "/public" prefix; GLPI 10 needs it.
 */
function plugin_lagapenak_public_url(string $path, bool $use_url_base = false): string {
    return plugin_lagapenak_web_dir($use_url_base)
        . (plugin_lagapenak_is_glpi11() ? '' : '/public')
        . '/' . ltrim($path, '/');
}

/**
 * Filesystem path of a file stored in the plugin "public" folder.
 */
function plugin_lagapenak_public_path(string $path): string {
    return dirname(__DIR__) . '/public/' . ltrim($path, '/');
}

/**
 * URL of a library bundled with GLPI core (public/lib/...).
 */
function plugin_lagapenak_core_lib_url(string $path): string {
    global $CFG_GLPI;
    return rtrim($CFG_GLPI['root_doc'] ?? '', '/')
        . (plugin_lagapenak_is_glpi11() ? '' : '/public')
        . '/lib/' . ltrim($path, '/');
}

/**
 * Write an error line to files/_log/lagapenak.log (Toolbox::logError() was removed in GLPI 11).
 */
function plugin_lagapenak_log_error(string $message): void {
    Toolbox::logInFile('lagapenak', $message . "\n");
}

/**
 * Run a self-crafted SQL query and fail loudly on error (replacement for queryOrDie()).
 *
 * @return mysqli_result|bool
 */
function plugin_lagapenak_query_or_fail(string $sql) {
    global $DB;
    $result = $DB->doQuery($sql);
    if ($result === false) {
        throw new RuntimeException('Lagapenak SQL error: ' . $DB->error() . ' — ' . $sql);
    }
    return $result;
}

/**
 * Stop the current page with an "access denied" error.
 */
function plugin_lagapenak_access_denied(): void {
    if (plugin_lagapenak_is_glpi11()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Html::displayRightError();
}

/**
 * Send an HTML email through GLPIMailer.
 * GLPI 10 exposes the PHPMailer API; GLPI 11 uses Symfony Mailer (the PHPMailer-like API is deprecated
 * there and crashes on null names / unsupported methods such as addStringAttachment()).
 *
 * @param array $o Keys: to_email, to_name, from_email, from_name, reply_to_email, reply_to_name,
 *                 subject, html, text, attachments (list of [content, filename, mime])
 *
 * @throws RuntimeException when the message could not be sent
 */
function plugin_lagapenak_send_mail(array $o): void {
    $to_email = (string) ($o['to_email'] ?? '');
    if ($to_email === '') {
        throw new RuntimeException('Missing recipient');
    }
    $to_name     = (string) ($o['to_name'] ?? '');
    $from_email  = (string) ($o['from_email'] ?? '');
    $from_name   = (string) ($o['from_name'] ?? '');
    $reply_email = (string) ($o['reply_to_email'] ?? '');
    $reply_name  = (string) ($o['reply_to_name'] ?? '');
    $subject     = (string) ($o['subject'] ?? '');
    $html        = (string) ($o['html'] ?? '');
    $text        = (string) ($o['text'] ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    $attachments = $o['attachments'] ?? [];

    $mail = new GLPIMailer();

    if (plugin_lagapenak_is_glpi11()) {
        $email = $mail->getEmail();
        if ($from_email !== '') {
            $email->from(new \Symfony\Component\Mime\Address($from_email, $from_name));
        }
        $email->to(new \Symfony\Component\Mime\Address($to_email, $to_name));
        if ($reply_email !== '') {
            $email->replyTo(new \Symfony\Component\Mime\Address($reply_email, $reply_name));
        }
        $email->subject($subject);
        $email->html($html);
        $email->text($text);
        foreach ($attachments as [$content, $filename, $mime]) {
            $email->attach($content, $filename, $mime);
        }
        if (!$mail->send()) {
            throw new RuntimeException($mail->getError() ?? 'Unknown mail error');
        }
        return;
    }

    if ($from_email !== '') {
        $mail->setFrom($from_email, $from_name);
    }
    $mail->addAddress($to_email, $to_name);
    if ($reply_email !== '') {
        $mail->addReplyTo($reply_email, $reply_name);
    }
    $mail->Subject  = $subject;
    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $text;
    foreach ($attachments as [$content, $filename, $mime]) {
        $mail->addStringAttachment($content, $filename, 'base64', $mime);
    }
    if (!$mail->send()) {
        throw new RuntimeException($mail->ErrorInfo ?: 'Unknown mail error');
    }
}
