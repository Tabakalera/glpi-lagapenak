#!/bin/bash
# Batería del modelo de permisos de Lagapenak 1.6.1 (perfiles ficticios con nombres nuevos y combinaciones).
# Modelo: READ consultar · CREATE crear · UPDATE gestionar · PURGE+UPDATE borrar ajenos · el solicitante
# retira su solicitud intacta · nunca se borra un firmado · todo dentro del ámbito de entidades.
# Estado: OK = coincide con el modelo · FALLO = acceso indebido o uso legítimo roto.
# Uso: tests/g1/g1.sh bateria <entorno> permisos  (dentro del contenedor: bash /tmp/suite_perm.sh)
source /tmp/lib.sh   # $B = URL base de GLPI, $P = URL del plugin (no reutilizar esos nombres)
RUN=$(date +%H%M%S)
N=$(( $(cat /tmp/suite_n 2>/dev/null || echo 0) + 1 )); echo $N > /tmp/suite_n
D0=$(date -d "2027-01-04 +$((N*14)) days" +%F); D4=$(date -d "$D0 +4 days" +%F)
D5=$(date -d "$D0 +5 days" +%F); D8=$(date -d "$D0 +8 days" +%F)
PASS=0; FAIL=0
DIAG=/tmp/diag-perm-$RUN; mkdir -p $DIAG; echo $DIAG > /tmp/ultimo_diag_perm
sqlv() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $r=$c->query($argv[1]); $x=($r instanceof mysqli_result)?$r->fetch_row():null; echo $x?$x[0]:"";' "$1"; }
sqlw() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $c->query($argv[1]);' "$1"; }  # solo datos ficticios
yn() { [ "$1" = "$2" ] && echo "$3" || echo "$4"; }
row() { printf "%-5s | %-24s | %-60s | %-10s | %-10s | %s\n" "$1" "$2" "$3" "$4" "$5" "$6"; }
check() { if [ "$4" = "$5" ]; then PASS=$((PASS+1)); row "$1" "$2" "$3" "$4" "$5" OK; tick "$1" OK; else FAIL=$((FAIL+1)); row "$1" "$2" "$3" "$4" "$5" FALLO; diag "$1" "$2 · $3" "$4" "$5"; tick "$1" FALLO; fi; }
newloan_at() { # newloan_at <usuario> <entidad> <destinatario> <nombre> <inicio> <fin>
  post $1 $P/front/loan.form.php --data-urlencode save_loan=1 --data-urlencode "name=$4" --data-urlencode entities_id=$2 \
    --data-urlencode users_id_destinatario=$3 --data-urlencode "fecha_inicio=$5 10:00:00" --data-urlencode "fecha_fin=$6 18:00:00"     --data-urlencode "beneficiary_name=Beneficiaria Perm Ficticia" --data-urlencode "beneficiary_email=benef-perm@example.test" >/dev/null   # G1-ID01 y G1-F08: nombre e identificador
  sqlv "select id from glpi_plugin_lagapenak_loans where name='$4' order by id desc limit 1"; }
newloan() { newloan_at "$1" "$2" "$3" "$4" $D0 $D4; }
exists() { sqlv "select count(*) from glpi_plugin_lagapenak_loans where id=$1"; }
opens() { get $1 "$P/front/loan.form.php?id=$2" | grep -q 'name="save_loan"\|Mi solicitud\|My request' && echo abre || echo denegado; }
editable() { get $1 "$P/front/loan.form.php?id=$2" | grep -q 'name="save_loan"' && echo editable || echo denegado; }
newform() { get $1 "$P/front/loan.form.php" | grep -q 'name="save_loan"' && echo abre || echo denegado; }
rename() { post $1 "$P/front/loan.form.php?id=$2" --data-urlencode save_loan=1 --data-urlencode "name=$3" --data-urlencode entities_id=$4 \
    --data-urlencode users_id_destinatario=$5 --data-urlencode "fecha_inicio=$D0 10:00:00" --data-urlencode "fecha_fin=$D4 18:00:00" >/dev/null
  yn "$(sqlv "select name from glpi_plugin_lagapenak_loans where id=$2")" "$3" modificado denegado; }
formdel() { post $1 "$P/front/loan.form.php?id=$2" --data-urlencode delete_loan=1 >/dev/null; yn "$(exists $2)" 0 borrado denegado; }
masspurge() { post $1 "$B/front/massiveaction.php" --data-urlencode action=purge --data-urlencode processor=MassiveAction \
    --data-urlencode "items[PluginLagapenakLoan][$2]=$2" >/dev/null; yn "$(exists $2)" 0 borrado denegado; }
massupdate() { # massupdate <usuario> <itemtype> <id> <opción de búsqueda> <campo> <valor>
  post $1 "$B/front/massiveaction.php" --data-urlencode action=update --data-urlencode processor=MassiveAction \
    --data-urlencode "items[$2][$3]=$3" --data-urlencode "search_options[$2]=$4" --data-urlencode "field=$5" --data-urlencode "$5=$6" >/dev/null; }
loanval() { sqlv "select ifnull($2,'') from glpi_plugin_lagapenak_loans where id=$1"; }
additem() { post $1 "$P/front/loan.form.php?id=$2" --data-urlencode add_loanitem=1 --data-urlencode loans_id=$2 --data-urlencode itemtype=Computer --data-urlencode items_id=$3 >/dev/null
  yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where loans_id=$2 and itemtype='Computer' and items_id=$3")" 1 añadido denegado; }
sign() { post $1 "$P/front/albaran.php?id=$2" --data-urlencode save_signature=1 --data-urlencode "signature_data@/tmp/sig.txt" --data-urlencode "signature_name=Firma $RUN" >/dev/null; }
ics_session() { get $1 "$P/front/loan.ics.php"; }
ics_token() { curl -s "$P/front/loan.ics.php?token=$1"; }
ics_code() { curl -s -o /dev/null -w '%{http_code}' "$P/front/loan.ics.php?token=$1"; }
has() { grep -q "$2" <<< "$1" && echo "$3" || echo "$4"; }
tokenof() { sqlv "select ifnull(max(token),'') from glpi_plugin_lagapenak_icstokens where users_id=$(sqlv "select id from glpi_users where name='$1'")"; }
rights() { sqlw "update glpi_profilerights set rights=$2 where name='plugin_lagapenak_loan' and profiles_id=(select id from glpi_profiles where name='$1')"; }

for u in d1_u1 d1_supA d1_supB d1_sinderecho d1_nuevo d1_lector d1_upd d1_purga d1_suppurga d1_multi; do login $u >/dev/null; done
SUPA=$(sqlv "select id from glpi_users where name='d1_supA'"); SUPB=$(sqlv "select id from glpi_users where name='d1_supB'")
EA=$(sqlv "select id from glpi_entities where name='D1-A'"); EB=$(sqlv "select id from glpi_entities where name='D1-B'")
CB2=$(sqlv "select id from glpi_computers where name='D1-PORTATIL-B2'"); CBB=$(sqlv "select id from glpi_computers where name='D1-PORTATIL-B'"); CA=$(sqlv "select id from glpi_computers where name='D1-PORTATIL-A'")
U2=$(sqlv "select id from glpi_users where name='d1_u2'")
echo "Lagapenak $(grep -o "VERSION', '[0-9a-z.-]*" /var/www/html/glpi/plugins/lagapenak/setup.php | sed "s/.*'//") · GLPI $(sqlv "select value from glpi_configs where context='core' and name='version'") · permisos · ronda $RUN"
row id perfil prueba esperado obtenido estado

# ── Préstamos de partida (usuarios autorizados) ──────────────────────────────────────────────
L1=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A pendiente")
L2=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A para purga")
L3=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A firmado"); sign d1_u1 $L3
L4=$(newloan d1_supB $EB $SUPB "PM$RUN supB B")
L5=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A para borrar")
L6=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A para purga M7")
L7=$(newloan d1_u1 $EA $SUPA "PM$RUN u1 A purga legítima")
check P0 "(preparación)" "Préstamos de partida creados y uno firmado" listos $( [ -n "$L1$L2$L4$L5$L6$L7" ] && [ "$(loanval $L3 "signature_data<>''")" = 1 ] && echo listos || echo faltan )

# ── Perfil nuevo READ + CREATE ───────────────────────────────────────────────────────────────
PN="Nuevo (READ+CREATE)"
check N1 "$PN" "Abre el formulario de alta" abre $(newform d1_nuevo)
LN=$(newloan d1_nuevo $EA $SUPA "PM$RUN nuevo A")
check N2 "$PN" "Crea un préstamo en su ámbito" creado $( [ -n "$LN" ] && echo creado || echo denegado )
check N3 "$PN" "Consulta su préstamo" abre $(opens d1_nuevo ${LN:-0})
check N4 "$PN" "Añade material prestable a su préstamo" añadido $(additem d1_nuevo ${LN:-0} $CB2)
check N5 "$PN" "Consulta un préstamo ajeno" denegado $(opens d1_nuevo $L1)
check N6 "$PN" "Modifica su préstamo (sin UPDATE)" denegado $(rename d1_nuevo ${LN:-0} "PM$RUN cambiado" $EA $SUPA)
check N7 "$PN" "Borra un préstamo ajeno" denegado $(formdel d1_nuevo $L5)

# ── Retirada de la propia solicitud (sin PURGE) ──────────────────────────────────────────────
O1=$(newloan d1_nuevo $EA $SUPA "PM$RUN O1 intacta")
check O1 "$PN" "Retira su solicitud pendiente e intacta" borrado $(formdel d1_nuevo ${O1:-0})
O2=$(newloan_at d1_nuevo $EA $SUPA "PM$RUN O2 entregada" $D5 $D8); additem d1_nuevo ${O2:-0} $CA >/dev/null
X2=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=${O2:-0} limit 1")
post d1_supA "$P/front/loan.form.php?id=$O2" --data-urlencode update_loanitem=1 --data-urlencode loanitem_id=$X2 --data-urlencode item_status=2 --data-urlencode "item_date_checkout=" --data-urlencode "item_date_checkin=" >/dev/null
check O2 "$PN" "Retira una solicitud con material ya entregado" denegado $(formdel d1_nuevo ${O2:-0})
O3=$(newloan d1_nuevo $EA $SUPA "PM$RUN O3 enlace emitido")
post d1_supA "$P/front/albaran.php?id=$O3" --data-urlencode action_gen_token=1 --data-urlencode show_link=1 >/dev/null
check O3 "$PN" "Retira una solicitud con enlace de firma emitido" denegado $(formdel d1_nuevo ${O3:-0})
O4=$(newloan d1_nuevo $EA $SUPA "PM$RUN O4 prórroga")
post d1_nuevo "$P/front/loan.form.php?id=$O4" --data-urlencode save_date_request=1 --data-urlencode "requested_date_end=$D4 20:00:00" >/dev/null
check O4 "$PN" "Retira una solicitud con prórroga pedida" denegado $(formdel d1_nuevo ${O4:-0})
O5=$(newloan d1_nuevo $EA $SUPA "PM$RUN O5 comentario supervisor")
post d1_supA "$P/front/loan.form.php?id=$O5" --data-urlencode add_comment=1 --data-urlencode "comment_text=en trámite" >/dev/null
check O5 "$PN" "Retira una solicitud que el supervisor ya comentó" denegado $(formdel d1_nuevo ${O5:-0})
O6=$(newloan d1_nuevo $EA $SUPA "PM$RUN O6 comentario propio")
post d1_nuevo "$P/front/loan.form.php?id=$O6" --data-urlencode add_comment=1 --data-urlencode "comment_text=nota mía" >/dev/null
check O6 "$PN" "Retira una solicitud con solo comentarios propios" borrado $(formdel d1_nuevo ${O6:-0})
O7=$(newloan d1_nuevo $EA $SUPA "PM$RUN O7 firmada"); sign d1_nuevo ${O7:-0}
check O7 "$PN" "Retira su solicitud firmada" denegado $(formdel d1_nuevo ${O7:-0})
O8=$(newloan d1_nuevo $EA $SUPA "PM$RUN O8 sin CREATE")
rights "Perfil Nuevo Préstamos Q4" 1
check O8 "$PN" "Retira su solicitud tras perder CREATE" denegado $(formdel d1_nuevo ${O8:-0})
rights "Perfil Nuevo Préstamos Q4" 5
O9=$(newloan d1_nuevo $EA $SUPA "PM$RUN O9 masiva propia")
check O9 "$PN" "Retira su solicitud con la acción masiva (sin PURGE)" denegado $(masspurge d1_nuevo ${O9:-0})

# ── Solo READ / sin derechos / E5 ────────────────────────────────────────────────────────────
PL="Solo READ"
check L1 "$PL" "Abre el formulario de alta" denegado $(newform d1_lector)
LL=$(newloan d1_lector $EA $SUPA "PM$RUN lector")
check L2 "$PL" "Crea un préstamo (envío directo)" denegado $( [ -z "$LL" ] && echo denegado || echo creado )
check L3 "$PL" "Abre la lista de préstamos" abre $(get d1_lector "$P/front/loan.php" | grep -q "Acceso denegado\|Access denied" && echo denegado || echo abre)
check L4 "$PL" "Consulta un préstamo ajeno" denegado $(opens d1_lector $L1)
check L5 "$PL" "Añade material a un préstamo ajeno" denegado $(additem d1_lector $L1 $CA)
PS="Sin derechos"
check S1 "$PS" "Abre el formulario de alta" denegado $(newform d1_sinderecho)
LS=$(newloan d1_sinderecho $EA $SUPA "PM$RUN sin derechos")
check S2 "$PS" "Crea un préstamo (envío directo)" denegado $( [ -z "$LS" ] && echo denegado || echo creado )
check S3 "$PS" "Consulta un préstamo ajeno" denegado $(opens d1_sinderecho $L1)
check S4 "$PS" "Abre la lista de préstamos (E5)" denegado $(get d1_sinderecho "$P/front/loan.php" | grep -q "Acceso denegado\|Access denied" && echo denegado || echo abre)
OWN7=$(sqlv "select min(id) from glpi_plugin_lagapenak_loans where users_id=(select id from glpi_users where name='d1_sinderecho')")
check S5 "$PS" "Consulta su propio préstamo antiguo sin READ (E5)" denegado $( [ -z "$OWN7" ] && echo denegado || opens d1_sinderecho $OWN7)
check S6 "$PS" "Calendario y disponibilidad por AJAX sin READ (E5)" denegado $(get d1_sinderecho "$P/ajax/calendar_events.php?start=$D0&end=$D8" | grep -q "Forbidden" && echo denegado || echo abre)
rights "Perfil Nuevo Préstamos Q4" 4
check E5a "$PN" "Sin READ (solo CREATE): consulta su préstamo" denegado $(opens d1_nuevo ${LN:-0})
rights "Perfil Nuevo Préstamos Q4" 5
check E5b "$PN" "Con READ de nuevo: consulta su préstamo" abre $(opens d1_nuevo ${LN:-0})

# ── UPDATE sin CREATE ni PURGE (solo A) ─────────────────────────────────────────────────────
PU="UPDATE sin CREATE (A)"
check U1 "$PU" "Abre el formulario de alta" denegado $(newform d1_upd)
LU=$(newloan d1_upd $EA $SUPA "PM$RUN upd")
check U2 "$PU" "Crea un préstamo (envío directo)" denegado $( [ -z "$LU" ] && echo denegado || echo creado )
check U3 "$PU" "Abre un préstamo de A para gestionarlo" editable $(editable d1_upd $L1)
check U4 "$PU" "Modifica un préstamo de A" modificado $(rename d1_upd $L1 "PM$RUN u1 A modificado por upd" $EA $SUPA)
check U5 "$PU" "Añade material a un préstamo de A" añadido $(additem d1_upd $L1 $CA)
check U6 "$PU" "Abre un préstamo de B" denegado $(opens d1_upd $L4)
check U7 "$PU" "Modifica un préstamo de B" denegado $(rename d1_upd $L4 "PM$RUN B intrusión" $EB $SUPB)
check U8 "$PU" "Borra un préstamo ajeno de A sin PURGE (ficha)" denegado $(formdel d1_upd $L5)
check U9 "$PU" "Borra un préstamo ajeno de A sin PURGE (masiva)" denegado $(masspurge d1_upd $L5)
check U10 "$PU" "Borra un préstamo firmado de A" denegado $(formdel d1_upd $L3)

# ── PURGE sin UPDATE (raíz) ──────────────────────────────────────────────────────────────────
PG="PURGE sin UPDATE"
check G1 "$PG" "Consulta un préstamo ajeno" denegado $(opens d1_purga $L1)
check G2 "$PG" "Borra un préstamo ajeno desde la ficha" denegado $(formdel d1_purga $L2)
check G3 "$PG" "Purga masiva de un préstamo firmado" denegado $(masspurge d1_purga $L3)
check G4 "$PG" "Purga masiva de un préstamo ajeno" denegado $(masspurge d1_purga $L2)

# ── Todos los derechos, solo en A ────────────────────────────────────────────────────────────
PC="Completo (A)"
LC=$(newloan d1_suppurga $EA $SUPA "PM$RUN completo A")
check C1 "$PC" "Crea un préstamo en A" creado $( [ -n "$LC" ] && echo creado || echo denegado )
check C2 "$PC" "Borra un préstamo ajeno sin firmar de A (ficha)" borrado $(formdel d1_suppurga $L5)
check C3 "$PC" "Borra un préstamo firmado de A (ficha)" denegado $(formdel d1_suppurga $L3)
check C4 "$PC" "Purga masiva de un préstamo firmado de A" denegado $(masspurge d1_suppurga $L3)
check C5 "$PC" "Purga masiva de un préstamo de B" denegado $(masspurge d1_suppurga $L4)
check C6 "$PC" "Purga masiva de un préstamo ajeno sin firmar de A" borrado $(masspurge d1_suppurga $L7)

# ── Modificación masiva (Completo en A, con UPDATE) ──────────────────────────────────────────
massupdate d1_suppurga PluginLagapenakLoan $L1 5 users_id_destinatario $U2
check MU1 "$PC" "Masiva: destinatario que no es supervisor" denegado $(yn "$(loanval $L1 users_id_destinatario)" "$SUPA" denegado cambiado)
massupdate d1_suppurga PluginLagapenakLoan $L1 14 entities_id $EB
check MU2 "$PC" "Masiva: mover el préstamo a la entidad B" denegado $(yn "$(loanval $L1 entities_id)" "$EA" denegado cambiado)
massupdate d1_suppurga PluginLagapenakLoan $L1 7 fecha_fin "$D8 18:00:00"
check MU3 "$PC" "Masiva: cambiar la fecha de fin" denegado $(loanval $L1 fecha_fin | grep -q "$D4" && echo denegado || echo cambiado)
massupdate d1_suppurga PluginLagapenakLoan $L1 24 beneficiary_email "otro@example.invalid"
check MU4 "$PC" "Masiva: cambiar el correo del beneficiario" denegado $(yn "$(loanval $L1 beneficiary_email)" "otro@example.invalid" cambiado denegado)
massupdate d1_suppurga PluginLagapenakLoan $L3 10 field_2 "Proyecto cambiado $RUN"
check MU5 "$PC" "Masiva: Proyecto de un préstamo firmado" denegado $(loanval $L3 field_2 | grep -q "cambiado" && echo cambiado || echo denegado)
massupdate d1_suppurga PluginLagapenakLoan $L1 8 observaciones "Nota masiva $RUN"
check MU6 "$PC" "Masiva: observaciones de un préstamo de A" cambiado $(loanval $L1 observaciones | grep -q "Nota masiva $RUN" && echo cambiado || echo denegado)
massupdate d1_suppurga PluginLagapenakLoan $L4 11 field_3 "Intrusión $RUN"
check MU7 "$PC" "Masiva: campo de un préstamo de B" denegado $(loanval $L4 field_3 | grep -q "Intrusión" && echo cambiado || echo denegado)
XL1=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$L1 limit 1")
massupdate d1_suppurga PluginLagapenakLoanItem ${XL1:-0} 3 status 2
check MU8p "(preparación)" "El préstamo de A tiene una línea de material" "línea" $( [ -n "$XL1" ] && echo línea || echo falta )
check MU8 "$PC" "Masiva: estado de una línea de material" denegado $(yn "$(sqlv "select status from glpi_plugin_lagapenak_loanitems where id=${XL1:-0}")" 1 denegado cambiado)
XL4=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$L4 limit 1")
[ -z "$XL4" ] && { additem d1_supB $L4 $CBB >/dev/null; XL4=$(sqlv "select id from glpi_plugin_lagapenak_loanitems where loans_id=$L4 limit 1"); }
check MU9p "(preparación)" "El préstamo de B tiene una línea de material" "línea" $( [ -n "$XL4" ] && echo línea || echo falta )
post d1_suppurga "$B/front/massiveaction.php" --data-urlencode action=purge --data-urlencode processor=MassiveAction --data-urlencode "items[PluginLagapenakLoanItem][${XL4:-0}]=${XL4:-0}" >/dev/null
check MU9 "$PC" "Masiva: purgar una línea de un préstamo de B" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where id=${XL4:-0}")" 1 denegado borrada)
post d1_purga "$B/front/massiveaction.php" --data-urlencode action=purge --data-urlencode processor=MassiveAction --data-urlencode "items[PluginLagapenakLoanItem][${XL1:-0}]=${XL1:-0}" >/dev/null
check MU10 "$PG" "Masiva: purgar una línea de un préstamo ajeno" denegado $(yn "$(sqlv "select count(*) from glpi_plugin_lagapenak_loanitems where id=${XL1:-0}")" 1 denegado borrada)

# ── Calendario iCal ──────────────────────────────────────────────────────────────────────────
F=$(ics_session d1_suppurga)
check I1 "$PC" "iCal (sesión): incluye un préstamo de B" denegado $(has "$F" "lagapenak-loan-$L4@" incluido denegado)
check I2 "$PC" "iCal (sesión): incluye un préstamo ajeno de A" incluido $(has "$F" "lagapenak-loan-$L1@" incluido denegado)
check I3 "$PC" "iCal: los eventos no llevan el nombre del préstamo" ausente $(has "$F" "PM$RUN" presente ausente)
F=$(ics_session d1_nuevo)
check I4 "$PN" "iCal (sesión): su préstamo sí, el ajeno no" propio $( grep -q "lagapenak-loan-${LN:-0}@" <<< "$F" && ! grep -q "lagapenak-loan-$L1@" <<< "$F" && echo propio || echo otro )
check I5 "$PS" "iCal (sesión) sin READ" denegado $(get d1_sinderecho "$P/front/loan.ics.php" | grep -q "BEGIN:VCALENDAR" && echo abre || echo denegado)
post d1_suppurga "$P/front/calendar.php" --data-urlencode regenerate_ics=1 >/dev/null; T1=$(tokenof d1_suppurga)
F=$(ics_token $T1)
check I6 "$PC" "iCal (token personal): préstamo ajeno de A sí, de B no" correcto $( grep -q "lagapenak-loan-$L1@" <<< "$F" && ! grep -q "lagapenak-loan-$L4@" <<< "$F" && echo correcto || echo incorrecto )
post d1_suppurga "$P/front/calendar.php" --data-urlencode regenerate_ics=1 >/dev/null; T2=$(tokenof d1_suppurga)
check I7 "$PC" "iCal: el token anterior deja de valer al regenerar" 403 $(ics_code $T1)
check I8 "$PC" "iCal: el token nuevo funciona" 200 $(ics_code $T2)
rights "Supervisión completa" $((1|4|16))
F=$(ics_token $T2)
check I9 "$PC" "iCal (token) tras quitarle UPDATE: ya no ve lo ajeno de A" denegado $(has "$F" "lagapenak-loan-$L1@" incluido denegado)
rights "Supervisión completa" $((1|2|4|16))
sqlw "update glpi_users set is_active=0 where name='d1_suppurga'"
check I10 "$PC" "iCal (token) con el usuario desactivado" 403 $(ics_code $T2)
sqlw "update glpi_users set is_active=1 where name='d1_suppurga'"
post d1_suppurga "$P/front/calendar.php" --data-urlencode revoke_ics=1 >/dev/null
check I11 "$PC" "iCal: token revocado" 403 $(ics_code $T2)
check I12 "$PC" "Tras revocar no se crea un enlace nuevo al abrir la página" ninguno $(get d1_suppurga "$P/front/calendar.php" >/dev/null; yn "$(tokenof d1_suppurga)" "" ninguno creado)
SINU=$(sqlv "select id from glpi_users where name='d1_sinderecho'"); TS=$(php -r 'echo bin2hex(random_bytes(20));')
sqlw "delete from glpi_plugin_lagapenak_icstokens where users_id=$SINU"; sqlw "insert into glpi_plugin_lagapenak_icstokens (users_id, token, date_creation) values ($SINU, '$TS', now())"
check I13 "$PS" "iCal (token) de un usuario sin READ" 403 $(ics_code $TS)
TG=$(php -r 'echo bin2hex(random_bytes(20));')
sqlw "delete from glpi_configs where context='plugin:lagapenak' and name='ics_token'"; sqlw "insert into glpi_configs (context, name, value) values ('plugin:lagapenak', 'ics_token', '$TG')"
F=$(ics_token $TG)
check I14 "(token general 1.6.0)" "iCal con el token general retirado: aviso y ningún préstamo" aviso $( grep -q "ya no funciona" <<< "$F" && ! grep -q "lagapenak-loan-" <<< "$F" && echo aviso || echo datos )
check I15 "(token inventado)" "iCal con un token que no existe" 403 $(ics_code $(php -r 'echo bin2hex(random_bytes(20));'))

# ── Usuario con dos habilitaciones: «Supervisión completa» en A y «Sin derechos» en B ───────
PM="Dos perfiles"
check M1 "$PM" "Con el perfil completo: gestiona un préstamo de A" editable $(editable d1_multi $L1)
check M2 "$PM" "Con el perfil completo: abre un préstamo de B" denegado $(opens d1_multi $L4)
get d1_multi "$B/front/central.php?active_entity=$EB&force_entity=$EB" >/dev/null
check M3 "$PM" "Pide como entidad activa B: préstamo de B" denegado $(opens d1_multi $L4)
SIN=$(sqlv "select id from glpi_profiles where name='D1 Sin Lagapenak'")
get d1_multi "$B/front/central.php?newprofile=$SIN&force_profile=$SIN" >/dev/null
prof_of() { local f=/var/www/html/glpi/files/_sessions/sess_$(awk '$6 ~ /^glpi_/ {print $7}' /tmp/cj_$1 | tail -1); grep -o 'glpiactiveprofile|a:[0-9]*:{s:2:"id";i:[0-9]*' $f 2>/dev/null | grep -o '[0-9]*$'; }
check M4a "$PM" "La sesión tiene ahora el perfil sin derechos" "perfil-$SIN" "perfil-$(prof_of d1_multi)"
check M4 "$PM" "Cambia al perfil sin derechos: préstamo de A" denegado $(opens d1_multi $L1)
LM=$(newloan d1_multi $EB $SUPB "PM$RUN multi sin derechos")
check M5 "$PM" "Con el perfil sin derechos: crea un préstamo" denegado $( [ -z "$LM" ] && echo denegado || echo creado )
check M6 "$PM" "Con el perfil sin derechos: modifica un préstamo de A" denegado $(rename d1_multi $L1 "PM$RUN multi intrusión" $EA $SUPA)
check M7 "$PM" "Con el perfil sin derechos: purga masiva de A" denegado $(masspurge d1_multi $L6)
check M8 "$PM" "Con el perfil sin derechos: iCal por sesión" denegado $(get d1_multi "$P/front/loan.ics.php" | grep -q "BEGIN:VCALENDAR" && echo abre || echo denegado)

echo
echo "Resultado: $PASS OK · $FAIL FALLO"
