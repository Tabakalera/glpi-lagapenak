# Changelog

All notable changes to this project will be documented in this file.

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
