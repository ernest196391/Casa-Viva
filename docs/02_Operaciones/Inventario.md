# Inventario

Conceptos:
- Stock físico.
- Stock comprometido.
- Stock disponible.
- Stock dañado.
- Stock en tránsito.
- Stock con proveedor.

Fórmula: disponible = físico - comprometido - dañado.

## Reglas
- Añadir al carrito no compromete inventario.
- Confirmar pedido compromete inventario.
- No existe vencimiento automático.
- Solo cancelación manual, entrega, devolución o ajuste autorizado cambia el compromiso.
- Toda venta física descuenta inventario.
- Toda modificación genera movimiento.
- Ajustes requieren motivo y usuario.
- Productos externos no entran al stock físico hasta su recepción.

## Códigos
Cada variante tendrá SKU, QR, código de barras y ubicación. Cada pedido tendrá código y QR propio.