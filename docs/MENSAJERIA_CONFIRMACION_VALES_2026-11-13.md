# Mensajería — Confirmación de vales y escalamiento humano (13 nov 2026)

## Incidente por investigar
Un vale volvió al grupo cuando aparentemente ya estaba con una mensajera. Hipótesis: expiración del reloj de aceptación; no confirmada sin ID de pedido, bitácora y chats reales de VivaBot. No atribuir culpa a Ely ni a Marlon.

## Regla de operación
- El mensajero titular confirma recepción del vale con **OK**, **aceptado** o **acepto**, asociado al pedido concreto; solo su teléfono autorizado puede hacerlo. Respuesta ambigua sin pedido no debe confirmar otro pedido.
- 09:00–17:00, Cuba: si pasan 20 minutos desde la asignación y el estado sigue `assigned`, recordar al titular y pedir a la gestora que lo llame. No liberar ni republicar automáticamente.
- La gestora elige después de llamar: **mantener esperando**, **pasar al grupo**, o **reasignar**. La opción existente «Pasar al grupo ya» debe verificar propietario y estado; cada cambio queda registrado.
- 17:00–09:00: las asignaciones para el día siguiente no generan escalamiento nocturno; el reloj puede revisarlas desde las 09:00 siguientes. Las confirmaciones siguen admitiéndose a cualquier hora.
- Si el pedido ya está `accepted`, `to_store` o posterior, ningún temporizador de falta de respuesta lo publica al grupo.
- Una sola persona puede tener la carrera: transiciones atómicas y comprobación de estado justo antes de publicar. Nunca considerar un WhatsApp entregado como aceptación hasta que Core confirme la transición.

## Implementación en esta rama
- Core `class-cvd-cadena-mensajeros.php`: recordatorio no antes de 20 minutos, limitado a 09:00–17:00, sin liberación automática por tiempo; no abrir respaldo automáticamente; aviso a gestora para llamada.
- Core `class-cvd-bot-bridge.php`: POST `/bot/dispatch/{id}/confirm`, protegido por la misma autenticación existente, verifica teléfono titular y respuesta exacta, llama a la transición atómica `accept_assigned`.
- **Pendiente**: VivaBot debe interpretar respuestas OK/aceptado dentro del contexto de UN vale, llamar a este endpoint y comprobar `accepted`. Sin esto no existe confirmación automática por WhatsApp.
- **Pendiente**: auditar publicación del grupo y configuraciones reales de producción, incluidas otras rutas de publicación y mensajes reintentados; revisar si un vale estaba aceptado antes de la publicación.

## Pruebas de aceptación antes de desplegar
1. Asignado 09:00, OK correcto: `accepted`, sin aviso ni republicación.
2. Asignado 09:00, sin respuesta a las 09:20: recordatorio y aviso gestora, pedido sigue asignado.
3. Gestora «seguir esperando»: estado sin cambios, no publicación automática a las 10:00.
4. Gestora «pasar al grupo»: se libera una sola vez, titular anterior recibe aviso coherente.
5. Asignado 18:00 para mañana: sin escalamiento nocturno, revisar a las 09:00.
6. OK desde otro teléfono, texto diferente o pedido sin titular: rechazado.
7. Doble OK: respuesta idempotente, sin doble cambio ni aviso.
8. Carreras aceptadas/recogidas/entregadas: no se publican por reloj.
9. La publicación del grupo debe verificar estado vigente y evitar duplicados incluso ante reintentos del bot.

## Comunicación
No enviar disculpa causal definitiva hasta verificar el vale concreto. Explicar a gestora, mensajera, Marlon y grupo la nueva regla solo después de despliegue y pruebas de extremo a extremo. No enviar mensajes automáticamente durante el desarrollo.
