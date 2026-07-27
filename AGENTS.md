# Reglas de trabajo para Casa Viva Commerce OS

Estas instrucciones se aplican a todo el repositorio.

## Contexto

Casa Viva es el primer caso real de una plataforma reutilizable para otras tiendas. El objetivo ya no es solo una tienda Next.js: el proyecto incluye WordPress, WooCommerce, un tema visual reutilizable y un plugin operativo modular.

## Antes de modificar código

1. Lee `docs/README.md`.
2. Lee únicamente los documentos del módulo solicitado.
3. Revisa el código existente antes de modificarlo.
4. Trabaja una sola tarea funcional por vez.
5. Si detectas contradicciones entre código y documentación, detente y enuméralas antes de programar.

## Reglas obligatorias

- No cambies reglas de negocio cerradas sin aprobación.
- No publiques en producción ni fusiones ramas sin aprobación.
- Mantén separado el tema visual del plugin operativo.
- Toda acción sobre inventario, dinero, atribución, pedidos o permisos debe auditarse.
- No almacenes secretos, contraseñas, tokens ni claves API.
- No hardcodees precios, tasas, porcentajes, WhatsApp, monedas ni reglas configurables.
- No elimines historial comercial; archiva y audita.
- No inventes resultados de pruebas.
- Usa staging, pruebas y rollback antes de producción.

## Calidad

- Mobile-first y optimizado para conexiones lentas.
- Componentes pequeños y reutilizables.
- Operaciones idempotentes para evitar duplicados.
- Validación, sanitización, autorización por rol y logs.
- Ejecuta las pruebas disponibles antes de dar una tarea por terminada.

## Orden de desarrollo

Auditoría -> núcleo -> centro operativo -> gestora -> dependienta -> mensajería -> proveedores -> tienda pública -> economía -> IA -> empaquetado comercial.