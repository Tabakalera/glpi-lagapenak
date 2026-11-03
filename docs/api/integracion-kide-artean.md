# Integración con la API de préstamos G1 de Lagapenak

Guía técnica para el equipo que implemente el consumidor en Kide·artean. Se basa exclusivamente en el contrato
`openapi-g1.yaml` (versión 3.0.0-draft) y en los vectores `g1-hmac-vectors.json`. Si algo de esta guía no
coincide con el contrato, manda el contrato.

Estado: borrador. La API no está en producción: falta la validación del DPD y la revisión de seguridad.

## 1. Qué ofrece la API

Una interfaz **de solo lectura** con la información estructurada de los préstamos de material de Lagapenak
(plugin de GLPI). Lagapenak no conoce residencias, cesiones ni personas de Kide·artean. Kide·artean decide:

- qué préstamos incorpora;
- cómo identifica a sus beneficiarios (regla G1-ID01, apartado 5);
- qué residencias o cesiones pueden corresponderles y qué asocia automáticamente;
- qué casos pasan a revisión manual;
- qué información muestra a cada uno de sus usuarios.

Hay dos niveles de consulta:

| Nivel | Operación | Para qué |
|---|---|---|
| — | `GET /v1/meta` | Estado de la API, del cliente, de las claves y de la sincronización |
| 1 · Descubrimiento | `GET /v1/loan-events?since=&limit=` | Qué préstamos han cambiado, con huellas y lo mínimo para decidir. Sin datos personales en claro |
| 2 · Detalle | `GET /v1/loans?uuids=` (máx. 50) | Detalle completo de los préstamos elegidos, reconocido o no el beneficiario |
| 2 · Detalle | `GET /v1/loans?number=` | Búsqueda manual de un préstamo por su número (exige `X-Acting-User`) |
| 2 · Detalle | `GET /v1/loans/{uuid}` | Detalle de un préstamo (404 no existe o no autorizado, 410 borrado) |

No hay listados completos, búsqueda por huella ni búsqueda por datos personales.

## 2. Conexión y credenciales

- URL base: `https://<servidor GLPI>/plugins/lagapenak/front/api.php`. Misma ruta en GLPI 10 y 11.
- Cabecera `Authorization: Bearer lgk_<8>_<40>`. Un Super-Admin de Lagapenak emite la credencial y la muestra
  una sola vez. Guárdala en el almacén de secretos.
  - Caduca como máximo a los 12 meses.
  - Se puede rotar con un periodo de gracia: mientras se usa la anterior, `meta.client.token_rotation_pending = true`.
  - Se puede revocar al instante.
- La petición debe salir de la IP del servidor de Kide·artean, que figura en la lista del cliente. HTTPS obligatorio.
- Ámbitos (`meta.client.scopes`):
  - `loans:read`: obligatorio. Préstamos, material, estados, fechas, entidad, personal de Tabakalera, campos de
    gestión y huellas del beneficiario.
  - `loans:read:personal`: lo concede expresamente un Super-Admin. Añade al detalle el nombre y el email del
    beneficiario (`beneficiary.contact`) y el nombre de quien firmó (`delivery_note.signer_name`). Sin él, esos
    campos son `null`.
- Cabeceras opcionales:
  - `X-Request-Id`: se devuelve y queda registrado. Útil para correlacionar.
  - `X-Acting-User`: UUID opaco de la persona de Kide·artean que origina la consulta. Obligatorio con `number`;
    recomendado siempre que la consulta la origine una persona (revisión manual).
- Límites: `meta.limits` (páginas de 100 como máximo, 50 UUID por lote, peticiones por minuto). Un 429 trae
  `Retry-After`. 20 credenciales fallidas desde una IP la bloquean 5 minutos.
- Errores en formato RFC 9457 (`application/problem+json`), con `code` estable y sin datos personales.

## 3. Sincronización (nivel 1)

### 3.1 Alta inicial

1. `GET /v1/loan-events?since=1970-01-01T00:00:00Z&limit=100`.
2. Procesar `data`. Guardar `next_since` **solo después** de procesar la página.
3. Repetir con `since=<next_since>` mientras `has_more = true`.

Entran los préstamos de las entidades autorizadas que estuvieron o están activos desde `meta.sync.from`
(01/01/2026), incluidos los anteriores que seguían abiertos o con material fuera. Los más antiguos se encuentran
por número.

### 3.2 Ciclo incremental

Cada pocos minutos: `GET /v1/loan-events?since=<último next_since>`, hasta `has_more = false`.

Tipos de evento:

| `event` | Significado | Qué hacer |
|---|---|---|
| `upsert` | Estado actual del préstamo (periodo, `material_status`, identificación del beneficiario) | Crear o actualizar; decidir si hace falta el detalle |
| `deleted` | Borrado en GLPI; trae `material_status_at_deletion` | Marcar como borrado; avisar si había material fuera |
| `unavailable` | Pasó a una entidad no autorizada | Dejar de mostrarlo como vigente (el detalle dará 404) |

Un préstamo vuelve a aparecer como `upsert` cuando cambian:
- el préstamo: beneficiario, fechas, referencia, campos o ampliación;
- sus líneas: estado del material;
- la firma, la cancelación o los comentarios;
- las entidades o los ámbitos del cliente, o las claves de identificación.

Si cambia solo el nombre de un usuario, una entidad o un activo en GLPI, no hay evento: el cambio se ve en la
siguiente aparición del préstamo o al pedir su detalle.

### 3.3 Garantías y obligaciones del consumidor

- Orden total `(changed_at, tipo, id)` y cursor opaco: sin duplicados ni pérdidas al paginar.
- Repetir una página con el mismo `since` devuelve lo mismo (reintentos seguros, idempotencia).
- Los cambios de los últimos `meta.sync.settle_seconds` (2 s) llegan en la siguiente lectura.
- Procesar los eventos por `loan_uuid` de forma idempotente (aplicar dos veces el mismo `upsert` no cambia nada).
- **Reconciliación**:
  - Si `meta.client.scope_changed_at` es posterior a la última reconciliación (por ejemplo, porque se redujeron
    las entidades del cliente), comprobar todos los UUID conocidos con `GET /v1/loans?uuids=` en lotes de 50.
    Los que vengan en `not_found` (`deleted` o `not_available`) ya no son visibles.
  - Recomendado además una reconciliación completa periódica (por ejemplo, semanal).

## 4. Detalle (nivel 2)

Pedir el detalle (`?uuids=`, hasta 50 por llamada) de:
- los préstamos que coinciden automáticamente;
- los que pasan a revisión manual, aunque el beneficiario no exista en Kide·artean;
- los que se van a mostrar a un usuario.

El detalle no depende de que Kide·artean haya reconocido a nadie.

Campos principales del `Loan` (ver el esquema del contrato):

| Grupo | Campos |
|---|---|
| Identificación | `uuid` (estable), `number` (visible en GLPI), `reference`, `glpi_url`, `entity {id, name}`, `ticket_id` |
| Estado | `status` (estado interno), `material_status` (calculado de las líneas; el que cuenta para cerrar) |
| Fechas | `created_at`, `period {start, end}` (previsto), `changed_at` (versión) |
| Personas de Tabakalera | `requester`, `recipient`, `cancellation.cancelled_by` — `{id, name}` de GLPI, sin email |
| Gestión | `details {call, project, location, area, department}` (texto del personal) |
| Ampliación | `extension_request {status pending/rejected, requested_end, applies_to_items}` o `null` |
| Albarán | `delivery_note {signed, signed_at, signer_name*, project, signing_link {pending, expires_at}}` |
| Cancelación | `cancellation {cancelled_at, cancelled_by}` o `null` (sin motivo) |
| Beneficiario | `beneficiary {email_status, tax_id_status, matches_requester_email, checked_on_entry, requester_autofill_possible, fingerprints[], contact*}` |
| Material | `items_summary` y `items[] {line_id, type, asset_id, name, inventory_number, serial, status, period, changed_at}` |
| Comentarios | `comments {count, last_at}` (nunca el texto) |

`*` Solo con `loans:read:personal`; si no, `null`.

Reglas útiles:
- `signed_at` es lo más cercano a la entrega efectiva: Lagapenak no guarda fechas reales de entrega ni de
  devolución, y la incidencia no tiene estado propio (solo «devuelto con incidencia» en la línea).
- `contact.email` y `contact.name` se envían tal como constan. En préstamos antiguos pueden no ser válidos:
  usa `email_status` para saberlo.
- Debes ignorar los campos que no conozcas. Dentro de `v1` solo se añaden campos opcionales o valores
  documentados.

## 5. Identificación del beneficiario (G1-ID01)

La API **no decide coincidencias**. Kide·artean aplica:

1. Huella del email. Una coincidencia inequívoca permite asociar.
2. Si no hay coincidencia inequívoca, huella del DNI/NIE.
3. Si email y DNI/NIE apuntan a personas distintas: discrepancia → revisión manual.
4. Sin coincidencia: revisión manual. Con `loans:read:personal`, el gestor ve nombre y email en el detalle.
5. Pasaportes y documentos extranjeros (`tax_id_status = other`): solo email.

Casos especiales:
- **`requester_autofill_possible = true`.** Hasta la 1.6.1, Lagapenak rellenaba nombre y email del
  beneficiario con los del solicitante (nunca el DNI/NIE). En esos préstamos antiguos el email puede no ser el
  del beneficiario real:
  - **No asociar automáticamente por el email.**
  - Si `tax_id_status = valid`, la huella del DNI/NIE sí es fiable y puede usarse según los pasos 2 y 3.
  - Si no, revisión manual.
- **`matches_requester_email = true` con `checked_on_entry = true`.** Préstamo nuevo en el que alguien eligió a
  propósito que beneficiario y solicitante son la misma persona. No es un riesgo.
- **Estados `invalid` o `missing`.** No hay huella para ese identificador; si no queda ninguno, revisión manual
  o búsqueda por número.
- **Asignación excepcional.** Cuando las huellas no permiten verificar la identidad, no se presenta como
  coincidencia confirmada. Si un gestor asigna a mano, la trazabilidad (quién, cuándo, por qué) queda en
  Kide·artean.

### 5.1 Cálculo de huellas (normalización `g1-v1`)

- Mensaje: `lagapenak-g1|v1|<email|tax_id>|<valor normalizado>`.
- Resultado: HMAC-SHA256 en hexadecimal en minúsculas, con la clave de la integración (32 bytes; se entrega en
  base64).
- **Email:**
  1. Quitar espacios ASCII (espacio, tab, CR, LF, NUL, VT) al principio y al final.
  2. Pasar A–Z a a–z, y nada más.
  3. Validar con la expresión `email_regex` del contrato (`x-identification.normalization_g1_v1`): solo ASCII, máximo 254 caracteres.
  4. Si no cumple, no hay huella.
- **DNI/NIE:**
  1. Pasar a–z a A–Z.
  2. Quitar espacios ASCII, puntos, guiones, guiones bajos y barras.
  3. DNI: 1–8 cifras y letra, completado con ceros a 8 cifras.
  4. NIE: X, Y o Z, 1–7 cifras y letra, completado a 7 cifras (para calcular la letra, X=0, Y=1, Z=2).
  5. La letra de control es obligatoria y debe ser la correcta: `TRWAGMYFPDXBNJZSQVHLCKE[número mod 23]`.
  6. Cualquier otro formato es `other` y no tiene huella.
- **Vectores:** `g1-hmac-vectors.json` trae la clave de prueba y 28 casos (10 de email y 18 de DNI/NIE) con la
  entrada, el estado, el valor normalizado y la huella esperada. Tu implementación debe reproducirlos todos
  antes de conectarse. La clave de prueba no sirve para nada real.

### 5.2 Claves

- **Entrega.** La clave la genera un Super-Admin de Lagapenak (`kid` y clave en base64), se muestra una vez y
  se entrega por un canal seguro. Nunca es una clave interna de Kide·artean.
- **`kid`.** Cada huella lleva el suyo. `meta.identification` indica `active_kid`, las claves en uso y
  `keys_changed_at`.
- **Rotación:**
  1. Lagapenak genera una clave nueva. La anterior sigue en transición (30 días por defecto, 90 como máximo) y
     la API envía huellas con las dos.
  2. Kide·artean carga la nueva clave y recalcula las huellas de sus personas antes de que acabe la transición.
  3. Todos los préstamos vuelven a llegar por el descubrimiento con las huellas nuevas.
  4. Al acabar la transición, solo queda la nueva.
- **Protección.** Las huellas son datos personales seudonimizados: no las registres en claro en tus logs.

## 6. Estados y cierre de residencias

Para decidir el cierre, usa `material_status`:

| `material_status` | Cierre |
|---|---|
| `pending_delivery` | Bloquea (G1-F02: solicitado y no entregado, pendiente hasta que se cancele) |
| `partially_delivered`, `delivered`, `partially_returned` | Bloquea (material fuera) |
| `returned` | Permite |
| `returned_with_incident` | Permite con aviso (G1-F01) |
| `cancelled` | Permite |
| Borrado (`deleted`) | Según `material_status_at_deletion` |
| Fuera de alcance, error, 5xx, 429, respuesta incompleta | No verificable: lo decide Kide·artean |

**Cancelación (G1-F05 a G1-F07).** Es una acción que se hace en Lagapenak, nunca a través de la API:
- La hacen el supervisor de la entidad o el solicitante de su propia solicitud.
- Solo es posible si no se entregó material y el albarán no está firmado.
- Un préstamo cancelado no se borra ni se reabre. Si hace falta el material, se crea una solicitud nueva, con
  otro `uuid`.

## 7. Lista de comprobación antes de conectar

- [ ] Las 28 huellas de `g1-hmac-vectors.json` reproducidas.
- [ ] Clave de la integración y credencial guardadas en el almacén de secretos; nada en el código ni en los logs.
- [ ] `next_since` guardado solo tras procesar cada página; reintentos con el mismo cursor.
- [ ] Reconciliación por lote ante `scope_changed_at` y periódica.
- [ ] `requester_autofill_possible` excluido de la asociación automática por email.
- [ ] `X-Acting-User` en las búsquedas por número y en las consultas de revisión manual.
- [ ] Datos personales (`contact`, `signer_name`) mostrados solo a quien corresponda según los permisos de
      Kide·artean, y consultas registradas.
- [ ] Manejo de 401, 403, 404, 410, 429 (con `Retry-After`) y 5xx sin perder el cursor.
