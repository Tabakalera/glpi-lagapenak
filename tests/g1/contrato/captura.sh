#!/bin/bash
# Captura respuestas reales de la API (datos ficticios) para validarlas contra el contrato OpenAPI.
# Se ejecuta dentro del contenedor GLPI tras la batería G1 (necesita sus préstamos). Lo lanza g1.sh contrato.
source /tmp/lib.sh
sqlv() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $r=$c->query($argv[1]); $x=$r===true?null:$r->fetch_row(); echo $x?$x[0]:"";' "$1"; }
API=$P/front/api.php; OUT=/tmp/contrato; mkdir -p $OUT
EA=$(sqlv "select id from glpi_entities where name='D1-A'")
sqlv "update glpi_configs set value='1' where context='plugin:lagapenak' and name='api_enabled'"
sqlv "update glpi_configs set value='0' where context='plugin:lagapenak' and name='api_require_https'"
sqlv "delete from glpi_plugin_lagapenak_apiclients where name like 'T-Contrato%'"
mk() { php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME")); $p=bin2hex(random_bytes(4)); $t="lgk_{$p}_".str_repeat("C",40);
  $c->query("INSERT INTO glpi_plugin_lagapenak_apiclients (name,source,token_prefix,token_hash,scopes,entities,is_recursive,allowed_ips,is_active,expires_at,scope_mod,date_creation,date_mod) VALUES (\"$argv[1]\",\"kide-artean\",\"$p\",\"".hash("sha256",$t)."\",\"$argv[2]\",\"[1]\",1,\"127.0.0.1\",1,NOW()+INTERVAL 1 DAY,NOW(),NOW(),NOW())"); echo $t;' "$@"; }
# Cliente con ámbito personal (detalle completo) y cliente solo operativo
TOK=$(mk T-Contrato-P "loans:read loans:read:personal"); TOKN=$(mk T-Contrato "loans:read")
g() { curl -s -H "Authorization: Bearer $TOK" -H "X-Acting-User: 3f6c1d2e-0000-4000-8000-000000000001" "$API$1" > $OUT/$2.json; }
g /v1/meta meta
g "/v1/loan-events?since=1970-01-01T00:00:00Z&limit=100" events
U1=""; SINCE=1970-01-01T00:00:00Z
for i in $(seq 1 30); do
  g "/v1/loan-events?since=$SINCE&limit=100" page
  U1=$(php -r '$j=json_decode(file_get_contents("/tmp/contrato/page.json"),true); foreach($j["data"] as $e) if($e["event"]==="upsert"){echo $e["loan_uuid"];break;}')
  [ -n "$U1" ] && cp $OUT/page.json $OUT/events_upsert.json && break
  SINCE=$(php -r '$j=json_decode(file_get_contents("/tmp/contrato/page.json"),true); echo $j["next_since"];')
done
U2=$(sqlv "select uuid from glpi_plugin_lagapenak_loans where status=4 and cancel_date is not null order by id desc limit 1")
DEL=$(sqlv "select loan_uuid from glpi_plugin_lagapenak_loanevents where event='deleted' and entities_id_from=$EA order by id desc limit 1")
g "/v1/loans/$U1" loan
SIG=$(sqlv "select uuid from glpi_plugin_lagapenak_loans where entities_id=$EA and signature_data<>'' order by id desc limit 1")
g "/v1/loans/$SIG" loan_signed
TOK_P=$TOK; TOK=$TOKN; g "/v1/loans/$U1" loan_np; g "/v1/meta" meta_np; TOK=$TOK_P
g "/v1/loans?uuids=$U1,$U2,$DEL,00000000-0000-4000-8000-000000000000" batch
g "/v1/loans?number=$(sqlv "select id from glpi_plugin_lagapenak_loans where entities_id=$EA order by id desc limit 1")" number
g "/v1/loans/$DEL" problem410
g "/v1/loans" problem400
sqlv "update glpi_configs set value='0' where context='plugin:lagapenak' and name='api_enabled'"
ls $OUT
