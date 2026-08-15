# Reglas de trabajo para Casa Viva

Estas instrucciones se aplican a todo el repositorio.

Antes de cualquier cambio funcional en pedidos, mensajería, gestoras, comisiones, pagos, cliente o administración, consultar `docs/CASA_VIVA_BLUEPRINT.md`.

## Forma de trabajo

- Trabaja una sola tarea funcional por vez.
- Revisa el código existente antes de modificarlo.
- Convierte cada petición informal en requisitos verificables antes de programar: objetivo, actor, flujo, estados, permisos y criterio de aceptación.
- Resuelve cada tarea en este orden: lógica de producto, arquitectura y seguridad, experiencia móvil, diseño visual, copy y pruebas.
- Reutiliza las decisiones ya validadas y consulta los errores anteriores para no reintroducir duplicaciones, accesos ambiguos, textos inflados ni soluciones parciales.
- Trata el copy como parte del producto: elimina redundancias, usa frases breves y conserva texto adicional solo cuando ayude a decidir o evite un error.
- Cuando una corrección revele el mismo problema evidente en componentes equivalentes, aplícala también dentro del mismo alcance y documéntala en la entrega.
- No consideres terminada una función solo porque exista: revisa coherencia visual, estados vacíos, acceso móvil, permisos y navegación de salida.
- No implementes funciones no solicitadas.
- Explica brevemente los cambios realizados, los archivos modificados y las pruebas ejecutadas.
- No elimines ni reestructures partes importantes sin explicar primero la razón.

## Base técnica

- Mantén Next.js con App Router, TypeScript y Tailwind CSS.
- Prioriza componentes pequeños, reutilizables y bien organizados.
- No instales dependencias nuevas sin justificar su necesidad.
- Ejecuta `npm run lint`, `npm run typecheck` y `npm run build` después de cambios funcionales cuando el entorno lo permita.
- No inventes resultados de pruebas que no pudieron ejecutarse.

## Alcance actual

- La primera versión es una tienda propia de Casa Viva, no un marketplace multivendedor.
- No agregues base de datos, autenticación, pagos, inventario, tracking, gestores, comisiones, integraciones externas o despliegues sin una tarea específica.
- Usa datos de demostración claramente identificados mientras no exista una base de datos real.

## Reglas comerciales y datos

- No fijes directamente en el código precios, tasas de cambio, comisiones, horarios, tarifas o reglas comerciales que deban ser configurables.
- No incluyas secretos, contraseñas, tokens ni credenciales en el repositorio.

## Experiencia y diseño

- Usa español para los textos visibles al cliente y para la documentación principal.
- Prioriza diseño mobile-first, responsive, accesible y navegable con teclado.
- Optimiza para conexiones lentas y dispositivos de recursos limitados.
- Mantén la identidad de Casa Viva: minimalista, cálida, beige y verde oscuro, con estética de lujo silencioso accesible.
