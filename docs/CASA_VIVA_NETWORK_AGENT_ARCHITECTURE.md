# Casa Viva Network — Agent Architecture V1

## Estado
Paso 6 — COMPLETADO V1.

## Objetivo
Diseñar una plataforma de agentes compartida para Casa Viva sin crear chatbots aislados ni automatizaciones opacas.

## Principio rector
> El agente no es la fuente de verdad. El agente razona, recomienda y coordina herramientas autorizadas; los dominios siguen siendo propietarios de los datos y reglas.

---

## 1. Capas del Agent Platform

### Context
Información relevante de la sesión/actor:
- identidad;
- rol;
- organización;
- pedido/servicio activo;
- agenda;
- metas;
- preferencias.

### Memory
Memoria útil y controlada:
- relaciones;
- patrones;
- configuraciones;
- decisiones anteriores;
- objetivos.

No duplicar bases canónicas. La memoria referencia información de dominios.

### Tools
Herramientas explícitas:
- buscar ofertas;
- consultar agenda;
- calcular ruta;
- consultar ledger;
- crear borrador;
- programar recordatorio;
- proponer asignación;
- preparar contenido;
- consultar CRM.

### Permission Envelope
Cada herramienta define:
- quién puede invocarla;
- datos accesibles;
- si solo lee;
- si propone;
- si escribe;
- si requiere confirmación;
- límites económicos/temporales.

### Planner
Convierte objetivo en pasos.

### Policy / Guardrails
Reglas deterministas por encima del agente.

### Execution
Las acciones se ejecutan a través de servicios de aplicación, nunca directamente sobre tablas.

### Audit
Guardar:
- intención;
- herramienta;
- parámetros relevantes;
- aprobación;
- resultado;
- error;
- actor.

---

## 2. Niveles de autonomía

### A0 — Información
El agente explica y consulta.

### A1 — Recomendación
Propone una acción.

### A2 — Preparación
Prepara borrador/ruta/agenda/mensaje para confirmación.

### A3 — Acción confirmada
Ejecuta después de aprobación explícita.

### A4 — Regla delegada
Ejecuta automáticamente porque existe una regla previa, limitada y revocable.

### A5 — Autonomía amplia
No se autoriza como comportamiento general. Solo considerar en dominios de bajo riesgo y con controles maduros.

---

## 3. Human-in-the-loop

Requerir confirmación o regla determinista para:
- aceptar compromisos con terceros;
- mover dinero;
- cambiar precios;
- cancelar pedidos;
- asignar trabajo con impacto económico;
- enviar comunicaciones masivas;
- publicar contenido;
- revelar ubicación;
- modificar permisos.

El agente puede preparar contexto y recomendar, pero la gobernanza decide cuándo puede actuar.

---

## 4. Courier Copilot

### Lee
- agenda;
- ubicación autorizada;
- rutas;
- pedidos disponibles;
- clientes;
- metas;
- ingresos/gastos.

### Recomienda
- mejor siguiente carrera;
- retorno;
- huecos;
- tarifa;
- orden de paradas;
- acciones para meta.

### Puede ejecutar con permiso
- crear recordatorio;
- reservar dentro de regla;
- actualizar agenda;
- enviar estado operativo.

### No debe
- aceptar cualquier carrera sin límite;
- compartir ubicación fuera de ventana autorizada;
- ocultar razón de una recomendación económica.

---

## 5. Sales Copilot

### Lee
- CRM;
- catálogo;
- stock permitido;
- historial;
- márgenes;
- contenido;
- pedidos.

### Recomienda
- clientes a seguir;
- productos;
- campañas;
- contenido;
- oportunidades;
- logística.

### Ejecuta con permiso
- crear borrador;
- programar seguimiento;
- preparar campaña;
- asignar logística según regla aprobada.

No puede apropiarse del cliente ni alterar atribución para favorecer a Casa Viva.

---

## 6. Home Agent

### Lee
- preferencias;
- compras;
- hogar;
- presupuesto;
- ubicaciones;
- recurrencias.

### Recomienda
- reposición;
- mejor cesta;
- profesional;
- mantenimiento;
- ahorro.

### Ejecuta con permiso
- preparar carrito;
- programar recordatorio;
- crear solicitud;
- ejecutar compra recurrente dentro de presupuesto y reglas explícitas.

### Salud
Bienestar general puede generar sugerencias no clínicas. Diagnóstico/tratamiento quedan fuera del Agent general de Casa Viva.

---

## 7. Business Agent

### Lee
- ventas;
- inventario;
- CRM;
- equipo;
- pedidos;
- logística;
- gestoras;
- márgenes.

### Recomienda
- reposición;
- seguimiento;
- contenido;
- precio a revisar;
- asignación;
- riesgos.

### Ejecuta con permiso
- crear tareas;
- preparar campañas;
- programar contenido;
- aplicar reglas operativas configuradas;
- proponer/ejecutar logística dentro de políticas.

---

## 8. Professional Agent

### Lee
- agenda;
- clientes;
- solicitudes;
- portafolio;
- materiales;
- historial.

### Recomienda
- cotización;
- huecos;
- seguimiento;
- compra de materiales;
- recurrencia.

### Ejecuta con permiso
- preparar presupuesto;
- agendar;
- recordar;
- enviar confirmaciones aprobadas.

---

## 9. Tareas programadas

El Agent Platform debe distinguir:

### Reminder
Acción futura puntual.

### Recurring Task
Acción repetida por calendario.

### Condition Watch
Evaluación periódica de una condición con notificación solo cuando se cumple.

### Automation Rule
Trigger de dominio + condición + acción.

No confundir tareas programadas con eventos transaccionales.

---

## 10. Diseño para baja conectividad

El agente no debe ser un punto único de fallo.

Si no hay Internet:
- la app sigue mostrando estado local;
- reglas deterministas locales pueden continuar si son seguras;
- solicitudes de agente se encolan o degradan;
- acciones urgentes básicas siguen disponibles manualmente;
- nunca bloquear entrega por no poder consultar IA.

---

## 11. Observabilidad de agentes

Métricas mínimas:
- recomendación aceptada/rechazada;
- tasa de éxito de herramienta;
- acciones revertidas;
- errores;
- tiempo ahorrado;
- valor económico atribuible;
- automatizaciones deshabilitadas;
- necesidad de intervención humana.

## 12. Seguridad contra bucles y exceso de autonomía

- límite de acciones por tarea;
- límite de gasto/precio;
- expiración de permisos;
- deduplicación/idempotencia;
- máximo de reintentos;
- circuit breaker;
- confirmación ante ambigüedad económica;
- kill switch por actor/organización.

## 13. Principio final
> Primero el agente observa. Después recomienda. Luego prepara. Solo cuando existe confianza demostrada y permiso explícito, ejecuta.