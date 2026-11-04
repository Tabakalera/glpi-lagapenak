#!/bin/bash
# Funciones de apoyo de las baterías (dentro del contenedor GLPI del entorno aislado, contra http://localhost).
# Las contraseñas llegan como variables de entorno del contenedor (las genera g1.sh al crear el entorno).
B=http://localhost
P=$B/plugins/lagapenak
PW="${G1_USER_PASSWORD:?falta G1_USER_PASSWORD: crea el entorno con tests/g1/g1.sh}"

login() { # login <usuario> [contraseña]
  local u=$1 pw=${2:-$PW} jar=/tmp/cj_$1
  rm -f $jar
  local page=$(curl -s -c $jar -b $jar $B/)
  local tok=$(echo "$page" | grep -o 'name="_glpi_csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  # GLPI 10 randomises the field names (fielda…/fieldb…); GLPI 11 uses login_name/login_password
  local fa=$(echo "$page" | grep -o 'id="login_name" name="[^"]*"' | sed 's/.*name="//;s/"//')
  local fb=$(echo "$page" | grep -o 'id="login_password" name="[^"]*"' | sed 's/.*name="//;s/"//')
  curl -s -c $jar -b $jar -o /dev/null -w '%{http_code} %{redirect_url}\n' \
    --data-urlencode "$fa=$u" --data-urlencode "$fb=$pw" \
    --data-urlencode "_glpi_csrf_token=$tok" --data-urlencode "noAUTO=0" $B/front/login.php
}

csrf() { # csrf <usuario> [url]  → token nuevo leído de una página con cabecera GLPI
  local url=${2:-$P/front/loan.php}
  curl -s -b /tmp/cj_$1 -c /tmp/cj_$1 "$url" \
    | grep -o 'name="_glpi_csrf_token" value="[^"]*"\|property="glpi:csrf_token" content="[^"]*"' \
    | head -1 | sed 's/.*\(value\|content\)="//;s/"//'
}

get() { # get <usuario> <url>
  curl -s -b /tmp/cj_$1 -c /tmp/cj_$1 "$2"
}

post() { # post <usuario> <url> <campos curl...>  → código HTTP y redirección
  local u=$1 url=$2; shift 2
  local tok=$(csrf $u)
  curl -s -b /tmp/cj_$u -c /tmp/cj_$u -o /tmp/last_body -w '%{http_code} %{redirect_url}\n' \
    -H "Referer: $url" --data-urlencode "_glpi_csrf_token=$tok" "$@" "$url"
}

sql() { # consulta en la base ficticia (se pasa por la red interna)
  php -r '
    $c = new mysqli("db", getenv("G1_DB_USER"), getenv("G1_DB_PASSWORD"), getenv("G1_DB_NAME"));
    $r = $c->query($argv[1]);
    if ($r === true) { echo "OK\n"; exit; }
    $first = true;
    while ($row = $r->fetch_assoc()) {
      if ($first) { echo implode("\t", array_keys($row)), "\n"; $first = false; }
      echo implode("\t", array_map(fn($v) => $v === null ? "NULL" : (strlen($v) > 60 ? substr($v,0,60)."…" : $v), $row)), "\n";
    }' "$1"
}

# ── Diagnóstico de fallos (también intermitentes) ───────────────────────────
# Cada batería fija DIAG=/tmp/diag-<batería>-<ronda> y llama a diag en cada FALLO y a tick en cada prueba.
# Queda: <id>.txt (esperado, obtenido, última respuesta, registros), copias de la última respuesta y
# tiempos.tsv (duración de cada prueba, para ver lentitud o carreras).
diag() { # diag <id> <descripción> <esperado> <obtenido>
  local d=${DIAG:-/tmp/diag}; mkdir -p $d; local f=$d/$1.txt
  cp /tmp/api_body $d/$1-api_body.json 2>/dev/null; cp /tmp/api_hdr $d/$1-api_hdr.txt 2>/dev/null
  cp /tmp/last_body $d/$1-last_body.html 2>/dev/null; cp /tmp/feed_all.json $d/$1-feed_all.json 2>/dev/null
  { echo "== $1 · $2"; echo "fecha: $(date '+%F %T.%3N')"
    echo "esperado: [$3]"; echo "obtenido: [$4]"
    echo "-- última respuesta de la API (cabeceras)"; cat /tmp/api_hdr 2>/dev/null
    echo "-- última respuesta de la API (cuerpo, 3000 caracteres)"; head -c 3000 /tmp/api_body 2>/dev/null; echo
    echo "-- mensajes de la última página del formulario"; grep -o 'alert-[a-z]*[^<]*<[^<]*<[^<]*' /tmp/last_body 2>/dev/null | head -10
    echo "-- reloj (contenedor web / base)"; date '+%F %T.%3N'; sql "select now(6) as base"
    echo "-- registro de la API (10 últimas)"; sql "select * from glpi_plugin_lagapenak_apilogs order by id desc limit 10"
    echo "-- eventos de préstamo (5 últimos)"; sql "select * from glpi_plugin_lagapenak_loanevents order by id desc limit 5"
    echo "-- errores PHP y del plugin (30 últimas líneas)"
    tail -30 /var/www/html/glpi/files/_log/php-errors.log 2>/dev/null; tail -30 /var/www/html/glpi/files/_log/lagapenak.log 2>/dev/null
  } > $f 2>&1
  echo "      diagnóstico: $f" >&2
}
T_LAST=$(date +%s%3N)
tick() { # tick <id> <estado> → tiempos.tsv
  local now=$(date +%s%3N) d=${DIAG:-/tmp/diag}; mkdir -p $d
  printf '%s\t%s\t%s\t%s\n' "$1" "$(date '+%F %T')" "$((now - T_LAST))" "$2" >> $d/tiempos.tsv; T_LAST=$now
}
