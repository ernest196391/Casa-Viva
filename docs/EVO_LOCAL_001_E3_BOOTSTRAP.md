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
