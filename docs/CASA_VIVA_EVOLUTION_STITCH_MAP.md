# Evolution Engine — mapa previo de integración Stitch
Fecha: 15 de noviembre de 2026. Unidad EVO-MAP-001, PREPARE.
Estado: diseño de integración documentado; implementación y certificación pendientes.

## Evidencia y límites
- Core consultado en `8f3e525985c6e1a4ebf8f249925672a6d8956aa5`.
- PR #224 consultado: abierto, no fusionado, mergeable=false; head `bbd10f21d82fd53fc8e6d1aed7d904e3ce8001e0`. EVO-001 no está cerrado.
- Se leyeron AGENTS, Blueprint maestro, checkpoint, estado actual, guard y app.js de Control Web, acceso de propietarios y auditoría Content Studio del 15 de noviembre.
- Referencia visual local inspeccionada: `Downloads/stitch_casa_viva_control_os.zip`, cinco HTML y DESIGN.md. La versión `stitch_casa_viva_control_os(2).zip` fue localizada por metadatos pero sus bytes NO se han comparado. Verificar ese delta antes de implementar.
- Brand Kit no localizado por nombre en Desktop, Documents y Pictures. Esto no demuestra su ausencia del equipo. Conservar identidad y logo oficiales actuales; no extraer una marca del prototipo.
- Clonación HTTPS local impedida por resolución DNS de github.com; consultas de repositorio realizadas por el conector. No se ejecutaron pruebas PHP, integración, navegador ni VPS.
- Producción privada no inspeccionada en esta unidad; ningún SHA de producción nuevo certificado.

## Autoridad, actores y permisos
WooCommerce conserva productos, precios, stock y pedidos. El Core conserva edición, medios, versiones y conocimiento. Control OS conserva tareas, decisiones y eventos. No crear aplicación, autenticación ni catálogo paralelo.
`scripts/ops/control-web/index.php` aplica sesión WordPress y capability manage_options: WP 1 consulta y escribe; WP 14 consulta. No ampliar esos permisos al integrar el editor; el editor conserva cvd_edit_catalog_content y sus controles de nonce y versión. El navegador nunca recibe credenciales del bot ni de infraestructura.
La nueva navegación es una presentación de las capacidades existentes. Conservar rutas/enlaces previos y accesos por rol.

## Mapa de pantallas
| Stitch | Reutilizar | Modificar | Construir incrementalmente | Eliminar del prototipo |
|---|---|---|---|---|
| Dirección: métricas, evolución, decisiones, prioridades, incidencias | Control Web index.php/app.js; snapshot; sincronizador; ops_tasks, aprobaciones y eventos | Jerarquía y navegación móvil; resumen por datos y fecha; decisiones con evidencia | Proyección de metas y métricas únicamente donde no exista campo equivalente; decisiones enlazadas a aprobación real | Agentes “en vivo” sin prueba, KPIs de muestra, avatar, almacenes y pasarelas ficticias |
| Catálogo: salud, cobertura y deficiencias | CVD_Catalogo_Auto::listo/informe; WooCommerce; cambios de CVD_Catalog_Version | Auditoría incluye productos protegidos, sin autorizar su sobrescritura; total y denominador explícitos | Rúbrica 0–100 versionada y evidencias; cola persistente conectada a tareas existentes | Corrección masiva de un clic; cifras constantes; animación de auditoría sin trabajo real |
| Productos, dentro de Catálogo | Listado y prioridades de CVD_Content_Studio; disponibilidad WooCommerce | Búsqueda, filtros y paginación de servidor; orden comercial explicable; conservar acceso previo | Ventana/fuente de ventas, desempate determinista y etiqueta Sin datos | Muebles y fotografías de muestra; lista paralela de productos |
| Editor: fotos, título, descripción, ficha, fuentes | CVD_Content_Studio v2; CVD_Photo_AI; CVD_Dudas_Catalogo; versiones y borradores existentes | Comparación actual/propuesta, diff y fuentes por afirmación; aprobación y restauración por versión | Cuarto entregable persistido y contrato de cuatro fotos distintas; bloqueos por datos críticos | SEO porcentual inventado, especificaciones sin fuente, aprobación ficticia y generación automática |
| Actividad: filtros, evidencia, reintento | Tareas, runs y ops_events reales; proyector existente | Seis estados de ejecución; duración, responsable y evidencia; detalles técnicos desplegables | Coste estimado/reservado/conciliado y conciliación de reintentos si falta | Logs de muestra, agentes simulados, reintentos que eludan autorización o presupuesto |

Los archivos de apariencia pertenecen al Control Web existente (`scripts/ops/control-web/`). El editor PHP y sus assets permanecen en el Core. No introducir Next.js como segunda aplicación para reemplazar este panel PHP.

## Cuatro fotografías
1. HERO profesional.
2. Lifestyle con uso fiel.
3. Características y beneficios sustentados.
4. Detalles, dimensiones o variantes comprobados.
v2 tiene hero/life/ben: no cumple aún cuatro archivos fotográficos persistidos. BENEFICIO compuesto en navegador no cuenta automáticamente como foto maestra.
No inventar perspectivas ni medidas cuando falten fuentes: solicitar información por Dudas. Separar generación, revisión y publicación. Conservar destino actual de HERO frente a anuncios; publicar cuatro imágenes en galería requerirá un contrato explícito y revisión. No activar publicación autónoma.

## Datos y estados
Cada métrica declara fuente, fecha y universo. Valor ausente: “Sin datos”; cero solo si se midió cero. Mantener último dato con fecha si falla la actualización.
Cobertura = auditados vigentes / universo auditable; una nueva versión invalida la auditoría afectada. Calidad media solo sobre rúbrica homogénea vigente, mostrando cobertura.
Prioridad comercial usa ventas, disponibilidad y demanda demostrada; margen únicamente si hay fuente fiable. Calidad y potencial comercial son dimensiones distintas.

| Estado interno existente | Presentación propuesta |
|---|---|
| new, triaged | Pendiente |
| running | En proceso |
| verifying, waiting_approval | En revisión |
| done | Completado |
| failed | Error |
| blocked, waiting_external, waiting_ai | Bloqueado, indicando causa |
| cancelled, superseded | Conservar como histórico Cancelado/Sustituido; no contarlos como completados |

Esta tabla es proyección, no migración de estados canónicos. Una espera no prueba que un agente esté ejecutándose. No fabricar duración ni coste cero si no hay recibo.

## Secuencia de unidades y aceptación
| Unidad | Alcance | Evidencia de salida |
|---|---|---|
| EVO-001 | Reconciliar #224; corregir resultado aplazado, restauración HERO y destino URL; política cero coste | CI nueva; casos dato/texto/foto aplazados; restaurar/rechazar conservan altas y bajas posteriores; spies prueban ausencia de llamadas facturables; recorte VPS real controlado |
| EVO-002 | Cola de auditoría y rúbrica sobre auditor actual | Recorrido >3000 productos y protegidos; dos workers; lease, reinicio, timeout e idempotencia; cobertura por versión |
| EVO-003 | Dirección, Catálogo y Productos con referencia visual definitiva y marca oficial | Datos canónicos, botones reales, filtros/paginación y permisos; estados vacíos/error |
| EVO-004 | Editor y contrato de cuatro fotos | Cuatro archivos distintos, fuentes, comparación, borrador/aprobación/restauración y bloqueo de afirmaciones críticas |
| EVO-005 | Actividad, decisiones, agentes, presupuesto | Ejecución enlazada a evidencia y responsable; agentes inactivos visibles como tales; coste desconocido bloquea |
| EVO-006 | Planificación nocturna, informe, metas y aprendizaje | Noche reanudable en America/Havana con recursos medidos, informe conciliado e historial; activación autónoma requiere cierre previo y aprobación |

Orden propuesto; no implica activación. Reutilizar planificador y cola operativa; un adaptador de contenido con funciones fijas, sin enviar auditorías del catálogo al worker que genera código.
No instalar servicios redundantes ni dependencias sin necesidad demostrada. Lotes y concurrencia se dimensionan midiendo el VPS de 4 GB/50 GB; no presuponer capacidad por los recursos del PC.

## Presupuesto inicial de 0 USD
Bloquear antes de la llamada toda generación, visión/QA, texto, búsqueda, audio y fallback facturable del Engine. Coste desconocido implica bloqueo. No basta con ocultar botones ni establecer una estimación a cero.
Procesamiento local y cuotas existentes solo tras verificar ausencia de cobro adicional. Fallar recorte gratuito nunca activa proveedor de pago. Respuestas de Dudas, rutas directas, reintentos y escalados están sujetos a la misma política.
Conservar capacidades existentes ajenas al Engine y no cambiar presupuestos de WhatsApp sin alcance específico. No realizar llamadas de prueba de pago para demostrar que se bloquean.

## Identidad y rendimiento
Helvetica, Helvetica Neue o Arial del sistema. Blanco, marfil y beige suave; verde y variantes únicamente de marca aprobada. No adoptar los hex de DESIGN.md como Brand Kit.
SVG lineales locales; conservar CSP self. Excluir Google Fonts, Material Symbols remotos, avatares, imágenes y logos remotos de Stitch y Tailwind CDN. No copiar sus scripts de simulación.
Navegación móvil apilada, controles táctiles legibles, carga paginada y fotografías optimizadas. No esconder overflow para maquillar una anchura incorrecta.

## Validación y reversión por unidad
- Mantener pruebas existentes y agregar regresiones concretas donde cambie lógica. Unitarias, integración WordPress/WooCommerce y navegador; CI debe corresponder al SHA candidato.
- Anchos 320, 360, 390 y escritorio; scrollWidth <= clientWidth; teclado y foco; sesión vencida, 401/403, nonce y conflicto 409; botones conectados y salida de editor. Redmi 9A físico sigue siendo una comprobación separada de emulación.
- Restaurar tras añadir/eliminar fotos y tras otra edición humana; cambios concurrentes bloquean aplicación obsoleta.
- Recorte VPS real: fuente controlada, proveedor y configuración comprobados, ninguna QA de pago, huella de entrada/salida, duración, recursos y coste conciliado; pruebas simuladas no lo certifican.
- Cada PR incluye checkpoint, archivos y evidencias; no fusionar ni desplegar hasta superar sus controles. Despliegue sigue necesitando aprobación expresa.
- Rollback: revertir PR de cada unidad, usar backup/manifest del despliegue vigente y detener el ejecutor afectado. Revertir contenido con sus versiones/recibos, nunca reemplazando toda una galería ni borrando cambios posteriores. Migraciones futuras requieren reversión compatible y respaldo.
- Esta unidad solo añade documentación: revertir sus commits retira el mapa/checkpoint sin tocar datos ni servicios.

## Siguiente acción
Cerrar EVO-001 en una rama independiente y contrastar el ZIP (2) con la referencia local antes de cambios visuales. No declarar integrado, gratuito ni operativo un componente por la existencia del diseño o de CI anterior.
