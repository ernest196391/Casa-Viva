# Proceso de release de Casa Viva

## Principio

Validar y desplegar son operaciones separadas.

La cadena autorizada es:

`PR validado → main validado → release candidato → staging → deploy autorizado`

La Fase 6A solo cubre hasta `release candidato`. No despliega a Hostinger ni modifica producción.

## Unidad desplegable

La unidad desplegable actual es el plugin WordPress:

`wordpress/casa-viva-dropship-core`

No se empaqueta el repositorio completo ni los entornos Docker de prueba.

## Release candidato

El workflow `.github/workflows/release-candidate.yml` se ejecuta manualmente.

Antes de empaquetar:

1. hace checkout de `main`;
2. captura el SHA exacto;
3. exige que exista un run post-merge exitoso de `Validar aplicación` para ese SHA;
4. construye el ZIP exclusivamente desde el árbol Git de ese commit;
5. genera `release-manifest.json`;
6. verifica `SHA256SUMS`;
7. publica los tres archivos como artefacto temporal de GitHub Actions.

## Manifest

El manifest identifica como mínimo:

- repositorio;
- SHA fuente;
- ref fuente (`main`);
- componente;
- versión del plugin;
- nombre del archivo ZIP;
- SHA-256 del ZIP.

El propio manifest también queda incluido en `SHA256SUMS`.

## Reproducibilidad

`scripts/build-release-candidate.sh` usa `git archive` sobre el SHA exacto. No copia el working tree ni archivos no versionados.

La construcción se bloquea si se intenta ejecutar desde una ref distinta de `main`.

## Seguridad

- 6A no contiene credenciales de Hostinger.
- 6A no toca staging ni producción.
- `github.token` se usa únicamente con permiso `actions: read` para comprobar el CI del SHA.
- ningún secreto de despliegue se guarda en el repositorio.

## Siguiente frontera

6B debe consumir exactamente el artefacto identificado por este manifest y probarlo en staging antes de cualquier promoción a producción.
