# Casa Viva — Entrega a la dueña (Lennys)

Guía corta para el día a día. Estado a 6 de octubre de 2026.

## Lo que tiene Casa Viva

| Pieza | Dónde | Para qué |
|---|---|---|
| Tienda web | https://casaviva.company | Clientes compran desde el móvil; ofertas reales arriba, catálogo completo en Tienda. |
| Panel de la tienda | https://casaviva.company/wp-admin | Pedidos, productos, gestoras, Curru. |
| Curru | Burbuja con su foto en la web | Responde dudas de productos, precios, mensajería y recogida. A clientes nunca les da cantidades; a gestoras sí. |
| VivaBot | WhatsApp 53 5405 6173 | Contesta a clientes y gestoras por WhatsApp con la misma información que Curru. Lo delicado se lo pasa a una persona. |
| Catálogo | BizneCubano (casaviva.biznecubano.com) | Fuente de productos, precios y existencias; la tienda se sincroniza desde ahí. |

## Lo que pasa solo

- **Ventas al por mayor:** VivaBot manda al cliente un enlace a tu WhatsApp (53 5688 5368) con su pedido ya escrito. Solo tienes que acordar el precio.
- **Cambios, devoluciones y garantía:** por ahora los atiende una persona, caso a caso. El bot y Curru piden el número de pedido y una foto.
- **Recogida:** Calle Conill A esquina 45 #864, Nuevo Vedado, de 9:00 a 17:00.

## Lo que haces tú

1. **Pedidos:** wp-admin › WooCommerce › Pedidos. Cada pedido se confirma con la clienta por WhatsApp.
2. **Gestoras nuevas:** se registran solas; tú apruebas cada cuenta.
3. **Productos y precios:** cámbialos en BizneCubano. La tienda los toma de ahí (la sincronización la lanza Ernesto o el equipo técnico).
4. **Fotos:** si un producto tiene una foto mala, sube una real en BizneCubano o en wp-admin › Productos.

## Antes de dar la entrega por cerrada

- [ ] Prueba de VivaBot desde otro teléfono: toallas, mensajería a Playa, recogida y al por mayor.
- [ ] Cambiar las claves que se vieron en capturas (Supabase, Anthropic, WooCommerce, Groq y la clave de Curru para VivaBot).
- [ ] Desplegar 3.13.10 y 3.13.11 (scroll horizontal en móvil, cabecera, ventana de ofertas) y repetir la auditoría móvil.
- [ ] Política escrita de cambios y garantía (cuando la haya).
