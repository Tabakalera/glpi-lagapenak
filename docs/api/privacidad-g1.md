# API G1 de Lagapenak: privacidad, accesos, auditoría y minimización

Documento para la validación del Delegado de Protección de Datos (DPD) y para la revisión de seguridad.
Describe el tratamiento de datos personales de la API de consulta de préstamos G1 (contrato
`openapi-g1.yaml`, versión 3.0.0-draft). La API no está en producción y no debe ponerse en producción sin
estas dos validaciones.

## 1. Finalidad

Kide·artean (gestión de residencias y cesiones) necesita conocer los préstamos de material de Lagapenak para:

- saber qué material tiene cada beneficiario;
- impedir el cierre de una residencia con material pendiente;
- resolver los préstamos que no encuentra automáticamente sin depender de acceder a GLPI.

Lagapenak solo entrega datos: no decide a qué residencia corresponde un préstamo ni qué persona es.

## 2. Partes y flujo

- **Origen:** Lagapenak, un complemento de GLPI de Tabakalera.
- **Destino:** el servidor de Kide·artean, que consulta la API desde una IP autorizada.
- **Sentido:** solo lectura. Kide·artean no puede crear, modificar, cancelar ni borrar nada en Lagapenak.
- **Momento:** el servidor de Kide·artean pide la información. Lagapenak no envía datos por iniciativa propia.

## 3. Datos tratados

| Clase | Datos | Dónde | Condición |
|---|---|---|---|
| Operativos | Número, identificador, referencia y entidad del préstamo; ticket; fechas; estados; material (tipo, nombre, inventario, serie); ampliaciones; si el albarán está firmado y cuándo; enlace de firma pendiente (solo sí/no y caducidad); número de comentarios | Detalle; parte en el descubrimiento | Ámbito `loans:read` |
| Personal de Tabakalera | Solicitante, supervisor destinatario y quien canceló: identificador de GLPI y nombre (sin email ni teléfono) | Solo detalle | Ámbito `loans:read` |
| Textos de gestión | Referencia del préstamo, convocatoria, proyecto, ubicación, área, departamento y proyecto del albarán. Los escribe el personal y pueden contener nombres | Solo detalle | Ámbito `loans:read` |
| Seudonimizados | Huellas HMAC-SHA256 del email y del DNI/NIE del beneficiario; estado de cada identificador; avisos de calidad | Descubrimiento y detalle | Ámbito `loans:read` |
| Personales del beneficiario | Nombre y email tal como constan en el préstamo | Solo detalle | Ámbito adicional `loans:read:personal` |
| Personales del firmante | Nombre de quien firmó el albarán | Solo detalle | Ámbito adicional `loans:read:personal` |
| **Nunca se transmiten** | DNI/NIE en claro, imagen de la firma, documentos y PDF, enlace o token de firma, documento del firmante, observaciones, notas de ampliación, notas del material (texto de las incidencias), texto de los comentarios, motivo de la cancelación, credenciales | — | — |

Interesados:
- beneficiarios: artistas, residentes y otras personas que reciben material, existan o no en Kide·artean;
- personas que firman el albarán;
- personal de Tabakalera que solicita, gestiona o cancela.

## 4. Minimización

- El descubrimiento incremental, que se consulta de forma periódica y masiva, **no lleva datos personales en
  claro**: solo huellas y estados.
- Los datos personales en claro solo aparecen en el **detalle** de préstamos concretos. Para recibirlos, el
  cliente necesita un permiso específico (`loans:read:personal`) que un Super-Admin concede expresamente. Un
  cliente nuevo no lo tiene.
- No hay listados completos. El detalle se pide por número (una persona) o por lotes de hasta 50
  identificadores.
- No se puede buscar por huella, nombre, email ni DNI.
- El DNI/NIE no sale nunca en claro. Su huella permite comparar sin revelarlo. Los pasaportes no generan huella.
- Los textos libres sensibles quedan fuera aunque existan en Lagapenak: observaciones, notas, comentarios,
  incidencias y motivos.
- El personal de Tabakalera aparece solo con su identificador de GLPI y su nombre.

## 5. Seudonimización (huellas)

- HMAC-SHA256 con una clave propia de la integración (32 bytes aleatorios). Nunca se reutiliza una clave de
  Kide·artean.
- Lagapenak guarda la clave cifrada con la clave de GLPI. Se muestra una sola vez al generarla y se entrega a
  Kide·artean por un canal seguro.
- Rotación con identificador de clave (`kid`) y periodo de transición de 30 días por defecto (90 como máximo).
- Las huellas son datos personales seudonimizados: quien tenga la clave puede comprobar si un email o un DNI
  concretos corresponden a un préstamo.

## 6. Control de acceso

- **Credencial por cliente.**
  - Se muestra una vez; solo se guarda su SHA-256.
  - Caduca como máximo a los 12 meses.
  - Se puede rotar y revocar al instante.
- **Entidades.** Cada cliente tiene sus entidades GLPI autorizadas (con o sin subentidades), comprobadas en
  cada petición. Un préstamo de otra entidad responde igual que uno inexistente.
- **Red y transporte.**
  - Solo desde las IP de origen autorizadas.
  - HTTPS obligatorio.
  - Proxies de confianza configurables.
- **Abusos.** Límite de peticiones por cliente; bloqueo de 5 minutos tras 20 credenciales fallidas desde una IP.
- **Gestión.** Solo un Super-Admin (derecho de configuración de GLPI) activa la API, crea clientes, concede el
  ámbito personal y gestiona las claves.
- **Activación.** La API viene **desactivada** al instalar o actualizar; desactivada, todo responde 404.
- **Errores.** Los mensajes de error no contienen datos personales ni los valores recibidos.

## 7. Auditoría

- Cada petición queda registrada en `glpi_plugin_lagapenak_apilogs` con:
  - cliente, fecha, IP, método y ruta;
  - los parámetros del contrato, solo si tienen el formato esperado (cualquier otro valor se registra como `?`);
  - resultado, número de préstamos devueltos y **número de préstamos con datos personales** (`personal_count`);
  - usuario de Kide·artean que origina la consulta, si se indica (`X-Acting-User`, un UUID opaco);
  - identificador de petición y duración.
- **No se registran** cuerpos, nombres, emails, DNI ni huellas.
- Conservación configurable: 365 días por defecto, 30 como mínimo. Una tarea diaria (`ApiLogClean`) borra lo
  anterior.
- Las acciones de gestión (cancelaciones, envíos del albarán) quedan en el registro de eventos de GLPI.
- Las 50 últimas llamadas se ven en la página de configuración.

## 8. Calidad de los datos y riesgos de identificación

- **Datos de préstamos antiguos.** Hasta la versión 1.6.1, el formulario rellenaba el nombre y el email del
  beneficiario con los del solicitante.
  - En esos préstamos, un email igual al del solicitante puede no identificar al beneficiario real. La API lo
    marca (`requester_autofill_possible`) y el contrato obliga a Kide·artean a no asociar por ese email sin
    verificación adicional.
  - Lagapenak **no corrige** automáticamente esos datos. Se corrigen cuando alguien edita el beneficiario.
- **Préstamos nuevos.** Exigen nombre del beneficiario y un identificador válido (email válido, o DNI/NIE con la
  letra correcta), y no se rellenan con los datos del solicitante.
- **Nombre y email** se envían tal como constan. En préstamos antiguos pueden ser incorrectos; la API indica si
  cada identificador es válido.

## 9. Conservación y supresión

- **Préstamos.** Lagapenak los conserva según sus propias reglas, que no cambian con la API. Un préstamo cancelado
  no se puede borrar: se conserva como registro.
- **Bajas.** El borrado de un préstamo se comunica a Kide·artean como evento `deleted`, sin datos personales,
  para que actualice su copia.
- **Desinstalación.** Desinstalar el complemento elimina las credenciales de los clientes y las claves de
  identificación. Los préstamos, los eventos y el registro de la API se conservan.
- **Lado de Kide·artean.** La conservación de lo recibido es responsabilidad de Kide·artean y debe definirse en
  su propio registro de actividades.

## 10. Obligaciones del consumidor (Kide·artean)

- Mostrar los datos personales solo a los usuarios que lo necesiten y registrar sus consultas.
- Indicar `X-Acting-User` en las consultas originadas por una persona.
- Guardar la credencial y la clave de identificación en un almacén de secretos. No registrar huellas ni datos
  personales en logs.
- No usar como asociación automática las coincidencias marcadas con `requester_autofill_possible`.
- Definir su propio plazo de conservación y actualizar los préstamos borrados o fuera de alcance.

## 11. Cuestiones para el DPD

1. Base jurídica y registro de actividades del tratamiento compartido entre Lagapenak y Kide·artean.
2. Adecuación del ámbito `loans:read:personal` (nombre y email del beneficiario y nombre del firmante) a la
   finalidad.
3. Envío de los textos de gestión (referencia, convocatoria, proyecto, etc.), que pueden contener nombres.
4. Envío de los nombres del personal de Tabakalera (solicitante, supervisor, quien cancela).
5. Plazo de conservación del registro de la API (365 días por defecto) y de los datos en Kide·artean.
6. Información a los interesados sobre la transmisión de sus datos a Kide·artean.
7. Tratamiento de los datos antiguos autocompletados: hoy solo se marcan, sin corregirse.
