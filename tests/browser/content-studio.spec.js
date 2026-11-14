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
// One save button in the bar: «Guardar cambios», «Guardar» or «Guardar borrador» depending on the product.
const saveNow = async (page) => (await Promise.all([page.waitForResponse((r) => isSave(r.request())), page.locator('#cv-save').click()]))[0].json();
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=', 'base64');
const tab = (page, name) => page.getByRole('tab', { name: new RegExp(`^${name}`) }).click();
const pick = (page, name) => page.getByRole('radio', { name, exact: true }).click();
const back = (page) => page.locator('#cv-back').click();
// v2: con cambios sin guardar sale la hoja «Cambios sin guardar» (antes era un confirm() del navegador).
const leaveUnsaved = async (page) => { await back(page); await page.getByRole('button', { name: 'Salir sin guardar', exact: true }).click(); };
// Characteristics: one row per item, «dato» and «para qué sirve».
async function fillFacts(page, rows) {
  for (const [i, [fact, benefit]] of rows.entries()) {
    await page.locator(`#cv-fact-${i + 1}`).fill(fact);
    if (benefit) await page.locator(`#cv-benefit-${i + 1}`).fill(benefit);
  }
}
const finish = (page) => page.getByRole('button', { name: 'Terminar anuncio', exact: true }).click();
// The notice can still show the previous «Imagen lista…»: wait for the sheet itself, then close it.
const pieSheet = (page) => page.getByRole('dialog', { name: 'Pie del anuncio' });
async function closePie(page) { await expect(pieSheet(page)).toBeVisible(); await page.keyboard.press('Escape'); await expect(pieSheet(page)).toBeHidden(); }

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
  await leaveUnsaved(page);
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Ficha B nueva');
  release();
  await expect(page.locator('#cv-save')).toBeEnabled();
  await expect.soft(page.locator('#cv-status')).toHaveText('Nuevo');
  await expect.soft(page.locator('#cv-commercial')).toHaveText('Pendiente');
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
  await page.getByRole('button', { name: 'Guardar borrador', exact: true }).click();
  const fresh = await held;
  await leaveUnsaved(page);
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
  await tab(page, 'Anuncio');
  await pick(page, 'Guardada');
  const panel = page.locator('#cv-option-panel');
  await expect(panel).toContainText('Desde entonces cambió la foto original y el texto de la ficha. Compárala con los datos actuales antes de usarla.');
  // Not used in the ad either: the preview says so and «Terminar anuncio» refuses it.
  await expect(page.locator('#cv-preview-note')).toHaveText('Compárala antes de usarla en el anuncio.');
  await finish(page);
  await expect(page.getByRole('status')).toHaveText('Compara la opción guardada con la ficha actual antes de usarla.');
  await expect(panel.getByRole('link', { name: 'Descargar sin textos', exact: true })).toBeVisible();
  await panel.getByRole('checkbox', { name: 'Ya la comparé con la ficha actual' }).check();
  // Now the option is the photo of the ad (the fixture has no duda → resultado → prueba yet).
  await expect(page.locator('#cv-preview-note')).toHaveText('Falta la duda → resultado → prueba: toca «Editar».');
  // A la web solo sube el HERO aprobado: en Anuncio ya no hay «Poner como foto de la web» (condición B4).
  await expect(page.getByRole('button', { name: 'Poner como foto de la web' })).toHaveCount(0);
});

test('El TXT trae título, descripción y texto para redes; la instrucción para IA solo si se pide', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  let saves = 0; page.on('request', (request) => { if (isSave(request)) saves++; });
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Lámpara TXT');
  await page.locator('#cv-description').fill('<p>Base de metal confirmada.</p><p>Luz cálida.</p>');
  await fillFacts(page, [['Metal confirmado']]);
  await tab(page, 'Anuncio');
  const download = async (button) => {
    const [file] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: button, exact: true }).click()]);
    return fs.readFileSync(await file.path(), 'utf8');
  };
  await page.getByRole('button', { name: 'Más opciones', exact: true }).click();
  expect(await download('Descargar textos (.txt)')).toBe('TÍTULO\nLámpara TXT\n\nDESCRIPCIÓN\nBase de metal confirmada.\nLuz cálida.\n\nTEXTO PARA REDES\nLámpara TXT\nMetal confirmado\nEscríbenos por WhatsApp y te lo apartamos.\n');
  await page.getByRole('button', { name: 'Más opciones', exact: true }).click();
  await page.getByRole('button', { name: 'Instrucción para IA', exact: true }).click();
  await expect(page.locator('#cv-prompt')).toHaveValue(/^Crea una imagen comercial para Casa Viva/);
  const withPrompt = await download('Descargar textos con la instrucción (.txt)');
  expect(withPrompt).toContain('\n\nINSTRUCCIÓN PARA IA\nCrea una imagen comercial para Casa Viva');
  expect(saves).toBe(0);
});

test('Beneficio: punto 1 es la prueba, sin repetir datos, números por foto con «Listo», sin precio ni logo en 9:16 y 1:1', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(90000);
  await page.addInitScript(() => {
    window.__drawn = []; const fill = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (text, ...rest) { window.__drawn.push(String(text)); return fill.call(this, text, ...rest); };
  });
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio v2 HERO fixture · Publicado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  // «Antiadherente» ya es la prueba de la matriz: no sale dos veces.
  await fillFacts(page, [['Antiadherente', 'no se pega'], ['Mango largo', 'no quema']]);
  await tab(page, 'Anuncio');
  const hint = page.locator('#cv-point-hint'), canvas = page.locator('#cv-canvas');
  await expect(hint).toHaveText('Toca la foto: punto 1');
  await expect(page.locator('#cv-pts button')).toHaveCount(2);
  const tap = async (x, y) => { const b = await canvas.boundingBox(); await canvas.click({ position: { x: x * b.width, y: y * b.height } }); };
  await tap(0.4, 0.5); await expect(hint).toHaveText('Toca la foto: punto 2');
  await tap(0.6, 0.6); await expect(hint).toHaveText('Listo');
  // Otra foto: los números se borran; al volver, se recuperan.
  await page.getByRole('radio', { name: 'Real 1', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Foto nueva: coloca los 2 puntos otra vez.');
  await expect(hint).toHaveText('Toca la foto: punto 1');
  await page.getByRole('radio', { name: 'HERO', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Puntos de esta foto recuperados.');
  await expect(hint).toHaveText('Listo');
  for (const [format, size] of [['Historia 9:16', [1080, 1920]], ['Post 1:1', [1080, 1080]]]) {
    await pick(page, format);
    await page.evaluate(() => { window.__drawn = []; });
    await finish(page);
    await expect(pieSheet(page)).toBeVisible();
    expect(await canvas.evaluate((c) => [c.width, c.height])).toEqual(size);
    const drawn = await page.evaluate(() => window.__drawn);
    expect(drawn.join(' | ')).toContain('Huevo sin aceite');
    // Nunca precio ni logo en la imagen (tampoco en el lienzo de descarga).
    expect(drawn.join(' ')).not.toMatch(/40[.,]00|\$|CASA VIVA|VER EN CASA VIVA/i);
    // Puede haber más de un dibujo (vista previa y final): por cada titular, una sola «Antiadherente».
    expect(drawn.filter((t) => /Antiadherente/.test(t)).length).toBe(drawn.filter((t) => t.includes('Huevo sin aceite')).length);
    // Pie PROOF: 5 líneas con su origen; el precio va aquí, nunca en la imagen.
    await expect(page.locator('#cv-proof .cv-pl')).toHaveCount(5);
    await expect(page.locator('#cv-proof')).toContainText('Gancho · Matriz · resultado');
    await expect(page.locator('#cv-copy')).toHaveValue(/^Huevo sin aceite\nAntiadherente\. Fuego medio\.\nPrecio: .*40\.00\n¿Se pega\? Antiadherente\.\nEscríbenos por WhatsApp y te lo apartamos\.$/);
    await closePie(page);
  }
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
  await back(page);
  await page.getByRole('button', { name: 'Studio rich text fixture · Borrador / privado', exact: true }).click();
  // v2: la negrita (preguntas frecuentes) se edita como texto normal, *así*, sin código.
  await expect(page.locator('#cv-description')).toHaveValue('Con *negrita*.');
  await expect(page.locator('#cv-text-note')).toBeHidden();
  const rich = await saveNow(page);
  expect(rich.description).toBe('<p>Con <strong>negrita</strong>.</p>');
  await page.locator('#cv-description').fill('Medidas:\n\n*¿Trae herrajes?* Sí, incluidos.');
  expect((await saveNow(page)).description).toBe('<h3>Medidas</h3><p><strong>¿Trae herrajes?</strong> Sí, incluidos.</p>');
  // Lo que el texto normal no puede guardar (un enlace) se ve como HTML, con aviso, y se guarda intacto.
  await back(page);
  await page.getByRole('button', { name: 'Studio link text fixture · Borrador / privado', exact: true }).click();
  await expect(page.locator('#cv-description')).toHaveValue('<p>Ver <a href="https://example.com">enlace</a>.</p>');
  await expect(page.locator('#cv-text-note')).toBeVisible();
  expect((await saveNow(page)).description).toBe('<p>Ver <a href="https://example.com">enlace</a>.</p>');
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
  await back(page);
  await page.getByRole('button', { name: 'Studio text fixture · Borrador / privado', exact: true }).click();
  await expect(notice).toHaveText('Producto abierto.');
  await expect(notice).toBeHidden({ timeout: 15000 });
});

test('Tarjeta de oferta: precio de la tienda, rebaja real con fecha y oferta escrita por la editora', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  // Record what the card draws, to check the words without reading pixels.
  await page.addInitScript(() => {
    window.__drawn = []; const fill = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (text, ...rest) { window.__drawn.push(String(text)); return fill.call(this, text, ...rest); };
  });
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio offer fixture · Publicado', exact: true }).click();
  await expect(page.locator('#cv-offer')).toHaveValue('Entrega gratis en La Habana');
  await page.locator('#cv-photo').setInputFiles({ name: 'oferta.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  await tab(page, 'Anuncio');
  await pick(page, 'Oferta');
  for (const format of ['Historia 9:16', 'Post 1:1']) {
    await pick(page, format);
    await page.evaluate(() => { window.__drawn = []; });
    await finish(page);
    await expect(pieSheet(page)).toBeVisible();
    await expect(page.getByRole('status')).toHaveText('Imagen lista para descargar.');
    const drawn = (await page.evaluate(() => window.__drawn)).join(' | ');
    // Nunca un precio ni el logo en la imagen: el precio va en el pie (condición A2).
    expect(drawn).not.toMatch(/25\.00|30\.00|CASA VIVA/);
    expect(drawn).toContain('Oferta hasta el'); expect(drawn.replace(/ \| /g, ' ')).toContain('Entrega gratis en La Habana');
    await closePie(page);
  }
  await expect(page.locator('#cv-copy')).toHaveValue(/Precio de oferta: .*25\.00 \(antes .*30\.00\) · Oferta: Entrega gratis en La Habana/);
  await page.locator('#cv-offer').fill('Entrega gratis en Centro Habana');
  const saved = await saveNow(page);
  expect(saved.brief.offer).toBe('Entrega gratis en Centro Habana');
  // Without a store price there is no offer card.
  await back(page);
  await page.locator('#cv-new').click();
  await page.getByRole('textbox', { name: 'Título', exact: true }).fill('Sin precio');
  await page.locator('#cv-photo').setInputFiles({ name: 'oferta.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  await tab(page, 'Anuncio');
  await pick(page, 'Oferta');
  await finish(page);
  await expect(page.getByRole('status')).toHaveText('No hay oferta vigente: escribe la oferta real o pon la rebaja en la tienda.');
});

test('Tarjeta de reseña: solo reseñas reales guardadas con permiso, nombre con inicial y estrellas si las dio', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await page.addInitScript(() => {
    window.__drawn = []; const fill = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (text, ...rest) { window.__drawn.push(String(text)); return fill.call(this, text, ...rest); };
  });
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio rich text fixture · Borrador / privado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  await page.locator('#cv-photo').setInputFiles({ name: 'resena.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  await tab(page, 'Anuncio');
  await pick(page, 'Reseña');
  await expect(page.locator('#cv-review-pick')).toHaveText('Aún no hay reseñas reales');
  await finish(page);
  await expect(page.getByRole('status')).toHaveText('Elige o añade una reseña real del cliente.');
  await tab(page, 'Ficha');
  await page.getByRole('button', { name: 'Añadir reseña real', exact: true }).click();
  await page.locator('#cv-review-text').fill('Llegó rápido y quedó precioso en la sala.');
  await page.locator('#cv-review-name').fill('María González');
  await page.getByRole('button', { name: '5 estrellas', exact: true }).click();
  await page.getByRole('button', { name: 'Guardar reseña', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Solo con el permiso del cliente para publicar su comentario y su nombre.');
  await page.getByRole('checkbox', { name: 'El cliente dio permiso para publicar' }).check();
  await page.getByRole('button', { name: 'Guardar reseña', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Reseña guardada. Ya aparece en la tienda y puedes usarla en la tarjeta.');
  await expect(page.locator('#cv-reviews')).toContainText('— María G.');
  await tab(page, 'Anuncio');
  // The list shortens long comments; the card draws the whole text.
  await expect(page.locator('#cv-review-pick [role=radio]')).toHaveText([/^★★★★★ «Llegó rápido y quedó precioso en la sala.*» — María G\.$/]);
  for (const format of ['Historia 9:16', 'Post 1:1']) {
    await pick(page, format);
    await page.evaluate(() => { window.__drawn = []; });
    await finish(page);
    await expect(pieSheet(page)).toBeVisible();
    await expect(page.getByRole('status')).toHaveText('Imagen lista para descargar.');
    const drawn = (await page.evaluate(() => window.__drawn)).join(' | ');
    expect(drawn).toContain('★★★★★'); expect(drawn).toContain('— María G.'); expect(drawn).toContain('Llegó');
    await closePie(page);
  }
});

test('Enlace de WhatsApp por anuncio: va al texto para el pie y aparece en Resultados', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio offer fixture · Publicado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  await page.locator('#cv-photo').setInputFiles({ name: 'anuncio.png', mimeType: 'image/png', buffer: pixel });
  await tab(page, 'Anuncio');
  await pick(page, 'Oferta'); await pick(page, 'Historia 9:16');
  const createAd = async () => {
    await finish(page);
    const [image] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descargar imagen y copiar pie', exact: true }).click()]);
    expect(image.suggestedFilename()).toBe('casa-viva.jpg');
    await expect(page.getByRole('status')).toContainText(/Anuncio A\d+ creado/);
  };
  await createAd();
  await expect(page.locator('#cv-copy')).toHaveValue(/\nEscríbenos por WhatsApp: https:\/\/wa\.me\/5354056173\?text=.*%C2%B7%20A\d+$/);
  await closePie(page);
  await tab(page, 'Resultados');
  await expect(page.locator('#cv-ads li')).toHaveText([/^A\d+ Oferta · 9:16 \d{1,2} \D+?0 escribieron 0 pedidos Repetir$/]);
  // A second ad replaces the link line in the text and adds a row.
  await tab(page, 'Anuncio');
  await createAd();
  expect((await page.locator('#cv-copy').inputValue()).match(/Escríbenos por WhatsApp:/g)).toHaveLength(1);
  await closePie(page);
  await tab(page, 'Resultados');
  await expect(page.locator('#cv-ads li')).toHaveCount(2);
});

test('Un borrador sin foto ni existencias no se publica y el panel dice lo que falta', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio stock fixture · Borrador / privado', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Producto abierto.');
  await expect(page.locator('#cv-publish')).toBeDisabled();
  await expect(page.locator('#cv-publish')).toContainText('Falta foto, existencias (Lennys)');
  // The title is saved without a trailing dot.
  await page.locator('#cv-name').fill('Mesa de oficina.');
  const saved = await saveNow(page);
  expect(saved.name).toBe('Mesa de oficina'); expect(saved.status).toBe('draft');
  await expect(page.locator('#cv-name')).toHaveValue('Mesa de oficina');
  expect(saved.faltan).toEqual(['foto', 'existencias']);
  await expect(page.getByRole('status')).toHaveText('Borrador guardado. Falta: foto real, existencias, que pone Lennys.');
});

test('Editor de contenido: login, guardar, subir foto y descargar en móvil', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await page.setViewportSize({ width: 390, height: 844 });
  page.on('dialog', (dialog) => dialog.accept());
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
  await page.locator('#cv-photo').setInputFiles({ name: 'fixture.png', mimeType: 'image/png', buffer: pixel });
  await expect(page.getByRole('status')).toContainText('Foto preparada.');
  const [uploaded] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && decodeURIComponent(r.url()).includes('/contenido/upload')),
    page.getByRole('button', { name: 'Subir a la web', exact: true }).click(),
  ]);
  await expect(page.getByRole('status')).toContainText('Foto subida y protegida.');
  // The web copy is converted to WebP in the browser before it is sent.
  expect((await uploaded.json()).image_full).toMatch(/\.webp$/);
  await expect(page.locator('#cv-commercial')).toContainText('25');
  await page.getByRole('textbox', { name: 'Característica 1', exact: true }).fill('Dato sintético de prueba');
  await tab(page, 'Anuncio');
  await finish(page);
  await expect(page.getByRole('status')).toHaveText('Falta la duda → resultado → prueba: toca «Editar».');
  await page.getByRole('button', { name: 'Editar', exact: true }).click();
  await page.getByLabel('Duda', { exact: true }).fill('¿Aguanta?');
  await page.getByLabel('Resultado', { exact: true }).fill('Resultado sintético');
  await page.getByLabel('Prueba', { exact: true }).fill('Prueba sintética');
  await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Duda → resultado → prueba confirmada.');
  await finish(page);
  await expect(page.getByRole('status')).toHaveText('Coloca los 2 puntos en la foto hasta que diga «Listo».');
  const canvas = page.locator('#cv-canvas'), box = await canvas.boundingBox();
  await canvas.click({ position: { x: box.width * 0.4, y: box.height * 0.5 } }); await canvas.click({ position: { x: box.width * 0.6, y: box.height * 0.6 } });
  await expect(page.locator('#cv-point-hint')).toHaveText('Listo');
  await finish(page);
  await expect(page.getByRole('link', { name: 'Solo la imagen', exact: true })).toBeVisible();
  const dimensions = await page.locator('#cv-canvas').evaluate((canvas) => [canvas.width, canvas.height]);
  expect(dimensions).toEqual([1080, 1920]);
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Solo la imagen', exact: true }).click()]);
  const bytes = fs.readFileSync(await download.path());
  expect(bytes[0]).toBe(255); expect(bytes[1]).toBe(216); expect(bytes.length).toBeGreaterThan(1000);
  await expect(page.getByRole('textbox', { name: 'Pie del anuncio', exact: true })).toHaveValue(/^Resultado sintético\nPrueba sintética\./);
  const width = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.viewport);
  await closePie(page);
  await back(page);
  await page.getByRole('button', { name: 'Mi cuenta', exact: true }).click();
  await page.getByRole('button', { name: 'Cambiar contraseña', exact: true }).click();
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

test('v2 · Lista: foto HERO, prioridad, lo que falta, filtros, bandeja con contador y Helvetica sin franja arriba', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  const hero = page.getByRole('button', { name: 'Studio v2 HERO fixture · Publicado', exact: true });
  await expect(hero).toContainText('Va a anuncios');
  await expect(hero.locator('.cv-thumb')).toHaveClass(/cv-white/);
  const none = page.getByRole('button', { name: 'Studio v2 sin HERO fixture · Publicado', exact: true });
  await expect(none).toContainText('Falta HERO');
  await page.getByRole('button', { name: /^Falta HERO/ }).click();
  await expect(none).toBeVisible();
  await expect(hero).toHaveCount(0);
  await page.getByRole('button', { name: /^Borradores/ }).click();
  await expect(page.getByRole('button', { name: 'Studio v2 compare fixture · Borrador / privado', exact: true })).toContainText('Lista para revisar');
  await expect(page.locator('#cv-dudas-open')).toBeVisible();
  await expect(page.locator('#cv-dudas-count')).toHaveText(/^\d+$/);
  // Arreglos de la prueba en vivo: Helvetica también en títulos y sin franja vacía arriba.
  await expect(page.locator('#cv-title')).toHaveCSS('font-family', /Helvetica/);
  await expect(page.locator('#cv-title')).toHaveCSS('text-transform', 'none');
  expect(await page.locator('#cv-list-screen').evaluate((el) => Math.round(el.getBoundingClientRect().top))).toBe(0);
});

test('v2 · HERO: comparar enciende los 3 estados, aprobar lo pone en la web y Restaurar vuelve la anterior', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio v2 compare fixture · Borrador / privado', exact: true }).click();
  await expect(page.getByRole('tab', { name: /^Fotos/ })).toHaveAttribute('aria-selected', 'true');
  const states = page.locator('#cv-fotobar [data-estado]');
  await expect(states).toHaveCount(3);
  for (const b of await states.all()) await expect(b).toBeDisabled();
  await expect(page.locator('#cv-foto-body .cv-used')).toContainText('IA de pago · 0,15 USD');
  await page.locator('.cv-cmp input[type=range]').fill('70');
  for (const b of await states.all()) await expect(b).toBeEnabled();
  await page.locator('#cv-fotobar').getByRole('button', { name: 'Aprobada', exact: true }).click();
  await page.getByRole('button', { name: 'Poner en la web', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('HERO en la web.');
  await expect(page.locator('#cv-pieces [data-pieza=hero]')).toContainText('Aprobada');
  await expect(page.locator('#cv-foto-body')).toContainText('Ya está en la web');
  await page.getByRole('button', { name: 'Restaurar la anterior', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Volvió la foto anterior a la web.');
  await expect(page.locator('#cv-foto-body')).toContainText('Aún no está en la web');
});

test('v2 · Crear HERO: escalones con coste, en marcha con segundos, se publica solo y Rechazada restaura', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(120000);
  await openPanel(page);
  await page.getByRole('button', { name: 'Studio v2 sin HERO fixture · Publicado', exact: true }).click();
  const body = page.locator('#cv-foto-body'), ladder = body.locator('.cv-ladder');
  await expect(ladder.locator('li')).toHaveCount(3);
  await expect(ladder).toContainText('Recorte gratis'); await expect(ladder).toContainText('0 USD');
  await expect(ladder).toContainText('IA de pago'); await expect(ladder).toContainText('~0,15 USD');
  await expect(ladder).toContainText('Corrector ChatGPT');
  await expect(body.locator('.cv-budget')).toContainText(/Hoy .* de 0,50 USD/);
  // The call is synchronous: hold it a moment to see the work screen with its seconds.
  await page.route(/contenido(%2F|\/)crear/, async (route) => { await new Promise((r) => setTimeout(r, 2500)); await route.continue(); });
  await page.getByRole('button', { name: 'Crear HERO', exact: true }).click();
  await expect(body).toContainText('Creando HERO');
  await expect(page.locator('#cv-work-sec')).toHaveText(/^\d+ s$/);
  await expect(page.getByRole('button', { name: 'Seguir en la Ficha', exact: true })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText(/ya está en la web/, { timeout: 60000 });
  await expect(body).toContainText('Ya está en la web');
  await expect(body.locator('.cv-used')).toContainText('Recorte gratis · 0 USD');
  await page.locator('.cv-cmp input[type=range]').fill('30');
  await page.locator('#cv-fotobar').getByRole('button', { name: 'Rechazada', exact: true }).click();
  await page.getByRole('button', { name: 'Fondo', exact: true }).click();
  await page.getByRole('button', { name: 'Dejarla rechazada', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Rechazada. Volvió la foto anterior a la web.');
  await expect(page.locator('#cv-pieces [data-pieza=hero]')).toContainText('Rechazada');
});

test('v2 · Dudas del catálogo: una tarjeta por pantalla, Revisión, Sí, Explicar, en marcha y hechas', async ({ page }) => {
  test.skip(process.env.CONTENT_STUDIO_BROWSER !== '1', 'Dedicated disposable studio environment required');
  test.setTimeout(60000);
  await openPanel(page);
  await page.locator('#cv-dudas-open').click();
  await expect(page.getByRole('heading', { name: 'Dudas del catálogo', exact: true })).toBeVisible();
  const bar = page.locator('#cv-dudas-bar'), card = page.locator('#cv-dudas-body .cv-dq');
  await expect(page.locator('.cv-dprog')).toContainText(/^1 de \d+/);
  // Otras pruebas del mismo WordPress dejan tarjetas antes: «Luego» pasa a la siguiente hasta llegar a la buscada.
  const goTo = async (question) => {
    for (let i = 0; i < 30 && (await card.locator('h2').textContent()) !== question; i++) {
      await bar.getByRole('button').nth(1).click();
      await expect(page.getByRole('status')).toHaveText('Queda en Por contestar.');
      await page.getByRole('status').click();
      await expect(bar.getByRole('button').nth(1)).toBeEnabled();
    }
    await expect(card.locator('h2')).toHaveText(question);
  };
  await goTo('¿Es el mismo producto?');
  await expect(bar.getByRole('button')).toHaveText(['Rechazar', 'Revisión', 'Aprobar']);
  await expect(card.getByRole('button', { name: 'Corregir', exact: true })).toBeVisible();
  await bar.getByRole('button', { name: 'Revisión', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Queda en Por contestar.');
  await page.getByRole('status').click();
  await expect(bar.getByRole('button').nth(1)).toBeEnabled();
  await goTo('¿Trae herrajes para instalarla?');
  await expect(bar.getByRole('button')).toHaveText(['No', 'Luego', 'Sí']);
  await bar.getByRole('button', { name: 'Sí', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Hecho · Ficha y crm_knowledge');
  await page.getByRole('status').click();
  await expect(bar.getByRole('button').nth(1)).toBeEnabled();
  await goTo('¿Este título está bien?');
  await card.getByRole('button', { name: 'Explicar', exact: true }).click();
  await page.getByRole('textbox', { name: 'Explicación escrita', exact: true }).fill('El título debe decir la medida.');
  await page.getByRole('button', { name: 'Enviar', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText(/^En marcha · /);
  await page.getByRole('tab', { name: /^En marcha/ }).click();
  const result = page.locator('#cv-dudas-body .cv-dq', { hasText: '¿Se ve bien la escena?' });
  await expect(page.locator('#cv-dudas-body .cv-dq', { hasText: '¿Este título está bien?' })).toContainText('Lo hace el supervisor del VPS');
  await result.getByRole('button', { name: 'Aprobada', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Hecho · lista para anuncios');
  await page.getByRole('tab', { name: /^Hechas/ }).click();
  const done = page.locator('#cv-dudas-body .cv-hist', { hasText: '¿Trae herrajes para instalarla?' });
  await expect(done).toContainText('Ficha y crm_knowledge');
  await expect(done).toContainText('cvt_content_browser');
  const width = await page.evaluate(() => ({ viewport: innerWidth, scroll: document.documentElement.scrollWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.viewport);
});
