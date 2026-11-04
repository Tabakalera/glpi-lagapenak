#!/bin/bash
# Ensayo de la migración de GLPI 10 → 11 con Lagapenak instalado y con datos (ficticios), y después actualización
# del plugin 1.6.0 → 1.6.1 → 1.7.0 sobre GLPI 11.
#   tests/g1/migracion_glpi11.sh <entorno GLPI 10> <entorno GLPI 11>
# Los dos entornos se crean antes con g1.sh crear. El de GLPI 10 pasa a tener la 1.6.0 (conserva sus datos); la
# base del de GLPI 11 se SUSTITUYE por una copia de la del GLPI 10 (solo datos ficticios).
# Versiones: etiqueta v1.6.0 (1.6.0), ramas release/1.6.1-seguridad y release/1.7.0. Resultados en pantalla.
set -u
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/../.."
G=tests/g1/g1.sh
[ $# = 2 ] || { sed -n '2,8p' "$0"; exit 2; }
TMPD=$(cd "$(mktemp -d)" && { pwd -W 2>/dev/null || pwd; })   # copia temporal de la base y de la clave de GLPI 10 (ruta
# que entienda también Docker en Windows); se borra al final
EO=$1; ED=$2; O=lagapenak-g1-$EO; D=lagapenak-g1-$ED
GL=/var/www/html/glpi
qo() { docker exec $O-db-1 sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "$1"' sh "$1" 2>/dev/null; }
qd() { docker exec $D-db-1 sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "$1"' sh "$1" 2>/dev/null; }
cd_() { docker exec -u www-data -w $GL $D-glpi-1 php bin/console "$@" --no-interaction 2>&1 | grep -v '^\s*$' | tail -2; }
datos() { "$1" "select concat('prestamos=',count(*),' firmados=',sum(signature_data<>''),' huella=',left(sha2(group_concat(concat(id,':',name,':',status,':',entities_id,':',ifnull(fecha_fin,''),':',ifnull(beneficiary_name,''),':',sha2(ifnull(signature_data,''),256)) order by id),256),12)) from glpi_plugin_lagapenak_loans"; }
derechos() { "$1" "select left(sha2(group_concat(concat(p.name,':',r.name,':',r.rights) order by p.name,r.name),256),12) from glpi_profilerights r join glpi_profiles p on p.id=r.profiles_id where r.name like 'plugin_lagapenak%'"; }
paso() { echo; echo "== $*"; }

paso "0. Origen: GLPI $(qo "select value from glpi_configs where context='core' and name='version'") con Lagapenak 1.6.0 y datos"
docker exec -u www-data -w $GL $O-glpi-1 php bin/console maintenance:enable --no-interaction >/dev/null 2>&1
bash $G desplegar $EO v1.6.0 2>&1 | tail -1
docker exec -u www-data -w $GL $O-glpi-1 php bin/console maintenance:disable --no-interaction >/dev/null 2>&1
A=$(datos qo); R=$(derechos qo); echo "  $(qo "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'") · $A · derechos=$R"

paso "1. Copia de la base y de la clave de cifrado de GLPI (glpicrypt.key) al GLPI 11"
docker exec $O-db-1 sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > "$TMPD/base-glpi10.sql" 2>/dev/null
echo "  copia: $(wc -c < "$TMPD/base-glpi10.sql") bytes"
docker exec $D-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "drop database $MARIADB_DATABASE; create database $MARIADB_DATABASE; grant all on $MARIADB_DATABASE.* to $MARIADB_USER@\"%\""'
docker exec -i $D-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < "$TMPD/base-glpi10.sql"
docker exec $D-glpi-1 sh -c "cp -a $GL/config/glpicrypt.key /tmp/glpicrypt.key.propio-\$(date +%H%M%S)"
docker cp $O-glpi-1:$GL/config/glpicrypt.key "$TMPD/glpicrypt.key" >/dev/null && docker cp "$TMPD/glpicrypt.key" $D-glpi-1:$GL/config/glpicrypt.key >/dev/null
docker exec $D-glpi-1 chown www-data:www-data $GL/config/glpicrypt.key
k1=$(docker exec $O-glpi-1 sha256sum $GL/config/glpicrypt.key | cut -c1-64); k2=$(docker exec $D-glpi-1 sha256sum $GL/config/glpicrypt.key | cut -c1-64)
[ "$k1" = "$k2" ] && echo "  glpicrypt.key de GLPI 10 copiada al GLPI 11: idéntica" || { echo "  ALTO: glpicrypt.key no se copió"; exit 1; }
echo "  base cargada en GLPI 11: versión de GLPI en la base = $(qd "select value from glpi_configs where context='core' and name='version'")"

paso "2. Plugin 1.6.0 en la carpeta del GLPI 11 y migración de GLPI (db:update)"
bash $G desplegar $ED v1.6.0 >/dev/null 2>&1   # copia la 1.6.0; su install fallará o no se ejecutará hasta migrar GLPI
cd_ maintenance:enable >/dev/null
echo "  db:update: $(cd_ db:update --allow-unstable)"
echo "  GLPI: $(qd "select value from glpi_configs where context='core' and name='version'") · Lagapenak tras migrar: $(qd "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'")"
echo "  modo de ejecución de plugins tras migrar: $(qd "select value from glpi_configs where context='core' and name='plugins_execution_mode'")"
echo "  reanudar plugins: $(cd_ plugin:resume_execution)"
echo "  modo de ejecución de plugins: $(qd "select value from glpi_configs where context='core' and name='plugins_execution_mode'")"
echo "  Lagapenak tras reanudar: $(qd "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'")"
echo "  activar: $(cd_ plugin:activate lagapenak)"
cd_ cache:clear >/dev/null
echo "  Lagapenak: $(qd "select concat(version,' state=',state) from glpi_plugins where directory='lagapenak'")"
cd_ maintenance:disable >/dev/null
# Contraseñas del entorno de destino (la base viene del origen, con otras contraseñas de prueba)
docker exec $D-glpi-1 php -r '$c=new mysqli("db",getenv("G1_DB_USER"),getenv("G1_DB_PASSWORD"),getenv("G1_DB_NAME"));
  $u=$c->real_escape_string(password_hash(getenv("G1_USER_PASSWORD"),PASSWORD_DEFAULT)); $a=$c->real_escape_string(password_hash(getenv("G1_ADMIN_PASSWORD"),PASSWORD_DEFAULT));
  $c->query("update glpi_users set password=\"$u\" where name like \"d1\\_%\""); $c->query("update glpi_users set password=\"$a\" where name=\"glpi\"");'
[ "$A" = "$(datos qd)" ] && echo "  préstamos: idénticos" || echo "  préstamos: DIFERENCIAS"
[ "$R" = "$(derechos qd)" ] && echo "  derechos de los perfiles: idénticos" || echo "  derechos de los perfiles: DIFERENCIAS"
docker exec $D-glpi-1 sh -c ": > $GL/files/_log/php-errors.log"
echo "  batería general 1.6.0 en GLPI 11 (referencia 24/40): $(bash $G bateria $ED general 2>&1 | grep -o 'Resultado.*')"
echo "  errores PHP: $(docker exec $D-glpi-1 sh -c "grep -c 'PHP\|Fatal\|Uncaught' $GL/files/_log/php-errors.log")"

paso "3. Lagapenak 1.6.0 → 1.6.1 en GLPI 11 (procedimiento de despliegue)"
B=$(datos qd)
cd_ maintenance:enable >/dev/null; bash $G desplegar $ED release/1.6.1-seguridad 2>&1 | tail -1; cd_ maintenance:disable >/dev/null
[ "$B" = "$(datos qd)" ] && echo "  préstamos: idénticos" || echo "  préstamos: DIFERENCIAS"
bash $G bateria $ED permisos 2>&1 | grep "Resultado\|FALLO" | sed 's/^/  permisos: /'
bash $G bateria $ED general 2>&1 | grep "Resultado\|FALLO" | sed 's/^/  general: /'

paso "4. Lagapenak 1.6.1 → 1.7.0 en GLPI 11"
B=$(datos qd)
cd_ maintenance:enable >/dev/null; bash $G desplegar $ED release/1.7.0 2>&1 | tail -1; cd_ maintenance:disable >/dev/null
[ "$B" = "$(datos qd)" ] && echo "  préstamos: idénticos" || echo "  préstamos: DIFERENCIAS"
echo "  API tras actualizar: $(docker exec $D-glpi-1 curl -s -o /dev/null -w '%{http_code}' http://localhost/plugins/lagapenak/front/api.php/v1/meta) (404 = desactivada)"
bash $G comprobar $ED 2>&1 | grep -E "Resultado|total errores|vectores|coinciden|Comprobación" | sed 's/^/  /'
rm -rf "$TMPD"
