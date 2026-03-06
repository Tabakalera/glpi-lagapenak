# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2024-03-06

### Added
- Gestión completa de préstamos de activos (Computer, Monitor, Peripheral, Phone, Printer, NetworkEquipment)
- Estados de préstamo: Pendiente, En curso, Entregado, Devuelto, Cancelado
- Asociación de múltiples activos a cada préstamo con fechas individuales de entrega/devolución
- 5 campos de texto libre configurables por instalación (`field_1` a `field_5`)
- Albarán de entrega con firma digital (compatible con tablet y móvil)
- Impresión/guardado del albarán como PDF mediante la impresión nativa del navegador
- Imágenes de cabecera y pie de página configurables en el albarán
- Condiciones de uso editables en el albarán
- Notificación por email al destinatario al crear un préstamo (usa el SMTP de GLPI)
- Vista de calendario mensual (FullCalendar) con colores por estado
- Vista de disponibilidad de activos por rango de fechas
- Suscripción a calendario externo vía iCal con autenticación por token (Google Calendar, Outlook…)
- Control de permisos por perfil GLPI
- Preferencias de visualización de columnas por defecto en el listado
