# Bloque 06 / Fase 6B — Staging y smoke tests

## Objetivo

Promover una release candidata validada a un entorno no productivo y bloquear producción si las comprobaciones mínimas reales fallan.

## Parte preparada en GitHub

- `scripts/smoke-staging.sh` ejecuta comprobaciones HTTP contra una URL de staging.
- `.github/workflows/staging-smoke.yml` permite ejecutar manualmente el smoke contra un SHA exacto de `main`.
- el workflow verifica que el SHA solicitado pertenece a `main` antes de ejecutar pruebas.
- el smoke exige HTTPS salvo ejecución local explícitamente aislada.
- comprueba que la portada responde sin error fatal visible.
- comprueba que el índice REST de WordPress responde.
- comprueba que el namespace `casa-viva/v1` está registrado.
- comprueba que una ruta operativa protegida existe pero no expone datos a una petición anónima (`401/403`, nunca `200`).
- no contiene credenciales ni secretos de Hostinger/WordPress.

## Identidad de la release

El workflow recibe `expected_sha`, pero una respuesta HTTP pública no es evidencia suficiente de qué ZIP está instalado.

Antes de declarar 6B cerrada, el staging real debe demostrar que el artefacto instalado corresponde al mismo `source_sha` y checksum de `release-manifest.json` generado por 6A. Esa verificación debe hacerse mediante el mecanismo seguro disponible en Hostinger (por ejemplo, filesystem/WP-CLI/deployment metadata) después de auditar el hosting real.

No se añadirá un endpoint público con información administrativa únicamente para facilitar esta comprobación.

## Evidencia pendiente que requiere Hostinger/WordPress real

1. identificar un entorno de staging aislado o crear uno mediante la capacidad soportada por el plan;
2. confirmar que no opera sobre pedidos/clientes productivos de forma peligrosa;
3. desplegar en staging una release candidata de 6A;
4. verificar artefacto/SHA/checksum instalados;
5. ejecutar `Smoke staging Casa Viva` contra ese entorno;
6. conservar el run verde como evidencia;
7. confirmar que un smoke rojo bloquea cualquier promoción a producción.

## Regla de cierre

6B permanece **PARCIAL** mientras no exista evidencia de una instalación real en staging y de un smoke verde contra ella.

No se autoriza ningún cambio en producción desde esta fase.
