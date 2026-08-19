# Bloque 06 — Auditoría de pruebas, releases y despliegues

## Base auditada

- Repositorio: `ernest196391/Casa-Viva`
- Rama base: `main`
- HEAD validado tras 6A: `d5cdacf80a47d967bd088b15192d3dab15f541a3`
- CI post-merge 6A: `Validar aplicación #141` y `Validar fundación de release #8` en success.

## Estado actual observado

### CI existente

El repositorio valida cada cambio relevante con:

1. `validate`
   - contratos PHP/Node;
   - lint;
   - TypeScript;
   - build.
2. `integration`
   - WordPress + MariaDB reales en Docker;
   - suites funcionales del sistema.
3. `browser`
   - WordPress desechable;
   - Playwright + Chromium;
   - diagnóstico automático.
4. `release-foundation-check`
   - contrato del empaquetado reproducible de 6A.
5. `staging-smoke-check`
   - contrato de smoke y despliegue controlado de 6B.

### Fundación 6A integrada

6A establece:

`main validado` → `release candidate reproducible` → `manifest` → `SHA256SUMS`

La unidad desplegable actual es exclusivamente:

`wordpress/casa-viva-dropship-core`

El ZIP se construye desde un SHA exacto de `main` mediante `git archive` y no contiene secretos.

## Infraestructura Hostinger auditada manualmente por SSH

Sitio: `https://casavivadecuba.com`

Estado observado el 18-08-2026:

- WordPress: `7.0.4`
- PHP CLI: `8.2.30`
- WooCommerce activo: `10.9.4`
- `casa-viva-dropship-core` activo: `3.4.0`
- plugin legacy/inactivo detectado: `casa-viva-dropship-core-error-2.1.0`
- ruta WordPress: `/home/u824654880/domains/casavivadecuba.com/public_html`
- SSH disponible por Hostinger;
- no se detectó staging dedicado por filesystem;
- el sitio se considera prototipo/no operativo, por lo que no se exige staging separado para esta fase.

## Decisión actualizada para 6B

Dado que `casavivadecuba.com` es todavía un prototipo y no procesa operación real, 6B se simplifica a un despliegue manualmente autorizado y auditable sobre ese prototipo.

La secuencia queda:

`main verde`
→ `release reproducible`
→ `checksum local`
→ `SSH con GitHub Secrets`
→ `copia del ZIP`
→ `backup inmediato de la carpeta actual del plugin`
→ `reemplazo únicamente de casa-viva-dropship-core`
→ `verificación de plugin/version`
→ `registro de SHA desplegado`
→ `smoke HTTP/REST/privacidad`
→ `rollback automático de la carpeta anterior si falla`

No se modifica base de datos deliberadamente como parte del mecanismo de deploy y no se despliega el repositorio completo.

## Secrets requeridos para el workflow de prototipo

Los valores deben configurarse en GitHub Actions Secrets y nunca escribirse en el repositorio:

- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SSH_USER`
- `HOSTINGER_SSH_PRIVATE_KEY`

## Garantías del deploy

El workflow `.github/workflows/deploy-prototype.yml`:

- solo se ejecuta manualmente (`workflow_dispatch`);
- exige un `expected_sha` completo;
- comprueba que ese SHA pertenece a `main`;
- exige un `Validar aplicación` post-merge exitoso para ese SHA;
- reconstruye la release desde ese SHA;
- valida `SHA256SUMS` antes de copiar;
- conserva la carpeta previa del plugin con timestamp;
- escribe `.cvd-deployed-sha` y `.cvd-deployed-archive-sha256` dentro del plugin desplegado;
- ejecuta smoke tests contra `https://casavivadecuba.com`;
- restaura automáticamente la carpeta previa si falla la verificación o el smoke.

## Frontera de autorización

Que el sitio sea un prototipo permite omitir staging separado, pero no convierte el deploy en automático. El workflow de prototipo se ejecuta únicamente mediante `workflow_dispatch` y la primera configuración de la clave SSH/secrets requiere una acción explícita del propietario del repositorio. Una vez configurados, cada ejecución seguirá siendo deliberada y trazable por SHA.

## 6C y producción futura

Cuando Casa Viva pase de prototipo a operación real, volverán a ser obligatorios:

- backup fresco previo a deploy;
- staging o estrategia equivalente de preproducción;
- rollback probado incluyendo cambios de base de datos cuando existan migraciones;
- autorización explícita de producción.

La simplificación de 6B no debe interpretarse como política permanente de producción.
