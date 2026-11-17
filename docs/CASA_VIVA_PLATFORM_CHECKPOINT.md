# CURRENT — Control OS primera entrega revisable (2026-11-16)

- Rama de propuesta UI existente; vistas y barrera conservadora de aprobaciones. CI y smoke privado pendientes; sin despliegue. Ver `docs/CONTROL_OS_FIRST_SLICE.md`. PR #241 conserva E2/E3 pendientes y E4 bloqueada.

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
