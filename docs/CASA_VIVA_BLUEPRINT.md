# Blueprint funcional de Casa Viva

## Autoridad del documento

Este documento es la referencia funcional permanente del proyecto Casa Viva. Antes de cualquier cambio funcional en pedidos, mensajería, gestoras, comisiones, pagos, cliente o administración debe consultarse este Blueprint.

No sustituye la inspección del código existente. Cuando exista una diferencia entre una propuesta futura y la implementación real, primero se audita, se documenta y se solicita aprobación antes de cambiar comportamiento.

## Objetivo

Evolucionar el sistema actual hacia una aplicación de ecommerce, gestión de pedidos y última milla, conservando lo que ya funciona y mejorándolo progresivamente.

Referencias de experiencia:

- Shop/Shopify: navegación móvil, carrito, pedidos e historial.
- Uber Eats: estados, seguimiento y comunicación del progreso.
- DoorDash: experiencia operativa del mensajero.
- Casa Viva actual: gestoras, dependientas, comisiones, tienda, administración y cobros.

No se copiará código ni diseño propietario y no se reconstruirá Casa Viva desde cero. Se adoptarán patrones compatibles con WordPress y WooCommerce.

## Regla cero: auditar antes de programar

Antes de implementar una mejora:

1. Inspeccionar el código actual.
2. Identificar componentes, páginas, APIs, datos, estados y servicios relacionados.
3. Determinar qué funcionalidad ya existe.
4. No duplicar funcionalidad existente.
5. No sustituir componentes funcionales sin una razón técnica demostrable.
6. Conservar compatibilidad con pedidos existentes.

Clasificación obligatoria de cada área:

- ✅ IMPLEMENTADO
- 🟡 PARCIALMENTE IMPLEMENTADO
- ❌ NO IMPLEMENTADO
- 🔴 IMPLEMENTADO PERO CON ERROR
- ⚠️ DEPENDENCIA CRÍTICA / NO MODIFICAR SIN REVISIÓN

## Principio central: el pedido

El pedido es la unidad central de Casa Viva. Cliente, gestora, dependienta, mensajero y administrador consultan diferentes vistas del mismo pedido y de los mismos datos almacenados en backend.

WooCommerce continúa siendo la fuente oficial del pedido. Las dimensiones operativa, logística, de incidencia, efectivo y comisión deben mantenerse separadas y coherentes; no deben convertirse en estados paralelos contradictorios.

La Fase 1A incorpora solamente un lector canónico calculado en lectura. No escribe un estado canónico ni corrige datos automáticamente.

## Navegación móvil del cliente

Implementar progresivamente una barra inferior persistente:

- Inicio
- Categorías
- Carrito
- Pedidos
- Cuenta

El carrito ocupa la posición central. Su badge indica el total de unidades que todavía no se han comprado. Una vez realizada la compra, el pedido pasa a Pedidos. El badge de Pedidos puede indicar pedidos activos.

No romper ni recrear la lógica existente de WooCommerce.

## Carrito

El contador debe actualizarse inmediatamente al:

- añadir un producto;
- aumentar o disminuir cantidad;
- eliminar un producto.

Al pulsar Carrito se abre el carrito actual de WooCommerce.

## Pedidos y centro único del pedido

Pedidos debe separar activos y terminados y mostrar estado, fecha, total y modalidad de entrega. Un pedido activo abre una pantalla única.

La pantalla puede incluir, según rol y permisos:

- productos, cantidades, fotografías y precios;
- gestora, dependienta y mensajero;
- dirección, ubicación e instrucciones;
- precio de mensajería y método de pago;
- importe pendiente;
- teléfono y WhatsApp;
- historial e incidencias.

No se mostrará información interna sensible al cliente. Cada rol recibe una representación adecuada del mismo pedido.

## Modelo canónico propuesto

El modelo objetivo sigue siendo:

```text
CREATED
→ CONFIRMED
→ PREPARING
→ READY_FOR_COURIER
→ COURIER_ASSIGNED
→ COURIER_GOING_TO_PICKUP
→ PICKED_UP
→ ON_THE_WAY_TO_CUSTOMER
→ DELIVERED
→ PAYMENT_RECONCILED
→ COMPLETED
```

Para recogida en tienda se utiliza `READY_FOR_PICKUP` cuando corresponde.

Estados terminales o alternativos:

- `CANCELLED`
- `DELIVERY_FAILED`
- `CONFLICT`

Una incidencia activa es una dimensión separada; no sustituye conceptualmente la etapa normal.

### Equivalencias demostradas por la Fase 1A

Estas equivalencias corrigen interpretaciones que el nombre por sí solo podía inducir:

- `delivery=picked_up`: la dependienta transfirió la custodia física al mensajero → `PICKED_UP`.
- `delivery=handed_over`: el mensajero salió hacia el cliente → `ON_THE_WAY_TO_CUSTOMER`.
- `delivery=delivered`: el cliente recibió el pedido, pero puede faltar reconciliación → `DELIVERED`.
- `delivery=cash_returned`: el efectivo regresó a Casa Viva, antes del cierre definitivo → `PAYMENT_RECONCILED`.
- `delivery=closed`: cierre definitivo de la entrega → `COMPLETED`.
- `operation=with_courier`: existe custodia del mensajero; como mínimo → `PICKED_UP`.
- `operation=delivered`: el flujo operativo registra dinero recibido y operación terminada → `COMPLETED`, siempre sujeto a coherencia con WooCommerce y mensajería.
- `incident`: marca una incidencia activa y debe recuperar la etapa anterior solamente cuando el historial la demuestra.

No se crearán estados duplicados con nombres diferentes si representan lo mismo. Las transiciones existentes no se modificarán sin una fase aprobada.

## Actualización y propagación

Cuando una fase futura autorice cambios de estado, cada cambio deberá:

1. Guardarse en backend.
2. Registrar el evento.
3. Actualizar las interfaces afectadas.
4. Actualizar el seguimiento.
5. Generar la notificación correspondiente.
6. Recalcular datos relacionados solamente cuando proceda.

Debe elegirse la solución menos disruptiva compatible con la arquitectura existente. El usuario no debería tener que refrescar manualmente.

## Historial de eventos

Cada transición debe conservar:

- pedido;
- estado anterior y nuevo;
- usuario y rol;
- fecha y hora;
- información adicional relevante.

El historial no debe desaparecer al avanzar el pedido. Si un historial actual está ausente o truncado, ningún lector puede inventar una etapa anterior: debe devolver `WARNING` o `CONFLICT`.

## Notificaciones

No se permiten avisos vagos como “Tienes una nueva actividad pendiente”. Deben explicar qué pasó y abrir el pedido relacionado cuando sea posible.

Ejemplos aprobados:

- “Pedido #459 confirmado.”
- “Pedido #459 listo para mensajería.”
- “Daiquel va camino a recoger el pedido #459.”
- “Daiquel recogió el pedido #459.”
- “Pedido #459 va camino al cliente.”
- “Pedido #459 entregado.”

## Experiencia del mensajero

El dashboard del mensajero no debe parecer una tienda. Debe concentrarse en:

- Pedidos nuevos.
- Entregas activas.
- Finalizados hoy.
- Dinero pendiente de liquidar.

Una pantalla debe tener una acción principal dominante.

Flujo funcional objetivo:

1. Disponible o asignado → `ACEPTAR PEDIDO`.
2. Aceptado → `VOY A RECOGER`.
3. Camino a tienda → mostrar tienda, ubicación, navegación y teléfono.
4. La dependienta entrega físicamente el pedido → custodia transferida (`picked_up`).
5. El mensajero inicia ruta al cliente → `EN CAMINO AL CLIENTE` (`handed_over` actual).
6. En ruta → `ENTREGADO`.
7. Entregado → registrar hora, mensajero, resultado e importe cuando corresponda.
8. Liquidación → registrar el dinero que debe regresar a Casa Viva.
9. Cierre → administración confirma el cierre definitivo.
10. Incidencia → acción secundaria que nunca elimina el pedido de las vistas de trabajo.

Incidencias previstas: cliente no responde, dirección incorrecta, rechazo, problema de pago, avería/transporte, producto dañado u otro.

## WhatsApp, teléfono y ubicación

Cuando exista un teléfono válido, WhatsApp abre la conversación y Llamar abre el marcador. No se exponen teléfonos innecesariamente a otros roles.

La ubicación proporcionada durante checkout debe conservarse en el pedido. Se priorizan deep links a aplicaciones de mapas instaladas antes de desarrollar navegación propia.

## Diez principios obligatorios

1. No reconstruir funcionalidad existente que pueda extenderse.
2. Una pantalla, una acción principal dominante.
3. Un pedido, una fuente de verdad.
4. Todos los roles consultan el mismo estado.
5. Los cambios importantes se propagan automáticamente.
6. Toda transición genera historial.
7. Toda notificación es descriptiva.
8. Nunca borrar el contexto anterior del pedido.
9. Cada rol ve solamente lo necesario.
10. Nada está terminado hasta probar el flujo completo.

## Protocolo de implementación

Trabajar en bloques pequeños. Antes de cada bloque:

1. Consultar este Blueprint.
2. Inspeccionar la implementación actual.
3. Indicar qué existe y qué falta.
4. Identificar riesgos.
5. Proponer la modificación mínima.

Después:

6. Implementar.
7. Ejecutar pruebas.
8. Corregir errores.
9. Repetir pruebas.
10. Comprobar regresiones.
11. Actualizar documentación y matriz.

No continuar automáticamente si una fase presenta errores.

## Matriz permanente

Cada requisito debe conservar una marca:

- ✅ terminado y probado
- 🟡 parcial
- ❌ pendiente
- 🔴 defectuoso
- ⚠️ bloqueado o dependencia

## Prueba end-to-end obligatoria

Antes de declarar estable un módulo se prueba:

```text
CLIENTE crea pedido
→ GESTORA recibe atribución y comisión correctas
→ DEPENDIENTA recibe, prepara, marca listo y entrega al mensajero
→ MENSAJERO recibe, acepta, va a recoger, recoge, inicia ruta, entrega y registra cobro
→ CLIENTE observa los estados correctos
→ ADMINISTRACIÓN observa el proceso completo
→ CONTABILIDAD registra importes, comisiones y dinero correctamente
```

Si una etapa falla, el flujo no está terminado.

## Restricciones vigentes de Fase 1A / 1A.1

- No modificar transiciones ni estados WooCommerce.
- No escribir el estado canónico en base de datos.
- No migrar pedidos históricos.
- No modificar pantallas.
- No modificar comisiones, ledger, pagos o liquidaciones.
- No eliminar ni renombrar metadatos.
- No corregir contradicciones automáticamente.
- Una contradicción no resoluble se devuelve como `CONFLICT`.
- El lector y detector continúan siendo exclusivamente de lectura y reversibles eliminando el servicio y su carga.
