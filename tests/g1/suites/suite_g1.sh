#!/bin/bash
# Batería de la API G1 de Lagapenak — consulta de solo lectura completa (G1-ID01, F01, F02, F04–F08).
# Solo datos ficticios, en un entorno aislado creado con tests/g1/g1.sh (ver tests/g1/README.md).
# Uso: tests/g1/g1.sh bateria <entorno> g1  (dentro del contenedor: bash /tmp/suite_g1.sh)
# Cada línea: id | qué se prueba | esperado | obtenido | OK/FALLO
source /tmp/lib.sh
RUN=$(date +%H%M%S)
API=$P/front/api.php
PASS=0; FAIL=0
DIAG=/tmp/diag-g1-$RUN; mkdir -p $DIAG; echo $DIAG > /tmp/ultimo_diag_g1
N=$(( $(cat /tmp/suite_g1_n 2>/dev/null || echo 0) + 1 )); echo $N > /tmp/suite_g1_n
D0=$(date -d "2028-01-03 +$((N*14)) days" +%F); D4=$(date -d "$D0 +4 days" +%F)

sqlv() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $c->set_charset("utf8mb4"); $r=$c->query($argv[1]); if($r===false){fwrite(STDERR,$c->error."\n");exit(1);} $x=$r===true?null:$r->fetch_row(); echo $x?$x[0]:"";' "$1"; }
check() {
  local st="FALLO"
  if [ "$3" = "$4" ]; then st="OK"; PASS=$((PASS+1)); else FAIL=$((FAIL+1)); diag "$1" "$2" "$3" "$4"; fi
  printf "%-5s | %-70s | %-14s | %-14s | %s\n" "$1" "$2" "$3" "$4" "$st"; tick "$1" "$st"
}
cfg() { sqlv "update glpi_configs set value='$2' where context='plugin:lagapenak' and name='$1'"; }
# api <token> <método> <ruta> [args curl] → código HTTP; cuerpo en /tmp/api_body, cabeceras en /tmp/api_hdr
api() { local t=$1 m=$2 r=$3; shift 3
  curl -s -o /tmp/api_body -D /tmp/api_hdr -w '%{http_code}' -X $m ${t:+-H "Authorization: Bearer $t"} "$@" "$API$r"; }
# j '<expresión PHP sobre $j>' (último cuerpo) · jf '<expresión sobre $f>' (feed completo en /tmp/feed_all.json)
j()  { php -r '$j=json_decode(file_get_contents("/tmp/api_body"),true); $v='"$1"'; echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):(string)$v);'; }
jf() { php -r '$f=json_decode(file_get_contents("/tmp/feed_all.json"),true); $v='"$1"'; echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):(string)$v);'; }
hdr() { grep -i "^$1:" /tmp/api_hdr | head -1 | sed 's/^[^:]*: *//;s/\r$//'; }
uuid() { php -r '$b=random_bytes(16);$b[6]=chr(ord($b[6])&0x0f|0x40);$b[8]=chr(ord($b[8])&0x3f|0x80);echo vsprintf("%s%s-%s-%s-%s-%s%s%s",str_split(bin2hex($b),4));'; }
fp() { php -r 'echo hash_hmac("sha256","lagapenak-g1|v1|".$argv[2]."|".$argv[3],base64_decode($argv[1]));' "$1" "$2" "$3"; }
# feedall <token> <since> [limit] → todas las páginas en /tmp/feed_all.json; imprime el último next_since
feedall() { local t=$1 s=$2 l=${3:-100} more=true pages=0; echo '[]' > /tmp/feed_all.json
  while [ "$more" = true ] && [ $pages -lt 3000 ]; do
    api "$t" GET "/v1/loan-events?since=$s&limit=$l" >/dev/null
    php -r '$a=json_decode(file_get_contents("/tmp/feed_all.json"),true); $b=json_decode(file_get_contents("/tmp/api_body"),true); file_put_contents("/tmp/feed_all.json", json_encode(array_merge($a,$b["data"]??[])));'
    s=$(j '$j["next_since"]'); more=$(j '$j["has_more"]'); pages=$((pages+1))
  done; echo "$s"; }
evt() { jf 'implode(",",array_column(array_filter($f,function($e){return $e["loan_uuid"]==="'$1'";}),"event"))'; }
mkclient() { php -r '
  $c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME"));
  $a="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"; $s=""; for($i=0;$i<40;$i++){$s.=$a[random_int(0,61)];}
  $p=bin2hex(random_bytes(4)); $t="lgk_{$p}_$s";
  $st=$c->prepare("INSERT INTO glpi_plugin_lagapenak_apiclients (name,source,token_prefix,token_hash,scopes,entities,is_recursive,allowed_ips,is_active,expires_at,scope_mod,date_creation,date_mod) VALUES (?,?,?,?,?,?,?,?,1,$argv[6],NOW(),NOW(),NOW())");
  $h=hash("sha256",$t); $src="kide-artean"; $st->bind_param("ssssssis",$argv[1],$src,$p,$h,$argv[2],$argv[3],$argv[4],$argv[5]); $st->execute(); echo $t;' "$@"; }
apost() { local url=$1; shift; local tok=$(csrf glpi "$url")
  curl -s -b /tmp/cj_glpi -c /tmp/cj_glpi -o /tmp/last_body -w '%{http_code}' -H "Referer: $url" --data-urlencode "_glpi_csrf_token=$tok" "$@" "$url"; }
lpost() { local u=$1 url=$2; shift 2; local tok=$(csrf $u "$url")   # formulario del préstamo, token de esa página
  curl -s -b /tmp/cj_$u -c /tmp/cj_$u -o /tmp/last_body -w '%{http_code}' -H "Referer: $url" --data-urlencode "_glpi_csrf_token=$tok" "$@" "$url"; }
ACT=$(uuid); H_ACT=(-H "X-Acting-User: $ACT")
P_DIR=/var/www/html/glpi/plugins/lagapenak
RUNSTART=$(date "+%Y-%m-%d %H:%M:%S")

EA=$(sqlv "select id from glpi_entities where name='D1-A'"); EB=$(sqlv "select id from glpi_entities where name='D1-B'")  # no usar $B: URL base de lib.sh
SUPA=$(sqlv "select id from glpi_users where name='d1_supA'"); SUPB=$(sqlv "select id from glpi_users where name='d1_supB'")
U1=$(sqlv "select id from glpi_users where name='d1_u1'"); PSUP=$(sqlv "select profiles_id from glpi_profiles_users where users_id=$SUPA limit 1")
echo "Lagapenak $(sqlv "select version from glpi_plugins where directory='lagapenak'") · GLPI $(sqlv "select value from glpi_configs where context='core' and name='version'") · ronda $RUN"

# ── Preparación ─────────────────────────────────────────────────────────────
EA1=$(sqlv "select id from glpi_entities where name='D1-A1'")
if [ -z "$EA1" ]; then
  EA1=$(sqlv "select max(id)+1 from glpi_entities")
  sqlv "insert into glpi_entities (id,name,entities_id,completename,level,ancestors_cache,date_creation,date_mod) values ($EA1,'D1-A1',$EA,'Entidad raíz > D1-A > D1-A1',3,'{\"0\":0,\"$EA\":$EA}',NOW(),NOW())"
  sqlv "update glpi_entities set sons_cache=NULL"
fi
# Supervisor ficticio de A y B (para mover un préstamo de entidad desde el formulario)
SUPAB=$(sqlv "select id from glpi_users where name='d1_supAB'")
if [ -z "$SUPAB" ]; then
  sqlv "insert into glpi_users (name,password,firstname,realname,is_active,authtype,profiles_id,entities_id,date_creation,date_mod) values ('d1_supAB','$(php -r 'echo password_hash(getenv("G1_USER_PASSWORD"),PASSWORD_DEFAULT);')','Gorka','Ficticio SupAB',1,1,$PSUP,$EA,NOW(),NOW())"
  SUPAB=$(sqlv "select id from glpi_users where name='d1_supAB'")
  sqlv "insert into glpi_useremails (users_id,is_default,email) values ($SUPAB,1,'d1_supab@example.test')"
  sqlv "insert into glpi_profiles_users (users_id,profiles_id,entities_id,is_recursive,is_dynamic,is_default_profile) values ($SUPAB,$PSUP,$EA,0,0,1),($SUPAB,$PSUP,$EB,0,0,0)"
fi
su -s /bin/bash www-data -c "php /var/www/html/glpi/bin/console cache:clear --no-interaction" >/dev/null 2>&1
sqlv "delete from glpi_plugin_lagapenak_apiclients where name like 'T-%'"
sqlv "delete from glpi_plugin_lagapenak_apilogs where status=401"   # bloqueo de 5 min por IP de rondas anteriores
# La batería hace muchas llamadas: límite alto salvo en la prueba del límite (L01)
cfg api_trusted_proxies ''; cfg api_rate_per_minute 10000; cfg api_sync_from 2026-01-01
sqlv "update glpi_configs set value='' where context='plugin:lagapenak' and name in ('api_hmac_keys','api_hmac_keys_mod')"
PCA=$(sqlv "select id from glpi_computers where name='D1-PORTATIL-A'")
sqlv "update glpi_computers set otherserial='INV-D1-A', serial='SN-D1-A' where id=$PCA"
U1MAIL=$(sqlv "select email from glpi_useremails where users_id=$U1 and is_default=1")

# mkloan <entidad> <estado> <email beneficiario> <dni> [validado] [inicio] [fin] [alta/modificación] → id
mkloan() {
  local u=$(uuid) ini=${6:-$D0 10:00:00} fin=${7:-$D4 18:00:00} mod=${8:-NOW() - INTERVAL 1 MINUTE}
  [ "$mod" != "NOW() - INTERVAL 1 MINUTE" ] && mod="'$mod'"
  sqlv "insert into glpi_plugin_lagapenak_loans (uuid,name,entities_id,users_id,users_id_destinatario,fecha_inicio,fecha_fin,status,observaciones,field_1,beneficiary_name,beneficiary_email,beneficiary_dni,beneficiary_validated,signature_data,signature_name,albaran_passport,date_creation,date_mod)
        values ('$u','Proyecto secreto $RUN',$1,$U1,$SUPA,'$ini','$fin',$2,'Observación privada $RUN','Convocatoria libre $RUN','Persona Ficticia $RUN','$3','$4',${5:-1},NULL,'','',$mod,$mod)"
  sqlv "select id from glpi_plugin_lagapenak_loans where uuid='$u'"
}
uuidof() { sqlv "select uuid from glpi_plugin_lagapenak_loans where id=$1"; }
additems() { local id=$1 s; shift; for s in "$@"; do sqlv "insert into glpi_plugin_lagapenak_loanitems (loans_id,itemtype,items_id,status,date_checkout,date_checkin,notes,date_creation,date_mod) values ($id,'Computer',$PCA,$s,'$D0 10:00:00','$D4 18:00:00','nota interna $RUN',NOW() - INTERVAL 1 MINUTE,NOW() - INTERVAL 1 MINUTE)"; done; }

MAIL_ART="artista.$RUN@example.test"
LA=$(mkloan $EA 2 "  Artista.$RUN@Example.TEST " "12.345.678-z"); LA_U=$(uuidof $LA); additems $LA 2
sqlv "update glpi_plugin_lagapenak_loans set signature_data='data:image/png;base64,FIRMA$RUN',signature_name='Firmante Ficticio $RUN',albaran_passport='22222222J',albaran_project='Proyecto albarán $RUN',tickets_id=4242,signature_date=NOW() - INTERVAL 1 MINUTE where id=$LA"
LA2=$(mkloan $EA 1 "$MAIL_ART" "X1234567L"); LA2_U=$(uuidof $LA2)
LP=$(mkloan $EA 1 "pasaporte.$RUN@example.test" "AB1234567"); LP_U=$(uuidof $LP)
LBAD=$(mkloan $EA 1 "no-es-un-correo" "12345678A" 0); LBAD_U=$(uuidof $LBAD)
LREQ=$(mkloan $EA 1 "$U1MAIL" "" 0); LREQ_U=$(uuidof $LREQ)
LDIS=$(mkloan $EA 1 "persona.a.$RUN@example.test" "Y0000001S"); LDIS_U=$(uuidof $LDIS)
LS=$(mkloan $EA1 3 "sub.$RUN@example.test" ""); LS_U=$(uuidof $LS); additems $LS 3 4
LB=$(mkloan $EB 1 "b.$RUN@example.test" ""); LB_U=$(uuidof $LB)
# Histórico: terminado en 2025 y devuelto (fuera) · empezado en 2025 y aún en curso (dentro) · 2025 con material fuera (dentro)
LOLD=$(mkloan $EA 3 "viejo.$RUN@example.test" "" 0 "2025-03-01 10:00:00" "2025-03-10 18:00:00" "2025-03-11 09:00:00"); LOLD_U=$(uuidof $LOLD)
sqlv "insert into glpi_plugin_lagapenak_loanitems (loans_id,itemtype,items_id,status,date_creation,date_mod) values ($LOLD,'Computer',$PCA,3,'2025-03-01','2025-03-11')"
LRUN=$(mkloan $EA 2 "curso.$RUN@example.test" "" 0 "2025-11-01 10:00:00" "2026-02-15 18:00:00" "2025-11-01 09:00:00"); LRUN_U=$(uuidof $LRUN)
LOUT=$(mkloan $EA 2 "fuera.$RUN@example.test" "" 0 "2025-05-01 10:00:00" "2025-05-10 18:00:00" "2025-05-01 09:00:00"); LOUT_U=$(uuidof $LOUT)
sqlv "insert into glpi_plugin_lagapenak_loanitems (loans_id,itemtype,items_id,status,date_creation,date_mod) values ($LOUT,'Computer',$PCA,2,'2025-05-01','2025-05-01')"

K1=$(mkclient "T-K1 $RUN" "loans:read" "[$EA]" 1 $'127.0.0.1\n::1' "NOW() + INTERVAL 30 DAY")
K3=$(mkclient "T-K3 $RUN" "loans:read" "[$EA]" 1 "10.9.9.9" "NOW() + INTERVAL 30 DAY")
K4=$(mkclient "T-K4 $RUN" "loans:read" "[$EA]" 1 "127.0.0.1" "NOW() - INTERVAL 1 DAY")
K5=$(mkclient "T-K5 $RUN" "loans:read" "[$EA]" 0 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
K6=$(mkclient "T-K6 $RUN" "loans:read" "[$EA]" 1 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
KB=$(mkclient "T-KB $RUN" "loans:read" "[$EB]" 0 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
K0=$(mkclient "T-K0 $RUN" "" "[$EA]" 1 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
KP=$(mkclient "T-KP $RUN" "loans:read loans:read:personal" "[$EA]" 1 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
KPO=$(mkclient "T-KPO $RUN" "loans:read:personal" "[$EA]" 1 "127.0.0.1" "NOW() + INTERVAL 30 DAY")
# Histórico autocompletado con el email del solicitante pero con DNI válido (identificable por DNI)
LREQD=$(mkloan $EA 1 "$U1MAIL" "12345678Z" 0); LREQD_U=$(uuidof $LREQD)

# ── 1. Activación, transporte y autenticación ──────────────────────────────
cfg api_enabled 0; cfg api_require_https 1
check A01 "API desactivada: 404" 404 $(api "$K1" GET /v1/meta)
cfg api_enabled 1
check A02 "Sin HTTPS: 403 https_required" "403 https_required" "$(api "$K1" GET /v1/meta) $(j '$j["code"]')"
cfg api_require_https 0
check A03 "Sin credencial: 401 + WWW-Authenticate" "401 Bearer" "$(api "" GET /v1/meta) $(hdr WWW-Authenticate | cut -d' ' -f1)"
check A04 "Credencial inventada / alterada / caducada: 401" "401 401 401" "$(api "lgk_00000000_$(printf 'a%.0s' {1..40})" GET /v1/meta) $(api "${K1%?}$([ "${K1: -1}" = a ] && echo b || echo a)" GET /v1/meta) $(api "$K4" GET /v1/meta)"
check A05 "IP no permitida: 403 ip_not_allowed" "403 ip_not_allowed" "$(api "$K3" GET /v1/meta) $(j '$j["code"]')"
check A06 "meta: 200, ámbito único loans:read, sin fuente de vínculos" '200 ["loans:read"]' "$(api "$K1" GET /v1/meta) $(j '$j["client"]["scopes"]')"
check A07 "Sin cookie de sesión; X-Request-Id devuelto; no-store" "0 kide-$RUN no-store" "$(api "$K1" GET /v1/meta -H "X-Request-Id: kide-$RUN" >/dev/null; grep -ci '^set-cookie' /tmp/api_hdr) $(hdr X-Request-Id) $(hdr Cache-Control | grep -o no-store)"
check A08 "Sin ámbito loans:read no lee préstamos: 403" 403 $(api "$K0" GET "/v1/loan-events?since=1970-01-01T00:00:00Z")
check A09 "Operaciones de vínculos retiradas: 404 / 404 / 404" "404 404 404" "$(api "$K1" PUT "/v1/loans/$LA_U/links/residency/$(uuid)" "${H_ACT[@]}") $(api "$K1" DELETE "/v1/loans/$LA_U/links/residency/$(uuid)" "${H_ACT[@]}") $(api "$K1" GET "/v1/links?changed_since=1970-01-01T00:00:00Z")"
check A10 "Escritura sobre préstamos: 405 (solo lectura)" "405 405" "$(api "$K1" PUT "/v1/loans/$LA_U") $(api "$K1" DELETE "/v1/loans/$LA_U")"
check A11 "Ruta desconocida 404 · X-Acting-User no UUID 400" "404 400" "$(api "$K1" GET /v1/nada) $(api "$K1" GET /v1/meta -H "X-Acting-User: ana@example.test")"

# ── 2. Detalle (nivel 2), privacidad y entidades ───────────────────────────
check B01 "Detalle por número sin X-Acting-User: 400" 400 $(api "$K1" GET "/v1/loans?number=$LA")
check B02 "Detalle por número: 1 resultado y uuid" "200 1 $LA_U" "$(api "$K1" GET "/v1/loans?number=$LA" "${H_ACT[@]}") $(j 'count($j["data"])') $(j '$j["data"][0]["uuid"]')"
check B03 "Campos del detalle (contrato 3.0)" '["uuid","number","reference","glpi_url","entity","ticket_id","status","material_status","created_at","period","requester","recipient","details","extension_request","delivery_note","cancellation","beneficiary","items_summary","items","comments","changed_at"]' "$(j 'array_keys($j["data"][0])')"
check B04 "Beneficiario del detalle: estados, avisos, huellas y contacto" '["email_status","tax_id_status","matches_requester_email","checked_on_entry","requester_autofill_possible","fingerprints","contact"]' "$(j 'array_keys($j["data"][0]["beneficiary"])')"
check B05 "Material: nombre e inventario; firma: solo sí/cuándo" "D1-PORTATIL-A INV-D1-A true 1" "$(j '$j["data"][0]["items"][0]["name"]') $(j '$j["data"][0]["items"][0]["inventory_number"]') $(j '$j["data"][0]["delivery_note"]["signed"]') $(j '(int)!empty($j["data"][0]["delivery_note"]["signed_at"])')"
# Clases de datos: nunca (sensibles), personales (solo con loans:read:personal, solo en el detalle) y operativos
# (texto del personal y nombres del personal: en el detalle, nunca en el descubrimiento)
NEVER=("Observación privada" "12345678" "X1234567" "AB1234567" "FIRMA$RUN" "22222222J" "nota interna" "Motivo privado" "comentario privado" "nota de ampliación" "token-secreto")
PERSONAL=("Persona Ficticia" "artista.$RUN" "Artista.$RUN" "Firmante Ficticio" "example.test")
OPER=("Proyecto secreto" "Convocatoria libre" "Dani" "Beñat" "d1_u1")
# leaks <fichero> [never] [personal] [oper] → número de textos encontrados (por defecto, las tres clases)
leaks() { local f=$1 n=0 g s; shift; local groups=${*:-never personal oper}; local -a arr
  for g in $groups; do case $g in never) arr=("${NEVER[@]}");; personal) arr=("${PERSONAL[@]}");; oper) arr=("${OPER[@]}");; esac
    for s in "${arr[@]}"; do grep -q "$s" $f && n=$((n+1)) && echo "      fuga ($g) en $f: $s" >&2; done; done; echo $n; }
check B06 "Detalle sin ámbito personal: ni datos sensibles ni personales" 0 $(leaks /tmp/api_body never personal)
check B07 "Número de otra entidad: 0 · subentidad (recursivo): 1 · no recursivo: 0" "0 1 0" "$(api "$K1" GET "/v1/loans?number=$LB" "${H_ACT[@]}" >/dev/null; j 'count($j["data"])') $(api "$K1" GET "/v1/loans?number=$LS" "${H_ACT[@]}" >/dev/null; j 'count($j["data"])') $(api "$K5" GET "/v1/loans?number=$LS" "${H_ACT[@]}" >/dev/null; j 'count($j["data"])')"
check B08 "Detalle por uuid: propio 200 · otra entidad 404 · inexistente 404 · mal formado 404" "200 404 404 404" "$(api "$K1" GET "/v1/loans/$LA_U") $(api "$K1" GET "/v1/loans/$LB_U") $(api "$K1" GET "/v1/loans/$(uuid)") $(api "$K1" GET "/v1/loans/no-es-uuid")"
check B09 "Lote por uuids: encontrados + no disponibles" "2 1 not_available" "$(api "$K1" GET "/v1/loans?uuids=$LA_U,$LA2_U,$LB_U" >/dev/null; j 'count($j["data"])') $(j 'count($j["not_found"])') $(j '$j["not_found"][0]["reason"]')"
check B10 "Lote: más de 50 / uuid inválido / número y lote a la vez: 400" "400 400 400" "$(api "$K1" GET "/v1/loans?uuids=$(for i in $(seq 51); do printf '%s,' $(uuid); done | sed 's/,$//')") $(api "$K1" GET "/v1/loans?uuids=abc") $(api "$K1" GET "/v1/loans?uuids=$LA_U&number=$LA" "${H_ACT[@]}")"
check B11 "Sin filtro: 400 (nunca listado completo)" 400 $(api "$K1" GET /v1/loans)
check B12 "Inyección en ruta y parámetros: 404 / 400 / 400" "404 400 400" "$(api "$K1" GET "/v1/loans/abc'%20OR%201=1--") $(api "$K1" GET "/v1/loans?number=1%27%20OR%201=1" "${H_ACT[@]}") $(api "$K1" GET "/v1/loans?uuids=%27%20OR%201=1")"
check B13 "Préstamo anterior a 2026 localizable por número" "1 returned" "$(api "$K1" GET "/v1/loans?number=$LOLD" "${H_ACT[@]}" >/dev/null; j 'count($j["data"])') $(j '$j["data"][0]["material_status"]')"
check B14 "Errores sin datos personales (cuerpo de un 404 y un 400)" 0 "$(api "$K1" GET "/v1/loans/$LB_U" >/dev/null; cp /tmp/api_body /tmp/e1; api "$K1" GET "/v1/loans?uuids=$MAIL_ART" >/dev/null; cat /tmp/e1 /tmp/api_body > /tmp/e2; leaks /tmp/e2)"

# ── 2b. Datos operativos del detalle (consulta completa) ────────────────────
api "$K1" GET "/v1/loans/$LA_U" >/dev/null
check O01 "Referencia, entidad (id y nombre) y ticket vinculado" "Proyecto secreto $RUN $EA D1-A 4242" "$(j '$j["reference"]') $(j '$j["entity"]["id"]') $(j '$j["entity"]["name"]' | grep -o 'D1-A$') $(j '$j["ticket_id"]')"
check O02 "Solicitante y supervisor destinatario: id y nombre (sin email)" "$U1 Ficticio Uno Beñat $SUPA Ficticio SupA Dani 0" "$(j '$j["requester"]["id"]') $(j '$j["requester"]["name"]') $(j '$j["recipient"]["id"]') $(j '$j["recipient"]["name"]') $(grep -c 'd1_supa@\|d1_u1@' /tmp/api_body)"
check O03 "Campos de gestión con nombre estable" "Convocatoria libre $RUN [\"call\",\"project\",\"location\",\"area\",\"department\"]" "$(j '$j["details"]["call"]') $(j 'array_keys($j["details"])')"
check O04 "Material: id de línea, id de activo, serie y último cambio" "1 $PCA SN-D1-A 1" "$(j '(int)($j["items"][0]["line_id"]>0)') $(j '$j["items"][0]["asset_id"]') $(j '$j["items"][0]["serial"]') $(j '(int)!empty($j["items"][0]["changed_at"])')"
check O05 "Albarán: firmado, fecha, proyecto; firmante solo con ámbito personal" "true 1 Proyecto albarán $RUN  false" "$(j '$j["delivery_note"]["signed"]') $(j '(int)!empty($j["delivery_note"]["signed_at"])') $(j '$j["delivery_note"]["project"]') $(j '$j["delivery_note"]["signer_name"]') $(j '$j["delivery_note"]["signing_link"]["pending"]')"
sqlv "update glpi_plugin_lagapenak_loans set sign_token='token-secreto-$RUN', sign_token_expires=NOW() + INTERVAL 2 DAY, date_request_status=1, requested_date_end='$D4 20:00:00', date_request_apply_items=1, date_request_notes='nota de ampliación $RUN' where id=$LA2"
api "$K1" GET "/v1/loans/$LA2_U" >/dev/null
check O06 "Enlace de firma pendiente (con caducidad, nunca el enlace)" "true 1 0" "$(j '$j["delivery_note"]["signing_link"]["pending"]') $(j '(int)!empty($j["delivery_note"]["signing_link"]["expires_at"])') $(grep -c 'token-secreto' /tmp/api_body)"
check O07 "Ampliación pendiente: fecha pedida y si se aplica al material, sin notas" "pending 1 true 0" "$(j '$j["extension_request"]["status"]') $(j '(int)!empty($j["extension_request"]["requested_end"])') $(j '$j["extension_request"]["applies_to_items"]') $(grep -c 'nota de ampliación' /tmp/api_body)"
sqlv "update glpi_plugin_lagapenak_loans set sign_token_expires=NOW() - INTERVAL 1 DAY where id=$LA2"
check O08 "Enlace de firma caducado: no pendiente" false "$(api "$K1" GET "/v1/loans/$LA2_U" >/dev/null; j '$j["delivery_note"]["signing_link"]["pending"]')"
sqlv "update glpi_plugin_lagapenak_loans set sign_token=NULL, sign_token_expires=NULL, date_request_status=0, requested_date_end=NULL, date_request_apply_items=0, date_request_notes='' where id=$LA2"
sqlv "insert into glpi_plugin_lagapenak_loancomments (loans_id,users_id,comment,date_creation) values ($LA,$U1,'comentario privado $RUN',NOW() - INTERVAL 1 MINUTE)"
check O09 "Comentarios: número y fecha, nunca el texto" "1 1 0" "$(api "$K1" GET "/v1/loans/$LA_U" >/dev/null; j '$j["comments"]["count"]') $(j '(int)!empty($j["comments"]["last_at"])') $(grep -c 'comentario privado' /tmp/api_body)"
check O10 "Detalle con datos operativos del personal (presentes)" 1 "$( [ $(leaks /tmp/api_body oper 2>/dev/null) -ge 3 ] && echo 1 || echo 0)"

# ── 2c. Datos personales (ámbito loans:read:personal) ──────────────────────
check P01 "meta del cliente con ámbito personal" '["loans:read","loans:read:personal"]' "$(api "$KP" GET /v1/meta >/dev/null; j '$j["client"]["scopes"]')"
api "$KP" GET "/v1/loans/$LA_U" >/dev/null
check P02 "Con ámbito personal: nombre y email tal como constan, firmante" "Persona Ficticia $RUN|Artista.$RUN@Example.TEST|Firmante Ficticio $RUN" "$(j '$j["beneficiary"]["contact"]["name"]')|$(j '$j["beneficiary"]["contact"]["email"]')|$(j '$j["delivery_note"]["signer_name"]')"
check P03 "Con ámbito personal: nunca DNI, firma, documento, notas ni textos sensibles" 0 $(leaks /tmp/api_body never)
check P04 "Sin ámbito personal: contacto y firmante null" "null " "$(api "$K1" GET "/v1/loans/$LA_U" >/dev/null; j 'json_encode($j["beneficiary"]["contact"])') $(j '$j["delivery_note"]["signer_name"]')"
check P05 "Solo el ámbito personal (sin loans:read): 403" 403 $(api "$KPO" GET "/v1/loans/$LA_U")
check P06 "Detalle de un beneficiario sin identificadores válidos (no reconocido)" "200 no-es-un-correo Persona Ficticia $RUN" "$(api "$KP" GET "/v1/loans?uuids=$LBAD_U" >/dev/null; echo 200) $(j '$j["data"][0]["beneficiary"]["contact"]["email"]') $(j '$j["data"][0]["beneficiary"]["contact"]["name"]')"
check P07 "Descubrimiento con ámbito personal: sin datos en claro" 0 "$(api "$KP" GET "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=100" >/dev/null; leaks /tmp/api_body)"
RID=p-$RUN; api "$KP" GET "/v1/loans?uuids=$LA_U,$LA2_U" -H "X-Request-Id: $RID" "${H_ACT[@]}" >/dev/null
RID2=k-$RUN; api "$K1" GET "/v1/loans?uuids=$LA_U,$LA2_U" -H "X-Request-Id: $RID2" >/dev/null
check P08 "Registro: préstamos con datos personales por llamada (2 / 0), con usuario" "2 2 $ACT 2 0" "$(sqlv "select concat(result_count,' ',personal_count,' ',acting_user) from glpi_plugin_lagapenak_apilogs where request_id='$RID'") $(sqlv "select concat(result_count,' ',personal_count) from glpi_plugin_lagapenak_apilogs where request_id='$RID2'")"
check P09 "Registro sin nombres ni emails" 0 "$(sqlv "select count(*) from glpi_plugin_lagapenak_apilogs where date >= '$RUNSTART' and (query like '%Ficticia%' or query like '%example%' or route like '%example%')")"

# ── 3. Identificación (G1-ID01) ─────────────────────────────────────────────
api "$K1" GET "/v1/loans/$LA_U" >/dev/null
check C01 "Sin clave de integración: estados sí, huellas no" "valid valid 0" "$(j '$j["beneficiary"]["email_status"]') $(j '$j["beneficiary"]["tax_id_status"]') $(j 'count($j["beneficiary"]["fingerprints"])')"
login glpi "$G1_ADMIN_PASSWORD" >/dev/null
apost "$P/front/apiclient.php" --data-urlencode rotate_hmac_key=1 --data-urlencode transition_days=30 >/dev/null
PAGE=$(get glpi "$P/front/apiclient.php")
KID1=$(echo "$PAGE" | grep -o 'kid: g1-[0-9]*-[0-9a-f]*' | head -1 | cut -d' ' -f2); KEY1=$(echo "$PAGE" | grep -o 'key (base64): [A-Za-z0-9+/=]*' | head -1 | cut -d' ' -f3)
check C02 "Generar clave: kid y clave mostrados una vez" "1 44 0" "$( [ -n "$KID1" ] && echo 1 || echo 0) ${#KEY1} $(get glpi "$P/front/apiclient.php" | grep -c 'key (base64)')"
check C03 "Clave guardada cifrada (ni la clave ni JSON en claro en la base)" "0 0" "$(sqlv "select count(*) from glpi_configs where name='api_hmac_keys' and value like '%$KEY1%'") $(sqlv "select count(*) from glpi_configs where name='api_hmac_keys' and value like '%kid%'")"
api "$K1" GET /v1/meta >/dev/null
check C04 "meta: algoritmo, normalización y kid activo" "HMAC-SHA256 g1-v1 $KID1" "$(j '$j["identification"]["algorithm"]') $(j '$j["identification"]["normalization"]') $(j '$j["identification"]["active_kid"]')"
api "$K1" GET "/v1/loans/$LA_U" >/dev/null
check C05 "Huella del email normalizado (mayúsculas y espacios)" "$(fp "$KEY1" email "$MAIL_ART")" "$(j '$j["beneficiary"]["fingerprints"][0]["email"]')"
check C06 "Huella del DNI normalizado (puntos, guion, minúscula)" "$(fp "$KEY1" tax_id 12345678Z)" "$(j '$j["beneficiary"]["fingerprints"][0]["tax_id"]')"
check C07 "Mismo email en otro préstamo: misma huella; NIE válido con huella" "$(fp "$KEY1" email "$MAIL_ART") $(fp "$KEY1" tax_id X1234567L)" "$(api "$K1" GET "/v1/loans/$LA2_U" >/dev/null; j '$j["beneficiary"]["fingerprints"][0]["email"]') $(j '$j["beneficiary"]["fingerprints"][0]["tax_id"]')"
check C08 "Pasaporte: documento other, sin huella; email sí" "other  valid" "$(api "$K1" GET "/v1/loans/$LP_U" >/dev/null; j '$j["beneficiary"]["tax_id_status"]') $(j '$j["beneficiary"]["fingerprints"][0]["tax_id"]') $(j '$j["beneficiary"]["email_status"]')"
check C09 "Datos antiguos mal escritos: invalid/invalid y sin huellas; no comprobado" "invalid invalid 0 false" "$(api "$K1" GET "/v1/loans/$LBAD_U" >/dev/null; j '$j["beneficiary"]["email_status"]') $(j '$j["beneficiary"]["tax_id_status"]') $(j 'count($j["beneficiary"]["fingerprints"])') $(j '$j["beneficiary"]["checked_on_entry"]')"
check C10 "Beneficiario = email del solicitante (autocompletado antiguo): marcado" "true false" "$(api "$K1" GET "/v1/loans/$LREQ_U" >/dev/null; j '$j["beneficiary"]["matches_requester_email"]') $(api "$K1" GET "/v1/loans/$LA_U" >/dev/null; j '$j["beneficiary"]["matches_requester_email"]')"
check C14 "Histórico con email del solicitante: riesgo de autocompletado" "true false" "$(api "$K1" GET "/v1/loans/$LREQ_U" >/dev/null; j '$j["beneficiary"]["requester_autofill_possible"]') $(j '$j["beneficiary"]["checked_on_entry"]')"
check C15 "Mismo caso con DNI válido: riesgo marcado y huella del DNI disponible" "true $(fp "$KEY1" tax_id 12345678Z)" "$(api "$K1" GET "/v1/loans/$LREQD_U" >/dev/null; j '$j["beneficiary"]["requester_autofill_possible"]') $(j '$j["beneficiary"]["fingerprints"][0]["tax_id"]')"
sleep 3
check C16 "El aviso también viaja en el descubrimiento" true "$(feedall "$K1" "1970-01-01T00:00:00Z" >/dev/null; jf 'array_values(array_filter($f,function($e){return $e["loan_uuid"]==="'$LREQ_U'";}))[0]["beneficiary"]["requester_autofill_possible"]')"
check C11 "Discrepancia posible: email y NIE de personas distintas viajan ambos" "$(fp "$KEY1" email "persona.a.$RUN@example.test") $(fp "$KEY1" tax_id Y0000001S)" "$(api "$K1" GET "/v1/loans/$LDIS_U" >/dev/null; j '$j["beneficiary"]["fingerprints"][0]["email"]') $(j '$j["beneficiary"]["fingerprints"][0]["tax_id"]')"
check C12 "La huella no se puede usar como filtro de búsqueda" 400 $(api "$K1" GET "/v1/loans?fingerprint=$(fp "$KEY1" email "$MAIL_ART")")
check C13 "El registro de la API no guarda huellas ni identificadores" 0 $(sqlv "select count(*) from glpi_plugin_lagapenak_apilogs where date >= '$RUNSTART' and (query like '%$(fp "$KEY1" email "$MAIL_ART" | cut -c1-16)%' or query like '%example.test%')")

# ── 4. Formulario: sin autocompletado y validación ─────────────────────────
login d1_u1 >/dev/null; login d1_supA >/dev/null
FORM=$(get d1_u1 "$P/front/loan.form.php")
check D01 "Formulario nuevo: email del beneficiario vacío (sin autocompletar)" "vacío" "$(echo "$FORM" | grep -o 'name="beneficiary_email" value="[^"]*"' | grep -q 'value=""' && echo vacío || echo relleno)"
check D02 "Formulario nuevo: nombre del beneficiario vacío" "vacío" "$(echo "$FORM" | grep -o 'name="beneficiary_name" maxlength="255" value="[^"]*"' | grep -q 'value=""' && echo vacío || echo relleno)"
check D03 "Botón explícito «el beneficiario es el solicitante»" 1 "$(echo "$FORM" | grep -c 'lagapenak-ben-requester"')"
newloan() { # newloan <nombre> <email> <dni> → id (vacío si no se creó)
  lpost d1_u1 "$P/front/loan.form.php" --data-urlencode save_loan=1 --data-urlencode "name=$1" --data-urlencode entities_id=$EA \
    --data-urlencode users_id_destinatario=$SUPA --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" \
    --data-urlencode "beneficiary_name=Artista Ficticia" --data-urlencode "beneficiary_email=$2" --data-urlencode "beneficiary_dni=$3" >/dev/null
  sqlv "select id from glpi_plugin_lagapenak_loans where name='$1' order by id desc limit 1"; }
check D04 "Sin email ni DNI/NIE: rechazado y conserva lo escrito" "0 1" "$( [ -z "$(newloan "F-$RUN-0" "" "")" ] && echo 0 || echo 1) $(grep -c "F-$RUN-0" /tmp/last_body)"
check D05 "Email inválido: rechazado" "" "$(newloan "F-$RUN-1" "no-valido@" "")"
check D06 "DNI con letra incorrecta: rechazado" "" "$(newloan "F-$RUN-2" "" "12345678A")"
check D07 "Solo pasaporte (sin email): rechazado" "" "$(newloan "F-$RUN-3" "" "AB1234567")"
F4=$(newloan "F-$RUN-4" "pas.$RUN@example.test" "AB1234567"); F5=$(newloan "F-$RUN-5" "" "12345678Z"); F6=$(newloan "F-$RUN-6" "solo.$RUN@example.test" "")
check D08 "Pasaporte + email / solo DNI válido / solo email: aceptados" "1 1 1" "$( [ -n "$F4" ] && echo 1 || echo 0) $( [ -n "$F5" ] && echo 1 || echo 0) $( [ -n "$F6" ] && echo 1 || echo 0)"
check D09 "Préstamo nuevo: uuid, beneficiario comprobado y datos tal cual" "36 1 12345678Z" "$(sqlv "select length(uuid) from glpi_plugin_lagapenak_loans where id=$F5") $(sqlv "select beneficiary_validated from glpi_plugin_lagapenak_loans where id=$F5") $(sqlv "select beneficiary_dni from glpi_plugin_lagapenak_loans where id=$F5")"
F7=$(newloan "F-$RUN-7" "$U1MAIL" "")
check D10 "Solicitante = beneficiario elegido a propósito: permitido y marcado" "true true" "$(api "$K1" GET "/v1/loans/$(uuidof $F7)" >/dev/null; j '$j["beneficiary"]["matches_requester_email"]') $(j '$j["beneficiary"]["checked_on_entry"]')"
check D11 "Solicitante distinto del beneficiario: no marcado" "false" "$(api "$K1" GET "/v1/loans/$(uuidof $F6)" >/dev/null; j '$j["beneficiary"]["matches_requester_email"]')"
LEG=$(mkloan $EA 1 "" "" 0)
lpost d1_supA "$P/front/loan.form.php?id=$LEG" --data-urlencode save_loan=1 --data-urlencode "name=Legado $RUN" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_name=Persona Ficticia $RUN" --data-urlencode "beneficiary_email=" --data-urlencode "beneficiary_dni=" >/dev/null
check D12 "Préstamo antiguo sin identificadores: se edita sin tocar beneficiario" "Legado $RUN 0 " "$(sqlv "select name from glpi_plugin_lagapenak_loans where id=$LEG") $(sqlv "select beneficiary_validated from glpi_plugin_lagapenak_loans where id=$LEG") $(sqlv "select beneficiary_email from glpi_plugin_lagapenak_loans where id=$LEG")"
lpost d1_supA "$P/front/loan.form.php?id=$LEG" --data-urlencode save_loan=1 --data-urlencode "name=Legado $RUN" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_name=Persona Ficticia $RUN" --data-urlencode "beneficiary_email=mal@" --data-urlencode "beneficiary_dni=" >/dev/null
check D13 "Cambiar el beneficiario a un email inválido: rechazado" "" "$(sqlv "select beneficiary_email from glpi_plugin_lagapenak_loans where id=$LEG")"
lpost d1_supA "$P/front/loan.form.php?id=$LEG" --data-urlencode save_loan=1 --data-urlencode "name=Legado $RUN" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_name=Persona Ficticia $RUN" --data-urlencode "beneficiary_email=legado.$RUN@example.test" --data-urlencode "beneficiary_dni=" \
  --data-urlencode "uuid=00000000-0000-4000-8000-000000000000" --data-urlencode "beneficiary_validated=0" --data-urlencode "cancel_reason=x" >/dev/null
check D14 "Completar beneficiario válido: comprobado; uuid y campos internos intactos" "legado.$RUN@example.test 1 0 0" "$(sqlv "select beneficiary_email from glpi_plugin_lagapenak_loans where id=$LEG") $(sqlv "select beneficiary_validated from glpi_plugin_lagapenak_loans where id=$LEG") $(sqlv "select count(*) from glpi_plugin_lagapenak_loans where uuid='00000000-0000-4000-8000-000000000000'") $(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LEG and cancel_reason is not null")"
check D15 "Ningún préstamo antiguo se ha completado solo" 0 "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id in ($LBAD,$LREQ,$LREQD,$LOLD) and beneficiary_validated=1")"
check D16 "Datos históricos autocompletados intactos (sin corrección automática)" "$U1MAIL 0" "$(sqlv "select beneficiary_email from glpi_plugin_lagapenak_loans where id=$LREQ") $(sqlv "select beneficiary_validated from glpi_plugin_lagapenak_loans where id=$LREQ")"
lpost d1_u1 "$P/front/loan.form.php" --data-urlencode save_loan=1 --data-urlencode "name=F-$RUN-N" --data-urlencode entities_id=$EA \
  --data-urlencode users_id_destinatario=$SUPA --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" \
  --data-urlencode "beneficiary_name=" --data-urlencode "beneficiary_email=sin.nombre.$RUN@example.test" --data-urlencode "beneficiary_dni=" >/dev/null
check D17 "Sin nombre del beneficiario: rechazado, con aviso y lo escrito conservado" "0 1 1" "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where name='F-$RUN-N'") $(grep -c 'alert-danger' /tmp/last_body | sed 's/[2-9]/1/') $(grep -c "sin.nombre.$RUN@example.test" /tmp/last_body)"
check D18 "Interfaz: nombre marcado obligatorio y validación antes de enviar" "1 1" "$(echo "$FORM" | grep -c 'name="beneficiary_name" maxlength="255"') $(echo "$FORM" | grep -c 'TRWAGMYFPDXBNJZSQVHLCKE')"
# Préstamo antiguo sin nombre ni identificadores: sigue editable; cambiar su beneficiario exige la regla completa
LEG2=$(mkloan $EA 1 "" "" 0); sqlv "update glpi_plugin_lagapenak_loans set beneficiary_name='' where id=$LEG2"
lpost d1_supA "$P/front/loan.form.php?id=$LEG2" --data-urlencode save_loan=1 --data-urlencode "name=Legado2 $RUN" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_name=" --data-urlencode "beneficiary_email=" --data-urlencode "beneficiary_dni=" >/dev/null
check D19 "Antiguo sin nombre ni identificadores: consultable y editable sin tocarlo" "Legado2 $RUN 200" "$(sqlv "select name from glpi_plugin_lagapenak_loans where id=$LEG2") $(api "$K1" GET "/v1/loans/$(uuidof $LEG2)")"
lpost d1_supA "$P/front/loan.form.php?id=$LEG2" --data-urlencode save_loan=1 --data-urlencode "name=Legado2 $RUN" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_name=" --data-urlencode "beneficiary_email=legado2.$RUN@example.test" --data-urlencode "beneficiary_dni=" >/dev/null
check D20 "Al editar su beneficiario sin nombre: rechazado y lo escrito se conserva" " 1" "$(sqlv "select beneficiary_email from glpi_plugin_lagapenak_loans where id=$LEG2") $(grep -c "legado2.$RUN@example.test" /tmp/last_body | sed 's/[2-9]/1/')"

# ── 5. Cancelación ──────────────────────────────────────────────────────────
LC=$(newloan "F-$RUN-C" "cancela.$RUN@example.test" ""); LC_U=$(uuidof $LC)
additems $LC 1
login d1_u2 >/dev/null; login d1_supB >/dev/null
lpost d1_u2 "$P/front/loan.form.php?id=$LC" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=intento" >/dev/null
lpost d1_supB "$P/front/loan.form.php?id=$LC" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=intento" >/dev/null
check E01 "Otro solicitante y el supervisor de otra entidad no cancelan una solicitud ajena" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC")"
check E02 "Botón «Cancelar solicitud» visible para el supervisor" 1 "$(get d1_supA "$P/front/loan.form.php?id=$LC" | grep -c 'name="cancel_loan"')"
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=" >/dev/null
check E03 "Cancelar sin motivo: no se cancela" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC")"
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=Motivo privado $RUN" >/dev/null
check E04 "Cancelación: estado, usuario, fecha y motivo; préstamo y líneas se conservan" "4 $SUPA 1 Motivo privado $RUN 1" "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC") $(sqlv "select cancel_users_id from glpi_plugin_lagapenak_loans where id=$LC") $(sqlv "select cancel_date is not null from glpi_plugin_lagapenak_loans where id=$LC") $(sqlv "select cancel_reason from glpi_plugin_lagapenak_loans where id=$LC") $(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LC")"
check E05 "Queda en el registro de eventos de GLPI" 1 "$(sqlv "select count(*)>0 from glpi_events where items_id=$LC and service='lagapenak' and message like '%cancelled loan%'")"
check E06 "La ficha muestra la cancelación con su motivo" 1 "$(get d1_supA "$P/front/loan.form.php?id=$LC" | grep -c "Motivo privado $RUN")"
api "$K1" GET "/v1/loans/$LC_U" >/dev/null
check E07 "API: status y material cancelled, fecha de cancelación, sin motivo" "cancelled cancelled 1 0" "$(j '$j["status"]') $(j '$j["material_status"]') $(j '(int)!empty($j["cancellation"]["cancelled_at"])') $(grep -c 'Motivo privado' /tmp/api_body)"
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode add_loanitem=1 --data-urlencode loans_id=$LC --data-urlencode itemtype=Computer --data-urlencode items_id=$PCA >/dev/null
LI=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$LC limit 1")
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode update_loanitem=1 --data-urlencode loanitem_id=$LI --data-urlencode item_status=2 >/dev/null
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode bulk_update_items=1 --data-urlencode bulk_status=2 >/dev/null
check E08 "Cancelado: no se añade ni se entrega material" "1 1" "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LC") $(sqlv "select status from glpi_plugin_lagapenak_loanitems where id=$LI")"
lpost d1_supA "$P/front/albaran.php?id=$LC" --data-urlencode action_gen_token=1 >/dev/null
check E09 "Cancelado: no se genera enlace de firma" "" "$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LC")"
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode save_loan=1 --data-urlencode "name=F-$RUN-C" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode status=1 >/dev/null
check E10 "Un cancelado no se reabre desde el formulario" 4 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC")"
LC2=$(newloan "F-$RUN-C2" "cancela2.$RUN@example.test" "")
lpost d1_supA "$P/front/loan.form.php?id=$LC2" --data-urlencode save_loan=1 --data-urlencode "name=F-$RUN-C2" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_email=cancela2.$RUN@example.test" --data-urlencode status=4 >/dev/null
check E11 "No se cancela por el formulario (sin motivo ni registro)" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC2")"
LCD=$(newloan "F-$RUN-CD" "entregado.$RUN@example.test" ""); additems $LCD 2; sqlv "update glpi_plugin_lagapenak_loans set status=2 where id=$LCD"
lpost d1_supA "$P/front/loan.form.php?id=$LCD" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=x" >/dev/null
check E12 "Con material entregado: no se puede cancelar" "2 0" "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LCD") $(get d1_supA "$P/front/loan.form.php?id=$LCD" | grep -c 'name="cancel_loan"')"
LCS=$(newloan "F-$RUN-CS" "firmado.$RUN@example.test" ""); sqlv "update glpi_plugin_lagapenak_loans set signature_data='data:image/png;base64,X',signature_name='F',signature_date=NOW() where id=$LCS"
lpost d1_supA "$P/front/loan.form.php?id=$LCS" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=x" >/dev/null
check E13 "Con albarán firmado: no se puede cancelar" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LCS")"
LCR=$(newloan "F-$RUN-CR" "devuelto.$RUN@example.test" ""); additems $LCR 3
lpost d1_supA "$P/front/loan.form.php?id=$LCR" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=x" >/dev/null
check E14 "Con material ya devuelto (entrega efectiva): no se puede cancelar" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LCR")"
check E15 "La retirada (borrado) sigue siendo distinta: un cancelado no se retira" "1" "$(lpost d1_u1 "$P/front/loan.form.php?id=$LC" --data-urlencode delete_loan=1 >/dev/null; sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LC")"
# Solicitante original (G1-F05)
LQ=$(newloan "F-$RUN-Q" "propia.$RUN@example.test" ""); LQ_U=$(uuidof $LQ)
check E16 "El solicitante ve «Cancelar solicitud» en su propia solicitud" 1 "$(get d1_u1 "$P/front/loan.form.php?id=$LQ" | grep -c 'name="cancel_loan"')"
lpost d1_u1 "$P/front/loan.form.php?id=$LQ" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=" >/dev/null
check E17 "Solicitante sin motivo: no se cancela" 1 "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LQ")"
lpost d1_u1 "$P/front/loan.form.php?id=$LQ" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=Motivo privado solicitante $RUN" >/dev/null
check E18 "El solicitante cancela su solicitud: usuario, fecha y motivo" "4 $U1 1 1" "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LQ") $(sqlv "select cancel_users_id from glpi_plugin_lagapenak_loans where id=$LQ") $(sqlv "select cancel_date is not null from glpi_plugin_lagapenak_loans where id=$LQ") $(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LQ and cancel_reason='Motivo privado solicitante $RUN'")"
check E19 "API: cancelado por el solicitante (id y nombre), sin motivo" "$U1 0" "$(api "$K1" GET "/v1/loans/$LQ_U" >/dev/null; j '$j["cancellation"]["cancelled_by"]["id"]') $(grep -c 'Motivo privado' /tmp/api_body)"
LQD=$(newloan "F-$RUN-QD" "propia.e.$RUN@example.test" ""); additems $LQD 2; sqlv "update glpi_plugin_lagapenak_loans set status=2 where id=$LQD"
LQS=$(newloan "F-$RUN-QS" "propia.f.$RUN@example.test" ""); sqlv "update glpi_plugin_lagapenak_loans set signature_data='data:image/png;base64,X',signature_name='F',signature_date=NOW() where id=$LQS"
lpost d1_u1 "$P/front/loan.form.php?id=$LQD" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=x" >/dev/null
lpost d1_u1 "$P/front/loan.form.php?id=$LQS" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=x" >/dev/null
check E20 "Solicitante: con material entregado o albarán firmado no cancela; sin botón" "2 1 0 0" "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LQD") $(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LQS") $(get d1_u1 "$P/front/loan.form.php?id=$LQD" | grep -c 'name="cancel_loan"') $(get d1_u1 "$P/front/loan.form.php?id=$LQS" | grep -c 'name="cancel_loan"')"
check E21 "Usuario sin solicitud propia no ve el botón en una ajena" 0 "$(get d1_u2 "$P/front/loan.form.php?id=$LQD" | grep -c 'name="cancel_loan"')"
# Cambios concurrentes: el préstamo se leyó antes de una firma / de un cambio de estado
cp /tmp/prueba_g1c.php $P_DIR/front/prueba_g1c.php 2>/dev/null; chown www-data $P_DIR/front/prueba_g1c.php
LR1=$(newloan "F-$RUN-R1" "carrera1.$RUN@example.test" ""); LR2=$(newloan "F-$RUN-R2" "carrera2.$RUN@example.test" "")
check E22 "Carrera con una firma: no se cancela" '{"cancelled":false,"status":1}' "$(get d1_supA "$P/front/prueba_g1c.php?op=race&race=sign&id=$LR1")"
check E23 "Carrera con un cambio de estado: no se cancela" '{"cancelled":false,"status":5}' "$(get d1_supA "$P/front/prueba_g1c.php?op=race&race=status&id=$LR2")"
LR3=$(newloan "F-$RUN-R3" "carrera3.$RUN@example.test" "")
for i in 1 2 3; do lpost d1_supA "$P/front/loan.form.php?id=$LR3" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=doble $i" >/dev/null & done; wait
check E24 "Tres envíos simultáneos: una sola cancelación registrada" "4 1" "$(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LR3") $(sqlv "select count(*) from glpi_events where items_id=$LR3 and service='lagapenak' and message like '%cancelled loan%'")"

# ── 5b. Cancelado: no se borra (G1-F06) ni se reabre (G1-F07) ───────────────
login d1_suppurga >/dev/null
lpost d1_suppurga "$P/front/loan.form.php?id=$LC" --data-urlencode delete_loan=1 >/dev/null
check X01 "Supervisor con permiso de borrar: el cancelado no se borra (formulario)" 1 "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LC")"
check X02 "Sin botón «Borrar» en un cancelado" 0 "$(get d1_suppurga "$P/front/loan.form.php?id=$LC" | grep -c 'name="delete_loan"')"
check X03 "Vía GLPI (acciones masivas / API de GLPI): ni permiso ni borrado" '{"can_purge":false,"deleted":false,"deleted_anyway":false,"exists":true}' "$(get d1_suppurga "$P/front/prueba_g1c.php?op=purge&id=$LC")"
LNC=$(newloan "F-$RUN-NC" "nocancel.$RUN@example.test" "")
check X04 "Control: un no cancelado sin firma sí se puede borrar con ese permiso" '{"can_purge":true,"deleted":true,"deleted_anyway":false,"exists":false}' "$(get d1_suppurga "$P/front/prueba_g1c.php?op=purge&id=$LNC")"
check X05 "Un cancelado sigue consultable (ficha y API)" "1 200" "$(get d1_supA "$P/front/loan.form.php?id=$LC" | grep -c "Motivo privado $RUN" | sed 's/[2-9]/1/') $(api "$K1" GET "/v1/loans/$LC_U")"
check X06 "Vías internas: ni material, ni fechas, ni ampliación, ni firma, ni estado" '{"line_update_right":false,"loan_update":false,"massive_field":false,"date_request":false,"signature":false,"status":4,"lines_delivered":0,"lines":1,"dates_applied":0}' "$(get d1_supA "$P/front/prueba_g1c.php?op=reopen&id=$LC&asset=$PCA")"
lpost d1_u1 "$P/front/loan.form.php?id=$LQ" --data-urlencode save_date_request=1 --data-urlencode "requested_date_end=$D4 22:00:00" >/dev/null
lpost d1_supA "$P/front/loan.form.php?id=$LC" --data-urlencode approve_date_request=1 >/dev/null
lpost d1_supA "$P/front/albaran.php?id=$LQ" --data-urlencode save_signature=1 --data-urlencode "signature_data=data:image/png;base64,QUJD" --data-urlencode "signature_name=X" >/dev/null
check X07 "Formulario: ampliación, aprobación y firma rechazadas en cancelados" "0 4 4 0" "$(sqlv "select date_request_status from glpi_plugin_lagapenak_loans where id=$LQ") $(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LQ") $(sqlv "select status from glpi_plugin_lagapenak_loans where id=$LC") $(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LQ and signature_data<>''")"
check X08 "El solicitante no ve «Solicitar cambio de fecha» en un cancelado" 0 "$(get d1_u1 "$P/front/loan.form.php?id=$LQ" | grep -c 'dateRequestModal')"
check X09 "Un cancelado se muestra en solo lectura también al supervisor (sin guardar ni añadir material)" "0 0" "$(get d1_supA "$P/front/loan.form.php?id=$LC" | grep -c 'name="save_loan"') $(get d1_supA "$P/front/loan.form.php?id=$LC" | grep -c 'name="add_loanitem"')"
mv $P_DIR/front/prueba_g1c.php /tmp/prueba_g1c-usada-$RUN.php

# ── 6. Estado del material (F01, F02) ───────────────────────────────────────
mat() { local id=$(mkloan $EA $1 "m.$RUN@example.test" ""); shift; [ $# -gt 0 ] && additems $id "$@"
  api "$K1" GET "/v1/loans?number=$id" "${H_ACT[@]}" >/dev/null; echo "$(j '$j["data"][0]["status"]')/$(j '$j["data"][0]["material_status"]')"; }
check M01 "Sin líneas: solicitado y no entregado (F02)"           "pending/pending_delivery"            $(mat 1)
check M02 "Líneas sin entregar (F02)"                              "pending/pending_delivery"            $(mat 1 1 1)
check M03 "Todo entregado"                                         "delivered/delivered"                 $(mat 2 2 2)
check M04 "Entregado + sin entregar: entregado parcialmente"       "partially_delivered/partially_delivered" $(mat 5 2 1)
check M05 "Devuelto + sin entregar: entregado parcialmente"        "partially_delivered/partially_delivered" $(mat 5 3 1)
check M06 "Devuelto + entregado: pendiente de devolución"          "delivered/partially_returned"        $(mat 2 3 2)
check M07 "Incidencia + entregado: pendiente de devolución"        "delivered/partially_returned"        $(mat 2 4 2)
check M08 "Todo devuelto sin incidencias"                          "returned/returned"                   $(mat 3 3 3)
check M09 "Devuelto con incidencia (F01)"                          "returned/returned_with_incident"     $(mat 3 3 4)
check M10 "Incidencia identificable: resumen y línea"              "1 returned_with_incident"            "$(j '$j["data"][0]["items_summary"]["returned_with_incident"]') $(j '$j["data"][0]["items"][1]["status"]')"
check M11 "Solo incidencias"                                        "returned/returned_with_incident"     $(mat 3 4)
check M12 "Cancelado sin líneas / con líneas sin entregar"          "cancelled/cancelled cancelled/cancelled" "$(mat 4) $(mat 4 1)"
check M13 "Cancelado (histórico) con material entregado: sigue fuera" "cancelled/delivered"               $(mat 4 2)
check M14 "Cancelado (histórico) con todo devuelto"                 "cancelled/cancelled"                 $(mat 4 3)

# ── 7. Sincronización (nivel 1) ─────────────────────────────────────────────
sleep 3
C0=$(feedall "$K1" "1970-01-01T00:00:00Z")
check S01 "Descubrimiento: campos mínimos de un evento" '["event","loan_uuid","number","changed_at","period","material_status","beneficiary"]' "$(jf 'array_keys(array_values(array_filter($f,function($e){return $e["loan_uuid"]==="'$LA_U'";}))[0])')"
check S02 "Descubrimiento sin nombres, emails, DNI ni textos" 0 $(leaks /tmp/feed_all.json)
check S03 "Préstamos de 2026 incluidos (con y sin identificadores)" "upsert upsert upsert" "$(evt $LA_U) $(evt $LBAD_U) $(evt $LP_U)"
check S04 "Anterior a 2026, ya devuelto: fuera del alta inicial" "" "$(evt $LOLD_U)"
check S05 "Empezado en 2025 y en curso en 2026: incluido" upsert "$(evt $LRUN_U)"
check S06 "De 2025 con material aún fuera: incluido" upsert "$(evt $LOUT_U)"
check S07 "Otra entidad: no aparece" "" "$(evt $LB_U)"
check S08 "Sin duplicados en el recorrido completo" 0 "$(jf 'count($f)-count(array_unique(array_column($f,"loan_uuid")))')"
TOTAL=$(jf 'count($f)'); feedall "$K1" "1970-01-01T00:00:00Z" 3 >/dev/null
check S09 "Paginando de 3 en 3: mismos eventos, sin pérdidas" "$TOTAL 0" "$(jf 'count($f)') $(jf 'count($f)-count(array_unique(array_column($f,"loan_uuid")))')"
check S10 "Repetir con next_since: nada nuevo (reintento seguro)" 0 "$(api "$K1" GET "/v1/loan-events?since=$C0" >/dev/null; j 'count($j["data"])')"
check S11 "Repetir la misma página: mismo resultado (idempotente)" "same" "$(api "$K1" GET "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=5" >/dev/null; md5sum < /tmp/api_body > /tmp/h1; api "$K1" GET "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=5" >/dev/null; md5sum < /tmp/api_body | cmp -s - /tmp/h1 && echo same || echo diff)"
s12() { local t0=$(date +%s%3N); sqlv "update glpi_plugin_lagapenak_loans set beneficiary_email='nuevo.$RUN@example.test', date_mod=NOW() where id=$LA2"
  feedall "$K1" "$C0" >/dev/null; S12R=$(evt $LA2_U); S12MS=$(( $(date +%s%3N) - t0 )); }
s12; if [ $S12MS -ge 1500 ]; then echo "S12 lectura lenta ($S12MS ms, resultado [$S12R]): se repite" >> $DIAG/avisos.txt; s12; fi
check S12 "Un cambio de hace menos de 2 s aún no se entrega" "" "$S12R"
[ -n "$S12R" ] && echo "S12 lectura en $S12MS ms" >> $DIAG/avisos.txt
sleep 3; C1=$(feedall "$K1" "$C0")
check S13 "Cambio de beneficiario: evento con la huella nueva" "upsert $(fp "$KEY1" email "nuevo.$RUN@example.test")" "$(evt $LA2_U) $(jf 'array_values(array_filter($f,function($e){return $e["loan_uuid"]==="'$LA2_U'";}))[0]["beneficiary"]["fingerprints"][0]["email"]')"
sqlv "update glpi_plugin_lagapenak_loans set signature_date=NOW() where id=$LA"; sqlv "update glpi_plugin_lagapenak_loanitems set notes='cambio $RUN', date_mod=NOW() where loans_id=$LS limit 1"
sleep 3; C2=$(feedall "$K1" "$C1")
check S14 "Firma (no toca date_mod) y cambio de línea: eventos" "upsert upsert" "$(evt $LA_U) $(evt $LS_U)"
check S15 "La cancelación apareció en el descubrimiento" "cancelled" "$(feedall "$K1" "1970-01-01T00:00:00Z" >/dev/null; jf 'array_values(array_filter($f,function($e){return $e["loan_uuid"]==="'$LC_U'";}))[0]["material_status"]')"
# Fechas, estado del material, comentarios y cancelación entre dos lecturas
additems $F5 1; LIF5=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$F5 limit 1")
LS2=$(newloan "F-$RUN-S2" "sync2.$RUN@example.test" ""); LS2_U=$(uuidof $LS2)
sleep 3; C2B=$(feedall "$K1" "$C2")
lpost d1_supA "$P/front/loan.form.php?id=$F6" --data-urlencode save_loan=1 --data-urlencode "name=F-$RUN-6" --data-urlencode entities_id=$EA --data-urlencode users_id_destinatario=$SUPA \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 20:00:00" >/dev/null
lpost d1_supA "$P/front/loan.form.php?id=$F5" --data-urlencode update_loanitem=1 --data-urlencode loanitem_id=$LIF5 --data-urlencode item_status=2 >/dev/null
lpost d1_u1 "$P/front/loan.form.php?id=$F4" --data-urlencode add_comment=1 --data-urlencode "comment_text=comentario privado $RUN" >/dev/null
lpost d1_u1 "$P/front/loan.form.php?id=$LS2" --data-urlencode cancel_loan=1 --data-urlencode "cancel_reason=Motivo privado sync $RUN" >/dev/null
sleep 3; C2C=$(feedall "$K1" "$C2B")
upd() { jf 'json_encode(array_values(array_filter($f,function($e){return $e["loan_uuid"]==="'$1'";}))[0]'"$2"')' | tr -d '"'; }
check S25 "Cambio de fechas: evento con el periodo nuevo" "upsert $(date -d "$D4 20:00:00" +%FT%T)" "$(evt $(uuidof $F6)) $(upd $(uuidof $F6) '["period"]["end"]' | cut -c1-19)"
check S26 "Cambio del estado del material: evento con el estado nuevo" "upsert delivered" "$(evt $(uuidof $F5)) $(upd $(uuidof $F5) '["material_status"]')"
check S27 "Comentario nuevo: evento (y en el detalle solo el número)" "upsert 1" "$(evt $(uuidof $F4)) $(api "$K1" GET "/v1/loans/$(uuidof $F4)" >/dev/null; j '$j["comments"]["count"]')"
check S28 "Cancelación por el solicitante: evento con material cancelled" "upsert cancelled" "$(evt $LS2_U) $(upd $LS2_U '["material_status"]')"
check S29 "Sin datos en claro en estos eventos" 0 $(leaks /tmp/feed_all.json)
C2=$C2C
# Borrado (retirada de una solicitud intacta por su solicitante)
LD=$(newloan "F-$RUN-D" "borrar.$RUN@example.test" ""); LD_U=$(uuidof $LD)
lpost d1_u1 "$P/front/loan.form.php?id=$LD" --data-urlencode delete_loan=1 >/dev/null
sleep 3; C3=$(feedall "$K1" "$C2")
check S16 "Borrado entre dos lecturas: solo el evento deleted, con el material" "deleted pending_delivery" "$(evt $LD_U) $(jf 'implode("",array_column(array_filter($f,function($e){return $e["event"]==="deleted" && $e["loan_uuid"]==="'$LD_U'";}),"material_status_at_deletion"))')"
check S17 "Borrado: detalle 410 · en lote reason=deleted" "410 deleted" "$(api "$K1" GET "/v1/loans/$LD_U") $(api "$K1" GET "/v1/loans?uuids=$LD_U" >/dev/null; j '$j["not_found"][0]["reason"]')"
check S18 "Borrado de otra entidad: otro cliente no lo ve" "" "$(feedall "$KB" "1970-01-01T00:00:00Z" >/dev/null; evt $LD_U)"
# Cambio de entidad desde el formulario (supervisor de A y B)
LM=$(newloan "F-$RUN-M" "mover.$RUN@example.test" ""); LM_U=$(uuidof $LM)
login d1_supAB >/dev/null; get d1_supAB "$B/front/central.php?force_entity=all" >/dev/null; sleep 3; C4=$(feedall "$K1" "$C3")
lpost d1_supAB "$P/front/loan.form.php?id=$LM" --data-urlencode save_loan=1 --data-urlencode "name=F-$RUN-M" --data-urlencode entities_id=$EB --data-urlencode users_id_destinatario=$SUPB \
  --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "beneficiary_email=mover.$RUN@example.test" >/dev/null
check S19 "Movido a otra entidad desde el formulario" "$EB 1" "$(sqlv "select entities_id from glpi_plugin_lagapenak_loans where id=$LM") $(sqlv "select count(*) from glpi_plugin_lagapenak_loanevents where loans_id=$LM and event='entity_changed'")"
sleep 3; C5=$(feedall "$K1" "$C4")
check S20 "Cliente de A: evento unavailable · detalle 404" "unavailable 404" "$(evt $LM_U) $(api "$K1" GET "/v1/loans/$LM_U")"
check S21 "Cliente de B: aparece como upsert" upsert "$(feedall "$KB" "1970-01-01T00:00:00Z" >/dev/null; evt $LM_U)"
# Ningún préstamo conocido desaparece sin evento: el último evento de cada uuid coincide con su detalle
feedall "$K1" "1970-01-01T00:00:00Z" >/dev/null
jf 'implode("\n",array_keys(array_filter(array_column($f,"event","loan_uuid"),function($e){return $e==="upsert";})))' > /tmp/known_upsert; echo >> /tmp/known_upsert
GONE=0; while read -r chunk; do [ -z "$chunk" ] && continue; api "$K1" GET "/v1/loans?uuids=$chunk" >/dev/null; GONE=$((GONE + $(j 'count($j["not_found"])'))); done < <(xargs -n 50 < /tmp/known_upsert | tr ' ' ',')
jf 'implode("\n",array_keys(array_filter(array_column($f,"event","loan_uuid"),function($e){return $e!=="upsert";})))' > /tmp/known_gone; echo >> /tmp/known_gone
GONE_OK=0; while read -r u; do [ -z "$u" ] && continue; c=$(api "$K1" GET "/v1/loans/$u"); [ "$c" = 404 ] || [ "$c" = 410 ] && GONE_OK=$((GONE_OK+1)); done < /tmp/known_gone
check S30 "Último evento coherente con el detalle: upsert → existe; deleted/unavailable → 410/404" "0 $(grep -c . /tmp/known_gone)" "$GONE $GONE_OK"

# Cambio de las entidades autorizadas al cliente: todo se vuelve a entregar
CID1=$(sqlv "select id from glpi_plugin_lagapenak_apiclients where name='T-K1 $RUN'")
sv() { api "$1" GET "${2:-/v1/meta}" >/dev/null; if [ -z "$2" ]; then j '$j["client"]["scope_version"]'; else j '$j["scope_version"]'; fi; }
SV0=$(sv "$K1")
check V01 "scope_version igual en meta, en el descubrimiento y en la cabecera" "$SV0 $SV0 $SV0 1" "$(sv "$K1") $(sv "$K1" "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=1") $(hdr X-Lagapenak-Scope-Version) $(echo "$SV0" | grep -c '^sv1-[0-9a-f]\{16\}$')"
check V02 "Sin cambios, la versión es estable; otro ámbito, otra versión" "$SV0 1" "$(sv "$K1") $( [ "$(sv "$KP")" != "$SV0" ] && echo 1 || echo 0)"
check V03 "La cabecera también va en un error tras autenticar (403 de ámbito)" "403 1" "$(api "$KPO" GET "/v1/loans/$LA_U") $(hdr X-Lagapenak-Scope-Version | grep -c '^sv1-')"
sleep 1; sqlv "update glpi_plugin_lagapenak_apiclients set entities='[$EA,$EB]', scope_mod=NOW() where id=$CID1"; sleep 3
SV1=$(sv "$K1")
C6=$(feedall "$K1" "$C5")
check S22 "Ampliar entidades del cliente: se reenvían todos (incluidos los de B)" "upsert upsert" "$(evt $LA_U) $(evt $LB_U)"
# Préstamos conocidos con el alcance ampliado (los de B incluidos), para reconciliar tras reducir
jf 'implode("\n",array_keys(array_filter(array_column($f,"event","loan_uuid"),function($e){return $e==="upsert";})))' > /tmp/known_ext; echo >> /tmp/known_ext
sqlv "update glpi_plugin_lagapenak_apiclients set entities='[$EA]', scope_mod=NOW() where id=$CID1"
SV2=$(sv "$K1")
check V04 "Ampliar cambia la versión; reducir la cambia de nuevo (vuelve a la anterior)" "1 $SV0" "$( [ "$SV1" != "$SV0" ] && echo 1 || echo 0) $SV2"
# Reconciliación del consumidor: lote de los conocidos; los de B vuelven como not_available y sin datos
echo '[]' > /tmp/recon.json
while read -r chunk; do [ -z "$chunk" ] && continue; api "$K1" GET "/v1/loans?uuids=$chunk" >/dev/null
  php -r '$a=json_decode(file_get_contents("/tmp/recon.json"),true); $b=json_decode(file_get_contents("/tmp/api_body"),true); $a[]=["data"=>array_map(function($l){return [$l["uuid"],$l["entity"]["id"]];},$b["data"]),"nf"=>$b["not_found"]]; file_put_contents("/tmp/recon.json",json_encode($a));'
done < <(xargs -n 50 < /tmp/known_ext | tr ' ' ',')
NB=$(for u in $(cat /tmp/known_ext); do echo -n "'$u',"; done | sed 's/,$//'); NB=$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where uuid in ($NB) and entities_id=$EB")
check V05 "Reducir entidades: los de B, not_available; ningún dato de B en la respuesta" "$NB 0" "$(php -r '$a=json_decode(file_get_contents("/tmp/recon.json"),true); $n=0; foreach($a as $p) foreach($p["nf"] as $x) $n+=$x["reason"]==="not_available"; echo $n;') $(php -r '$a=json_decode(file_get_contents("/tmp/recon.json"),true); $n=0; foreach($a as $p) foreach($p["data"] as $d) $n+=($d[1]=='$EB'); echo $n;')"
check S23 "meta informa del cambio de autorización (reconciliar por lote)" 1 "$(api "$K1" GET /v1/meta >/dev/null; j '(int)!empty($j["client"]["scope_changed_at"])')"
check S31 "Tras reducir las entidades: la reconciliación por lote los devuelve como not_available" "not_available" "$(api "$K1" GET "/v1/loans?uuids=$LB_U" >/dev/null; j '$j["not_found"][0]["reason"]')"
# Cambio en el árbol de entidades de GLPI sin tocar el cliente: la subentidad D1-A1 sale de D1-A
entmove() { sqlv "update glpi_entities set entities_id=$1, completename='$2', level=$3, ancestors_cache='$4' where id=$EA1"; sqlv "update glpi_entities set sons_cache=NULL"
  su -s /bin/bash www-data -c "php /var/www/html/glpi/bin/console cache:clear --no-interaction" >/dev/null 2>&1; }
SVT0=$(sv "$K1")
entmove 0 'Entidad raíz > D1-A1' 2 '{"0":0}'
SVT1=$(sv "$K1")
check V06 "Subentidad sacada de D1-A en GLPI (sin tocar el cliente): versión nueva" 1 "$( [ "$SVT1" != "$SVT0" ] && echo 1 || echo 0)"
check V07 "Sus préstamos: lote not_available, detalle 404, sin evento ni datos" "not_available 404 " "$(api "$K1" GET "/v1/loans?uuids=$LS_U" >/dev/null; j '$j["not_found"][0]["reason"]') $(api "$K1" GET "/v1/loans/$LS_U") $(feedall "$K1" "1970-01-01T00:00:00Z" >/dev/null; evt $LS_U)"
entmove $EA 'Entidad raíz > D1-A > D1-A1' 3 "{\"0\":0,\"$EA\":$EA}"
check V08 "Subentidad devuelta: versión original y préstamo visible de nuevo" "$SVT0 200" "$(sv "$K1") $(api "$K1" GET "/v1/loans/$LS_U")"
check S24 "since inválido / sin since / limit 0 o 101: 400" "400 400 400 400" "$(api "$K1" GET "/v1/loan-events?since=ayer") $(api "$K1" GET "/v1/loan-events") $(api "$K1" GET "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=0") $(api "$K1" GET "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=101")"
# Rotación de la clave de identificación
sleep 1; apost "$P/front/apiclient.php" --data-urlencode rotate_hmac_key=1 --data-urlencode transition_days=30 >/dev/null
PAGE=$(get glpi "$P/front/apiclient.php"); KID2=$(echo "$PAGE" | grep -o 'kid: g1-[0-9]*-[0-9a-f]*' | head -1 | cut -d' ' -f2); KEY2=$(echo "$PAGE" | grep -o 'key (base64): [A-Za-z0-9+/=]*' | head -1 | cut -d' ' -f3)
api "$K1" GET /v1/meta >/dev/null
check R01 "Rotación: meta con clave nueva activa y anterior en transición" "$KID2 active previous" "$(j '$j["identification"]["active_kid"]') $(j 'implode(" ",array_column($j["identification"]["keys"],"state"))' | tr ' ' '\n' | sort | tr '\n' ' ' | sed 's/ $//')"
api "$K1" GET "/v1/loans/$LA_U" >/dev/null
check R02 "Durante la transición: huellas con las dos claves" "2 $(fp "$KEY1" email "$MAIL_ART") $(fp "$KEY2" email "$MAIL_ART")" "$(j 'count($j["beneficiary"]["fingerprints"])') $(j 'array_column($j["beneficiary"]["fingerprints"],"email","kid")["'$KID1'"]') $(j 'array_column($j["beneficiary"]["fingerprints"],"email","kid")["'$KID2'"]')"
sleep 3; C7=$(feedall "$K1" "$C6")
check R03 "La rotación vuelve a entregar todos los préstamos (sin perder eventos)" "upsert upsert" "$(evt $LA_U) $(evt $LOUT_U)"
apost "$P/front/apiclient.php" --data-urlencode retire_hmac_keys=1 >/dev/null
check R04 "Retirar la anterior: solo la clave nueva" "1 $KID2" "$(api "$K1" GET "/v1/loans/$LA_U" >/dev/null; j 'count($j["beneficiary"]["fingerprints"])') $(j '$j["beneficiary"]["fingerprints"][0]["kid"]')"

# ── 8. Límites, proxies y registro ──────────────────────────────────────────
cfg api_rate_per_minute 5
for i in 1 2 3 4 5; do api "$K6" GET /v1/meta >/dev/null; done
check L01 "Límite por minuto: 429 + Retry-After; otro cliente sigue" "429 60 200" "$(api "$K6" GET /v1/meta) $(hdr Retry-After) $(api "$K5" GET /v1/meta)"
cfg api_rate_per_minute 10000
XF=192.0.2.$((N % 200 + 20))
check L02 "X-Forwarded-For de origen no confiable: ignorado" 200 $(api "$K1" GET /v1/meta -H "X-Forwarded-For: $XF")
cfg api_trusted_proxies 127.0.0.1
check L03 "Desde proxy confiable: IP real (no permitida → 403)" 403 $(api "$K1" GET /v1/meta -H "X-Forwarded-For: $XF")
for i in $(seq 1 20); do api "lgk_00000000_$(printf 'b%.0s' {1..40})" GET /v1/meta -H "X-Forwarded-For: $XF" >/dev/null; done
check L04 "20 fallos de credencial desde una IP: 429; otras IP siguen" "429 200" "$(api "$K1" GET /v1/meta -H "X-Forwarded-For: $XF") $(api "$K1" GET /v1/meta)"
cfg api_trusted_proxies ''
check L05 "Registro: búsqueda por número con usuario de Kide-Artean" "GET 200 $ACT" "$(sqlv "select concat(method,' ',status,' ',acting_user) from glpi_plugin_lagapenak_apilogs where query like 'number=$LA%' and status=200 order by id desc limit 1")"
check L06 "Registro sin cuerpos (columnas)" "apiclients_id,date,method,route,query,status,code,result_count,ip,request_id,acting_user,personal_count,duration_ms" "$(sqlv "select group_concat(column_name order by ordinal_position) from information_schema.columns where table_schema=database() and table_name='glpi_plugin_lagapenak_apilogs' and column_name<>'id'")"

# ── 9. Administración ───────────────────────────────────────────────────────
check G01 "Usuario sin config UPDATE no abre la administración" denegado $(get d1_supA "$P/front/apiclient.php" | grep -q 'name="add_client"' && echo abierto || echo denegado)
apost "$P/front/apiclient.php" --data-urlencode add_client=1 --data-urlencode "name=T-Admin $RUN" --data-urlencode source=kide-artean \
  --data-urlencode "scopes[]=loans:read" --data-urlencode "entities[]=$EA" --data-urlencode $'allowed_ips=127.0.0.1\n::1' --data-urlencode is_active=1 --data-urlencode "expires_at=$(date -d '+6 months' +%F)" >/dev/null
TOK=$(get glpi "$P/front/apiclient.php" | grep -o 'lgk_[a-f0-9]\{8\}_[A-Za-z0-9]\{40\}' | head -1)
check G02 "Alta: credencial mostrada una vez y válida; solo hash guardado" "200 0 0 1" "$(api "$TOK" GET /v1/meta) $(get glpi "$P/front/apiclient.php" | grep -c 'lgk_[a-f0-9]\{8\}_') $(sqlv "select count(*) from glpi_plugin_lagapenak_apiclients where token_hash='$TOK'") $(sqlv "select count(*) from glpi_plugin_lagapenak_apiclients where token_hash=sha2('$TOK',256)")"
CID=$(sqlv "select id from glpi_plugin_lagapenak_apiclients where name='T-Admin $RUN'")
apost "$P/front/apiclient.php" --data-urlencode rotate_client=1 --data-urlencode id=$CID --data-urlencode grace_days=0 >/dev/null
TOK2=$(get glpi "$P/front/apiclient.php" | grep -o 'lgk_[a-f0-9]\{8\}_[A-Za-z0-9]\{40\}' | head -1)
check G03 "Rotar credencial sin gracia: nueva vale, anterior no" "200 401" "$(api "$TOK2" GET /v1/meta) $(api "$TOK" GET /v1/meta)"
apost "$P/front/apiclient.php" --data-urlencode revoke_client=1 --data-urlencode id=$CID >/dev/null
check G04 "Revocar: deja de funcionar al instante" 401 $(api "$TOK2" GET /v1/meta)
apost "$P/front/apiclient.php" --data-urlencode save_config=1 --data-urlencode api_enabled=1 --data-urlencode api_sync_from=ayer --data-urlencode api_rate_per_minute=120 --data-urlencode api_log_retention_days=365 >/dev/null
check G05 "Fecha de inicio de sincronización inválida: rechazada" 2026-01-01 "$(sqlv "select value from glpi_configs where context='plugin:lagapenak' and name='api_sync_from'")"
check G07 "Formulario de cliente nuevo: solo loans:read marcado por defecto" "1 0" "$(get glpi "$P/front/apiclient.php" | grep -c 'value="loans:read" checked') $(get glpi "$P/front/apiclient.php" | grep -c 'value="loans:read:personal" checked')"
check G06 "Sin token CSRF no se genera clave" "$KID2" "$(curl -s -o /dev/null -b /tmp/cj_glpi --data-urlencode rotate_hmac_key=1 "$P/front/apiclient.php"; api "$K1" GET /v1/meta >/dev/null; j '$j["identification"]["active_kid"]')"

cfg api_enabled 0; cfg api_rate_per_minute 120
echo
echo "Resultado: $PASS OK · $FAIL FALLO"
echo "Diagnóstico: $DIAG ($(ls $DIAG | grep -c '^[A-Z][0-9]*\.txt$') fallos con detalle)"
