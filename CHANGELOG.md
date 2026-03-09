# Changelog

All notable changes to this project will be documented in this file.

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
