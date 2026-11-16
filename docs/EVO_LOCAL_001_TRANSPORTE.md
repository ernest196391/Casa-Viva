# EVO-LOCAL-001 — Diseño del transporte portátil ↔ VPS (16-nov-2026)

Solo diseño. Nada de esto está instalado ni activado. Continúa [EVO_LOCAL_001_PROPONEDOR_LOCAL.md](EVO_LOCAL_001_PROPONEDOR_LOCAL.md).

## 1. Qué existe hoy (comprobado en vivo el 16-nov)
- **Entrada ya construida:** la función de Supabase `ops_prepare_dev_patch` (solo `service_role`) recibe `operations` + `allow_paths` para una tarea `executor='dev'` sin aprobación pendiente. Valida repo (`casa-viva`), perfil de pruebas, raíces permitidas, rutas protegidas y tamaños; deja `payload.dev` en la tarea con `auto_merge=false` (si se pide) y la pone en `triaged`. Registra el evento `dev_patch_prepared`.
- **Dev Worker (`cv-dev-worker`, activo en el VPS):** reclama la tarea, crea un worktree, aplica las operaciones, ejecuta las pruebas, empuja una rama y deja la ejecución en `waiting_approval`. La decisión humana pasa por `ops_decide_approval`.
- **Tareas `executor='dev'` en `triaged` sin `payload.dev`:** hay 18, pero son errores del bot (`tipo`, `que_debia`, `crm_error_id`), sin texto de petición ni rutas, y la mayoría afecta a VivaBot, no a `docs/` de Casa-Viva. **No son trabajo para el portátil.** Hoy no existe ninguna tarea que pida una propuesta local: el Control Center (o el piloto) debe crearlas con el campo `payload.proposal_request` descrito abajo. *(Corrección del 16-nov: la primera versión de este documento las daba por trabajo disponible.)*
- **No instalado en el VPS:** el ejecutor aislado (Fase B), el consumidor de resultados activo y el sandbox (Fase E). `bwrap` está instalado, pero `ISOLATION_GUARANTEED = False` sigue vigente. El buzón del modo IA existe y está vacío.
- **Portátil:** Ollama con `qwen2.5-coder:7b` y el proponedor local ya fusionado (genera y valida propuestas, no entrega nada).

## 2. Requisitos (comentario del propietario en #231)
Sin credenciales de producción en Windows · sin puertos entrantes · transporte autenticado y acotado por tarea · identidad, TTL e idempotencia · heartbeat · trabajo aislado · piloto con datos ficticios · resultado pendiente de aprobación · sin push ni despliegue automáticos · coste adicional 0.

## 3. Opciones evaluadas
| Opción | Idea | Veredicto |
|---|---|---|
| **A. Puerta SSH de comando forzado** | Clave SSH propia del portátil que solo puede ejecutar un script del VPS (`lista`, `tarea`, `entregar`, `latido`) | **Recomendada.** Sin claves de Supabase en Windows, sin puertos nuevos, un solo punto de control, se revoca borrando una línea |
| B. Rol de Supabase limitado con RPC propios | El portátil habla con Supabase con un JWT restringido | Exige migración nueva y deja una credencial de producción (aunque acotada) en Windows. Descartada por el requisito |
| C. GitHub como canal (issues y ramas) | El portátil abre ramas y PR directos | Salta el contrato, el worker y la aprobación; duplica el sistema. Solo como reserva para documentos |
| D. El VPS llama al portátil | Túnel o puerto abierto hacia Ollama | Exige entrada de red; el VPS (3,9 GB, 1 núcleo) tampoco puede alojar Qwen. Descartada |

## 4. Diseño recomendado: la «puerta de propuestas»
```
Portátil (Qwen + proponedor)            VPS
 local-runner ── ssh (clave restringida) ──► cv-proposal-gate (script root, comando forzado)
   lista / tarea N / entregar N / latido        │ valida, deduplica, registra
                                                ▼
                                   ops_prepare_dev_patch (RPC existente)
                                                ▼
                       Dev Worker existente → pruebas → rama → waiting_approval
                                                ▼
                              Persona aprueba (DecisionCard del Control Center)
```
**Comandos de la puerta (lista blanca; cualquier otro se rechaza):**
1. `lista` → tareas elegibles: `executor='dev'`, `triaged`, sin `payload.dev`, sin aprobación pendiente y **con `payload.proposal_request = {request, allow_paths}`** (opt-in explícito; `allow_paths` solo bajo `docs/`). Devuelve `public_id`, `task_id`, título, petición (≤ 2000 caracteres), rutas y riesgo, con los secretos redactados. No devuelve el SHA de `main`: el Dev Worker aplica las operaciones sobre un worktree nuevo y, si no encajan, la ejecución falla sin tocar nada.
2. `tarea N` → reserva la tarea 45 min y devuelve el detalle. La reserva vive en un archivo de estado de la puerta (no se usan las columnas `claimed_by` de `ops_tasks`, que comparten otros procesos). Una reserva vencida se libera sola.
3. `entregar N` (propuesta por stdin, ≤ 200 KB) → comprueba que quien entrega tiene la reserva, valida con el contrato y el validador del ejecutor, aplica la huella de idempotencia del puente y llama a `ops_prepare_dev_patch` con `auto_merge=false`.
4. `latido` → guarda `{hora, modelo, versión, última tarea ok}` para la pantalla 102 «Programadores locales» del Control OS. Se considera «en línea» si pasó menos de 10 min.

**Límites:** una entrega por tarea; máximo 6 entregas por hora; el cuerpo nunca se escribe en los logs (solo ids, huella y resultado); cada orden deja un evento de auditoría con `actor_ref='local-proposer'`. **Apagada por defecto:** solo funciona con `crm_settings local_proposer = si`; cualquier otro valor, o su ausencia, la apaga. El latido se guarda en `crm_settings programador_local_latido`.

**Autenticación:** par de claves nuevo, solo para esto, creado en el portátil; en el VPS una línea de `authorized_keys` con `restrict,command="…cv-proposal-gate"`. Revocar = borrar esa línea. La clave privada nunca sale del portátil.

## 5. Fase 1 limitada a `docs/**` (y por qué)
El Dev Worker ejecuta `npm test` y compañía sobre el worktree con las operaciones ya aplicadas. Una propuesta de un modelo pequeño que tocara `tests/` o `scripts/` podría hacer que esas pruebas ejecuten código no revisado en el VPS. Mientras el aislamiento no esté certificado, la puerta **solo acepta rutas bajo `docs/`** para propuestas locales (el RPC admite más; la puerta es más estricta). Ampliar a código exige: ejecutor aislado con `bwrap` en el VPS, `ISOLATION_GUARANTEED` certificado y pruebas de escape.

## 6. Fallos y respuesta
| Fallo | Respuesta |
|---|---|
| Portátil apagado o sin red | Las reservas vencen; sin latido, el Control OS lo muestra «sin conexión» |
| Propuesta inválida, duplicada o caducada | La puerta la rechaza con código y no toca la tarea |
| Dos intentos sobre la misma tarea | Gana la reserva; el segundo recibe «reservada» |
| Modelo propone algo dañino | El validador y la comprobación contra archivos reales la rechazan; y aun aceptada, queda en `waiting_approval` |
| Clave comprometida | Borrar la línea de `authorized_keys` y quitar `local_proposer = si` |

## 7. Plan de construcción (cada paso con su aprobación)
1. **Puerta + pruebas en local, sin instalar** (script y pruebas de lista blanca, reservas, idempotencia, límites). Rama y PR.
2. **Instalar en el VPS** (copia del script, línea de `authorized_keys`, copia de seguridad previa). *Requiere SÍ de Ernesto: toca el VPS.*
3. **`local-runner.py` en el portátil:** `lista → tarea → propone → entregar`, con `--dry-run` por defecto.
4. **Piloto con una tarea ficticia de documentación**, de punta a punta, hasta `waiting_approval`.
5. **Pantallas 99–102 del Control OS** con datos reales, cuando Diseño responda en #231.

## 8. Decisiones que necesito de Ernesto
1. ¿Autorizas, para el paso 2, crear la clave restringida y añadir la línea al VPS?
2. ¿De acuerdo con que la fase 1 solo acepte `docs/**`?
3. ¿El runner del portátil se lanza a mano («Ejecutar ahora») o programado? Recomiendo a mano al principio.
4. El texto de la petición: se confirma en el paso 1 qué campo del `payload` lo trae (si no existe, se usa el título).
