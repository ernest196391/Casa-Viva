# E3 — diagnóstico de arranque y revisión de seguridad

## Evidencia real

E2 instalado desde ab40f22d446a007be776da2440a0f9f0af19e55c. El worker quedó activo con CV_DEV_SANDBOX=0; perfil dedicado enforce, cola ociosa. El arnés E3 ejecutado como cvdev en un workspace sintético devolvió sandbox-no-operativo (exit 4, bootstrap rc 1). El error directo fue `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`.

No se ha certificado ninguna denegación del sandbox. La ausencia de una entrada de auditoría del perfil dedicado no identifica por sí sola la causa. Ubuntu 24.04 restringe user namespaces sin privilegios; el error coincide con reportes upstream, pero hay que confirmar qué perfil y operación niegan el arranque en este VPS.

Referencias primarias: [AppArmor: perfil upstream bwrap-userns-restrict](https://gitlab.com/apparmor/apparmor/-/blob/master/profiles/apparmor/profiles/extras/bwrap-userns-restrict) y [reproducción exacta con auditoría de kernel](https://github.com/openai/codex/issues/29908).

## Próximo experimento, sin modificar producción

1. Identificar versión de bwrap/kernel/AppArmor y correlacionar auditoría del kernel con un único bootstrap sintético. Registrar solo código de error y nombre de perfil, sin datos sensibles.
2. Evaluar el perfil upstream por separado. No copiarlo directamente a producción: su contrato de aislamiento puede diferir del nuestro. Una solución que permita operaciones de setup de red debe garantizar que el proceso hijo no retenga capacidades para modificar red del host y que permanezca en namespace independiente, sin interfaz externa ni acceso TCP/UDP. La configuración de loopback no equivale a acceso a Internet.
3. Si la regla necesaria amplía privilegios, presentar diff exacto y revisión humana previa. Alternativa a estudiar: aislamiento de red creado por un supervisor de confianza con límites de cgroups y transición explícita a perfil de payload restringido, sin capacidades en el proceso hijo. No usar `--share-net` sobre la red del host. No cambiar sysctl, setuid, file capabilities, perfiles de otros servicios, ni activar E4.
4. Antes de cualquier candidato en el VPS: CI verde, respaldo exacto, revisión humana, prueba exclusivamente sintética y reversión. Verificar bootstrap, ocho comprobaciones existentes, canarios de HOME/npm y otros trabajos, aislamiento del instalador npm, límites cgroup medidos y terminación de descendientes. Una prueba verde de bootstrap no implica E3 certificado.

El cambio de este PR se limita a clasificar el stderr del bootstrap con códigos constantes (no exponer logs crudos), manteniendo el veredicto y los códigos de salida fallidos. No cambia permisos, profilado, red ni ejecución autónoma. Reversión: revertir este PR; sin cambios operativos.

## Experimento alternativo real: systemd (sin modificación persistente)

VPS kernel 6.8.0-147-generic, Bubblewrap 0.9.0-1ubuntu0.3, AppArmor 4.0.1really4.0.1-0ubuntu0.24.04.8, sysctl=1. Repetición del bootstrap produjo el mismo RTM_NEWADDR; no se hallaron registros correlacionados con comm=bwrap en journal kernel. No se identifica aún la regla denegatoria exacta.

Dos unidades transitorias ejecutadas como cvdev con systemd-run --wait --pipe --collect, PrivateNetwork=yes, RestrictAddressFamilies=AF_UNIX, ProtectSystem=strict, ProtectHome=tmpfs, PrivateTmp=yes, NoNewPrivileges=yes, CapabilityBoundingSet= vacío, ReadWritePaths limitado al workspace sintético, CPUQuota=25%, MemoryMax=256M, TasksMax=16. Primera prueba exit 0, 166ms: workspace write, root/home ocultos, socket INET rechazado, cgroup observado memory.max=268435456, pids.max=16, cpu.max=25000 100000. Segunda añadió TemporaryFileSystem=/var/lib/control-os-dev/work:ro y BindPaths limitado al workspace: exit 0, 145ms; canario público de otro trabajo oculto, escritura fuera denegada, canario intacto. Worker permaneció active. No se cambió perfil, sysctl, repositorio del VPS ni unidad persistente.

**Línea recomendada:** evaluar un backend systemd fijo de supervisor de confianza que no acepte propiedades/comandos arbitrarios y mantenga E4 apagado. No es certificación E3: ProtectSystem=strict protege escrituras, no garantiza confidencialidad del resto de /etc, /var, /srv, /opt ni sockets UNIX. Exigir una vista de filesystem mínima, canarios sintéticos en rutas externas y otros trabajos, validación de symlinks/hardlinks y metadatos Git, denegación de IPC del host, verificación de entorno, npm aislado, límites y terminación de descendientes (RuntimeMaxSec y KillMode=control-group), sin acceso a API/credenciales. No ejecutar código del modelo en este backend hasta certificarlo.
