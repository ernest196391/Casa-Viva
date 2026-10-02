// Auditoría de fotos del catálogo (solo lectura): descarga la foto principal de cada
// producto de la última instantánea de BizneCubano, mide resolución y genera hojas de
// contacto numeradas para revisar a ojo qué fotos no se ven profesionales.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const SNAPSHOT = process.env.SNAPSHOT_FILE || 'biznecubano.json';
const OUT = process.env.OUT_DIR || 'catalog-photos';
const PER_SHEET = 24;
fs.mkdirSync(OUT, { recursive: true });

const products = JSON.parse(fs.readFileSync(SNAPSHOT, 'utf8')).products.filter((p) => p.image);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });

const rows = [];
for (const [i, p] of products.entries()) {
  const n = i + 1;
  const sku = (p.image.match(/\/products\/(\d+)\//) || [])[1] || p.code;
  try {
    const res = await page.request.get(p.image, { timeout: 30000 });
    const buf = await res.body();
    const ext = (p.image.split('?')[0].match(/\.(jpe?g|png|webp)$/i) || [, 'jpg'])[1];
    const file = `${String(n).padStart(3, '0')}-BC-${sku}.${ext}`;
    fs.writeFileSync(path.join(OUT, file), buf);
    rows.push({ n, sku: `BC-${sku}`, name: p.name, image: p.image, file, bytes: buf.length, images: (p.images || []).length });
  } catch (e) {
    rows.push({ n, sku: `BC-${sku}`, name: p.name, image: p.image, error: String(e).slice(0, 200) });
  }
}

// Medir dimensiones reales en el navegador.
for (const r of rows.filter((x) => x.file)) {
  const data = fs.readFileSync(path.join(OUT, r.file)).toString('base64');
  const mime = /png$/i.test(r.file) ? 'image/png' : /webp$/i.test(r.file) ? 'image/webp' : 'image/jpeg';
  Object.assign(r, await page.evaluate(async (src) => {
    const img = new Image();
    img.src = src;
    await img.decode().catch(() => {});
    return { width: img.naturalWidth, height: img.naturalHeight };
  }, `data:${mime};base64,${data}`));
}

// Hojas de contacto numeradas.
const ok = rows.filter((r) => r.file);
for (let s = 0; s < ok.length; s += PER_SHEET) {
  const chunk = ok.slice(s, s + PER_SHEET);
  const tiles = chunk.map((r) => `<figure><img src="${r.file}"><figcaption><b>${r.n}</b> ${r.width}×${r.height} · ${r.name.replace(/</g, '')}</figcaption></figure>`).join('');
  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;font:12px sans-serif;background:#fff}main{display:grid;grid-template-columns:repeat(6,200px);gap:6px;padding:6px}figure{margin:0}img{width:200px;height:200px;object-fit:contain;background:#eee;display:block}figcaption{height:30px;overflow:hidden}</style><main>${tiles}</main>`;
  const sheet = path.join(OUT, `sheet-${String(s / PER_SHEET + 1).padStart(2, '0')}.html`);
  fs.writeFileSync(sheet, html);
  await page.goto('file://' + path.resolve(sheet));
  await page.waitForLoadState('load');
  await page.screenshot({ path: sheet.replace(/\.html$/, '.png'), fullPage: true });
  fs.unlinkSync(sheet);
}

fs.writeFileSync(path.join(OUT, 'photos.json'), JSON.stringify({ generated_at: new Date().toISOString(), count: rows.length, photos: rows }, null, 2));
console.log(`Fotos: ${ok.length}/${rows.length}; hojas: ${Math.ceil(ok.length / PER_SHEET)}`);
await browser.close();
