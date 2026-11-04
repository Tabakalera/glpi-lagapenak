<?php
// Perfiles y usuarios ficticios adicionales para probar el modelo de permisos (nombres nuevos, combinaciones).
// Idempotente: si un perfil o usuario ya existe, lo reutiliza. Derechos: 1 READ, 2 UPDATE, 4 CREATE, 16 PURGE.
$c = new mysqli('db', getenv('G1_DB_USER'), getenv('G1_DB_PASSWORD'), getenv('G1_DB_NAME'));
$c->set_charset('utf8mb4');
function q($sql) { global $c; $r = $c->query($sql); if ($r === false) { fwrite(STDERR, $c->error . "\n$sql\n"); exit(1); } return $r; }
function one($sql) { $r = q($sql)->fetch_row(); return $r ? $r[0] : null; }
function e($s) { global $c; return "'" . $c->real_escape_string($s) . "'"; }

$A = (int) one("SELECT id FROM glpi_entities WHERE name='D1-A'");
$B = (int) one("SELECT id FROM glpi_entities WHERE name='D1-B'");

function profile($name, $from, $loan, $alb) {
    global $c;
    if ($id = one("SELECT id FROM glpi_profiles WHERE name=" . e($name))) { return (int) $id; }
    q("CREATE TEMPORARY TABLE tp AS SELECT * FROM glpi_profiles WHERE id=$from");
    q("ALTER TABLE tp DROP COLUMN id");
    q("UPDATE tp SET name=" . e($name));
    q("INSERT INTO glpi_profiles SELECT NULL, tp.* FROM tp");
    $id = $c->insert_id;
    q("DROP TEMPORARY TABLE tp");
    q("INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT $id, name, rights FROM glpi_profilerights WHERE profiles_id=$from");
    foreach (['plugin_lagapenak_loan' => $loan, 'plugin_lagapenak_albaran' => $alb] as $r => $v) {
        q("DELETE FROM glpi_profilerights WHERE profiles_id=$id AND name='$r'");
        q("INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ($id, '$r', $v)");
    }
    return $id;
}
function user($login, $last, array $habs) { // $habs = [[profile, entity, recursive, default], ...]
    global $c;
    if (!($id = one("SELECT id FROM glpi_users WHERE name=" . e($login)))) {
        q("INSERT INTO glpi_users (name, password, firstname, realname, is_active, authtype, profiles_id, entities_id, date_creation, date_mod)
           VALUES (" . e($login) . ", " . e(password_hash(getenv('G1_USER_PASSWORD'), PASSWORD_DEFAULT)) . ", 'Ficticio', " . e($last) . ", 1, 1, {$habs[0][0]}, {$habs[0][1]}, NOW(), NOW())");
        $id = $c->insert_id;
        q("INSERT INTO glpi_useremails (users_id, is_default, email) VALUES ($id, 1, " . e($login . '@example.test') . ")");
        foreach ($habs as [$p, $en, $rec, $def]) {
            q("INSERT INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile) VALUES ($id, $p, $en, $rec, 0, $def)");
        }
    }
    return (int) $id;
}

$sin = (int) one("SELECT id FROM glpi_profiles WHERE name='D1 Sin Lagapenak'");
$P = [
    'nuevo'     => profile('Perfil Nuevo Préstamos Q4', 1, 1 | 4, 1),          // READ + CREATE (helpdesk)
    'lector'    => profile('Consulta Lagapenak', 1, 1, 0),                    // solo READ (helpdesk)
    'upd'       => profile('Gestión sin alta', 6, 1 | 2, 1),                  // READ + UPDATE (central)
    'purga'     => profile('Depuración Lagapenak', 6, 1 | 16, 0),             // READ + PURGE (central)
    'suppurga'  => profile('Supervisión completa', 6, 1 | 2 | 4 | 16, 1),     // los cuatro (central)
];
$U = [
    'd1_nuevo'    => user('d1_nuevo', 'Nuevo', [[$P['nuevo'], 0, 1, 1]]),
    'd1_lector'   => user('d1_lector', 'Lector', [[$P['lector'], 0, 1, 1]]),
    'd1_upd'      => user('d1_upd', 'Gestor', [[$P['upd'], $A, 0, 1]]),
    'd1_purga'    => user('d1_purga', 'Depurador', [[$P['purga'], 0, 1, 1]]),
    'd1_suppurga' => user('d1_suppurga', 'Completo', [[$P['suppurga'], $A, 0, 1]]),
    // Dos habilitaciones: supervisor completo en A (por defecto) y perfil sin derechos en B
    'd1_multi'    => user('d1_multi', 'Multi', [[$P['suppurga'], $A, 0, 1], [$sin, $B, 0, 0]]),
];
echo json_encode(['A' => $A, 'B' => $B, 'P' => $P, 'sin' => $sin, 'U' => $U], JSON_UNESCAPED_UNICODE), "\n";
