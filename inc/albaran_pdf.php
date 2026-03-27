<?php

/**
 * Shared PDF generation helpers for the albarán (delivery note).
 * Included by front/albaran.php and inc/notification.php.
 */

// ── TCPDF subclass ────────────────────────────────────────────────────────────
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

// ── Conditions list ───────────────────────────────────────────────────────────
function plugin_lagapenak_get_condiciones(): array {
    return [
        'El uso que se vaya a hacer de los materiales deberá ir acorde con las líneas de trabajo desarrolladas por el centro.',
        'La persona solicitante del material deberá devolver el material en las mismas condiciones en que le fue entregado. En caso de desperfecto el/la solicitante deberá abonar el valor de reposición del elemento o su reparación.',
        'La persona solicitante asumirá cualquier tipo de responsabilidad que pudiera derivarse directa o indirectamente de la actividad que realice con el material prestado.',
        'La recogida y entrega de materiales se hará en las oficinas de Tabakalera de 9h a 15h.',
        'Los materiales fungibles asociados al uso del material correrán por cuenta de la persona usuaria.',
        'La persona solicitante se hará cargo del borrado del material generado en los equipos solicitados. Tabakalera procederá al borrado de las memorias diariamente.',
        'La persona solicitante se compromete a hacer constar la colaboración de Tabakalera, incluyendo su logotipo, en las producciones que deriven del uso del material prestado.',
    ];
}

// ── Image helpers ─────────────────────────────────────────────────────────────
function plugin_lagapenak_find_img($name) {
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        $fs = GLPI_ROOT . '/plugins/lagapenak/pics/' . $name . $ext;
        if (file_exists($fs)) return $fs;
    }
    return null;
}

function plugin_lagapenak_img_web($name) {
    global $CFG_GLPI;
    $root_doc = rtrim($CFG_GLPI['root_doc'] ?? '', '/');
    foreach (['.png', '.jpg', '.jpeg'] as $ext) {
        $fs = GLPI_ROOT . '/plugins/lagapenak/pics/' . $name . $ext;
        if (file_exists($fs)) return $root_doc . '/plugins/lagapenak/pics/' . $name . $ext;
    }
    return null;
}

// ── Name helpers ──────────────────────────────────────────────────────────────
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

// ── PDF generator ─────────────────────────────────────────────────────────────
function plugin_lagapenak_generate_albaran_pdf($loan, $items, $condiciones, $header_fs, $footer_fs, $albaran_loan_fields = []) {

    $footer_h_mm = 0;
    if ($footer_fs) {
        $fi = @getimagesize($footer_fs);
        if ($fi && $fi[0] > 0) {
            $footer_h_mm = min(round(170 * ($fi[1] / $fi[0])), 25);
        } else {
            $footer_h_mm = 18;
        }
    }
    $foot_margin      = $footer_h_mm + 5;
    $autobreak_margin = $foot_margin + 5;

    $pdf = new PluginLagapenakAlbaranPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('Lagapenak');
    $pdf->SetTitle('Cesión de Material #' . $loan->getID());
    $pdf->setPrintHeader(false);
    $pdf->SetMargins(20, 15, 20);
    $pdf->SetAutoPageBreak(true, $autobreak_margin);

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

    if ($header_fs) {
        $hi    = @getimagesize($header_fs);
        $max_w = 90; $max_h = 20;
        $hh_mm = $max_h; $hw_mm = $max_w;
        if ($hi && $hi[0] > 0 && $hi[1] > 0) {
            $aspect = $hi[0] / $hi[1];
            $calc_w = $hh_mm * $aspect;
            if ($calc_w > $max_w) { $hh_mm = $max_w / $aspect; $hw_mm = $max_w; }
            else                  { $hw_mm = $calc_w; }
        }
        $pdf->Image($header_fs, 20, $y_cursor, $hw_mm, $hh_mm);
        $y_cursor += $hh_mm + 6;
    }
    $pdf->SetY($y_cursor);

    $name     = $loan->fields['signature_name']   ?? '';
    $passport = $loan->fields['albaran_passport'] ?? '';
    $project  = $loan->fields['albaran_project']  ?? '';
    $fecha_rec = !empty($loan->fields['fecha_inicio']) ? Html::convDate($loan->fields['fecha_inicio']) : '—';
    $fecha_dev = !empty($loan->fields['fecha_fin'])    ? Html::convDate($loan->fields['fecha_fin'])    : '—';
    $sig_date_txt = !empty($loan->fields['signature_date'])
        ? plugin_lagapenak_date_es($loan->fields['signature_date']) : '';

    $items_li = '';
    foreach ($items as $item) {
        $n = htmlspecialchars(PluginLagapenakLoanItem::getItemName($item['itemtype'], $item['items_id']));
        $items_li .= "<li>1x {$n}</li>";
    }
    if (!$items_li) $items_li = '<li>—</li>';

    $cond_li = '';
    foreach ($condiciones as $c) {
        $cond_li .= '<li style="margin-bottom:2px;">' . htmlspecialchars($c) . '</li>';
    }

    $ben_email_pdf = htmlspecialchars(trim($loan->fields['beneficiary_email'] ?? ''));
    $field_labels  = PluginLagapenakLoan::getFieldLabels();
    $extra_rows    = '';
    if ($ben_email_pdf) {
        $extra_rows .= '  <tr><td style="font-weight:bold;width:48mm;">' . __('Email address', 'lagapenak') . ':</td><td>' . $ben_email_pdf . '</td></tr>';
    }
    foreach ($albaran_loan_fields as $fkey) {
        $fval = htmlspecialchars(trim($loan->fields[$fkey] ?? ''));
        if (!$fval) continue;
        $flabel = htmlspecialchars($field_labels[$fkey] ?? $fkey);
        $extra_rows .= '  <tr><td style="font-weight:bold;width:48mm;">' . $flabel . ':</td><td>' . $fval . '</td></tr>';
    }

    $lbl  = 'font-weight:bold;width:48mm;';
    $html = '
<h3 style="font-size:12pt;letter-spacing:1px;text-transform:uppercase;margin:0 0 8px 0;">' . __('MATERIAL HANDOVER', 'lagapenak') . '</h3>
<table style="width:100%;font-size:10pt;margin-bottom:5px;">
  <tr><td style="' . $lbl . '">' . __('Full name', 'lagapenak') . ':</td><td>' . htmlspecialchars($name) . '</td></tr>
  <tr><td style="' . $lbl . '">' . __('Passport / ID', 'lagapenak') . ':</td><td>' . htmlspecialchars($passport) . '</td></tr>
  <tr><td style="' . $lbl . '">' . __('Project', 'lagapenak') . ':</td><td>' . htmlspecialchars($project) . '</td></tr>
' . $extra_rows . '
</table>
<p style="font-weight:bold;margin:5px 0 2px;font-size:10pt;">' . __('Material / equipment', 'lagapenak') . ':</p>
<ul style="font-size:10pt;margin:0 0 5px 0;">' . $items_li . '</ul>
<table style="width:100%;font-size:10pt;margin-bottom:5px;">
  <tr><td style="' . $lbl . '">' . __('Pickup date', 'lagapenak') . ':</td><td>' . htmlspecialchars($fecha_rec) . '</td></tr>
  <tr><td style="' . $lbl . '">' . __('Return date', 'lagapenak') . ':</td><td>' . htmlspecialchars($fecha_dev) . '</td></tr>
</table>
<hr/>
<p style="font-size:8.5pt;">' . __('The material user agrees to comply with the following rules of use:', 'lagapenak') . '</p>
<ol style="font-size:8.5pt;">' . $cond_li . '</ol>
<p style="font-size:8.5pt;">' . __('The material user confirms acceptance of these rules,', 'lagapenak') . '</p>';

    $pdf->writeHTML($html, true, false, false, false, '');

    if (!empty($loan->fields['signature_data'])) {
        $sig_y      = $pdf->GetY() + 4;
        $sig_data   = $loan->fields['signature_data'];
        $sig_binary = base64_decode(substr($sig_data, strpos($sig_data, ',') + 1));

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
