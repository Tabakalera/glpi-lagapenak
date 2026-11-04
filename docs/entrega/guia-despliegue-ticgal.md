# Lagapenak 1.6.1 y 1.7.0: guía de despliegue y reversión

Destinatario: Ticgal, para actualizar Lagapenak en el GLPI corporativo de Tabakalera.
Estado: Lagapenak 1.6.1 y 1.7.0 publicadas el 4 de noviembre de 2026. Tabakalera autoriza instalar la 1.7.0
(que incluye la 1.6.1) con la API **desactivada**. La API no se activa hasta cerrar las aprobaciones del
apartado 13.

Los paquetes, sus sumas SHA-256 y los commits de los que proceden están en `MANIFIESTO.md`, que se entrega junto a
esta guía (fuera de los paquetes). Comprueba siempre la suma antes de instalar.

---

## 1. Situación de partida y versiones

| | |
|---|---|
| Producción hoy | GLPI 10.0.20 con Lagapenak 1.6.0 |
| Migración prevista | GLPI 11 (versión exacta pendiente de confirmar por Ticgal) |
| Versión 1.6.1 | Seguridad: corrige permisos, borrados, firma, envíos y calendario. Sin cambios de estructura en la base de datos |
| Versión 1.7.0 | Funcional: beneficiario obligatorio e identificable, cancelación de solicitudes y API de consulta para Kide·artean (**desactivada**). Añade columnas, tablas y una tarea automática |

Rutas de actualización soportadas y ensayadas. Cada una puede hacerse en una ventana distinta: no es necesario
hacerlo todo a la vez.

| Ruta | Cuándo |
|---|---|
| 1.6.0 → 1.7.0 (directa) | **La prevista**: desde la 1.6.0 de producción, instala todo de una vez |
| 1.6.0 → 1.6.1 | Solo si se prefiere un paso intermedio (por ejemplo, si la 1.7.0 tuviera que esperar) |
| 1.6.1 → 1.7.0 | Si ya se instaló la 1.6.1 |

### Compatibilidad comprobada

Pruebas automáticas con datos ficticios en entornos aislados (detalle en el apartado 12):

| GLPI | 1.6.1 | 1.7.0 | Actualizaciones y reversiones ensayadas |
|---|---|---|---|
| 10.0.11 (mínima declarada) | Sí | Sí | 1.6.0 → 1.6.1, 1.6.1 → 1.7.0 |
| 10.0.20 (producción) | Sí | Sí | Las tres rutas, reversión completa y conservando datos |
| 10.0.28 (última de GLPI 10) | Sí | Sí | 1.6.0 → 1.6.1, 1.6.1 → 1.7.0 |
| 11.0.12 (última de GLPI 11) | Sí | Sí | Las tres rutas, reversión completa y conservando datos, y migración de GLPI 10.0.20 → 11.0.12 con el plugin instalado |

Si Ticgal va a instalar una versión de GLPI 11 distinta de la 11.0.12, debe comunicarla antes para repetir las
pruebas sobre esa versión.

## 2. Requisitos previos

**Técnicos**
- GLPI 10.0.11 o posterior, o GLPI 11.0.x. PHP según la versión de GLPI (probado con PHP 8.2 en GLPI 10 y 8.3 en
  GLPI 11). El plugin no necesita extensiones ni librerías aparte de las que exige GLPI (usa el TCPDF y el
  sistema de correo de GLPI, `gd` y `mbstring`). No usa Composer ni descarga nada.
- Acceso de consola al servidor, como el usuario del servidor web (en los ejemplos, `www-data`), para
  `php bin/console`.
- Usuario de base de datos con permisos para crear tablas, columnas e índices (la 1.7.0 los necesita; GLPI ya
  los tiene para sus propias actualizaciones).
- Acciones automáticas de GLPI (cron) funcionando: `LoanReminder` (recordatorios) y, en la 1.7.0, `ApiLogClean`.
- Correo saliente de GLPI configurado (notificaciones y envío de albaranes).
- Una cuenta Super-Admin de GLPI para las comprobaciones.

**Organizativos**
- Autorización de Tabakalera para la versión que se instala y la ventana de mantenimiento comunicada al personal.
- Para la 1.7.0: aviso previo al personal que gestiona préstamos (apartado 11).

**Permisos de los perfiles.** Lagapenak usa dos derechos de perfil: `plugin_lagapenak_loan` (Leer, Crear,
Actualizar, Purgar) y `plugin_lagapenak_albaran`. La actualización no los cambia, pero desde la 1.6.1 se aplican
de forma estricta:

| Para... | Hace falta |
|---|---|
| Ver préstamos (lista, ficha, albarán, calendario) | Leer |
| Pedir un préstamo | Leer + Crear |
| Gestionar préstamos de una entidad (supervisor) | Leer + Actualizar, y acceso a esa entidad |
| Borrar préstamos de otras personas | Leer + Actualizar + Purgar (nunca un préstamo con albarán firmado) |

Antes de actualizar, anota qué perfiles tienen derechos (consulta del apartado 4) para compararlos después.

## 3. Qué cambia en la base de datos

| Versión | Cambios |
|---|---|
| 1.6.1 | Ninguno en la estructura. El enlace iCal general de la 1.6.0 deja de funcionar (cada persona usa su enlace personal) |
| 1.7.0 | Tabla `glpi_plugin_lagapenak_loans`: columnas `uuid` (índice único), `beneficiary_validated`, `cancel_date`, `cancel_users_id`, `cancel_reason`. Tablas nuevas `glpi_plugin_lagapenak_loanevents`, `glpi_plugin_lagapenak_apiclients` y `glpi_plugin_lagapenak_apilogs`. Configuración `api_*` en `glpi_configs` (API desactivada). Acción automática `ApiLogClean` |

La instalación solo crea lo que falta: nunca borra ni modifica datos existentes. A los préstamos existentes la
1.7.0 les asigna un `uuid` y los marca como «beneficiario no comprobado»; sus datos no cambian.

## 4. Copia de seguridad (antes de cada ventana)

Haz las tres copias **con GLPI en modo mantenimiento** (paso 1 del apartado 5) y guárdalas fuera del servidor
hasta que se dé por buena la actualización.

1. **Base de datos completa** (no solo las tablas del plugin; la reversión completa restaura la base entera):
   ```bash
   mysqldump --single-transaction --routines --triggers -u <usuario> -p <base_glpi> > glpi-antes-lagapenak-<versión>-<fecha>.sql
   ```
   Comprueba que el archivo termina con `-- Dump completed`.
2. **Carpeta del plugin**, copiándola fuera de `plugins/` (no la borres):
   ```bash
   cp -a <glpi>/plugins/lagapenak /<copias>/lagapenak-<versión anterior>-<fecha>
   ```
3. **Configuración de GLPI**: la carpeta de configuración (`<glpi>/config`, o la que indique
   `GLPI_CONFIG_DIR`), sobre todo `glpicrypt.key`. En la 1.7.0 la clave de identificación de la API se guarda
   cifrada con ella; sin esa clave no se puede descifrar.
4. **Derechos de los perfiles sobre Lagapenak**, aparte, para poder restaurarlos solos (apartado 9):
   ```bash
   mysqldump --no-create-info --replace --skip-triggers -u <usuario> -p <base_glpi> glpi_profilerights \
     --where="name LIKE 'plugin_lagapenak%'" > derechos-lagapenak-<fecha>.sql
   ```
   El archivo solo debe contener una sentencia `REPLACE INTO` y ningún `DROP TABLE` ni `CREATE TABLE`.

**Foto previa** para comparar después (guárdala con las copias):

```sql
SELECT version, state FROM glpi_plugins WHERE directory = 'lagapenak';
SELECT COUNT(*) AS prestamos, SUM(signature_data <> '') AS firmados FROM glpi_plugin_lagapenak_loans;
SELECT COUNT(*) AS lineas FROM glpi_plugin_lagapenak_loanitems;
SELECT COUNT(*) AS comentarios FROM glpi_plugin_lagapenak_loancomments;
SELECT p.name AS perfil, r.name AS derecho, r.rights FROM glpi_profilerights r
  JOIN glpi_profiles p ON p.id = r.profiles_id WHERE r.name LIKE 'plugin_lagapenak%' AND r.rights > 0 ORDER BY 1, 2;
```

## 5. Procedimiento de actualización del plugin

El mismo procedimiento sirve para las tres rutas. En los ejemplos, `<glpi>` es la carpeta de GLPI y los comandos
se ejecutan en `<glpi>` como el usuario del servidor web.

1. **Modo mantenimiento:** `php bin/console maintenance:enable`.
2. **Copias de seguridad** del apartado 4 y foto previa.
3. **Comprobar el paquete:** `sha256sum <paquete>` debe coincidir con `MANIFIESTO.md`.
4. **Sustituir la carpeta del plugin** sin borrar la anterior:
   ```bash
   mv <glpi>/plugins/lagapenak /<copias>/lagapenak-retirada-<fecha>   # si no se movió ya en el apartado 4
   tar -xzf <paquete>.tar.gz -C <glpi>/plugins/                           # crea <glpi>/plugins/lagapenak
   chown -R www-data:www-data <glpi>/plugins/lagapenak
   ```
5. **Recargar PHP** para vaciar su caché de código: `systemctl reload apache2` (o `php-fpm`).
6. **Actualizar y activar:**
   ```bash
   php bin/console plugin:install lagapenak -u <cuenta Super-Admin> --force
   php bin/console plugin:activate lagapenak
   php bin/console cache:clear
   ```
   Equivale a pulsar **Actualizar** y **Activar** en *Configuración → Plugins*.
7. **Comprobaciones técnicas** del apartado 7. Si algo falla, ve al apartado 9 sin salir del modo mantenimiento.
8. **Fin del mantenimiento:** `php bin/console maintenance:disable`.
9. **Pruebas de aceptación** del apartado 8.

Importante: no dejes GLPI fuera del modo mantenimiento entre el paso 4 y el 6. Si una petición web encuentra
archivos de una versión y la base de otra, GLPI desactiva el plugin («version changed») y hay que repetir el
paso 6.

## 6. Particularidades de cada ruta

### 6.1 De 1.6.0 a 1.6.1
- Sin cambios en la base de datos. La actualización es rápida.
- Después: avisar a quien use el calendario de que el enlace iCal general ya no funciona y debe suscribirse con
  su enlace personal (*Lagapenak → Calendario → Suscribirse*).
- Revisar que los perfiles que piden préstamos tienen **Leer** además de **Crear**.

### 6.2 De 1.6.1 a 1.7.0
- Crea las columnas, tablas y la tarea automática del apartado 3. Con unos cientos de préstamos tarda segundos.
- La API queda **desactivada**: `GET /plugins/lagapenak/front/api.php/v1/meta` responde 404.
- Cambia el formulario de préstamo (apartado 11): avisar antes al personal.

### 6.3 De 1.6.0 a 1.7.0 directamente
- Es la ruta prevista. Instala de una vez todo lo de 6.1 y 6.2; se ha ensayado y funciona igual que en dos pasos.
- Después, haz lo indicado en 6.1 (aviso del calendario, revisión de perfiles) y en 6.2 (aviso al personal).

## 7. Comprobaciones técnicas después de actualizar

```sql
SELECT version, state FROM glpi_plugins WHERE directory = 'lagapenak';   -- versión nueva y state = 1
```

- Los recuentos de la foto previa (préstamos, firmados, líneas, comentarios) son **idénticos**.
- Los derechos de los perfiles son **idénticos** a la foto previa.
- No hay errores nuevos de Lagapenak en `<glpi>/files/_log/php-errors.log` ni en `sql-errors.log`.
- En *Configuración → Acciones automáticas*: `LoanReminder` y, en la 1.7.0, `ApiLogClean`.
- Solo 1.7.0: `curl -s -o /dev/null -w '%{http_code}' https://<glpi>/plugins/lagapenak/front/api.php/v1/meta`
  devuelve `404` (API desactivada) y existen las tablas `loanevents`, `apiclients` y `apilogs`.

## 8. Pruebas de aceptación

Hazlas con una cuenta de supervisor y otra de solicitante reales, sobre una entidad de pruebas si existe.
Cuando la prueba necesite un beneficiario, usa a una persona del personal que lo acepte (nunca datos de un
artista). Retira o cancela al final los préstamos de prueba.

**Con préstamos existentes (todas las versiones)**

| # | Prueba | Resultado esperado |
|---|---|---|
| A1 | Abrir la lista de préstamos y varios préstamos antiguos (pendiente, entregado, devuelto) | Se ven igual que antes: material, fechas, estado, comentarios |
| A2 | Abrir un préstamo con albarán firmado y su PDF | Se ve la firma, el nombre del firmante y el beneficiario |
| A3 | Ver los datos del beneficiario de un préstamo antiguo | Se conservan tal cual |
| A4 | Calendario de Lagapenak y comprobador de disponibilidad | Muestran los préstamos de las entidades del usuario |

**Permisos (todas las versiones)**

| # | Prueba | Resultado esperado |
|---|---|---|
| P1 | Solicitante: crear un préstamo en su entidad | Se crea |
| P2 | Solicitante: abrir un préstamo de otra persona por su número | No puede |
| P3 | Supervisor: gestionar un préstamo de su entidad (material, entrega) | Puede |
| P4 | Supervisor: abrir un préstamo de una entidad que no supervisa | No puede |
| P5 | Cualquiera: borrar un préstamo con albarán firmado | No puede |
| P6 | Usuario sin derecho Leer: abrir Lagapenak | No puede |

**Material y firma (todas las versiones)**

| # | Prueba | Resultado esperado |
|---|---|---|
| M1 | Añadir material con «Permitir reservas» y entregarlo | Se añade y cambia de estado |
| M2 | Enviar el enlace de firma por correo y firmar desde él (sin sesión) | Llega el correo; el albarán queda firmado |
| M3 | Intentar firmar de nuevo con el mismo enlace | No se puede |
| M4 | Calendario personal (iCal): suscribirse con el enlace personal | Funciona; el enlace general antiguo no |

**Solo 1.7.0**

| # | Prueba | Resultado esperado |
|---|---|---|
| N1 | Crear un préstamo sin nombre de beneficiario | Lo impide y conserva lo escrito |
| N2 | Crear un préstamo con nombre y un email o DNI/NIE válidos | Se crea; no se rellena solo con los datos del solicitante |
| N3 | Cancelar una solicitud sin material entregado, con motivo | Queda cancelada, con quién y cuándo; no se puede borrar ni reabrir |
| N4 | Intentar cancelar un préstamo con material entregado o firmado | No se puede |
| N5 | Ver el aviso de no escribir datos personales en referencia, campos adicionales y proyecto del albarán | Aparece |
| N6 | Comprobar que la API está desactivada (apartado 7) | 404 |

## 9. Plan de reversión

Decide la reversión **dentro de la ventana** si fallan las comprobaciones, o después si aparece un problema
grave. Hay dos métodos, ambos ensayados en GLPI 10.0.20 y 11.0.12:

| Método | Qué recupera | Qué se pierde | Cuándo |
|---|---|---|---|
| A. Conservando datos (solo archivos) | La versión anterior del plugin con todos los datos actuales | Nada; quedan en la base columnas y tablas que la versión anterior ignora | Problema funcional detectado después de que el personal haya trabajado |
| B. Completa (archivos + base) | Exactamente el estado de antes de actualizar | **Todo lo registrado en GLPI después de la copia** (no solo en Lagapenak) | Fallo durante la ventana, antes de reabrir GLPI, o datos dañados |

**No uses «Desinstalar» como reversión**: desinstalar elimina los derechos de los perfiles sobre Lagapenak, los
enlaces iCal personales y la configuración de la API. Comprobado en los ensayos: tras desinstalar y reinstalar, los
usuarios pierden el permiso de pedir y gestionar préstamos.

**Protección de los derechos de los perfiles.** Tras cualquier reversión, compara los derechos con la foto previa
(apartado 4). Si no coinciden (por ejemplo, porque alguien desinstaló el plugin), restáuralos solos con la copia
del apartado 4, punto 4, sin tocar el resto de la base:

```bash
mysql -u <usuario> -p <base_glpi> < derechos-lagapenak-<fecha>.sql
php bin/console cache:clear
```

Ensayado: los derechos vuelven a quedar idénticos y el resto de la tabla `glpi_profilerights` no cambia.

### Método A: conservando datos

1. `php bin/console maintenance:enable`.
2. Retirar la carpeta actual y poner la de la versión anterior (la copia del apartado 4 o el paquete anterior):
   ```bash
   mv <glpi>/plugins/lagapenak /<copias>/lagapenak-retirada-<fecha>
   cp -a /<copias>/lagapenak-<versión anterior>-<fecha> <glpi>/plugins/lagapenak
   chown -R www-data:www-data <glpi>/plugins/lagapenak
   systemctl reload apache2        # o php-fpm
   ```
3. `php bin/console plugin:install lagapenak -u <cuenta Super-Admin> --force`,
   `php bin/console plugin:activate lagapenak` y `php bin/console cache:clear`.
4. **Solo al volver desde la 1.7.0** a una versión anterior, retirar la acción automática que la anterior no
   conoce (si no, GLPI registra un error cada día):
   ```sql
   DELETE FROM glpi_crontasks WHERE itemtype = 'PluginLagapenakLoan' AND name = 'ApiLogClean';
   ```
   La 1.7.0 la vuelve a crear si se reinstala más adelante.
5. Comprobaciones del apartado 7 con la versión anterior y `php bin/console maintenance:disable`.

Las columnas y tablas de la 1.7.0 se quedan en la base sin efecto; si más adelante se vuelve a instalar la 1.7.0,
las reutiliza. Los préstamos cancelados con la 1.7.0 se ven como «Cancelado» en la 1.6.1.

Volver a la 1.6.0 desde cualquier versión **reabre los problemas de seguridad** que corrige la 1.6.1 (y el
enlace iCal general vuelve a funcionar). Úsalo solo si la 1.6.1 impide trabajar.

### Método B: completa

1. `php bin/console maintenance:enable`.
2. Retirar la carpeta actual y restaurar la copia del apartado 4 (como en el método A, paso 2).
3. Restaurar la base de datos completa de la copia:
   ```bash
   mysql -u <usuario> -p <base_glpi> < glpi-antes-lagapenak-<versión>-<fecha>.sql
   ```
   (sobre una base vacía o recreada; consulta el procedimiento habitual de Ticgal).
4. Recargar PHP, `php bin/console cache:clear` y comprobar con la foto previa: misma versión, `state = 1` y
   mismos recuentos y derechos.
5. `php bin/console maintenance:disable`.

Restaurar solo los archivos sin `plugin:install --force` deja el plugin desactivado; restaurar solo la base deja
archivos y base de versiones distintas. Hazlo siempre como se indica.

## 10. Coordinación con la migración a GLPI 11

Lagapenak 1.6.0, 1.6.1 y 1.7.0 funcionan en GLPI 10 y en GLPI 11. Ensayo realizado: base de un GLPI 10.0.20 con
Lagapenak 1.6.0 y datos, migrada a GLPI 11.0.12 con `db:update`, y después actualización del plugin a 1.6.1 y a
1.7.0. Resultado en el apartado 12.

**Hallazgo: GLPI 11 suspende los plugins al migrar.** Tras `db:update` desde GLPI 10, GLPI 11 deja la ejecución
de todos los plugins suspendida (`plugins_execution_mode = suspended_by_update`). Mientras siga así, Lagapenak no
funciona y no se puede activar ni actualizar ningún plugin (*«Checking a plugin state is forbidden when plugins
execution is suspended»*). Hay que reanudarlos expresamente:

```bash
php bin/console plugin:resume_execution
php bin/console plugin:list          # Lagapenak debe aparecer instalado y activado
```

**Secuencias posibles** (no hace falta hacerlo todo en una ventana; entre una y otra, GLPI funciona con
normalidad):

| Opción | Ventana 1 | Ventana 2 | Ventana 3 |
|---|---|---|---|
| Prevista | Migrar GLPI 10.0.20 → 11 con Lagapenak 1.6.0; `plugin:resume_execution`; comprobar | Lagapenak 1.6.0 → 1.7.0 | — |
| Alternativa | Migrar GLPI 10.0.20 → 11 con Lagapenak 1.6.0; `plugin:resume_execution`; comprobar | Lagapenak 1.6.0 → 1.6.1 | Lagapenak 1.6.1 → 1.7.0 |

Si en una misma ventana se migra GLPI y se actualiza Lagapenak, hazlo en dos pasos separados, con sus copias y
comprobaciones: primero GLPI, comprobar, después el plugin.

Otras notas para GLPI 11:
- Conserva `glpicrypt.key` de GLPI 10 en GLPI 11 (lo exige GLPI y, desde la 1.7.0, también la API).
- La raíz web pasa a ser `<glpi>/public`. Lagapenak no necesita configuración adicional para el uso ordinario.
  Solo cuando se active la API (apartado 11), el servidor web debe pasar la cabecera `Authorization` a PHP.
- La carpeta del plugin sigue siendo `<glpi>/plugins/lagapenak`.

## 11. Lagapenak 1.7.0: cambios para el personal y API

**Cambios en el trabajo diario** (avisar al personal antes de instalar):
- Beneficiario: en los préstamos nuevos el nombre completo es obligatorio, junto con un email o un DNI/NIE
  válidos. El formulario ya no copia los datos del solicitante (hay un botón para hacerlo cuando son la misma
  persona).
- Cancelar en vez de borrar: una solicitud sin material entregado se cancela con un motivo (supervisor o el
  propio solicitante). Un préstamo cancelado no se borra ni se reabre.
- Aviso en el formulario: los datos personales del beneficiario van solo en sus campos.
- Correos y pantallas del enlace de firma, ahora en castellano.

**API G1 para Kide·artean**
- Viene **desactivada** al instalar o actualizar, y así debe quedar.
- **No es necesaria** para usar las mejoras ordinarias del plugin.
- Su activación posterior requiere: autorización de Tabakalera, revisión de seguridad, validación del DPD y
  configuración del cliente de Kide·artean (IP, entidades, permisos, caducidad), con HTTPS.
- **No se deben generar ni entregar todavía credenciales de integración**: no crear clientes ni pulsar
  «Generar clave» en *Configuración → Plugins → Lagapenak → Configurar*.
- Referencia técnica: contrato `docs/api/openapi-g1.yaml` (OpenAPI 3.1, versión 3.1.0-draft), guía
  `docs/api/integracion-kide-artean.md` y documento de privacidad `docs/api/privacidad-g1.md`, incluidos en el
  paquete. Cualquier cambio del contrato se coordinará con el equipo de Kide·artean.

## 12. Pruebas realizadas

Pruebas automáticas (`tests/g1/`, en el repositorio, fuera de los paquetes) en entornos Docker aislados, sin
conexión a la red corporativa y solo con datos ficticios.

| GLPI | Batería API (183) | Permisos (86) | General (63) | Contrato | Vectores HMAC |
|---|---|---|---|---|---|
| 10.0.11 | 183 OK | 86 OK | 63 OK | 0 errores | 28/28 × 3 |
| 10.0.20 | 183 OK | 86 OK | 63 OK | 0 errores | 28/28 × 3 |
| 10.0.28 | 183 OK | 86 OK | 63 OK | 0 errores | 28/28 × 3 |
| 11.0.12 | 183 OK | 86 OK | 63 OK | 0 errores | 28/28 × 3 |

Ensayos de actualización (copia, mantenimiento, actualización, desinstalación, reinstalación, reversión
conservando datos y reversión completa), todos sin diferencias en los datos: 1.6.0 → 1.6.1 y 1.6.1 → 1.7.0 en
10.0.11, 10.0.20, 10.0.28 y 11.0.12; 1.6.0 → 1.7.0 en 10.0.20 y 11.0.12.

Migración de GLPI 10.0.20 → 11.0.12 con Lagapenak 1.6.0 instalado y con datos: `db:update` correcto; plugins
suspendidos hasta `plugin:resume_execution`; después, Lagapenak 1.6.0 activo con préstamos y derechos de los
perfiles idénticos y sin errores de PHP. Sobre esa base migrada, 1.6.0 → 1.6.1 (permisos 86/86, general 64/64) y
1.6.1 → 1.7.0 (API 183/183, permisos 86/86, general 64/64, contrato 0 errores, vectores 28/28 × 3), con los
préstamos idénticos en cada paso.

La batería general sobre la 1.6.0 da 24 correctas y 40 fallos: son los problemas de seguridad que corrige la
1.6.1, y desaparecen al actualizar.

**Límites de lo probado**
- Solo datos ficticios y volúmenes pequeños (decenas de préstamos). No se ha medido la duración de la
  actualización con el volumen real de producción.
- MariaDB 10.11 y PHP 8.2 y 8.3. Si producción usa MySQL u otras versiones de PHP, conviene indicarlo.
- GLPI 11 probado en 11.0.9 y 11.0.12. Con otra versión hay que repetir las pruebas.
- La migración a GLPI 11 se ha ensayado solo con Lagapenak, no con el resto de plugins del GLPI corporativo.
- El correo se ha probado con un servidor de pruebas interno, no con el SMTP corporativo.
- Las pruebas de aceptación del apartado 8 deben hacerse en el GLPI corporativo tras cada ventana.

## 13. Requisitos y aprobaciones

| Requisito | Responsable | Estado |
|---|---|---|
| Publicación y autorización de instalar la 1.7.0 con la API desactivada | Tabakalera | Concedida (4 de noviembre de 2026) |
| Versión exacta de GLPI 11 | Ticgal | Comunicarla a Tabakalera. Probadas: 11.0.9 y 11.0.12; con otra, hacer las pruebas de aceptación del apartado 8 con especial atención y avisar de cualquier error |
| Ventanas de mantenimiento y copias de seguridad | Ticgal, con Tabakalera | Según este documento |
| Aviso al personal de los cambios del formulario (apartado 11) | Responsables de préstamos de Tabakalera | Antes de instalar |
| Revisión de seguridad | Responsable de seguridad designado por Tabakalera | **Necesaria antes de activar la API** |
| Validación del DPD (`docs/api/privacidad-g1.md`) | Delegado de Protección de Datos | **Necesaria antes de activar la API** |
| Activación de la API y alta del cliente Kide·artean | Super-Admin de GLPI | No hacer hasta las dos anteriores y nueva autorización |

## 14. Qué devolver a Tabakalera tras cada ventana

- Versión de GLPI y de Lagapenak instaladas (`plugin:list`) y hora de inicio y fin.
- Foto previa y foto posterior (consultas del apartado 4).
- Resultado de las pruebas de aceptación y cualquier error de los registros de GLPI.
- Si hubo reversión: método usado y motivo.
