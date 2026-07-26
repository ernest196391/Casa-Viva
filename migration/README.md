# Migración del catálogo de Casa Viva

Esta carpeta conserva una copia de solo lectura de los productos publicados en
BizneCubano y prepara un CSV inicial para el importador nativo de WooCommerce.

## Archivos

- `biznecubano-products-2026-07-25.json`: copia estructurada de la pantalla de
  inventario masivo.
- `biznecubano-products-2026-07-25.csv`: la misma copia en formato tabular.
- `build-woocommerce-import.mjs`: transforma la copia estructurada sin alterar
  el origen.
- `woocommerce-products-2026-07-25.csv`: borrador de importación para
  WooCommerce.

## Estado de los datos

- 159 productos publicados y 159 identificadores únicos.
- 119 productos con precio; 40 requieren revisión.
- 110 productos con cantidad controlada; 49 aparecen sin cantidad.
- 159 productos con imagen de portada.
- 7 grupos de nombres repetidos que deben revisarse antes de publicar.

Los campos vacíos se conservan vacíos. En el borrador de WooCommerce, un
producto sin cantidad se marca como inventario no gestionado; esto evita
convertir un dato ausente en una existencia de cero.

## Imágenes

Las URLs extraídas apuntan a las portadas pequeñas que BizneCubano sirve
actualmente en JPEG. Son referencias temporales, no el banco final de imágenes.
Antes de publicar la nueva tienda se debe:

1. recibir la carpeta original de imágenes;
2. relacionar cada archivo con su identificador de producto;
3. recortar a una proporción consistente sin deformar;
4. exportar en WebP con peso y dimensiones definidos;
5. importar las URLs definitivas de la biblioteca de WordPress.

## Regenerar el CSV de WooCommerce

```bash
node migration/build-woocommerce-import.mjs
```

No se debe importar en producción hasta completar categorías, descripciones,
moneda, precios faltantes y la revisión de nombres duplicados.
