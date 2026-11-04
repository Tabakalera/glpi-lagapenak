# Pruebas de la API G1 de Lagapenak

Baterías automáticas de la API G1 (consulta de préstamos para Kide·artean), del modelo de permisos y de
regresión del plugin, con la validación del contrato OpenAPI, los vectores HMAC y el ensayo de actualización y
reversión desde la 1.6.1.

Todo se ejecuta en entornos Docker **aislados** y **solo con datos ficticios**. Nada de esto toca el GLPI
corporativo ni Kide·artean.

## Garantías

- **Aislamiento.** Cada entorno es un proyecto Docker propio (`lagapenak-g1-<entorno>`) con red interna: sin
  salida a Internet ni a la red corporativa y sin puertos publicados en el equipo. El correo va a un Mailpit
  interno. La subred (`10.250.200.0/24` a `10.250.254.0/24`) la elige `g1.sh` entre las que no usa ninguna
  otra red de Docker.
- **Sin credenciales en el repositorio.** Al crear un entorno, `g1.sh` genera contraseñas aleatorias (base de
  datos, administrador `glpi` y usuarios ficticios) en `tests/g1/.local/<entorno>.env`, que Git ignora. Las
  cuentas por defecto de GLPI (`tech`, `normal`, `post-only`) quedan desactivadas. Los clientes y las claves de
  la API se crean durante las pruebas y solo existen en el entorno.
- **Datos ficticios.** Entidades `D1-A`/`D1-B`, usuarios `d1_*` con correos `@example.test`, equipos
  `D1-PORTATIL-*`, DNI/NIE de ejemplo (`12345678Z`, `00000000T`, `X1234567L`...) y una firma de un solo trazo
  generada al vuelo. La clave HMAC de los vectores es la clave de prueba publicada en
  `docs/api/g1-hmac-vectors.json`.
- **Resultados fuera de Git.** Las salidas, el diagnóstico de los fallos y las respuestas capturadas de la API
  se guardan en `tests/g1/resultados/`, que Git ignora: pueden contener credenciales de prueba del entorno.
- **Fuera del plugin.** `tests/` no se copia al desplegar (`g1.sh` lo excluye), no entra en los paquetes
  (`export-ignore` en `.gitattributes`) y, si aun así estuviera en el servidor, `tests/.htaccess` lo bloquea.

## Requisitos

| Herramienta | Para qué |
|---|---|
| Docker con Compose v2 (Docker Desktop en Windows) | Entornos |
| Bash (en Windows, Git Bash) y Git | `g1.sh` |
| Python 3.10 o posterior con `pip install -r tests/g1/contrato/requirements.txt` | Validar el contrato y la referencia de los vectores |
| Node.js 18 o posterior | Validar el JavaScript del formulario contra los vectores |
| Internet, solo al crear un entorno | Descarga de GLPI desde GitHub y de las imágenes `mariadb:10.11`, `axllent/mailpit` y `php` |

## Uso

Desde la raíz del plugin:

```bash
tests/g1/g1.sh crear t10 10.0.24      # crea el entorno, instala GLPI, el plugin (copia de trabajo) y los datos ficticios
tests/g1/g1.sh comprobar t10          # las tres baterías + contrato + vectores
```

| Orden | Qué hace |
|---|---|
| `crear <entorno> <versión GLPI>` | Crea el entorno (GLPI 10.0.x u 11.0.x). Si ya existe, solo vuelve a desplegar el plugin |
| `desplegar <entorno> [rama\|paquete]` | Copia otra vez el plugin: la copia de trabajo (también lo no confirmado), la rama o commit indicado, o un paquete de entrega `.tar.gz` tal cual |
| `bateria <entorno> [g1\|permisos\|general\|todas]` | Ejecuta una batería o todas |
| `contrato <entorno>` | Captura respuestas reales de la API y las valida contra `docs/api/openapi-g1.yaml` (después de la batería g1) |
| `vectores [entorno]` | Vectores HMAC con la referencia Python; con entorno, también con el PHP del plugin y el JavaScript del formulario |
| `comprobar <entorno>` | Todo lo anterior. Sale con código distinto de 0 si algo falla |
| `ensayo <entorno> [origen] [destino]` | Ensayo de actualización y reversión (ver abajo) |
| `arrancar <entorno>` / `parar <entorno>` | Encender un entorno parado tal como quedó (sin volver a copiar el plugin) / pararlo sin borrar nada |
| `eliminar <entorno>` / `lista` | Borrar contenedores, volúmenes y contraseñas del entorno / ver los entornos |

Entornos recomendados antes de una entrega:

| Entorno | Versión | Por qué |
|---|---|---|
| `rv` | 10.0.20 | La de producción: ensayo de actualización y reversión |
| `t10` | 10.0.24 | Siguiente versión de GLPI 10 prevista |
| `t11` | 11.0.9 | Compatibilidad con GLPI 11 |

Las baterías se pueden repetir en el mismo entorno: cada ronda usa sus propias fechas y nombres. No ejecutes
dos baterías a la vez en el mismo entorno.

## Qué comprueba cada parte

| Parte | Fichero | Pruebas | Contenido |
|---|---|---|---|
| API G1 | `suites/suite_g1.sh` | 183 | Autenticación, ámbitos (`loans:read`, `loans:read:personal`), entidades, sincronización incremental y cursor, `scope_version` y reconciliación, detalle completo, huellas del beneficiario, cancelación (F04–F08), registro de la API, límites, administración de clientes y claves |
| Permisos | `suites/suite_perm.sh` | 86 | Modelo de permisos de la 1.6.1 con perfiles y combinaciones nuevas: consultar, crear, gestionar, borrar, firmar, iCal, acciones masivas, entidades |
| General | `suites/suite.sh` | 63 | Seguridad y regresión del plugin: formulario, material, firma y albarán, correo, calendario, iCal. La prueba P1c solo se añade (64) si el entorno conserva un préstamo antiguo de un usuario que perdió sus permisos; en un entorno nuevo no aparece |
| Contrato | `contrato/captura.sh`, `contrato/validar_contrato.py` | 11 respuestas | Meta, páginas de eventos, detalle con y sin datos personales, lote, búsqueda por número y errores contra los esquemas del contrato |
| Vectores | `vectores/` | 28 × 3 | Los 28 casos de `g1-hmac-vectors.json` con la referencia Python independiente, el PHP del plugin y el JavaScript del formulario tal como lo sirve GLPI |
| Ensayo | `g1.sh ensayo` | — | Ver abajo |
| Migración de GLPI | `migracion_glpi11.sh <entorno GLPI 10> <entorno GLPI 11>` | — | Copia la base de un GLPI 10 con la 1.6.0 y datos a un GLPI 11, migra con `db:update`, reanuda los plugins (`plugin:resume_execution`) y actualiza a 1.6.1 y 1.7.0 con las baterías. **Sustituye la base del entorno GLPI 11** |

`suites/prueba_g1c.php` es una sonda que la batería G1 copia en el plugin del entorno solo durante unas pruebas
(carreras al cancelar y vías internas de GLPI) y retira después. `suites/lib.sh` reúne las funciones comunes y
el diagnóstico.

## Ensayo de actualización y reversión

`g1.sh ensayo <entorno> [origen] [destino]` reproduce el procedimiento de despliegue en un entorno creado con la
versión de GLPI de producción. **Borra y vuelve a crear la base de datos de ese entorno**, que solo contiene datos
ficticios. El origen es una rama, un commit o un paquete `.tar.gz` (por defecto `release/1.6.1-seguridad`); el
destino, otra rama, commit o paquete o, por defecto, la copia de trabajo. Las ramas solo se leen. Para validar una
entrega, ensaya con los paquetes mismos.

| Ruta | Orden |
|---|---|
| 1.6.0 → 1.6.1 | `g1.sh ensayo rv main release/1.6.1-seguridad` |
| 1.6.1 → 1.7.0 | `g1.sh ensayo rv release/1.6.1-seguridad release/1.7.0` |
| 1.6.0 → 1.7.0 | `g1.sh ensayo rv main release/1.7.0` |

0. GLPI limpio con la versión de origen y actividad generada por la batería general. Su resultado es la
   referencia: la 1.6.0 tiene fallos de seguridad conocidos que corrige la 1.6.1, y no cuentan como problema.
1. Modo mantenimiento, copia de la base y del plugin, sustitución por la versión de destino, Actualizar +
   Activar, `cache:clear`. Comprueba que los datos no cambian, que la API queda desactivada y ejecuta las
   baterías (la de la API, solo si el destino la incluye).
1b. Reversión al origen **conservando los datos**, como se haría en producción si aparece un problema tras
   actualizar: solo se cambian los archivos (con `plugin:install --force` y la retirada de la tarea automática
   `ApiLogClean` si el origen no la conoce), sin desinstalar. Los préstamos y los derechos de los perfiles deben
   quedar idénticos, sin errores de PHP ni de tareas automáticas, y la batería general del origen debe pasar si
   pasaba en el paso 0.
1c. Volver a actualizar al destino sobre esa base: datos y derechos idénticos y baterías correctas.
2. Desinstalación: no deben quedar credenciales, configuración ni claves de la API.
3. Reinstalación: los préstamos, sus `uuid` y los eventos se conservan.
4. Reversión completa al origen con la copia del paso 1: datos idénticos, sin columnas nuevas, y la batería
   general da el mismo resultado que en el paso 0.

El ensayo termina con «sin diferencias ni fallos» o con el número de problemas.

## Resultados y diagnóstico

Cada ejecución crea `tests/g1/resultados/<entorno>-<fecha>/` con:

- `<batería>.txt`: la salida completa (una línea por prueba: id, qué se prueba, esperado, obtenido, estado).
- `diag-<batería>/`:
  - `<id>.txt` por cada fallo, también los intermitentes: esperado y obtenido, última respuesta de la API
    (cabeceras y cuerpo), mensajes del formulario, relojes del contenedor y de la base, últimas filas del
    registro de la API y de los eventos, y los errores de PHP y del plugin.
  - Copias de la última respuesta, de la página y del feed completo en el momento del fallo.
  - `tiempos.tsv`: duración de cada prueba, para detectar lentitud o carreras.
  - `avisos.txt`: lecturas lentas que obligaron a repetir una prueba sensible al tiempo (S12). Que exista no es
    un fallo, pero conviene revisarlo.
- `contrato/` y `contrato.txt`, `formulario.html`, `ensayo.txt`, según la orden.

Para estudiar un fallo, empieza por `diag-<batería>/<id>.txt`.

## Mantenimiento

- Si cambia la normalización `g1-v1`, regenera los vectores con `python tests/g1/vectores/referencia.py
  docs/api/g1-hmac-vectors.json` y comunícalo a Kide·artean: es un cambio de contrato.
- Si cambia el contrato, `g1.sh contrato` debe seguir dando 0 errores; añade a `validar_contrato.py` los casos
  nuevos.
- Las baterías identifican cada prueba con un id estable (`C05`, `V03`...). No reutilices ids de pruebas
  retiradas.
