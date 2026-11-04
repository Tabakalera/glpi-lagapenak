<?php
// Datos ficticios por SQL (GLPI 11 no carga sus clases desde un script CLI suelto). Válido en GLPI 10 y 11.
// Mismos datos que fixtures.php: entidades D1-A/D1-B, 3 perfiles, 5 usuarios @example.test, 4 equipos.
$c = new mysqli('db', getenv('G1_DB_USER'), getenv('G1_DB_PASSWORD'), getenv('G1_DB_NAME'));
$c->set_charset('utf8mb4');
function q($sql) { global $c; $r = $c->query($sql); if ($r === false) { fwrite(STDERR, $c->error . "\n$sql\n"); exit(1); } return $r; }
function one($sql) { $r = q($sql)->fetch_row(); return $r ? $r[0] : null; }
function e($s) { global $c; return "'" . $c->real_escape_string($s) . "'"; }

foreach ([
    'smtp_mode' => 1, 'smtp_host' => 'mailpit', 'smtp_port' => 1025,
    'admin_email' => 'lagapenak-d1@example.test', 'admin_email_name' => 'Lagapenak D1',
    'use_notifications' => 1, 'notifications_mailing' => 1, 'url_base' => 'http://localhost',
] as $k => $v) {
    q("UPDATE glpi_configs SET value=" . e((string) $v) . " WHERE context='core' AND name=" . e($k));
}

$ent = [];
foreach (['D1-A', 'D1-B'] as $n) {
    // GLPI 10.0.20 assigns entity ids itself (no AUTO_INCREMENT on glpi_entities.id)
    $id = (int) one("SELECT MAX(id) + 1 FROM glpi_entities");
    q("INSERT INTO glpi_entities (id, name, entities_id, completename, level, ancestors_cache, date_creation, date_mod)
       VALUES ($id, " . e($n) . ", 0, " . e('Entidad raíz > ' . $n) . ", 2, '{\"0\":0}', NOW(), NOW())");
    $ent[$n] = $id;
}
q("UPDATE glpi_entities SET sons_cache=NULL");
[$A, $B] = [$ent['D1-A'], $ent['D1-B']];

function profile($name, $from, $loan, $alb) {
    global $c;
    q("CREATE TEMPORARY TABLE tp AS SELECT * FROM glpi_profiles WHERE id=$from");
    q("ALTER TABLE tp DROP COLUMN id");
    q("UPDATE tp SET name=" . e($name));
    q("INSERT INTO glpi_profiles SELECT NULL, tp.* FROM tp");
    $id = $c->insert_id;
    q("DROP TEMPORARY TABLE tp");
    q("INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT $id, name, rights FROM glpi_profilerights WHERE profiles_id=$from");
    q("UPDATE glpi_profilerights SET rights=$loan WHERE profiles_id=$id AND name='plugin_lagapenak_loan'");
    q("UPDATE glpi_profilerights SET rights=$alb WHERE profiles_id=$id AND name='plugin_lagapenak_albaran'");
    foreach (['plugin_lagapenak_loan' => $loan, 'plugin_lagapenak_albaran' => $alb] as $r => $v) {
        if (!one("SELECT COUNT(*) FROM glpi_profilerights WHERE profiles_id=$id AND name='$r'")) {
            q("INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES ($id, '$r', $v)");
        }
    }
    return $id;
}
// READ=1 UPDATE=2 CREATE=4
$P_SIN = profile('D1 Sin Lagapenak', 1, 0, 0);
$P_SOL = profile('D1 Solicitante',   1, 5, 1);
$P_SUP = profile('D1 Supervisor',    6, 7, 1);

function user($login, $first, $last, $profile, $entity, $recursive) {
    global $c;
    q("INSERT INTO glpi_users (name, password, firstname, realname, is_active, authtype, profiles_id, entities_id, date_creation, date_mod)
       VALUES (" . e($login) . ", " . e(password_hash(getenv('G1_USER_PASSWORD'), PASSWORD_DEFAULT)) . ", " . e($first) . ", " . e($last) . ", 1, 1, $profile, $entity, NOW(), NOW())");
    $id = $c->insert_id;
    q("INSERT INTO glpi_useremails (users_id, is_default, email) VALUES ($id, 1, " . e($login . '@example.test') . ")");
    q("INSERT INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile)
       VALUES ($id, $profile, $entity, $recursive, 0, 1)");
    return $id;
}
$U = [
    'U_SIN'  => user('d1_sinderecho', 'Ana', 'Ficticia Sinderecho', $P_SIN, 0, 1),
    'U_1'    => user('d1_u1', 'Beñat', 'Ficticio Uno', $P_SOL, 0, 1),
    'U_2'    => user('d1_u2', 'Carla', 'Ficticia Dos', $P_SOL, 0, 1),
    'U_SUPA' => user('d1_supA', 'Dani', 'Ficticio SupA', $P_SUP, $A, 0),
    'U_SUPB' => user('d1_supB', 'Edurne', 'Ficticia SupB', $P_SUP, $B, 0),
];

$comp = [];
foreach ([['D1-PORTATIL-A', $A, 1], ['D1-PORTATIL-B', $B, 1], ['D1-NO-PRESTABLE', $A, 0], ['D1-PORTATIL-B2', $B, 1]] as [$n, $en, $res]) {
    q("INSERT INTO glpi_computers (name, entities_id, is_deleted, is_template, date_creation, date_mod) VALUES (" . e($n) . ", $en, 0, 0, NOW(), NOW())");
    $comp[$n] = $c->insert_id;
    if ($res) {
        q("INSERT INTO glpi_reservationitems (itemtype, items_id, entities_id, is_active) VALUES ('Computer', {$c->insert_id}, $en, 1)");
    }
}
echo json_encode(compact('A', 'B', 'P_SIN', 'P_SOL', 'P_SUP', 'U', 'comp'), JSON_PRETTY_PRINT), "\n";
