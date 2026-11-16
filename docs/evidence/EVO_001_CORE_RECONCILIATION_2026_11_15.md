# EVO-001 — reconciliación del Core y certificación del servicio de imagen
15-nov-2026, 22:55–23:10 Cuba. Sesión con SSH propio (`vps`, `web`). Continúa [EVO_001_PRODUCTION_PREFLIGHT_2026_11_15.md](EVO_001_PRODUCTION_PREFLIGHT_2026_11_15.md).

## Qué se comprobó (todo en vivo)
| Comprobación | Resultado |
|---|---|
| VivaBot VPS | `3cbdeca` = main, limpio; bot online, 0 reinicios/h |
| `casaviva-imagen` antes | PID 2429316, arrancado 14-nov 06:44, anterior al merge (19:02Z del 15-nov): código viejo en memoria |
| Hashes Core (sin CR) vs `a5551fd` | 4 distintos (`casa-viva-dropship-core.php`, `content-studio`, `panel-bridge`, `photo-ai`) + 2 ausentes (`content-policy`, `dudas-catalogo`); `bot-bridge`, `cadena-mensajeros`, `palla-api`, `web-push` idénticos salvo saltos de línea |
| Contenido distinto | main: faltan 2 `require_once`; photo-ai: sin guardia `CVD_Content_Policy::billable()`; content-studio: versión anterior (482 vs 1352 líneas; conserva rutas `contenido/*`, `gestora/estudio/*`, `gestora/taller/texto`); panel-bridge: **3 líneas vivas con `clone` que no existen en ninguna rama** |

## Qué se hizo
1. Copias y estado previo (ver checkpoint). 2. `casaviva-imagen` reiniciado; 401 / 403 / `/recorte` 400 verificados con la clave del `.env` sin imprimirla. 3. PR #233 (`c9bc6e9`): arreglo `clone` + versión 3.14.172; CI completa verde. 4. Archivos subidos primero a `~/evo-stage-20261115`, `php -l` (PHP 8.2.35) y hashes OK; luego copiados al plugin; purge. 5. Smoke: ver checkpoint.

## Límites (no certificado)
- No hay prueba de navegador sobre producción; la de navegador/integración es de CI sobre la rama.
- No se invocó ningún proveedor de IA ni recorte de pago; `/panel` con clave válida devolvió 403 antes de generar.
- Credencial GitHub del mecanismo de release: sin verificar por esta sesión.
- #233 queda sin fusionar: producción ya tiene sus bytes, `main` aún no.
