# C9 — Contrato de control conversacional Casa Viva (v0, sin activar)

## Estado y objetivo
Diseño versionado. No hay todavía un conector Casa Viva instalado en ChatGPT ni una API de ejecución desplegada. Este documento NO autoriza acceso a la cola privilegiada `crm_ops_bridge`.

Objetivo: permitir consultas y solicitudes de cambios desde una conversación, con identidad verificable y evidencia de resultados, sin ejecutar comandos arbitrarios.

## Primer conjunto de herramientas (solo lectura)
| Herramienta conversacional | Acción interna permitida | Parámetros |
|---|---|---|
| `casa_viva_estado` | `bridge_status`, `pm2_status`, `supervisor_status` | ninguno |
| `casa_viva_salud` | `health`, `errors_open` | ninguno |
| `casa_viva_ingreso_lennys` | `owner_ingest_audit` | ninguno |
| `casa_viva_estado_web` | `wp_status` | ninguno |

No exponer acciones de escritura, `deploy_*`, `update_*`, `install_*`, `cv_order`, `wp_product`, `bot_access_audit` ni `dev_*` en el conector inicial.

## Seguridad obligatoria
1. OAuth/OIDC por usuario, vinculación explícita a la cuenta autorizada y sesión verificable en cada petición. La frase «soy la dueña» o un hashtag no es identidad.
2. El backend valida sujeto, audiencia, emisor, caducidad, permisos y revocación. Rechaza solicitudes sin identidad; no acepta `service_role` del navegador o del modelo.
3. Servicio intermediario separado de la base de datos: el chat nunca recibe credenciales de Supabase ni permisos directos sobre `crm_ops_bridge`.
4. Cada herramienta tiene parámetros tipados y una correspondencia fija a acciones permitidas. No aceptar `action` o comandos shell elegidos por el cliente.
5. Cuotas, límites de salida, supresión de datos personales, IDs de solicitud e historial auditable; nunca devolver claves, tokens, números personales o contenido de mensajes en diagnósticos.
6. Los resultados se presentan como `queued`, `running`, `done`, `failed` o `blocked`, con ID real y fecha; nunca interpretar `queued` como ejecución exitosa.
7. El puente existente usa credenciales privilegiadas y acepta acciones de escritura; antes de conectar ChatGPT, añadir una API de intermediación con validación de permisos en servidor y revisar políticas RLS/grants.

## Segunda etapa (cambios de código)
- Solicitud conversacional → alcance → rama y PR → CI → evaluación de riesgo → fusión bajo reglas de protección.
- Para producción: comprobación de SHA, staging, respaldo, pruebas de humo y reversión.
- Cambios de precios, pagos, comisiones, aprobaciones y mensajes a terceros: deshabilitados por defecto. Una solicitud de cambio requiere autorización explícita y verificable de la propietaria, no una inferencia del modelo.

## Pruebas de aceptación previas a activar
- Sin token, token de otro usuario, token caducado o rol insuficiente → denegado.
- Herramienta no autorizada o parámetros extra → denegado.
- Dos solicitudes con el mismo identificador → sin ejecución duplicada.
- Consulta permitida → resultado trazable, con datos mínimos.
- Credenciales y contenido privado no aparecen en respuesta ni registros.
- VPS desconectado → error/timeout claro, sin éxito ficticio.
- Ninguna operación de escritura está expuesta en la fase 1.

## Dependencias abiertas
- Identificar el proyecto Supabase canónico de Casa Viva sin asumir que alguno de los proyectos conectados lo es.
- Definir y desplegar la API autenticada / servidor MCP de Casa Viva, y conectarla explícitamente a ChatGPT.
- Confirmar que el worker del VPS está instalado, responde y aplica la versión aprobada.
- Ejecutar pruebas de identidad, autorización, filtrado de datos y diagnóstico real antes de habilitar uso operativo.
