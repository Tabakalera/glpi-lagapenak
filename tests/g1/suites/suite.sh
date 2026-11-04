#!/bin/bash
# Batería general de seguridad y regresión de Lagapenak (solo datos ficticios, entorno aislado de tests/g1).
# Uso: tests/g1/g1.sh bateria <entorno> general  (dentro del contenedor: bash /tmp/suite.sh)
# Cada línea: id | qué se prueba | esperado | obtenido | OK/FALLO
source /tmp/lib.sh
RUN=$(date +%H%M%S)
# Fechas propias de cada ronda: sin conflictos de reserva con rondas anteriores
N=$(( $(cat /tmp/suite_n 2>/dev/null || echo 0) + 1 )); echo $N > /tmp/suite_n
D0=$(date -d "2027-01-04 +$((N*14)) days" +%F); D4=$(date -d "$D0 +4 days" +%F); D5=$(date -d "$D0 +5 days" +%F); D8=$(date -d "$D0 +8 days" +%F)
YM=$(date -d "$D0" +%Y); MM=$(date -d "$D0" +%-m)
PASS=0; FAIL=0; OPEN=0
DIAG=/tmp/diag-general-$RUN; mkdir -p $DIAG; echo $DIAG > /tmp/ultimo_diag_general

sqlv() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $r=$c->query($argv[1]); $x=$r?$r->fetch_row():null; echo $x?$x[0]:"";' "$1"; }
mail_count() { curl -s http://mailpit:8025/api/v1/messages | php -r 'echo json_decode(stream_get_contents(STDIN),true)["total"];'; }
mail_to() { curl -s "http://mailpit:8025/api/v1/search?query=to:$1" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["messages_count"];'; }
ajax() { # ajax <usuario> <url> <campos...>
  local u=$1 url=$2; shift 2; local tk=$(csrf $u)
  curl -s -b /tmp/cj_$u -c /tmp/cj_$u -H "X-Glpi-Csrf-Token: $tk" -H "X-Requested-With: XMLHttpRequest" "$@" "$url"
}
check() { # check <id> <descripción> <esperado> <obtenido>
  local st="FALLO"
  if [ "$3" = "$4" ]; then st="OK"; PASS=$((PASS+1)); else FAIL=$((FAIL+1)); diag "$1" "$2" "$3" "$4"; fi
  printf "%-5s | %-62s | %-9s | %-9s | %s\n" "$1" "$2" "$3" "$4" "$st"; tick "$1" "$st"
}
open_item() { # resultado conocido y abierto a la espera de una decisión
  OPEN=$((OPEN+1)); printf "%-5s | %-62s | %-9s | %-9s | %s\n" "$1" "$2" "$3" "$4" "ABIERTO ($5)"
}
newloan() { # newloan <usuario> <entidad> <destinatario> <nombre> → id del préstamo creado (vacío si no)
  post $1 $P/front/loan.form.php --data-urlencode save_loan=1 --data-urlencode "name=$4" \
    --data-urlencode entities_id=$2 --data-urlencode users_id_destinatario=$3 \
    --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" \
    --data-urlencode "field_2=Proyecto $RUN" --data-urlencode "beneficiary_name=Persona Ficticia" \
    --data-urlencode "beneficiary_dni=00000000T" --data-urlencode "beneficiary_email=benef-$RUN@example.test" >/dev/null
  sqlv "select id from glpi_plugin_lagapenak_loans where name='$4' order by id desc limit 1"
}
additem() { # additem <usuario> <préstamo> <itemtype> <items_id>
  post $1 "$P/front/loan.form.php?id=$2" --data-urlencode add_loanitem=1 --data-urlencode loans_id=$2 \
    --data-urlencode itemtype=$3 --data-urlencode items_id=$4 >/dev/null
}
sign() { # sign <usuario> <préstamo> <nombre> <dni>
  post $1 "$P/front/albaran.php?id=$2" --data-urlencode save_signature=1 --data-urlencode "signature_data@/tmp/sig.txt" \
    --data-urlencode "signature_name=$3" --data-urlencode albaran_passport=$4 --data-urlencode "albaran_project=P" >/dev/null
}
anon_sign() { # anon_sign <token> <nombre> → firma por el enlace público, sin sesión
  rm -f /tmp/cj_anon
  local cs=$(curl -s -c /tmp/cj_anon -b /tmp/cj_anon "$P/front/sign.php?token=$1" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -c /tmp/cj_anon -b /tmp/cj_anon -o /dev/null --data-urlencode "_glpi_csrf_token=$cs" --data-urlencode "sign_token=$1" \
    --data-urlencode save_signature=1 --data-urlencode "signature_data@/tmp/sig.txt" --data-urlencode "signature_name=$2" \
    --data-urlencode albaran_passport=55555555K "$P/front/sign.php?token=$1"
}
sigof() { sqlv "select concat(ifnull(signature_name,''),'|',ifnull(albaran_passport,''),'|',ifnull(signature_date,'')) from glpi_plugin_lagapenak_loans where id=$1"; }
yn() { [ "$1" = "$2" ] && echo "$3" || echo "$4"; }

for u in d1_sinderecho d1_u1 d1_u2 d1_supA d1_supB; do login $u >/dev/null; done; login glpi "$G1_ADMIN_PASSWORD" >/dev/null
curl -s -X DELETE http://mailpit:8025/api/v1/messages >/dev/null
echo "Lagapenak $(grep -o "VERSION', '[0-9a-z.-]*" /var/www/html/glpi/plugins/lagapenak/setup.php | sed "s/.*'//") · GLPI $(sqlv "select value from glpi_configs where context='core' and name='version'") · ronda $RUN"
echo "id    | prueba                                                         | esperado  | obtenido  | estado"

# ── Preparación con usuarios autorizados (también es regresión) ─────────────────────────────
LA=$(newloan d1_u1 1 10 "S$RUN-LA u1 en A")
check R1 "Solicitante con CREATE crea préstamo en A (destinatario supA)" creado $( [ -n "$LA" ] && echo creado || echo no )
LA2=$(newloan d1_u2 1 10 "S$RUN-LA2 u2 en A")
LB=$(newloan d1_supB 2 11 "S$RUN-LB supB en B")
check R2 "Supervisor de B crea préstamo en B" creado $( [ -n "$LB" ] && echo creado || echo no )
check R3 "Correo de alta al supervisor (Mailpit)" llega $( [ "$(mail_to d1_supA@example.test)" -ge 1 ] && echo llega || echo no )
additem d1_u1 $LA Computer 1
check R4 "Solicitante añade equipo prestable de A a su préstamo" añadido $( [ "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LA and itemtype='Computer' and items_id=1")" = 1 ] && echo añadido || echo no )
additem d1_u2 $LA2 Computer 2
X2=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$LA2 order by id desc limit 1")
additem d1_supB $LB Computer $(sqlv "select id from glpi_computers where name='D1-PORTATIL-B2'")
XB=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$LB order by id desc limit 1")
check R4b "Preparación: cada préstamo de partida tiene su línea" lineas $( [ -n "$X2" ] && [ -n "$XB" ] && echo lineas || echo faltan )
check R5 "Supervisor de B gestiona su préstamo (abre la ficha editable)" editable $(get d1_supB "$P/front/loan.form.php?id=$LB" | grep -q 'name="save_loan"' && echo editable || echo no)

# ── P1: alta sin derecho, destinatario arbitrario ──────────────────────────────────────────
N0=$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where users_id=7")
newloan d1_sinderecho 2 11 "S$RUN-P1 sin derecho" >/dev/null
check P1a "Usuario sin derecho crea préstamo" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where users_id=7")" "$N0" denegado creado)
check P1b "Usuario sin derecho abre el formulario de alta" denegado $(get d1_sinderecho $P/front/loan.form.php | grep -q 'name="save_loan"' && echo abierto || echo denegado)
OWN7=$(sqlv "select min(id) from glpi_plugin_lagapenak_loans where users_id=7")
# equipo prestable que aún no esté en ese préstamo (si ya estuviera, el duplicado se rechazaría igual en 1.6.0)
CFREE=$( [ -n "$OWN7" ] && sqlv "select r.items_id from glpi_reservationitems r where r.itemtype='Computer' and r.is_active=1 and r.items_id not in (select items_id from glpi_plugin_lagapenak_loanitems where loans_id=$OWN7 and itemtype='Computer') order by r.items_id limit 1")
if [ -n "$OWN7" ] && [ -n "$CFREE" ]; then N1=$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$OWN7"); additem d1_sinderecho $OWN7 Computer $CFREE
  check P1c "Usuario sin derecho añade material a un préstamo antiguo suyo" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$OWN7")" "$N1" denegado añadido); fi
E2=$(newloan d1_u1 1 9 "S$RUN-E2 destinatario u2")
check E2 "Destinatario que no es supervisor de la entidad" denegado $( [ -z "$E2" ] && echo denegado || echo creado )

# ── P2: línea temporal ─────────────────────────────────────────────────────────────────────
TL=$(get d1_sinderecho "$P/ajax/asset_timeline.php?year=$YM&month=$MM")
check P2a "Línea temporal: nombres de solicitantes ajenos (sin derecho)" denegado $(echo "$TL" | grep -q "Ficticia Dos\|Ficticio Uno\|Ficticia SupB" && echo expuesto || echo denegado)
TL=$(get d1_u1 "$P/ajax/asset_timeline.php?year=$YM&month=$MM")
check P2b "Línea temporal: préstamos ajenos (solicitante)" denegado $(echo "$TL" | grep -q "S$RUN-LA2\|S$RUN-LB" && echo expuesto || echo denegado)
ES=$(get d1_supA "$P/ajax/entity_supervisor.php?entities_id=2")
check P2c "Supervisores de una entidad ajena (supA pide B)" denegado $(echo "$ES" | grep -q "SupB" && echo expuesto || echo denegado)
CE=$(get d1_u1 "$P/ajax/calendar_events.php?start=$D0&end=$D8")
check R6 "Calendario del solicitante muestra su préstamo" visible $(echo "$CE" | grep -q "S$RUN-LA u1" && echo visible || echo no)

# ── P3: supervisor de A sobre la entidad B ───────────────────────────────────────────────────
check P3a "supA abre préstamo de B" denegado $(get d1_supA "$P/front/loan.form.php?id=$LB" | grep -q 'name="save_loan"' && echo abierto || echo denegado)
post d1_supA "$P/front/loan.form.php?id=$LB" --data-urlencode save_loan=1 --data-urlencode "name=S$RUN-LB HACKEADO" --data-urlencode entities_id=2 --data-urlencode users_id_destinatario=11 --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" >/dev/null
check P3b "supA edita préstamo de B" denegado $( [ "$(sqlv "select name from glpi_plugin_lagapenak_loans where id=$LB")" = "S$RUN-LB supB en B" ] && echo denegado || echo editado )
check P3c "supA ve el albarán de B (DNI)" denegado $(get d1_supA "$P/front/albaran.php?id=$LB" | grep -q "00000000T" && echo expuesto || echo denegado)
P3C=$(newloan d1_supA 2 11 "S$RUN-P3 supA crea en B")
check P3d "supA crea préstamo en B" denegado $( [ -z "$P3C" ] && echo denegado || echo creado )
post d1_supA "$P/front/loan.form.php?id=$LB" --data-urlencode add_comment=1 --data-urlencode "comment_text=intruso" >/dev/null
check P3e "supA comenta en préstamo de B" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loancomments where loans_id=$LB")" 0 denegado comentado)
post d1_supA "$P/front/loan.form.php?id=$LB" --data-urlencode delete_loan=1 >/dev/null
check P3f "supA borra préstamo de B" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LB")" 1 denegado borrado)

# ── P4 / E3: líneas de otro préstamo ───────────────────────────────────────────────────────
post d1_u1 "$P/front/loan.form.php?id=$LA" --data-urlencode remove_loanitem=1 --data-urlencode loanitem_id=$X2 >/dev/null
check P4 "u1 quita línea de un préstamo de u2 vía el suyo" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where id=$X2")" 1 denegado borrada)
LAS=$(newloan d1_supA 1 10 "S$RUN-LAS supA en A")
post d1_supA "$P/front/loan.form.php?id=$LAS" --data-urlencode update_loanitem=1 --data-urlencode loanitem_id=$XB --data-urlencode item_status=2 --data-urlencode "item_date_checkout=" --data-urlencode "item_date_checkin=" >/dev/null
check E3 "supA cambia estado de línea de B vía préstamo de A" denegado $(yn "$(sqlv "select status from glpi_plugin_lagapenak_loanitems where id=$XB")" 1 denegado cambiada)

# ── P5: objetos que no son activos prestables ──────────────────────────────────────────────
for t in "P5a User 2 usuario_GLPI" "P5b Computer 3 equipo_no_prestable" "P5c ClaseInexistenteD1 1 clase_inexistente"; do set -- $t
  additem d1_u1 $LA $2 $3
  check $1 "Solicitante añade $4" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LA and itemtype='$2' and items_id=$3")" 0 denegado añadido)
done
for i in 1 2; do BA=$(ajax d1_u1 $P/ajax/bulk_add.php --data-urlencode loans_id=$LA --data-urlencode 'items=[{"itemtype":"User","items_id":10}]' --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00"); done
check P5d "Carga en bloque devuelve el nombre de un usuario" denegado $(echo "$BA" | grep -q "SupA" && echo expuesto || echo denegado)
check P5e "Carga en bloque añade un usuario" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LA and itemtype='User'")" 0 denegado añadido)
BA=$(ajax d1_supA $P/ajax/bulk_add.php --data-urlencode loans_id=$LAS --data-urlencode 'items=[{"itemtype":"Computer","items_id":1}]' --data-urlencode "fecha_inicio=$D5 10:00:00" --data-urlencode "fecha_fin=$D8 18:00:00")
check R7 "Carga en bloque válida por supervisor" añadido $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$LAS and itemtype='Computer' and items_id=1")" 1 añadido no)

# ── Firma y albarán ─────────────────────────────────────────────────────────────────────────
curl -s -X DELETE http://mailpit:8025/api/v1/messages >/dev/null
sign d1_u1 $LA "Firma Primera $RUN" 11111111H
S1=$(sigof $LA)
check R8 "Primera firma del solicitante" firmado $(echo "$S1" | grep -q "Firma Primera" && echo firmado || echo no)
check R9 "Correo del albarán firmado (solicitante, beneficiario, kontratazioak)" 3 $(mail_count)
check R10 "Descarga del PDF firmado" pdf $(curl -s -b /tmp/cj_d1_u1 "$P/front/albaran.php?id=$LA&action=pdf" | head -c 4 | grep -q "%PDF" && echo pdf || echo no)
sign d1_u1 $LA "Firma Repetida $RUN" 22222222J
check P6a "Volver a firmar un albarán firmado (solicitante)" denegado $(yn "$(sigof $LA)" "$S1" denegado cambiada)
sign d1_supA $LA "Firma Supervisor $RUN" 22222222J
check P6b "Volver a firmar un albarán firmado (supervisor)" denegado $(yn "$(sigof $LA)" "$S1" denegado cambiada)
post d1_supA "$P/front/loan.form.php?id=$LA" --data-urlencode save_loan=1 --data-urlencode "name=S$RUN-LA u1 en A" --data-urlencode entities_id=1 --data-urlencode users_id_destinatario=10 --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" --data-urlencode "signature_name=E6 cambiada" --data-urlencode albaran_passport=44444444A --data-urlencode "signature_date=2026-01-01 00:00:00" >/dev/null
check E6 "Supervisor reescribe la firma al guardar el préstamo" denegado $(yn "$(sigof $LA)" "$S1" denegado cambiada)
post d1_u1 "$P/front/albaran.php?id=$LA" --data-urlencode action_gen_token=1 >/dev/null
check P6c "Generar enlace de firma para un albarán firmado" denegado $(yn "$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LA")" "" denegado generado)
OLD=$(php -r 'echo bin2hex(random_bytes(32));')
php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $c->query("update glpi_plugin_lagapenak_loans set sign_token=\"".$argv[1]."\", sign_token_expires=date_add(now(), interval 1 day) where id=".$argv[2]);' $OLD $LA
anon_sign $OLD "Firma por enlace antiguo $RUN" >/dev/null
check E1 "Enlace emitido antes de firmar sustituye la firma (sin sesión)" denegado $(yn "$(sigof $LA)" "$S1" denegado cambiada)
php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $c->query("update glpi_plugin_lagapenak_loans set sign_token=NULL, sign_token_expires=NULL where id=".$argv[1]);' $LA

# Flujo legítimo por enlace y reutilización del enlace
LT=$(newloan d1_u1 1 10 "S$RUN-LT firma por enlace")
curl -s -X DELETE http://mailpit:8025/api/v1/messages >/dev/null
post d1_u1 "$P/front/albaran.php?id=$LT" --data-urlencode action_gen_token=1 --data-urlencode sign_to_email=otra-persona@example.invalid >/dev/null
check A5e "Solicitante envía el enlace de firma a otra dirección" denegado $( [ -z "$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LT")" ] && [ "$(mail_to otra-persona@example.invalid)" = 0 ] && echo denegado || echo enviado )
post d1_u1 "$P/front/albaran.php?id=$LT" --data-urlencode action_gen_token=1 --data-urlencode show_link=1 >/dev/null
check A5f "Solicitante pide ver el enlace en pantalla" denegado $( [ -z "$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LT")" ] && echo denegado || echo generado )
post d1_u1 "$P/front/albaran.php?id=$LT" --data-urlencode action_gen_token=1 >/dev/null
TK=$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LT")
check R11 "Enlace de firma para un albarán sin firmar" generado $( [ ${#TK} = 64 ] && echo generado || echo no )
check A5g "El enlace llega solo al firmante (beneficiario)" firmante $( [ "$(mail_to benef-$RUN@example.test)" = 1 ] && [ "$(mail_count)" = 1 ] && echo firmante || echo otros )
anon_sign $TK "Firma por enlace $RUN" >/dev/null
check R12 "Firma por enlace (sin sesión), primer uso" firmado $(sigof $LT | grep -q "Firma por enlace $RUN" && echo firmado || echo no)
ST=$(sigof $LT); anon_sign $TK "Reuso del enlace $RUN" >/dev/null
check P6d "Reutilizar el mismo enlace" denegado $(yn "$(sigof $LT)" "$ST" denegado cambiada)

LP=$(newloan d1_u1 1 10 "S$RUN-LP enlace pendiente y firma presencial")
post d1_u1 "$P/front/albaran.php?id=$LP" --data-urlencode action_gen_token=1 >/dev/null
TP=$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LP")
sign d1_u1 $LP "Firma presencial $RUN" 66666666Q
check P6f "La firma presencial anula el enlace pendiente" anulado $(yn "$(sqlv "select ifnull(sign_token,'') from glpi_plugin_lagapenak_loans where id=$LP")" "" anulado vigente)
SP=$(sigof $LP); anon_sign $TP "Enlace tras firma presencial $RUN" >/dev/null
check P6e "Enlace pendiente tras firmar en pantalla" denegado $(yn "$(sigof $LP)" "$SP" denegado cambiada)

# ── A5: destinatarios del albarán firmado ──────────────────────────────────────────────────
ev() { sqlv "select count(*) from glpi_events where type='PluginLagapenakLoan' and items_id=$1 and message like '%$2%'"; }
curl -s -X DELETE http://mailpit:8025/api/v1/messages >/dev/null
post d1_u1 "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email=destino-arbitrario@example.invalid >/dev/null
check A5a "Solicitante envía el albarán a una dirección no vinculada" denegado $(yn "$(mail_to destino-arbitrario@example.invalid)" 0 denegado enviado)
post d1_u1 "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email_other=destino-arbitrario@example.invalid --data-urlencode "send_reason=me lo pide la productora" >/dev/null
check A5b "Solicitante usa «otra dirección» con motivo" denegado $(yn "$(mail_to destino-arbitrario@example.invalid)" 0 denegado enviado)
post d1_u1 "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email=benef-$RUN@example.test >/dev/null
check R18 "Solicitante envía el albarán al beneficiario (vinculado)" enviado $(yn "$(mail_to benef-$RUN@example.test)" 1 enviado no)
check R19 "Ese envío queda registrado en los eventos de GLPI" registrado $( [ "$(ev $LA benef-$RUN@example.test)" -ge 1 ] && echo registrado || echo no )
post d1_supA "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email_other=externo-$RUN@example.invalid --data-urlencode "send_reason=corto" >/dev/null
check A5c "Supervisor a otra dirección sin motivo suficiente" denegado $(yn "$(mail_to externo-$RUN@example.invalid)" 0 denegado enviado)
post d1_supA "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email_other=externo-$RUN@example.invalid --data-urlencode "send_reason=Copia para la aseguradora del evento" >/dev/null
check R20 "Supervisor a otra dirección con motivo" enviado $(yn "$(mail_to externo-$RUN@example.invalid)" 1 enviado no)
check A5d "El envío excepcional registra destinatario y motivo" registrado $( [ "$(ev $LA aseguradora)" -ge 1 ] && echo registrado || echo no )
post d1_supB "$P/front/albaran.php?id=$LA" --data-urlencode action_email=1 --data-urlencode to_email_other=externo-b-$RUN@example.invalid --data-urlencode "send_reason=Supervisor de otra entidad" >/dev/null
check A5h "Supervisor de otra entidad envía el albarán" denegado $(yn "$(mail_to externo-b-$RUN@example.invalid)" 0 denegado enviado)
LS=$(newloan d1_u1 1 10 "S$RUN-LS enlace en pantalla")
post d1_supA "$P/front/albaran.php?id=$LS" --data-urlencode action_gen_token=1 --data-urlencode show_link=1 >/dev/null
check R21 "Supervisor ve el enlace en pantalla (queda registrado)" registrado $( [ "$(sqlv "select length(ifnull(sign_token,'')) from glpi_plugin_lagapenak_loans where id=$LS")" = 64 ] && [ "$(ev $LS pantalla)" -ge 1 ] && echo registrado || echo no )

# ── Operaciones legítimas después de firmar ─────────────────────────────────────────────────
XA=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$LA and itemtype='Computer' and items_id=1")
post d1_supA "$P/front/loan.form.php?id=$LA" --data-urlencode update_loanitem=1 --data-urlencode loanitem_id=$XA --data-urlencode item_status=2 --data-urlencode "item_date_checkout=" --data-urlencode "item_date_checkin=" >/dev/null
check R13 "Tras firmar: supervisor entrega el material" entregado $(yn "$(sqlv "select status from glpi_plugin_lagapenak_loanitems where id=$XA")" 2 entregado no)
post d1_u1 "$P/front/loan.form.php?id=$LA" --data-urlencode save_date_request=1 --data-urlencode "requested_date_end=$D4 23:00:00" >/dev/null
post d1_supA "$P/front/loan.form.php?id=$LA" --data-urlencode approve_date_request=1 >/dev/null
check R14 "Tras firmar: prórroga solicitada y aprobada" aprobada $(sqlv "select fecha_fin from glpi_plugin_lagapenak_loans where id=$LA" | grep -q "$D4 23:00" && echo aprobada || echo no)
post d1_u1 "$P/front/loan.form.php?id=$LA" --data-urlencode add_comment=1 --data-urlencode "comment_text=comentario tras firmar" >/dev/null
check R15 "Tras firmar: comentario del solicitante" guardado $( [ "$(sqlv "select count(*) from glpi_plugin_lagapenak_loancomments where loans_id=$LA")" -ge 1 ] && echo guardado || echo no)
check R16 "Tras firmar: la firma no ha cambiado" intacta $(yn "$(sigof $LA)" "$S1" intacta cambiada)
check R17 "supA gestiona un préstamo de su entidad (de otro usuario)" editable $(get d1_supA "$P/front/loan.form.php?id=$LA" | grep -q 'name="save_loan"' && echo editable || echo no)

# ── A7: borrado de préstamos firmados ──────────────────────────────────────────────────────
post d1_u1 "$P/front/loan.form.php?id=$LP" --data-urlencode delete_loan=1 >/dev/null
check A7a "Solicitante borra su préstamo pendiente y firmado" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LP")" 1 denegado borrado)
LD=$(newloan d1_u1 1 10 "S$RUN-LD pendiente sin firmar")
post d1_u1 "$P/front/loan.form.php?id=$LD" --data-urlencode delete_loan=1 >/dev/null
check R22 "Solicitante borra su préstamo pendiente sin firmar" borrado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LD")" 0 borrado no)
LX=$(newloan d1_u1 1 10 "S$RUN-LX firmado para borrado de supervisor"); sign d1_u1 $LX "Firma $RUN" 77777777B
check A7c "La ficha firmada no ofrece «Borrar» al supervisor" oculto $(get d1_supA "$P/front/loan.form.php?id=$LX" | grep -q 'name="delete_loan"' && echo visible || echo oculto)
post d1_supA "$P/front/loan.form.php?id=$LX" --data-urlencode delete_loan=1 >/dev/null
check A7b "Supervisor de la entidad borra un préstamo firmado" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LX")" 1 denegado borrado)
check R23 "El préstamo firmado conserva su firma tras el intento" intacta $(sigof $LX | grep -q "Firma $RUN" && echo intacta || echo no)
LY=$(newloan d1_supA 1 10 "S$RUN-LY sin firmar para borrado de supervisor")
post d1_supA "$P/front/loan.form.php?id=$LY" --data-urlencode delete_loan=1 >/dev/null
check R24 "Supervisor borra un préstamo sin firmar" borrado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$LY")" 0 borrado no)

# ── A8: historial (solo si el código desplegado lo incluye; está fuera de la 1.6.1) ────────
if grep -q dohistory /var/www/html/glpi/plugins/lagapenak/inc/loan.class.php; then
post d1_supA "$P/front/loan.form.php?id=$LA" --data-urlencode save_loan=1 --data-urlencode "name=S$RUN-LA renombrado" --data-urlencode entities_id=1 --data-urlencode users_id_destinatario=10 --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 23:00:00" --data-urlencode "beneficiary_dni=99999999R" --data-urlencode "beneficiary_name=Persona Ficticia" --data-urlencode "beneficiary_email=benef-$RUN@example.test" >/dev/null
check A8a "El historial registra el cambio de nombre" registrado $( [ "$(sqlv "select count(*) from glpi_logs where itemtype='PluginLagapenakLoan' and items_id=$LA and new_value like '%renombrado%'")" -ge 1 ] && echo registrado || echo no )
check A8b "El historial no guarda el DNI" ausente $(yn "$(sqlv "select count(*) from glpi_logs where itemtype='PluginLagapenakLoan' and (new_value like '%99999999R%' or old_value like '%00000000T%')")" 0 ausente guardado)
check A8c "Histórico visible para el supervisor de la entidad" visible $(get d1_supA "$P/front/loan.form.php?id=$LA" | grep -q 'id="history"' && echo visible || echo no)
check A8d "Histórico no visible para el solicitante" oculto $(get d1_u1 "$P/front/loan.form.php?id=$LA" | grep -q 'id="history"' && echo visible || echo oculto)
else
  printf "%-5s | %-62s | %-9s | %-9s | %s\n" "A8" "Historial (A8a–A8d)" "—" "—" "no aplica: A8 no incluido"
fi

echo
echo "Resultado: $PASS OK · $FAIL FALLO · $OPEN abiertos"
