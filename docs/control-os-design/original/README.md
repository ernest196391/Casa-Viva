Casa Viva Control OS es el sistema operativo del negocio: un solo lugar, pensado primero para el teléfono, desde el que los propietarios ven, deciden y hacen evolucionar Casa Viva. Debe transmitir **control, tranquilidad, claridad y confianza**. Referencias de calidad: Apple, Linear, Stripe, Shopify Admin.

## Principios

1. **Decisión primero.** Cada pantalla empieza por lo que espera al propietario (`DecisionCard`), después el estado (`KpiTile`, `StatusPill`) y al final el detalle. Si algo no lleva a una decisión o a una acción, baja o desaparece.
2. **Una cifra protagonista.** Un solo `KpiTile hero` (`numeric-xl`) por pantalla; el resto en `numeric-lg`.
3. **Nada cambia sin confirmación.** Precios, reglas, permisos, reembolsos y despliegues pasan por `ActionProposal`: petición → cambios (antes → después) → impacto → confirmar.
4. **Evidencia, no promesas.** Agentes y Evolution Engine muestran pruebas reales (PR, pruebas, logs, capturas) junto a su `StatusPill`.
5. **Cero decoración.** Sin ilustraciones, degradados, emojis ni textos explicativos. Sin tarjetas repetidas: las colecciones son filas `ListRow` dentro de un único `.cv-list`.

## Contenido y tono

- Español de Cuba, claro y directo; tuteo («Tienes 3 pedidos por aprobar»). El asistente habla en primera persona («He preparado…»), la interfaz no.
- Frases cortas, sin tecnicismos: «Pedido sin mensajero», no «Unassigned delivery entity».
- Títulos en mayúscula inicial (sentence case); solo `overline` va en mayúsculas.
- Botones en infinitivo, una o dos palabras: «Aprobar», «Asignar», «Publicar».
- Números siempre con unidad: `Money` para dinero (CUP, USD, EUR, «USD · Zelle», USDT), «%» para márgenes, «hace 12 min» para tiempos recientes y «14 nov, 10:42» para fechas.
- Nombres de productos y personas tal cual: «Ventilador Royal 18"», «Gestora Yaimí».
- Estados vacíos: una línea + una acción. «No hay pedidos pendientes. · Nuevo pedido».

## Color

- Marca: `brand-petroleo` (verde corporativo), `brand-caoba`, `brand-espresso`, `brand-crema`. Úsalos tal cual solo en identidad (logotipo, cover, splash).
- Interfaz: fondo `bg` (blanco cálido) → tarjetas `surface` (blanco) → campos `surface-sunken` (beige). Líneas `border`; campos y controles `border-strong`.
- Texto: `ink` para lo principal, `ink-muted` para metadatos. Ambos ≥4.5:1 sobre `bg`, `surface` y `surface-sunken` en los dos temas.
- `primary` (petróleo) = acción principal, navegación activa y Aprobado. Texto sobre ella: `on-primary`.
- `accent` (caoba) = **solo** lo que viene de la IA o del asistente: botón flotante, propuestas, respuestas sugeridas (`accent-soft` de fondo). Así el propietario distingue siempre lo que propuso una máquina.
- Estados: `status-pending`, `status-running`, `status-blocked`, `status-verified` con su `-bg`, y `warning`/`warning-bg`. Siempre con icono y palabra; Bloqueado (rojo, oscuro) y Verificado (verde) difieren también en icono y luminosidad.
- Modo oscuro: mismos nombres de token, valores en el tema `dark`. Nunca escribas un color a mano.

## Tipografía

- Una sola familia: Helvetica Neue (`--font-sans`), con Helvetica y Arial de respaldo. Sin archivos que cargar.
- Escala: `display` (saludo de Inicio) · `title-1` (título de pantalla) · `title-2` (sección) · `headline` (fila, tarjeta) · `body` · `callout` · `label` (botones, pestañas) · `caption` · `overline` (grupos, etiquetas de KPI).
- Cifras: `numeric-xl` y `numeric-lg`, siempre con `font-variant-numeric: tabular-nums`.
- Campos de texto a 16px en móvil (evita el zoom de iOS).

## Espacio, forma y profundidad

- Rejilla de 4px: `space-1` 4 · `space-2` 8 · `space-3` 12 · `space-4` 16 · `space-5` 20 · `space-6` 24 · `space-8` 32 · `space-12` 48.
- Móvil: margen lateral `space-4`, separación entre secciones `space-6`. Escritorio: `space-6` y `space-8`, contenido hasta `content-max`.
- Radios: `radius-sm` píldoras · `radius-md` botones y campos · `radius-lg` tarjetas y listas · `radius-xl` hojas y compositor · `radius-pill` chips y botón del asistente.
- Profundidad: `border` + `shadow-sm` para tarjetas; `shadow-lg` solo para lo que flota (hojas, menús, botón del asistente). Velo `overlay` detrás de las hojas.
- Tocables de al menos `touch-min` (44px). Foco: anillo `focus` de 2px con 2px de separación, en todo control.
- Movimiento: 150 ms para estados, 250 ms para hojas que suben; nada que rebote ni se repita.

## Layout

- **Móvil (<768px):** cabecera con `title-1` + campana y búsqueda; contenido en una columna; `TabBar` fija abajo con el botón flotante del asistente encima, a la derecha.
- **Tableta (768–1023px):** misma barra inferior; rejillas de 2 columnas; el detalle se abre en hoja lateral.
- **Escritorio (≥1024px):** `Sidebar` de `sidebar-width` a la izquierda; lista + detalle en dos paneles; el asistente se abre como panel derecho (⌘K).
- Orden de cada pantalla: decisiones → cifra protagonista → KPIs → lista → actividad.

## Iconografía

- Lucide, trazo 1.75 (2 en estado activo), extremos redondeados, siempre en `currentColor`. Tamaños: 22 barra inferior, 18 filas y barra lateral, 14 píldoras.
- El componente `Icon` incluye el conjunto base; para producción usa `lucide-react` con la misma geometría.
- Nunca emojis ni iconos rellenos. Un icono solo, sin texto, lleva `label` accesible.

## Logotipo

- Usa únicamente el isotipo CV y el logotipo CASA VIVA del Brand Kit oficial (archivos SVG aprobados). Este sistema todavía no los contiene: hasta subirlos al grupo Logos, la barra lateral muestra la palabra «CASA VIVA» en texto (`primary`, espaciado .14em). No redibujes ni aproximes el logotipo.

## Asistente universal

- Accesible en toda pantalla: botón flotante caoba (móvil) o ⌘K / «Pregúntale al asistente» (escritorio).
- Entrada por texto, voz (`mic`), cámara y archivos (`AssistantComposer`), con sugerencias según la pantalla de origen.
- Ciclo fijo: petición → resumen de lo entendido → `ActionProposal` (cambios + advertencias de impacto) → confirmación → seguimiento con `StatusPill` y resultado.
- Las respuestas informativas («¿Cuánto ganamos hoy?») se contestan con `KpiTile` y una frase; nada de párrafos largos.

## Componentes

`Icon` · `Button` · `StatusPill` · `Money` · `KpiTile` · `DecisionCard` · `ListRow` · `SegmentedControl` · `TabBar` · `Sidebar` · `AssistantComposer` · `ActionProposal`. Cada uno tiene su guía y vista previa. Las secciones **Navegación**, **Roles**, **Flujos críticos** y **Pantallas** definen cómo se combinan.
