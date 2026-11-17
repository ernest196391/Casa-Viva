# Flujos críticos

Cada paso indica pantalla (número del inventario), quién actúa y el estado que queda.

## 1. Recibir, preparar y entregar un pedido

1. Entra el pedido (web, VivaBot o gestora) → Pedidos (8) en `pendiente`; notificación a Dependienta y Manager.
2. Dependienta abre Detalle de pedido (9), confirma stock y pago (`Money` en su moneda) → «Preparar» → `ejecutando` · Pedidos en preparación (76).
3. Marca «Listo» → Pedidos pendientes de entrega (75).
4. Manager asigna en hoja modal → Asignación de mensajeros (77), sugerido por cercanía y carga.
5. Mensajero ve la entrega en su Inicio, abre Mapa de rutas (79), «Recogido» → «Entregado» con foto o firma y cobro → `verificado`.
6. Liquidación de mensajeros (81) suma la tarifa (80); el cliente recibe confirmación por WhatsApp.
7. Excepción: no entregado → Incidencias de entrega (82) → `bloqueado` + `DecisionCard` al Manager (reintentar, devolver, cancelar).

## 2. Corregir una incidencia de VivaBot

1. VivaBot escala o un cliente se queja → Incidencias comerciales (34) en `bloqueado`; `DecisionCard` en Inicio de Lennys.
2. Abrir Detalle de conversación (31) con transcripción (33) y la respuesta errónea resaltada.
3. Elegir: responder ahora (Respuestas sugeridas por IA, 35, en `accent-soft`) y/o corregir el conocimiento.
4. «Corregir el bot» abre `ActionProposal`: regla o dato que cambia (antes → después) y conversaciones afectadas.
5. Confirmar → Configuración y conocimiento de VivaBot (38) se actualiza → prueba automática con la misma pregunta → `verificado` con la respuesta nueva como evidencia.

## 3. Encontrar una oportunidad y convertirla en producto

1. Radar de ofertas en WhatsApp (41) detecta una oferta → Detalle de oportunidad (43) con precio, proveedor y margen estimado.
2. Comparador de precios (42) e Historial (44) → «Es buena» → Productos candidatos (46).
3. Compradora crea Solicitud de compra (45) → `DecisionCard` a Lennys con importe y margen → `aprobado`.
4. Al recibir: alta en Catálogo (11/13) e Inventario (14) → entra en la Cola de Content Studio (49).
5. Content Studio genera fotos y descripción (flujo 4) → publicado en web y ofrecido a gestoras.

## 4. Crear, aprobar y publicar contenido

1. Producto en la Cola de productos pendientes (49).
2. **Cleanfy:** Editor de fotografías (50) limpia fondo y luz → Comparador antes/después (51).
3. **Lifestyle:** Generador de imágenes lifestyle (52) coloca el producto en un hogar habanero.
4. **Hero Offer:** Generador de ofertas (53) + Descripciones comerciales (54).
5. Aprobaciones de contenido (57): se aprueba pieza a pieza (`aprobado`) o se pide ajuste con un comentario al asistente.
6. Calendario de publicaciones (55) → publicado → Biblioteca multimedia (56); cada versión queda en Historial (58) y se puede restaurar.

## 5. Modificar una regla comercial con advertencia y aprobación

1. Desde Reglas comerciales (124) o pidiéndolo al asistente («Sube el margen de los ventiladores al 30 %»).
2. Resumen de la petición (116) → `ActionProposal` (117) con cambios antes → después y productos afectados.
3. Advertencias de impacto (118): `medio` (pedidos abiertos, precios de gestoras) y `alto` (márgenes, cobros).
4. Impacto alto → Confirmación de cambios (119) por Lennys (aprobación con su usuario; si lo pidió otro rol, le llega como `DecisionCard`).
5. Seguimiento (120): la regla pasa a `ejecutando` → `verificado`, con el listado de precios cambiados y opción de revertir durante 24 h.
