## CV-AUTO-002 — autonomous pilot blocker (2026-11-25)

- Host pilot request 7178856863 returned blocked automatically in 78 seconds; rollback 0, profile cleanup confirmed, both existing services active. Journal: transient launcher exited 1 after ~1s, no AppArmor denials in checked interval.
- db9e038/ae953f0 retain numeric launcher outcome and sanitized reason. 82a1861 fixes concrete regression: exact host-only @.service and @.timer paths rejected by confined Git extractor; exclude only those paths, retain all other validation. Regression added. Cause is consistent with host failure but not confirmed until new isolated pilot. CI pending. No production activation or extra cost.

## CV-AUTO-002 — installer regression gate (2026-11-25)

- CI 45726940437, 45727577753 and 45727839523 completed successfully; AppArmor fixture ownership correction and installer rollback baseline green.
- f64e0c76 requires exact remote request and at least 330 seconds remaining across local and remote authorization; 4c99390 adds eight deadline regressions.
- 483c464 adds five installer syntax/static contract regressions. These do not certify real systemd, signal handling or host cleanup. New CI and isolated host pilot pending. Create a fresh mailbox request only after reviewed revision is green. No production activation or new expenditure.

## CV-AUTO-002 — exclusive AppArmor lease (2026-11-25)

- 5fc0e2f/7947e79: cooperative root-owned global profile lease, digest verification and exact cleanup following unit/Docker removal.
- 04221cf/cde6fa5: receiver acquisition and service cleanup allowance; five regression cases added to existing CI suite.
- Host compatibility, full CI and forced interruption test remain pending. Existing profile names are shared; lease is cooperative, not protection against unrelated privileged writers. No production activation or new cost.

## CV-AUTO-002 — isolated receiver CLI and one-shot timer (2026-11-25)

- cb98118 connects receiver to existing disposable Docker integral coordinator, root-owned arm and exact clean local revision.
- 0339e46 reserves 240s arm margin and records acknowledgement count; a458a5c/9f98808 add inactive instance service and one-shot timer templates without Install section.
- Added three adapter/ack/timer regressions to existing CI suite. No host activation. Before pilot: validate systemd nesting, Docker resource cleanup on forced timeout, profile ownership/cleanup, credential file handling, timer expiry and rollback; final previous host exit still unobserved. No production activation, zero extra spend.

## CV-AUTO-002 — integral receipt follow-up (2026-11-25)

- CI 45724775684 all five jobs passed; Docker regression run 45725133048 PostgreSQL passed, remaining jobs nonterminal.
- Hostinger terminal still displays same pre-final output; INTEGRAL_EXIT, durable_rows and cleanup not observed. Do not replay request 7178243950.
- Isolated unattended receiver acceptance and rollback gates documented; no timer activation, no production change or new expense.

## CV-AUTO-002 — real mailbox round trip PASS receipt (2026-11-25)

- Exact revision 0ba3f16387aa28440b9569e004d5690aff3df79e, Hostinger disposable clone /tmp/cv-integral.5GSAOi/source.
- Request issue #3 comment 7178243950 created 23:57:36 UTC, immutable owner ID 175450950, OWNER association, unedited. Real automatic terminal result comment 7178255793 created 23:58:17 UTC, outcome passed, task_public_id 1, matching request and SHA. Round trip 41 seconds from request creation to result publication.
- At observation the terminal had not yet emitted INTEGRAL_EXIT or final cleanup. Remote receipt proves publication, not final database acknowledgement or Docker/AppArmor cleanup. Full integral certification pending those observations.
- No production activation or migration requested; disposable fixture only. CI 45725133048 nonterminal. Zero additional spend.

## CV-AUTO-002 — Docker image confirmed and integral request preparation (2026-11-25)

- Hostinger directly observed Docker active and local postgres:17.6-alpine image sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94; no pull needed.
- 2f715cb adds immutable-image, container isolation and startup-failure cleanup regressions. CI 45725133048 queued at observation; earlier 45724775684 validate and PostgreSQL passed.
- Operator integral run must use exact reviewed revision, private temporary clone, one fresh owner-authored request, existing mailbox credential locally only, temporary AppArmor profiles and disposable Docker DB. No activation/migration of production. Actual integration not yet certified.

## CV-AUTO-002 — Hostinger integral preflight and Docker fixture (2026-11-25)

- Hostinger terminal directly observed: postgres OS account unavailable, Docker binary /usr/bin/docker available, mailbox credential key present in /root/supervisor/.env (value not accessed), available RAM 2730 MiB, disk free 12 GB.
- 7cdce1e5: optional Docker disposable PostgreSQL adapter, immutable local image only, pull never, no network, no host mounts, bounded CPU/memory/PIDs, temporary data and cleanup.
- CI 45724775684 control-os-postgres success; other jobs nonterminal at observation.
- Docker daemon/image availability, Docker adapter regressions and actual integral test pending. Do not install packages, pull images automatically, or activate production. Zero additional spend.

## CV-AUTO-002 — integral certification lease budget (2026-11-25)

- 45fe1f0: transient launcher accepts bounded caller budget, uses monotonic deadline and reserves stop/show time; systemd RuntimeMaxSec reduced accordingly.
- 302a9c0: integral coordinator passes actual queue budget, checks temporary PostgreSQL postmaster PID removal.
- d18ea67: updated certification command regression and budget rejection cases.
- CI 45724775684 still nonterminal at observation. Host PostgreSQL server binaries/account availability not yet established. No host E2E execution or new certification claim.
- Next operator preflight must check installed PostgreSQL binaries and postgres account without installing anything; verify existing mailbox credential availability without disclosure, then use only selected fresh owner request. No production migration or activation, zero extra spend.

## CV-AUTO-002 — executable integral coordinator (2026-11-25)

- 26ed40c/5912f7b: exact selected mailbox comment scope and successful launcher outcome required.
- 5dc12d8: operator-only CLI connects existing real GitHub adapter, temporary local PostgreSQL cluster (Unix socket only) and certified launcher.
- 0b349e8/148665b: six scope and fail-closed regressions integrated in validate CI.
- Earlier run 45724003737 all five jobs passed; 45724354740 validate and control-os-postgres passed at last observation. New integration CI and actual host end-to-end run not yet certified.
- Remaining before host invocation: verify installed PostgreSQL server binaries and postgres account, enforce actual outer launcher lease deadline including startup/cleanup, verify scoped read/reconciliation and isolated database cleanup. No production activation or migration, no additional spend.

## CV-AUTO-002 — real exact launcher Hostinger certification PASS (2026-11-25)

- Directly observed Hostinger terminal, staged revision 9f74fbd3a4e2e1eeb3e4806366e7f6dd1cc70358, disposable clone /tmp/cv-exact-cert.09TQQ3/source.
- CERTIFICATION_EXIT=0; launcher_exit=0; ok=true; cleanup_confirmed=true; scope=exact_launcher_fixture; production_authorized=false. Same internal launcher executed fixed test profile within bounded transient unit, private network, temporary workspace and AppArmor stacked preflight.
- Existing cv-ops-bridge and cv-dev-worker services remained active, original cv-dev-worker-bwrap profile enforce, both temporary profiles removed.
- This is a fixture-path certification, not independent host observation of every candidate process nor live mailbox/DB integration. No production marker, migrations, timer activation or permanent profiles installed. Temporary evidence clone retained. Zero additional spend.
- Next: isolated real transport/queue/launcher/report end-to-end certification using disposable database and narrowly authorized mailbox, before any live enablement; Hermes location unresolved.

## CV-AUTO-002 — exact launcher certification harness (2026-11-25)

- 84dca39/62a3daf: seven local real-Git extraction regressions and CI integration.
- 5a31f20: shared exact launcher implementation with strictly named transient certification units, fixed unittest argv.
- d90aefa: operator-only exact launcher certification harness, private network, bounded transient unit, temporary Git clone, no database or mailbox access, cleanup required.
- 1d616fe/3817b41/d4f924c: five harness regressions, staging and CI integration.
- PostgreSQL integration run 45723834934 previously passed control-os-postgres and validate. New harness CI and actual Hostinger exact launcher execution remain pending. Existing synthetic host pilot is not a substitute.
- No production installation, migration, marker, timer activation or additional spend.

## CV-AUTO-002 — cross-component integration and bounded Git extraction (2026-11-25)

- 08fd8cb/3531d63: offline receiver/executor/reporter round trip and ambiguous POST recovery wired into validate CI.
- 6f67c29/e0ae446/110317d: actual four RPCs exercised via psql against disposable CI PostgreSQL, simulated GitHub transport and candidate launch. Run 45723834934 was nonterminal at observation; no certification claim.
- 71a552d: streaming Git stdout bounded before allocation, shared monotonic deadline, per-object limits. New regressions and exact launcher host certification pending.
- Prior real synthetic host pilot remains certified only for its fixed fixture. No production migration, installation or activation. Zero additional spend.

## CV-AUTO-002 — real synthetic host pilot PASS (2026-11-25)

- VPS srv2034193, staged exact revision 902289d1167243523b00fbd68992f67176a4e624, /tmp/cv-auto-002.JeoODd. Observed directly via connected Hostinger terminal after operator execution.
- Both named pilot profiles loaded temporarily in enforce; PILOT_EXIT=0, cases=3, host_observed=true, cleanup_confirmed=true, ok=true, production_authorized=false, scope=synthetic_only. Digest ad56c87fba9c5ccb77906f2ab143b7ca1fe53ca0b14beffbfac17e4e76ac777e.
- Reduced supervisor bounding set worked without CAP_SYS_PTRACE or CAP_DAC_READ_SEARCH. Existing cv-ops-bridge and cv-dev-worker active after run; original cv-dev-worker-bwrap enforce; temporary profiles removed.
- This certifies only the fixed synthetic fixture path, not arbitrary candidate tests, production health, database lifecycle or end-to-end mailbox execution. No permanent unit/profile installed, no migration, no timer enabled, zero additional cost.
- Next: isolated end-to-end receiver/queue/worker/reporter fixture and exact test-only launcher host certification; no production activation. Hermes not found in earlier checked paths.

## CV-AUTO-002 — least privilege and host diagnostics (2026-11-25)

- 0a26c5c: synthetic pilot removes CAP_SYS_PTRACE and CAP_DAC_READ_SEARCH; no automatic privilege escalation on failed observation.
- 628ec45: regression requires exact five-capability supervisor bounding set. Runtime minimum remains unverified.
- CI runs 45722765904 and 45722791546: all five jobs passed. Run 45723074904: validate, control-os-postgres, integration passed; browser and content-studio pending at observation.
- Hostinger read-only terminal: clean local checkout a3a761d38f06ed81df81b4c07037ba66f3857b32; bridge and dev-worker active; python3.12, systemd-run, dedicated bwrap root-owned 0755, cvdev uid 1000 gid 1001, work root root:root 0755. Only cv-dev-worker-bwrap appeared in loaded profile filter; pilot profiles not observed. Hermes not in current PATH; installation location unconfirmed. Second diagnostic recursive search still incomplete at observation.
- No installation, profile load, production migration or activation. Host pilot remains blocked pending reviewed profiles and isolated staging; zero new spend.

## CV-AUTO-002 — piloto fijo local con evidencia de host (2026-11-25)

- Confirmado GitHub: solicitud health 7177709087 → respuesta 7177723956 a los 39s, bridge active, recovery receiver y nexofirma online, principal stopped. No repetir health. Inventario 7177710544 aún sin respuesta correlacionada al consultar.
- CI 45722238330 (instalador 716b56d) y 45722279157 (9272c362) completos SUCCESS; cinco trabajos, paso Python incluido.
- 5c117ed: piloto ejecutable SOLO operador local, systemd-run transitorio sin red, límites memoria/CPU/PIDs, NNP, reusa CV-AUTO-001 fixture fijo, observer host y sandbox; valida tres mediciones, misma InvocationID, UID no root, cinco conjuntos caps cero, label enforce, y exige limpieza de unidad única. Dependencias añadidas al staging, seis regresiones en CI. No modifica receptor existente ni Hermes, no toca DB ni release_apply. CAP_SYS_PTRACE del observador confiable propuesto para lectura de /proc ajeno; no se ha certificado su necesidad ni suficiencia, candidato sigue obligado a cero capacidades; revisar antes de cualquier instalación.
- Pendiente CI del piloto, ejecución real supervisada desde bundle revisado, confirmar perfiles y compatibilidad kernel/systemd. No existe acción remota piloto instalada y no se ha ejecutado en Hostinger. No certificar retorno automático del piloto todavía. Sin migración, activación ni gasto.

## CV-AUTO-002 — buzón existente e instalación reversible offline (2026-11-25)

- Buzón privado #3 verificado abierto, nombre exacto y repo privado. Última salud histórica 7172321110 (19:27:23Z) bridge active, recovery receiver y nexofirma online, vivabot principal stopped; no equivale a conexión WhatsApp certificada. Nuevas solicitudes read-only health 7177709087 y agent_inventory 7177710544 (23:32Z, TTL 15min), sin resultado correlacionado al consultar. No repetir antes de expirar.
- PR #295 (29e22c3) amplía github_contingency con Hermes; resultados históricos reales 7171543774, 7171565663, 7171589048 demuestran diagnósticos fijos, no permiso de ejecución arbitraria. Rama #299 tiene versión anterior: no sustituir receptor vivo ni usar release_apply para piloto. Falta confirmar versión instalada y cualquier ruta de certificación específicamente autorizada.
- 716b56d: instalador ejecutable de bundle inactivo en prefijo privado /tmp, manifiesto SHA256 externo, no symlinks, no sobrescritura, rollback verifica identidad y rechaza archivos ajenos; cinco regresiones integradas CI. No instala unidades de sistema ni activa nada; no equivale a instalador productivo. CI run 45722238330 en cola; run 45721943656 de ddc94a7 validate y postgres success, otros pendientes al consultar.
- Sin mutación VPS, migración, gasto ni activación. Próximo: correlacionar respuestas #3, verificar CI terminal, certificar piloto sintético reversible sin secretos con E3, conservar funciones Hermes existentes. No habilitar ejecución remota genérica.

## CV-AUTO-002 — separación de privilegios y recepción periódica (2026-11-25)

- CI base ee0f28f run 45720264359: cinco jobs completos SUCCESS.
- 8a06d08: unidad conserva CHOWN, SETUID, SETGID, DAC_READ_SEARCH, DAC_OVERRIDE, KILL para padre de confianza; NNP y ambiente sin capacidades; candidato cvdev mantiene bwrap cap-drop ALL, AppArmor stacked y cinco sets cero antes del test. Regresión de lista exacta. Pendiente kernel real: no asumir que cap-drop vacía CapBnd hasta observarlo.
- aa75aba: recepción opt-in en worker dedicado bajo lock existente, procesa solicitudes admitidas dentro de límite de página, evita inanición y recibos de ingreso repetidos; retorno terminal durable existente. Aísla fallos para permitir reconciliación. Regresión de dos solicitudes y repoll sin POST.
- f1e3131: timer candidato sin Install, staging y procedimiento de certificación/reversión con contrato Hermes documentados. No instalador productivo terminado ni ejecución real Hermes.
- CI 45721833978 y 45721874808 en curso al consultar. Browser list_tabs devolvió ChatGPT, YouTube y Actions; no consola Hostinger; herramientas Browser son lectura, no shell. Sin piloto real, SQL, activación o gastos nuevos.
- Siguiente: confirmar CI, completar instalador reversible y piloto sintético con kernel, transporte autorizado y Hermes. No certificar sin evidencia.

## CV-AUTO-002 — preparación reversible y auditoría read-only (2026-11-25)

- edec53d: `github_test_only_host_preflight.py` audita sin mutaciones binarios git/python3.12/aa-exec/bwrap, perfiles E3 AppArmor en enforce, Git local y sintaxis de unidad con systemd-analyze verify; devuelve `runtime_certified=false` y `activation=false` siempre. Un resultado positivo permite solo preparar piloto reversible, nunca activar producción.
- b56016d + 1ddd968: regresiones de dependencias completas, perfil ausente, unidad inválida, acceso a perfil denegado; fe9555f agrega inspector al staging offline; 324f061 integra nuevo test en CI.
- Estado evidencia: run 45720043025 sobre 99438e4 tenía test Python Ops verde y SQL desechable verde, resto en curso; nuevo HEAD 324f061 pendiente de CI al consultar. No hay resultados Hostinger de auditoría ni ejecución de un candidato test-only con NNP/5 capacidades cero comprobados en el kernel. No migraciones, no activación, cero gasto nuevo.
- Seguimiento: recopilar resultados de CI hasta terminal, corregir fallos verificables, ejecutar inspector solo lectura en VPS a través del puente autorizado, verificar compatibilidad de perfiles y captura independiente en el kernel antes de certificar E2E. El servicio dedicado continúa sin [Install], condicionado a marca explícita y apagado.

## CV-AUTO-002 — AppArmor/NNP hardening, 2026-11-25 (SIN INSTALAR)

- Base a5289639, PR #299 draft. CI base run 45700124954 COMPLETO SUCCESS: validate, integration, postgres, browser y content-studio. No equiparar CI con certificación VPS.
- d911155: el launcher de prueba fija usa el mismo bootstrap E3 `cv-release-pilot-bootstrap` (aa-exec) y la transición apilada obligatoria `cv-release-pilot-candidate`. Prefijo confiable verifica etiqueta enforce exacta, UID no root, NoNewPrivs=1 y CapEff/CapPrm/CapInh/CapAmb/CapBnd=0 ANTES de runpy.run_path. No abrir/importar código candidato antes de checks.
- 592d4fb + 13b97a8: unidad propuesta exige NNP y sets vacíos de capacidades, límites existentes; corrige incompatibilidad: ProtectHome=read-only permite leer Git local /root/Casa-Viva; se retira RestrictAddressFamilies=AF_UNIX porque dev-worker requiere RPC HTTPS. El test permanece sin red por Bubblewrap, no por el servicio host.
- 99438e4: regresiones de orden del prefijo y unit; 50e07c8: exit 126 de preflight se registra blocked. Estos tests son de contrato, NO una medición del kernel.
- CI nuevo de la rama: run 45720043025 en curso sobre 99438e4 al consultar. Todavía no hay CI confirmado del último HEAD. Pendientes: comprobar resultado, posibles reparaciones, validar dependencias/instalador staging, host AppArmor real (stacking y aa-exec), cgroup, NNP/caps en /proc y reversión; no afirmar certificación E2E ni Hermes hasta evidencia real.
- Cero migraciones Supabase, cero instalación en Hostinger, cero activación de servicios, cero cambios en pedidos/pagos/inventario y cero gasto adicional. Mantener dependencia PR #298. Revertir solo estos commits en rama si se rechaza; sin modificaciones VPS que revertir.

## CV-AUTO-002 — retorno persistente y staging candidato (2026-11-25)

- c67ead3/1634c80: owner ID fijo, JSON sin claves duplicadas, UTC y fixture.
- 5e08cc6 reporter terminal con reconciliación remota acotada; c6d44e3 RPC ack transaccional; 3fdd703 integración opt-in worker; 69bdabb deadline extracción; cef627b cierre repetido después de ack; 1b2867e fixture SQL ack.
- ee71bd3 unidad dedicada sin timer ni instalación, tmpfs 64 MiB, 4096 inodos, límites CPU/RAM/PIDs; 4ebbb0d staging offline con manifiesto SHA256, no instalador productivo; 77ab516 regresión POST ambiguo.
- Pendiente integrar regresiones reporter/staging en CI, revisar compatibilidad systemd/AppArmor, límites reales y retorno; certificar en host antes de cualquier activación. No migración aplicada, no instalación ni gasto nuevo. PR #299 sigue candidato dependiente de #298.

## CV-AUTO-002 — ejecutor candidato y conexión worker (2026-11-25)

- a275ef4: executor perfil fijo, presupuesto lease, RPC claim/finish. 6739a73: launcher local Git exact SHA, copia regular scripts/ops sin .git, límites de extracción, cgroup dedicado verificado (256 MiB, CPU 50%, PIDs 32), Bubblewrap cap-drop, tiempo/salida existentes. 09fadd6: mismo dev-worker en modo dedicado opt-in, sin entrar a ruta legacy. 9e261c4: regresiones contrato, 699702c CI.
- No se ha provisionado unidad cv-test-only-candidate.service ni certificado su AppArmor/aislamiento real; opt-ins apagados. Falta verificar límite total de extracción, comportamiento de montaje, límites de disco, retorno terminal al buzón, instalador y E2E. Solo objetos Git locales; no fetch automático. No interpretar el resultado de candidato como evidencia independiente de salud.
- CI run 45698560148 completo success (entrada). Lifecycle run 45698869326: integration=in_progress, control-os-postgres=success, content-studio=in_progress, browser=in_progress, validate=success. CI executor pendiente. Sin migración ni instalación, producción intacta, costo nuevo cero. Reversión: revertir rama; no recursos instalados.

## CV-AUTO-002 — lifecycle transaccional test-only (2026-11-25)

- aeac7f5: SQL candidato agrega mode test-only, claim exclusivo con FOR UPDATE SKIP LOCKED, run único, lease máximo 180 s, expiración terminal sin replay, cierre atómico con fence task/run/worker y resultado enumerado; SECURITY INVOKER/service_role. No modifica claim legacy.
- ae4c9e1: fixture desechable de permisos, doble claim, fence incorrecto, replay terminal, conflicto y expiración. ebd0015 integra fixture en CI. Pendiente ejecución real y revisión de concurrencia.
- Siguiente: ejecutar perfil fijo por SHA local exacto, copia sin .git, sandbox cvdev sin red ni secretos, límites cgroup CPU/memoria/pids y salida, retorno terminal. No se ha instalado ni ejecutado en VPS. No aplicar SQL en producción. Costo nuevo cero.

## CV-AUTO-002 — contrato SQL real y entrada transaccional (2026-11-25)

- Consultas SELECT en nexo-production confirman columnas, índices, constraints de ops_tasks/ops_dev_runs/ops_events y definición de ops_claim_dev_task(p_worker text). Claim legacy solo acepta deterministic/ai, filtra payload.dev.ready=true; constraint de runs también solo permite deterministic/ai. No introducir test-only en ese recorrido.
- e09f6a3: propuesta SQL github-test-only-enqueue.sql usa columnas reales source_ref, dedupe_key, deadline_at, public_id; índices únicos parciales de comentario y nonce, fingerprint validado, TTL máximo 15 min, RPC SECURITY INVOKER y permiso service_role; una transacción crea tarea y evento. Payload test_only sin dev.ready impide claim legacy.
- d487382: fixture PostgreSQL desechable para duplicados, conflictos, nonce reutilizado, expiración y permisos. 13575eb: CI fixture y trigger de rama. Pendiente comprobar ejecución; no se ha aplicado migración.
- Siguiente: claim específico test-only con run mode y lease/fencing, cierre atómico y ejecutor aislado revisión exacta, sin Git push/deploy; retorno terminal GitHub. Verificar triggers y grants reales antes de instalación. No activación ni gastos. Reversión de rama; ninguna modificación DB productiva.

## CV-AUTO-002 — receptor GitHub test-only (2026-11-25; fase 1, SIN ACTIVAR)

- Base certificada d4b25bf534e07c9c5bd64581706a0f0cc6327b67; rama cv-auto-002-bridge; depende del PR #298 (CV-AUTO-001).
- Se añadió scripts/ops/github-test-only-bridge.py: aprovecha autenticación/issue privado #3 del módulo github_contingency y cliente API de dev-worker, validación owner, edición, SHA 40 caracteres, perfil fijo, TTL <=900 s, nonce, whitelist de campos, y solo llama RPC transaccional ops_enqueue_github_test_only. No ejecuta comandos. Opt-in CV_GITHUB_TEST_ONLY_ENABLED=1; apagado por defecto; retorno GitHub con recibo limitado.
- Regresiones Python negativas en scripts/ops/tests/github-test-only-bridge.test.py e integración en validate.yml. Pruebas CI pendientes de resultado real.
- Bloqueador confirmado: el repositorio muestra consumo de ops_claim_dev_task(p_worker), pero NO su definición SQL productiva ni la RPC de inserción; la RPC ops_enqueue_github_test_only es contrato propuesto, TODAVÍA NO INSTALADO. No cambiar esquema ni instalar mientras no exista migración transaccional aprobada y fixture PostgreSQL certificado. Ninguna ejecución autónoma VPS demostrada.
- Dev Worker determinista sigue siendo ruta de edición/push: NO introducir test-only como tarea reclamable antes de agregar una ruta confinada de solo pruebas y demostrar ausencia de push/deploy, revisión exacta y límites.
- Producción intacta, sin instalación VPS, sin mensajes, pedidos, inventario ni pagos. costo_actual: infraestructura existente; costo_nuevo_requerido: 0; alternativa_gratuita: cola, bunker cvdev, GitHub y CI; momento_de_escalar: solo autorización explícita.
- Reversión: revertir los commits de esta rama; no hay servicios ni migraciones instaladas.

## CV-AUTO-001 — compatibilidad transporte PM2 7 (2026-11-25)

- Hostinger d8540db49e82c06a75367ceac2fad48ab74f97a4: 6 regresiones OK (0.012s), daemon_identity (2429462,241763921,2049,262257) verificada; RPC runtime_error, tres unknown, bridge active; precheck bloqueó candidato, perfil original OK y limpieza. PM2 7.0.4 distribuye transportes en modules/pm2-axon y modules/pm2-axon-rpc, no necesariamente dependencias npm externas. 5bd1f5d prioriza módulos internos, conserva compatibilidad legacy y añade diagnóstico 25; b6f268c prueba ambos layouts y ausencia mediante Node real. Hipótesis causal apoyada por distribución pública, pendiente confirmar ejecución real. No reinicio ni producción ni gasto. Reversión: revertir rama y eliminar recursos temporales únicamente.

## CV-AUTO-001 — identidad PM2 sin archivo PID (2026-11-25)

- Hostinger: PM2 7.0.4 daemon PID 2429462, rpc.sock y pub.sock escuchando, rpc.sock root:root 775, pm2.pid ausente. Causa confirmada del rechazo anterior. 8fe9a79 sustituye dependencia PID por SO_PEERCRED, inicio de proceso, título PM2 y dev/inode socket; exige identidad estable antes/después del RPC. 383e74a regresiones sin archivo PID, socket sustituido, epoch cambiado, daemon ausente y peer incorrecto. Pendiente CI nuevo y prueba real --local-observation; no producción, no reinicio, no gasto. Reversión: revertir rama y limpiar solo recursos temporales. No confundir identidad observada en dos conexiones con autenticación de cada conexión RPC.

## CV-AUTO-001 — observación real bloqueada por RPC (2026-11-25)

- Hostinger revisión c7a398f84ff2212b36295ee6224d5c80ad02dd53: bridge active; tres PM2 unknown; precheck bloqueó antes de candidato y perfiles temporales; perfil original OK, limpieza finalizada. Causa exacta no determinada. 4a6476a añade categorías de diagnóstico RPC sin salida cruda ni secretos; 016c978 añade regresiones. No se cambia política ni se inicia PM2; siguiente paso diagnóstico local únicamente, no repetir piloto. CI base foundation/staging correctos, validate/integration/content-studio/postgres correctos, browser en curso al consultar. Sin producción ni gasto.

## CV-AUTO-001 — entrada local de observación (2026-11-25)

- 29770cd/cbc58b9 recolector fijo PM2 RPC getMonitorData sin CLI ni arranque de daemon, Systemd ActiveState; 3e26b77 entrada --local-observation reutiliza fixture fijo y handshake; bd90798 regresiones y c0972f9 CI. Pendiente verificar compatibilidad real de RPC del PM2 instalado y CI, no instalado en producción. No repetir piloto sintético; prueba reversible con datos reales solo tras CI. Reversión: retirar recursos temporales y revertir commits de rama. Costo nuevo cero.

## CV-AUTO-001 — proyección y observación advisory (2026-11-25)

- Base 422db02. 3e39e3e conecta summary normalizado al mismo lanzador con handshake host obligatorio en modo observation_only, contrasta resultado con referencia y no autoriza producción; 2e487a2 añade 6561 combinaciones del contrato original y entradas inválidas, 2e4aa34 CI, 1683d0e prueba handshake y discrepancias. El contrato del buzón y puente root permanecen intactos. Pruebas pendientes de CI y observación real; no se ha conectado a producción ni existe entrada remota nueva. Reversión: revertir commits de rama. Costo nuevo cero.

## CV-AUTO-001 — piloto sincronizado con observador host (2026-11-25)

- f3dbc60, 9281589, 0b9f574: el prefijo confiable del fixture mide bytes, espera ACK por stdin; el lanzador observa PID host/NSpid/UID, InvocationID y hash de staging antes de ACK. 9bf587c regresiones de identidad, fba479a rechazo en errores o vencimiento, 7f68ad4 pruebas de tuberías reales. Solo fixture fijo, no recibo productivo; CI y ejecución VPS pendientes. El puente root sigue sin separación instalada. No cambios de producción ni gasto adicional. Reversión: revertir commits en rama y retirar únicamente perfiles y workspace temporales del piloto.

## CV-AUTO-001 — observador kernel del piloto (2026-11-25)

- c9e6b52 añade muestreo de PID host, NSpid, start ticks, UID, NNP, capacidades, AppArmor y cgroup, con InvocationID estable y rechazo de múltiples coincidencias. 07ef808 añade pruebas; 2f71687 integra CI. Aún no conectado al lanzador ni vinculado a bytes cargados: requiere piloto específico y correlación temporal independiente. No autoriza producción. Hostinger 16:16:31 -04:00: principal stopped exit 0, recovery online PID 2441402, ambos unstable_restarts 0; no reiniciar sin verificar rol WhatsApp. Costo nuevo cero.

## CV-AUTO-001 — medición previa del fixture (2026-11-25)

- 41a556d mide bytes exactos y restricciones dentro del prefijo confiable antes de ejecutar el fixture fijo; 6b34adf prueba el contrato de salida y rechaza evidencias incompletas. PID interno del namespace, no identidad de host; canal compartido con fixture, no sirve para candidatos arbitrarios ni recibos productivos. Pendiente CI y piloto VPS; separación del bridge root aún no instalada. Reversión: revertir commits de rama. Costo actual: infraestructura existente; nuevo costo 0; alternativa gratuita: sandbox existente; sin escalado autorizado.

## CV-AUTO-001 — contrato de observación independiente (2026-11-25)

- Base 70287a101300f4ec3afe94eea3fdb2d1c3714b2a. 8737cbb añade contrato de observador con revisión, hash, epoch, InvocationID, uid no root y confinamiento; ab0e043 añade regresiones de cambios de identidad, fallo confirmado y evidencia desconocida. Las dependencias son callbacks confiables de prueba, no proveedor productivo instalado. No acredita bytes cargados por sí mismo. Mantener release cerrado; pendiente proveedor independiente, separación real del puente root y pruebas de recuperación. Reversión: revertir commits en rama, sin cambios VPS. Costo actual: infraestructura existente; costo nuevo requerido 0; alternativa gratuita: sandbox y systemd existentes; escalar solo si se demuestra necesidad y se autoriza.

# CURRENT — P0 preservar ofertas al editar pedidos (2026-11-21)

## Infraestructura VPS durante HTTP 402 (2026-11-23)

- Reutiliza el sincronizador y el SSH a Hostinger existentes. Si Supabase responde 402, publica solo el estado local de PM2 y del puente con su propia fecha en `control-private/infrastructure.json`. La instantánea principal, los controles de vigencia y las aprobaciones existentes conservan su fecha y sus permisos.
- Reversión: restaurar sincronizador y archivos web previos. El diagnóstico local queda fuera del directorio público. Verificar HTTP 401 sin sesión y lectura autenticada con fechas distintas antes de cerrar.


## Centro de Control — Infraestructura VPS (2026-11-23)

- Base `a28a3e7145516855e426e68d6593a4568650c8e8`. `/control/` reutiliza el acceso WordPress vigente y la instantánea privada del sincronizador. La vista muestra por separado el receptor y el proceso principal, indica datos vencidos y enlaza a hPanel. Sin credenciales, acciones nuevas ni ampliación de permisos. Estado operativo PM2 no certifica conexión WhatsApp.
- Reversión: restaurar los archivos web y el sincronizador desde el respaldo previo al eventual despliegue. Validar lectura con sesión autorizada, rechazo de usuarios sin acceso y estado contrastado con el VPS antes de cerrar. Costo adicional cero.


## P0 VivaBot — consulta comercial con catálogo real

- VPS run-u34758 exit 0. Receptor b4c6477d…a92f y diálogo df60609d…77f8. Base: la pregunta compuesta daba null; Woo Store API vacía en plural. Candidato aplica cambio en memoria, preserva saludo y clasifica traspaso de carrera sin ejecutarlo.
- GET público WooCommerce reproducido desde VPS; el fetch inyectado en Node aislado validó la URL y reprodujo los datos reales. El formateador real devolvió 72.00 USD y bajo pedido para Royal RA12RSL. El parser de confirmación mediante OK se sustituyó por stub, no se ejecutó respond, chatAllowed o envío de WhatsApp. Sin cambios persistentes VPS. Reversión de eventual cambio: restaurar natural-dialog.mjs desde copia verificada. Pendiente desplegar por separado y certificar adaptador completo.

## P0 VivaBot — prueba sintética del clasificador

- Buzón: `receiver_classification` acepta únicamente SHA-256 del módulo más el sobre habitual del propietario. Opt-in independiente `CV_GITHUB_RECEIVER_CLASSIFICATION_ENABLED=1`. La acción verifica SHA-256 del ejecutor y éste la de la prueba Node. Resultados enumerados y sin salida cruda. El instalador vigente solo instala `github_contingency.py`: antes de activar se requieren ambos auxiliares certificados, privados, propiedad del usuario efectivo del puente en `/root/supervisor/`, y validación de aislamiento en VPS. Reversión: desactivar opt-in. Prueba de buzón y proceso simulados: entrada, huella incorrecta, filtrado y opt-in aprobados.

- El PR incluye un ejecutor transitorio `systemd-run` que exige la huella esperada y entrega el archivo por stdin a Node. Usuario dinámico, red privada, familias de socket limitadas y límites de tiempo y memoria. Respuesta limitada a resultados enumerados. Cuatro comprobaciones locales con datos ficticios, Node real y lanzador systemd simulado. El entorno Work carece de systemd como PID 1. Validación pendiente: prueba de las restricciones en el host y conexión al buzón mediante una acción fija autorizada.

- El VPS devolvió la huella `b4c6477d…a92f` por el buzón de solo lectura. El nuevo diagnóstico offline simula dos sobres Baileys y devuelve clasificación y huella, con catálogo y respuesta expresamente pendientes.
- Pruebas locales sobre código ficticio: saludo detectado, ventiladores sin intención, dependencia externa no soportada y módulo completo no ejecutado. Ejecutar solo sobre fuente propia y en entorno de pruebas aislado; `node:vm` no es una frontera de seguridad. El diagnóstico aún no se instaló ni se conectó al VPS.

## P0 VivaBot — versión del receptor por el puente

- PR #277 añade `receiver_contract`, acción de solo lectura del buzón privado con opt-in local. Devuelve SHA-256 e indicadores de código sin ejecutar el módulo. Prueba de contrato añadida para opt-in y permisos del archivo.
- Inventario del VPS confirmado por el resultado 7083571245. Pendiente CI del último commit, confirmar lectura con propietario y permisos válidos e instalar el diagnóstico con reversión. Sin despliegue ni pruebas conversacionales sobre el VPS en este bloque.

- Base fb5bdce6b324a6e1719b2c7a604e5f293d627015. Incidente #6790: guardar datos administrativos podía resetear una oferta abierta a «Por ofertar» por selección 0 sin cambio. Usuario confirmó liberación y republicación automática; conservar esa ruta.
- Candidato: formulario conserva valor inicial, no escribe si no hay cambio, rechaza selección inválida o mensajero inicial desactualizado; mantiene opción de mensajero actual si no figura en listado. Sin cambios en pagos, inventario, pedidos ni WhatsApp reales.
- Regresión en test-order-transition-service-1c.php: oferta abierta, asignación existente, formulario antiguo, campos ausentes/invalidos. Pendiente CI, revisión, despliegue y verificación funcional. Reversión: revertir commit.
- Puente health confirma receptor recovery online, vivabot anterior stopped, bridge active; no certifica socket ni versión. Siguiente: validar receptor y variantes YO, sin mensajes de prueba reales.

# CURRENT — P0 comisión pendiente después de corrección de precio (2026-11-20)

- #273 integrado con tres CI verdes. #6790 corregido por administrador WooCommerce, 57,75 USD y pago efectivo 57,75 verificados; comisión pendiente aún obsoleta. Candidato: al guardar estado pendiente por vía administrativa existente, reconciliar únicamente línea que coincide con precio acordado, propietario, base y cantidad históricos, sin extra, conflicto, payout o devolución. Conserva comisión base, registra nota e idempotencia. Prueba 4E. CI pendiente, no desplegado; no cambia cobro, stock, reparto ni comisión aprobada. Próximo: certificar, desplegar y guardar pendiente; verificar comisión 8,75 antes del vale. Costo cero. Rollback revertir PR.

---

# CURRENT — P0 #272 precio de gestora (2026-11-20)

- Base main 94da7384. Pedido histórico #6790: 55 × 1,05 = 57,75 USD, pero get_price vuelve a aplicar margen tras set_price → 60,64. Candidato: marca por instancia WeakMap, no por ID; conserva filtros de catálogo y regular/sale, restauración de precio original al desbloquear; recalculación repetible. Regresión integración 4E con precio bloqueado, cambio, aislamiento y salida. CI pendiente; sin despliegue, sin modificación histórica ni mensajería. Reversión: revertir PR. Próximo: certificar CI, corregir el pedido y su snapshot de comisión por transición autorizada antes de asignación. Costo adicional cero.

---

# CURRENT — CV-RECOVERY-009: búsqueda de pedidos y señales de audio (2026-11-20)

- Puente remoto #270 instalado y confirmado por buzón 7054815743 (changed:true). Se agrega order_lookup de solo lectura en copia local, producto limitado, 72h, un único negocio, chats privados, IDs explícitos de pedido en mensajes coincidentes y conteo de señales de audio en chats relacionados. Sin textos, nombres, teléfonos, archivos, transcripciones, SQL libre ni cambios de pedidos/cobros. 21/21 pruebas Python locales; PostgreSQL real y CI pendientes. No equivale a lectura del vale ni a escucha del audio, requiere un canal privado autorizado distinto de GitHub para contenido. Rollback módulo previo; cero costo adicional. Siguiente: certificar CI, instalar vía release_apply y validar consulta real; luego consultar Core y habilitar vista privada.

---

# CURRENT — CV-RECOVERY-008: señales urgentes (2026-11-20)

- Puerta release certificada no-op 7054628844, main protegido; snapshot 7049450593 certificado. Ernesto solicita priorizar dinero/pedidos y bot. Acción urgent_triage fija, solo conteos de escalaciones abiertas, estados de errores y señales de texto en copia local; coincidencias no equivalen a casos confirmados, WhatsApp fecha ISO solamente. No mensajes privados, SQL libre, cobros, pedidos ni envíos. Pruebas añadidas; CI y despliegue pendientes. Próximo certificar y reconciliar Core/Caja. Rollback módulo previo.

---

# CURRENT — CV-RECOVERY-007: diagnóstico agregado PostgreSQL (2026-11-20)

- Inventario remoto confirmado 7048899873; contenedor vivabot-recovery-db aislado, copia poblada. Acción fija db_snapshot sin argumentos, Docker/psql de solo lectura, conteos y fechas UTC, sin datos personales, secretos, SQL libre, puertos nuevos ni reinicios. No implica frescura ni permiso vigente. Pruebas de contrato y redacción añadidas; CI pendiente, sin instalar. Rollback: restaurar módulo previo. Próximo: certificar snapshot y puerta de actualización, luego reconciliar Caja y reparto.

---

# CURRENT — P0 NEXO Caja: reconciliación lectura Core (2026-11-20)

- Zahymi no ve pedidos: fallo documentado de ruta dependiente de Supabase HTTP402. Corrección #264 CI anterior verde, conflicto con main por documentación/CI. Reconciliado sobre 3e177084 conservando exactamente clase PHP blob fe90597d y test #264; workflow mantiene pruebas existentes del puente. Sin cambio a pedidos/cobros ni credenciales, ruta desactivada por defecto hasta autorización y configuración legítima.
- Cliente #151 bloquea cobros/vuelto en recuperación (CI previa verde); falta enlace autenticado, aprobación/dispositivos y prueba real. No declarar operativo por CI. Costo cero. Próximo: certificar CI y release Core, preparar adaptador autorizado Caja, proteger SQLite offline; #267 puente control remoto en paralelo, sin reiniciar VivaBot.

---

# CURRENT — CV-RECOVERY-006: actualización remota certificada (2026-11-20)

- Ernesto pide una instalación permanente para operar desde móvil/chat/voz. Reutiliza puente certificado y supervisor, no shell root remoto. Candidato: inventario fijo de agentes y `release_apply` opt-in, puerta local inmutable que verifica SHA exacto de main protegido, tres CI push exitosas y artefacto único; copia previa por hash, reemplazo atómico, sin tocar servicio/credenciales/cobros. Token separado Actions/Metadata Read, no ampliar Issues.
- 15/15 pruebas locales, py_compile PASS. CI pendiente. No instalado ni permisos nuevos. #266 integrado 3e177084, preflight aún no instalado/configurado. Ver docs/CV_RECOVERY_006_REMOTE_CONTROL_GATE.md. Próximo: CI y una sola instalación controlada para habilitar diagnóstico, inventario y actualización. Coste cero; sin cambios de pedido ni cobros.

---

# CURRENT — CV-RECOVERY-005: reparto diagnóstico remoto (2026-11-20)

- Puente GitHub–VPS certificado: #265 main 269f4285, solicitud 7047216297 respondida en buzón privado #3; servicio active, receptor recovery online, VivaBot principal stopped. Backoff #263 preservado en VPS. No envíos ni cobros.
- Candidato opt-in `CV_GITHUB_DISPATCH_PREFLIGHT_ENABLED=1`: acción fija sin argumentos, reutiliza VivaBot #85 main 6ce7a0e (blob 5dfafab9), script local con permisos privados, configuración dedicada `.dispatch-preflight.env`, seis claves allowlist, Node fijo con ambiente limpio y timeout, solo GET y respuesta de booleanos/conteos. Conserva propietario/TTL/nonce, no repite reservas, no ejecuta comandos arbitrarios ni expone datos. No cambia servicio existente hasta instalar módulo revisado, script exacto y configuración local legítima.
- 10/10 pruebas locales PASS; CI pendiente. Costo adicional 0. Próximo: validar CI, revisar e instalar de forma reversible con acceso SSH, comprobar reconciliación real; sin reactivar sockets ni publicar pedidos hasta certificar. Rollback: desactivar flag y restaurar módulo anterior, conservar estado/nonce.

---

# CURRENT — EVO-FLUX-PILOT: vista previa gratuita aislada (2026-11-19)

- Base main 0bc7570b3d7ed4543212586d120e2638f7965bf1. Cuenta Cloudflare autenticada: Workers Free, 0/10.000 neuronas al observar el dashboard; Klein 4B en catálogo. No se han revelado claves ni invocado inferencia. Producción no modificada ni reconsultada en este bloque.
- Candidato: método CLI exclusivo `CVD_Photo_AI::cloudflare_free_pilot()`, sin REST, prompt sintético fijo de fondo, formato 1024×1024, sin imagen privada. Reutiliza almacenamiento de claves y transporte del Core. Attestation privada del operador (plan Free, hash de cuenta coincidente, uso conocido ≤9750, antigüedad ≤1 h, aprobación estricta). Reserva persistente por cuenta de un solo piloto; no se libera ni cambia con el día. Sin gateway, fallback, reintentos, publicación ni alteración de catálogo. No habilita generate/poll/vps_image/suggest.
- La attestation representa comprobación humana, no consulta automática de facturación (requiere permiso Billing Read). No usar si el plan cambió. La futura ejecución diaria necesitará verificación automatizada y revisión separada. No ampliar permisos.
- Pruebas con transporte sintético: bloqueo sin CLI/attestation, plan pago, evidencia vencida, cuenta diferente, cuota, falta de aprobación, respuesta válida y repetición; CI pendiente. No certificar token o modelo real hasta smoke autorizado con el Core instalado y evidencia fresca. No se instala código en producción aún. Coste nuevo cero, alternativa montaje local.
- Siguiente: certificar CI y revisar candidato; el despliegue/ensayo productivo requiere aprobación del SHA y evidencia de plan fresca mediante canal privado. No publicar imágenes ni iniciar radar/proveedores.

---

# CURRENT — CV-INC-250: ajuste de puntos (2026-11-18)

- Fase 0, corrección operativa aislada. Comisión ya aclarada; solo puntos. No se modifica pedido, comisión, cobro, inventario ni liquidaciones. Producción no cambiada por esta unidad; último SHA certificado según registro anterior.
- Candidato: registro administrativo por mes vigente, referencia idempotente, actor, motivo y reversión auditada; cálculo puro incorpora puntos al equipo y piedra, excluyéndolos del fondo monetario. El detalle de gestora distingue ajuste de venta. Cierre mensual inmutable. Reintento no duplica.
- Pruebas nuevas en test-programa-gestoras.php; PHP no disponible localmente, CI pendiente. No fusionar/desplegar ni acreditar en producción hasta certificación y puerta de producción. Después aplicar exclusivamente 4 puntos a la gestora autorizada en #250 y verificar dos cálculos y panel. Reversión: acción administrativa con referencia y motivo antes del cierre. Costo incremental 0 USD.

# CURRENT — EVO-LOCAL-001 E3: evaluación de límites (2026-11-17)

- #251: evaluador documental cgroup v2 para memory.max, pids.max y cpu.max; falla si faltan, son ilimitados, inválidos o superan umbrales aprobados. CLI --cgroup-evidence con presupuesto explícito, no ejecuta sandbox ni modifica host. Tres regresiones añadidas. CI pendiente. NO configura límites ni certifica que el proceso y sus descendientes estén en ese cgroup: validar pertenencia, mediciones y terminación en el VPS, tras autorización E2. Sin cambio productivo; coste nuevo cero.

---

# CURRENT — EVO-LOCAL-001 E3: configuración npm aislada (2026-11-17)

- #251: HOME y cache npm desechables por workspace, userconfig/globalconfig /dev/null, PATH fijo, se ignoran variables npm heredadas y se rechaza .npmrc o HOME npm preexistente/incluso symlink colgante. Regresión de configuración y rutas negativas añadida. Esto reduce exposición accidental, NO impide que un proceso npm con red lea archivos del host: falta aislamiento de instalación y límites efectivos de recursos, canarios E3 y prueba en VPS. CI pendiente. E2/E4 sin autorización nueva, interruptor no activado, gasto adicional cero.

---

# CURRENT — EVO-LOCAL-001 E3: verificador fail-closed (2026-11-17)

- #251: arnés verify-sandbox v2 rechaza salida no cero, JSON de otra prueba, booleano no estricto, comando inválido; runner real no hereda entorno y se niega a correr como root. Modo explícito --dev-worker selecciona binario y raíz de E2. Cuatro regresiones añadidas sin ejecutar bwrap. CI pendiente; no certificación en VPS. Falta aislamiento npm y límites efectivos, pruebas de canarios y E2 autorizado. CV_DEV_SANDBOX no activado, costo adicional cero.

---

# CURRENT — EVO-LOCAL-001 E2/E3: preparación documental (2026-11-17)

- E1 candidato #251 SHA 602b64d701bb60e1749dc7400c0447e0a7922d18: CI aplicación 44228082919, release 44228082935 y staging 44228084707 completadas success. Incluye regresión de enlaces colgantes. No integrado ni instalado.
- Runbook E2 revisado: SHA integrado verificable, respaldo con manifiesto y hashes de tres destinos, reversión ante fallo intermedio, comprobación efectiva del interruptor apagado. Criterios E3 ampliados para canarios sintéticos, npm aislado, límites cgroup y checkout confiable intacto. Sin ejecución VPS; CI de cambios documentales pendiente. E2 y E4 requieren aprobación explícita; E3 no certificado. Costo adicional cero, infraestructura existente, sin compra de créditos.

---

# CURRENT — EVO-LOCAL-001 E1: reconciliación con main (2026-11-17)

- Se conserva el código del PR #241 SHA aeca5e5, con tres workflows verdes (43992655160, 43992655264, 43992655176). Main bf1398d avanzó 19 commits; se incorporan los cambios E1 conservando los cambios de CI y checkpoint de main. Candidato de revisión; CI conjunto pendiente. No se fusiona ni despliega, CV_DEV_SANDBOX=0, E2/E3 pendientes y E4 bloqueada. Costo adicional cero.

---

# CURRENT — EVO-LOCAL-001 E1: corrección P1 en revisión (2026-11-16)

- PR #241: separación de checkout Git confiable y copia de pruebas sin metadatos; ninguna mutación de la copia se importa. Rechazo de rutas cambiadas simbólicas/no regulares y de ancestros inseguros. Validador bwrap falla cerrado. Perfil dedicado propuesto y [runbook E2](EVO_LOCAL_001_E2_RUNBOOK.md).
- Evidencia local: 68 pruebas de worker, entorno y caja (14 + 14 + 10 + 30); CI del nuevo SHA pendiente al registrar este checkpoint. Sin merge ni despliegue. E2 requiere aprobación; E3 debe validar aislamiento real, límites de recursos y npm; E4 exige aprobación separada. CV_DEV_SANDBOX permanece apagado y puerta docs-only. Costo adicional: 0 USD; alternativa: infraestructura existente; escalar solo tras aprobación.

---

# CURRENT — EVO-LOCAL-001: Dev Worker cableado a la caja, APAGADO y sin desplegar (2026-11-16)

- `CV_DEV_SANDBOX=1` (único valor que enciende) lleva el worktree a `run-task-N` bajo la raíz de la caja, instala con `npm ci --ignore-scripts` como `cvdev`, prueba dentro de bwrap sin red, recupera el worktree y revalida diff y enlaces antes de commit/push. Apagado = comportamiento anterior. 16 pruebas; CI verde (PR #241). Fusionados antes: #238, #239 y #240; el worker con entorno limpio sigue en el VPS (hash = main).
- **Falta (pide SÍ):** E2 copiar `dev-worker.py`, `dev-sandbox.py` y la especificación al VPS, instalar el bwrap dedicado del Dev Worker y su perfil AppArmor; E3 validar en vivo con `verify-sandbox.py` y una tarea de docs; E4 encender el interruptor.

---

# CURRENT — VivaBot despliegue con recuperación conservadora (2026-11-17)

- PR candidato: puente 4.1.3, solo recuperación de VivaBot. Producción último SHA certificado `0d537006aa6e5aa45ad89464f7ce4b6bbf6a419d`; candidato VivaBot #48 `b90883295e278c5a383bd7b832bb592b24daa4fd`, ambos workflows verdes, pendiente instalación. Puente anterior 4.1.2.
- La recuperación comprueba HEAD esperado, rama y ausencia de cambios tracked, utiliza `git reset --keep` (no hard), verifica el SHA restaurado y solo recarga PM2 si se confirma. Si hay cambio concurrente, se bloquea y se informa revisión manual. No se cambian permisos ni se reinicia VPS.
- Cuatro pruebas con repositorios temporales: restauración limpia conserva archivo local, cambio tracked bloquea, HEAD movido bloquea, rama cambiada bloquea. Integradas en CI, todavía pendiente resultado. Sin instalación del puente ni #48 en este subpaso. Costo nuevo 0 USD; reversión puente mediante copia anterior existente si se aprueba instalación.

# Control OS — shell visual inicial (2026-11-16)

- Fase CURRENT · PR #244. Base productiva ee8638f. Primer shell con navegación agrupada, cinco pestañas móviles, sidebar 248px, asistente desplegable y tarjetas de indicadores reales. Auth, endpoint, nonce y validación de aprobaciones preservados. Sin despliegue. Pendiente CI, inspección visual y comparación de identidad/logo, tema oscuro, controles de captura y pantallas restantes. Costo adicional 0 USD.

# Control OS — recuperación de diseño (2026-11-16)

- Fuente original archivada parcialmente en `docs/control-os-design/`; ocho archivos visibles recuperados, faltan fuentes de componentes. No hay cambios de runtime ni despliegue. Siguiente: shell visual exacto sobre `/control/`, con pruebas.

# CURRENT — Control OS: integración de corrección 4A (2026-11-16)

- #243 fusionado con aprobación expresa de Ernesto (main 3ab358a). Tres workflows verdes, integración confirma OK 4A. Candidato #242 actualizado para certificar ambos cambios juntos; sin despliegue ni E4.

# CURRENT — Control OS recuperación del trabajo local (2026-11-16)

- PR #242: recuperado parche de Codex. Amplía pendientes y vínculos de aprobación, bloquea decisiones no accionables, incorpora inicio, ejecutor `dev` y latido declarado. No cambia estados ni despliega.
- 9 pruebas de snapshot y sintaxis JS/Python verificadas; PHP y CI del nuevo SHA pendientes. Las 122 tareas, cero aprobaciones formales y cuatro revisiones #8/#14/#19/#24 son evidencia reportada por Codex, no reconsultada aquí. Parche VivaBot independiente pendiente de recuperar.
- E2/E3 pendientes de autorización y E4 bloqueada. Sin gasto adicional.

# CURRENT — Control OS primera entrega revisable (2026-11-16)

- Rama de propuesta UI existente; vistas y barrera conservadora de aprobaciones. CI y smoke privado pendientes; sin despliegue. Ver `docs/CONTROL_OS_FIRST_SLICE.md`. PR #241 conserva E2/E3 pendientes y E4 bloqueada.

# CURRENT — Control OS: estabilización independiente de integración 4A (2026-11-16)

- Bloqueo de PR #242: integración WordPress 4A intermitente incluso en cambios documentales (#239). La selección previa del primer pedido tomaba el primer resultado ordenado solo por fecha; los pedidos del mismo segundo quedaban sin desempate. Se propone comparación explícita por fecha de creación y luego ID menor, con paginación estable por ID. Se conserva atribución de venta por enlace/vale/sesión, condición de pedido válido y prioridad de reasignación aprobada.
- Regresión 4A fuerza empate de sellos de tiempo y verifica prioridad de fecha si el ID es posterior. CI pendiente; sin despliegue ni fusión. CV_DEV_SANDBOX desactivado; presupuesto adicional cero. Reversión: revertir el PR antes de cualquier release.

---

# CURRENT — EVO-LOCAL-001: mitigación del entorno limpio y módulo de caja construidos (2026-11-16)

- `dev-worker.py`: los procesos hijos (git, npm, pruebas) ya NO heredan los 10 secretos (lista blanca en `child_env()`). 10 pruebas; validado en copia temporal del VPS con las 1 715 pruebas de VivaBot (0 fallos, entorno de 3 variables). **DESPLEGADO el 16-nov ~00:40 (SÍ de Ernesto):** hijos del worker con 5 variables y 0 secretos; copia previa en `/root/backups/evo001-20261115/`. Perfil `casa-viva` validado en verde con entorno mínimo; `nexo` ya falla por código/dependencias del checkout, no por el entorno.
- `scripts/ops/dev-sandbox.py` (E1, sin cablear): entrega/recupera el worktree, instala dependencias como `cvdev` y ejecuta en bwrap sin privilegios; 13 pruebas. Falta cablear el worker, rechazar symlinks, límites de memoria y E2 (copia de bwrap + perfil). Detalle: `EVO_LOCAL_001_EJECUTOR_AISLADO.md` §8.

---

# CURRENT — EVO-LOCAL-001: ejecutor aislado, evidencia y plan (2026-11-16)

- Verificado en el VPS: el aislamiento YA existe y funciona (copia dedicada de bwrap + perfil `cv-control-codex-bwrap` cargado desde el 14-nov; como `cvdev`: sin red, sin `/root` ni `~/.ssh`, `/usr` solo lectura, un solo destino de escritura, entorno limpio). `/usr/bin/bwrap` sigue fallando (RTM_NEWADDR).
- **Hallazgo:** las pruebas del Dev Worker corren como root, con red y con 10 variables secretas en el entorno (medido). Plan A: pruebas en caja con perfil propio; mitigación inmediata: entorno limpio en `run()`. Detalle: [EVO_LOCAL_001_EJECUTOR_AISLADO.md](EVO_LOCAL_001_EJECUTOR_AISLADO.md). **Nada instalado ni cambiado.** Espera 3 decisiones de Ernesto.

---

# CURRENT — EVO-LOCAL-001: piloto de punta a punta HECHO (2026-11-16 00:16 Cuba)

- **Resultado:** la tarea ficticia #150 («PILOTO: nota de prueba del programador local») llegó hasta `waiting_approval` por el camino completo: `ops_tasks` → puerta (`lista`, `tarea`, `entregar`) → `ops_prepare_dev_patch` → Dev Worker (reclamó 05:13:53 UTC, rama empujada 05:15:53) → pruebas locales `passed`. Qwen tardó 75 s en proponer; la entrega y el worker, ~2 min.
- **Qué quedó en GitHub:** rama `control-os/task-150-piloto-nota-de-prueba-del-programador-local-f`, 1 commit (`98539fa`, base `457a821`), autor «Casa Viva Control OS», `auto_merge=0`, **un solo archivo añadido** (`docs/control-os-smoke/piloto-qwen-local.md`, 4 líneas). Sin PR, sin fusión, sin despliegue. «Control OS Dev Gate» estaba en curso al cerrar este registro.
- **Aviso de honestidad:** el Dev Worker EMPUJA la rama a GitHub por sí solo (como en las pruebas del 13-nov); antes del piloto se dijo que «no se publicaría nada» y eso era impreciso: se publicó una rama de revisión, no `main`.
- **Auditoría en `ops_events`:** `dev_patch_prepared` (ai:local-proposer), `dev_claimed`, `dev_worktree_ready`, `dev_branch_pushed` (worker). Registro de la puerta sin cuerpos: 1 `lista` ok, 1 `tarea` ok, 1 `entregar` ok.
- **Carga:** VPS de 1 núcleo llegó a carga ≈ 4,7 durante ~2 min; memoria disponible ≥ 2 GB; web 200, bot online sin reinicios. Con tareas de código (tests más largos) hay que vigilar.
- **Estado final:** puerta APAGADA otra vez (fila `local_proposer` borrada 05:14 UTC; `lista` responde «apagada»), sin reservas. La fila de latido sigue. La tarea #150 queda **pendiente de aprobación humana** (nada se aprobó).
- **Hueco corregido durante el piloto:** tras `proponer N` la tarea queda reservada y `--entregar` fallaba; se añadió `local-runner.py entregar N archivo.json` (PR #238, 18 pruebas).
- **Limpieza (orden de Ernesto, 00:25):** rama borrada en GitHub (era `98539fa`; «Control OS Dev Gate» había terminado en éxito), tarea #150 `cancelled`, ejecución `blocked` («piloto cancelado») y evento `task_cancelled` en `ops_events`. Las 4 aprobaciones pendientes que quedan (8, 14, 19, 24) son anteriores y ajenas al piloto.
- **Falta:** fusionar #238; ejecutor aislado y sandbox antes de abrir la fase 2 (código); Control Center crea tareas con `payload.proposal_request`; pantallas 99–102.

---

# CURRENT — EVO-LOCAL-001: programa del portátil construido (2026-11-16)

- `scripts/ops/local-runner.py` (a mano): `lista`, `latido`, `proponer N` (reserva, genera con Qwen y GUARDA en local) y `proponer N --entregar` (además entrega a la puerta). Por defecto NO entrega. Única llamada externa: `ssh vps-gate <orden>` con lista blanca, sin shell. 16 pruebas cableadas en `validate.yml`.
- **Probado en vivo contra la puerta apagada:** `lista` y `proponer 1` → «puerta apagada» (código 3 del programa, 7 de la puerta), sin reservas ni cambios; `latido` → ok y guardó `programador_local_latido` (`qwen2.5-coder:7b`, versión 1.0).
- **Sin encender:** `crm_settings local_proposer` sigue sin existir. El piloto de punta a punta (tarea ficticia de `docs/` con `payload.proposal_request`) exige encenderla: pide el SÍ de Ernesto.
- Diseño (artifact «Casa Viva Control OS», versión del 16-nov 13:00): ya incluye las pantallas 96–130; la 102 «Programadores locales» (Ollama, OpenCode, Qwen 2.5 Coder) puede leer `programador_local_latido`. Sin respuesta escrita a las 5 preguntas de #231.

---

# CURRENT — EVO-LOCAL-001: puerta de propuestas INSTALADA, apagada (2026-11-16 00:45 Cuba)

- **Qué:** `cv-proposal-gate.py` en el VPS (`/opt/cv-proposal-gate/`, hashes = rama del PR #237 `c6c5114`; estado en `/var/lib/cv-proposal-gate/`, 0700). Clave SSH propia `cv-local-proposer-16nov` con `restrict,command=…` en `/root/.ssh/authorized_keys` (36 → 37 líneas; copia previa en `/root/backups/evo001-20261115/authorized_keys.antes-puerta`). Portátil: clave `~/.ssh/cv_local_proposer` y `Host vps-gate`.
- **Probado en vivo con la clave restringida:** `lista` → código 7 «puerta apagada»; `id`, `lista; id` y sin orden → código 2; sin shell ni PTY; reenvío de puertos bloqueado; la clave anterior sigue funcionando; `latido` → ok (escribió `crm_settings programador_local_latido`).
- **Apagada:** `crm_settings local_proposer` no existe (solo `si` la enciende). No hay tareas con `payload.proposal_request`. Nada se ha entregado al Dev Worker.
- **Reversión:** restaurar `authorized_keys` desde la copia (o borrar la línea `cv-local-proposer-16nov`); `rm -rf /opt/cv-proposal-gate /var/lib/cv-proposal-gate`; borrar la fila `programador_local_latido`; en el portátil quitar `Host vps-gate` y la clave.
- **Falta:** `local-runner.py` en el portátil (`lista → tarea → propone → entregar`, `--dry-run` por defecto), piloto con tarea ficticia de documentación (exige encender `local_proposer`), y pantallas 99–102 cuando Diseño responda en #231.

---

# CURRENT — EVO-LOCAL-001: diseño del transporte portátil ↔ VPS (2026-11-16)

- Diseño en [EVO_LOCAL_001_TRANSPORTE.md](EVO_LOCAL_001_TRANSPORTE.md): «puerta de propuestas» por SSH con comando forzado sobre `ops_prepare_dev_patch` y el Dev Worker existentes; fase 1 solo `docs/**`. **Nada instalado.** Espera 3 decisiones de Ernesto.

---

# CURRENT — EVO-LOCAL-001: proponedor local construido (2026-11-16)

- Nuevo `scripts/ops/local-proposer.py` (Qwen2.5-Coder 7B por Ollama, solo loopback): tarea → propuesta `cv-change-proposal-v1`, validada con el contrato oficial y comprobada contra los archivos reales. No aplica ni entrega; sin subprocess ni credenciales. Detalle y límites: [EVO_LOCAL_001_PROPONEDOR_LOCAL.md](EVO_LOCAL_001_PROPONEDOR_LOCAL.md).
- Pruebas: 18 (17 en Windows, 1 omitida por symlinks), cableadas en `validate.yml`; prueba real con Qwen sobre archivo de ejemplo: propuesta válida en 57 s, original intacto.
- VPS (sin cambios de código): buzón `/home/cvdev/control-os-dev` creado como `cvdev` (0700) y validado con `validate_mailbox_dir`; proceso huérfano de OpenCode cerrado; `cv-dev-worker` activo.
- **Falta:** recoger tareas `waiting_ai` desde el portátil y entregar al puente (requiere aprobación de Ernesto); ejecutor aislado y sandbox sin instalar en el VPS; EVO-IA-001 y Centro de Control a la espera de la respuesta de Diseño en #231.

---

# CURRENT — EVO-001 / BLOQUE 1: Core y servicio de imagen en producción, certificación parcial (2026-11-15 23:10 Cuba)

Sustituye la entrada «BLOQUEADA» de más abajo (incidencia resuelta; ver evidencia). No cierra EVO-001 entero.

- **Servicio `casaviva-imagen`:** reiniciado 23:01:39 (PID 2429316 → 3080096; antes corría código anterior al merge). Clave falsa → 401; clave válida a `/panel` → **403 `evo_budget_zero`**, gasto de imagen 0,000 USD; `/recorte` (local) responde. VivaBot en VPS = `3cbdeca`.
- **Core vivo:** desplegado **3.14.172** desde la rama del PR #233 (`c9bc6e9`, CI verde: validate, integration, browser, content-studio). Hashes de los 6 archivos desplegados = rama. Política cargada: `CVD_Content_Policy::billable()` → `WP_Error evo_budget_zero`. Home y /tienda/ 200, `/control/` 401 (protegido), 306 rutas `casa-viva`, sin errores PHP nuevos.
- **Deriva explicada:** el vivo era anterior a EVO-001. De los «8 distintos» del auditor, 4 solo difieren en saltos de línea; los reales eran 4 distintos + 2 ausentes. El vivo tenía un arreglo que no estaba en git (`clone` en `set_attributes`, cojines 12/14-nov): se conserva en #233 (panel-bridge idéntico al vivo).
- **Congelación:** `catalogo-fichas` retenido 23:01–23:3x con `flock` (sin editar el cron); soltado. El orquestador en ensayo solo tenía los días 14–15.
- **Copias:** Core `~/bak-20261115-cvd-3.14.171-antes-3.14.172` (7,6 MB, 308 archivos); VPS `/root/backups/evo001-20261115/` (crontab, unidad, SHA, estado previo).
- **Reversión Core:** copiar la carpeta de la copia sobre el plugin + `wp litespeed-purge all`. El servicio de imagen NO se revierte (reabriría el gasto).
- **Pendiente:** fusionar #233 (el clasificador bloqueó a la sesión; lo hace Ernesto); prueba de navegador sobre producción (solo hay CI de rama); recuperar credencial GitHub del mecanismo de release (#226, no verificado); decidir qué hacer con el cron `catalogo-fichas` (cada 15 min, solo llama a la web). Bloques 2–6 siguen detenidos hasta el SÍ de Ernesto.
- Evidencia: [EVO_001_CORE_RECONCILIATION_2026_11_15.md](evidence/EVO_001_CORE_RECONCILIATION_2026_11_15.md).

---

# Casa Viva Platform — Checkpoint operativo

## Activación limitada del panel de fotos

La tienda recibe únicamente los cuatro archivos del editor de fotos, con comprobación de huellas y respaldo. Una opción guardada para revisión pertenece a la editora y al producto. Su publicación requiere una acción separada.

## Fondo protegido de catálogo — 3.14.115

Base `111d70a`, panel 3.14.113 desplegado. La IA genera solo el fondo. El móvil coloca la foto íntegra sin recortar y conserva los detalles opacos. Las fotos de fondo complejo se mantienen completas. Las pruebas distinguen generación y publicación.

## Fotos de catálogo con IA — 3.14.113

La base 3.14.112 (d39e7b3) pasó el despliegue 41667340233 y se creó la cuenta de Zayri. Esta ampliación prepara ediciones desde una referencia mediante Cloudflare Workers AI, OpenAI Images o Higgsfield Marketing Studio. Solo el administrador configura credenciales en WooCommerce → IA para fotos. En Workers Free se aplica la cuota diaria del proveedor. Cada opción se revisa en el móvil antes de designarla imagen principal mediante la subida protegida existente. Las credenciales y el resultado en vivo se verifican por separado del despliegue.

## PREPARE — Mi catálogo y fotos para Zayri (2026-11-02)

- Petición explícita de Ernesto: panel móvil en casaviva.company, crear/editar/publicar contenido y fotos, conservar cambios humanos frente a BizneCubano; mantener precios y existencias.
- Base inspeccionada: `038050a12575e9039d9a766c25036d7fec937ada`, plugin 3.14.111. Producción actual no certificada en esta unidad. No sustituye los estados históricos siguientes.
- Rama de trabajo: `codex/zahyir-catalog-studio` (el usuario corrigió el nombre a Zayri durante el trabajo).
- Implementación y límites: `docs/herramientas/panel-contenido-zayri.md`.
- Estado: piloto construido y probado, en revisión; sin despliegue y sin cuenta creada. Contratos, Taller, WordPress/MariaDB, subida/descarga/cambio de contraseña en navegador real y suite general superados sobre `913928fa1290c3658a551b8d68a987834a6ecfeb` (Actions `41525690191`). Release `41525690210` y staging `41525690145` verdes.
- Limitación local inicial: npm interrumpido por conexión y permisos del equipo; posteriormente lint, TypeScript y build se superaron en CI sobre `913928f`.
- Costo nuevo requerido: 0. Composiciones gratuitas con fotos reales; generación nueva con Higgsfield no conectada. Skill original instalada en Codex como herramienta de trabajo.
- Revisión publicada: #167. Candidata 3.14.112. Contratos locales y prueba real WordPress/MariaDB del panel superados (Actions `41522895994`, revisión `f73428a`); recorrido de navegador y suite completa en comprobación. Reparaciones de CI preexistente documentadas en la guía.
- Evidencia de candidata `815bb0e`: Actions `41524288477` valida código, navegador general y recorrido real del panel (login limitado, guardado, subida, JPG y cambio de contraseña) en verde; contratos de release y staging verdes. La integración general detectó una fixture antigua que reutiliza dirección sin confirmar el nuevo aviso de duplicado; se adapta la fixture a esa confirmación explícita, sin cambiar la protección de producción.
- Siguiente acción: aprobación de despliegue de la revisión #167; después crear la cuenta zayri y verificar en producción con un producto controlado. No se inicia otra fase de plataforma.

## Resumen de reanudación

```text
FASE: 0 — Casa Viva Core estable
SUBFASE ACTUAL: CV-ENTREGA — Entrega a la dueña (ver docs/CASA_VIVA_ENTREGA_DUENA.md)
DOMINIO: https://casaviva.company (WP_PATH /home/u824654880/domains/casaviva.company/public_html; workflows corregidos en PR #144)
ESTADO: 3.13.9 DESPLEGADO (deploy 37328697869: portada con ofertas reales). En main sin desplegar: 3.13.10 (#151, scroll horizontal en móvil) y 3.13.11 en PR #152 (cabecera de ordenador, ventana de ofertas solo en tienda/categorías, sin «Inicio» duplicado). Recorrido clienta + gestora verde el 2026-10-06
SUBFASES ANTERIORES: CV-PREMIUM-001 (3.13.0–3.13.7), CV-CURRU (3.11.0–3.12.1), CV-LAUNCH-POLISH-002, CV-CATALOG-SYNC y CV-LAUNCH-POLISH-001 — CERRADAS
NOTA: al fusionar cambios solo documentales, `main` puede avanzar sin que producción cambie
DECISIÓN: Ernesto (2026-10-02): checkout sin confirmar pedido; Curru da existencias exactas a gestoras y solo estados a clientes; mantener identidad beige/verde; destacar con ventana las ofertas ya existentes
DECISIÓN: Ernesto (2026-10-05): quitar de la tienda todo lo que no lleve a la compra ni ayude a navegar; Higgsfield permitido para fotos y vídeos sin abuso; animaciones y ventanas orgánicas manteniendo la marca; número del negocio/bot 5354056173; recogida en la dirección de la web (Calle Conill A esquina 45 #864, Nuevo Vedado), 9:00–17:00; OpenAI ya activo en Curru
DECISIÓN: Ernesto (2026-10-06): garantía y devoluciones las atiende una persona caso a caso hasta tener política; al por mayor → WhatsApp de Lennys (53 5688 5368) con resumen; la tienda no se elimina (catálogo), solo se quita «Inicio» del menú
VIVABOT: 34/34 preguntas activas en nexo-production; servidor en d39b1d1 con CURRU_KEY; pendiente git pull de 1571947, prueba desde otro teléfono y cambio de claves expuestas en capturas
CATÁLOGO: Sobrecama tiwn (BC-102299) retenida con `catalog-sync.yml` modo hold (run 37301636740); Aspiradora de Mano Inalámbrica ya oculta (no está en BizneCubano)
PENDIENTE ANTES DE ENTREGAR: ver la lista final de docs/CASA_VIVA_ENTREGA_DUENA.md
CUENTAS DE PRUEBA: gestora de prueba creada con `test-accounts.yml` (secretos JOURNEY_GESTORA_*); recorrido clienta + gestora 100 % verde el 2026-10-06; dependienta piloto existente: cv_dependienta_piloto
FOTOS: 44 fotos reales ampliadas a 2K con Higgsfield (118 créditos) aplicadas con `catalog-photos-apply.yml` (revert disponible); 15 descartadas porque la IA deformaba etiquetas o texto
```

## Foto de cada producto en la tarjeta de la tienda (3.14.37, 2026-10-18)

- Lennys (16 y 17-oct): «Al dependiente hay que mandarle foto con el vale. Ayer se le mandó al cliente un cortinero que no era.»
- Hoy la dependienta no recibe el vale por WhatsApp: le llega el aviso web «Nuevo pedido #N» y prepara desde la tarjeta de `/ventas/` (que ya trae el vuelto). Las recogidas además salen con foto en el grupo «Recogida en tienda» (VivaBot).
- Cambio: cada producto de la tarjeta de `/ventas/` lleva su foto (120 px, se abre grande al tocarla) y su color/diseño debajo del nombre. Misma foto que el vale del mensajero: `CVD_Order_Lines::photo_url` (foto del diseño comprado; si no tiene, la del producto), usada también por `CVD_Bot_Bridge::images`.
- Sin cambios en numeración, estados, reparto, stock ni dinero. Sin mensajes nuevos por WhatsApp.
- Pruebas: `php artifacts/tests/test-vale-fotos-tienda.php`, `node artifacts/tests/test-vale-fotos-tienda.mjs` (en `validate.yml`).

## CV-FOTOS-SYNC — Fotos cambiadas en BizneCubano (2026-10-10)

- Lennys cambió fotos en BizneCubano (albornoz, alfombra persa, árbol de Navidad, cestos, mochilas, puertas de corredera…) y no llegaban: la sincronización solo copiaba la foto al crear el producto.
- `cvd_sync_photo`: compara la foto de BizneCubano con `_cvd_bc_image` (o, la primera vez, con el `_source_url` de la foto original) y, si cambió, la descarga y la pone como principal (olvida la versión mejorada vieja). Informe: `photo_updates`.
- La caja (NEXO) toma la miniatura de la web en su importación horaria.

## Mejora #23 — Recogida sin datos de mensajería (3.13.27, 2026-10-10)

En recogida en tienda el pedido ya no guarda dirección, reparto, mapa, fecha/horario ni vuelto precargados de pedidos anteriores; el resumen del formulario no muestra vuelto. Contrato en `test-store-pickup-5b.mjs`.

## CV-MERCADO-001 — Mercado «bajo pedido» (3.13.26, 2026-10-09)

- `CVD_Market_Bridge`: `POST /casa-viva/v1/bot/market/publish` y `/bot/market/{item}/unpublish` (clave X-Vivabot-Key). SKU `MK-<id>`, categoría «Bajo pedido», sin control de stock, aviso «confirmamos disponibilidad en 1 hora», comisión fija = mitad de la ganancia (la calcula VivaBot). Fotos solo desde Supabase Storage. El proveedor no se guarda en la web.
- La sincronización de BizneCubano ya no oculta los `MK-*`.
- Decisiones de Ernesto: publicar en Casa Viva; proveedor +5/+10 USD, >300 USD +5 %; comisión del gestor = mitad; al vender, VivaBot pregunta a Ernesto antes de escribir al proveedor.
- Pruebas: `php -l`, `test-market-bridge.mjs`. Sin prueba E2E contra producción todavía.

## CV-EQUIPO-001 — Arreglos pedidos por Lennys y Thaly (3.13.25, 2026-10-09)

- Vale de WhatsApp: nueva sección *PAGO* con forma de pago y vuelto (misma regla que el bot, `CVD_Bot_Bridge::change_label`), y enlace 📷 a cada producto para que mensajero y dependienta no confundan modelos parecidos.
- Botón de ayuda (asistente contextual): quien entra por el enlace de una gestora ve el WhatsApp de esa gestora, no el central.
- Ficha de producto: se ocultan al mostrar las líneas de la descripción con precio (vienen de BizneCubano); el precio válido es el de WooCommerce/gestora. No cambia datos.
- Pendiente de comprobar en producción: botón «atrás» a mitad del pedido y vuelta tras «continuar por WhatsApp» (carrito vacío + «Volver a la tienda» ya existían).
- Pruebas: `php -l` en los 4 archivos; prueba local de `strip_description_prices`. No se ejecutó el recorrido E2E.

## CV-HOME-DEALS — Portada con ofertas reales (2026-10-05)

Auditoría en móvil (390×844) de Amazon, Gymshark y Brooklinen (Shein y Temu bloquean con captcha): ninguna usa una foto con «Comprar ahora»; la primera pantalla enseña buscador, categorías, a dónde entregan y productos con precio. En Casa Viva el primer precio estaba a 1.381 px.

- 3.13.8: Curru en móvil, la barra inferior (z-index 9998) tapaba la caja de escribir del panel (80). Con Curru abierto el panel sube a 10000 y se ocultan la barra inferior y el WhatsApp flotante.
- 3.13.9: portada = buscador y categorías → «📍 Entregamos en La Habana · mira cuánto cuesta en tu zona» (tarifario) → «Ofertas de hoy» (hasta 8 rebajas reales de WooCommerce con -%, precio y Añadir/Elegir) → Más vendidos → Nuevos → Regala desde fuera → Ventajas. Sin rebajas solo sale la línea de entrega. Se ocultan la foto con «Comprar ahora», la estantería de ofertas repetida, «Comprar por habitación» y el Blog en la cabecera; la ventana de ofertas ya no sale en la portada. Reordenado con CSS sobre el tema `casa-viva-storefront` (no versionado en el repo). Primer precio a 657 px y botón Añadir a 731 px en la copia local verificada.
- Curru: responde la dirección y el horario de recogida; la IA ya no recomienda productos en respuestas de mensajería, pagos o recogida.
- Auditoría móvil de producción: nuevas comprobaciones de precio visible sin scroll y caja de Curru tocable.
- `catalog-sync.yml`: modos `hold`/`unhold` con `skus`; lo retenido (`_cvd_hold`) no se republica.

## CV-PREMIUM-001 — Auditoría de recorrido y tienda premium (2026-10-02)

Auditoría `customer-journey-audit.yml` (run 37041230617) como clienta en móvil: portada → categoría → producto → carrito → checkout sin confirmar → Curru. Investigación de patrones en `/mnt/project-files/auditoria/investigacion-tiendas-premium-2026-10-02.md`.

- Curru y existencias: gestoras y operación reciben cantidades exactas por modelo (`CVD_Product_Search::cards( $ids, true )`); clientes y visitantes solo «Disponible», «Últimas unidades» (umbral de stock bajo de WooCommerce) o «Agotado». El endpoint público de búsqueda nunca expone cantidades. La IA recibe `CONTEXTO` y la misma regla.
- Ficha de producto: barra fija de compra en móvil (encima de la barra inferior; Curru sube y el saludo se oculta), garantías junto al botón con los métodos reales (WhatsApp o transferencia, entrega por municipio o recogida, Curru) y disponibilidad sin cantidades para clientes.
- Portada: imagen generada con Higgsfield (sin productos, enlace remoto `_min.webp`, filtro `cvd_home_hero_image`), titular, botón a la tienda y línea de entrega.
- Ofertas: ventana una vez por sesión (al bajar más de la mitad o a los 15 s; nunca en ficha, carrito, checkout ni cuenta) con hasta 4 productos rebajados reales; insignias con porcentaje real; `/tienda/?cvd_ofertas=1` lista solo ofertas.
- Verificación 3.13.0 en producción: portada con imagen, opciones de entrega como tarjetas, Curru a la clienta dice «algunas están en últimas unidades» sin cifras; 14 páginas sin scroll horizontal ni imágenes rotas. Hallazgo: la barra fija no aparecía porque el botón quedaba tapado pero «visible» bajo la barra inferior; 3.13.1 descuenta la barra inferior, oscurece el degradado de la portada y oculta el saludo de Curru en la portada móvil (tapaba el buscador).
- 3.13.2: la barra fija se muestra cuando el botón no se ve entero (`intersectionRatio < 0.99`); el recorrido de auditoría registra `report.buybar` (existencia, visibilidad y posiciones del botón y la barra inferior).
- 3.13.3: la barra fija se calcula con la posición real del botón y de la barra inferior en cada desplazamiento (antes la barra inferior aún no existía al cargar el script). Probado en Chromium local: visible al cargar con el botón tapado, oculta con el botón entero, visible al pasarlo.
- 3.13.3 (portada): texto más corto («Todo para tu casa, sin salir de ella.»), dos botones («Comprar ahora» y «Ver ofertas», este solo si hay rebajas) y portada más baja en móvil (380 px) para que el buscador quede en la primera pantalla.
- 3.13.4: Curru y el botón de WhatsApp del tema (`.cv-whatsapp`) quedan en una sola columna a la derecha, con el mismo tamaño y margen; WhatsApp ya no queda tapado por la barra inferior y ambos suben con la barra fija de compra. En producción estaban a 12 px de distancia horizontal y WhatsApp quedaba medio oculto (sonda en la rama cv-probe-floats).
- 3.13.5: pedido recibido al estilo Colo Shop (plantilla propia `templates/checkout/thankyou.php`): título «Tu pedido está listo», referencia, botón principal «Confirmar por WhatsApp», tarjeta con productos y totales, tarjeta de entrega o recogida, y «Ver seguimiento» (solo dueña con sesión, regla 7A) + «Seguir comprando». Se quitan las tarjetas de resumen de WooCommerce, «Dirección de facturación», el botón azul de mapa y el «Seguir mi pedido» duplicado. Vale de WhatsApp 3.2.0 con el formato de Colo Shop (PEDIDO CASA VIVA, TOTAL, PRODUCTOS, IMPORTES, ENTREGA/RECOGIDA, CLIENTE, SEGUIMIENTO, PARA CONFIRMAR). Curru y el WhatsApp flotante no aparecen en carrito, pago ni pedido recibido. Carrito: nombre sin subrayado, sin la descripción genérica y sugerencias en cuadrícula de 2 columnas (máximo 4) con botones verdes.
- 3.13.7: VivaBot (WhatsApp) puede preguntar a Curru en `/casa-viva/v1/curru/ask` con la cabecera `X-VivaBot-Key` y `context` = cliente o gestora. La clave se genera en WooCommerce › Curru (asistente), se muestra una vez y solo se guarda su hash (`cvd_vivabot_key_hash`). Clave incorrecta: 401, nunca modo visitante. Límite propio: 600 por minuto. Se registra el contexto, no la pregunta.
- 3.13.6: la barra negra de administrador de WordPress ya no aparece sobre la tienda (filtro `show_admin_bar`), ni siquiera para administradores; el panel sigue en /wp-admin/. Pedido de Ernesto tras verla en el carrito.
- Marca: botones de carrito, checkout y añadir en verde Casa Viva; precio en verde, rebaja en terracota; opciones de entrega del checkout como tarjetas con radio propio.

## CV-CURRU — Asistente y buscador (2026-10-02)

Referencias auditadas: 23 y 28 (asistente «Veci») y Colo Shop (asistente «Colo»). Se adoptó: avatar con foto, panel lateral, respuestas con tarjetas de producto y precio real de WooCommerce, añadir al carrito desde el chat, dictado por voz, búsqueda tolerante (acentos, plurales, sinónimos cubanos) y tarifas por municipio sin inventar precios.

- 3.11.0 (PR #128): `CVD_Product_Search` (REST `casa-viva/v1/products/search`), Curru (`casa-viva/v1/curru/ask`, reglas locales: productos, mensajería por zona, pedidos, pagos, gestora, mensajero, WhatsApp), página WooCommerce › Curru para la foto.
- 3.11.1: buscador de la tienda (tienda, categorías y resultados) con sugerencias con foto y precio, combobox accesible por teclado; también mejora los buscadores del tema. La búsqueda de la tienda usa el mismo motor tolerante («pailas» encuentra sartenes). La auditoría móvil de producción abre Curru (`curru.png`) y prueba el buscador (`buscador.png`).
- 3.12.0: Curru con dibujo propio (`assets/curru-avatar.svg`, se usa si no hay URL de foto en wp-admin) e IA compatible con OpenAI (`CVD_Curru_AI`). Ernesto eligió OpenAI el 2026-10-02. La clave la pega él en WooCommerce › Curru (nunca en el repo ni el chat). La IA solo redacta sobre la respuesta local verificada y elige productos de una lista cerrada; tarjetas y precios salen de WooCommerce; si falla, responde la regla local.
- 3.12.1: avatar por defecto = ilustración 3D generada en Higgsfield (enlace remoto, versión `_min.webp`); si no carga, el navegador usa `curru-avatar.svg`. Para fijarla de forma permanente, subirla a Medios y pegar su URL en WooCommerce › Curru.
- Pendiente: Ernesto pega la clave de OpenAI en WooCommerce › Curru.

## CV-CATALOG-SYNC — Catálogo real desde BizneCubano (2026-10-02)

Fuente del catálogo de Casa Viva: https://casaviva.biznecubano.com (SKU `BC-<id>`). Ernesto aprobó por escrito publicar, igualar stock y ocultar lo que no está en BizneCubano.

- Herramientas: instantánea (`catalog-source-snapshot.yml`), sincronización `catalog-sync.yml` con modos dry-run, apply y restore (`scripts/catalog/biznecubano-sync.php`), auditoría de fotos `catalog-photo-audit.yml`. PRs #123, #124 y #125.
- Apply 2026-10-02 (run 37014528832): 122 productos publicados con foto, 119 ajustes de stock con conteo en el libro de inventario, 126 ocultos como privados (63 NEXO y 63 BC retirados), 0 errores. Re-ejecución dry-run: 0 cambios (idempotente).
- Reversión: `catalog-sync.yml` modo `restore` vuelve a publicar lo ocultado (`_cvd_sync_hidden_from`).
- Auditoría móvil posterior #37014654943: 14 páginas sin scroll horizontal ni imágenes rotas.
- Fotos: 119 de 208 necesitan mejora (65 resolución, 29 foto real nueva, 16 fondo, 9 reencuadre). Lista: `/mnt/project-files/catalogo/fotos-revision-2026-10-02.csv`. Mejora con Higgsfield pendiente de que Ernesto valide 2 muestras.
- Pendiente: 29 productos variables (precio y stock por variante, sin sincronizar) y "Sobrecama tiwn" sin precio en BizneCubano.

## Qué estamos construyendo

Casa Viva será el primer comercio certificado de una plataforma multiempresa que después incorporará Todo Hogar, Estilo y Hogar y Dulce Hogar, Gestor multitienda, mensajería global y contabilidad offline para Windows.

No se deben iniciar esas fases hasta cerrar completamente la Fase 0.

## Cierre de CV-RECOVERY-001

| Paso | Estado | Evidencia |
| --- | --- | --- |
| 001.1 Crear Blueprint y checkpoint vivos | CERRADO | PR #116 fusionado |
| 001.2 Corregir orden de carga del guard del mensajero | CERRADO | Contratos de recarga verdes |
| 001.3 Incorporar prueba omitida al CI | CERRADO | Regresiones incluidas en `validate.yml` |
| 001.4 Inventariar pruebas huérfanas | CERRADO | Cuatro pruebas añadidas al CI; inventario documentado |
| 001.5 Ejecutar validación completa | CERRADO | aplicación, integración, navegador, release y staging verdes |
| 001.6 Fijar y desplegar SHA candidato | CERRADO | `001589c4ce4d0d31ac4986a18fbb20168e8369b3` |

## Evidencia de producción

- workflow: **Deploy prototype Casa Viva #20**;
- ejecución: `36466381630`;
- resultado: **success**;
- duración del job: 37 s;
- SHA solicitado y desplegado: `001589c4ce4d0d31ac4986a18fbb20168e8369b3`;
- release reproducible y checksum: aprobados;
- identidad del plugin desplegado: aprobada por lectura SSH del marcador;
- análisis real de un vale sintético: aprobado;
- smoke contra `https://casavivadecuba.com`: aprobado;
- rollback automático: disponible y no ejecutado porque no hubo fallo;
- portada pública: accesible y con catálogo renderizado tras el despliegue.

## CV-LAUNCH-POLISH-001 — Portada móvil, SEO y categorías

Objetivo de esta unidad:

- corregir el SEO técnico ausente en la portada sin depender del tema;
- garantizar un único H1 semántico aunque el tema no invoque `wp_body_open`;
- sustituir automáticamente imágenes rotas de enlaces a categorías WooCommerce por un fallback local y ligero;
- verificar el comportamiento en viewport móvil 390×844;
- conservar intactas tarifas, stock, pedidos, comisiones, payouts y demás lógica comercial.

Evidencia previa observada en producción:

- la portada pública indexada no expone H1;
- el título público observado es genérico;
- las cuatro tarjetas visibles de “Comprar por habitación” enlazan a categorías reales de WooCommerce;
- el tarifario sigue cubierto por `test-shipping-quote-contract.mjs` y `test-tariffs-mobile-polish.mjs` sin cambiar importes.

Implementación:

- PR: #118;
- candidato plugin: `3.10.14`;
- rama: `cv-launch-polish-001`;
- prueba de contrato: `artifacts/tests/test-launch-polish-001.mjs`;
- prueba navegador móvil: `tests/browser/launch-polish.spec.js`;
- SHA certificado del candidato (head del PR): `9698dda112c98c585b2f8e3444aa40348dbe344e`;
- CI de evidencia sobre ese SHA: Validar aplicación #393 (validate, integración y browser verdes), release #260 verde y staging #252 verde;
- viewport verificado: 390×844;
- fusionado a `main` en `635dd1fc55aaf12f0e54080cef6c3f9743c763b2` y desplegado con Deploy prototype #21 (https://github.com/ernest196391/Casa-Viva/actions/runs/36856337660);
- pendiente: comprobación visual en móvil de H1, title, description, canonical, tarjetas de categoría y tarifario.

### Verificación en producción (2026-10-01)

Nuevo workflow **Auditoría móvil de producción** (`.github/workflows/production-mobile-audit.yml`, PR #120): Playwright 390×844 de solo lectura contra `https://casavivadecuba.com`, automático tras cada despliegue exitoso y bajo demanda. Publica capturas y `report.json` en la rama `evidence/production-mobile`.

Resultado sobre 3.10.14:

- portada: title, meta description, canonical y un único H1 correctos;
- tienda, tarifario, Mi cuenta, accesos de gestoras y mensajeros, 6 categorías y 2 productos: HTTP 200, un H1, sin errores fatales ni de consola;
- imágenes de categoría: ninguna rota hoy (el fallback queda como protección);
- **P1 móvil encontrado**: la portada tenía scroll horizontal (647 px en viewport de 390). Causa: los textos `screen-reader-text` de precios rebajados de WooCommerce en la estantería “Ofertas” (`.cv-market-shelf`, con `overflow-x:auto`) son absolutos y su bloque contenedor quedaba fuera del carrusel, así que no se recortaban. Corrección en 3.10.15: `.cv-market-shelf{position:relative}` en `launch-polish.css` (solo portada). Reproducido y comprobado en Chromium (648 → 390).
- la auditoría de producción ahora falla si cualquier página auditada tiene scroll horizontal.

Cierre en producción: 3.10.15 desplegado con Deploy prototype #23 (https://github.com/ernest196391/Casa-Viva/actions/runs/36867758303). La auditoría automática posterior confirmó HTTP 200, un H1, cero imágenes rotas y ancho 390/390 en portada, tienda, tarifario, Mi cuenta, accesos de gestoras y mensajeros, 6 categorías y 2 productos.

Pendiente menor de catálogo: un producto sin imagen (placeholder de WooCommerce) visible en portada/tienda y en Electrodomésticos; requiere subir la foto real del producto.

Criterio de cierre de la unidad:

- validate, integración y navegador verdes;
- PR #118 fusionado a `main`;
- producción no cambia hasta una aprobación explícita de despliegue;
- después del futuro despliegue, comprobar H1, title, description, canonical, tarjetas de categoría y tarifario desde móvil antes de marcar producción cerrada.

## Hallazgos que continúan abiertos en Fase 0

### P2 — Lanzamiento web

- SEO de portada, resiliencia de imágenes y scroll horizontal: cerrados en 3.10.15;
- sanear catálogo y accesos;
- botones “Añadir al carrito” de tienda/categorías en azul genérico de WooCommerce con texto oscuro: bajo contraste y fuera de la identidad (portada sí usa el verde Casa Viva);
- tarifario móvil: la tarjeta de resultado muestra “Copiar” y “Compartir” vacíos antes de elegir zona;
- producto “Aspiradora de Mano Inalámbrica” sin foto (placeholder WooCommerce): requiere la imagen real;
- ejecutar cierre funcional de compra, vale, IA, mensajería y roles.

### Mantenimiento no bloqueante

- GitHub advierte que `actions/checkout@v4` todavía usa Node.js 20 y será forzado a Node.js 24;
- `ubuntu-latest` migrará a Ubuntu 26 a partir del 19 de octubre de 2026;
- estas advertencias no afectaron el despliegue #20, pero deben entrar en mantenimiento técnico.

## Panel de Zayri v2 (rama `diseno/panel-v2`, 14-nov-2026)

- Servidor (ya probado): escalones del HERO (recorte gratis → IA de pago), publicación automática D152, restaurar, 3 estados, prioridades, matriz y bandeja «Dudas del catálogo». Incluye #187 (`vps_image`) y `vps_recorte` (a856eec).
- Móvil (nuevo): lista con HERO, prioridad y filtros; pestaña Fotos (HERO · LIFESTYLE · BENEFICIO, comparar con 3 estados, tope del día); Anuncio sin precio ni logo con encuadre por la caja del producto, puntos por foto y pie PROOF; bandeja con voz o texto.
- Estado: PR abierto hacia `main`, sin desplegar. Espera a la sesión de imágenes: servidor real de dudas en el VPS y recorte real.

## Próxima compuerta

CV-LAUNCH-POLISH-002 cerrada (3.10.16): Añadir al carrito en verde #006068 con texto blanco en tienda/categorías; Copiar/Compartir del tarifario ocultos hasta consultar una tarifa.

Siguiente: entrega a la dueña para revisión; pendientes de catálogo en la sección CV-CATALOG-SYNC (29 fotos reales nuevas, 29 productos variables).

No iniciar Foundation multiempresa, nuevas tablas, compras de servicios ni cambios sobre pedidos reales sin aprobación expresa.

## Protocolo de continuidad

Al retomar el proyecto:

1. leer este checkpoint y el Blueprint maestro;
2. comprobar por separado que producción continúa en el SHA certificado y que `main` contiene ese SHA como ancestro o documenta explícitamente cualquier delta posterior;
3. ejecutar solamente la próxima acción aprobada;
4. actualizar estado, evidencia, costo y próxima compuerta;
5. no avanzar de fase por inferencia ni por CI verde.

## PREPARE — Aserezon, piloto de borradores (2026-11-13)

- Base: `main` actualizado (rama `prep/aserezon-wp-drafts-20261113-r2`); reemplaza la rama antigua del PR #192 para evitar arrastrar commits anteriores.
- Incluye cuatro referencias iniciales en `data/catalog/aserezon-piloto-borrador.json`, importador WP-CLI idempotente y flujo GitHub Actions/Hostinger `aserezon-drafts.yml`, sin publicación automática.
- El importador **solo crea borradores ocultos** y nunca edita productos existentes; stock sin confirmar e imágenes sin asignar.
- Autorización de fotos y publicidad expresada por Ernesto. Falta identificar las fotografías originales exactas y revisar técnicamente las tres complementarias IA por SKU.
- Precios y comisiones son propuestas, no condiciones formalmente aceptadas por Aserezon. Reservas, pagos y liquidación pendientes de validar.
- `dry-run` desde la rama habilitado como prueba de acceso a WP-CLI; la ejecución real y el informe del servidor todavía deben verificarse. Prohibido declarar importado sin evidencia.
- No desplegar plugin, no modificar operaciones financieras ni stock, no publicar productos hasta revisión de calidad y acuerdo comercial.

## PREPARE — OpenCode × Control OS, Fase A (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `8b8c0ab` (3.14.169). Solo repo local: sin push, sin despliegue, sin acceso a `/root`, sin servicios ni Supabase real.
- `scripts/ops/dev-worker.py`: los claims `mode == "ai"` ya no solo marcan `waiting_ai`; ahora preparan un trabajo para el sandbox OpenCode con `build_ai_job` (whitelist estricta del payload `dev`, sin secretos por diseño más redacción defensiva de claves/valores con forma de credencial; el código de las ediciones viaja verbatim), spool local con permisos 0700/0600 y TTL de 45 minutos (`expires_at`).
- Caducidad: `expire_waiting_ai_runs` falla las runs `waiting_ai` vencidas, libera la tarea, emite `ops_events.ai_job_expired` y purga el spool; `clean_expired_ai_jobs` borra también trabajos caducados o corruptos en cada pasada del worker.
- Manejo de errores: cualquier fallo de validación o escritura deja run y tarea en `failed` con evento `ai_job_failed`, sin tirar el loop del worker. Las credenciales se cargan ahora en `main()` (`load_credentials`), necesario para poder importar el módulo en pruebas; el comportamiento del CLI no cambia.
- Pruebas: `scripts/ops/tests/dev-worker.test.py` (27 casos: paquete sin secretos, TTL, spool, redacción, rutas seguras y ediciones estructuradas sobre directorio temporal), cableado en `validate.yml` junto al `py_compile` del Ops Bridge. Local: 27/27 OK; `npm run lint/typecheck/build` no ejecutados por falta de `node_modules` en este equipo (limitación conocida).
- Pendiente (Fases B+): consumidor sandbox OpenCode que lea el spool, contrato de devolución de resultados y publicación de la auditoría de la integración como documento propio del repo.

## PREPARE — OpenCode × Control OS, Fase B (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `18c91c0`. Solo repo local: sin push, sin merge, sin despliegue, sin Supabase real y sin cambios en servicios del VPS.
- **Buzón cvdev (ajuste de Fase A)**: el spool pasa de `/root/control-os-dev/ai-jobs` a `/home/cvdev/control-os-dev/jobs` (`CV_AI_MAILBOX`, constante `AI_MAILBOX`). El worker root ya no crea el buzón: `validate_mailbox_dir` exige que exista, provisionado como cvdev sin escritura de grupo/otros, o el trabajo IA falla con error claro. Los paquetes viajan `0644` (cvdev debe poder leerlos); la confidencialidad la aporta el directorio `0700`, no la redacción. Pruebas de Fase A actualizadas al nuevo contrato.
- **Ejecutor cvdev (nuevo)**: `scripts/ops/opencode-executor.py`, corre como cvdev (rechaza euid 0). Buzón `jobs/claims/results/dead/work` con validación de permisos en cada pasada (`ensure_mailbox`).
- **Entrega atómica y duplicados**: productor escribe tmp+rename; el ejecutor reclama por `rename(jobs→claims)` (dos ejecutores nunca toman el mismo trabajo), flock sobre el reclamo como segunda barrera, y resultado previo existente impide re-ejecución sin sobrescribir nada. Todo lo anómalo va a `dead/` con `.motivo.txt` (nombres inválidos, IDs inseguros, JSON corrupto, permisos alterables, caducados, duplicados).
- **Validación sin confianza en el productor**: versión exacta, modo `ai`, solo repo `casa-viva` (ruta de configuración local, nunca del paquete), identificadores con regex estricta, whitelist de claves en paquete/instrucciones/operaciones, rutas relativas sin traversal/.git con `allow_paths` y bloqueo duro de `.github/`, `package.json` y `package-lock.json`, límites de tamaño (8 ops, 120k, diff ≤12 archivos y ≤1200 líneas).
- **Ejecución aislada**: worktree desechable `git worktree add --detach` en `work/run-<run_id>`; únicos subprocesos git de lista blanca (rev-parse, worktree add/remove/prune, status -uall, diff/--check, add -N), sin shell ni red. Operaciones solo `create_file`/`replace_file`/`text_replace` (expected_count 1..5) y `write_smoke_file` en `docs/control-os-smoke/*.md`. Rechazo de symlinks (escape de workspace) y de cambios inesperados (status debe coincidir exactamente con lo esperado).
- **Caducidad coherente**: TTL 45 min en paquete, reclamo y resultado; trabajos vencidos van a `dead/` sin ejecutar ni escribir resultado (la run la falla el worker); `sweep_expired` cuarentena reclamos vencidos y resultados no recogidos.
- **Resultados para revisión**: `results/task-run-<run_id>.json` (`opencode-result-v1`) con estado, error, `base_sha`, `changed_paths` y diff unificado; escritura atómica 0600. Nunca push, merge, commit, branch ni despliegue (prueba verifica HEAD, ramas y árbol del repo tras ejecutar).
- **Pruebas**: `scripts/ops/tests/opencode-executor.test.py` (47 casos) + escaneo estático AST de la fuente (sin urllib/socket/ssl/http/ssh, sin shell, sin `/root` ni credenciales, sin Popen directo). Cableado en `validate.yml` junto a las 30 pruebas de Fase A. Local: 47/47 y 30/30 OK; `npm run lint/typecheck/build` no ejecutables por falta de `node_modules` en este equipo (limitación conocida).
- **Documento**: `docs/CONTROL_OS_OPENCODE_EXECUTOR.md` (contrato del buzón, permisos de provisionamiento, invariantes de seguridad y contrato de resultados para Fase C).
- Pendiente (Fase C): consumidor root de `results/` con revisión humana, auditoría de la integración y cierre de la run en Supabase.

## PREPARE — OpenCode × Control OS, Fase C simulada (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `8dcef34`. Solo repo local: sin push, sin merge, sin despliegue, sin Supabase real y sin cambios en servicios del VPS.
- **Consumidor de resultados (nuevo)**: `scripts/ops/opencode-result-consumer.py`, rol root, modo simulación con `SimStore` (archivo JSON 0600 con runs/tareas/eventos ficticios espejo de `ops_dev_runs` / `ops_tasks` / `ops_events`). Sin subprocesos, sin red, sin rutas de sistema ni credenciales (escaneo estático AST).
- **Recepción y validación sin confianza en el ejecutor**: versión exacta `opencode-result-v1`, whitelist de claves, identificadores seguros, solo repo `casa-viva`, marcas de tiempo coherentes (expires_at > finished_at > -, TTL ≤ 3600), tamaños acotados (archivo ≤1MB, diff ≤400k, logs ≤10×2k, error ≤8k), `completed` exige `base_sha` 40-hex + `changed_paths` 1..12 + diff no vacío, `failed` exige error y sin cambios residuales. Contrato del doc corregido para reflejar los campos reales que el ejecutor escribe (`executor`, `task_public_id`, `ttl_seconds`).
- **Conciliación**: el resultado solo se acepta si la run existe y está en `waiting_ai` y `task_id`, `repo` y `task_public_id` concilian con run y tarea. Huérfanos e inconciliables van a `dead/` con `.motivo.txt` (nada se borra en silencio); cualquier archivo con nombre no conforme en `results/` también.
- **Duplicados**: resultado de una run que ya no está en `waiting_ai` (`awaiting_approval`, `approved`, `rejected`, `failed`) es duplicado → `dead/` sin sobrescribir la revisión en curso ni su diff.
- **Caducidad**: resultado vencido → `dead/`; runs `waiting_ai` sin resultado tras TTL 45 min fallan con `ai_job_expired` liberando la tarea (espejo de `expire_waiting_ai_runs` de Fase A). `flock` sobre `consumer.lock` evita dos consumidores concurrentes.
- **Aprobación humana**: `completed` válido → run `awaiting_approval` (conserva diff, base_sha, changed_paths) y tarea `waiting_approval` con `approval_required` y diff en `result`. `pending_approvals` lista los cambios y `record_decision` registra `approve`/`reject` con actor y motivo; el sistema no integra nada por sí (sin commit/push/merge/deploy).
- **Pruebas**: `scripts/ops/tests/opencode-result-consumer.test.py` (28 casos con datos ficticios: validación, conciliación, duplicados, caducidad, cuarentena, decisiones humanas, persistencia de la tienda, escaneo estático). Cableado en `validate.yml` con `py_compile` del consumidor. Local: 28/28, 47/47 y 30/30 OK; `npm run lint/typecheck/build` no ejecutables por falta de `node_modules` en este equipo (limitación conocida).
- **Documento**: `docs/CONTROL_OS_OPENCODE_EXECUTOR.md` ampliado con la sección "Consumidor de resultados (Fase C, modo simulación)" y contrato de resultados corregido.
- Pendiente (Fase D): tienda real de `ops_dev_runs`/`ops_tasks` contra Supabase, decisión humana con evidencia, propuesta de rama determinista tras aprobación y publicación de la auditoría de integración.

## PREPARE — OpenCode × Control OS, Fase D: adaptador real de CLI (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `d10b1ef`. Solo repo local: sin push, sin merge, sin despliegue, sin Supabase real y sin cambios en servicios del VPS.
- **Adaptador de CLI (nuevo)**: `scripts/ops/opencode-adapter.py`, superficie exclusivamente la interfaz documentada `opencode run --pure -m opencode/glm-5.3-flash-free --format json`. Comando construido por lista blanca (sin shell, sin `--auto`, sin argumentos del paquete), entorno del hijo por lista de conservación (nunca hereda claves de servicio ni tokens), timeout 5..900 s con muerte del grupo de procesos (SIGKILL al process group), límites de recursos en el hijo (RLIMIT_FSIZE sobre la salida, RLIMIT_CPU, RLIMIT_AS 2 GiB, RLIMIT_NPROC, RLIMIT_CORE=0) y validación estricta de la salida (JSON Lines: cada línea un objeto con `type` string; el texto solo se extrae de eventos `text` con `part.text` o `text`; desviación = error de protocolo). Errores tipificados con código de salida: uso 2, deshabilitado 3, bloqueado 4, timeout 5, límite de salida 6, protocolo 7, CLI 8.
- **Bloqueo documentado**: `ISOLATION_GUARANTEED = False`. `--pure` solo desactiva plugins externos; NO aísla archivos, comandos ni red. Hasta implementar restricciones efectivas de permisos y un entorno aislado (sandbox sin red con escritura limitada al workspace, p. ej. nsjail/bubblewrap), el adaptador se niega a invocar el binario real (`AdapterBlocked`). Ningún proceso invoca el modelo automáticamente.
- **Integración desactivada por defecto**: doble interruptor `CV_OPENCODE_MODEL_ENABLED=1` y `CV_OPENCODE_SANDBOX_ATTESTED=1`; aun activados, el binario real sigue bloqueado por la invariante anterior. `invoke()` es la única puerta de alto nivel; el ejecutor de Fase B y el consumidor de Fase C NO la usan (prueba estática verifica que no hay cableado).
- **Conservado intacto**: ejecutor determinista (operaciones estructuradas + smoke), buzón atómico, consumidor de resultados con conciliación y aprobación humana.
- **Pruebas**: `scripts/ops/tests/opencode-adapter.test.py` (46 casos, TODOS con simulador de CLI en directorio temporal; ninguna prueba invoca el modelo ni el binario `opencode`). Cubren puerta por defecto apagada, bloqueo de aislamiento, comando exacto, scrubbing de entorno, límites (SIGXFSZ por RLIMIT_FSIZE), timeout con verificación de que el grupo muere realmente, JSONL estricto, errores de CLI/contrato del runner, `main()` con códigos de salida y escaneo estático (sin shell, sin red, sin `--auto`, sin secretos ni rutas de sistema en la fuente). Cableado en `validate.yml` (py_compile + suite). Local: 46/46, 47/47, 28/28 y 30/30 OK; `npm run lint/typecheck/build` no ejecutables por falta de `node_modules` en este equipo (limitación conocida).
- **Documento**: `docs/CONTROL_OS_OPENCODE_EXECUTOR.md` ampliado con la sección "Adaptador real de CLI (Fase D)".
- Pendiente (Fase E): sandbox efectivo con restricciones de permisos y red, calibración del vocabulario de eventos en la primera invocación real supervisada, tienda real contra Supabase y propuesta de rama determinista tras aprobación.

## PREPARE — OpenCode × Control OS, Fase E: propuesta de sandbox Bubblewrap + AppArmor (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `76c84b0`. Solo repo local: sin push, sin merge, sin despliegue, sin comandos de inspección del sistema (bwrap, aa-status, sysctl) ni cambios en el VPS. **La propuesta NO está instalada, NO ha sido probada y nada afirma que el sandbox funcione.**
- **Diagnóstico (hipótesis a validar en instalación supervisada)**: `uid_map: Permission denied` = mediación de user namespaces de AppArmor 4.0 en Ubuntu 24.04 (bwrap sin perfil no puede crear el namespace); `net_admin` = síntoma posterior (sin userns, bwrap cae al camino privilegiado y no puede configurar el loopback). Hipótesis central: un perfil adjunto a `/usr/bin/bwrap` con `userns,` habilita el camino sin privilegios y elimina ambos errores.
- **Perfil propuesto** (`scripts/ops/sandbox/opencode-sandbox.profile`, NO instalado): `abi 4.0`, adjunto a `/usr/bin/bwrap`, regla `userns,` (único permiso nuevo), `deny network,` (defensa en profundidad), hijos `ix`, escritura limitada al worktree y `/tmp`, sin `flags=(unconfined)` (prohibido) y sin capability alguna.
- **Comando bwrap por módulo** (`scripts/ops/sandbox/opencode-sandbox-spec.py`, sin ejecutar nada): montaje mínimo documentado — sistema read-only (`--ro-bind /usr`, `--ro-bind-try` para ssl/localtime/ld), `--unshare-all`, `--clearenv` + entorno por lista blanca, un único destino de escritura real (worktree `run-<id>`), tmpfs sobre la raíz de worktrees para ocultar los demás run, `--die-with-parent`/`--new-session`. `validate_command` rechaza `--share-net`, `--dev-bind`, segundo `--bind`, binds de `/etc`, traversal y shell en argv.
- **Arnés de pruebas negativas** (`scripts/ops/sandbox/verify-sandbox.py`, para ejecutar DESPUÉS de instalar, exige `--workspace` + `--execute`; CI nunca lo ejecuta real): bootstrap de arranque, control positivo de escritura en el worktree, denegación de escritura en `/usr/bin`, `/etc` y `/`, home enmascarado, `/etc/shadow` ausente y bloqueo de salida TCP. Veredictos: `ok` (0), `failed` (5), `sandbox-no-operativo` (4). Un fallo se corrige en la propuesta, nunca aflojando el perfil.
- **Pruebas de configuración** (`scripts/ops/tests/opencode-sandbox.test.py`, cableadas en `validate.yml` con `py_compile` de ambos módulos): invariantes del perfil y mutaciones negativas, contrato exacto del comando y rechazos, lógica del arnés con runner falso (sin bwrap), protecciones de ejecución accidental, coherencia de ruta entre artefactos y escaneo estático (módulo de especificación sin subprocesos, arnés sin shell/red, sin cableado con el adaptador, perfil sin unconfined ni capabilities).
- **Adaptador intacto**: `ISOLATION_GUARANTEED = False`, sin integración con el sandbox (prueba estática lo verifica). La integración futura (envolver el comando con `build_bwrap_command`) exige: perfil instalado, arnés con veredicto `ok` real y decisión humana documentada.
- **Instalación y reversión** (para revisión humana, en `docs/CONTROL_OS_SANDBOX_PROPOSAL.md`): sintaxis con `apparmor_parser -Q`, instalación en `/etc/apparmor.d/cv-opencode-bwrap`, verificación con el arnés, reversión con `-R` + `rm` (sin persistencia residual). Alternativas si la hipótesis falla: capability `net_admin` tras revisión, setuid+filecaps descartado por riesgo, nsjail, o desistir (adaptador permanece bloqueado).
- **Auditoría Fase E (2026-11-14, mismo día, rama local)**: correcciones de inconsistencias demostrables, sin instalar ni activar nada. (1) Ruta única: `CV_CONTROL_OS` del módulo = variable del perfil = valor por defecto de `CV_OPENCODE_HOME` del ejecutor, verificado por pruebas cruzadas (cambiar la ruta en el VPS invalida los tres). (2) Fuga de secretos: `--ro-bind-try /etc/ssl` exponía `/etc/ssl/private` (claves TLS del host); ahora solo se monta `/etc/ssl/certs` y el perfil prohíbe `/etc/ssl/** r`. (3) Cargador dinámico: faltaba el enlace `/lib64 → usr/lib64` (usrmerge) y la regla `/usr/lib64/** mr`; sin ellos ningún binario dinámico arrancaría dentro de la jaula (fallo cerrado, visible en `bootstrap`). (4) Paridad de entorno: `SHELL` (conservada por `child_environment()` del adaptador) falta en `CHILD_ENV_KEYS`; habilitada. (5) `validate_command` endurecido: rechaza `--dev-bind-try` y `--setenv` con claves fuera de lista blanca (un secreto inyectado en argv era invisible para el validador). (6) Hoja del workspace restringida a `run-<id>`. (7) Conflicto documentado (NO resuelto): `deny network` + `--unshare-all` y la ausencia de credenciales en el entorno hacen inviable el modelo remoto (`opencode run -m ...`) dentro de la jaula tal como está propuesta; la resolución (proxy/socket UNIX o red selectiva) es decisión humana pendiente (§6/§8 del documento), nunca un aflojamiento del perfil. Comentario del perfil descartaba `/etc/protocols`/`/etc/services` como "montados" sin serlo: reglas ajustadas a lo realmente montado. `apparmor_parser -Q -K` local no pudo completar la revisión de sintaxis (resuelve los `abi/4.0` del sistema, fuera del repositorio por restricción): queda como paso de la revisión humana.
- Pruebas locales: 76/76 sandbox + 30/30 + 47/47 + 28/28 + 46/46 (227 total) OK; `npm run lint/typecheck/build` no ejecutables por falta de `node_modules` en este equipo (limitación conocida).
- Pendiente: revisión humana de la propuesta, instalación supervisada del perfil, ejecución real del arnés, decisión de diseño para el modelo remoto (red/credenciales) y solo entonces decidir la activación del adaptador.

## PREPARE — OpenCode × Control OS, Fase F: contrato de propuestas y puente (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `72b764d`. Solo repo local: sin push, sin merge, sin despliegue, sin Supabase real, sin automatizaciones activadas y sin ejecutar comandos generados por IA.
- **Contrato de propuestas IA (nuevo)**: `scripts/ops/opencode-proposal.py`, `cv-change-proposal-v1` con whitelist estricta de claves; solo operaciones estructuradas reutilizadas del ejecutor de Fase B (`create_file`/`replace_file`/`text_replace` con `expected_count` 1..5 vía `validate_operations`); rechaza versiones distintas, claves no autorizadas (incluye `commands`/`shell`/`deterministic` a nivel de propuesta), identificadores inseguros, repo no habilitado, rutas absolutas/traversal/`.git`/protegidas/barra invertida/NUL/`~`, tamaños fuera de límite, timestamps incoherentes, propuestas caducadas y secretos en cualquier texto (patrones de credencial compartidos con la Fase A: JWT, `sk-…`, `sbp_…`, `Bearer …` → rechazo, no redacción). Módulo puro: sin IO, sin red, sin subprocesos.
- **Puente hacia el ejecutor determinista (nuevo)**: `scripts/ops/opencode-proposal-bridge.py`. Carga la propuesta desde archivo local (nunca red ni stdin), valida el contrato, bloquea duplicados antes de entregar (misma `proposal_id`, misma huella SHA-256 de contenido con otro id, o `run_id` ya en `jobs/claims/results/dead`), hace precheck de symlinks contra el repo real, construye el paquete reutilizando `build_ai_job` de la Fase A (whitelist + redacción + TTL 45 min), lo revalida con `validate_job` del ejecutor de Fase B (que no confía en el productor) y lo entrega atómicamente en `jobs/` reutilizando `write_ai_job` (tmp+rename, 0644). Registro anti-duplicados: recibos 0600 en `CV_PROPOSAL_LEDGER` (por omisión `<buzón>/../proposal-ledger`). CLI con códigos: 0 entregada, 2 uso, 3 contrato, 4 duplicada, 5 caducada, 6 ruta insegura, 7 entorno, 8 interno. NUNCA ejecuta texto generado por la IA: la única salida es un paquete estructurado en el buzón.
- **Separación generación ↔ ejecución**: el adaptador de Fase D sigue con `ISOLATION_GUARANTEED = False` y sin cablear con el puente ni con el ejecutor; el ejecutor de Fase B no fue modificado y no conoce la Fase F. Sin red, sin subprocesos, sin shell, sin `eval`/`exec`/`Popen` en validador y puente (escaneo estático AST en ambas direcciones).
- **Aprobación humana intacta**: la entrega solo habilita la ejecución aislada que produce diff para revisión; la integración sigue pasando por `record_decision` del consumidor de Fase C. Sin commit, push, merge ni despliegue en todo el camino nuevo.
- **Pruebas**: `scripts/ops/tests/opencode-proposal.test.py` (44 casos): contrato completo, maliciosas (rutas, operaciones no autorizadas, comandos, secretos, caducidad), symlinks (padre/target con repo temporal), duplicados (id, huella, run_id en buzón), extremo a extremo (propuesta → puente → ejecutor real sobre repo temporal → resultado `completed` con diff; ejecutor rechaza paquete entregado y luego alterado; repo sin commits/ramas/cambios), CLI real del puente con subproceso y escaneos estáticos. Cableado en `validate.yml` (py_compile + suite).
- **Documento**: `docs/CONTROL_OS_PROPOSAL_CONTRACT.md` (cadena completa, contrato, validador, puente, separación, aprobación, limitaciones); `docs/CONTROL_OS_OPENCODE_EXECUTOR.md` ampliado con referencia a la Fase F.
- Pruebas locales: 44/44 fase F + 76/76 sandbox + 30/30 + 47/47 + 28/28 + 46/46 (271 total) OK; `npm run lint/typecheck/build` no ejecutables por falta de `node_modules` en este equipo (limitación conocida).
- Limitaciones: registro anti-duplicados local (sin Supabase ni conciliación con `ops_dev_runs`), precheck de symlinks sobre el checkout configurado (la garantía del worktree sigue siendo la del ejecutor), huella bloquea re-envíos idénticos por diseño (repetir exige revisión manual del recibo), puente en rol del worker root invocado puntualmente sin automatización.
- Pendiente: integración de la entrega del puente en el flujo del worker con propuesta seleccionada, tienda real contra Supabase para conciliar run_id de propuestas y decisión de activación del adaptador (sigue dependiendo de la Fase E).

## PREPARE — OpenCode × Control OS, Fase G: demostración E2E reproducible (2026-11-14)

- Rama `control-os/opencode-fase-a` sobre `baf60ca`. Solo repo local y directorios temporales: sin push, sin merge, sin despliegue, sin Supabase real, sin secretos, sin AppArmor instalado, sin Bubblewrap real, sin servicios externos, sin comandos generados por IA y sin habilitar el adaptador de OpenCode.
- **Arnés de demostración (nuevo)**: `scripts/ops/opencode-demo-e2e.py`. Crea en `/tmp` un repo Git temporal con datos 100 % ficticios, un buzón cvdev provisionado, un registro de recibos y una tienda simulada (`SimStore`, Fase C). Sobre ese mundo temporal demuestra: solicitud humana ficticia → propuesta `cv-change-proposal-v1` fija (archivo, sin modelo) → **puente real de Fase F vía CLI** → **ejecutor determinista de Fase B vía CLI** sobre el repo temporal → resultado `completed` con archivos afectados y diff unificado en pantalla → **consumidor de Fase C (in-process)** con conciliación → `awaiting_approval` → **decisión humana explícita** reutilizando `record_decision` (por teclado con TTY o `--decision approve|reject`).
- **Aprobar no despliega (demostrado)**: tras la decisión se verifica programáticamente que el repo temporal conserva HEAD, el número de commits, la única rama base, el árbol limpio y cero remotos. La integración tras aprobación se declara como paso humano posterior no implementado. El aislamiento del sistema operativo se declara NO verificado (Fase E sigue siendo propuesta no instalada).
- **Defensas demostradas en el mismo mundo**: reintento idéntico bloqueado por el puente (código 4), propuesta con `run_command` rechazada (código 3), paquete entregado y manipulado después → el ejecutor lo rechaza (run `failed`, repo intacto), resultado manipulado antes del consumidor → cuarentena `dead/` con `.motivo.txt` y run sin tocar.
- **Sin modificaciones funcionales en A–F**: las fases reutilizadas no cambiaron; el arnés solo las invoca (escaneo estático: subprocesos limitados a `git` y las dos CLI fijas de las fases B y F; sin adaptador, sin variables de activación, sin secretos, sin rutas reales del repo).
- **Pruebas**: `scripts/ops/tests/opencode-demo-e2e.test.py` (18 casos): mundo temporal, flujo aprobado y rechazado completo, defensas, interacción humana (sin TTY exige `--decision`, decisión inválida → 2), CLI real de la demo con subproceso y escaneos estáticos. Cableado en `validate.yml` (py_compile + suite).
- **Documento**: `docs/CONTROL_OS_DEMO_E2E.md` (comando de lanzamiento, escenas observables, garantías demostradas, limitaciones); `docs/CONTROL_OS_OPENCODE_EXECUTOR.md` ampliado con referencia a la Fase G.
- Comando de la demostración: `python3 scripts/ops/opencode-demo-e2e.py --decision approve` (o `reject`; con TTY se pide por teclado; `--conservar` mantiene el directorio temporal).
- Limitaciones: modo simulación heredado de Fase C (sin Supabase), propuesta de demostración fija (generación real por modelo sigue bloqueada hasta sandbox efectivo y decisión humana), integración tras aprobación pendiente.
- Pendiente: decisión humana sobre instalación del sandbox (Fase E), cableado de Supabase real y diseño de la integración post-aprobación.

## CURRENT — Control OS: auditoría de continuidad (2026-11-14)

- Estado: integración A–G en Git completada; operación autónoma por lenguaje natural NO certificada.
- Último SHA del bridge instalado: `687df775fa09bf0e9307aaa8596e5919a2d60595` (PR #208). Supabase bridge #122 confirma actualización con respaldo .prev.
- Relevo antiguo superado: #201 fusionado; #202 cerrado y sustituido por #203; rebase remoto #95 terminado y checkout #121 limpio en 9784676153db5ba608ab09b42f462d99e30b0e55, respaldo 25142ed intacto; #205 fusionó A–G.
- VivaBot: snapshot #105 preservó bc1058f; snapshot #114 preservó 08e7b33; deploy #115 confirmó 4a26e0797eb50851fb3a35216f406a7352c9282a y rollback 08e7b33c4d4ee2f222f55c6d6a5e6cc476d30b48.
- Salud #119: web 200, bot online, buzón sin atasco, datos respondiendo, disco 56 %, RAM 69 % libre.
- PR #208: validate, integration, browser, staging-contract, release-foundation y content-studio en success. Los dos checks gate fallaron porque las ramas de mantenimiento no cumplen control-os/task-N-slug (exit 2 antes de cambios); no afirmar todos los checks verdes.
- Corrección #208: probe de OpenCode instalado 1.19.5 usa --pure y permission con denegación de herramientas. Probe remoto #123 debe verificarse por resultado terminal; no equivale a activar el adaptador.
- Brecha comprobada: owner-change.js crea payload.request/impact, sin payload.dev.ready/repo; ops_claim_dev_task exige esos campos. Hay 18 tareas dev triaged sin paquete listo. Su estado triaged no demuestra programación en curso.
- Consumidor A–G aún declara SimStore; cableado Supabase real y promoción post-aprobación pendientes. Mantener ISOLATION_GUARANTEED=False hasta verificar aislamiento real.
- Worker #120 active/enabled; journal devuelve fallo DNS de 06:44. Esto no demuestra un fallo actual ni una ejecución reciente exitosa. Pendiente smoke real de tarea.
- Legacy #8/#14/#19/#24: sin aprobación pendiente. Reparación auditable control-os-20261114-legacy-orphans añade result.legacy_review y cuatro ops_events legacy_approval_orphan_identified; conserva waiting_approval y no autoriza ejecución ni mueve dinero. Verificación SELECT confirmó las cuatro marcas.
- Panel: código exige sesión WordPress, user ID 1 y manage_options; nonce en POST y cabeceras privadas. No se certificó navegación autenticada; DNS local del entorno falló. No ampliar accesos.
- costo_actual: infraestructura existente; costo_nuevo_requerido: 0; alternativa_gratuita: planner gratuito solo tras calibración; momento_de_escalar: únicamente si proveedor gratuito no permite operación verificable.
- Siguiente: verificar #123–125; integrar un planner sin herramientas con contrato de propuesta, consumidor Supabase real y aprobación/promoción auditadas; después prueba E2E reversible desde panel/WhatsApp. No declarar Control OS operativo antes de esa evidencia.

- Resultado terminal posterior: probe #123 falló por TimeoutExpired a los 120 s, sin CV_PROBE_OK. No se ha determinado si es red, proveedor o arranque de CLI; no atribuir causa sin diagnóstico. #124 confirma VivaBot limpio en 4a26e0797eb50851fb3a35216f406a7352c9282a; #125 confirma control_sync active/enabled. No se certifica modelo ni recorrido E2E.

## CURRENT — Diagnóstico acotado del planner (2026-11-14)

- Sustituye probe silencioso por salida conservada, HOME temporal nuevo y timeout de 50 s con SIGKILL al grupo de procesos; consultas de red de solo estado a dos endpoints públicos fijos como cvdev.
- Herramientas denegadas, entorno sin credenciales, prompt/modelo/comandos fijos; ningún argumento de tarea modifica esos valores. Éxito exige evento text exacto CV_PROBE_OK y exit 0.
- Tres regresiones locales pasaron: salida/redacción, timeout mata grupo y conserva diagnóstico, exit no cero. CI incluye la suite; resultado remoto pendiente al abrir PR.
- Coste nuevo: 0. Sin activar el adaptador ni desplegar código comercial. Próximo: instalar bridge validado y repetir prueba supervisada.

## CURRENT — Checkout dedicado certificado (2026-11-14)

- Acción prepare_control_dev_checkout: destino fijo /home/cvdev/workspace/control-os-certified, exige SHA completo exacto de origin/main; checkout detached como cvdev, origin público sin credenciales. No mueve ramas, no cambia servicios ni cambia el worker.
- Rechaza symlinks y checkouts existentes no coincidentes; reintento del mismo SHA comprueba limpieza y propiedad sin mutar. Fallo conserva archivos para diagnóstico, no borra ni resetea.
- Próximo: CI, instalar bridge, ampliar allowlist conservando CHECK, preparar checkout y verificar propietario/SHA/limpieza. Luego conectar una tarea real determinista; el bootstrap por sí solo no demuestra E2E.
- Coste adicional 0; IA no invocada; producción sin cambios. Autorizado por Ernesto continuar bloques 1–2.

## CURRENT — Cierre real sin IA de operaciones de consulta (2026-11-14)

- Aplicadas migraciones close_explicit_readonly_bridge_tasks y bind_readonly_bridge_on_enqueue. Se amplían los RPC/proyectores existentes, sin cola paralela, SECURITY DEFINER ni grants nuevos.
- Contrato explícito payload.ops: completion_mode=bridge_readonly_v1, action y bridge_id exacto. Solo task_type ops_operation, executor ops, risk safe, approval_required=false, status running; allowlist de cinco consultas sin escritura. Resultado done requiere returncode numérico 0. Fallo/bloqueo cierra como failed. Tareas dev, sensibles, canceladas o esperando aprobación no se cierran por esta vía.
- Encolado vincula bridge_id en la misma transacción; conserva evidencia/eventos existentes y agrega un evento de cierre. Repetir actualización no duplica el cierre.
- Prueba SQL en Postgres real: diez escenarios y duplicados pasaron; fixtures revertidas con ROLLBACK. Tarea real #115/bridge #150 cerrada automáticamente. #116/bridge #151 certifica vínculo automático sin actualización manual.
- Fixture #114 quedó cancelada y etiquetada invalid_fixture_missing_bridge_id; la defensa rechazó correctamente cerrarla. No es una tarea comercial.
- Scripts SQL de avance, rollback exacto y pruebas en scripts/ops/sql y scripts/ops/tests. No cambia servicios ni despliega web/VivaBot. Coste adicional 0; llamadas a modelos 0.
- Límite: certifica cola→bridge→resultado→cierre de consulta. No certifica WhatsApp/panel ni generación/aprobación/promoción de cambios de código. Siguiente: ruta canónica para solicitudes desde canales y consumidor Supabase de desarrollo con aislamiento verificado.

## CURRENT — Entradas de propietario y preparación Codex (2026-11-14)

- Aplicado router SQL por coincidencia exacta para salud del sistema, estado de WordPress y estado del bot. Panel conserva actor Ernesto; WhatsApp conserva owner_change y exige sus permisos/aprobación originales antes del enrutado. No interpreta instrucciones ambiguas como consultas.
- Pruebas SQL de solicitud conocida, duplicado e instrucción desconocida pasaron con rollback. Solicitud real por ops_ingest_control_request #130 produjo bridge #163 y resultado done con resumen, sin IA. No sustituye una prueba manual en panel/WhatsApp.
- Snapshot incluye 20 tareas terminadas además de abiertas para que el resultado siga visible. Hoy conserva solo trabajo abierto.
- Acciones nuevas propuestas: control_codex_setup instala @openai/codex@0.169.0 oficial como cvdev en directorio dedicado; control_codex_login solo inicia/consulta device auth, sin modelos ni API keys. CODEX_HOME separado; entorno limpio sin credenciales de producción; no cambia servicios.
- Pendiente de CI y despliegue. Inicio de sesión requiere intervención del propietario con link y código oficial. Ninguna suscripción contratada ni coste adicional autorizado.

## CURRENT — Panel real y autenticación de Codex (2026-11-14)

- #212 y #213 fusionados tras CI verde (integración requirió un segundo intento por fallo 4A de atribución ya observado).
- Bridge actualizado y control_sync desplegado desde 05306de1c7a69bcc555bedfd61ba9aa84b0967b1; bridge #166 confirma primer ciclo correcto con tareas recientes terminadas visibles.
- Ernesto confirmó acceso al panel y envió una solicitud real: #131 con comillas «salud del sistema». Se corrigió normalización de comillas exteriores; #131 pasó por bridge #164 y terminó automáticamente con resumen de salud. No se validó visualmente el resultado en navegador del agente: conexión CUA agotó tiempo.
- Codex oficial 0.169.0 instalado en /home/cvdev/control-os-tools como cvdev, CODEX_HOME /home/cvdev/control-os-auth, sin API key ni invocación de modelo (bridge #167). Device auth iniciado #170; requiere autenticación personal del propietario, no se extraen credenciales de su sesión local.
- SQL de entradas probó aprobación WhatsApp, conservación de owner_change, cierre, comillas y rechazo de instrucción ambigua con rollback de fixtures.
- Guía docs/CONTROL_OS_USO.md distingue consultas operativas verificadas de programación autónoma aún pendiente. No declarar objetivo completo hasta autenticar proveedor, certificar sandbox y conectar consumidor/promoción reales.
- Coste nuevo 0. Claves de Gemini no configuradas. El envío de audios usa transcripción existente; no se afirma coste cero para audio.

## CURRENT — Preparación fija del consumidor en cvdev (2026-11-14)

- Codex device auth #172 confirmó logged_in=true y subscription_login=true. No clave API.
- Nueva acción acotada control_codex_consumer_build: solo tarea fija para consumidor Supabase, worktree propio, usuario cvdev, sandbox workspace-write nativo y unidad systemd con límite 600 s, NoNewPrivileges, ProtectSystem=strict y ProtectHome=read-only; escritura solo worktree y CODEX_HOME. No SQL real, push ni despliegue. Nunca ejecuta comandos enviados por una tarea.
- Resultado se revisará como diff antes de promover. Si el sandbox no arranca, se mantiene cerrado; no se desactiva AppArmor ni se usa danger-full-access.
- Costo adicional previsto 0: usa cuota existente de ChatGPT. No certifica todavía generación ni consumo de resultados hasta revisar ejecución real.

## CURRENT — Publicación acotada del consumidor (2026-11-14)

- Preparación Codex arrancó en unidad cv-control-consumer-build como cvdev (#174), sin tocar producción. El journal confirmó sesión y primer mensaje del modelo; no equivale a resultado certificado.
- Se propone acción de publicación solo desde worktree fijo, preparación inactiva, HEAD igual a origin/main, máximo 20 archivos/500KB, sin borrados, symlinks, dotfiles, rutas fuera de scripts/ops y docs ni patrones de secreto. Commit como cvdev y push sin force a rama fija review/control-os-real-consumer; hooks deshabilitados para el push privilegiado. No ejecuta código generado, merge, SQL ni despliegue.
- Pendiente revisar diff y pruebas antes de cualquier instalación. Coste adicional 0; utiliza cuota ChatGPT existente.

## CURRENT — Diagnóstico de aislamiento nativo (2026-11-14)

- La sesión Codex autenticó y respondió, pero el trabajo #174/estado #176 no produjo cambios: fallo previo a comandos bwrap loopback Failed RTM_NEWADDR Operation not permitted. Exit 0 del modelo NO significa tarea realizada. No se repite un modelo hasta arreglar el entorno.
- Nuevo modo diagnostic de la acción existente: prueba codex sandbox linux /usr/bin/true sin modelos, lee perfiles AppArmor públicos y denegaciones del kernel. No instala perfiles, cambia sysctl ni usa danger-full-access. Se comparará con documentación de la versión 0.169.0 antes de proponer una corrección específica.
- Producción sin cambios; coste extra 0. Usuario confirmó sesión iniciada y panel accesible; consultas conocidas siguen operativas.

## CURRENT — Remediación específica de Bubblewrap (2026-11-14)

- Diagnóstico #178: sysctl restrict=1, ningún perfil bwrap cargado, sandbox nativo true falla RTM_NEWADDR. Kernel muestra transición unprivileged_userns y denegaciones setpcap/net_admin/uid_map. No hay evidencia de fallo de login.
- Perfil propuesto confinado para copia root-owned 0755 de /usr/bin/bwrap en /usr/local/lib/control-os/bwrap, solo en PATH de Codex cvdev. No toca /usr/bin/bwrap ni sysctl; sin setuid, filecaps ni flags unconfined. Capacidades AppArmor limitadas al bootstrap de los namespaces de bwrap: no confieren capacidades de host al usuario cvdev. Montajes siguen sujetos a kernel/userns y sandbox nativo; hijos heredan perfil, red IP y credenciales denegadas.
- Acción control_codex_sandbox: install/verify/rollback de rutas fijas; comprueba sintaxis antes de cargar, rechaza perfiles ajenos, pruebas nativas sin modelos para bootstrap, escritura en workspace, lectura de canario privado, escritura fuera y red IP. No relanzar modelo si falla verificación.
- Pendiente: CI, instalación supervisada, verificar aislamiento real y registrar evidencia/denegaciones si procede. Política global preservada. Coste de modelos durante diagnóstico: 0.

## CURRENT — Compatibilidad del recurso bwrap (2026-11-14)

- Perfil dedicado cargó en enforce (#181) y sysctl global permanece 1. Prueba nativa #180 sigue RTM_NEWADDR; no se llamó modelo.
- Revisión del launcher de Codex rust-v0.169.0: exige --perms y --as-pid-1; si el sistema no los soporta, ignora ese binario y usa el incluido sin perfil. Se propone copiar exclusivamente el recurso codex-resources/bwrap del paquete oficial instalado; exige candidato único, comprobación de help y rechazo de binarios previos desconocidos.
- No cambia perfil, permisos, capacidades ni límites; conserva pruebas negativas. Se ejecuta help como cvdev y la copia queda root-owned 0755 sin setuid/filecaps. Pendiente prueba real; diagnóstico sin consumo de modelos.

## CURRENT — Acceso al directorio público del sandbox (2026-11-14)

- #183 falló bootstrap sin modelos. La revisión encontró que main() fija umask 077: folder.mkdir(mode=0755) genera 0700 y no se normalizaba después. cvdev no puede recorrer ese directorio y Codex escoge otro bwrap sin perfil; kernel #184 sigue mostrando unprivileged_userns, no el perfil dedicado.
- Corrección: comprobar propietario root y ausencia de escritura de grupo/otros, luego chmod 0755 explícito al directorio que solo guarda bwrap. Los archivos de autenticación permanecen 0700/0600. Se añade prueba directa del binario como cvdev antes de la prueba Codex, diferenciando permisos de ruta y selección del helper.
- Pendiente CI y verificación real; no afirmar corrección hasta controles positivos/negativos verdes. No se amplían capacidades ni permisos del perfil. Cero llamadas a modelo durante diagnóstico.


### Control OS — staging privado de Bubblewrap

PR #220 resolvió el directorio root 0700 causado por umask 077; prueba directa dedicada superada. Bridge #187 llegó al ejecutor nativo, pero AppArmor bloqueó mkdir/mknod en /newroot/dev/null (auditoría #188). Se permite el staging /newroot del tmpfs privado previo a pivot_root, con denegaciones explícitas equivalentes para root, autenticación, SSH y claves privadas en esa ruta. No se añaden capabilities ni se cambia el sysctl global. La actualización acepta exclusivamente el perfil anterior exacto o el nuevo; deben pasar las pruebas positivas de escritura del workspace y negativas de secretos/escritura/red antes de ejecutar el modelo.


### Control OS — sintaxis del diagnóstico Codex 0.169

Bridge #190 superó las restricciones previas de Bubblewrap y llegó al exec del comando interno. La prueba conservaba `codex sandbox linux -- ...`; en rust-v0.169.0 `SandboxCommand` aplana `HostSandboxArgs` y pasa `linux` como nombre de ejecutable, provocando `Failed to execvp linux`. Se corrigen las tres pruebas a `codex sandbox -- ...` según codex-rs/cli/src/main.rs oficial. Sin cambios de permisos ni nuevas llamadas al modelo. La certificación exige de nuevo arranque nativo, escritura permitida y lectura/escritura/red prohibidas antes de reintentar la tarea de desarrollo.

## PREPARE — Control OS: resultados Supabase transaccionales (2026-11-14)

- Alcance único: recepción de resultados y solicitud de revisión humana, sin promoción. Base local inspeccionada `085b27a35880bdab1585da3d0d9b6cf3fb765ec9`. Producción y CI remoto no consultados; sin red, SQL real, credenciales, commits, push, despliegues ni servicios activados.
- Consumidor separado `scripts/ops/opencode-supabase-result-consumer.py`: reutiliza contrato del consumidor simulado sin modificarlo; cliente Supabase inyectado, una única RPC por resultado. CLI sin activación. Verifica bigint/UUID canónicos, identidad del nombre de archivo, límites, tiempos y lectura sin seguir symlinks; conserva archivo ante error RPC o respuesta perdida.
- Migración propuesta en `scripts/ops/sql/opencode-result-receipts.sql`, NO aplicada: recibos por run con paquete completo (diff/base_sha/changed_paths), RLS, RPC SECURITY INVOKER y grants internos solo service_role. No presupone columnas diff/result en ops_dev_runs. Conciliación y bloqueo run/tarea, estados, modo IA, auto_merge=false, SHA y allowlist persistidos; rechaza otras runs activas de la tarea. Duplicado exacto sin efectos adicionales; conflicto rechazado.
- Una transacción crea recibo, aprobación real pendiente en ops_approvals, actualiza estados a waiting_approval y audita evento. Fallos del ejecutor cierran failed sin aprobación aceptada. No existe decisión humana automática ni ejecución de comandos del modelo, integración, push o deploy. SimStore conserva su demostración independiente.
- Pruebas locales: 295 casos OK (adaptador 46, demo 18, ejecutor 47, propuestas 44, consumidor simulado 28, sandbox 76, consumidor Supabase mock 6, worker 30). py_compile y git diff --check OK. No se ejecutaron suites npm: cambio exclusivamente Python/SQL/documentación sin modificación de aplicación.
- Fixture `scripts/ops/sql/opencode-result-receipts.fixture.sql`: base vacía desechable, negativos de conciliación/estados/límites, duplicados y fallo tardío para probar rollback total, termina ROLLBACK. NO ejecutada por prohibición de SQL real. Los mocks comprueban cliente/reintentos, no certifican atomicidad PostgreSQL.
- Bloqueo para activación: revisar DDL real de ops_tasks/ops_approvals/ops_events (tipos, defaults, enums, rol owner/status pending, logs y permisos existentes), aplicar migración transaccional y ejecutar fixtures en entorno autorizado. No se certifica concurrencia ni permisos reales. Guía Supabase de skill inaccesible por permisos del entorno; sin conector utilizado.
- Documento: `docs/CONTROL_OS_SUPABASE_RESULTS.md`. Siguiente acción: revisión del esquema y pruebas SQL autorizadas; promoción e instalación de servicios siguen fuera de alcance. Costo nuevo requerido: 0; infraestructura existente, sin escalado requerido por esta preparación.


### Auditoría posterior de la propuesta Codex

- PR #223, tarea real en cvdev completada y publicada sin force: bridge #193/#200, head inicial 2745006e9491314abb216ca2fa397c1641c7c392. Aislamiento #192: escritura workspace permitida y lecturas auth/escrituras auth-root/red denegadas; AppArmor global sigue 1.
- Revisión del esquema real detectó y corrigió estado de run (waiting_approval) e impact JSONB; recibo conciliado por run_id/estado. Cuarentena con descriptores fijados, rechazo de enlaces y notas únicas sin sobrescritura.
- CHECK crm_ops_bridge_action_chk conservada y ampliada exclusivamente con control_codex_consumer_publish (migración allow_fixed_control_codex_consumer_publish), verificada con convalidated=true.
- Se añaden al CI la suite Python real y PostgreSQL 17 desechable con fixtures de negativos, duplicación y atomicidad. La migración de recibos no se ha aplicado y el servicio no se ha activado; pendiente validación CI y revisión de permisos.

- PostgreSQL desechable del primer head auditado 4274ad2 pasó (job 132576605470). Se refuerzan después permisos de recibos y aislamiento por business_id/executor; nuevo head requiere repetir CI.
- Gate identificado antes de activar: ops_decide_approval genérico devuelve a triaged; falta decisión de resultado IA que no reprograme el trabajo aprobado. No aplicar esta migración ni activar consumidor hasta certificar ese paso. PR permanece draft y producción sin cambios de ese consumidor.

- Gate de decisión resuelto en propuesta: aprobar un recibo IA deja tarea verifying/executor human y run blocked para PR/CI/promoción, nunca triaged. Rechazo sin aplicar diff, replay idempotente, aprobaciones ordinarias preservadas. Fixture ampliada y rollback no destructivo incluidos; locks tarea→run coherentes. Pendiente nuevo CI antes de cualquier migración.


## CURRENT — Panel limpio desde hoy (2026-11-14)

- Base verificada: e6838cdf7ae4eb93af7e28bfee7bd699d07c68b3. Producción: panel anterior auditado mediante navegador; consultas #138/#139 terminadas, presentación confusa. Costo nuevo necesario: 0.
- La migración control_os_transactional_ai_results_with_review_gate SÍ está aplicada: prueba con service_role y rollback real superada, evento ops_events #368. No quedan datos sintéticos. Consumidor y promoción automática siguen pendientes.
- Petición de Ernesto: arreglar el panel y empezar de cero hoy. Se implementa limpieza de presentación desde 2026-11-14T22:24:32.392Z; no se borran ni se cierran tareas, aprobaciones, pedidos, dinero o conversaciones. El historial sigue accesible.
- Interfaz con Mis solicitudes, Aprobaciones y Sistema. Solicitudes nuevas primero, buscador, estados claros, detalles desplegables, consultas rápidas sin IA, seguimiento del número por source_ref y timeout de lectura. Todas las aprobaciones reales se muestran sin aplicar el corte temporal.
- Se retiran de la portada los mensajes sueltos de Lennys y el historial técnico; conservados en secciones desplegables del historial/sistema. La programación incompleta se informa explícitamente. La aprobación no promete aplicar ni desplegar.
- Snapshot añade created_at/request_id/executor/task_type y reserva solicitudes recientes del propietario para que las tareas legacy no las oculten. Solo se resumen outputs allowlist wp_status/vivabot_git_status ya completados; no se exponen credenciales.
- Validación pendiente: CI, despliegue allowlist deploy_control_web/update_control_sync con SHA certificado y auditoría real de escritorio/móvil. Rollback: archivos anteriores conservados por el despliegue del panel y posibilidad de redeplegar SHA base; sync conserva .legacy y puede reinstalarse desde el SHA base.


### Verificación real del panel limpio y conexión lenta

- PR #225 fusionado y desplegado en 99a35ceb1ea19456ff557d7ec5ac403845cb0839. Bridge #204 actualizó sync con primera ejecución OK; #205 instaló panel, PHP lint y checksums OK. Backup control-20261114T223535Z-99a35ceb1ea1 conservado.
- Navegador real: vista limpia con 0 solicitudes anteriores, botón Salud crea #140 y muestra el número; historial encuentra #139 y muestra resumen legible del código del bot. No se borró ni se canceló el historial.
- Observación: el timeout de lectura de 10s genera avisos prematuros en conexión lenta. Se amplía a 30s y se conserva el último estado de menos de 3 minutos durante dos reintentos; se informa Reintentando. Tres fallos consecutivos o estado viejo mantienen aviso visible. No se reenvían instrucciones automáticamente.
- Pendiente CI de este ajuste y revalidación de resultado final/móvil. Costo nuevo: 0.


## CURRENT — Solicitudes desde el panel y consulta automática de errores (2026-11-14)

- Base 49502dfcdc9e2e46634059a16b1838d53dd3d662; panel limpio auditado y operativo, evento #384. Ernesto solicita ejemplo real desde el panel. Coste adicional: 0.
- Envío real mediante navegador: #141 revisar errores del bot; #142 preparar vista de pedidos para Lennys con WooCommerce/Core canónico, permisos por diseñar y aprobación previa. #142 queda revisión pendiente, no se afirma construida ni automática.
- Nueva regla exacta revisar errores del bot / revisa los errores del bot / errores del bot usa errors_open ya allowlisted. Reutiliza la única ops_tasks y el cierre read-only ligado a bridge_id. Conserva identidad owner/WhatsApp, riesgo, aprobación y duplicación. No analiza chats nuevos con IA: muestra incidencias abiertas ya registradas y lo declara en el informe.
- Botón de consulta en el panel. Assets JS/CSS versionados por hash de contenido para que las siguientes versiones no queden obsoletas en caché (se observó texto anterior en navegador tras redeploy).
- Pruebas PostgreSQL desechables: ruta/cierre/síntesis, duplicados, instrucción ambigua, riesgo sensible, aprobación, actor distinto, legacy, owner_change de WhatsApp, fallo y bridge incorrecto. Pendiente CI, migración y cierre real de #141; rollback de las tres funciones incluido. No se habilita programación autónoma ni se amplían permisos de Lennys.

- Preflight real errors_open #208: returncode 0, 21 incidencias abiertas, salida legible de 6123 caracteres. No es análisis IA nuevo. Informes largos se muestran con resumen y Leer informe completo para conservar la portada limpia. La prueba SQL incorpora además identidades nulas y las rechaza explícitamente.


## CURRENT — EVO-001: estabilización preparada y verificada, sin desplegar (2026-11-15)

- Rama independiente `evo/evo-001-content-studio`, [PR #230](https://github.com/ernest196391/Casa-Viva/pull/230), base `8f3e525985c6e1a4ebf8f249925672a6d8956aa5`. El servidor de #224 (`bbd10f21d82fd53fc8e6d1aed7d904e3ce8001e0`) queda reconciliado como segundo padre, preservando main posterior, pantalla `page()` y assets actuales byte a byte. Core candidato 3.14.171. Diseño v2/suite visual de #224 reservados para Stitch.
- Corregidos y probados: resultado aplazado conserva corrección del supervisor (dato/texto/foto); restaurar/rechazar HERO conserva altas y bajas posteriores de galería; URL-only respeta HERO/web y LIFESTYLE-BENEFICIO/anuncios. Clases, REST, prioridades, recorte, snapshots y pruebas anteriores reutilizados.
- Política fija de gasto adicional 0: generación/poll/texto externo y escalados bloqueados con credenciales/flags. Recorte gratuito permanece. Publicación autónoma deshabilitada incluso por llamada directa; aprobación humana existente preservada. Handoffs declaran budget_usd=0 y billable_allowed=false.
- Complemento [VivaBot #42](https://github.com/ernest196391/vivabot/pull/42), SHA `d95b31801b58578da93a478ec33ee0f37a75a814`: QA visual humana con/sin --sin-pago; bloqueo panel/lote antes de proveedor; sin cambios a imágenes ordinarias de WhatsApp. CI completo 43744354065 y Pedidos/vales 43744354087 en success. Aún no instalado.
- Core código `89abd45b001560ec04324d031bff68bb5598ae27`: CI 43744444152 success en los cinco jobs, release/staging success. PHP local: contratos anteriores, 94 comprobaciones v2 y política con red espía; Node 7/7. Integración WordPress/MariaDB y navegador: Content Studio 14/14; general 39 pass, 13 skip reservadas al job dedicado. No pruebas con proveedores pagados.
- Recorte real verificado por SSH existente/WordPress → VPS, sin instalar nada: smoke 43744911546 sobre `52fd70ee8ccd2379b5c20bd65b7f92278eba4a95`, producto 52/adjunto 6490, PNG 377×872, 33,24 s; hashes y evidencia en docs/evidence/EVO_001_RECORTE.json. Temporales remotos borrados; catálogo y servicios sin cambios. Bridge #217 había confirmado que no expone recorte, limitación superada por workflow acotado.
- Revisión visual del PNG: quedan restos claros de fondo inferior/halo; no aprobar publicación aunque la máscara diga fiable=true. No bloquea certificar el transporte/recorte real, sí impide declarar calidad fotográfica automática. Se mantiene revisión humana y autonomía desactivada.
- Estado: implementación estabilizada y verificada, PRs en borrador para revisión del propietario. Ningún merge ni despliegue. `costo_actual`: infraestructura existente; `costo_nuevo_requerido`: 0; `alternativa_gratuita`: recorte local y revisión humana; `momento_de_escalar`: fase posterior expresamente aprobada.
- Evidencias, archivos y reversión: docs/EVO_001_CONTENT_STUDIO.md. Siguiente: aprobación explícita de ambos PR/SHA y despliegue controlado con rollback; después EVO-002 de auditoría/score/cola. No incorporar interfaces ni activar publicaciones autónomas.


## CURRENT — CV-RECOVERY-004: canal GitHub de contingencia (20-nov-2026)

- P0 operativo: Supabase devuelve HTTP 402; último VPS verificado tenía receptor recovery activo y bot principal intencionadamente detenido. GitHub permanece operativo; #85/#86 VivaBot CI 4/4 aprobada, sin desplegar. Este bloque no cambia pedidos ni WhatsApp.
- Reutiliza `cv-ops-bridge` y su lock: solo ante HTTP 402 en **primera lectura** del buzón remoto, entra a `github_contingency.py` si está explícitamente habilitado. Reintento Supabase a 300 s; GitHub cada 60 s máximo. No reintenta una escritura incierta ni convierte el transporte en shell. Fuente canónica Core preservada.
- Buzón privado dedicado: https://github.com/ernest196391/casa-viva-centro-control/issues/3. Solo comentario nuevo del propietario exacto y actor OWNER, acción health, nonce 16–64 caracteres y expiración UTC ≤30 min. Rechaza editados, replay, inputs adicionales, repositorio público o issue equivocado. Respuesta solo estados PM2 de tres procesos conocidos y estado systemd del bridge. Reserva persistente y fail-closed ante timeout; ningún dato personal/secretos.
- Configuración VPS, opt-in después de CI y revisión: instalar juntos los dos archivos Python desde SHA exacto certificado (el actual updater solo descarga un archivo; no usarlo para esta instalación), conservar backup y runner existente. Variables `CV_GITHUB_CONTINGENCY_ENABLED=0` inicialmente, `CV_GITHUB_CONTINGENCY_ISSUE=3`, credencial dedicada `CV_GITHUB_CONTINGENCY_TOKEN` únicamente en configuración privada del supervisor. Verificar autorización preexistente de lectura del repo privado y escritura de comentarios; si no existe, solicitar provisión explícita con mínimo alcance, nunca extraer/copiar tokens. No mostrar secretos ni abrir nuevos puertos.
- Smoke aislado con API simulada: 6/6 PASS, py_compile PASS; CI nueva pendiente. Al instalar, validar config, modo 0700 y persistencia en /var/lib/cv-ops-bridge-contingency, activar solo el diagnóstico, enviar un comando health con TTL y nonce únicos, confirmar recibo y retorno al flujo normal. No habilitar mutaciones, despliegue ni reinicio remoto: fase separada con controles y aprobación. Rollback: flag=0 y restaurar bridge previo conservando reservas; no borrar historial.
- Coste nuevo 0, alternativa gratuita GitHub + VPS existentes; si cuota GitHub falla, mantener VPS mediante SSH legítimo. Bloqueo real actual: acceso VPS y credencial autorizada no verificados; no afirmar instalado.
