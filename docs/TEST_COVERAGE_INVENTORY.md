# Casa Viva — Inventario de cobertura automatizada

## Propósito

Evitar que una prueba existente quede fuera de CI y evitar falsos positivos al confundir una prueba indirecta con una prueba huérfana.

## Clasificación

### Contratos estáticos `artifacts/tests`

Todos los contratos `.php` y `.mjs` forman parte de CI:

- los contratos del Core, cliente, operaciones y mensajería se ejecutan en `validate.yml`;
- release se valida en `release-foundation-check.yml`;
- staging y deploy se validan en `staging-smoke-check.yml`.

Los contratos que estaban huérfanos y fueron incorporados durante `CV-RECOVERY-001` son:

- `test-messenger-feed-reload-guard.mjs`;
- `test-messenger-immediate-reload-guard.mjs`;
- `test-canonical-order-reader-1a.mjs`;
- `test-canonical-order-reader-history-truncation-1a.mjs`;
- `test-order-success-access-7a.mjs`;
- `test-catalog-presentation-7c3.mjs`.

### Integración WordPress/MariaDB

`scripts/integration.sh test` orquesta los archivos de transición, concurrencia, logística, custodia, cierre, excepciones, centro del pedido, atribución, comisiones, payouts y almacenamiento de fallos.

Después, `validate.yml` ejecuta comprobaciones adicionales de panel financiero, precios espejo, reasignación, enlaces, privacidad, recogida, inventario, incidencias, jornada de mensajería y obligaciones de cobro.

Por tanto, un archivo `integration/tests/*.php` que no aparece directamente en YAML no es huérfano cuando está referenciado por `scripts/integration.sh`.

### Navegador

El comando:

```bash
npx playwright test tests/browser --workers=1
```

ejecuta todas las especificaciones `tests/browser/*.spec.js`. Se usa un solo worker porque comparten una base WordPress desechable y los logins concurrentes pueden crear falsos timeouts.

## Regla permanente

Toda prueba nueva debe cumplir una de estas condiciones en el mismo PR:

1. añadirse explícitamente a un workflow;
2. quedar cubierta por un runner con patrón de archivos documentado;
3. quedar llamada por un script orquestador ejecutado en CI.

Si no cumple ninguna, CI debe considerarla huérfana y el PR no está listo.

## Auditoría de CV-RECOVERY-001

- contratos estáticos relevantes: cubiertos;
- integración: cubierta directa o indirectamente;
- navegador: cubierto por glob de Playwright;
- release: cubierto;
- staging/deploy contract: cubierto;
- despliegue real y smoke de producción: deliberadamente fuera del PR y sujetos a aprobación.
