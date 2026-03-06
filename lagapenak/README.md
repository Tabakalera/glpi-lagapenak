# Lagapenak — Plugin de Gestión de Préstamos para GLPI

## Instalación

1. Copia la carpeta `lagapenak/` en `glpi/plugins/`
2. Ve a **Configuración → Plugins → Lagapenak → Instalar → Activar**

---

## Texto de condiciones del albarán

Cuando se genera un albarán de entrega (firma digital), se muestra una lista de condiciones que el destinatario debe aceptar antes de firmar.

Para personalizar ese texto, edita el array `$condiciones` al principio del archivo:

```
plugins/lagapenak/front/albaran.php
```

Busca el bloque marcado con el comentario `CONDICIONES DE PRÉSTAMO`:

```php
// ── CONDICIONES DE PRÉSTAMO ─────────────────────────────────────────────────
// Para personalizar las condiciones, edita este array.
$condiciones = [
    'El destinatario se compromete a usar el material prestado de forma adecuada y cuidadosa.',
    'El material debe ser devuelto en el plazo acordado y en el mismo estado en que fue entregado.',
    // ... añade, elimina o modifica líneas aquí
];
```

Cada elemento del array es un punto de la lista numerada que aparece en el albarán y en el PDF.

---

## Albarán de entrega con firma digital

El albarán permite registrar la entrega del material prestado con firma del destinatario directamente en pantalla (compatible con tablet/móvil).

### Flujo de uso

1. Abre un préstamo en `Lagapenak → Préstamos → [préstamo]`
2. Pulsa el botón **Albarán** (se abre en una nueva pestaña)
3. El destinatario revisa los activos y las condiciones
4. Dibuja su firma en el pad de firma con el dedo o el ratón
5. Pulsa **Guardar firma**
6. Descarga el PDF con el botón **Descargar PDF**

### El PDF incluye

- Datos del préstamo (referencia, solicitante, destinatario, fechas)
- Listado de activos entregados con fechas
- Condiciones de uso numeradas
- Imagen de la firma con nombre del firmante y fecha/hora

### La firma se puede repetir

Si el destinatario necesita volver a firmar (error, sustitución), el supervisor puede pulsar **Volver a firmar** en la página del albarán. La firma anterior se sobreescribe.

---

## Campos personalizados del préstamo

El formulario incluye 5 campos de texto libre (`field_1` a `field_5`) con etiquetas configurables.
Para cambiar sus nombres, edita `getFieldLabels()` en:

```
plugins/lagapenak/inc/loan.class.php
```
