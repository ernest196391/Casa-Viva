# EVO-001 — certificación de producción bloqueada
15 de noviembre de 2026, consultas 15:20–15:23 America/Havana (20:20–20:23 UTC).
Coordinación: Casa-Viva #231. Unidad: BLOQUE 1; los bloques 2–6 no se abren.

## Autorización y candidato
Se leyeron todos los comentarios del #231, incluidas EVO-LOCAL-001 y EVO-IA-001.
GitHub confirma #230 fusionado desde head 2b5446e1e31ebd889519b7ac215c8fd24ffcae37 a main a5551fdfb9c849ade7ecc5d76b0b703d808c2d7e; VivaBot #42 fusionado desde 992e070f90528a766da3f2ec1683c6a3693e8d26 a 3cbdeca5eaed19bdaaecbb79384999e5ab4a1b77.
La autorización existente permite instalación coordinada con gasto adicional cero y autonomía desactivada. No repetir merge ni pedir otra aprobación ordinaria. El orden exige congelar trabajos, respaldo/SHA vivo, verificar motor de imágenes y luego Core.
No se certifica CI nueva en esta unidad ni se trasladan pruebas históricas a producción.

## Consultas reales
Se usó la cola y acciones fijas existentes de crm_ops_bridge; no se añadieron acciones, permisos, tablas ni infraestructura. Todas requested_by=work-evo001-certification. Las tareas 221–226 terminaron; no quedaron despliegues encolados por esta sesión.

| Bridge ID | Acción | Resultado |
|---|---|---|
| 221 | vivabot_git_status | done, código 0. SHA vivo 3cbdeca5eaed19bdaaecbb79384999e5ab4a1b77, main, tracked limpio y 0 untracked; bridge 4.1.2 |
| 222 | pm2_status | done, código 0. VivaBot online, restart_time 4, uptime sin cambios frente a #220 |
| 223 | wp_status | done, código 0. WordPress 7.1.4, casaviva.company, plugin activo 3.14.171 |
| 224 | ai_audit | done, código 0. Cron de catalogo-fichas cada 15 minutos sigue sin comentar, con llamada IA según comentario operativo; también existe orquestador en ensayo fechado 14/15-nov |
| 225 | core_drift_audit | done, código 0 de la consulta. **Resultado de certificación negativo**: 8 archivos distintos y 2 ausentes respecto al SHA aprobado |
| 226 | casa_viva_deploy_status | failed, código 3: no encuentra credencial GitHub utilizable en VPS |

Código 0 del auditor significa que pudo comparar, NO que producción coincida.

## Incidencia crítica: Core no coincide con candidato
Auditor comparó 308 archivos Git contra 306 vivos; ambas versiones declaradas son 3.14.171.
Referencia: a5551fdfb9c849ade7ecc5d76b0b703d808c2d7e.

Distintos:
- casa-viva-dropship-core.php
- includes/class-cvd-bot-bridge.php
- includes/class-cvd-cadena-mensajeros.php
- includes/class-cvd-content-studio.php
- includes/class-cvd-palla-api.php
- includes/class-cvd-panel-bridge.php
- includes/class-cvd-photo-ai.php
- includes/class-cvd-web-push.php

Ausentes en vivo:
- includes/class-cvd-content-policy.php
- includes/class-cvd-dudas-catalogo.php

No hay archivos exclusivos de producción según este auditor. No se atribuye un SHA completo al plugin vivo. La versión no identifica sus bytes. Las diferencias de otros componentes requieren preservar y comparar, no sobrescribirlas por inferencia. No se identifica causa ni autor de la discrepancia con esta evidencia.

## Acceso y límites
La prueba SSH de solo lectura mediante alias vps y host checking estricto falló inmediatamente: Permission denied al conectar al puerto 22. No se intentó saltar la restricción.
El puente 4.1.2 no expone reinicio de casaviva-imagen ni inspección de sus procesos/locks. No se amplió la allowlist ni se ejecutaron comandos arbitrarios por SQL.
El puente sí consulta estado pero no pudo consultar despliegues por credencial GitHub ausente. No se extrajeron claves ni se trasladaron secretos a Windows.
No se probó 403 con clave válida contra un servicio potencialmente antiguo: antes debe verificarse la política cargada para garantizar que esa prueba no facture.
No se invocó recorte, proveedor IA, publicación, restauración ni reinicio. Sin gasto adicional generado por las operaciones realizadas. Esto NO certifica el gasto de cron o procesos preexistentes.
No se verifica congelación efectiva de trabajos por existencia del cron: tampoco puede afirmarse que esté ejecutándose en este instante.

## Recuperación preparada, antes de reanudar
1. Restablecer un canal operativo autorizado al VPS; no solicitar nuevamente autorización de despliegue ya concedida.
2. Inspeccionar estado y PID de casaviva-imagen, configuración y unidad sin exponer secretos; inspeccionar cron, procesos y locks de contenido. Congelar exclusivamente sus trabajos y registrar su estado previo.
3. Preservar copia del Core vivo y sus hashes, comparar los ocho archivos divergentes; revisar si corresponden a cambios válidos no integrados. No desplegar encima ni etiquetar “a5551fd instalado” sin resolver esa diferencia.
4. Registrar respaldo/SHA de VivaBot y servicio. Verificar código/política en disco, ausencia de trabajos activos y reiniciar exclusivamente casaviva-imagen. Verificar PID/fecha y salud.
5. Comprobar rechazo 403 autenticado ANTES de proveedor con evidencia de cero invocaciones, después un recorte gratuito controlado. Mantener revisión humana.
6. Recuperar la autenticación del mecanismo oficial de release, sin copiarla al trabajador local. Confirmar CI post-merge, manifest/checksum y destino autorizado. Desplegar Core solo al cerrar las condiciones previas y preservar cambios vivos legítimos.
7. Comprobar marcador SHA, hashes completos, rutas/permisos, bloqueo de pago, aprobación manual/restauración controladas y smoke; actualizar checkpoint con resultado real.
8. Solo entonces Bloque 2. La petición de continuar no elimina la compuerta de incidencia crítica.

## Acción mínima de Ernesto
Desde PowerShell del equipo, comprobar el canal con:
```powershell
ssh vps "systemctl show casaviva-imagen -p ActiveState -p SubState -p MainPID -p ExecMainStartTimestamp -p WorkingDirectory -p FragmentPath"
```
Compartir esa salida permite distinguir acceso SSH del equipo y de la sesión. Es solo lectura y no imprime claves. Si conecta, continuar en una sesión Work con acceso SSH autorizado al mismo VPS y esta coordinación. No reiniciar manualmente ni desplegar Core antes de revisar trabajos y diferencias.

## Reversión y cierre
Esta sesión solo ejecutó consultas operativas y publica documentación. No requiere rollback comercial. Revertir el PR documental retira el informe/checkpoint, conservando historial del puente.
Si se necesita revertir instalaciones anteriores, usar sus backups/SHA documentados, después de preservar el estado actual. El software anterior puede reabrir gasto; nunca usar rollback para habilitar llamadas de pago.
Estado final: BLOQUE 1 EN INVESTIGACIÓN/BLOQUEADO. EVO-001 no certificado. Sin activación de autonomía, cambios de cron, envío de mensajes, reinicio o despliegue en esta sesión.
