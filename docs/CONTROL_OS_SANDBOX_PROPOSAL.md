# Control OS × OpenCode — Propuesta de sandbox Bubblewrap + AppArmor (Fase E)

Estado: **PROPUESTA. No instalada, no probada, no activada.** Nada de este documento se ha ejecutado contra el VPS. El adaptador (`scripts/ops/opencode-adapter.py`) sigue con `ISOLATION_GUARANTEED = False` y sigue sin invocar el binario real. Este documento es material de revisión humana.

Datos verificados en el entorno (aportados por la operación, no re-verificados aquí): Ubuntu 24.04, AppArmor 4.0.1 activo, Bubblewrap 0.9.0 instalado, restricción de user namespaces activa, sin perfil específico de Bubblewrap, el perfil de Flatpak usa `flags=(unconfined)` y **no debe copiarse**. Los intentos previos de Bubblewrap fallaron por permisos de `uid_map` y `net_admin`.

## 1. Diagnóstico de los fallos observados (hipótesis a validar)

- **`uid_map: Permission denied`**: en Ubuntu 24.04 la creación de user namespaces sin privilegios está mediada por AppArmor 4.0 (`apparmor_restrict_unprivileged_userns`). Un binario sin perfil no puede crear el user namespace que bwrap necesita para operar sin setuid.
- **`net_admin: Operation not permitted`**: síntoma posterior, no causa raíz. Sin user namespace, bwrap cae en el camino privilegiado (configurar el loopback del nuevo namespace de red exige `CAP_NET_ADMIN` en el namespace inicial, que cvdev no tiene). Si el user namespace se crea, bwrap configura el loopback con las capacidades propias de ese namespace y este error desaparece.

Hipótesis central: **un perfil AppArmor adjunto a `/usr/bin/bwrap` con la regla `userns,` habilita el camino sin privilegios** y con él desaparecen ambos errores. Si la instalación supervisada refutara esta hipótesis, volver a la sección de alternativas (§6) — nunca resolverlo aflojando el perfil (`unconfined` está prohibido).

## 2. Artefactos de la propuesta

| Artefacto | Papel |
| --- | --- |
| `scripts/ops/sandbox/opencode-sandbox.profile` | Perfil AppArmor propuesto (no instalado) |
| `scripts/ops/sandbox/opencode-sandbox-spec.py` | Autoridad del comando bwrap + validadores (sin ejecutar nada) |
| `scripts/ops/sandbox/verify-sandbox.py` | Arnés de pruebas negativas para ejecutar DESPUÉS de instalar |
| `scripts/ops/tests/opencode-sandbox.test.py` | Pruebas de configuración (corren en CI ahora, sin bwrap) |

## 3. Montaje mínimo de Bubblewrap

El comando lo construye `build_bwrap_command(workspace, inner_cmd)`; este es un ejemplo con worktree `run-123` (la autoridad es el módulo, no este texto):

```text
bwrap
  --unshare-all --die-with-parent --new-session --clearenv
  --setenv PATH /usr/bin:/bin --setenv HOME /tmp --setenv TMPDIR /tmp
  --ro-bind /usr /usr
  --ro-bind-try /usr/local /usr/local
  --ro-bind-try /etc/ssl/certs /etc/ssl/certs
  --ro-bind-try /etc/localtime /etc/localtime
  --ro-bind-try /etc/ld.so.cache /etc/ld.so.cache
  --ro-bind-try /etc/ld.so.preload /etc/ld.so.preload
  --symlink usr/bin /bin  --symlink usr/lib /lib  --symlink usr/lib64 /lib64
  --symlink usr/sbin /sbin
  --proc /proc --dev /dev
  --tmpfs /tmp --tmpfs /run
  --tmpfs /home/cvdev/control-os-dev/work        # oculta los demás run-<id>
  --bind /home/cvdev/control-os-dev/work/run-123 /home/cvdev/control-os-dev/work/run-123
  --chdir /home/cvdev/control-os-dev/work/run-123
  -- <comando interno, p. ej. opencode run --pure -m ... --format json>
```

Invariantes del contrato (verificadas por `validate_command`):

- **Un solo destino de escritura real**: el worktree desechable (`run-<id>`, hijo directo de `/home/cvdev/control-os-dev/work`, único nombre de hoja aceptado). Todo lo demás del sistema es read-only o tmpfs desechable.
- **Red aislada**: `--unshare-all` incluye el namespace de red; `--share-net`, `--dev-bind` y `--dev-bind-try` están prohibidos y las pruebas los rechazan; las claves de `--setenv` se validan contra `CHILD_ENV_KEYS` (un secreto inyectado en el comando es una violación).
- **Entorno limpio por lista blanca**: `--clearenv` + `--setenv` solo con claves de `CHILD_ENV_KEYS`. La integración futura con el adaptador debe pasar aquí SOLO el entorno ya depurado por `child_environment()` del adaptador (segunda capa sobre el scrubbing existente).
- **La raíz de worktrees se cubre con tmpfs** antes de montar el `run-<id>`, para que la jaula no vea ningún otro trabajo del buzón.
- `--die-with-parent` y `--new-session` complementan la muerte del grupo de procesos que ya hace el adaptador con SIGKILL.

## 4. Perfil AppArmor propuesto

`scripts/ops/sandbox/opencode-sandbox.profile`, adjunto a `/usr/bin/bwrap`:

- `abi 4.0` + `include <tunables/global>`, con `@{CV_CONTROL_OS} = /home/cvdev/control-os-dev` (debe coincidir con el valor por defecto de `CV_OPENCODE_HOME` del ejecutor y con `CV_CONTROL_OS` del módulo; cambiar la ruta en el VPS invalida perfil y comando).
- **`userns,`**: la regla que justifica el perfil (creación de user namespaces). Es el único permiso nuevo respecto a la mediación por omisión.
- **`deny network,`**: defensa en profundidad; la red ya está aislada por namespace. **Interferencia conocida con el modelo remoto**: con esta regla y `--unshare-all`, la jaula NO puede ejecutar el modelo remoto (la CLI de OpenCode necesita salida a la red) ni transportar sus credenciales (`CHILD_ENV_KEYS` no lleva secretos). Tal como está propuesta, la integración del §8 con el comando `opencode run -m ...` no funcionaría dentro de la jaula; resolverlo exige una decisión de diseño documentada (§6), nunca aflojar el perfil por su cuenta.
- Hijos con herencia `ix` (`/usr/bin/** ix`, implica `m` para la carga ejecutable), bibliotecas `mr` (incluido `/usr/lib64/**`, que aloja el cargador dinámico en distros usrmerge vía `/lib64 → usr/lib64`), lecturas de sistema acotadas.
- **Escritura limitada** al worktree (`@{CV_CONTROL_OS}/work/{,**} rw`) y a `/tmp` (tmpfs). Todo lo demás queda denegado por omisión.
- **Sin `flags=(unconfined)`** (prohibido) y **sin `capability` alguna**: dentro del user namespace bwrap usa las capacidades del namespace, incluida la configuración del loopback.
- **`/etc/ssl` nunca se concede ni se monta entero**: contiene `/etc/ssl/private` (claves TLS del host). Solo se expone `/etc/ssl/certs` (CA públicas).

Supuestos pendientes de validar en la instalación supervisada (anotados, no resueltos): si bwrap crea los directorios destino de los montajes (`/home/cvdev/control-os-dev/work`, `/bin`, …) dentro de la jaula; si los flags `attach_disconnected,mediate_deleted` son necesarios o sobran; qué archivos de `/usr` exactos necesita el binario real de OpenCode (calibración de la primera invocación supervisada).

## 5. Pruebas

### Ya ejecutables (CI, sin bwrap)

`scripts/ops/tests/opencode-sandbox.test.py` (cableada en `validate.yml` junto a las suites de Fases A–D):

- Perfil: invariantes completas (`validate_profile`) y **mutaciones negativas** detectadas: `flags=(unconfined)`, `capability net_admin`, reglas `rw` amplias (`/ rw`, `/etc/{,**} rw`), lectura de `/etc/ssl/**` (expondría `/etc/ssl/private`), falta `/usr/lib64/** mr`, falta `userns`, falta `deny network`, segundo bloque de perfil.
- Comando: contrato exacto del montaje mínimo y **rechazo** de `--share-net`, `--dev-bind`, `--dev-bind-try`, segundo `--bind`, bind de `/etc` y de `/etc/ssl` entero, `--setenv` con claves fuera de lista blanca (secretos), falta `--unshare-all`, traversal de workspace, hoja que no es `run-<id>`, entorno fuera de lista blanca, construcción de shell en argv.
- Coherencia entre artefactos: `@{CV_CONTROL_OS}` del perfil = `CV_CONTROL_OS` del módulo = valor por defecto de `CV_OPENCODE_HOME` en el ejecutor de Fase B (cambio de ruta = revisión de los tres).
- Arnés: lógica completa con **runner falso** (veredictos `ok` / `failed` / `sandbox-no-operativo`, control positivo obligatorio, acumulación de fallos, sin respuesta = no operativo en bootstrap).
- Protecciones: el arnés se niega a ejecutarse sin `--workspace` + `--execute`; CI nunca lo ejecuta real.
- Escaneo estático: sin subprocesos en el módulo de especificación, sin red ni shell en el arnés, sin cableado con el adaptador.

### Para ejecutar después de instalar (revisión humana)

`python3 scripts/ops/sandbox/verify-sandbox.py --workspace /home/cvdev/control-os-dev/work/run-<run_id> --execute`

Ejecuta dentro de la jaula real: `bootstrap` (arranque), control positivo de escritura en el worktree, denegación de escritura en `/usr/bin`, `/etc` y `/`, enmascaramiento del home, ausencia de `/etc/shadow` y bloqueo de salida TCP. Veredictos:

- `ok` (salida 0): todas las denegaciones confirmadas.
- `failed` (salida 5): alguna denegación faltó — **corregir la propuesta, no el veredicto**.
- `sandbox-no-operativo` (salida 4): la jaula ni arrancó — revisar el diagnóstico contra §1; si la hipótesis de `userns,` falla, ir a §6.

## 6. Alternativas si la hipótesis central falla

| Alternativa | Evaluación |
| --- | --- |
| bwrap setuid + file capabilities (`cap_net_admin`, `cap_sys_admin`) | Descartada: amplia la superficie privilegiada en el host; último recurso |
| Añadir `capability net_admin,` al perfil | Solo si la calibración lo exige y tras revisión; nunca como atajo de un fallo |
| Proxy local (socket UNIX) o calibración de red selectiva para el modelo remoto | Pendiente de diseño: sin ella, `deny network` + `--unshare-all` hacen inviable el `opencode run -m ...` dentro de la jaula; las credenciales del modelo no pueden entrar por `CHILD_ENV_KEYS` (contienen secretos) |
| nsjail en lugar de bwrap | Requiere nueva evaluación completa; no emprendida |
| Desistir del sandbox | El adaptador permanece bloqueado indefinidamente; la integración IA no avanza |

## 7. Instalación y reversión (solo tras revisión humana)

**Revisión de sintaxis sin cargar en el kernel:**

```bash
sudo apparmor_parser -Q -r scripts/ops/sandbox/opencode-sandbox.profile
```

**Instalación:**

```bash
sudo install -m 0644 scripts/ops/sandbox/opencode-sandbox.profile /etc/apparmor.d/cv-opencode-bwrap
sudo apparmor_parser -r /etc/apparmor.d/cv-opencode-bwrap
```

**Verificación posterior (§5):** arnés con `--execute` sobre un worktree desechable creado a mano; solo un veredicto `ok` habilita discutir la activación del adaptador.

**Reversión:**

```bash
sudo apparmor_parser -R /etc/apparmor.d/cv-opencode-bwrap
sudo rm /etc/apparmor.d/cv-opencode-bwrap
```

Con el perfil removido, bwrap vuelve al estado anterior (fallará igual que en los intentos previos; no queda nada persistente salvo el archivo copiado, eliminado en la reversión).

## 8. Integración futura con el adaptador (NO realizada)

El punto de integración sería `run_command` del adaptador: envolver el comando documentado con `build_bwrap_command(cwd, comando)` y fusionar el entorno depurado del adaptador en `--setenv`. **No se ha implementado**: `ISOLATION_GUARANTEED` sigue en `False`, los interruptores siguen exigidos y las pruebas estáticas verifican que no hay cableado. La constante solo puede pasar a `True` tras: (1) instalación del perfil, (2) arnés con veredicto `ok` en una instalación real, (3) decisión humana documentada.

**Incompatibilidad pendiente**: `child_environment()` del adaptador puede conservar `SHELL` (ya habilitada en `CHILD_ENV_KEYS`), pero el comando documentado (`opencode run -m ...`) necesita red de salida y credenciales del modelo, que la propuesta prohíbe deliberadamente. Antes de cablear el adaptador hay que resolver §6 (proxy o red selectiva con decisión humana) o reformular la frontera de lo que corre dentro de la jaula.

## 9. Limitaciones declaradas

- La propuesta no ha sido ejecutada: ni el perfil, ni el comando bwrap, ni el arnés corrieron contra el sistema real. **Nada afirma que el sandbox funcione.**
- El perfil no concede `net_admin`: si la hipótesis de §1 es incorrecta, la instalación supervisada lo revelará y se decide en §6.
- Los montajes son deliberadamente mínimos; la primera invocación real supervisada calibrará lecturas adicionales (p. ej. zona horaria, datos de locales) sin conceder escrituras nuevas. La inclusión del enlace `/lib64 → usr/lib64` asume distro usrmerge (Ubuntu 24.04): si el host no tuviera `/usr/lib64`, el cargador dinámico fallaría de forma visible en `bootstrap` (fallo cerrado, no silencioso).
- **El modelo remoto no funciona dentro de la jaula tal como está propuesta**: `deny network` + `--unshare-all` bloquean la salida que la CLI necesita, y ninguna clave de `CHILD_ENV_KEYS` transporta credenciales del modelo. Es un riesgo abierto con decisión pendiente (§6/§8), no un detalle de calibración.
- La ruta del buzón (`CV_CONTROL_OS`) está fijada en perfil, módulo y ejecutor; cambiar `CV_OPENCODE_HOME` en el VPS invalida la propuesta y debe ir acompañada de la revisión de los tres artefactos.
- El arnés no cubre fugas por señales, `/proc` detallado ni timing; se acepta como primera barrera, no como garantía absoluta.
- `npm run lint/typecheck/build` no fueron ejecutables en este equipo (limitación conocida del entorno local, ya documentada en fases anteriores).
