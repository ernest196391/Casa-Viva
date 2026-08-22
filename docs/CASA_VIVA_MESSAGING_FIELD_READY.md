# Casa Viva Mensajería — auditoría field-ready

Fecha de corte: 2026-08-22. Superficie operativa: `/area-mensajeros/`. `/mensajero` continúa siendo únicamente sandbox Stitch.

| Capacidad | Ya funciona | Gap | Bloqueador MVP | Acción |
|---|---|---|---|---|
| Vale de texto | NEXO interpreta sin persistir | OCR/foto/PDF | No | NEXT desacoplado |
| Revisión humana | Faltantes, warnings, confidence y corrección móvil | Ninguno crítico | No | Mantener contrato |
| Crear pedido | WooCommerce canónico, catálogo real, idempotencia y auditoría | No admite línea sin producto de catálogo | No; es una protección | Selección humana obligatoria |
| Ownership | Cliente permanente prevalece; gestora solo la propia | Ninguno | No | Mantener |
| Contactar | Llamar, WhatsApp y cuatro eventos `contact.*` auditables | Sin WhatsApp API | No | Enlaces nativos |
| Preparar/cargar | Manifiesto real y transiciones por rol | Cambio post-carga no tiene diff granular | No | Refresh/push existente; evento granular NEXT |
| Mi ruta | Solo asignados, orden manual de sesión, mapa/teléfono | Sin route-suggest | No | Mantener manual |
| Entrega/incidencia | Transiciones e incidencias canónicas | Sin ETA predictiva | No | No inventar ETA |
| Cobro/cierre | Importes reales USD/CUP, medio, actor, fecha y reconciliación | No desglosa un cobro entre cliente y gestora | **Sí para jornadas con pagador dividido** | Resolver política y contrato de conciliación antes de pilotar ese caso |
| Tarifa | Cotizador móvil y snapshot oficial Casa Viva | Zonas sin tarifa quedan por confirmar | No | Cotización manual explícita |
| Seguimiento cliente | Pedido, progreso y ayuda con privacidad | Sin ETA si no existe fuente | No | Mantener |
| NEXO caído | Error explícito; no crea pedido | Requiere reintento/manual | No | Checklist de degradación |

## Autoridades preservadas

- WooCommerce/Casa Viva posee pedido, estados, roles, catálogo, inventario, tarifa, cobro y eventos.
- NEXO recibe texto, devuelve una propuesta y no persiste ni crea pedidos.
- Los precios interpretados nunca sustituyen el catálogo ni la tabla de tarifas.
- El navegador no recibe claves de proveedores de IA.

## Release y rollback

1. Crear release versionado del plugin desde el SHA aprobado.
2. Backup de base de datos y directorio del plugin.
3. Instalar primero en staging con la misma versión de WordPress/WooCommerce/PHP.
4. Ejecutar el checklist de campo completo con cuentas piloto.
5. Validar que `cvd_nexo_service_url` apunta por HTTPS al servicio aprobado.
6. Desplegar en ventana controlada; no activar optimización de ruta ni ETA.
7. Smoke de `/interpretar-vale/`, `/cotizar-mensajeria/`, `/area-mensajeros/` y `/seguimiento/`.

Rollback: restaurar el directorio de la versión anterior y ejecutar su activación. No borrar pedidos creados: son pedidos WooCommerce válidos. Las tablas/eventos son aditivos; no requieren downgrade destructivo.

## NEXT no bloqueante

- OCR de foto/PDF.
- `route-suggest` como sugerencia confirmable.
- Diff/evento granular para cambios posteriores a carga.
- ETA solo con fuente verificable.
- Copilot read-only.
