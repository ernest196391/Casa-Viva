# Roles

Cada persona ve exclusivamente lo que le corresponde. Los módulos ocultos no aparecen ni en la barra inferior, ni en la lateral, ni en las respuestas del asistente.

| Rol | Inicio muestra primero | Pestañas | Módulos |
| --- | --- | --- | --- |
| Lennys · Propietaria | Aprobaciones comerciales y financieras, ganancia real de hoy, flujo de caja | Inicio · Negocio · Operaciones · IA · Más | A–M completo; Evolution Engine solo lectura + aprobación de despliegues |
| Ernesto · Dirección | Despliegues y PR por aprobar, estado de agentes y VPS, crecimiento | Inicio · Negocio · Operaciones · IA · Más | A–M completo; edición total en J y K |
| Manager | Pedidos atrasados, incidencias, tareas del equipo | Inicio · Negocio · Operaciones · Más | A, B, D, E (sin precios de coste), F, G, H (sin comisiones ajenas), I |
| Dependienta | Pedidos de hoy por preparar, clientes en espera | Inicio · Pedidos · Clientes · Más | B (pedidos, nuevo pedido, catálogo e inventario de lectura), D (conversaciones asignadas) |
| Compradora | Solicitudes de compra, ofertas del radar | Inicio · Proveedores · Catálogo · Más | E completo, B (inventario, agotados, candidatos) |
| Mensajero | Próxima entrega con dirección y cobro | Entregas · Ruta · Liquidación · Más | I (solo sus entregas, ruta, liquidación propia, incidencias) |
| Gestora | Sus ventas y comisión del mes, pedidos de sus clientes | Inicio · Catálogo · Mis ventas · Más | B (catálogo y nuevo pedido), H (su perfil, ventas y comisiones), G (ofertas para compartir) |

## Reglas

- Los costes, márgenes, ganancia real y comisiones ajenas solo los ven Lennys y Ernesto.
- Los roles operativos (Mensajero, Dependienta) tienen una pantalla de inicio de tarea, no un dashboard: la siguiente acción ocupa el primer pliegue con un `Button` `block`.
- El asistente responde dentro de los permisos del rol que pregunta y lo dice cuando no puede: «Eso lo aprueba Lennys. ¿Se lo envío?».
- Las aprobaciones de impacto alto van al rol responsable: dinero y reglas comerciales → Lennys; tecnología y despliegues → Ernesto.
