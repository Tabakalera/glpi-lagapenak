# Lagapenak — Asset Loan Manager for GLPI

> **Español** · [English below](#english)

Plugin para GLPI 10.0.x y 11.0.x que permite gestionar préstamos de activos (ordenadores, periféricos, monitores, etc.) con albarán de entrega, firma digital, calendario y notificaciones por email.

---

## Requisitos

- GLPI 10.0.11 o superior (probado en 10.0.24) o GLPI 11.0.x (probado en 11.0.9)
- PHP >= 7.4
- MySQL / MariaDB

## Instalación

1. Copia la carpeta `lagapenak/` en `glpi/plugins/`
2. Ve a **Configuración → Plugins**
3. Localiza **Lagapenak** y pulsa **Instalar**, luego **Activar**

Para desinstalar, pulsa **Desactivar** y luego **Desinstalar** desde el mismo menú.

Desde la versión 1.6.1, desinstalar **conserva** los préstamos, sus líneas de material, los comentarios y las firmas de los albaranes, porque son el registro de las entregas. Sí se eliminan los tokens iCal (general y personales), las preferencias de visualización y el derecho `plugin_lagapenak_loan` de los perfiles. Al reinstalar, los préstamos vuelven a aparecer y hay que volver a asignar los derechos en los perfiles.

Actualizar el plugin (copiar una versión nueva y pulsar **Actualizar**) nunca borra datos: la instalación solo crea las tablas y columnas que faltan.

Borrado definitivo de los datos: solo con autorización y tras una copia de seguridad de la base de datos, ejecutar con el plugin desinstalado:

```sql
DROP TABLE glpi_plugin_lagapenak_loancomments, glpi_plugin_lagapenak_loanitems, glpi_plugin_lagapenak_loans;
DELETE FROM glpi_profilerights WHERE name = 'plugin_lagapenak_albaran';
```

---

## Funcionalidades

- Gestión de préstamos con estados: **Pendiente, En curso, Entregado, Devuelto, Cancelado**
- Asociación de múltiples activos a cada préstamo (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Fechas de entrega/devolución por activo (anulan las fechas globales del préstamo)
- Detección de conflictos al añadir activos (préstamos solapados)
- Añadir activos en bloque con comprobación de conflictos
- **Datos del beneficiario**: nombre, email y DNI/pasaporte de la persona que recoge el material, con pre-relleno desde los datos del solicitante en GLPI
- **Albarán de entrega** con firma digital en pantalla (tablet y móvil), descarga como PDF real y envío por correo electrónico
- **Solicitud de prórroga**: el solicitante puede pedir al supervisor extender la fecha de devolución; incluye observaciones y opción de aplicar el cambio a todos los activos; notificaciones por email en ambas direcciones
- **Chat / comentarios**: hilo de conversación entre solicitante y supervisores dentro del préstamo, con notificaciones por email
- **Self-Service — gestión de activos**: el solicitante puede añadir activos a un préstamo existente (en cualquier estado salvo Devuelto) y quitar los que aún estén en estado Pendiente
- **Self-Service — borrar préstamo propio**: el solicitante puede eliminar su propio préstamo si está en estado Pendiente
- Notificación por email al destinatario al crear un préstamo
- Recordatorio automático por cron 2 días antes de la fecha de devolución
- **Calendario mensual** (FullCalendar) con colores por estado y filtro por activo
- **Comprobador de disponibilidad** de activos en un rango de fechas (incluye estado Entregado con badge naranja)
- **Suscripción iCal** (Google Calendar, Outlook) con autenticación por token
- Panel de resumen (contadores por estado) en la página principal del plugin
- 3 pestañas en la página principal: **Préstamos** / **Listado por activos** / **Disponibilidad**
- 5 campos de texto libre configurables por instalación
- Control de permisos por perfil GLPI (READ / CREATE / UPDATE)
- Integración nativa con el buscador de GLPI (filtros, columnas configurables)
- Compatible con la interfaz **Self-Service** (usuarios sin perfil técnico)

---

## Permisos

Los permisos se gestionan en **Administración → Perfiles → [perfil] → pestaña Lagapenak**.

| Permiso  | Capacidad                                                                 |
|----------|---------------------------------------------------------------------------|
| READ     | Ver préstamos propios (o todos si tiene UPDATE)                           |
| CREATE   | Crear préstamos; añadir/quitar activos pendientes en préstamos propios    |
| UPDATE   | Supervisor: ver y editar todos los préstamos, generar albaranes, ver stats|

---

## Campos personalizados (field_1 a field_5)

El formulario incluye 5 campos de texto libre con etiquetas configurables. Para cambiarlos, edita `getFieldLabels()` en `inc/loan.class.php`:

```php
static function getFieldLabels() {
    return [
        'field_1' => 'Convocatoria',
        'field_2' => 'Proyecto',
        'field_3' => 'Ubicación',
        'field_4' => 'Arloa',
        'field_5' => 'Departamentua',
    ];
}
```

Estos campos aparecen en el formulario, el listado y el albarán.

---

## Datos del beneficiario

El **beneficiario** es la persona que recibe el material (artista, residente...). Normalmente **no** es el solicitante de GLPI (un trabajador de Tabakalera) ni necesariamente quien firma el albarán.

| Campo              | Columna BD          | Regla (préstamos nuevos)                                |
|--------------------|---------------------|---------------------------------------------------------|
| Nombre completo    | `beneficiary_name`  | Obligatorio                                             |
| Correo electrónico | `beneficiary_email` | Si se rellena, debe ser válido                          |
| DNI/NIE o pasaporte| `beneficiary_dni`   | Si es DNI/NIE, se comprueban formato y letra            |

- Además del nombre, hace falta **al menos un identificador válido**: email o DNI/NIE. Con pasaporte u otro documento extranjero, el email es obligatorio.
- Se comprueba en el navegador y en el servidor; si algo falla, el formulario conserva lo escrito.
- El formulario **ya no se rellena con los datos del solicitante**. Si de verdad son la misma persona, el botón *El beneficiario es el solicitante* copia sus datos.
- Los préstamos antiguos conservan sus datos tal cual y siguen consultables; la regla se aplica cuando alguien cambia el beneficiario.
- Estos datos se usan en el albarán (formulario de firma, envío por correo, documento impreso y PDF).

## Cancelar una solicitud

Pueden **cancelar** una solicitud cuyo material **no se ha entregado** el supervisor de la entidad del préstamo y el **solicitante** de su propia solicitud (botón *Cancelar solicitud*, con motivo obligatorio). La solicitud se conserva con quién, cuándo y por qué. No se puede cancelar un préstamo con el albarán firmado ni con material entregado o devuelto.

Un préstamo cancelado:
- **no se puede borrar** por ninguna vía, aunque se tenga permiso de borrar;
- **no se reabre**: no admite cambios, material, fechas, ampliaciones ni firma. Si hace falta el material, se crea una solicitud nueva.

Cancelar no es borrar: la retirada de una solicitud intacta por quien la pidió sigue existiendo.

---

## Albarán de entrega con firma digital

1. Abre un préstamo → pulsa **Albarán** (nueva pestaña)
2. El destinatario revisa activos y condiciones
3. Dibuja la firma con el dedo o el ratón
4. Pulsa **Guardar firma** (se guarda en BD)
5. Pulsa **Descargar PDF** para obtener el documento como PDF real (A4, con cabecera y pie de página)
6. O pulsa **Enviar por correo** para enviarlo como adjunto a cualquier dirección de email

**Imagen corporativa:** Sustituye `public/pics/albaran_header.png` y `public/pics/albaran_footer.png`.

**Condiciones de uso:** Edita el array `$condiciones` al inicio de `front/albaran.php`.

**Campos adicionales en el albarán:** Edita `$albaran_loan_fields` al inicio de `front/albaran.php` para controlar qué campos `field_N` aparecen en el documento:

```php
// front/albaran.php
$albaran_loan_fields = ['field_1', 'field_2']; // Convocatoria + Proyecto (por defecto)
```

Pon `[]` para no mostrar ninguno. Los labels se obtienen automáticamente de `getFieldLabels()`.

---

## Cambios en la 1.6.1 para quienes gestionan préstamos

**Envío del albarán y del enlace de firma**

| # | Situación | Qué ocurre desde la 1.6.1 | Qué hacer |
|---|---|---|---|
| 1 | Un solicitante quiere enviar el albarán firmado a alguien que no figura en el préstamo (productora, gestoría, aseguradora…) | No se permite: solo se ofrecen las direcciones del solicitante, del beneficiario y del supervisor | Lo envía el supervisor indicando el motivo, o esa persona se registra como beneficiaria |
| 2 | Un solicitante quiere copiar el enlace de firma para mandarlo por otro canal | Ya no puede ver el enlace en pantalla | El enlace se envía por correo al firmante, o lo muestra un supervisor |
| 3 | Firma una persona distinta del beneficiario anotado | El enlace solo se envía al beneficiario | El supervisor corrige el beneficiario del préstamo antes de enviar el enlace |
| 4 | El beneficiario no tiene correo (o no hay beneficiario y el solicitante no tiene correo en GLPI) | No se puede enviar el enlace | Añadir el correo, firmar en pantalla o pedir al supervisor que muestre el enlace |
| 5 | Un supervisor envía el albarán a una dirección que no figura en el préstamo | Debe indicar el motivo (mínimo 10 caracteres) | El envío y el motivo quedan registrados en Administración > Registros |

**Otros cambios**

- Un albarán firmado no se puede volver a firmar ni sustituir. Si hay un error, no se corrige desde la aplicación hasta la 1.7.0 (anulación y nueva versión).
- Un préstamo con el albarán firmado no se puede borrar, tampoco por un supervisor. Se puede seguir entregando, devolviendo, prorrogando y comentando.
- Quien pide un préstamo puede retirar su solicitud solo mientras nadie haya actuado sobre ella: sigue pendiente, no hay material entregado, no se ha enviado enlace de firma, no hay prórroga pedida y nadie más ha comentado. Para borrar préstamos de otras personas hacen falta los derechos «Actualizar» y «Purgar».
- Para consultar préstamos (lista, ficha, albarán, calendario) hace falta el derecho «Leer» de Lagapenak.
- Calendario: cada persona tiene su propio enlace de suscripción, que puede regenerar o revocar en Lagapenak > Calendario. Muestra sus préstamos y, si supervisa, los de sus entidades, según sus permisos en cada momento. Los eventos muestran el número del préstamo, su estado y el material, no el nombre del préstamo. **El enlace general anterior deja de funcionar**: quien lo use debe suscribirse con su enlace personal.
- Para pedir un préstamo hace falta el derecho «Crear» de Lagapenak en el perfil.
- Cada supervisor gestiona solo los préstamos de las entidades a las que tiene acceso.
- Solo se puede añadir material marcado con «Permitir reservas».

## Calendario y suscripción iCal

Accede desde el icono de calendario en el menú de Lagapenak.

- **Vista mensual:** un evento por préstamo, con colores por estado
- **Vista por activo:** un evento por activo, con filtro
- **Suscripción iCal:** botón en el calendario → URL para Google Calendar / Outlook
  - Supervisores: enlace general con todos los préstamos
  - Resto de usuarios: enlace personal con solo los préstamos que han pedido
  - Botón **Regenerar enlace**: crea un enlace nuevo y el anterior deja de funcionar

> Las URLs iCal solo funcionan si GLPI es accesible desde Internet.

---

## Notificaciones por email

Requiere servidor SMTP configurado en **Configuración → Notificaciones**. Se envía email al destinatario al crear el préstamo, y recordatorio automático 2 días antes de la devolución.

---

## API de integración con Kide-Artean (en desarrollo)

Kide-Artean (residencias y cesiones) **consulta** los préstamos de Lagapenak. Lagapenak ofrece la información completa y estructurada de sus préstamos; Kide-Artean decide qué incorpora, cómo identifica al beneficiario y qué asocia. Contrato completo: `docs/api/openapi-g1.yaml`; guía de integración: `docs/api/integracion-kide-artean.md`; privacidad: `docs/api/privacidad-g1.md`. Pruebas automáticas (entornos aislados, datos ficticios): `tests/g1/README.md`.

- **Qué recibe (permiso `loans:read`):** número e identificador del préstamo, referencia, entidad, ticket, solicitante y supervisor (nombre), campos de gestión (convocatoria, proyecto...), fechas previstas, ampliaciones, estado del préstamo y del material (incluidas las devoluciones con incidencia y las cancelaciones, con quién canceló), material (tipo, activo, nombre, inventario, serie), firma del albarán (si existe y cuándo), número de comentarios y **huellas** del email y del DNI/NIE del beneficiario.
- **Datos personales (permiso adicional `loans:read:personal`):** nombre y email del beneficiario y nombre de quien firmó, solo en el detalle del préstamo. Cada respuesta que los incluye queda contada en el registro.
- **Qué nunca recibe:** DNI/NIE en claro, firmas, documentos, enlaces de firma, observaciones, notas, comentarios, textos de incidencias ni motivos de cancelación.
- **Préstamos antiguos:** si el email del beneficiario coincide con el del solicitante en un préstamo anterior a esta versión, la API lo marca (`requester_autofill_possible`): pudo autocompletarse y no debe usarse para asociar por email sin verificación. Lagapenak no modifica esos datos.
- **Huellas:** se calculan con una clave de la integración. Se genera en *Configurar › Claves de identificación*, se muestra **una sola vez** y se entrega a Kide-Artean por un canal seguro. Para cambiarla, *Rotar clave*: la anterior sigue funcionando los días indicados.
- **Viene desactivada.** Un Super-Admin la activa en *Configuración › Complementos › Lagapenak › Configurar*: marca **API activada**, genera la clave de identificación y crea el cliente (permisos, entidades autorizadas, IP del servidor de Kide-Artean, caducidad de 12 meses como máximo). Un cliente nuevo solo tiene `loans:read`; el permiso de datos personales se marca expresamente. La credencial del cliente también se muestra una sola vez.
- **Sincronización:** préstamos activos desde el 01/01/2026 (configurable); los anteriores se pueden consultar por número. Si cambia lo que el cliente puede ver (entidades, subentidades o permisos), la API cambia su `scope_version` para que Kide-Artean revise los préstamos que conoce.
- Cada llamada queda en *Últimas 50 llamadas* y en `glpi_plugin_lagapenak_apilogs` (365 días por defecto). Pendiente de validación del DPD y revisión de seguridad.

---

## Estructura de archivos

```
lagapenak/
├── setup.php                      # Versión, hooks, inicialización
├── hook.php                       # Instalación / desinstalación (tablas BD)
├── README.md
├── LICENSE
├── CHANGELOG.md
├── docs/api/openapi-g1.yaml       # Contrato de la API G1 / G1 API contract
├── docs/api/g1-hmac-vectors.json  # Vectores de prueba de las huellas / fingerprint test vectors
├── docs/api/integracion-kide-artean.md # Guía de integración / integration guide
├── docs/api/privacidad-g1.md     # Privacidad (DPD) / privacy (DPD)
├── tests/g1/                     # Pruebas de G1 en entornos aislados (ver tests/g1/README.md) / G1 tests in isolated environments
├── ajax/
│   ├── asset_list.php             # Listado de activos para selector
│   ├── asset_timeline.php         # Retirado en 1.6.1 (responde 410)
│   ├── availability.php           # Disponibilidad en rango de fechas
│   ├── bulk_add.php               # Añadir múltiples activos a un préstamo
│   ├── calendar_events.php        # Eventos para el calendario FullCalendar
│   └── entity_supervisor.php      # Supervisores disponibles por entidad
├── front/
│   ├── loan.php                   # Lista de préstamos + pestañas + panel resumen
│   ├── loan.form.php              # Formulario de préstamo
│   ├── albaran.php                # Albarán de entrega con firma digital
│   ├── calendar.php               # Vista de calendario
│   ├── loan.ics.php               # Feed iCal (autenticación por token)
│   ├── api.php                    # API de integración G1 (Kide-Artean)
│   └── apiclient.php              # Configuración de la API y clientes (Super-Admin)
├── inc/
│   ├── loan.class.php             # Clase principal del préstamo
│   ├── loanitem.class.php         # Clase de ítem de préstamo
│   ├── beneficiary.class.php      # Identificación del beneficiario (huellas G1)
│   ├── loanevent.class.php        # Borrados y cambios de entidad (API)
│   ├── api.class.php              # API de integración
│   ├── apiclient.class.php        # Clientes y credenciales de la API
│   ├── profile.class.php          # Integración de permisos con perfiles GLPI
│   ├── compat.php                 # Compatibilidad GLPI 10 / GLPI 11
│   └── notification.php           # Envío de emails y cron de recordatorios
├── locales/
│   ├── en_GB.po / en_GB.mo        # English translations
│   └── es_ES.po / es_ES.mo        # Traducciones español
└── public/pics/
    ├── albaran_header.png         # Imagen de cabecera del albarán (personalizar)
    └── albaran_footer.png         # Imagen de pie del albarán (personalizar)
```

---

## Licencia

GPL v2 o posterior. Ver [LICENSE](LICENSE).

---

---

<a name="english"></a>
# Lagapenak — Asset Loan Manager for GLPI (English)

Plugin for GLPI 10.0.x and 11.0.x to manage asset loans (computers, peripherals, monitors, etc.) with delivery notes, digital signatures, calendar views and email notifications.

---

## Requirements

- GLPI 10.0.11 or later (tested on 10.0.24) or GLPI 11.0.x (tested on 11.0.9)
- PHP >= 7.4
- MySQL / MariaDB

## Installation

1. Copy the `lagapenak/` folder into `glpi/plugins/`
2. Go to **Setup → Plugins**
3. Find **Lagapenak**, click **Install** then **Enable**

To uninstall: click **Disable** then **Uninstall**.

Since 1.6.1, uninstalling **keeps** the loans, their asset lines, comments and signed delivery notes, because they are the record of the handovers. The iCal tokens (general and personal), display preferences and the `plugin_lagapenak_loan` profile right are removed. After a reinstall the loans are back and the rights have to be granted again in the profiles.

Updating the plugin (copying a new version and clicking **Update**) never deletes data: install only creates missing tables and columns.

Deleting the data for good: only with authorization and after a database backup, with the plugin uninstalled:

```sql
DROP TABLE glpi_plugin_lagapenak_loancomments, glpi_plugin_lagapenak_loanitems, glpi_plugin_lagapenak_loans;
DELETE FROM glpi_profilerights WHERE name = 'plugin_lagapenak_albaran';
```

---

## Features

- Loan management with statuses: **Pending, In Progress, Delivered, Returned, Cancelled**
- Multiple assets per loan (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Per-asset checkout/checkin dates (override global loan dates)
- Conflict detection when adding assets (overlapping loans)
- Bulk asset addition with conflict checking
- **Beneficiary fields**: name, email and ID/passport of the person collecting the equipment, pre-filled from the requester's GLPI data and fully editable
- **Delivery note** with on-screen digital signature (tablet and mobile compatible), real PDF download and email sending
- **Date extension request**: requesters can ask the supervisor to extend the return date; includes notes and apply-to-all-assets option; email notifications in both directions
- **Comments / chat**: conversation thread between requester and supervisors within each loan, with email notifications
- **Self-Service — asset management**: requesters can add assets to an existing loan (any status except Returned) and remove assets still in Pending status
- **Self-Service — delete own loan**: requesters can delete their own loan while it is still Pending
- Email notification to recipient on loan creation
- Automatic cron reminder 2 days before return date
- **Monthly calendar** (FullCalendar) with status colors and asset filter
- **Availability checker** for a given date range (Delivered loans shown with orange badge, selectable)
- **iCal subscription** (Google Calendar, Outlook) with token authentication
- Summary dashboard (counters by status) on the plugin home page
- 3 tabs on the main page: **Loans** / **Assets** / **Availability**
- 5 configurable free-text fields per installation
- Profile-based permissions (READ / CREATE / UPDATE)
- Native GLPI search engine integration (filters, configurable columns)
- Compatible with the **Self-Service** (helpdesk) interface

---

## Permissions

Managed in **Administration → Profiles → [profile] → Lagapenak tab**.

| Right  | Capability                                                            |
|--------|-----------------------------------------------------------------------|
| READ   | View own loans (all loans if also has UPDATE)                              |
| CREATE | Create loans; add/remove pending assets on own existing loans              |
| UPDATE | Supervisor: view/edit all loans, generate delivery notes, view stats       |

---

## Custom fields (field_1 to field_5)

The loan form includes 5 free-text fields with configurable labels. To change them, edit `getFieldLabels()` in `inc/loan.class.php`:

```php
static function getFieldLabels() {
    return [
        'field_1' => 'Call',
        'field_2' => 'Project',
        'field_3' => 'Location',
        'field_4' => 'Area',
        'field_5' => 'Department',
    ];
}
```

These fields appear in the form, the search list and the delivery note.

---

## Beneficiary fields

The **beneficiary** is the person who receives the material (artist, resident...). Usually **not** the GLPI requester (a Tabakalera worker) and not necessarily who signs the delivery note.

| Field          | DB column           | Rule (new loans)                              |
|----------------|---------------------|-----------------------------------------------|
| Full name      | `beneficiary_name`  | Required                                      |
| Email address  | `beneficiary_email` | Must be valid if filled in                    |
| DNI/NIE or passport | `beneficiary_dni` | A DNI/NIE is checked (format and letter)   |

- Besides the name, **at least one valid identifier** is needed: email or DNI/NIE. With a passport or foreign document, the email is required.
- Checked in the browser and on the server; when a check fails the form keeps what was typed.
- The form is **no longer pre-filled with the requester's data**. When they really are the same person, *The beneficiary is the requester* copies it.
- Older loans keep their data as is and remain available; the rule applies when someone changes the beneficiary.
- The data is used in the delivery note (signing form, email, printout and PDF).

## Cancelling a request

A request whose material was **not delivered** can be **cancelled** by the supervisor of the loan's entity and by the **requester** of their own request (*Cancel request*, reason required). It is kept with who, when and why. Signed loans and loans with delivered or returned material cannot be cancelled.

A cancelled loan:
- **cannot be deleted** by any route, even with the purge right;
- **is never reopened**: no changes, assets, dates, extensions or signature. When the material is needed again, a new request is created.

Cancelling is not deleting: requesters can still withdraw an untouched request.

---

## Delivery note with digital signature

1. Open a loan → click **Delivery note** (opens in new tab)
2. The recipient reviews the assets and conditions
3. Signs with finger or mouse on the signature pad
4. Clicks **Save signature** (stored in the database)
5. Clicks **Download PDF** to get a real A4 PDF (header/footer images included on every page)
6. Or clicks **Send by email** to deliver it as a PDF attachment to any email address

**Corporate images:** Replace `public/pics/albaran_header.png` and `public/pics/albaran_footer.png` with your own (header recommended: 260×100 px max; footer: full width).

**Loan conditions text:** Edit the `$condiciones` array at the top of `front/albaran.php`:

```php
// ── LOAN CONDITIONS ──────────────────────────────────────────────────────────
$condiciones = [
    'The recipient agrees to use the loaned equipment responsibly.',
    'The equipment must be returned by the agreed date in the same condition.',
    // add, remove or modify lines as needed
];
```

**Additional fields in the document:** Edit `$albaran_loan_fields` at the top of `front/albaran.php` to control which `field_N` columns appear in the delivery note and PDF:

```php
// front/albaran.php
$albaran_loan_fields = ['field_1', 'field_2']; // shown in document and PDF (default)
```

Set to `[]` to show none. Labels are read automatically from `getFieldLabels()`.

---

## Changes in 1.6.1 for loan managers

**Sending the delivery note and the signing link**

| # | Situation | What happens since 1.6.1 | What to do |
|---|---|---|---|
| 1 | A requester wants to send the signed delivery note to someone not on the loan | Not allowed: only the requester's, beneficiary's and supervisor's addresses are offered | The supervisor sends it stating the reason, or that person is recorded as beneficiary |
| 2 | A requester wants to copy the signing link to send it another way | The link is no longer shown to them | The link is emailed to the signer, or a supervisor shows it |
| 3 | Someone other than the recorded beneficiary signs | The link only goes to the beneficiary | The supervisor corrects the beneficiary before sending the link |
| 4 | The beneficiary has no email (or there is no beneficiary and the requester has no GLPI email) | The link cannot be sent | Add the email, sign on screen, or ask a supervisor to show the link |
| 5 | A supervisor sends the delivery note to an address not on the loan | A reason is required (10 characters minimum) | The sending and the reason are recorded in Administration > Logs |

**Other changes**

- A signed delivery note cannot be signed again or replaced. Mistakes cannot be corrected from the application until 1.7.0 (annulment and new version).
- A loan with a signed delivery note cannot be deleted, not even by a supervisor. Deliveries, returns, extensions and comments keep working.
- A requester can withdraw their request only while nobody has acted on it (still pending, no asset delivered, no signing link sent, no date request, no comment from anyone else). Deleting other people's loans needs the "Update" and "Purge" rights.
- Consulting loans (list, loan, delivery note, calendar) needs the Lagapenak "Read" right.
- Calendar: everybody has their own subscription URL, which they can regenerate or revoke in Lagapenak > Calendar. It shows their loans and, for supervisors, the loans of their entities, according to their current rights. Events show the loan number, status and assets, not the loan name. **The former general URL stops working**: its users must subscribe with their personal URL.
- Requesting a loan needs the Lagapenak "Create" right in the profile.
- Supervisors only manage loans of the entities they have access to.
- Only assets with "Allow reservations" enabled can be added.

## Calendar and iCal subscription

Access from the calendar icon in the Lagapenak menu.

- **Monthly view:** one event per loan, colour-coded by status
- **Asset view:** one event per asset, with asset filter dropdown
- **iCal subscription:** click **Subscribe** in the calendar → copy the URL into Google Calendar, Outlook or any iCal-compatible app
  - Supervisors get a general URL with every loan
  - Other users get a personal URL with only the loans they requested
  - **Regenerate URL** creates a new URL and disables the previous one

> iCal URLs only work if GLPI is accessible from the Internet (not from `localhost`).

---

## Email notifications

Requires SMTP configured in **Setup → Notifications → Email setup**.

- **On loan creation:** email sent to the recipient with loan details and a direct link
- **Return reminder:** automatic email 2 days before the return date (GLPI cron)

If sending fails, the error is logged in `files/_log/`.

---

## Kide-Artean integration API (in development)

Kide-Artean (residencies and assignments) **reads** Lagapenak loans. Lagapenak provides complete, structured loan data; Kide-Artean decides what to take in, how to identify the beneficiary and what to associate. Full contract: `docs/api/openapi-g1.yaml`; integration guide: `docs/api/integracion-kide-artean.md`; privacy: `docs/api/privacidad-g1.md`. Automated tests (isolated environments, fictitious data): `tests/g1/README.md`.

- **What it receives (scope `loans:read`):** loan number and identifier, reference, entity, ticket, requester and recipient (name), management fields (call, project...), planned dates, extensions, loan and material status (returns with incident and cancellations included, with who cancelled), assets (type, asset, name, inventory and serial numbers), whether and when the delivery note was signed, number of comments, and **fingerprints** of the beneficiary's email and DNI/NIE.
- **Personal data (additional scope `loans:read:personal`):** the beneficiary's name and email and the signer's name, in the loan detail only. Every response that carries them is counted in the log.
- **What it never receives:** DNI/NIE in clear, signatures, documents, signing links, observations, notes, comments, incident texts or cancellation reasons.
- **Older loans:** when the beneficiary's email equals the requester's on a loan created before this version, the API flags it (`requester_autofill_possible`): it may have been pre-filled and must not be used to associate by email without verification. Lagapenak does not change that data.
- **Fingerprints** use an integration key generated in *Configure › Identification keys*, shown **once** and handed to Kide-Artean through a secure channel. *Rotate key* keeps the previous one working for the given days.
- **Disabled by default.** A Super-Admin enables it in *Setup › Plugins › Lagapenak › Configure*, generates the identification key and creates the client (scopes, authorised entities, Kide-Artean server IP, expiry of 12 months at most). A new client only has `loans:read`; the personal-data scope is ticked expressly. The client credential is also shown once.
- **Sync:** loans active from 2026-01-01 (configurable); older loans can be looked up by number. When what the client may see changes (entities, sub-entities or scopes), its `scope_version` changes so that Kide-Artean reconciles the loans it knows.
- Every call is listed in *Last 50 calls* and in `glpi_plugin_lagapenak_apilogs` (365 days by default). Pending DPD validation and security review.

---

## File structure

```
lagapenak/
├── setup.php                      # Version, hooks, initialisation
├── hook.php                       # Install / uninstall (database tables)
├── README.md
├── LICENSE
├── CHANGELOG.md
├── docs/api/openapi-g1.yaml       # Contrato de la API G1 / G1 API contract
├── docs/api/g1-hmac-vectors.json  # Vectores de prueba de las huellas / fingerprint test vectors
├── docs/api/integracion-kide-artean.md # Guía de integración / integration guide
├── docs/api/privacidad-g1.md     # Privacidad (DPD) / privacy (DPD)
├── tests/g1/                     # Pruebas de G1 en entornos aislados (ver tests/g1/README.md) / G1 tests in isolated environments
├── ajax/
│   ├── asset_list.php             # Asset list for selector
│   ├── asset_timeline.php         # Retired in 1.6.1 (answers 410)
│   ├── availability.php           # Availability checker for a date range
│   ├── bulk_add.php               # Bulk add assets to a loan
│   ├── calendar_events.php        # FullCalendar event feed
│   └── entity_supervisor.php      # Available supervisors by entity
├── front/
│   ├── loan.php                   # Loan list + tabs + summary tiles
│   ├── loan.form.php              # Loan form (create / edit)
│   ├── albaran.php                # Delivery note with digital signature
│   ├── calendar.php               # Calendar view
│   ├── loan.ics.php               # iCal feed (token authentication)
│   ├── api.php                    # G1 integration API (Kide-Artean)
│   └── apiclient.php              # API settings and clients (Super-Admin)
├── inc/
│   ├── loan.class.php             # Main loan class
│   ├── loanitem.class.php         # Loan item class
│   ├── beneficiary.class.php      # Beneficiary identification (G1 fingerprints)
│   ├── loanevent.class.php        # Deletions and entity moves (API)
│   ├── api.class.php              # Integration API
│   ├── apiclient.class.php        # API clients and credentials
│   ├── profile.class.php          # GLPI profile permissions integration
│   ├── compat.php                 # GLPI 10 / GLPI 11 compatibility helpers
│   └── notification.php           # Email notifications and cron reminders
├── locales/
│   ├── en_GB.po / en_GB.mo        # English translations
│   └── es_ES.po / es_ES.mo        # Spanish translations
└── public/pics/
    ├── albaran_header.png         # Delivery note header image (customise)
    └── albaran_footer.png         # Delivery note footer image (customise)
```

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
