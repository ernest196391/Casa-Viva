# Messenger MVP v1

## Objetivo

Convertir el prototipo operativo de mensajería en una primera interfaz mobile-first dentro de Casa Viva, reutilizando el mismo concepto de pedido y sin crear una segunda máquina de estados.

## Actor

Mensajero asignado a una entrega.

## Alcance de esta primera iteración

- Ruta web `/mensajero` optimizada para móvil.
- Una entrega activa como prioridad visual.
- Pedido de demostración anonimizado; no contiene datos reales de clientes.
- Resumen visible de productos, mensajería, importe a cobrar y vuelto.
- Una acción logística principal por etapa.
- Secuencia visual: `ACEPTAR PEDIDO` → `VOY A RECOGER` → `PEDIDO RECOGIDO` → `EN CAMINO AL CLIENTE` → `ENTREGADO`.
- Accesos rápidos a WhatsApp, llamada y navegación después de aceptar el pedido.
- Incidencia como acción secundaria, sin sacar el pedido del flujo.

## Fuera de alcance en v1

- Escritura en WooCommerce o WordPress.
- Autenticación y permisos reales.
- Persistencia de transiciones.
- Geolocalización en vivo.
- Contabilidad o liquidación automática.
- Uso de datos personales reales.

## Criterios de aceptación

1. El mensajero entiende de inmediato cuál es la entrega activa.
2. Solo existe una acción logística principal dominante.
3. El importe a cobrar y el vuelto se ven antes de salir.
4. WhatsApp, llamada y navegación aparecen al aceptar la entrega.
5. La secuencia visual sigue el modelo canónico ya definido en Casa Viva.
6. La incidencia permanece secundaria.
7. La interfaz funciona sin dependencias nuevas y está pensada para conexión lenta y pantalla móvil.

## Siguiente integración

Después de validar visualmente y en móvil, sustituir el pedido demo por una lectura del pedido asignado desde los servicios existentes. Las acciones deberán llamar a las transiciones ya implementadas y generar historial; no se debe almacenar un estado paralelo en esta pantalla.

## Design Foundation Stitch

La base visual aprobada usa los tokens Casa Viva/Stitch petróleo, carbón, ámbar, crema y blanco. La ruta `/mensajero` incluye componentes reutilizables para alertas, badges, desglose monetario, acciones rápidas, navegación inferior y acceso visual a NEXO.

Esta iteración es exclusivamente de presentación:

- el pedido continúa anonimizado y marcado como demostración;
- las transiciones visibles continúan siendo una simulación local temporal;
- la navegación hacia Contactos, Preparar y Ruta anuncia pantallas posteriores, sin inventar funcionalidad;
- el acceso a NEXO permanece deshabilitado hasta implementar el Copilot read-only;
- no existe escritura en WooCommerce, WordPress ni servicios canónicos.
