# ENERGY-PILOT-008 — Puerta de integración y prueba controlada

Fecha: 2026-11-18. Alcance: revisión de código y CI de PR Casa-Viva #255 y VivaBot #53. Sin merge, despliegue, mensaje WhatsApp, escritura en CRM ni gasto nuevo.

## Evidencia comprobada
- Casa Viva SHA 9e8229f4a57664c5de0ff0f3e571537bfd71323f: CI de aplicación, release y staging en success (Actions 44369159995, 44369159968, 44369160033).
- VivaBot SHA 3aa82ff382bafc8125b1f89676cff533725e1b92: CI VivaBot verification y Pedidos/vales en success (44365857410 y 44365857354).
- Los dos PR están abiertos en draft. No hay prueba de despliegue de /energia/, conexión real CRM o WhatsApp.

## Hallazgos bloqueantes observados en el código
1. **Emisión sin vinculación obligatoria:** createProposalStore.issue permite caseId=null y aprobadoBy es una cadena libre; no consulta autorización. server.mjs solo comprueba estado/versión si p.caseId es verdadero. Para producción, prohibir emisión anónima y exigir aprobación autenticada vinculada al expediente, huella y versión.
2. **Visitas duplicables tras reinicio:** server.mjs mantiene el mapa visits en RAM. El id determinista es apto para un contrato idempotente, pero la escritura de visita debe hacer UNIQUE/UPSERT en el adaptador CRM real.
3. **Conector no existente:** la ruta /cases recibe notify callback inyectable; no demuestra cola real ni reintento persistente. Un fallo de notify devuelve 201 y notified=false. Se necesita outbox/reintento duradero con estado visible para el supervisor.
4. **Persistencia de archivos de piloto:** createCaseRepository y createProposalStore escriben ficheros locales. La versión en fichero no resuelve coordinación entre múltiples procesos, permisos del usuario web, respaldos y control de acceso. Diseñar un adaptador autenticado sobre la fuente canónica, sin ampliar permisos.
5. **Entrada pública:** POST /cases no tiene aún rate limiting, mitigación de bots, consentimiento visible ni política de retención. Debe resolverse antes de publicar.
6. **Ruta aislada VivaBot:** su PR declara flag apagado y coincidencia exacta con contacto de prueba; no activar hasta comprobar un número autorizado y la URL real. No usar números de clientes por defecto.
7. **Seguridad eléctrica y comercial:** predimensionamiento no equivale a proyecto técnico; las ofertas de proveedores, compatibilidad eléctrica, protecciones, componentes, logística y garantía real deben validarse antes de aprobar.

## Secuencia exacta de certificación
A. Repetir CI en los SHA candidatos; revisar cambios de PR, secret scanning y pruebas de acceso no autorizado.
B. Implementar autorización obligatoria de emisión y outbox/idempotencia en adaptador CRM, con pruebas de concurrencia, reinicio, caducidad, reapertura y rechazo de actor no autorizado.
C. Preparar despliegue aislado y autenticado con variables no secretas por defecto OFF; registrar respaldos, rutas, hash instalado y reversión. No tocar VivaBot de producción antes de certificar Core.
D. Verificar en staging el diagnóstico en 320, 360, 393 px y Android antiguo: 1 CTA por paso, no scroll lateral, inputs accesibles, teclado, consumo, privacidad, velocidad de carga y fallback.
E. Realizar prueba en vivo solo con el número de ensayo aprobado: pregunta energética -> VivaBot entrega URL -> formulario -> expediente CRM -> aviso supervisor -> cotización ficticia -> revisión y aprobación humana -> enlace privado -> selección -> visita. Registrar IDs y evidencia de cada salto sin PII pública.
F. Probar cuatro controles negativos: contacto no autorizado, oferta agotada, presupuesto antiguo y visita repetida tras reinicio. Todos deben quedar bloqueados.
G. Habilitar comercialmente solo mediante aprobación nueva, después de certificar el piloto; nunca por CI verde o por la aprobación de una oferta ficticia.

## Reversión
Mantener flags OFF; si se instala versión piloto, retirar ruta y restaurar los archivos y servicios desde respaldo exacto, verificar que el tráfico comercial anterior no cambió. No reiniciar el VPS completo.

## Estado de esta revisión
CI previa verde; integración real, aprobación autenticada, anti-duplicado durable y UX móvil real NO certificados. Próxima tarea: implementar y probar bloqueos 1–5 en el PR #255; después conectar CRM en staging. Coste incremental: no se ha contratado servicio nuevo.
