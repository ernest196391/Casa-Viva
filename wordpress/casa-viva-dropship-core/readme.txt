=== Casa Viva Dropship Core ===
Contributors: casaviva
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 2.1.1
License: Proprietary

Plugin base para convertir una tienda WooCommerce en una operación de dropshipping atendida por gestoras e influencers.

== Funciones incluidas ==

* Atribución permanente por primer referente.
* Referencia inicial protegida con cookie firmada.
* Respaldo de referencia en la sesión WooCommerce y en el carrito.
* Consolidación por cuenta, teléfono o correo.
* Clientes orgánicos sin comisión.
* Códigos de gestoras e influencers.
* Comisiones configurables por persona y producto.
* Estados pendiente, aprobada, cancelada y pagada.
* Pedido WooCommerce como fuente de verdad para atribución, clientes y comisiones.
* Panel frontal mediante [casa_viva_portal].
* Proveedor, precio mínimo, precio máximo y comisión en cada producto.
* Método de pedido que termina en WhatsApp.
* Checkout cubano con país fijo, provincias y municipios dependientes.
* Repartos sugeridos con escritura libre y punto de referencia.
* Elección entre mensajería y recogida en tienda.
* Compatibilidad con transferencia directa y confirmación por WhatsApp.
* Separación entre la persona que compra y la persona que recibe en Cuba.
* Captura opcional de coordenadas del lugar de entrega sin API de pago.
* Enlace navegable al mapa dentro del pedido y del vale de WhatsApp.
* Enlaces de productos incluidos en el vale para verificar fotos y detalles.
* Comprobante modular mediante WhatsApp Receipt Template, con una fuente de datos reutilizable.
* Enlaces breves y firmados para mapa y productos, sin URLs extensas en el comprobante.
* Modelo preparado para variaciones, cupones, descuentos, impuestos y múltiples monedas.
* Página estable de pedido recibido antes de abrir WhatsApp.
* Validación básica de que la ubicación compartida se encuentre dentro de Cuba.
* Registro sujeto a aprobación para gestoras y mensajeros.
* Área de gestoras con enlace permanente, clientes, pedidos y comisiones.
* Área de mensajeros con entregas asignadas y actualización de estados.
* Asignación de mensajero desde el pedido de WooCommerce.
* Páginas operativas creadas automáticamente al actualizar el plugin.
* Inventario con escáner, códigos QR, entradas, salidas y conteos auditables.
* Registro automático de rebajas y reposiciones originadas por pedidos WooCommerce.
* Informe diario de movimientos y productos con existencias bajas.
* Centro de ventas con QR de pedido, custodia del mensajero y confirmación del dinero recibido.
* Comisión fija o porcentual y aumento de gestora congelados por artículo al crear el pedido.
* Solicitudes de pago, destino bancario privado, comprobante, referencia e historial de liquidaciones.

== Instalación ==

1. Instala y activa WooCommerce.
2. Copia esta carpeta en wp-content/plugins/.
3. Activa Casa Viva Dropship Core.
4. Configura el WhatsApp central en WooCommerce > Casa Viva Dropship.
5. Crea usuarios con rol Gestora o Influencer y asigna código, WhatsApp y comisión.
6. El plugin crea automáticamente Registro de gestoras, Área de gestoras, Registro de mensajeros y Área de mensajeros.
7. Revisa cada solicitud en Usuarios y cambia su estado a Aprobada.
8. Asigna un mensajero desde la edición de cada pedido.

== Nota de migración ==

El catálogo se importa mediante los CSV verificados de Casa Viva. Cada producto conserva su ID y URL de BizneCubano como metadatos para futuras sincronizaciones sin duplicados.
