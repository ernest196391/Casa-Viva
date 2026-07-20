# Reglas de trabajo para Casa Viva

Estas instrucciones se aplican a todo el repositorio.

## Forma de trabajo

- Trabaja una sola tarea funcional por vez.
- Revisa el código existente antes de modificarlo.
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
