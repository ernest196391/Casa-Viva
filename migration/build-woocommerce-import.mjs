import { readFile, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const currentDirectory = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
  currentDirectory,
  "biznecubano-products-2026-07-25.json",
);
const outputPath = join(
  currentDirectory,
  "woocommerce-products-2026-07-25.csv",
);

const source = JSON.parse(await readFile(sourcePath, "utf8"));

const columns = [
  "Type",
  "SKU",
  "Name",
  "Published",
  "Is featured?",
  "Visibility in catalog",
  "Regular price",
  "Sale price",
  "Manage stock?",
  "Stock",
  "In stock?",
  "Images",
  "Meta: _cvd_source",
  "Meta: _cvd_source_product_id",
];

function csvCell(value) {
  const text = String(value ?? "");
  return /[",\n\r]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

const rows = source.products.map((product) => {
  const hasManagedStock = product.stock_quantity !== "";
  const numericStock = Number(product.stock_quantity);

  return {
    Type: "simple",
    SKU: `CV-BIZ-${product.source_product_id}`,
    Name: product.name,
    Published: 1,
    "Is featured?": 0,
    "Visibility in catalog": "visible",
    "Regular price": product.regular_price,
    "Sale price": product.sale_price,
    "Manage stock?": hasManagedStock ? 1 : 0,
    Stock: hasManagedStock ? product.stock_quantity : "",
    "In stock?": hasManagedStock && numericStock <= 0 ? 0 : 1,
    Images: product.image_url,
    "Meta: _cvd_source": product.source,
    "Meta: _cvd_source_product_id": product.source_product_id,
  };
});

const csv = [
  columns.join(","),
  ...rows.map((row) => columns.map((column) => csvCell(row[column])).join(",")),
].join("\n");

await writeFile(outputPath, `${csv}\n`, "utf8");

console.log(`Created ${outputPath} with ${rows.length} products.`);
