# Changelog

All notable changes to this project will be documented in this file.

## [1.7.0-dev] - Unreleased (branch `feature/api-g1`, number to be decided at release)

Loan query API G1 (read-only, first consumer Kide-Artean), beneficiary identification (G1-ID01) and explicit
cancellation (G1-F05 to G1-F07). Contract: `docs/api/openapi-g1.yaml` (3.0.0-draft). Built on 1.6.1, which is
unchanged. Pending: security review, DPD validation (`docs/api/privacidad-g1.md`), release authorisation.

### Added
- `front/api.php/v1/...`: read-only server-to-server API with four operations: `meta`, `loan-events` (level 1,
  light incremental discovery), `loans?number=` / `loans?uuids=` and `loans/{uuid}` (level 2, full detail of
  any loan of the authorised entities, whether or not the consumer recognised the beneficiary). Lagapenak
  does not know residencies or assignments: the consumer identifies the beneficiary and decides what to
  associate
- Full structured detail: reference, entity, linked ticket, requester and recipient (GLPI id and name),
  management fields (call, project, location, area, department), planned period, extension request (status,
  requested date, whether it applies to the assets), delivery note (signed, date, project, pending signing link
  and its expiry, never the link), cancellation (date and who), number of comments and date of the last one,
  and per asset line: line id, type, GLPI asset id, name, inventory and serial numbers, status, planned period,
  last change
- Two scopes: `loans:read` (operational data and beneficiary fingerprints) and `loans:read:personal` (adds the
  beneficiary's name and email and the signer's name to the detail). A new client gets `loans:read` only. The
  DNI/NIE is never sent in clear; signatures, documents, signing links, observations, notes, comments,
  incident texts and reasons are never sent
- Beneficiary identification: HMAC-SHA256 fingerprints of the normalised email and DNI/NIE ("g1-v1", shared
  test vectors in `docs/api/g1-hmac-vectors.json`), with a key that belongs to the integration, stored
  encrypted with GLPI's key, identified by `kid`, rotated with a transition period
- Warning `requester_autofill_possible` on older loans whose beneficiary email equals the requester's (1.6.1
  pre-filled it): the consumer must not associate them by email alone. Historical data is not changed
- Computed material status: pending delivery, partially delivered, delivered, partially returned, returned,
  returned with incident, cancelled (G1-F01, G1-F02)
- Change feed from 2026-01-01 (loans active from that date, including older loans still running), with
  deletions and moves out of the authorised entities; total order and opaque cursor; changes of comments, of
  the client's entities or scopes, or of the keys send the loans again
- Explicit cancellation of requests whose material was never handed over, by the supervisor of the loan's
  entity or by the original requester of their own request (G1-F05), reason required, checked again in the
  write itself against concurrent deliveries or signatures; the loan is kept with who, when and why
- Every loan gets a random UUID (filled in for existing loans on update), not editable
- API log without bodies, with the number of loans returned with personal data per call (`personal_count`);
  daily task `ApiLogClean`; Super-Admin page (*Setup › Plugins › Lagapenak › Configure*)
- `docs/api/integracion-kide-artean.md` (integration guide) and `docs/api/privacidad-g1.md` (for the DPD)

### Changed
- Loan form: the beneficiary is no longer pre-filled with the requester's data; a button copies it when they
  really are the same person. New loans need the beneficiary's full name (G1-F08) and at least one valid
  identifier: a valid email or a valid DNI/NIE (format and letter). Passports and foreign documents are
  accepted, with an email. Checked in the browser and on the server; what was typed is kept when the check
  fails. Editing the beneficiary of an existing loan applies the same rule; older loans keep their data until
  someone edits the beneficiary
- A cancelled loan can never be deleted (G1-F06), by any route (loan form, GLPI massive actions, GLPI API),
  even with the purge right
- A cancelled loan is never reopened nor changed (G1-F07): no edit, massive update, asset, delivery, date,
  extension or signature. A new request is created instead
- A loan can no longer be created already cancelled, nor cancelled through the loan form

### Notes
- The API is **disabled** after install or update until a Super-Admin enables it
- GLPI 11: the API path is declared stateless in `plugin_lagapenak_boot()`; GLPI 10: the script disables
  session cookies itself
- Uninstall removes API clients and integration keys (credentials) and keeps loans, the loan event log and
  the API log (records)

## [1.6.1] - Unreleased

Security release. Every issue below was reproduced first and checked after the fix with fictitious
data on an isolated GLPI 10.0.24 and GLPI 11.0.9.

### Security
- Creating a loan now requires the CREATE right, also to open the form (the "New" button was hidden, the page and its submission were not). The entity must be one the user can access and the recipient must be a supervisor of that entity. A requester needs CREATE to add assets to their own loan
- "Supervisor" of a loan now means UPDATE right **and** access to the loan's entity: a supervisor of one entity could open, edit, comment, delete and see the delivery note (ID number included) of loans in other entities by id, and create loans there
- `ajax/asset_timeline.php` retired (answers 410): no page used it and it returned every loan name and requester name of the month, all entities, to any logged-in user. `ajax/entity_supervisor.php` and `ajax/asset_list.php` now respect the user's entities
- Removing or changing an asset line now checks that the line belongs to the loan being edited (lines of other people's loans could be removed or have their status changed)
- Assets are validated on the server: loanable type, existing item, entity the user can access, "Allow reservations" enabled. Any GLPI object (a User, for instance) could be added to a loan, and the bulk-add answer revealed the full name of any user by id
- A signed delivery note can no longer be signed again or replaced: the signature is written only if there is none (conditional UPDATE), any pending signing link is voided when the note is signed, the public signing page refuses links of signed notes, no link is generated for a signed note, the loan form can no longer write signature fields (a supervisor could rewrite or back-date a signature) and the signature must be a PNG image. "Sign again" removed
- The generated signing link is no longer passed in the redirect URL (browser history, access logs)

- Recipients are checked on the server. The signed delivery note can only be sent to addresses linked to the loan (requester's and recipient's GLPI addresses, beneficiary's address); a supervisor of the loan's entity may use another address by stating a reason. The signing link only goes to the signer (the beneficiary, or the requester when there is none); showing the link on screen is reserved to supervisors. Every manual sending is recorded in the GLPI event log (user, address and, for an exceptional address, the reason)
- A loan with a signed delivery note can no longer be deleted, by anyone (supervisors included): it is the record of the handover. Deliveries, returns, extensions and comments keep working. 1.7.0 adds cancellation
- One deletion rule for every route (loan form, GLPI massive actions, API): a signed loan is never deleted; a requester may withdraw their own request (READ + CREATE, entity access) only while nobody has acted on it (still pending, no asset delivered, no signing link, no date request, no comment from anyone else); any other loan needs READ + UPDATE + PURGE and access to its entity. A profile with PURGE but without UPDATE could delete other people's loans through GLPI's "Delete permanently" massive action
- Consulting loans requires the READ right of the active profile (list, loan, delivery note, calendar, availability, iCal), on top of ownership and entity. Changing an own request (adding or removing assets, asking for a date change) also requires CREATE
- GLPI massive "update" can no longer change entity, recipient, dates or beneficiary data (it skipped the loan form's checks), nor the fields printed on a signed delivery note; entity and recipient rules now apply to every update route. Asset lines can only be changed or removed through GLPI's own routes by whoever manages their loan, and never in bulk
- iCal feeds: personal URLs only, revocable, for everybody (supervisors included). What a feed shows is computed on every request from the owner's current rights and entities: their own loans plus the loans of the entities they supervise. It stops working when regenerated or revoked, or when the account is disabled. Events no longer carry the loan name (loan number, status and assets only). The general URL of 1.6.0, which gave every loan of every entity to any supervisor, is retired: it now answers with a notice and no loan data

### Changed
- Uninstalling the plugin keeps the loans, asset lines, comments and signed delivery notes (it used to drop every table). iCal tokens are still removed. See README, "Installation", for deleting the data for good

### Known open items
- Subscribers of the general iCal URL of 1.6.0 have to subscribe again with their personal URL (Lagapenak > Calendar > Subscribe)
- The signed PDF is still regenerated from current loan data; immutable signed versions come in 1.7.0
- The audit trail of manual sendings lives in the GLPI event log, whose cleanup task (if enabled) keeps 30 days by default

## [1.6.0] - 2026-09-23

### Added
- GLPI 11 compatibility (tested on GLPI 11.0.9). The plugin keeps working on GLPI 10.0.x (tested on 10.0.24); minimum is now 10.0.11
- `inc/compat.php`: helpers that pick the right GLPI API for each version (URLs, public files, logging, emails, access denied)
- Conflict warning when extending dates: editing the loan dates (with "apply to assets"), editing an asset's dates, requesting a date extension and approving it now check whether the assets are booked in other loans for the new period. The change is not saved until the user confirms with "Save / Send / Approve anyway"
- The supervisor's date extension request box lists the assets that would overlap if the request is approved
- Personal iCal URL: non-supervisors can subscribe to a calendar with only the loans they requested (new table `glpi_plugin_lagapenak_icstokens`). Supervisors keep the general URL with every loan
- "Regenerate URL" button in the calendar subscription panel (general URL for supervisors, personal URL for everybody else)

### Changed
- Images moved from `pics/` to `public/pics/` (GLPI 11 only serves static files from the plugin `public/` folder)
- All emails are sent through a single helper that uses Symfony Mailer on GLPI 11 and PHPMailer on GLPI 10
- Font Awesome for the standalone delivery-note and signing pages is now loaded from jsDelivr (GLPI 11 no longer ships it as a separate file)
- The public signing page and the iCal feed are registered as "no login required" in the GLPI 11 firewall

### Fixed
- Security: when creating a loan, signature, delivery-note and date-request fields sent in the request were saved as-is, so a non-supervisor could create a loan already "Returned" and "signed". These fields are now ignored and non-supervisors always create "Pending" loans
- An asset returned early (item status "Returned") kept blocking other loans until the planned end date of its loan
- Availability checker let users select assets in "Delivered" loans that were then rejected when adding them. Delivered assets now count as occupied everywhere
- Privacy: the general iCal subscription URL (which gives access to every loan) was shown to every user; it is now only shown to supervisors, and the session-based feed only returns the user's own loans for non-supervisors
- Privacy: non-supervisors could see other people's loan names in the "fill dates from loan" dropdown, the availability data and conflict messages; they now only see status and dates
- Loan name was inserted unescaped in the "new loan" and "return reminder" emails (only an issue on GLPI 11, which no longer stores HTML-encoded values)
- Security: the public signing page accepted `token=NULL`, which matched any loan without an active signing link and allowed signing it. Tokens are now strictly validated
- Plugin update on GLPI 11 failed with a duplicate-key error when re-creating existing profile rights
- Approving a date extension request with no pending request emptied the loan end date
- Return reminder email contained a relative (non-clickable) link to the loan
- iCal events now contain absolute URLs so external calendar apps can open them
- On GLPI 11, a successfully sent delivery-note or signing-link email was reported as an error

---

## [1.5.0] - 2026-07-07

### Added
- "Pending signature" tile in the loan list summary, with count and click-through filter; new "Signed" search field
- Status filter for the "fill dates from loan" dropdown in the availability checker

### Changed
- Return reminder now emails the beneficiary and falls back to the requester (previously always the requester, or the GLPI destinatario in an interim version)
- Comment/chat notifications now alternate between requester and destinatario, instead of notifying all supervisors
- Signed delivery note email now always includes kontratazioak@tabakalera.eus as an additional recipient

### Fixed
- Spanish translations added for new labels ("Pendiente de firma", "Firmado")

---

## [1.4.1] - 2026-04-08

### Fixed
- Two date columns (`sign_token_expires`, `requested_date_end`) were created as `DATETIME` instead of `TIMESTAMP`, causing a GLPI migration warning on the dashboard. The upgrade routine now converts them automatically.

---

## [1.4.0] - 2026-04-07

### Added
- **Date extension request**: self-service users can request a change to the loan end date; request includes notes and an option to apply the new date to all assets. Supervisor receives an email notification and can approve or reject directly from the loan form.
- **Comments / chat**: conversation thread between the requester and supervisors within each loan, with email notifications in both directions.
- **Self-service: delete own loan**: requesters can delete their own loan while it is still in Pending status.
- **Self-service: add assets (CREATE)**: requesters can add new assets to an existing loan in any status except Returned.
- **Self-service: remove pending assets (CREATE)**: requesters can remove individual assets that are still in Pending status (not yet delivered), regardless of the overall loan status.
- **Availability — Delivered state**: loans in Delivered status now appear in the availability checker with an orange "Entregado" badge and remain selectable for date pre-fill.

### Fixed
- Asset dates inherited from the loan (no explicit per-asset dates set) are now displayed in the same style as explicit dates — no longer shown as muted/grey text.
- End-date sync: when saving a loan, a checkbox allows applying the loan end date to all associated assets at once.
- Email links now use `$CFG_GLPI['url_base']` to guarantee correct absolute URLs in all environments.

### Changed
- Permissions table updated: CREATE now also covers adding/removing own pending assets on existing loans.

---

## [1.3.1] - 2026-03-24

### Fixed
- Simplified interface (helpdesk profiles): plugin now stays under "Complementos" instead of being relocated to "Herramientas" — `Html::helpHeader()` is used when the active profile interface is `helpdesk`
- Simplified interface: added "+ New loan" button directly in the page content, since the standard toolbar is not rendered in helpdesk layout
- Email notification and return reminder links were broken (`http://plugins/lagapenak/...`) — replaced `Plugin::getWebDir()` with `$CFG_GLPI['url_base']` to guarantee an absolute URL in all contexts
- Helpdesk sidebar entry ("Complementos") is now hidden for profiles without READ permission on the plugin — hook moved to `plugin_init_lagapenak()` with permission check

---

## [1.3.0] - 2026-03-16

### Added
- **Beneficiary fields on loan form**: three new fields — Full name, Email and Passport / ID — to identify the person who physically collects the equipment (may differ from the requester). Pre-filled from the requester's GLPI data on new loans but fully editable.
- **Beneficiary fields in delivery note**: the albarán now pre-fills the signing form from the loan's beneficiary data; the email modal defaults to `beneficiary_email`; and the rendered document (HTML + PDF) shows email and the fields listed in `$albaran_loan_fields`.
- **`$albaran_loan_fields` config array** in `front/albaran.php`: controls which `field_N` columns appear in the delivery note document and PDF. Defaults to `['field_1', 'field_2']` (Convocatoria + Proyecto).
- **New search / list columns**: Full name (`beneficiary_name`), Passport / ID (`beneficiary_dni`) and Email address (`beneficiary_email`) are now available as filterable columns in the GLPI search engine (option IDs 22, 23, 24).
- **DB auto-migration**: `hook.php` adds the three new columns automatically on fresh install and on upgrade (idempotent `ALTER TABLE … ADD COLUMN IF NOT EXISTS`).

### Changed
- Default label for `field_4` changed from "Referencia" to **"Arloa"**.
- Default label for `field_5` changed from "Sortzailearen izena" to **"Departamentua"**.

---

## [1.2.0] - 2026-03-16

### Added
- **Delivery note PDF**: generate and download a real A4 PDF (via TCPDF) with header image, footer image on every page, digital signature and loan details
- **Send delivery note by email**: send the PDF as an attachment to any email address directly from the albarán page
- **Full internationalisation (i18n)**: all UI strings wrapped in `__()` / `_n()`; locale files `en_GB.po/.mo` and `es_ES.po/.mo` included
- **Self-Service albarán access**: recipients (`users_id_destinatario`) can now view and sign the delivery note from the Self-Service interface
- **"Overdue" tile always visible**: the Vencidos tile is now shown to all users even when the count is zero

### Fixed
- Self-Service users blocked from albarán: `$loan->can($ID, READ)` only checked the requester, not the recipient; replaced with explicit `hasPluginRight()` check
- Self-Service menu placement: plugin now renders under "Herramientas" (central layout) instead of the helpdesk "Complementos" layout
- "Sign again" canvas had zero dimensions when hidden on page load; `resizeCanvas()` is now called when the form becomes visible
- `writeHTML()` `$reseth=true` caused the signature image to overlap the document content in PDF output
- Transparent canvas PNG rendered as black in TCPDF; fixed by compositing a white background with GD before passing to TCPDF

---

## [1.1.0] - 2026-03-09

### Added
- Página principal con 3 pestañas: **Préstamos**, **Listado por activos**, **Disponibilidad**
- Panel de resumen con contadores por estado (Todas, Pendiente/En curso, Entregadas, Devueltas, Vencidas, Mis préstamos)
- Pestaña **Listado por activos**: estadísticas de uso por activo (total préstamos, días, último préstamo, estado actual)
- Pestaña **Disponibilidad** integrada en la página principal (sin necesidad de ir al calendario)
- Selector de entidad en el formulario de préstamo con carga dinámica de supervisores por entidad (AJAX)
- Acceso al plugin desde la interfaz **Self-Service** (usuarios sin perfil técnico)
- Restricción de búsqueda por usuario para perfiles sin permiso de supervisión (`addDefaultWhere`)

### Fixed
- Página en blanco para perfiles Técnico: `fetchAssoc()` sobre resultado `false` al fallar la consulta SQL de contadores
- Contadores del panel siempre a 0: `getEntitiesRestrictRequest` con `is_recursive=true` generaba SQL con columna inexistente en la tabla del plugin
- Calendario en blanco para perfiles Técnico: mismo bug de `is_recursive` en `calendar_events.php`
- Disponibilidad sin datos para perfiles Técnico: mismo bug en `availability.php`

### Changed
- Tiles del panel de resumen ahora usan `Toolbox::getFgColor()` para contraste automático de texto
- Eliminada dependencia del módulo Dashboard de GLPI para el panel de resumen

---

## [1.0.0] - 2024-03-06

### Added
- Gestión completa de préstamos de activos (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Estados de préstamo: Pendiente, En curso, Entregado, Devuelto, Cancelado
- Asociación de múltiples activos a cada préstamo con fechas individuales de entrega/devolución
- Detección de conflictos al añadir activos
- Añadir activos en bloque
- 5 campos de texto libre configurables por instalación (`field_1` a `field_5`)
- Albarán de entrega con firma digital (compatible con tablet y móvil)
- Impresión / guardado del albarán como PDF mediante impresión nativa del navegador
- Imágenes de cabecera y pie de página configurables en el albarán
- Condiciones de uso editables en el albarán
- Notificación por email al destinatario al crear un préstamo (usa el SMTP de GLPI)
- Recordatorio automático por cron 2 días antes de la fecha de devolución
- Vista de calendario mensual (FullCalendar) con colores por estado
- Vista de disponibilidad de activos por rango de fechas
- Suscripción a calendario externo vía iCal con autenticación por token (Google Calendar, Outlook…)
- Control de permisos por perfil GLPI (READ / CREATE / UPDATE)
- Preferencias de visualización de columnas por defecto en el listado
