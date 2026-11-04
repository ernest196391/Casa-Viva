# Triciclub · calles y lugares de La Habana (4-nov-2026)

- `construir_v2.py`: del .osm.pbf de Geofabrik → `habana-calles-v2.json` (mismo formato que v1 + d, g, c, xs, alt, par)
  y `habana-lugares.json`. Instrucciones arriba del archivo. Necesita `osmium-tool`, `python3-pyosmium`, `python3-shapely`.
- `prueba/`: banco de pruebas antes/después en PHP de consola con las clases reales del Core y el OSRM del VPS.
  `generar_casos.py` → `casos*.json`; `comparar.py` → `tabla*.md`. Resultados del 4-nov en `tabla*.md`.
- Los JSON van a `wp-content/uploads/cvd-geo/` (fuera del plugin). `habana-calles-v2.json` se sube con el nombre
  `habana-calles.json`.
- Para comparar con «antes» hacen falta en `prueba/`: `antes/cvd-geo/habana-calles.json` (v1), `despues/cvd-geo/` (v2 + lugares) y `geo-antes.php`, `direccion-antes.php`, `api-antes.php` (`git show origin/main:…`). En el VPS está todo en `/root/triciclub-geo/`.
