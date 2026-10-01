# Casa Viva Platform — Checkpoint operativo

## Resumen de reanudación

```text
FASE: 0 — Casa Viva Core estable
SUBFASE CERRADA: CV-RECOVERY-001 — Recuperar una línea base confiable
ESTADO: CERRADA Y DESPLEGADA
MAIN BASE DEL PR: 001589c4ce4d0d31ac4986a18fbb20168e8369b3
PRODUCCIÓN CERTIFICADA: 001589c4ce4d0d31ac4986a18fbb20168e8369b3
NOTA: al fusionar cambios solo documentales, `main` puede avanzar sin que producción cambie
DEPLOY: GitHub Actions #20 — SUCCESS
DECISIÓN: GO para iniciar el próximo subpaso de Fase 0, únicamente con aprobación
COSTO NUEVO UTILIZADO: 0 USD
```

## Qué estamos construyendo

Casa Viva será el primer comercio certificado de una plataforma multiempresa que después incorporará Todo Hogar, Estilo y Hogar y Dulce Hogar, Gestor multitienda, mensajería global y contabilidad offline para Windows.

No se deben iniciar esas fases hasta cerrar completamente la Fase 0.

## Cierre de CV-RECOVERY-001

| Paso | Estado | Evidencia |
| --- | --- | --- |
| 001.1 Crear Blueprint y checkpoint vivos | CERRADO | PR #116 fusionado |
| 001.2 Corregir orden de carga del guard del mensajero | CERRADO | Contratos de recarga verdes |
| 001.3 Incorporar prueba omitida al CI | CERRADO | Regresiones incluidas en `validate.yml` |
| 001.4 Inventariar pruebas huérfanas | CERRADO | Cuatro pruebas añadidas al CI; inventario documentado |
| 001.5 Ejecutar validación completa | CERRADO | aplicación, integración, navegador, release y staging verdes |
| 001.6 Fijar y desplegar SHA candidato | CERRADO | `001589c4ce4d0d31ac4986a18fbb20168e8369b3` |

## Evidencia de producción

- workflow: **Deploy prototype Casa Viva #20**;
- ejecución: `36466381630`;
- resultado: **success**;
- duración del job: 37 s;
- SHA solicitado y desplegado: `001589c4ce4d0d31ac4986a18fbb20168e8369b3`;
- release reproducible y checksum: aprobados;
- identidad del plugin desplegado: aprobada por lectura SSH del marcador;
- análisis real de un vale sintético: aprobado;
- smoke contra `https://casavivadecuba.com`: aprobado;
- rollback automático: disponible y no ejecutado porque no hubo fallo;
- portada pública: accesible y con catálogo renderizado tras el despliegue.

## Hallazgos que continúan abiertos en Fase 0

### P2 — Lanzamiento web

- revisar y sustituir las cuatro imágenes de categorías detectadas como rotas;
- establecer H1, meta description, canonical y título definitivo;
- sanear catálogo y accesos;
- verificar visualmente en móvil el tarifario de mensajería actualizado;
- ejecutar cierre funcional de compra, vale, IA, mensajería y roles.

### Mantenimiento no bloqueante

- GitHub advierte que `actions/checkout@v4` todavía usa Node.js 20 y será forzado a Node.js 24;
- `ubuntu-latest` migrará a Ubuntu 26 a partir del 19 de octubre de 2026;
- estas advertencias no afectaron el despliegue #20, pero deben entrar en mantenimiento técnico.

## Próxima compuerta

Presentar para aprobación el siguiente subpaso de Fase 0: **auditoría y cierre de lanzamiento web en móvil**, empezando por imágenes rotas, SEO técnico y verificación visual del tarifario.

No iniciar Foundation multiempresa, nuevas tablas, compras de servicios ni cambios sobre pedidos reales sin aprobación expresa.

## Protocolo de continuidad

Al retomar el proyecto:

1. leer este checkpoint y el Blueprint maestro;
2. comprobar por separado que producción continúa en el SHA certificado y que `main` contiene ese SHA como ancestro o documenta explícitamente cualquier delta posterior;
3. ejecutar solamente la próxima acción aprobada;
4. actualizar estado, evidencia, costo y próxima compuerta;
5. no avanzar de fase por inferencia ni por CI verde.
