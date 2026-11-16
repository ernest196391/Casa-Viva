# EVO-LOCAL-001 — Ejecutor aislado: evidencia y plan (16-nov-2026)

Solo auditoría y diseño. **No se instaló ni se cambió nada en el VPS.** Todo lo de «Verificado» se comprobó el 16-nov con comandos de solo lectura o pruebas inofensivas (sin escribir fuera de `/tmp` del sandbox).

## 1. Verificado hoy en el VPS
| Qué | Resultado |
|---|---|
| Sistema | Ubuntu 24.04.5, kernel 6.8.0-147, bubblewrap 0.9.0 |
| Restricción de user namespaces | `apparmor_restrict_unprivileged_userns = 1` |
| `/usr/bin/bwrap` como `cvdev` | **Falla:** `loopback: Failed RTM_NEWADDR: Operation not permitted` (reproduce el bloqueo del 14-nov) |
| Perfil AppArmor `cv-control-codex-bwrap` | **Ya instalado y cargado** (14-nov 15:07) para la copia `/usr/local/lib/control-os/bwrap` (root, 0755, sin setuid) |
| Esa copia, como `cvdev`, con la caja mínima de la Fase E | **Funciona:** red bloqueada (`socket: Permission denied`), `/root` y `~/.ssh` no existen dentro, `/usr` solo lectura, no se escribe fuera del worktree, entorno limpio (5 variables) |
| Node/npm para `cvdev` | Node 22.23.3 |

Conclusión: **el mecanismo de aislamiento ya existe y funciona.** Lo que falta es usarlo donde importa.

## 2. El hallazgo: dónde NO hay aislamiento hoy
El Dev Worker (`cv-dev-worker`, root) ejecuta con `subprocess.run` sin entorno propio (`run()` de `dev-worker.py`) las pruebas de cada tarea (`npm ci`, `lint`, `typecheck`, `build`, `test`, `py_compile`). Medido: un proceso hijo de `run()` hereda **47 variables, 10 con aspecto de secreto** (`SUPABASE_SERVICE_KEY`, `WOO_KEY`, `WOO_SECRET`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `ELEVENLABS_API_KEY`, `CURRU_KEY`, `IMAGEN_PANEL_KEY`), corre como root y tiene red. Es así para todas las tareas `dev` (VivaBot, NEXO y Casa-Viva), no solo para las propuestas locales.

Por eso la puerta de propuestas se limita a `docs/**`: mientras las pruebas corran así, una propuesta que toque `tests/`, `scripts/` o código ejecutaría código sin revisar con esos secretos.

## 3. Diseño recomendado (A): aislar el paso de pruebas del Dev Worker
Reutiliza el pipeline que ya existe; no crea un ejecutor paralelo.
1. **Worktree de `cvdev`.** El worker (root) crea el worktree bajo `/home/cvdev/control-os-dev/work/run-<id>` (la ruta que ya prevé la Fase B), aplica las operaciones estructuradas (solo escritura de archivos validados, sin ejecutar nada) y le hace `chown` a `cvdev`.
2. **Instalación fuera de la caja, con red, sin secretos.** `npm ci --ignore-scripts` como `cvdev` y con entorno limpio. `package.json` y `package-lock.json` son rutas protegidas: una propuesta no puede cambiarlos.
3. **Pruebas dentro de la caja.** `lint`, `typecheck`, `build`, `test` y `py_compile` se ejecutan con una copia dedicada de bwrap y su perfil: sin red, entorno por lista blanca, `/usr` solo lectura, un solo destino de escritura (el worktree), tiempo límite por orden (ya existe) y `--die-with-parent`.
4. **Commit y push fuera de la caja, como root.** Las credenciales de GitHub nunca entran en la caja. Antes del commit se rechazan symlinks y se mantiene la validación de rutas cambiadas (`validate_diff`).
5. **Interruptor.** `CV_DEV_SANDBOX` apagado por defecto: sin el sandbox el worker se comporta como hoy.

**Perfil nuevo, no reutilizado:** el perfil `cv-control-codex-bwrap` da escritura a `control-os-consumer-build` (trabajo de Codex). El Dev Worker necesita su propia copia de bwrap y su propio perfil (`/home/cvdev/control-os-dev/work/**` escribible, `deny` explícito sobre `/root`, `~/.ssh`, `control-os-auth`, `/etc/ssl/private`), partiendo de `scripts/ops/sandbox/opencode-sandbox.profile`.

## 4. Alternativa descartada (B)
Instalar el ejecutor de la Fase B (buzón `jobs/` → resultado → consumidor) como servicio aparte. Es más código, más piezas sin instalar (consumidor activo, RPC de recibos) y duplica lo que el Dev Worker ya hace (aplicar, probar, empujar rama, aprobación humana).

## 5. Pasos, cada uno con su aprobación
1. **E1 — Código y pruebas en local/CI, sin tocar el VPS:** módulo de ejecución en caja del worker con `bwrap` simulado en las pruebas, entorno por lista blanca, interruptor apagado; ampliar `verify-sandbox.py` con pruebas negativas (red, secretos, escritura fuera, symlinks, entorno, bombas de procesos/memoria).
2. **E2 — Instalar en el VPS** (copia de bwrap, perfil AppArmor, directorio de worktrees; copia de seguridad previa). *Toca la seguridad del sistema: requiere SÍ de Ernesto.*
3. **E3 — Validar en vivo** con `verify-sandbox.py` y con una tarea ficticia de documentación pasando por el worker con `CV_DEV_SANDBOX=1`.
4. **E4 — Activar el interruptor** para tareas `dev`. *Requiere SÍ.*
5. **E5 — Fase 2 de la puerta:** permitir `src/`, `tests/` y `app/` en propuestas locales; nunca `scripts/`, `.github/`, `package*.json`, `render.yaml` ni configuración de compilación.

## 6. Riesgos y límites
- **1 núcleo y 3,9 GB:** `next build` dentro de la caja sigue siendo pesado (en el piloto la carga llegó a ≈ 4,7). El tiempo límite protege, pero conviene probar el consumo real en E3.
- **Los límites de bwrap no son una cuota de memoria:** añadir límites de proceso (`prlimit`/cgroup) en E1 y medirlos en E3.
- **Un sandbox bien montado no certifica nada por sí solo:** `ISOLATION_GUARANTEED` solo pasa a `True` tras las pruebas negativas en vivo (E3) y su revisión.
- **Lo de hoy no cierra el riesgo existente:** hasta E4, las tareas `dev` actuales siguen ejecutando pruebas con los secretos en el entorno. Mitigación inmediata y barata (decisión de Ernesto): que `run()` use un entorno limpio por lista blanca sin sandbox, que ya quita los 10 secretos de las pruebas.

## 7. Decisiones de Ernesto
1. ¿Se sigue con el diseño A (aislar el paso de pruebas del Dev Worker)?
2. ¿Se aplica ya la mitigación inmediata (entorno limpio en `run()`), antes del sandbox completo?
3. ¿E2 (perfil AppArmor y copia de bwrap en el VPS) se hace en cuanto E1 esté en `main`, o prefieres revisarlo antes?
