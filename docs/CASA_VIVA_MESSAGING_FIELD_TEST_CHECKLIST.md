# Checklist de prueba real desde teléfono

Usar únicamente datos sintéticos o cuentas piloto consentidas. Marcar evidencia y pedido utilizado.

## Preparación

- [ ] Staging usa el SHA candidato y no producción.
- [ ] Probar 360×740 y 390×844, Android/iOS disponibles.
- [ ] Roles separados: gestora A/B, dependienta, mensajero A/B, cliente y admin.
- [ ] Catálogo, stock, proveedores y tarifas son copias seguras de configuración.

## Vale y pedido

- [ ] Pegar vale con varios proveedores/productos, USD de producto y CUP de mensajería.
- [ ] Corregir cliente, teléfono principal/alternativo, municipio, zona, referencia, fecha/mañana-tarde y vuelto.
- [ ] Vincular cada línea con un producto del catálogo.
- [ ] Verificar que precio/tarifa usados son los de Casa Viva, no los del texto.
- [ ] Doble tap en Confirmar produce un solo pedido.
- [ ] Back/forward y refresh no duplican pedido.
- [ ] NEXO lento muestra loading sin congelar la vista.
- [ ] NEXO inaccesible muestra error y no crea pedido.
- [ ] Pedido sin teléfono/dirección/zona no se confirma.
- [ ] Zona sin tarifa queda “requiere cotización”, nunca precio inventado.

## Jornada del mensajero

- [ ] Mensajero solo ve pedidos asignados y aceptados por él.
- [ ] Gestora A no ve pedidos de gestora B.
- [ ] Contactos: Llamar y WhatsApp abren el destino correcto.
- [ ] Registrar Confirmó, No responde, Reprogramar y Ubicación recibida; revisar auditoría.
- [ ] Dependienta prepara/verifica; mensajero solo recibe/carga cuando corresponde.
- [ ] Manifiesto consolida cantidades y separa proveedores/puntos de recogida.
- [ ] Vuelto y notas reales son visibles antes de salir.
- [ ] Ordenar ruta manualmente, refrescar y comprobar el comportamiento de sesión documentado.
- [ ] Sin teléfono: degradación explícita, sin botón roto.
- [ ] Sin mapa: dirección copiable y sin navegación falsa.
- [ ] Abrir mapa real y volver a la app sin perder el pedido activo.
- [ ] Abrir/resolver incidencia y comprobar auditoría.
- [ ] Entregar y registrar medio e importes reales USD/CUP; doble submit no duplica transición.
- [ ] Staff confirma devolución/verificación y cierre; revisar conciliación.
- [ ] No ejecutar caso de pagador dividido cliente/gestora hasta aprobar su contrato estructurado.

## Resiliencia y cierre

- [ ] Simular red lenta/offline y recuperar con refresh.
- [ ] Cambio operativo posterior a carga aparece mediante refresh/notificación existente.
- [ ] Seguimiento cliente muestra solo su pedido y no GPS exacto permanente.
- [ ] No aparece ETA cuando no existe fuente real.
- [ ] Revisar timeline, actores, importes, inventario y comisión del pedido.
- [ ] Exportar evidencia sin PII: IDs internos, timestamps y capturas redactadas.
