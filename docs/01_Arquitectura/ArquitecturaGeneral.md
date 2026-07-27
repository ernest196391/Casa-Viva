# Arquitectura general

## Capas

### WordPress y WooCommerce
- Catálogo público.
- Categorías y variantes.
- Carrito y checkout.
- Cuenta del cliente.
- Pedido comercial base.

### Tema reutilizable
- Identidad visual.
- Portada, categorías, producto, carrito y checkout.
- Diseño mobile-first.
- Presets configurables para otras tiendas.

### Plugin operativo
- Organizaciones.
- Roles y permisos.
- Atribución permanente.
- Gestoras e influencers.
- Proveedores.
- Estados operativos.
- Inventario y movimientos.
- Preparación y escaneo.
- Mensajería.
- Pagos, ganancias y liquidaciones.
- Incidencias, auditoría e IA.

## Reglas técnicas

- No guardar todo como `post_meta`; usar tablas propias para operaciones de alto volumen.
- Conservar snapshots de precio y costo en cada venta.
- Toda acción sensible debe autorizarse y auditarse.
- Las operaciones deben ser idempotentes.
- No exponer secretos.
- Migraciones versionadas y rollback.
- Instalación independiente por tienda en la primera estrategia comercial.