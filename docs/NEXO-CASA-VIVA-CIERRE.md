# Cierre Casa Viva — tienda web

Fecha: 2026-10-10 (America/Havana). Base main: eecbb922b5c4f2ba2876b95319a48b1e085b959a (3.13.27).

## Resultado de esta ejecución

- APROBADO: 31 contratos JavaScript locales en artifacts/tests. Antes: 30/31; el contrato de historial truncado no encontraba el método PHP en Windows porque esperaba LF y Git usa CRLF. Se normalizan saltos de línea al leer, conservando todas las aserciones.
- APROBADO parcial público: inicio, categoría Baño (42 resultados), búsqueda toalla (6 resultados), ficha Toallas pequeña (Woo 684), carrito y llegada al checkout. Un intento de 4 unidades en carrito se limita a 3 y deshabilita aumentar. Checkout muestra 3 × USD 10 = USD 30. Es evidencia del límite en UI; no sustituye la prueba transaccional del servidor.
- APROBADO formulario: recogida oculta dirección y campos de mensajería; resumen muestra No aplica. Transferencia cambia el botón a Crear pedido y ver transferencia. No se enviaron datos personales, pedido, pago ni WhatsApp.
- El carrito tarda en hidratar: primera observación muestra placeholders; posteriormente productos y totales correctos. Se eliminó el producto al finalizar.
- BLOQUEADO: confirmación del pedido, notificación, cumplimiento, gestora autenticada y comparación con inventario real necesitan staging aislado o autorización específica. No se certifica checkout integral por haber cargado el formulario.
- No hay PHP ni Docker disponibles por PATH en esta PC; integración WordPress/MariaDB no ejecutada localmente. Los contratos estáticos no equivalen a integración.

## Evidencia y continuidad

Informe detallado de ejecución: NEXO/evidence/web-bot.md; resultados de los 31 contratos: NEXO/evidence/casa-viva-contract-tests.json. Prueba concreta: node artifacts/tests/test-canonical-order-reader-history-truncation-1a.mjs.

No se desplegó a producción. Su SHA exacto no se deduce de main ni de los recursos públicos. Deploy prototype es workflow_dispatch manual; esta rama solo modifica prueba y documentación.

Siguiente acción: revisar y fusionar corrección de portabilidad, ejecutar CI integración/browser y preparar entorno de prueba para pedido→notificación→cumplimiento. Mantener prohibición de pedidos reales sin aprobación.
