/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const isSave = (request) => request.method() === 'POST' && decodeURIComponent(request.url()).includes('/contenido/save');
async function openPanel(page) {
  await page.setViewportSize({ width: 390, height: 844 });
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto('http://localhost:8889/wp-login.php');
  await page.locator('#user_login').fill('cvt_content_browser');
  await page.locator('#user_pass').fill('Synthetic-Content-Only-1!');
  await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
  await page.goto('http://localhost:8889/?cvd_contenido=1');
  await expect(page.getByRole('heading', { name: 'Mi catálogo y fotos', exact: true })).toBeVisible();
}
// Holds the first save response until release(); held resolves with the server's answer.
async function holdFirstSave(page) {
  let release, answered;
  const gate = new Promise((resolve) => { release = resolve; }), held = new Promise((resolve) => { answered = resolve; });
  let first = true;
  await page.route('**/*', async (route) => {
    if (!first || !isSave(route.request())) return route.continue();
    first = false;
    const response = await route.fetch(); answered(await response.json());
    await gate; await route.fulfill({ response });
  });
  return { release, held };
}
const readProduct = (page, id) => page.evaluate(async (id) => {
  const url = new URL(cvContent.base + 'products', location.href); url.searchParams.set('id', id);
  return (await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cvContent.nonce } })).json();
}, id);
const saveNow = async (page) => (await Promise.all([page.waitForResponse((r) => isSave(r.request())), page.getByRole('button', { name: 'Guardar cambios', exact: true }).click()]))[0].json();

test('Guardado tardío de una ficha publicada no pisa la mercancía nueva', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio race fixture 1 · Publicado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  const { release, held } = await holdFirstSave(page);
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Ficha A guardada tarde');
  await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
  const a = await held;
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Ficha B nueva');
  release();
  await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeEnabled();
  await expect.soft(page.locator('#cv-commercial')).toHaveText('Nueva mercancía · borrador');
  const b = await saveNow(page);
  expect(b.name).toBe('Ficha B nueva'); expect(b.status).toBe('draft'); expect(b.id).not.toBe(a.id);
  const after = await readProduct(page, a.id);
  expect(after.name).toBe('Ficha A guardada tarde'); expect(after.status).toBe('publish');
});

test('Guardado tardío de mercancía nueva no pisa la ficha publicada abierta después', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.locator('#cv-new').click();
  const { release, held } = await holdFirstSave(page);
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Ficha nueva guardada tarde');
  await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
  const fresh = await held;
  await page.getByRole('button', { name: 'Studio race fixture 2 · Publicado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Ficha publicada editada');
  release();
  await expect(page.getByRole('button', { name: 'Guardar cambios', exact: true })).toBeEnabled();
  const published = await saveNow(page);
  expect(published.id).not.toBe(fresh.id); expect(published.name).toBe('Ficha publicada editada'); expect(published.status).toBe('publish');
  const after = await readProduct(page, fresh.id);
  expect(after.name).toBe('Ficha nueva guardada tarde'); expect(after.status).toBe('draft');
});

test('Editor de contenido: login, guardar, subir foto y descargar en móvil', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('http://localhost:8889/wp-login.php');
  await page.locator('#user_login').fill('cvt_content_browser');
  await page.locator('#user_pass').fill('Synthetic-Content-Only-1!');
  await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
  await page.goto('http://localhost:8889/?cvd_contenido=1');
  await expect(page.getByRole('heading', { name: 'Mi catálogo y fotos', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Studio browser fixture · Publicado', exact: true }).click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Studio browser edited');
  await page.getByRole('button', { name: 'Guardar cambios', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Cambios publicados en Casa Viva.');
  const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=', 'base64');
  await page.locator('#cv-photo').setInputFiles({ name: 'fixture.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  await page.getByRole('button', { name: 'Subir como foto principal', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Foto subida y protegida.');
  await expect(page.locator('#cv-commercial')).toContainText('25');
  await page.getByRole('textbox', { name: 'Características confirmadas (una por línea, hasta tres)', exact: true }).fill('Dato sintético de prueba');
  await page.getByRole('button', { name: 'Preparar imagen', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Descargar imagen', exact: true })).toBeVisible();
  const dimensions = await page.locator('#cv-canvas').evaluate((canvas) => [canvas.width, canvas.height]);
  expect(dimensions).toEqual([1080, 1920]);
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Descargar imagen', exact: true }).click()]);
  const bytes = fs.readFileSync(await download.path());
  expect(bytes[0]).toBe(255); expect(bytes[1]).toBe(216); expect(bytes.length).toBeGreaterThan(1000);
  await page.getByRole('button', { name: 'Preparar textos para redes', exact: true }).click();
  await expect(page.getByRole('textbox', { name: 'Texto para compartir', exact: true })).toHaveValue(/Studio browser edited/);
  const width = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.viewport);
  await page.locator('summary').filter({ hasText: 'Cambiar mi contraseña' }).click();
  await page.locator('#cv-current-password').fill('Synthetic-Content-Only-1!');
  await page.locator('#cv-next-password').fill('Synthetic-Content-Changed-2!');
  await page.getByRole('button', { name: 'Guardar contraseña y volver a entrar', exact: true }).click();
  await expect(page).toHaveURL(/wp-login\.php/);
});

test('El fondo cambia y se conservan las piezas negras, claras y los huecos cerrados', async ({ page }) => {
  await page.goto('about:blank');
  await page.addScriptTag({path:'wordpress/casa-viva-dropship-core/assets/product-background.js'});
  const actual = await page.evaluate(() => {
    const source = document.createElement('canvas'); source.width = 100; source.height = 100;
    const ctx = source.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0,0,100,100);
    for (const x of [5,35]) { ctx.fillStyle = '#111'; ctx.fillRect(x,10,14,14); ctx.fillStyle='#fff'; ctx.fillRect(x+4,14,6,6); }
    ctx.fillStyle='#ebebeb'; ctx.fillRect(70,65,3,6);
    const bg = document.createElement('canvas'); bg.width=1;bg.height=1;
    const b=bg.getContext('2d');b.fillStyle='#ccbb99';b.fillRect(0,0,1,1);
    const composed = window.cvProductBackground(source,bg,true).getContext('2d');
    return [[5,10],[35,10],[10,16],[70,65],[50,50]].map(([x,y]) => Array.from(composed.getImageData(238+x,462+y,1,1).data).slice(0,3));
  });
  expect(actual).toEqual([[17,17,17],[17,17,17],[255,255,255],[235,235,235],[204,187,153]]);
});
