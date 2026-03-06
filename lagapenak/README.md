# Lagapenak — Plugin de Gestión de Préstamos para GLPI

Plugin para GLPI 10.0.x que permite gestionar préstamos de activos (ordenadores, periféricos, monitores, etc.) con albarán de entrega, firma digital, calendario y notificaciones por email.

## Requisitos

- GLPI >= 10.0.0
- PHP >= 7.4

## Instalación

1. Copia la carpeta `lagapenak/` en `glpi/plugins/`
2. Ve a **Configuración → Plugins**
3. Localiza **Lagapenak** y pulsa **Instalar**, luego **Activar**

Para desinstalar, pulsa **Desactivar** y luego **Desinstalar** desde el mismo menú. Las tablas de base de datos se eliminan automáticamente.

---

## Funcionalidades principales

- Gestión de préstamos con estados (Pendiente, En curso, Entregado, Devuelto, Cancelado)
- Asociación de múltiples activos (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment) a cada préstamo
- Albarán de entrega con firma digital (compatible con tablet y móvil)
- Notificación por email al destinatario al crear un préstamo
- Calendario mensual de préstamos y vista de disponibilidad por activo
- Suscripción a calendario externo (Google Calendar, Outlook) vía iCal
- 5 campos de texto libre configurables por instalación
- Control de permisos por perfil GLPI

---

## Campos personalizados (field_1 a field_5)

El formulario de préstamo incluye 5 campos de texto libre cuyas etiquetas son configurables por instalación. Los valores por defecto son:

| Campo    | Etiqueta por defecto     |
|----------|--------------------------|
| field_1  | Convocatoria             |
| field_2  | Proyecto                 |
| field_3  | Ubicación                |
| field_4  | Referencia               |
| field_5  | Sortzailearen izena      |

Para cambiar los nombres, edita el método `getFieldLabels()` en:

```
plugins/lagapenak/inc/loan.class.php
```

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

Estos campos aparecen en el formulario de préstamo, en el listado y en el albarán de entrega.

---

## Albarán de entrega con firma digital

El albarán permite registrar la entrega del material con firma del destinatario directamente en pantalla.

### Flujo de uso

1. Abre un préstamo desde **Herramientas → Lagapenak → Préstamos → [préstamo]**
2. Pulsa el botón **Albarán** (se abre en una nueva pestaña)
3. El destinatario revisa los activos y las condiciones
4. Dibuja su firma en el pad con el dedo o el ratón
5. Pulsa **Guardar firma**
6. Desde la misma página se puede imprimir o guardar como PDF con **Imprimir / Guardar PDF** (usa la impresión nativa del navegador)

La firma queda almacenada en la base de datos. Si es necesario repetirla, pulsa **Volver a firmar**.

### Imagen de cabecera y pie de página

El albarán puede mostrar imágenes corporativas en la cabecera y en el pie. Para configurarlas, reemplaza los archivos:

```
plugins/lagapenak/pics/albaran_header.png   ← imagen superior (máx. 260×100 px recomendado)
plugins/lagapenak/pics/albaran_footer.png   ← imagen inferior (ancho completo)
```

Si estos archivos no existen, la cabecera y el pie se muestran vacíos sin errores.

### Condiciones de uso del préstamo

En el albarán se muestra una lista numerada de condiciones que el destinatario debe aceptar antes de firmar. Para personalizar el texto, edita el array `$condiciones` al inicio de:

```
plugins/lagapenak/front/albaran.php
```

Busca el bloque marcado con el comentario `CONDICIONES DE PRÉSTAMO`:

```php
// ── CONDICIONES DE PRÉSTAMO ─────────────────────────────────────────────────
// Edita este array para personalizar las condiciones de uso que aparecen
// en el albarán de entrega.
$condiciones = [
    'El destinatario se compromete a usar el material prestado de forma adecuada y cuidadosa.',
    'El material debe ser devuelto en el plazo acordado y en el mismo estado en que fue entregado.',
    // añade, elimina o modifica líneas según necesites
];
```

---

## Notificaciones por email

Al crear un nuevo préstamo, el sistema envía automáticamente un email al destinatario con los datos del préstamo (nombre, fechas, enlace).

**Requisito:** El destinatario debe tener una dirección de email configurada en su perfil GLPI, y GLPI debe tener el servidor de correo (SMTP) configurado en **Configuración → Notificaciones → Configuración del correo electrónico**.

Si el envío falla, el error queda registrado en los logs de GLPI (`files/_log/`).

---

## Calendario y suscripción iCal

El plugin incluye una vista de calendario accesible desde el icono de calendario en el menú de Lagapenak.

### Vistas disponibles

- **Por préstamo** — Un evento por préstamo, con todos los activos en la descripción
- **Por activo** — Un evento por activo por préstamo, con filtro por activo
- **Disponibilidad** — Búsqueda de disponibilidad en un rango de fechas

### Suscripción a calendario externo

En la pantalla del calendario, el botón **Suscribir calendario** muestra dos URLs de suscripción iCal:

- **Por préstamo** — Una entrada por préstamo en el calendario externo
- **Por activo** — Una entrada por activo en préstamo

Estas URLs incluyen un token de seguridad generado automáticamente durante la instalación. Se pueden usar directamente en Google Calendar, Outlook o cualquier aplicación compatible con iCal.

> **Nota:** Las URLs de suscripción solo funcionan si GLPI es accesible desde Internet (no funcionan con `localhost`).

---

## Permisos

Los permisos se gestionan por perfil en **Administración → Perfiles → [perfil] → pestaña Lagapenak**.

| Acción                    | Permiso requerido |
|---------------------------|-------------------|
| Ver préstamos             | READ              |
| Crear préstamos           | CREATE            |
| Modificar / supervisar    | UPDATE            |

---

## Estructura de archivos

```
lagapenak/
├── setup.php                  # Versión, hooks, inicialización
├── hook.php                   # Instalación / desinstalación (tablas BD)
├── README.md
├── LICENSE
├── CHANGELOG.md
├── ajax/
│   ├── asset_list.php         # Listado de activos para selector
│   ├── asset_timeline.php     # Historial de préstamos de un activo
│   ├── availability.php       # Disponibilidad en rango de fechas
│   ├── bulk_add.php           # Añadir múltiples activos a un préstamo
│   └── calendar_events.php    # Eventos para el calendario FullCalendar
├── front/
│   ├── loan.php               # Lista de préstamos
│   ├── loan.form.php          # Formulario de préstamo
│   ├── albaran.php            # Albarán de entrega con firma digital
│   ├── calendar.php           # Vista de calendario
│   └── loan.ics.php           # Feed iCal (autenticación por token)
├── inc/
│   ├── loan.class.php         # Clase principal del préstamo
│   ├── loanitem.class.php     # Clase de ítem de préstamo
│   ├── profile.class.php      # Integración de permisos con perfiles GLPI
│   └── notification.php       # Envío de email al crear préstamo
├── locales/
│   └── es_ES.php              # Traducciones español
└── pics/
    ├── albaran_header.png     # Imagen de cabecera del albarán (personalizar)
    └── albaran_footer.png     # Imagen de pie del albarán (personalizar)
```

---

## Licencia

GPL v2 o posterior. Ver archivo [LICENSE](LICENSE).
