<?php
// Comprueba la implementación PHP del plugin (PluginLagapenakBeneficiary) contra los vectores compartidos.
// Uso (dentro del contenedor GLPI): php php_vectores.php <inc/beneficiary.class.php> <g1-hmac-vectors.json>
// Sale con código 1 si algún caso no coincide (estado, valor normalizado o huella).
require $argv[1];
$v   = json_decode(file_get_contents($argv[2]), true);
$key = base64_decode($v['test_key_base64']);
$ok  = $ko = 0;
foreach (['email' => 'normalizeEmail', 'tax_id' => 'normalizeTaxId'] as $type => $fn) {
    foreach ($v[$type] as $c) {
        [$status, $norm] = PluginLagapenakBeneficiary::$fn($c['input']);
        $fp  = $norm === null ? null : PluginLagapenakBeneficiary::fingerprint($type, $norm, $key);
        $got = [$status, $norm, $fp];
        if ($got === [$c['status'], $c['normalized'], $c['fingerprint']]) {
            $ok++;
        } else {
            $ko++;
            echo "$type ", json_encode($c['input']), ' esperado ', json_encode([$c['status'], $c['normalized']]), ' obtenido ', json_encode([$status, $norm]), "\n";
        }
    }
}
echo "PHP del plugin contra los vectores: $ok OK · $ko FALLO\n";
exit($ko ? 1 : 0);
