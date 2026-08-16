# Fase 3A — Navegación móvil del cliente

## Objetivo

Crear la base de navegación móvil persistente del cliente sin sustituir WooCommerce ni introducir estados o fuentes de datos paralelas.

## Alcance

- Barra inferior mobile-first con Inicio, Categorías, Carrito, Pedidos y Cuenta.
- Carrito como acción central destacada.
- Badge del carrito conectado al carrito real de WooCommerce.
- Actualización del badge al añadir, eliminar o modificar cantidades.
- Pedidos reutiliza el endpoint de pedidos de WooCommerce para clientes autenticados y dirige a Mi cuenta para visitantes.
- Exclusión explícita de gestoras, mensajeros, dependientas, operadores y administración.
- La navegación no se muestra durante checkout.

## Fuera de alcance

- Rediseño del listado de pedidos.
- Seguimiento visual del pedido.
- Badge de pedidos activos.
- Cambios de checkout.
- Nuevos estados de pedido.
- Cambios de comisiones, mensajería o contabilidad.

## Criterios de aceptación

1. En móvil el cliente ve las cinco entradas sin desbordamiento horizontal.
2. Carrito ocupa la posición central y muestra el número real de unidades.
3. El contador responde a altas, bajas y cambios de cantidad.
4. La navegación no aparece en superficies internas de Casa Viva.
5. Se conservan las URLs y la lógica oficial de WooCommerce.
6. Contrato estático, integración existente y prueba Playwright deben quedar verdes antes de integrar.
