# CV-RECOVERY-006 — control persistente desde chat/móvil

## Principio
Reutilizar cv-ops-bridge, GitHub privado y Centro de Control sin abrir un segundo canal de administración root. La voz es entrada de intención del propietario; la acción resultante es un contrato tipado, no un shell. El token de buzón no tiene acceso al código; un token separado limitado a Casa-Viva, Actions **Read**, Metadata **Read**, permite verificar los checks. No ampliar el token del buzón.

## Capacidades y frontera
| Capacidad | Ruta | Estado |
|---|---|---|
| Salud de procesos | health | certificado VPS |
| Reconciliar reparto | dispatch_preflight | #266 integrado, pendiente config local |
| Inventario herramientas/agentes | agent_inventory | este PR, pendiente VPS |
| Actualizar módulo del puente | release_apply, SHA exacto | este PR, opt-in y requisitos CI |
| Incidencias/diagnóstico | funciones existentes del supervisor, adaptadores redaccionados | próxima capacidad |
| Programación/agentes | Dev Worker existente, sandbox, PR, revisión y CI | puerta E2/E3 pendiente |
| Despliegue Casa Viva | workflow deploy-prototype con SHA y smoke/rollback | reutilizar, no bypass |
| Despliegue VivaBot | action_deploy_vivabot + guard de receptor único y reconciliación | pendiente |
| Modificaciones de pedido | Core canónico, actor/tenant, transición permitida, idempotencia y auditoría | pendiente |
| Cobros/reembolsos | autorización específica y ledger canónico | no habilitados |
| Comandos arbitrarios, reset VPS, permisos globales | ninguna | fuera de alcance |

## Puerta de actualización
El módulo auxiliar github_release_gate.py se instala localmente y no se actualiza por el buzón. Solo acepta SHA completo, coincide con origin/main y con rama main protegida consultada mediante API, tres workflows de push del SHA exacto en success, artefacto fijo scripts/ops/github_contingency.py, compilación y copia previa con hash. No recibe ruta, script, argumentos shell, servicio, token ni contenido. Vuelve a consultar main antes de reemplazar. Conserva nonce y estado; el siguiente ciclo carga el módulo nuevo. Cualquier fallo bloquea el cambio. No equivale a certificar despliegues de otros productos ni permisos de pagos.

## Instalación única pendiente
1. Verificar main protegido y runs post-merge de los tres workflows. Si cualquiera falta, no habilitar release.
2. Preparar token de lectura de Actions y metadata de Casa-Viva, independiente del token de Issues del buzón; introducir localmente sin log ni chat, archivo .github-release.env 0600.
3. En una sola sesión SSH, extraer módulo y puerta desde SHA integrado y certificado, conservar el bridge instalado y su pausa 402; instalar preflight exacto desde VivaBot #85 y configurar las seis claves autorizadas de CRM loopback/Core. No imprimir secretos.
4. Habilitar CV_GITHUB_RELEASE_ENABLED=1 y CV_GITHUB_DISPATCH_PREFLIGHT_ENABLED=1 solo después de los checks, mediante drop-in del servicio existente. Backup y rollback bajo lock. No reiniciar WhatsApp ni VPS.
5. Certificar health, agent_inventory, dispatch_preflight y release_apply sin cambio (SHA ya instalado), luego una actualización controlada. Registrar evidencias y capacidad efectiva.
6. Para nuevas capacidades, revisión + CI + promoción exacta a main, publicar orden release_apply. La puerta no modifica permisos, servicios ni su propio código. Si se requiere una operación fuera del contrato, el propietario debe autorizar la capacidad específica.

## Seguridad
No devolver registros de chats, datos de clientes, credenciales ni outputs de shell al issue. Efectos comerciales requieren Core y control de autorización propio, no delegación genérica a root. Reutilizar los agentes CV-00/01/03/04 y sus bloqueos. Gasto adicional cero.
