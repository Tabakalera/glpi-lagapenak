#!/bin/bash
# Pruebas G1 de Lagapenak en entornos Docker aislados, solo con datos ficticios. Instrucciones: README.md.
#
#   g1.sh crear <entorno> <versión GLPI>      crea el entorno, instala GLPI y el plugin (copia de trabajo) y los datos ficticios
#   g1.sh desplegar <entorno> [rama]          vuelve a copiar el plugin (copia de trabajo, o la rama/commit indicado)
#   g1.sh bateria <entorno> [g1|permisos|general|todas]
#   g1.sh contrato <entorno>                  respuestas reales de la API validadas contra docs/api/openapi-g1.yaml
#   g1.sh vectores [entorno]                  vectores HMAC: referencia Python; con entorno, también PHP y el JS del formulario
#   g1.sh comprobar <entorno>                 todas las baterías + contrato + vectores
#   g1.sh ensayo <entorno> [rama 1.6.1]       actualización desde 1.6.1, desinstalación, reinstalación y reversión
#   g1.sh parar <entorno> | eliminar <entorno> | lista
#
# Resultados (salidas completas y diagnóstico de cada fallo): tests/g1/resultados/<entorno>-<fecha>/, fuera de Git.
set -uo pipefail
export MSYS_NO_PATHCONV=1   # Git Bash en Windows: no traducir las rutas de dentro de los contenedores

here() { cd "$1" && { pwd -W 2>/dev/null || pwd; }; }
DIR=$(here "$(dirname "$0")")
REPO=$(here "$DIR/../..")
LOCAL=$DIR/.local
GLPI=/var/www/html/glpi
PLUGIN=$GLPI/plugins/lagapenak

die() { echo "g1.sh: $*" >&2; exit 2; }
proyecto() { echo "lagapenak-g1-$E"; }
C() { echo "$(proyecto)-glpi-1"; }
DB() { echo "$(proyecto)-db-1"; }
ENVF() { echo "$LOCAL/$E.env"; }
compose() { docker compose -p "$(proyecto)" --env-file "$(ENVF)" -f "$DIR/entorno/compose.yaml" "$@"; }
console() { docker exec -u www-data -w $GLPI "$(C)" php bin/console "$@" --no-interaction 2>&1 | grep -v '^\s*$' | tail -1; }
# Consulta en la base del entorno con el usuario de la aplicación (las credenciales están en el contenedor)
q() { docker exec "$(DB)" sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "$1"' sh "$1" 2>/dev/null; }
salida() { RES=$DIR/resultados/$E-$(date +%Y%m%d-%H%M%S); mkdir -p "$RES"; echo "resultados: $RES"; }

entorno() { # entorno <nombre>: valida el nombre y que el entorno exista
  E=${1:-}; [[ $E =~ ^[a-z0-9]+$ ]] || die "nombre de entorno no válido: «$E» (solo minúsculas y cifras)"
  [ "${2:-}" = nuevo ] || [ -f "$(ENVF)" ] || die "el entorno «$E» no existe: créalo con g1.sh crear $E <versión GLPI>"
}

# paquete <rama|-> → tar del plugin por la salida estándar. «-» = copia de trabajo (también lo no confirmado).
# Nunca incluye tests/ (en las ramas, por export-ignore de .gitattributes).
paquete() {
  if [ "$1" = - ]; then
    (cd "$REPO" && git ls-files -co --exclude-standard | grep -v '^tests/' | while read -r f; do [ -e "$f" ] && echo "$f"; done | tar -cf - -T -)
  else
    git -C "$REPO" archive --format=tar "$1"
  fi
}

poner_plugin() { # poner_plugin <rama|-> : sustituye la carpeta del plugin (la anterior queda en /tmp del contenedor)
  local c; c=$(C); local t=/tmp/plugin-anterior-$(date +%H%M%S)
  docker exec "$c" sh -c "[ -d $PLUGIN ] && mv $PLUGIN $t; mkdir -p $PLUGIN"
  paquete "$1" | docker exec -i "$c" tar -xf - -C $PLUGIN || die "no se pudo copiar el plugin ($1)"
  docker exec "$c" sh -c "cd $PLUGIN && for f in \$(find . -name '*.php'); do php -l \$f >/dev/null || echo \"  error de sintaxis: \$f\"; done"
  docker exec "$c" chown -R www-data:www-data $PLUGIN
  docker exec "$c" apachectl -k graceful 2>/dev/null; sleep 2
}

instalar_plugin() {
  echo "  plugin: $(console plugin:install lagapenak -u glpi --force) · $(console plugin:activate lagapenak) · $(console cache:clear)"
  echo "  Lagapenak $(q "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'")"
}

copiar_apoyos() { # baterías, biblioteca, sonda y datos ficticios en /tmp del contenedor
  local c; c=$(C)
  for f in suites/lib.sh suites/suite_g1.sh suites/suite_perm.sh suites/suite.sh suites/prueba_g1c.php \
           fixtures/fixtures_sql.php fixtures/fixtures_perm.php contrato/captura.sh vectores/php_vectores.php; do
    docker cp "$DIR/$f" "$c:/tmp/$(basename $f)" >/dev/null || die "no se pudo copiar $f"
  done
  docker cp "$REPO/docs/api/g1-hmac-vectors.json" "$c:/tmp/g1-hmac-vectors.json" >/dev/null
  docker exec "$c" sh -c 'chmod 644 /tmp/*.php /tmp/*.sh /tmp/*.json'
  # Firma ficticia (un trazo) para las pruebas del albarán
  docker exec "$c" php -r '$i=imagecreatetruecolor(240,60); imagefill($i,0,0,imagecolorallocate($i,255,255,255));
    imagesetthickness($i,3); imageline($i,10,45,230,15,imagecolorallocate($i,0,0,0)); ob_start(); imagepng($i);
    file_put_contents("/tmp/sig.txt","data:image/png;base64,".base64_encode(ob_get_clean()));'
}

# Cuentas de GLPI tras instalar: «glpi» con la contraseña aleatoria del entorno; las demás cuentas por defecto,
# desactivadas
cuentas() {
  docker exec "$(C)" php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME"));
    $h=$c->real_escape_string(password_hash(getenv("G1_ADMIN_PASSWORD"),PASSWORD_DEFAULT));
    $c->query("update glpi_users set password=\"$h\" where name=\"glpi\"");
    $c->query("update glpi_users set is_active=0 where name in (\"tech\",\"normal\",\"post-only\")");'
}

crear() {
  local v=${1:-}; [[ $v =~ ^1[01]\.[0-9]+\.[0-9]+$ ]] || die "indica la versión de GLPI, p. ej. 10.0.24 o 11.0.9"
  mkdir -p "$LOCAL"
  if [ ! -f "$(ENVF)" ]; then
    rnd() { openssl rand -hex 16 2>/dev/null || od -An -N16 -tx1 /dev/urandom | tr -d ' \n'; }
    local df=Dockerfile.glpi10; [[ $v == 11.* ]] && df=Dockerfile.glpi11
    umask 077
    printf '%s\n' "# Entorno de pruebas G1 «$E» (generado por g1.sh; no se versiona)" "G1_GLPI_VERSION=$v" "G1_DOCKERFILE=$df" \
      "G1_DB_NAME=glpi_g1" "G1_DB_USER=glpi_g1" "G1_DB_PASSWORD=$(rnd)" "G1_DB_ROOT_PASSWORD=$(rnd)" \
      "G1_USER_PASSWORD=Ficticio-$(rnd)" "G1_ADMIN_PASSWORD=Ficticio-$(rnd)" > "$(ENVF)"
  fi
  grep -q "^G1_GLPI_VERSION=$v$" "$(ENVF)" || die "el entorno «$E» ya existe con otra versión de GLPI"
  if ! grep -q '^G1_SUBNET=' "$(ENVF)"; then   # una subred /24 que no use ninguna red de Docker
    local used n; used=$(docker network inspect $(docker network ls -q) --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null)
    used+=" $(cat "$LOCAL"/*.env 2>/dev/null | grep '^G1_SUBNET=' | cut -d= -f2)"
    for n in $(seq 200 254) x; do [ $n = x ] && die "no hay subred libre en 10.250.200-254.0/24"; grep -q "10\.250\.$n\.0/24" <<< "$used" || break; done
    echo "G1_SUBNET=10.250.$n.0/24" >> "$(ENVF)"
  fi
  echo "== $E: GLPI $v (proyecto Docker $(proyecto))"
  compose up -d --build --quiet-pull 2>&1 | grep -iv 'pulling\|pulled\|waiting\|downloading\|extracting' | tail -3
  for i in $(seq 1 60); do q "select 1" >/dev/null && break; sleep 2; done
  q "select 1" >/dev/null || die "la base de datos no responde"
  local c; c=$(C)
  if ! docker exec "$c" test -f $GLPI/config/config_db.php; then
    docker exec "$c" chown -R www-data:www-data $GLPI/config $GLPI/files
    docker exec -u www-data -w $GLPI "$c" sh -c 'php bin/console db:install --db-host=db --db-name="$G1_DB_NAME" --db-user="$G1_DB_USER" \
      --db-password="$G1_DB_PASSWORD" --default-language=es_ES --no-interaction --force --reconfigure' 2>&1 | grep -v '^\s*$' | tail -1
    docker exec "$c" rm -f $GLPI/install/install.php
    cuentas
  fi
  echo "  GLPI $(q "select value from glpi_configs where context='core' and name='version'")"
  poner_plugin -
  instalar_plugin
  copiar_apoyos
  if [ -z "$(q "select id from glpi_entities where name='D1-A'")" ]; then
    docker exec "$c" php /tmp/fixtures_sql.php >/dev/null || die "fallaron los datos ficticios"
    docker exec "$c" php /tmp/fixtures_perm.php >/dev/null || die "fallaron los datos ficticios de permisos"
    console cache:clear >/dev/null
  fi
  echo "  datos ficticios: $(q "select concat(count(*),' usuarios') from glpi_users where name like 'd1\\_%'") · $(q "select concat(count(*),' equipos') from glpi_computers where name like 'D1-%'")"
}

# bateria_una <clave g1|perm|general> <fichero> [nombre] → salida completa en $RES/<nombre>.txt y, en
# $RES/diag-<nombre>/, el diagnóstico de cada fallo y los tiempos de cada prueba (la batería anota su carpeta
# en /tmp/ultimo_diag_<clave>)
bateria_una() {
  local k=$1 f=$2 n=${3:-$1} c; c=$(C)
  echo "== batería $n ($f)"
  docker exec "$c" bash /tmp/$f > "$RES/$n.txt" 2>&1
  grep "^Lagapenak\|^Resultado" "$RES/$n.txt"; grep "| FALLO" "$RES/$n.txt" | cut -c1-250
  local d; d=$(docker exec "$c" cat /tmp/ultimo_diag_$k 2>/dev/null)
  [ -n "$d" ] && docker cp "$c:$d" "$RES/diag-$n" >/dev/null
  [ -f "$RES/diag-$n/avisos.txt" ] && echo "  avisos (lecturas lentas): $RES/diag-$n/avisos.txt"
  ! grep -q "| FALLO" "$RES/$n.txt" && grep -q "^Resultado" "$RES/$n.txt"
}

bateria() {
  local cual=${1:-todas} r=0
  copiar_apoyos
  [ -n "${RES:-}" ] || salida
  case $cual in
    g1)       bateria_una g1 suite_g1.sh || r=1 ;;
    permisos) bateria_una perm suite_perm.sh || r=1 ;;
    general)  bateria_una general suite.sh || r=1 ;;
    todas)    bateria_una g1 suite_g1.sh || r=1; bateria_una perm suite_perm.sh || r=1; bateria_una general suite.sh || r=1 ;;
    *) die "batería desconocida: $cual (g1, permisos, general o todas)" ;;
  esac
  return $r
}

contrato() {
  local c; c=$(C); [ -n "${RES:-}" ] || salida
  copiar_apoyos
  [ -n "$(q "select id from glpi_plugin_lagapenak_apiclients limit 1")" ] || die "ejecuta antes la batería g1 (crea los datos que se capturan)"
  echo "== contrato: respuestas reales contra docs/api/openapi-g1.yaml"
  docker exec "$c" sh -c 'rm -rf /tmp/contrato' && docker exec "$c" bash /tmp/captura.sh >/dev/null 2>&1
  docker cp "$c:/tmp/contrato" "$RES/contrato" >/dev/null || die "no se capturaron respuestas"
  PYTHONIOENCODING=utf-8 python "$DIR/contrato/validar_contrato.py" "$REPO/docs/api/openapi-g1.yaml" "$RES/contrato" | tee "$RES/contrato.txt"
  return "${PIPESTATUS[0]}"
}

vectores() {
  local r=0 V=$REPO/docs/api/g1-hmac-vectors.json
  echo "== vectores HMAC ($V)"
  PYTHONIOENCODING=utf-8 python "$DIR/vectores/referencia.py" --comprobar "$V" || r=1
  if [ -n "${E:-}" ]; then
    local c; c=$(C); [ -n "${RES:-}" ] || salida
    copiar_apoyos
    docker exec "$c" php /tmp/php_vectores.php $PLUGIN/inc/beneficiary.class.php /tmp/g1-hmac-vectors.json || r=1
    docker exec "$c" bash -c 'source /tmp/lib.sh; login d1_supA >/dev/null; get d1_supA "$P/front/loan.form.php" > /tmp/formulario.html'
    docker cp "$c:/tmp/formulario.html" "$RES/formulario.html" >/dev/null
    node "$DIR/vectores/js_vectores.js" "$RES/formulario.html" "$V" || r=1
  fi
  return $r
}

ensayo() {
  local ref161=${1:-release/1.6.1-seguridad} c db; c=$(C); db=$(DB)
  local v161 vg1
  v161=$(git -C "$REPO" show "$ref161:setup.php" | grep -o "VERSION', '[0-9a-z.-]*" | sed "s/.*'//")
  vg1=$(grep -o "VERSION', '[0-9a-z.-]*" "$REPO/setup.php" | sed "s/.*'//")
  [ -n "$v161" ] && [ -n "$vg1" ] || die "no se pudieron leer las versiones"
  local DUMP=/tmp/copia-previa-$(date +%H%M%S).sql problemas=0
  st() { q "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'"; }
  datos() { q "select concat('prestamos=',(select count(*) from glpi_plugin_lagapenak_loans),
    ' firmados=',(select count(*) from glpi_plugin_lagapenak_loans where signature_data<>''),
    ' lineas=',(select count(*) from glpi_plugin_lagapenak_loanitems),
    ' comentarios=',(select count(*) from glpi_plugin_lagapenak_loancomments),
    ' huella=',ifnull((select left(sha2(group_concat(concat(id,':',name,':',status,':',entities_id,':',ifnull(fecha_fin,''),':',sha2(ifnull(signature_data,''),256),signature_name,ifnull(signature_date,'')) order by id),256),12) from glpi_plugin_lagapenak_loans),'-'),
    ' beneficiarios=',ifnull((select left(sha2(group_concat(concat(id,':',beneficiary_name,'|',beneficiary_email,'|',beneficiary_dni) order by id),256),12) from glpi_plugin_lagapenak_loans),'-'))"; }
  tablas() { q "select group_concat(replace(table_name,'glpi_plugin_lagapenak_','') order by table_name) from information_schema.tables where table_schema=database() and table_name like 'glpi_plugin_lagapenak_%'"; }
  uuids() { q "select concat(count(uuid),'/',count(*),' distintos=',count(distinct uuid),' huella=',left(sha2(group_concat(concat(id,uuid) order by id),256),12)) from glpi_plugin_lagapenak_loans"; }
  eventos() { q "select left(sha2(group_concat(concat(id,event,loan_uuid) order by id),256),12) from glpi_plugin_lagapenak_loanevents"; }
  apicfg() { q "select ifnull(group_concat(concat(name,'=',value) order by name),'(sin configuración de API)') from glpi_configs where context='plugin:lagapenak' and name like 'api\\_%' and name not in ('api_hmac_keys','api_hmac_keys_mod')"; }
  meta() { docker exec "$c" curl -s -o /dev/null -w '%{http_code}' http://localhost/plugins/lagapenak/front/api.php/v1/meta; }
  need() { local n; n=$(q "$2"); if [ -z "$n" ] || [ "$n" = 0 ]; then echo "  ALTO: no se cumple «$1»."; exit 1; fi; }
  same() { if [ "$2" = "$3" ]; then echo "  $1: idénticos"; else echo "  $1: DIFERENCIAS ($2 ≠ $3)"; problemas=$((problemas+1)); fi; }
  run() { # run <etiqueta> <paso> <clave> <fichero>: un fallo o una batería sin terminar cuentan como problema
          bateria_una "$3" "$4" "paso$2-$3" >/dev/null || problemas=$((problemas+1)); local f=$RES/paso$2-$3.txt
          echo "  $1: $(grep "^Resultado" "$f" || echo "SIN RESULTADO")"; grep "| FALLO" "$f" | cut -c1-250; }
  paso() { echo; echo "== $*"; }

  paso "0. Producción simulada: GLPI $(q "select value from glpi_configs where context='core' and name='version'") + Lagapenak $v161 ($ref161, $(git -C "$REPO" rev-parse --short "$ref161")) con actividad"
  docker exec "$db" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "drop database if exists $MARIADB_DATABASE; create database $MARIADB_DATABASE; grant all on $MARIADB_DATABASE.* to $MARIADB_USER@\"%\""'
  docker exec -u www-data -w $GLPI "$c" sh -c 'php bin/console db:install --db-host=db --db-name="$G1_DB_NAME" --db-user="$G1_DB_USER" \
    --db-password="$G1_DB_PASSWORD" --default-language=es_ES --no-interaction --force --reconfigure' 2>&1 | grep -v '^\s*$' | tail -1
  cuentas
  poner_plugin "$ref161"; console plugin:install lagapenak -u glpi >/dev/null; console plugin:activate lagapenak >/dev/null
  copiar_apoyos
  docker exec "$c" php /tmp/fixtures_sql.php >/dev/null; docker exec "$c" php /tmp/fixtures_perm.php >/dev/null; console cache:clear >/dev/null
  need "$v161 activo" "select count(*) from glpi_plugins where directory='lagapenak' and version='$v161' and state=1"
  docker exec "$c" bash -c "echo 80 > /tmp/suite_n; echo 0 > /tmp/suite_g1_n"
  run "batería general $v161" 0 general suite.sh
  local ANTES; ANTES=$(datos); echo "  $(st) · datos: $ANTES"; echo "  tablas: $(tablas)"

  paso "1. Actualización con el procedimiento de despliegue"
  console maintenance:enable >/dev/null; echo "  1) modo mantenimiento"
  docker exec "$db" sh -c "mariadb-dump -uroot -p\"\$MARIADB_ROOT_PASSWORD\" --single-transaction \$MARIADB_DATABASE > $DUMP" \
    && echo "  2) copia de la base: $(docker exec "$db" stat -c %s $DUMP) bytes"
  local COPIA=/tmp/plugin-$v161-$(date +%H%M%S)
  docker exec "$c" cp -a $PLUGIN $COPIA && echo "     copia del plugin: $COPIA"
  poner_plugin -; echo "  3) carpeta sustituida por la versión G1 ($vg1, copia de trabajo) · 4) Apache recargado"
  console plugin:install lagapenak -u glpi --force >/dev/null; console plugin:activate lagapenak >/dev/null; echo "  5) Actualizar + Activar"
  console cache:clear >/dev/null; echo "  6) cache:clear"
  need "versión G1 activa" "select count(*) from glpi_plugins where directory='lagapenak' and version='$vg1' and state=1"
  echo "  7) estado: $(st)"
  console maintenance:disable >/dev/null; echo "  8) fin del mantenimiento"
  same "datos de préstamos" "$ANTES" "$(datos)"
  echo "  uuid: $(uuids)"
  echo "  tablas: $(tablas)"
  echo "  configuración: $(apicfg)"
  echo "  API tras actualizar (desactivada): GET /v1/meta → $(meta)"
  [ "$(meta)" = 404 ] || { echo "  DIFERENCIAS: la API debería estar desactivada"; problemas=$((problemas+1)); }
  echo "  préstamos anteriores marcados como no comprobados: $(q "select concat(sum(beneficiary_validated=0),'/',count(*)) from glpi_plugin_lagapenak_loans")"
  run "batería API G1" 1 g1 suite_g1.sh
  run "batería de permisos" 1 perm suite_perm.sh
  run "batería general" 1 general suite.sh

  paso "2. Desinstalar la versión G1"
  local EV PRE PREU; EV=$(eventos); PRE=$(datos); PREU=$(uuids)
  console plugin:deactivate lagapenak >/dev/null; echo "  $(console plugin:uninstall lagapenak)"
  echo "  tablas que quedan: $(tablas)"
  echo "  clientes de la API (credenciales): $(q "select count(*) from information_schema.tables where table_schema=database() and table_name='glpi_plugin_lagapenak_apiclients'") tablas · configuración: $(apicfg) · claves de identificación: $(q "select count(*) from glpi_configs where context='plugin:lagapenak' and name in ('api_hmac_keys','api_hmac_keys_mod')")"

  paso "3. Reinstalar la versión G1"
  console plugin:install lagapenak -u glpi >/dev/null; console plugin:activate lagapenak >/dev/null; console cache:clear >/dev/null
  echo "  $(st)"
  same "préstamos, líneas, comentarios y firmas" "$PRE" "$(datos)"
  same "uuid de los préstamos" "$PREU" "$(uuids)"
  same "eventos de préstamo" "$EV" "$(eventos)"
  echo "  API tras reinstalar: $(apicfg) · GET /v1/meta → $(meta)"

  paso "4. Reversión completa a $v161 (archivos + base de la copia del paso 1)"
  console maintenance:enable >/dev/null
  docker exec "$c" sh -c "rm -rf $PLUGIN && cp -a $COPIA $PLUGIN" && echo "  carpeta $v161 restaurada"
  docker exec "$db" sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e \"drop database \$MARIADB_DATABASE; create database \$MARIADB_DATABASE; grant all on \$MARIADB_DATABASE.* to \$MARIADB_USER@'%'\" && mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" \$MARIADB_DATABASE < $DUMP" && echo "  base restaurada"
  docker exec "$c" apachectl -k graceful 2>/dev/null; sleep 2
  console cache:clear >/dev/null
  need "$v161 activo tras revertir" "select count(*) from glpi_plugins where directory='lagapenak' and version='$v161' and state=1"
  console maintenance:disable >/dev/null
  echo "  $(st) · tablas: $(tablas)"
  same "datos respecto a la copia previa" "$ANTES" "$(datos)"
  echo "  columnas nuevas: $(q "select count(*) from information_schema.columns where table_schema=database() and table_name='glpi_plugin_lagapenak_loans' and column_name in ('uuid','beneficiary_validated','cancel_date','cancel_users_id','cancel_reason')") · front/api.php: $(docker exec "$c" test -f $PLUGIN/front/api.php && echo existe || echo no existe)"
  run "batería general $v161 tras revertir" 4 general suite.sh
  echo; echo "Ensayo: $([ $problemas = 0 ] && echo "sin diferencias ni fallos" || echo "$problemas PROBLEMAS (ver $RES)")"
  [ $problemas = 0 ]
}

cmd=${1:-}; shift || true
case $cmd in
  crear)     entorno "${1:-}" nuevo; shift; crear "$@" ;;
  desplegar) entorno "${1:-}"; poner_plugin "${2:--}"; instalar_plugin ;;
  bateria)   entorno "${1:-}"; bateria "${2:-todas}" ;;
  contrato)  entorno "${1:-}"; contrato ;;
  vectores)  if [ -n "${1:-}" ]; then entorno "$1"; fi; vectores ;;
  comprobar) entorno "${1:-}"; salida; r=0; bateria todas || r=1; contrato || r=1; vectores || r=1
             echo; echo "Comprobación de «$E»: $([ $r = 0 ] && echo "todo correcto" || echo "HAY FALLOS (ver $RES)")"; exit $r ;;
  ensayo)    entorno "${1:-}"; salida; ensayo "${2:-}" 2>&1 | tee "$RES/ensayo.txt"; exit "${PIPESTATUS[0]}" ;;
  parar)     entorno "${1:-}"; compose stop ;;
  eliminar)  entorno "${1:-}"; echo "Se borran los contenedores y volúmenes de $(proyecto) (solo datos ficticios)."
             compose down -v && rm -f "$(ENVF)" ;;
  lista)     docker ps -a --filter "name=lagapenak-g1-" --format '{{.Names}}\t{{.Status}}\t{{.Image}}' ;;
  *)         sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
