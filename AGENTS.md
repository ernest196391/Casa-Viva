# Reglas permanentes para agentes de Casa Viva

Estas reglas aplican a todo el repositorio y deben guiar el trabajo de cualquier agente que participe en el desarrollo de Casa Viva.

## Forma de trabajo

1. Trabajar una sola tarea funcional por vez.
2. Revisar el código existente antes de modificarlo.
3. No implementar funciones que no hayan sido solicitadas.
4. No eliminar o reestructurar partes importantes sin explicar primero la razón.
5. Informar siempre los archivos modificados, las pruebas realizadas y los asuntos pendientes.

## Base técnica

6. Mantener Next.js con App Router, TypeScript y Tailwind CSS.
7. Mantener los componentes pequeños, reutilizables y bien organizados.
8. No instalar dependencias nuevas sin justificar claramente su necesidad.
9. Ejecutar lint y build después de cambios funcionales.
10. No cambiar configuraciones sin una tarea específica que lo requiera.

## Alcance del producto

11. No implementar funciones que todavía no hayan sido solicitadas.
12. No agregar todavía base de datos, autenticación, pagos ni integraciones externas sin una tarea específica.
13. No confundir la primera versión, que será una tienda propia, con el futuro marketplace multivendedor.
14. No realizar despliegues ni conectar servicios externos sin autorización.

## Datos y reglas comerciales

15. No fijar directamente en el código precios, tasas de cambio, comisiones, horarios, tarifas o reglas comerciales.
16. Utilizar datos de demostración claramente identificados mientras no exista una base de datos real.
17. No incluir secretos, contraseñas, tokens ni credenciales en el repositorio.

## Experiencia de usuario y diseño

18. Priorizar diseño mobile-first y responsive.
19. Optimizar para conexiones lentas y dispositivos de recursos limitados.
20. Preservar accesibilidad, legibilidad y navegación mediante teclado.
21. Usar español para los textos visibles al cliente y para la documentación principal del proyecto.
22. Mantener el diseño alineado con Casa Viva: minimalista, cálido, beige y verde oscuro, con una estética de lujo silencioso accesible.
