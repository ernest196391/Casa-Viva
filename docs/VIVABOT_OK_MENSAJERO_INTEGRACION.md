# VivaBot — integración obligatoria de OK para mensajeros

## Estado
Core implementado en rama PR #193; **VivaBot VPS no modificado ni desplegado**. La recepción de WhatsApp debe programarse en el repositorio/servicio real de VivaBot antes de anunciar esta función.

## Evento entrante
- Solo mensajes privados entrantes, nunca mensajes del grupo, citas de terceros ni mensajes emitidos por el propio bot.
- Identificar teléfono normalizado del remitente y vincularlo a mensajero autorizado.
- Interpretar únicamente `ok`, `aceptado` y `acepto` (sin importar mayúsculas y espacios), y solo si el chat tiene contexto de un vale asignado a ese teléfono.
- Si existen dos o más pedidos candidatos, pedir el número del vale; **no elegir arbitrariamente**.
- Si hay un pedido único, POST autenticado a `/wp-json/casa-viva/v1/bot/dispatch/{id}/confirm` con `{phone,text}`.
- Solo responder «Carrera #N confirmada» si Core devuelve `accepted` o `already_accepted`. Si devuelve conflicto o error, informar que no se confirmó y ofrecer consulta a la tienda.
- No inferir aceptación de un acuse de entrega de WhatsApp, de un mensaje leído, ni de actividad genérica en el chat.

## Regla temporal
- 09:00–17:00 Cuba: a los 20 minutos sin transición a `accepted`, el Core avisa al mensajero y a la gestora. Nunca republica por reloj.
- Después de las 17:00: no escalamiento nocturno. Las gestoras pueden asignar para mañana.
- La gestora puede decidir «grupo» o «esperar»; el bot debe autenticarla y resolver el pedido concreto antes de invocar `/bot/cadena/{id}/grupo`.

## Prevención de duplicados
- Antes de enviar cualquier oferta al grupo, volver a leer el estado Core: solo `offered` y sin titular; registrar identificador de publicación por versión de oferta. Reintentos no deben producir dos mensajes.
- Si la oferta ya se publicó y después alguien la aceptó, los reintentos no la republican.
- Para un vale asignado a la misma persona que lo creó (caso Elia), se aplican exactamente las mismas garantías de titularidad; no hay liberación automática.

## Pruebas de extremo a extremo
1. Mensajero titular recibe #123 y responde OK: Core `accepted`, confirmación única.
2. Mensajero titular responde OK sin número y tiene dos vales: solicitar aclaración.
3. Un tercero responde OK: no confirmar.
4. Mensajero sin respuesta 20 minutos: avisos, estado `assigned` y ninguna publicación al grupo.
5. Gestora ordena mantener esperando: no cambia estado.
6. Gestora ordena grupo: se libera una vez y se notifica al anterior.
7. Vale 18:30 para mañana: no alerta nocturna.
8. Reintento de publicación y eventos duplicados: una sola oferta por versión.

## Activación
No desplegar hasta identificar el servicio VivaBot, ejecutar pruebas y confirmar versión de WordPress y bot. Tras despliegue, revisar un pedido de prueba real y luego enviar el comunicado aprobado al grupo. No enviar automáticamente sin autorización y conexión verificada.
