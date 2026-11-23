# Casa Viva Network — Repository Gap Analysis V1

## Estado
Paso 8 — COMPLETADO V1.

## Base observada
Repositorio: `ernest196391/Casa-Viva`.

Estado documentado en `main`:
- Bloques 1A–1C.4: pedidos/eventos/transiciones/logística/excepciones integrados;
- 2A–2D: centro de pedido/notificaciones/mensajero integrados;
- 3A–3E: UX cliente/seguimiento integrados;
- 4A–4H: gestoras/comisiones/payouts/privacidad integrados;
- 5A–5D: dependientas/inventario/incidencias integrados;
- 6A–6B: releases/despliegue/rollback integrados;
- 7A–7B: compra real/onboarding integrados;
- 7C/7D: puesta en marcha visual/E2E como frontera actual del producto.

Este análisis no autoriza cambios funcionales. Clasifica compatibilidad entre el sistema presente y la visión Network.

---

## 1. Qué ya sirve como fundamento Network

### Order Core — FUERTE
Ya existe trabajo sustancial en:
- modelo canónico;
- estados;
- eventos;
- transición;
- historial;
- excepciones.

Decisión: **conservar y extender**, no reemplazar.

### Logistics — FUERTE/PARCIAL
Ya existe:
- mensajero;
- custodia;
- recogida;
- entrega;
- incidencias;
- centro operativo.

Faltará para Network:
- scheduling avanzado;
- disponibilidad general;
- matching;
- mapas offline;
- múltiples orígenes de trabajo;
- flota.

### Gestoras / Attribution / Commissions — FUERTE
Ya existe:
- referidos;
- comisiones;
- payouts;
- precios espejo;
- privacidad;
- reasignación auditable.

Esto es una ventaja importante para el futuro Marketplace/Sales Network.

### Inventory — FUERTE PARA COMMERCE ACTUAL
WooCommerce permanece fuente oficial.

No generalizar a Universal Catalog todavía.

### Privacy / Capability boundaries — BUENA BASE
Bloques de dependienta y roles ya han forzado separación de datos por necesidad.

### Release Engineering — FUERTE
Existe:
- SHA reproducible;
- checksums;
- CI;
- Hostinger;
- smoke;
- rollback.

La evolución Network debe preservar este nivel de disciplina.

---

## 2. Gaps de arquitectura comunes

### Identity multi-role — GAP / PREPARE
El sistema ya tiene roles, pero Network necesita garantizar que una identidad pueda acumular múltiples capacidades/roles sin crear cuentas duplicadas.

Acción futura: auditar modelo real antes de cambiar.

### Organizations — GAP
Necesario para:
- negocios;
- sucursales;
- equipos de gestoras;
- flotas;
- empresas profesionales.

No es requisito para cerrar 7D.

### Universal Catalog — GAP FUTURO
WooCommerce cubre Commerce actual. No existe necesidad demostrada de reemplazarlo.

Preparar adapters, no un segundo catálogo todavía.

### Scheduling Core — GAP
Casa Viva actual está centrada en pedidos y etapas; Network requiere reservas futuras, recurrencias, disponibilidad y conflictos.

Es uno de los primeros nuevos dominios candidatos después del lanzamiento.

### Geo abstraction / offline — GAP IMPORTANTE
Existe uso de ubicación/deep links, pero Network requiere:
- GeoPoint canónico;
- timestamps;
- proveedor desacoplado;
- offline regions;
- local DB/sync en clientes móviles futuros.

### CRM común — GAP
Gestoras y flujos operativos tienen relaciones, pero no existe todavía un CRM transversal como dominio común.

### Reputation — GAP
Debe diseñarse después de tener intercambios reales suficientes. No mezclarlo con ratings cosméticos.

### Goals — GAP
Capacidad nueva relativamente aislable y de bajo riesgo una vez exista fuente de datos fiable.

### Agent/Automation Platform — GAP FUTURO
No debe implementarse antes de:
- servicios de dominio claros;
- permisos;
- eventos;
- auditabilidad.

### Benefits — GAP FUTURO
No prioritario.

### Universal Ledger — PARCIAL
Comisiones/payouts ya obligan a separar conceptos económicos. Hay que auditar el modelo real antes de declarar un ledger universal.

---

## 3. Gaps por actor

### Mensajero
Existe operación de pedidos.
Faltan:
- agenda;
- clientes propios;
- metas;
- offline app;
- matching;
- rentabilidad;
- mantenimiento;
- flota.

### Gestora
Existe attribution/commission/portal financiero/precio espejo.
Faltan:
- tienda multinegocio general;
- CRM universal;
- Studio;
- Scout;
- Sales Copilot;
- equipo.

### Cliente
Existe compra/pedidos/seguimiento/ayuda.
Faltan:
- CV Home;
- recurrencia;
- despensa;
- coste puesto en casa;
- profesionales;
- planificación.

### Negocio
Commerce Casa Viva existe como operador, pero falta convertir la arquitectura en producto multi-negocio:
- Organizations;
- merchant onboarding;
- catálogo por merchant;
- analytics;
- CRM;
- fuerza de gestoras configurable;
- Business Agent.

### Profesional
Prácticamente nuevo vertical:
- perfil;
- especialidad;
- disponibilidad;
- presupuesto;
- service job;
- reputación.

---

## 4. Riesgos si se intenta Network demasiado pronto

1. Duplicar WooCommerce con un segundo motor comercial.
2. Introducir roles/organizaciones sin auditar permisos actuales.
3. Romper estados canónicos por adaptar demasiados verticales a la vez.
4. Diseñar IA antes de tener herramientas/servicios confiables.
5. Crear wallet/ledger universal sin reconciliar comisiones actuales.
6. Mezclar mapas offline con la lógica de pedido.
7. Sobrearquitectura antes de certificación de lanzamiento 7D.

---

## 5. Oportunidades de preparación de bajo riesgo

Durante trabajo futuro, cuando sea relevante y dentro de una fase aprobada:
- evitar hardcodes de `Casa Viva` como único source cuando sea trivial abstraerlo;
- mantener IDs estables;
- documentar contratos;
- encapsular accesos a WooCommerce;
- conservar eventos auditables;
- separar geo de proveedor;
- separar payout/comisión/precio;
- no asumir one-user-one-role;
- mantener APIs internas con fronteras claras.

No hacer refactors puramente especulativos.

---

## 6. Evaluación de preparación

| Área | Estado Network |
|---|---|
| Order/Event foundation | 🟢 fuerte |
| Logistics current | 🟢/🟡 fuerte pero específico |
| Gestora attribution/commission | 🟢 fuerte |
| Inventory commerce | 🟢 fuerte para presente |
| Release/rollback | 🟢 fuerte |
| Identity multi-role | 🟡 auditar/preparar |
| Organizations | 🔴 no existe como núcleo |
| Scheduling | 🔴 gap |
| Geo offline | 🔴 gap |
| CRM shared | 🔴 gap |
| Reputation | 🔴 gap |
| Goals | 🔴 gap |
| Agent platform | 🔴 futuro |
| Multi-business marketplace | 🔴 futuro |
| Professional services | 🔴 futuro |

## 7. Conclusión
Casa Viva actual no debe ser reemplazada para construir Network. Ya posee piezas difíciles —pedido, eventos, logística, comisiones, privacidad y despliegue— que deben convertirse en la base. El camino correcto es **launch → stabilize → extract boundaries → add shared capabilities → add second real vertical → generalize only when proven**.