# Casa Viva Platform — Blueprint Maestro vivo

## 1. Autoridad y propósito

Este documento define la dirección del producto que nace de Casa Viva. Su función es permitir que una persona, ChatGPT, Claude Code u otro agente pueda abrir el repositorio, comprender qué se está construyendo, identificar la fase activa y continuar sin duplicar capacidades ni inventar resultados.

Orden obligatorio de lectura:

1. `docs/CASA_VIVA_PLATFORM_MASTER_BLUEPRINT.md`
2. `docs/CASA_VIVA_PLATFORM_CHECKPOINT.md`
3. `docs/CASA_VIVA_CURRENT_STATE.md`
4. `docs/CASA_VIVA_BLUEPRINT.md`
5. documentación específica de la fase activa
6. código y estado real de `main`

Si existe contradicción, prevalece el código real y la evidencia verificable. La documentación debe corregirse en la misma fase.

## 2. Visión

Construir una plataforma comercial reutilizable para Casa Viva, Todo Hogar, Estilo y Hogar, Dulce Hogar y futuras tiendas.

El producto deberá ofrecer:

- tienda online independiente por negocio;
- catálogo, precios, inventario y pedidos por empresa;
- IA comercial específica de cada tienda;
- tarifas, geolocalización y mensajería;
- paneles por rol;
- Gestor como oficina comercial de gestoras;
- tiendas personales tipo dropshipping;
- aplicación global de mensajeros;
- contabilidad offline para Windows con sincronización;
- configuración white-label para nuevos negocios.

## 3. Decisiones no negociables

1. No clonar Casa Viva ni mantener un plugin distinto por tienda.
2. Una capacidad común se construye una sola vez.
3. Cada empresa conserva aislamiento de datos, marca, catálogo, reglas y responsables.
4. WooCommerce continúa como fuente oficial de pedidos, clientes, productos y stock en las tiendas conectadas hasta que una migración futura sea aprobada.
5. El pedido comercial pertenece a la tienda que vende.
6. Un carrito multitienda se divide en pedidos por tienda y los relaciona mediante un `checkout_group_id`.
7. Mensajería administra trabajos de entrega; no es dueña del pedido.
8. Las incidencias no sustituyen el estado normal.
9. Cobro, comisión, payout, logística e inventario son dimensiones separadas.
10. Toda transición sensible debe ser auditable e idempotente.
11. La IA consulta herramientas y fuentes autorizadas; no contiene la verdad operativa en prompts.
12. Mobile-first, conexiones lentas y dispositivos modestos son requisitos del producto.
13. No avanzar a una fase posterior sin cierre verificable y aprobación del propietario.
14. Se priorizan servicios gratuitos o de bajo costo con una vía clara de escalado.

## 4. Arquitectura objetivo

### 4.1 Comercios

Cada tienda es un `tenant` independiente con:

- identidad, dominio y configuración;
- catálogo y disponibilidad;
- pedidos, clientes y responsables;
- reglas de precios, pagos y mensajería;
- usuarios y permisos;
- IA y conocimiento autorizados.

### 4.2 NEXO Commerce Hub

Servicio común que conecta las tiendas y ofrece:

- registro de empresas y capacidades;
- conectores WooCommerce;
- índice federado de productos;
- eventos normalizados;
- identidad y autorización multiempresa;
- IA, búsqueda, geo, rutas y tarifas;
- auditoría y observabilidad;
- contratos para Gestor, operaciones, mensajería y contabilidad.

NEXO no duplica la propiedad de pedidos o stock de WooCommerce durante la transición.

### 4.3 Gestor

Oficina comercial para gestoras. Permite seleccionar productos autorizados de varias tiendas, aplicar márgenes dentro de reglas, crear una tienda personal, compartir enlaces, atribuir clientes y consultar pedidos, ganancias y comisiones.

La selección de una gestora referencia el producto fuente; no crea inventario paralelo.

### 4.4 Operaciones por rol

Una sola plataforma ofrece vistas autorizadas para:

- propietario/administración;
- dependienta/operador;
- gestora;
- mensajero;
- cliente;
- contador.

El permiso se evalúa como `usuario + rol + tenant + sucursal + capability`.

### 4.5 Mensajería global

Una sola aplicación gestiona `delivery_jobs` de múltiples comercios. Cada trabajo referencia uno o varios pedidos, mantiene custodia, ruta, tarifa, pagador, cobro, evidencia y liquidación, sin apropiarse del estado comercial.

### 4.6 Contabilidad Windows offline-first

Aplicación prevista con Tauri, TypeScript y SQLite local:

- funciona sin conexión;
- registra una cola local auditable;
- sincroniza al recuperar internet;
- recibe actualizaciones firmadas;
- usa un ledger append-only;
- corrige mediante asientos compensatorios, nunca borrando historia;
- consume eventos canónicos de pedidos, cobros, entregas, comisiones, payouts, devoluciones, gastos y ajustes.

El certificado de firma de Windows es un gasto futuro: sirve para demostrar al sistema operativo quién publicó el instalador y reducir advertencias. No es necesario adquirirlo durante la fase Foundation.

## 5. Estrategia de costo

### Comenzar con planes gratuitos cuando sea seguro

- GitHub: repositorio, issues, PR y CI.
- Vercel: frontends y previews dentro de límites del plan.
- Supabase: desarrollo inicial de Postgres, Auth y Storage.
- WordPress/WooCommerce existente: conservar infraestructura pagada ya disponible.
- Aplicaciones PWA: evitar tiendas de aplicaciones durante pilotos.
- Tauri + SQLite: tecnologías abiertas para escritorio.

### Gastos que se activan solo al demostrar necesidad

- dominios de cada negocio;
- mayor capacidad de base de datos/almacenamiento;
- correo transaccional con volumen;
- WhatsApp Business API oficial;
- proveedor de mapas/rutas con volumen;
- monitoreo avanzado;
- certificado de firma para Windows;
- copias de seguridad administradas y soporte comercial.

Cada checkpoint debe incluir `costo_actual`, `costo_nuevo_requerido`, `alternativa_gratuita` y `momento_de_escalar`.

## 6. Roadmap por fases

### Fase 0 — Casa Viva Core estable

Objetivo: cerrar Casa Viva como primer comercio certificable antes de extraer la plataforma común.

Subfases:

- `CV-RECOVERY-001`: recuperar una línea base confiable; corregir regresiones y cobertura CI.
- `CV-RECOVERY-002`: sincronizar producción con un SHA certificado.
- `CV-7D-FINAL`: ejecutar el pedido controlado completo por todos los roles.
- `CV-LAUNCH-POLISH`: corregir recursos rotos, accesos, catálogo, SEO, rendimiento y accesibilidad necesarios para lanzamiento.
- `CV-BASELINE`: emitir `CASA VIVA CORE — BASELINE ESTABLE PRE-PLATFORM`.

Criterio de salida:

- mismo SHA certificado y desplegado;
- CI, integración, navegador y smoke verdes;
- cero P0/P1 abiertos;
- ciclo cliente → gestora → operación → mensajero → administración → comisión/payout probado;
- rollback disponible;
- documentación actualizada.

### Fase 1 — Foundation multiempresa

Objetivo: extraer contratos y capacidades comunes sin romper Casa Viva.

Incluye:

- tenants, sucursales, membresías, roles y capabilities;
- configuración de marca y dominio;
- adaptador WooCommerce versionado;
- eventos normalizados y outbox/inbox idempotente;
- catálogo federado de solo lectura;
- auditoría, telemetría y seguridad por tenant;
- contratos comunes de tarifas, geo, IA y notificaciones;
- modelo de costos y límites por plan.

No incluye todavía migrar pedidos de Casa Viva fuera de WooCommerce.

### Fase 2 — Todo Hogar como segunda tienda

Objetivo: demostrar que una tienda nueva se activa por configuración y adaptadores, no copiando código.

Incluye marca, dominio, catálogo, checkout, administración, dependientes, IA y mensajería conectada.

### Fase 3 — Gestor multitienda

Objetivo: permitir que una gestora seleccione productos autorizados de Casa Viva y Todo Hogar, cree su tienda, comparta enlaces y reciba atribución y comisión.

### Fase 4 — Operaciones compartidas

Objetivo: panel único multiempresa para propietarios, administradores y dependientes con aislamiento y auditoría.

### Fase 5 — Mensajería global

Objetivo: una aplicación reutilizable para trabajos de entrega de múltiples tiendas, con geo, tarifas, custodia, cobro y liquidación separados.

### Fase 6 — Contabilidad Windows offline-first

Objetivo: ledger y conciliación sincronizables con operación offline, actualizaciones seguras y recuperación ante fallos.

Secuencia interna:

1. contratos contables y ledger;
2. motor de sincronización;
3. SQLite y cola offline;
4. interfaz Windows;
5. backups y recuperación;
6. actualizador firmado;
7. piloto con Casa Viva;
8. multiempresa.

### Fase 7 — Estilo y Hogar + Dulce Hogar

Objetivo: incorporar ambas tiendas usando la misma Foundation y medir tiempo/costo de activación.

### Fase 8 — Producto white-label comercial

Objetivo: onboarding de nuevos comercios, planes, facturación, límites, soporte, plantillas y despliegue repetible.

## 7. Protocolo obligatorio de continuidad

Al iniciar cualquier sesión o agente:

1. leer el orden obligatorio de documentos;
2. inspeccionar repo, rama, HEAD, CI y producción;
3. informar `fase`, `subfase`, `estado`, `bloqueo` y `siguiente acción`;
4. clasificar la tarea como `CURRENT`, `NEXT`, `PREPARE` o `FUTURE`;
5. identificar la fuente de verdad y capability dueña;
6. buscar implementación existente antes de crear archivos o tablas;
7. trabajar en una rama y PR pequeño;
8. ejecutar pruebas pertinentes y registrar evidencia real;
9. actualizar `CASA_VIVA_PLATFORM_CHECKPOINT.md` al cerrar cada subpaso;
10. detenerse para aprobación al final de una fase o ante una decisión con costo, producción o cambio irreversible.

Formato de reanudación obligatorio:

```text
FASE:
SUBFASE:
ESTADO:
ÚLTIMO SHA VERIFICADO:
PRODUCCIÓN:
QUÉ FUNCIONA:
QUÉ FALLA:
QUÉ FALTA:
COSTO NUEVO NECESARIO:
SIGUIENTE ACCIÓN:
REQUIERE APROBACIÓN: sí/no
```

## 8. Regla de actualización

Este Blueprint cambia únicamente cuando cambia la visión, arquitectura o secuencia aprobada. El progreso cotidiano se registra en `CASA_VIVA_PLATFORM_CHECKPOINT.md`. No marcar una fase como terminada por existencia de código: requiere evidencia y criterio de salida completo.
