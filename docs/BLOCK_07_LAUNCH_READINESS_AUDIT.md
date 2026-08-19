# Casa Viva — auditoría de puesta en marcha real

Base auditada: `main` en `8064e07162531540c9b324ab3aa0a3739d159823`.

Objetivo: contrastar lo ya construido en Bloques 01–06 con la experiencia realmente accesible en `casavivadecuba.com`, sin reconstruir contratos canónicos ni duplicar lógica.

## 1. Resumen ejecutivo

La arquitectura funcional está más avanzada que la superficie visible. El plugin 3.6.0 ya registra navegación de cliente, pedidos/seguimiento, checkout cubano, registro de gestoras/mensajeros, áreas privadas, privacidad de dependientas, inventario, incidencias y administración. Sin embargo, la web pública observada sigue presentándose principalmente como una tienda WooCommerce: Inicio, Categorías, Tienda, Vende con Casa Viva, Mi cuenta y Carrito.

El hueco principal no es crear otra plataforma: es **conectar, hacer descubribles y probar de extremo a extremo las capacidades existentes en la experiencia real**.

## 2. Clasificación por recorrido

### A. Superficie pública del cliente — PARCIAL

Evidencia:
- Inicio, buscador, categorías, catálogo, carrito y Mi cuenta son accesibles públicamente.
- La navegación móvil canónica existe en código con Inicio / Categorías / Carrito / Pedidos / Cuenta.
- La portada pública observada todavía no comunica con claridad el recorrido completo pedido → seguimiento → ayuda.

Hueco:
- validar visualmente la navegación móvil desplegada y asegurar que los puntos de entrada de Pedidos/seguimiento sean descubribles;
- eliminar contradicciones entre navegación del tema y navegación operativa;
- comprobar el flujo completo en navegador real.

### B. Checkout y creación real de pedido — PARCIAL

Evidencia:
- `CVD_Cuban_Checkout` implementa Cuba por defecto, provincias/municipios/localidades, mensajería vs recogida y creación del pedido WooCommerce antes de continuar por WhatsApp.
- WooCommerce sigue siendo la fuente oficial del pedido.

Hueco:
- falta evidencia E2E sobre el sitio desplegado con carrito no vacío hasta creación de pedido;
- deben verificarse en producción-prototipo: campos, tarifa visible, recogida, mensajería, errores, confirmación y retorno/seguimiento;
- la prueba debe ser controlada y no contaminar pedidos comerciales reales.

### C. Pedidos y seguimiento del cliente — IMPLEMENTADO EN CÓDIGO / PARCIAL EN SUPERFICIE REAL

Evidencia:
- `CVD_Customer_Orders`, soporte contextual y navegación cliente están cargados por el plugin.
- el endpoint Pedidos se integra en Mi cuenta.

Hueco:
- validar una cuenta cliente real de prueba y confirmar listado, detalle, timeline, ayuda y privacidad en la web desplegada.

### D. Gestoras — PARCIAL

Evidencia:
- página pública `Vende con Casa Viva` existe;
- registro seguro y aprobación están implementados;
- área privada y vista financiera existen en código;
- atribución, precios espejo, comisiones y payouts están cerrados en Bloque 04.

Hueco:
- comprobar onboarding completo desde solicitud pública → aprobación admin → acceso → área gestora;
- hacer claro el acceso posterior para una gestora aprobada;
- prueba E2E de privacidad entre dos gestoras.

### E. Mensajeros — PARCIAL

Evidencia:
- rol, registro y área de mensajero existen en código;
- Centro Operativo del Mensajero está cerrado en Bloque 02.

Hueco:
- no se observó un punto de entrada público equivalente al de gestoras en la navegación principal;
- validar solicitud → aprobación → login → pedido asignado → acciones de custodia/ruta;
- revisar UX móvil real.

### F. Dependientas / operación de tienda — PARCIAL

Evidencia:
- rol `cvd_clerk`, privacidad, recogida, inventario e incidencias están implementados;
- administración mantiene vista ampliada.

Hueco:
- no existe evidencia todavía de un recorrido visual consolidado y fácil de descubrir desde teléfono para dependienta;
- validar pedido recibido → preparación → listo → recogida/handoff → incidencia.

### G. Administración — IMPLEMENTADO FUNCIONALMENTE / PARCIAL EN UX

Evidencia:
- existen capacidades administrativas, ajustes, overrides auditables, inventario, payouts e incidencias.

Hueco:
- auditar densidad, navegación y seguridad del backoffice como herramienta cotidiana;
- confirmar que las correcciones sensibles tienen motivo/evidencia y no hay acciones paralelas a los servicios canónicos.

### H. Identidad visual y navegación — PARCIAL

Evidencia:
- la web tiene una estructura pública coherente de tienda.

Hueco:
- la experiencia visible todavía debe alinearse con la identidad Casa Viva definida para la marca;
- revisar duplicidad de navegación, jerarquía de CTA, estados vacíos, nomenclatura y consistencia móvil;
- no mezclar esta mejora visual con cambios de lógica de pedidos.

### I. Catálogo / datos legacy — PARCIAL

Evidencia:
- catálogo y categorías están activos;
- existe infraestructura de calidad de catálogo.

Hueco:
- auditar nombres, categorías, imágenes, productos agotados/legacy y contenido que pueda confundir el lanzamiento;
- no alterar stock ni pedidos históricos mediante una limpieza visual.

### J. Pruebas de lanzamiento — PENDIENTE COMO MATRIZ E2E REAL

CI cubre validate/integration/browser y smoke de despliegue, pero falta una matriz explícita sobre el sitio desplegado que certifique los recorridos críticos por rol con cuentas/fixtures de prueba controlados.

## 3. Riesgos

1. Tener funcionalidades correctas pero inaccesibles para usuarios reales.
2. Duplicar páginas o menús para resolver descubribilidad, creando dos fuentes de UX.
3. Probar checkout con datos comerciales reales y contaminar operación/stock/comisiones.
4. Mejorar diseño antes de asegurar que el recorrido crítico compra → pedido → operación funciona.
5. Dar acceso cómodo a roles internos exponiendo datos que Bloques 04–05 ya protegen.

## 4. Secuencia mínima propuesta

### 7A — Camino de compra real y acceso al pedido

Frontera: convertir la experiencia pública actual en un recorrido verificable Inicio/Tienda → producto → carrito → checkout → pedido → Pedidos/seguimiento/ayuda, reutilizando exclusivamente WooCommerce y los contratos de Bloques 01–03.

Incluye:
- wiring/visibilidad de navegación necesaria;
- checkout desplegado;
- cuenta cliente de prueba controlada;
- prueba E2E real sin contaminar operación;
- correcciones mobile-first estrictamente necesarias.

No incluye:
- rediseño general de marca;
- nuevos estados;
- cambios a gestoras/mensajería interna.

### 7B — Puertas de entrada y onboarding por rol

Frontera: hacer accesibles y verificables los recorridos existentes de gestora, mensajero y dependienta/admin sin duplicar sus motores funcionales.

Incluye solicitud/login/aprobación/acceso, redirects role-aware, privacidad y prueba E2E por rol.

### 7C — Capa visual de lanzamiento y saneamiento del catálogo

Frontera: aplicar identidad Casa Viva, simplificar navegación/copy, resolver estados vacíos y limpiar presentación de datos legacy sin recalcular stock, pedidos o finanzas.

### 7D — Certificación de lanzamiento

Frontera: matriz E2E final sobre el sitio desplegado para cliente, recogida, mensajería, gestora, dependienta y admin; documentación de rollback y checklist de operación real.

## 5. Primera fase recomendada

**7A — Camino de compra real y acceso al pedido.**

Razón: es el cuello de botella de valor. Antes de optimizar onboarding interno o branding, Casa Viva debe demostrar en la web desplegada que un cliente puede completar el recorrido crítico y luego encontrar su pedido sin depender de conocimiento interno.

## 6. Regla de implementación

7A debe empezar con una auditoría técnica de las páginas WooCommerce configuradas y los hooks ya activos. Solo se modifica aquello que impida o confunda el recorrido real. Cada cambio debe tener una regresión y pasar `validate`, `integration` y `browser`; luego se despliega por el workflow SHA→Hostinger ya validado en Bloque 06.
