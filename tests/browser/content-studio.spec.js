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

test('Una opción guardada antes de cambiar la ficha se ve con aviso y no se usa sin compararla', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio stale review fixture · Borrador / privado', exact: true }).click();
  const card = page.locator('#cv-variants article');
  await expect(card).toContainText('Desde entonces cambió la foto original y el texto de la ficha. Compárala con los datos actuales antes de usarla.');
  const choose = card.getByRole('button', { name: 'Elegir opción 1 como foto principal', exact: true });
  const use = card.getByRole('button', { name: 'Usar opción 1 en la plantilla', exact: true });
  await expect(choose).toBeDisabled(); await expect(use).toBeDisabled();
  await expect(card.getByRole('link', { name: 'Descargar esta opción', exact: true })).toBeVisible();
  await card.getByRole('checkbox', { name: 'Ya la comparé con la ficha actual' }).check();
  await expect(choose).toBeEnabled(); await expect(use).toBeEnabled();
});

test('El TXT trae título, descripción y texto para redes; la instrucción para IA solo si se marca', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  let saves = 0; page.on('request', (request) => { if (isSave(request)) saves++; });
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Lámpara TXT');
  await page.locator('#cv-description').fill('<p>Base de metal confirmada.</p><p>Luz cálida.</p>');
  await page.locator('#cv-facts').fill('Metal confirmado');
  await page.locator('#cv-use').fill('Para la mesa de noche');
  await page.getByRole('button', { name: 'Preparar textos para redes', exact: true }).click();
  const download = async () => {
    const [file] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descargar textos', exact: true }).click()]);
    return fs.readFileSync(await file.path(), 'utf8');
  };
  expect(await download()).toBe('TÍTULO\nLámpara TXT\n\nDESCRIPCIÓN\nBase de metal confirmada.\nLuz cálida.\n\nTEXTO PARA REDES\nLámpara TXT\n• Metal confirmado\nPara la mesa de noche\nConsulta en Casa Viva.\n');
  await page.getByRole('checkbox', { name: 'Incluir la instrucción para IA en el archivo' }).check();
  const withPrompt = await download();
  expect(withPrompt).toContain('\n\nINSTRUCCIÓN PARA IA\nCrea una imagen comercial para Casa Viva');
  expect(saves).toBe(0);
});

test('Características señaladas: línea fina de cada punto a su explicación', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Diagrama de prueba');
  await page.locator('#cv-facts').fill('Metal resistente — aguanta cortinas pesadas\nExtensible — sirve para ventanas grandes');
  const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=', 'base64');
  await page.locator('#cv-photo').setInputFiles({ name: 'diagrama.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  await page.locator('#cv-layout').selectOption('features'); await page.locator('#cv-format').selectOption('1920');
  await page.getByRole('button', { name: 'Preparar imagen', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('número 1');
  const canvas = page.locator('#cv-canvas');
  const tap = async (x, y, next) => {
    const b = await canvas.boundingBox();
    await canvas.click({ position: { x: x * b.width / 1080, y: y * b.height / 1920 } });
    await expect(page.getByRole('status')).toContainText(`número ${next}`);
  };
  await tap(300, 800, 2); await tap(800, 900, 1);
  // Point 1 is on the left: its line runs down the first left lane; point 2 down the second right lane.
  const colors = await canvas.evaluate((c) => [[24, 1000], [1042, 1100], [540, 330]].map(([x, y]) => Array.from(c.getContext('2d').getImageData(x, y, 1, 1).data).slice(0, 3)));
  expect(colors).toEqual([[23, 62, 53], [23, 62, 53], [246, 242, 233]]);
  await expect(page.getByRole('link', { name: 'Descargar imagen', exact: true })).toBeVisible();
});

test('Descripción y resumen en texto normal, sin código, y sin la barra de la tienda', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await expect(page.locator('.cvd-customer-nav')).toHaveCount(0);
  await page.getByRole('button', { name: 'Studio text fixture · Borrador / privado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  await expect(page.locator('#cv-description')).toHaveValue('Un cortinero para vestir la ventana.\n\nCaracterísticas:\n\n• Metal negro\n• Ligero');
  await expect(page.locator('#cv-short')).toHaveValue('Corto.');
  await expect(page.locator('#cv-text-note')).toBeHidden();
  await page.locator('#cv-description').fill('Un cortinero para vestir la ventana.\n\nCaracterísticas:\n• Metal negro\n• Ligero\n\nNuevo párrafo <b>');
  const saved = await saveNow(page);
  expect(saved.description).toBe('<p>Un cortinero para vestir la ventana.</p><h3>Características</h3><ul><li>Metal negro</li><li>Ligero</li></ul><p>Nuevo párrafo &lt;b&gt;</p>');
  expect(saved.short).toBe('<p>Corto.</p>');
  // Formatting the plain editor cannot keep is shown as HTML, with a note, and saved untouched.
  await page.getByRole('button', { name: 'Studio rich text fixture · Borrador / privado', exact: true }).click();
  await expect(page.locator('#cv-description')).toHaveValue('<p>Con <strong>negrita</strong>.</p>');
  await expect(page.locator('#cv-text-note')).toBeVisible();
  const rich = await saveNow(page);
  expect(rich.description).toBe('<p>Con <strong>negrita</strong>.</p>');
});

test('La entrada lleva la marca Casa Viva y el aviso del panel no tapa la pantalla', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await page.goto('http://localhost:8889/wp-login.php');
  await expect(page.locator('.login h1 a')).toHaveText('Casa Viva');
  await expect(page.locator('.login h1 a')).toHaveCSS('background-image', /logo-casa-viva-crema\.svg/);
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(23, 62, 53)');
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio text fixture · Borrador / privado', exact: true }).click();
  const notice = page.getByRole('status');
  await expect(notice).toHaveText('Producto abierto.');
  await expect(notice).toHaveCSS('position', 'fixed');
  await notice.click();
  await expect(notice).toBeHidden();
  await page.getByRole('button', { name: 'Studio text fixture · Borrador / privado', exact: true }).click();
  await expect(notice).toHaveText('Producto abierto.');
  await expect(notice).toBeHidden({ timeout: 15000 });
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
  const [uploaded] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && decodeURIComponent(r.url()).includes('/contenido/upload')),
    page.getByRole('button', { name: 'Subir como foto principal', exact: true }).click(),
  ]);
  await expect(page.getByRole('status')).toContainText('Foto subida y protegida.');
  // The web copy is converted to WebP in the browser before it is sent.
  expect((await uploaded.json()).image_full).toMatch(/\.webp$/);
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
    const vertical = window.cvProductBackground(source,bg,true), square = window.cvProductBackground(source,bg,false);
    const composed = vertical.getContext('2d');
    return { sizes: [[vertical.width, vertical.height], [square.width, square.height]],
      pixels: [[5,10],[35,10],[10,16],[70,65],[50,50]].map(([x,y]) => Array.from(composed.getImageData(490+x,910+y,1,1).data).slice(0,3)) };
  });
  // Composed at social size so templates and downloads are not upscaled.
  expect(actual.sizes).toEqual([[1080, 1920], [1080, 1080]]);
  expect(actual.pixels).toEqual([[17,17,17],[17,17,17],[255,255,255],[235,235,235],[204,187,153]]);
});
