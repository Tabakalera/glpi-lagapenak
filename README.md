# Lagapenak — Asset Loan Manager for GLPI

> **Español** · [English below](#english)

Plugin para GLPI 10.0.x que permite gestionar préstamos de activos (ordenadores, periféricos, monitores, etc.) con albarán de entrega, firma digital, calendario y notificaciones por email.

---

## Requisitos

- GLPI >= 10.0.0 (probado hasta 10.0.24)
- PHP >= 7.4
- MySQL / MariaDB

## Instalación

1. Copia la carpeta `lagapenak/` en `glpi/plugins/`
2. Ve a **Configuración → Plugins**
3. Localiza **Lagapenak** y pulsa **Instalar**, luego **Activar**

Para desinstalar, pulsa **Desactivar** y luego **Desinstalar** desde el mismo menú. Las tablas de base de datos se eliminan automáticamente.

---

## Funcionalidades

- Gestión de préstamos con estados: **Pendiente, En curso, Entregado, Devuelto, Cancelado**
- Asociación de múltiples activos a cada préstamo (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Fechas de entrega/devolución por activo (anulan las fechas globales del préstamo)
- Detección de conflictos al añadir activos (préstamos solapados)
- Añadir activos en bloque con comprobación de conflictos
- **Albarán de entrega** con firma digital en pantalla (tablet y móvil)
- Notificación por email al destinatario al crear un préstamo
- Recordatorio automático por cron 2 días antes de la fecha de devolución
- **Calendario mensual** (FullCalendar) con colores por estado y filtro por activo
- **Comprobador de disponibilidad** de activos en un rango de fechas
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
| CREATE   | Crear nuevos préstamos                                                    |
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
        'field_4' => 'Referencia',
        'field_5' => 'Sortzailearen izena',
    ];
}
```

Estos campos aparecen en el formulario, el listado y el albarán.

---

## Albarán de entrega con firma digital

1. Abre un préstamo → pulsa **Albarán** (nueva pestaña)
2. El destinatario revisa activos y condiciones
3. Dibuja la firma con el dedo o el ratón
4. Pulsa **Guardar firma** (se guarda en BD)
5. Pulsa **Imprimir / Guardar PDF** para obtener el documento

**Imagen corporativa:** Sustituye `pics/albaran_header.png` y `pics/albaran_footer.png`.

**Condiciones de uso:** Edita el array `$condiciones` al inicio de `front/albaran.php`.

---

## Calendario y suscripción iCal

Accede desde el icono de calendario en el menú de Lagapenak.

- **Vista mensual:** un evento por préstamo, con colores por estado
- **Vista por activo:** un evento por activo, con filtro
- **Suscripción iCal:** botón en el calendario → URL para Google Calendar / Outlook

> Las URLs iCal solo funcionan si GLPI es accesible desde Internet.

---

## Notificaciones por email

Requiere servidor SMTP configurado en **Configuración → Notificaciones**. Se envía email al destinatario al crear el préstamo, y recordatorio automático 2 días antes de la devolución.

---

## Estructura de archivos

```
lagapenak/
├── setup.php                      # Versión, hooks, inicialización
├── hook.php                       # Instalación / desinstalación (tablas BD)
├── README.md
├── LICENSE
├── CHANGELOG.md
├── ajax/
│   ├── asset_list.php             # Listado de activos para selector
│   ├── asset_timeline.php         # Historial de préstamos de un activo (calendario)
│   ├── availability.php           # Disponibilidad en rango de fechas
│   ├── bulk_add.php               # Añadir múltiples activos a un préstamo
│   ├── calendar_events.php        # Eventos para el calendario FullCalendar
│   └── entity_supervisor.php      # Supervisores disponibles por entidad
├── front/
│   ├── loan.php                   # Lista de préstamos + pestañas + panel resumen
│   ├── loan.form.php              # Formulario de préstamo
│   ├── albaran.php                # Albarán de entrega con firma digital
│   ├── calendar.php               # Vista de calendario
│   └── loan.ics.php               # Feed iCal (autenticación por token)
├── inc/
│   ├── loan.class.php             # Clase principal del préstamo
│   ├── loanitem.class.php         # Clase de ítem de préstamo
│   ├── profile.class.php          # Integración de permisos con perfiles GLPI
│   └── notification.php           # Envío de emails y cron de recordatorios
├── locales/
│   └── es_ES.php                  # Traducciones español
└── pics/
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

Plugin for GLPI 10.0.x to manage asset loans (computers, peripherals, monitors, etc.) with delivery notes, digital signatures, calendar views and email notifications.

---

## Requirements

- GLPI >= 10.0.0 (tested up to 10.0.24)
- PHP >= 7.4
- MySQL / MariaDB

## Installation

1. Copy the `lagapenak/` folder into `glpi/plugins/`
2. Go to **Setup → Plugins**
3. Find **Lagapenak**, click **Install** then **Enable**

To uninstall: click **Disable** then **Uninstall**. Database tables are removed automatically.

---

## Features

- Loan management with statuses: **Pending, In Progress, Delivered, Returned, Cancelled**
- Multiple assets per loan (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Per-asset checkout/checkin dates (override global loan dates)
- Conflict detection when adding assets (overlapping loans)
- Bulk asset addition with conflict checking
- **Delivery note** with on-screen digital signature (tablet and mobile compatible)
- Email notification to recipient on loan creation
- Automatic cron reminder 2 days before return date
- **Monthly calendar** (FullCalendar) with status colors and asset filter
- **Availability checker** for a given date range
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
| READ   | View own loans (all loans if also has UPDATE)                         |
| CREATE | Create new loans                                                      |
| UPDATE | Supervisor: view/edit all loans, generate delivery notes, view stats  |

---

## Custom fields (field_1 to field_5)

The loan form includes 5 free-text fields with configurable labels. To change them, edit `getFieldLabels()` in `inc/loan.class.php`:

```php
static function getFieldLabels() {
    return [
        'field_1' => 'Call',
        'field_2' => 'Project',
        'field_3' => 'Location',
        'field_4' => 'Reference',
        'field_5' => 'Custom label',
    ];
}
```

These fields appear in the form, the search list and the delivery note.

---

## Delivery note with digital signature

1. Open a loan → click **Delivery note** (opens in new tab)
2. The recipient reviews the assets and conditions
3. Signs with finger or mouse on the signature pad
4. Clicks **Save signature** (stored in the database)
5. Clicks **Print / Save as PDF** to get the document

**Corporate images:** Replace `pics/albaran_header.png` and `pics/albaran_footer.png` with your own (header recommended: 260×100 px max; footer: full width).

**Loan conditions text:** Edit the `$condiciones` array at the top of `front/albaran.php`:

```php
// ── LOAN CONDITIONS ──────────────────────────────────────────────────────────
$condiciones = [
    'The recipient agrees to use the loaned equipment responsibly.',
    'The equipment must be returned by the agreed date in the same condition.',
    // add, remove or modify lines as needed
];
```

---

## Calendar and iCal subscription

Access from the calendar icon in the Lagapenak menu.

- **Monthly view:** one event per loan, colour-coded by status
- **Asset view:** one event per asset, with asset filter dropdown
- **iCal subscription:** click **Subscribe** in the calendar → copy the URL into Google Calendar, Outlook or any iCal-compatible app

> iCal URLs only work if GLPI is accessible from the Internet (not from `localhost`).

---

## Email notifications

Requires SMTP configured in **Setup → Notifications → Email setup**.

- **On loan creation:** email sent to the recipient with loan details and a direct link
- **Return reminder:** automatic email 2 days before the return date (GLPI cron)

If sending fails, the error is logged in `files/_log/`.

---

## File structure

```
lagapenak/
├── setup.php                      # Version, hooks, initialisation
├── hook.php                       # Install / uninstall (database tables)
├── README.md
├── LICENSE
├── CHANGELOG.md
├── ajax/
│   ├── asset_list.php             # Asset list for selector
│   ├── asset_timeline.php         # Asset loan history (calendar)
│   ├── availability.php           # Availability checker for a date range
│   ├── bulk_add.php               # Bulk add assets to a loan
│   ├── calendar_events.php        # FullCalendar event feed
│   └── entity_supervisor.php      # Available supervisors by entity
├── front/
│   ├── loan.php                   # Loan list + tabs + summary tiles
│   ├── loan.form.php              # Loan form (create / edit)
│   ├── albaran.php                # Delivery note with digital signature
│   ├── calendar.php               # Calendar view
│   └── loan.ics.php               # iCal feed (token authentication)
├── inc/
│   ├── loan.class.php             # Main loan class
│   ├── loanitem.class.php         # Loan item class
│   ├── profile.class.php          # GLPI profile permissions integration
│   └── notification.php           # Email notifications and cron reminders
├── locales/
│   └── es_ES.php                  # Spanish translations
└── pics/
    ├── albaran_header.png         # Delivery note header image (customise)
    └── albaran_footer.png         # Delivery note footer image (customise)
```

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
