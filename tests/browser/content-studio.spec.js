/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
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
