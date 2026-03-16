# Changelog

All notable changes to this project will be documented in this file.

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
