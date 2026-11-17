# Pantallas

Inventario de las 130 pantallas. **Patrón** remite a Navegación → Patrones de pantalla.

**Estado del diseño:** Módulos A a M diseñados: las 130 pantallas (móvil claro/oscuro y escritorio; ver los grupos de cards A–M). Vistas por rol diseñadas (grupo N: Lennys, Ernesto, Manager, Dependienta, Compradora, Mensajero y Gestora, móvil claro/oscuro). Flujos críticos diseñados como secuencias (grupo O: pedido nuevo, incidencia con VivaBot, oportunidad a producto, contenido y regla comercial, claro y oscuro). Logo oficial aplicado (componente `Logo`, barra lateral, portada y pantalla 128; SVG en `assets/`).

## A · Inicio ejecutivo

Acceso: Inicio · Quién: Lennys, Ernesto (Manager: versión operativa)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 1 | Dashboard general | Índice | DecisionCard ×n, KpiTile hero, lista de actividad |
| 2 | Resumen de ventas de hoy | Detalle | KpiTile hero + SegmentedControl Hoy/Semana, ventas por canal |
| 3 | Estado financiero | Detalle | Caja por moneda (Money), ganancia real, cuentas por cobrar |
| 4 | Alertas prioritarias | Lista | ListRow + StatusPill alerta/bloqueado, ordenadas por impacto |
| 5 | Actividad reciente | Lista | ListRow por evento con hora, agrupado por hoy/ayer |
| 6 | Tareas pendientes de aprobación | Lista | DecisionCard por tarea, filtro por tipo |
| 7 | Estado de funcionamiento del negocio | Detalle | Web, VivaBot, pagos, VPS: ListRow + StatusPill verificado/bloqueado |

## B · Comercio electrónico

Acceso: Negocio · Quién: Lennys, Ernesto, Manager, Dependienta, Compradora, Gestora (según rol)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 8 | Pedidos | Lista | Chips por estado, ListRow con Money y StatusPill |
| 9 | Detalle de pedido | Detalle | Cliente, artículos, pago, entrega, historial; acción principal fija |
| 10 | Nuevo pedido | Editor | Cliente → productos → entrega → pago; resumen con Money |
| 11 | Catálogo de productos | Lista | Rejilla 2 col. de fotos en móvil, lista en escritorio |
| 12 | Detalle de producto | Detalle | Fotos, precio, stock, margen (solo propietarios), ventas |
| 13 | Edición de producto | Editor | Campos en surface-sunken; cambio de precio → ActionProposal |
| 14 | Inventario | Lista | Stock por producto, warning si bajo, ajuste rápido en hoja |
| 15 | Categorías | Lista | ListRow con recuento, reordenar arrastrando |
| 16 | Precios y márgenes | Lista | Precio, coste, margen %; margen bajo en warning |
| 17 | Productos agotados | Lista | ListRow + acción «Pedir al proveedor» |
| 18 | Productos con mayor potencial | Lista | Ranking con razón de la IA en accent-soft |

## C · Finanzas

Acceso: Negocio · Quién: Lennys, Ernesto

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 19 | Dashboard financiero | Índice | KpiTile hero ganancia real, caja por moneda, DecisionCard de gastos |
| 20 | Ingresos y gastos | Lista | Movimientos con Money ±, filtro por moneda y categoría |
| 21 | Ganancia real | Detalle | Ventas − coste − comisiones − mensajería − IA, desglosado |
| 22 | Márgenes por producto | Lista | Ordenable por margen; warning bajo el mínimo |
| 23 | Cuentas por cobrar y pagar | Lista | SegmentedControl Cobrar/Pagar, vencidas en status-blocked |
| 24 | Comisiones | Lista | Por gestora y periodo, estado pagada/pendiente |
| 25 | Flujo de caja | Detalle | Entradas/salidas por semana, saldo proyectado |
| 26 | Tasas de cambio | Detalle | CUP↔USD/EUR/Zelle/USDT, histórico, tasa usada en precios |
| 27 | Presupuestos | Lista | Barra de consumo por partida, warning al 80 % |
| 28 | Informes financieros | Lista | Informes por periodo, exportar PDF/Excel |
| 29 | Simulador de cambios de precios | Editor | Sliders de precio/tasa → impacto en ganancia; aplicar vía ActionProposal |

## D · CRM y WhatsApp

Acceso: Negocio · Quién: Lennys, Ernesto, Manager, Dependienta (asignadas)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 30 | Bandeja unificada | Lista | ListRow por chat: cliente, último mensaje, StatusPill bot/humano/escalado |
| 31 | Detalle de conversación | Detalle | Burbujas; respuestas del bot marcadas; «Intervenir» / «Aprobar respuesta» |
| 32 | Perfil del cliente | Detalle | Datos, gasto total (Money), pedidos, etiquetas, gestora |
| 33 | Transcripción de audios | Detalle | Audio + texto con marcas de tiempo |
| 34 | Incidencias comerciales | Lista | bloqueado/ejecutando/verificado; abre flujo 2 |
| 35 | Respuestas sugeridas por IA | Lista | Sugerencias en accent-soft: Enviar / Editar / Descartar |
| 36 | Historial de pedidos | Lista | Pedidos del cliente con Money y estado |
| 37 | Seguimiento comercial | Lista | Clientes a recontactar, motivo y fecha |
| 38 | Configuración y conocimiento de VivaBot | Editor | Fuentes, reglas, tono, horario (9–17 h); cambios vía ActionProposal |

## E · Proveedores y oportunidades

Acceso: Negocio · Quién: Lennys, Ernesto, Compradora, Manager (sin costes)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 39 | Directorio de proveedores | Lista | ListRow con categoría y fiabilidad |
| 40 | Perfil del proveedor | Detalle | Contacto, productos, precios, historial de compras |
| 41 | Radar de ofertas en WhatsApp | Lista | Ofertas detectadas por la IA, margen estimado |
| 42 | Comparador de precios | Detalle | Mismo producto entre proveedores, el mejor destacado |
| 43 | Detalle de oportunidad | Detalle | Oferta, margen, demanda estimada; «Convertir en candidato» |
| 44 | Historial de precios | Detalle | Evolución del precio por proveedor |
| 45 | Solicitudes de compra | Lista | pendiente → aprobado → recibido; DecisionCard a Lennys |
| 46 | Productos candidatos | Lista | Candidatos con puntuación y razón de la IA |
| 47 | Generador de cotizaciones | Editor | Cliente + productos + margen → cotización PDF/WhatsApp |

## F · Content Studio

Acceso: Operaciones · Quién: Lennys, Ernesto, Manager

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 48 | Dashboard de contenido | Índice | Pendientes por flujo (Cleanfy, Lifestyle, Hero Offer), publicados, aprobaciones |
| 49 | Cola de productos pendientes | Lista | Producto + flujo que le falta |
| 50 | Editor de fotografías | Editor | Cleanfy: fondo, luz, recorte |
| 51 | Comparador antes y después | Detalle | Deslizador antes/después |
| 52 | Generador de imágenes lifestyle | Editor | Escena, estilo, variantes |
| 53 | Generador de ofertas publicitarias | Editor | Hero Offer: oferta, precio, formato |
| 54 | Generador de descripciones | Editor | Texto generado en accent-soft, editable |
| 55 | Calendario de publicaciones | Detalle | Semana/mes, canal por publicación |
| 56 | Biblioteca multimedia | Lista | Rejilla de medios, filtros por producto |
| 57 | Aprobaciones de contenido | Lista | DecisionCard por pieza con vista previa |
| 58 | Historial y restauración | Lista | Versiones por imagen, «Restaurar» |

## G · Marketing y ventas

Acceso: Negocio · Quién: Lennys, Ernesto, Manager

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 59 | Campañas | Lista | StatusPill por campaña, gasto y resultado |
| 60 | Detalle de campaña | Detalle | Piezas, público, presupuesto, conversiones |
| 61 | Rendimiento publicitario | Detalle | KPIs: alcance, clics, ventas, coste por venta |
| 62 | Generador de ofertas Grand Slam | Editor | Oferta paso a paso con el asistente |
| 63 | Propuestas comerciales | Editor | Plantilla + cliente → PDF/WhatsApp |
| 64 | Segmentación de clientes | Lista | Segmentos con tamaño y valor |
| 65 | Seguimiento de conversiones | Detalle | Embudo conversación → pedido → entrega |
| 66 | Calendario comercial | Detalle | Fechas clave, campañas, temporadas |

## H · Gestoras y equipo

Acceso: Operaciones · Quién: Lennys, Ernesto, Manager; Gestora ve solo lo suyo

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 67 | Directorio de gestoras | Lista | ListRow con avatar, ventas del mes |
| 68 | Perfil de gestora | Detalle | Datos, clientes, ventas, comisión |
| 69 | Ventas y comisiones | Lista | Por gestora y periodo, pagadas/pendientes |
| 70 | Clasificación por rendimiento | Lista | Ranking con variación |
| 71 | Gestión de empleados | Lista | Empleados con rol y estado |
| 72 | Horarios | Detalle | Semana por persona |
| 73 | Permisos y responsabilidades | Editor | Matriz rol × módulo; cambios vía ActionProposal |
| 74 | Tareas del equipo | Lista | Tareas con responsable, fecha y StatusPill |

## I · Logística

Acceso: Operaciones · Quién: Lennys, Ernesto, Manager; Mensajero ve solo lo suyo

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 75 | Pendientes de entrega | Lista | Pedidos listos sin mensajero, más antiguos arriba |
| 76 | En preparación | Lista | Pedidos ejecutando con responsable |
| 77 | Asignación de mensajeros | Hoja | Sugerencia por cercanía y carga; «Asignar» |
| 78 | Seguimiento de entregas | Lista | En ruta / entregado / incidencia |
| 79 | Mapa de rutas | Detalle | Mapa con paradas ordenadas |
| 80 | Tarifas de mensajería | Editor | Tarifa por zona; cambios vía ActionProposal |
| 81 | Liquidación de mensajeros | Lista | Entregas, cobros y tarifa por mensajero |
| 82 | Incidencias de entrega | Lista | bloqueado + DecisionCard (reintentar, devolver, cancelar) |

## J · Inteligencia artificial y agentes

Acceso: IA · Quién: Ernesto (edición), Lennys (lectura y gasto)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 83 | Centro de agentes | Índice | ChatGPT, Claude Code, Codex, Ollama, Qwen, OpenCode, VivaBot y futuros: ListRow + StatusPill |
| 84 | Perfil de agente | Detalle | Función, permisos, tareas, coste, rendimiento |
| 85 | Estado operativo | Lista | Disponible / ejecutando / bloqueado por agente |
| 86 | Tareas activas | Lista | Tarea, agente, tiempo, StatusPill |
| 87 | Historial de ejecuciones | Lista | Ejecuciones con resultado y evidencia |
| 88 | Evaluación del rendimiento | Detalle | Éxito, tiempo, coste por tarea |
| 89 | Configuración de permisos | Editor | Qué puede tocar cada agente; cambios vía ActionProposal |
| 90 | Registro de nuevas herramientas | Editor | Formulario o conversación con el asistente |
| 91 | Comparador de herramientas | Detalle | Coste, capacidad, límites lado a lado |
| 92 | Consumo y límites | Detalle | Uso por herramienta vs. límite |
| 93 | Suscripciones y renovaciones | Lista | Fecha de renovación, importe en Money |
| 94 | Gastos mensuales | Detalle | KpiTile total vs. presupuesto (15–40 USD), por herramienta |
| 95 | Alertas de presupuesto | Lista | warning al 80 %, bloqueado al 100 % |

## K · Evolution Engine

Acceso: IA · Quién: Ernesto (edición), Lennys (aprobación de despliegues)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 96 | Dashboard de evolución técnica | Índice | Fase actual, cola, PR por aprobar, salud del VPS |
| 97 | Roadmap | Detalle | Fases en línea de tiempo con StatusPill |
| 98 | Fases y subfases | Lista | Árbol con progreso |
| 99 | Cola de desarrollo | Lista | Tareas por prioridad y estado |
| 100 | Detalle de tarea técnica | Detalle | Descripción, agente, evidencias (PR, pruebas, capturas), estado |
| 101 | Dev Worker | Detalle | Trabajo actual del worker, logs en vivo |
| 102 | Programadores locales | Lista | Ollama/Qwen/OpenCode locales: online/offline |
| 103 | Integración GitHub | Detalle | Repos conectados, ramas |
| 104 | Pull requests | Lista | PR con pruebas y StatusPill; abrir → aprobar |
| 105 | Pruebas automatizadas | Lista | Suites con verificado/bloqueado |
| 106 | Estado del VPS | Detalle | CPU, memoria, disco, uptime |
| 107 | Monitor de servicios | Lista | Web, bot, n8n, Supabase: StatusPill |
| 108 | Incidencias técnicas | Lista | bloqueado → ejecutando → verificado |
| 109 | Centro de auditoría | Lista | Hallazgos con severidad y evidencia |
| 110 | Respaldos y restauración | Lista | Copias con fecha; «Restaurar» con confirmación |
| 111 | Historial de cambios | Lista | Cambios desplegados con autor y PR |
| 112 | Aprobación de despliegues | Detalle | DecisionCard alto: pruebas, cambios, «Desplegar» |

## L · Asistente universal

Acceso: Botón flotante / ⌘K · Quién: Todos, dentro de sus permisos

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 113 | Conversación | Detalle | Mensajes; respuestas con KpiTile o ListRow, no párrafos |
| 114 | Entrada por voz | Hoja | Onda de voz, transcripción en vivo |
| 115 | Cámara y archivos | Hoja | Foto, galería, documento |
| 116 | Resumen de la petición | Detalle | «Entendí que quieres…» con Editar |
| 117 | Propuesta de acción | Detalle | ActionProposal |
| 118 | Advertencias de impacto | Detalle | Avisos medio/alto dentro de la propuesta |
| 119 | Confirmación de cambios | Hoja | Confirmación final; impacto alto pide al rol responsable |
| 120 | Seguimiento y resultados | Detalle | StatusPill hasta verificado, con evidencia y «Revertir» |

## M · Configuración

Acceso: Más · Quién: Todos (Administración y Roles: Lennys, Ernesto)

| # | Pantalla | Patrón | Contenido clave |
| --- | --- | --- | --- |
| 121 | Perfil personal | Editor | Nombre, foto, contacto, idioma |
| 122 | Administración de usuarios | Lista | Usuarios con rol y estado |
| 123 | Roles y permisos | Editor | Matriz; cambios vía ActionProposal |
| 124 | Reglas comerciales | Lista | Márgenes, descuentos, comisiones; flujo 5 |
| 125 | Integraciones | Lista | WooCommerce, WhatsApp, Supabase, n8n, GitHub: StatusPill |
| 126 | Notificaciones | Editor | Qué llega y por dónde |
| 127 | Seguridad | Editor | Sesiones, doble factor, registro de accesos |
| 128 | Identidad visual | Detalle | Este sistema de diseño: logo, colores, tipografía |
| 129 | Preferencias | Editor | Tema claro/oscuro, moneda por defecto |
| 130 | Centro de ayuda | Lista | Guías breves y «Pregúntale al asistente» |
