/* eslint-disable @typescript-eslint/no-require-imports */
// Recorrido de auditoría en producción: clienta en móvil (hasta el checkout, sin confirmar pedido)
// y gestora de prueba temporal preguntando a Curru por existencias. No envía pedidos ni formularios de pago.
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const baseURL = (process.env.PRODUCTION_URL || 'https://casavivadecuba.com').replace(/\/$/, '');
const outDir = process.env.JOURNEY_EVIDENCE_DIR || 'journey-evidence';
const report = { base_url: baseURL, generated_at: new Date().toISOString(), steps: [], curru: [], issues: [] };
let shot = 0;

test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 CasaVivaAudit/1.0' });
test.beforeAll(() => fs.mkdirSync(outDir, { recursive: true }));
test.afterAll(() => fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2)));

function watch(page) {
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 200)));
  return errors;
}

async function step(page, name, action, errors) {
  const started = Date.now();
  let ok = true;
  let note = '';
  try { note = (await action()) || ''; } catch (error) { ok = false; note = String(error.message).split('\n')[0].slice(0, 240); }
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  const file = `${String(++shot).padStart(2, '0')}-${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`;
  await page.screenshot({ path: path.join(outDir, file) }).catch(() => {});
  const metrics = await page.evaluate(() => ({
    url: location.href,
    title: document.title,
    scroll_x: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    small_taps: [...document.querySelectorAll('a,button,input,select')].filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 40 || r.width < 40) && getComputedStyle(el).visibility !== 'hidden'; }).length,
  })).catch(() => ({}));
  report.steps.push({ name, ok, note, ms: Date.now() - started, screenshot: file, console_errors: errors.splice(0), ...metrics });
  return ok;
}

async function askCurru(page, who, question) {
  const launcher = page.locator('.cvd-assistant-launcher');
  const panel = page.locator('#cvd-contextual-assistant');
  if (!(await panel.isVisible().catch(() => false))) await launcher.click();
  const before = await page.locator('.cvd-curru-reply').count();
  const started = Date.now();
  const responsePromise = page.waitForResponse((r) => r.url().includes('/curru/ask'), { timeout: 30000 }).catch(() => null);
  await page.fill('#cvd-contextual-question', question);
  await page.press('#cvd-contextual-question', 'Enter');
  const response = await responsePromise;
  const data = response ? await response.json().catch(() => ({})) : {};
  await page.waitForFunction((n) => document.querySelectorAll('.cvd-curru-reply').length > n, before, { timeout: 30000 }).catch(() => {});
  const file = `${String(++shot).padStart(2, '0')}-curru-${who}.png`;
  await page.screenshot({ path: path.join(outDir, file) }).catch(() => {});
  report.curru.push({ who, question, ms: Date.now() - started, status: response ? response.status() : 0, ai: Boolean(data.ai), answer: data.answer || '', products: (data.products || []).map((p) => ({ name: p.name, price: p.price, inStock: p.inStock, stock: p.stock ?? null })), links: (data.links || []).map((l) => l.label), screenshot: file });
}

test('clienta en móvil: portada → categoría → producto → carrito → checkout', async ({ page }) => {
  test.setTimeout(6 * 60 * 1000);
  const errors = watch(page);
  await step(page, 'portada', async () => { await page.goto(`${baseURL}/`, { waitUntil: 'domcontentloaded' }); }, errors);
  let categoryUrl = '';
  await step(page, 'categoria', async () => {
    categoryUrl = await page.locator('a[href*="/categoria-producto/"]').first().getAttribute('href');
    if (!categoryUrl) throw new Error('No hay enlaces a categorías en la portada');
    await page.goto(categoryUrl, { waitUntil: 'domcontentloaded' });
    return categoryUrl;
  }, errors);
  let productUrl = '';
  await step(page, 'producto', async () => {
    const cards = page.locator('ul.products li.product:not(.outofstock) a.woocommerce-LoopProduct-link');
    productUrl = await cards.first().getAttribute('href');
    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    return productUrl;
  }, errors);
  await step(page, 'producto-detalle', async () => {
    const info = await page.evaluate(() => ({
      gallery: document.querySelectorAll('.woocommerce-product-gallery__image').length,
      description: (document.querySelector('.woocommerce-product-details__short-description, #tab-description')?.textContent || '').trim().length,
      stock: document.querySelector('.stock')?.textContent?.trim() || '',
      price: document.querySelector('.summary .price')?.textContent?.trim() || '',
      variable: Boolean(document.querySelector('form.variations_form')),
      reviews: Boolean(document.querySelector('#reviews, .woocommerce-product-rating')),
    }));
    report.product = { url: productUrl, ...info };
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    return JSON.stringify(info);
  }, errors);
  await step(page, 'anadir-al-carrito', async () => {
    if (report.product?.variable) {
      const selects = page.locator('form.variations_form select');
      for (let i = 0; i < await selects.count(); i++) {
        const options = await selects.nth(i).locator('option:not([value=""])').all();
        if (options.length) await selects.nth(i).selectOption(await options[0].getAttribute('value'));
      }
    }
    await page.locator('form.cart button[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded');
    const notice = (await page.locator('.woocommerce-message, .woocommerce-error').first().textContent().catch(() => '')) || '';
    return notice.trim().slice(0, 160);
  }, errors);
  await step(page, 'carrito', async () => {
    await page.goto(`${baseURL}/carrito/`, { waitUntil: 'domcontentloaded' });
    const items = await page.locator('.woocommerce-cart-form__cart-item, .wc-block-cart-items__row').count();
    report.cart = { items, total: (await page.locator('.order-total, .wc-block-components-totals-footer-item').first().textContent().catch(() => '') || '').trim() };
    if (!items) throw new Error('El carrito está vacío tras añadir');
    return `${items} artículo(s)`;
  }, errors);
  await step(page, 'checkout-sin-confirmar', async () => {
    const go = page.locator('a.checkout-button, a.wc-block-cart__submit-button').first();
    if (await go.count()) { await go.click(); await page.waitForLoadState('domcontentloaded'); }
    else await page.goto(`${baseURL}/finalizar-compra/`, { waitUntil: 'domcontentloaded' });
    const form = await page.evaluate(() => {
      const fields = [...document.querySelectorAll('form.checkout input:not([type=hidden]), form.checkout select, form.checkout textarea, .wc-block-checkout input, .wc-block-checkout select')].filter((el) => el.getBoundingClientRect().height > 0);
      return {
        url: location.href,
        fields: fields.length,
        required: fields.filter((el) => el.required || el.closest('.validate-required')).length,
        labels: fields.map((el) => (el.labels && el.labels[0] ? el.labels[0].textContent.trim().replace(/\s+/g, ' ') : el.name || el.id)).slice(0, 30),
        payment: [...document.querySelectorAll('.wc_payment_method label, .wc-block-components-radio-control__label')].map((el) => el.textContent.trim()),
        guest: !document.querySelector('form.woocommerce-form-login') || Boolean(document.querySelector('form.checkout')),
      };
    });
    report.checkout = form;
    return `${form.fields} campos visibles`;
  }, errors);
  await step(page, 'cliente-pregunta-curru', async () => {
    await page.goto(`${baseURL}/`, { waitUntil: 'domcontentloaded' });
    await askCurru(page, 'cliente', '¿Tienen toallas? ¿Cuántas quedan?');
    await askCurru(page, 'cliente', '¿Cuánto cuesta el envío a Playa?');
  }, errors);
  expect(report.steps.filter((s) => !s.ok).map((s) => `${s.name}: ${s.note}`)).toEqual([]);
});

test('gestora de prueba: acceso y existencias con Curru', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000);
  const user = process.env.JOURNEY_GESTORA_USER;
  const pass = process.env.JOURNEY_GESTORA_PASS;
  test.skip(!user || !pass, 'Sin cuenta de gestora temporal');
  const errors = watch(page);
  await step(page, 'gestora-login', async () => {
    await page.goto(`${baseURL}/mi-cuenta/?acceso=gestoras`, { waitUntil: 'domcontentloaded' });
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await page.locator('button[name="login"]').click();
    await page.waitForLoadState('domcontentloaded');
    if (await page.locator('.woocommerce-error').count()) throw new Error((await page.locator('.woocommerce-error').first().textContent()).trim());
  }, errors);
  await step(page, 'gestora-area', async () => { await page.goto(`${baseURL}/area-gestoras/`, { waitUntil: 'domcontentloaded' }); return page.url(); }, errors);
  await step(page, 'gestora-pregunta-curru', async () => {
    await page.goto(`${baseURL}/`, { waitUntil: 'domcontentloaded' });
    await askCurru(page, 'gestora', '¿Hay albornoces? ¿Cuántos quedan de cada modelo?');
    await askCurru(page, 'gestora', '¿Cuántas sartenes quedan en existencia?');
  }, errors);
  expect(report.steps.filter((s) => s.name.startsWith('gestora') && !s.ok).map((s) => `${s.name}: ${s.note}`)).toEqual([]);
});
