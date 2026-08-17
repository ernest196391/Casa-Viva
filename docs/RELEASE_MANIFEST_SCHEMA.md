# Contrato del manifest de release

El archivo `release-manifest.json` identifica de forma inequívoca un candidato desplegable de Casa Viva.

Campos obligatorios:

- `schema`: versión del esquema del manifest;
- `repository`: repositorio fuente;
- `source_sha`: commit exacto validado de `main`;
- `source_ref`: debe ser `main`;
- `component`: componente desplegable;
- `plugin_version`: versión declarada por el plugin;
- `archive`: nombre exacto del ZIP;
- `archive_sha256`: checksum SHA-256 del ZIP.

Un consumidor de este artefacto debe rechazarlo si:

- el checksum no coincide;
- `source_ref` no es `main`;
- el SHA no coincide con el candidato aprobado;
- el componente no es `casa-viva-dropship-core`;
- el ZIP esperado no está presente.

Las fases 6B–6D deben reutilizar este manifest en lugar de inferir versión o commit desde nombres de archivo o desde el estado actual del repositorio.
