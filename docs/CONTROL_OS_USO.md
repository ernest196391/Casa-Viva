# Control OS — uso actual

Panel: https://casaviva.company/control/

## Consultar el sistema

1. Abre el panel con tu sesión habitual de administrador.
2. Escribe `salud del sistema` y pulsa Enviar.
3. Espera la sincronización y abre Tareas. La solicitud aparecerá como Terminada con el resultado. También funcionan `estado de WordPress` y `estado del bot`.
4. Las últimas tareas terminadas permanecen visibles. Si el estado indica más de 3 minutos sin actualizar, no asumas que refleja el servidor actual.

## WhatsApp

Desde el número autorizado de Ernesto o Lennys, envía `#cambiar salud del sistema`. Si el bot solicita confirmación, responde `APLICAR CAMBIO N` usando el número indicado. `CAMBIOS` muestra tus solicitudes. `CANCELAR CAMBIO N` cancela una solicitud pendiente; no revierte una operación ya terminada.

Puede enviarse una nota de voz con la misma instrucción; el bot utiliza su transcripción existente. El procesamiento de audio es distinto del coste de ejecutar una consulta sin IA. En el panel puedes usar el dictado del teclado del teléfono; no hay grabador propio del panel en esta versión.

## Pedir cambios de código o reglas

El panel y WhatsApp registran las peticiones en la misma cola. Por ahora, registrar o autorizar la preparación NO garantiza que el código se programe y despliegue automáticamente. El consumidor real de resultados de desarrollo, la promoción tras aprobación y la certificación del aislamiento siguen pendientes. Codex 0.169.0 está instalado como cvdev, sin API key; falta autenticarlo y verificar el flujo completo.

## Aprobaciones

Las consultas conocidas anteriores se ejecutan sin aprobación financiera. En otros cambios, revisa la advertencia antes de aprobar. Autorizar preparación no debe confundirse con aprobar una versión final del código.

## Recuperación

Para una consulta fallida, conserva su número de tarea para diagnóstico. Para un cambio ya desplegado, solicita rollback al responsable técnico; cancelar la tarea no hace rollback. Los scripts de reversión exacta de las migraciones están en scripts/ops/sql/rollback-*.sql. No hay un botón de rollback general certificado en esta versión.

## Evidencia

Tarea real enviada desde el panel #131: Terminada, salud verificada y sin IA de programación. Puente #166: sincronizador actualizado con resultados recientes. Puente #167: Codex instalado sin invocar modelos ni alterar producción. Pruebas SQL cubren aprobación WhatsApp, preservación de owner_change, comillas, deduplicación y rechazo de instrucciones ambiguas. Esta evidencia no certifica todavía una nota de voz real ni programación autónoma.
