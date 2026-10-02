// Instantánea de solo lectura del catálogo público de Casa Viva en BizneCubano
// y del catálogo WooCommerce público de casavivadecuba.com (Store API).
// No inicia sesión, no añade al carrito y no envía formularios.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const SOURCE = (process.env.SOURCE_URL || 'https://casaviva.biznecubano.com').replace(/\/$/, '');
const TARGET = (process.env.TARGET_URL || 'https://casavivadecuba.com').replace(/\/$/, '');
const OUT = process.env.OUT_DIR || 'catalog-snapshot';
const MAX_PAGES = Number(process.env.MAX_PAGES || 30);
const DISCOVERY = process.env.DISCOVERY === '1';
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 390, height: 844 },
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 CasaVivaCatalogSync/1.0',
  locale: 'es-ES',
});
const page = await context.newPage();

const apiLog = [];
page.on('response', async (r) => {
  const type = r.headers()['content-type'] || '';
  if (!/json/.test(type)) return;
  try {
    const body = await r.text();
    apiLog.push({ url: r.url(), status: r.status(), body: body.slice(0, DISCOVERY ? 20000 : 4000) });
  } catch { /* cuerpo no disponible */ }
});

// 1. Enlaces de producto desde el listado paginado.
const productUrls = new Set();
for (let n = 1; n <= MAX_PAGES; n++) {
  await page.goto(`${SOURCE}/?page=${n}`, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
  const links = await page.$$eval('a[href*="/p/"]', (as) => as.map((a) => a.href.split(/[?#]/)[0]));
  const before = productUrls.size;
  links.forEach((l) => productUrls.add(l));
  console.log(`listado página ${n}: ${links.length} enlaces, ${productUrls.size - before} nuevos`);
  if (productUrls.size === before) break;
}

// 2. Detalle de cada producto ya renderizado por JavaScript.
const products = [];
const urls = [...productUrls].slice(0, DISCOVERY ? 3 : undefined);
for (const url of urls) {
  apiLog.length = 0;
  await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
  await page.waitForFunction(() => !/Cargando información/i.test(document.body.innerText), null, { timeout: 15000 }).catch(() => {});
  const data = await page.evaluate(() => {
    const text = (el) => (el ? el.innerText.replace(/\s+\n/g, '\n').trim() : '');
    const imgs = [...document.querySelectorAll('img')]
      .map((i) => i.currentSrc || i.src)
      .filter((s) => /\/products\//.test(s));
    const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent);
    const metas = Object.fromEntries([...document.querySelectorAll('meta[property^="og:"], meta[name="description"], meta[property^="product:"]')]
      .map((m) => [m.getAttribute('property') || m.getAttribute('name'), m.content]));
    return {
      title: text(document.querySelector('h1')),
      images: [...new Set(imgs)],
      breadcrumbs: [...document.querySelectorAll('a[href*="/c/"]')].map((a) => ({ text: a.innerText.trim(), href: a.href })),
      metas,
      ld,
      body_text: document.body.innerText.slice(0, 6000),
    };
  });
  const entry = { url, code: url.split('/p/')[1], ...data };
  if (DISCOVERY) entry.api = apiLog.map((a) => ({ ...a }));
  products.push(entry);
  console.log(`producto ${products.length}/${urls.length}: ${entry.title}`);
}
fs.writeFileSync(path.join(OUT, 'biznecubano.json'), JSON.stringify({ source: SOURCE, generated_at: new Date().toISOString(), count: products.length, products }, null, 2));

// 3. Catálogo WooCommerce público actual (Store API, solo lectura).
const woo = [];
for (let n = 1; n <= 50; n++) {
  const res = await context.request.get(`${TARGET}/wp-json/wc/store/v1/products?per_page=100&page=${n}`);
  if (!res.ok()) { console.log(`woo página ${n}: HTTP ${res.status()}`); break; }
  const items = await res.json();
  woo.push(...items.map((p) => ({
    id: p.id, name: p.name, slug: p.slug, sku: p.sku, permalink: p.permalink,
    price: p.prices?.price, regular_price: p.prices?.regular_price, sale_price: p.prices?.sale_price,
    currency: p.prices?.currency_code, minor_unit: p.prices?.currency_minor_unit,
    in_stock: p.is_in_stock, categories: (p.categories || []).map((c) => c.name),
    images: (p.images || []).map((i) => i.src), short_description: p.short_description,
  })));
  if (items.length < 100) break;
}
fs.writeFileSync(path.join(OUT, 'woocommerce.json'), JSON.stringify({ target: TARGET, generated_at: new Date().toISOString(), count: woo.length, products: woo }, null, 2));
console.log(`BizneCubano: ${products.length} productos · WooCommerce: ${woo.length} productos`);
await browser.close();
