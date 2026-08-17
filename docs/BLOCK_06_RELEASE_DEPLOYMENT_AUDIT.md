# Bloque 06 — Auditoría de pruebas, releases y despliegues

## Base auditada

- Repositorio: `ernest196391/Casa-Viva`
- Rama base: `main`
- HEAD de base: `428f7e1fffe34431782c2237239fb02b7a002e9f`
- Último cierre funcional: Bloque 05 / Fase 5D
- CI post-merge de referencia: run #133 (`validate`, `integration`, `browser` en success)

## Estado actual observado

### CI existente

Existe un único workflow: `.github/workflows/validate.yml`.

Se ejecuta en:

- `pull_request` hacia `main`;
- `push` a `main`;
- `workflow_dispatch`.

Incluye tres jobs principales:

1. `validate`
   - contratos PHP/Node de las fases funcionales;
   - lint;
   - TypeScript;
   - build Next.js.
2. `integration`
   - WordPress + MariaDB reales en Docker;
   - suites funcionales de gestoras, operaciones, inventario e incidencias.
3. `browser`
   - WordPress desechable;
   - fixtures reales;
   - Playwright + Chromium;
   - artefactos de diagnóstico.

### Fortalezas

- La rama `main` queda protegida de regresiones funcionales mediante validación estática, integración y navegador.
- Las pruebas de WordPress no dependen de la instalación productiva.
- Los jobs de integración y navegador limpian su entorno al finalizar.
- Los artefactos de Playwright se conservan para diagnóstico.
- Los permisos del workflow están limitados a lectura de contenidos.

### Huecos identificados

No se observa todavía una capa explícita y auditable para:

- empaquetado de una versión desplegable;
- identificación/versionado de releases;
- promoción controlada desde código validado hacia Hostinger;
- separación entre validar y desplegar;
- entorno de staging o preproducción;
- smoke test contra el sitio desplegado;
- backup previo al despliegue;
- rollback documentado y probado;
- registro de qué commit está desplegado en producción;
- política de secretos/credenciales de despliegue;
- protección contra desplegar un SHA distinto al que fue validado;
- procedimiento de emergencia cuando producción falla después de un release.

## Decisión de arquitectura para el Bloque 06

El Bloque 06 no debe convertir cada push a `main` en un despliegue automático ciego.

La primera versión del proceso debe separar claramente cuatro etapas:

`PR validado` → `main validado` → `release candidato` → `deploy autorizado`

Producción solo debe recibir un artefacto o commit que ya haya pasado las mismas garantías de CI que protegieron el merge.

## Secuencia propuesta

### 6A — Fundación de release y trazabilidad

Objetivo: definir un artefacto/release reproducible e identificar inequívocamente qué SHA se pretende desplegar.

Criterios de aceptación:

- el release referencia un SHA exacto de `main`;
- no contiene secretos;
- puede verificarse antes de subirlo;
- existe una manifestación mínima de versión, SHA y contenido;
- el proceso no modifica producción.

### 6B — Staging y smoke tests

Objetivo: desplegar primero en un entorno no productivo o equivalente seguro y ejecutar comprobaciones mínimas reales.

Criterios de aceptación:

- el entorno de prueba no comparte datos productivos de forma peligrosa;
- la versión desplegada coincide con el SHA esperado;
- se verifican disponibilidad, WordPress/plugin y rutas críticas;
- el fallo bloquea promoción a producción.

### 6C — Backup y rollback

Objetivo: garantizar una vía de retorno antes de cualquier despliegue productivo.

Criterios de aceptación:

- backup previo identificable;
- procedimiento de restauración documentado;
- rollback a una versión conocida;
- no se declara cerrado hasta verificar el mecanismo de recuperación con recursos seguros.

### 6D — Deploy productivo controlado

Objetivo: desplegar una release validada a Hostinger sin depender de pasos manuales ambiguos.

Criterios de aceptación:

- requiere autorización explícita antes de tocar producción;
- despliega solo una release/SHA previamente validada;
- registra resultado y versión desplegada;
- ejecuta smoke tests posteriores;
- si falla, activa el procedimiento de rollback.

### 6E — Cierre operativo de releases

Objetivo: dejar una rutina repetible para futuras versiones.

Criterios de aceptación:

- checklist de release;
- responsable y evidencias;
- política de versionado;
- runbook de incidentes de despliegue;
- documentación alineada con GitHub y Hostinger reales.

## Información de Hostinger pendiente de auditar

Antes de implementar 6B–6D hay que leer, mediante el conector de Hostinger cuando esté disponible en la sesión:

- sitio/hosting que contiene `casavivadecuba.com`;
- tipo de plan;
- instalación WordPress activa;
- versión PHP;
- plugins y tema relevantes;
- subdominios disponibles;
- mecanismos de backup/restauración disponibles;
- forma segura de desplegar el plugin/código de Casa Viva;
- posibilidad de staging;
- caché y operaciones posteriores al deploy.

No deben copiarse usuario, contraseña, tokens ni secretos al repositorio.

## Regla de seguridad

Ningún paso de esta auditoría autoriza por sí mismo un cambio en producción. Las operaciones de escritura en Hostinger deben identificar el recurso afectado y solicitar confirmación antes de ejecutarse.

## Estado

Auditoría inicial completada. La implementación comienza por 6A y puede avanzar en GitHub sin credenciales de Hostinger. 6B–6D quedan condicionadas a la lectura segura de la infraestructura real de Hostinger.