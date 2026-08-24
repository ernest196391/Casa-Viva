/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const pageId = Number(process.env.MESSENGER_CENTER_PAGE_ID);
const orderId = Number(process.env.MESSENGER_CENTER_ORDER_ID);
const messenger = { user: process.env.MESSENGER_CENTER_USER, pass: process.env.MESSENGER_CENTER_PASSWORD };

async function login(page) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(messenger.user);
  await page.locator('#user_pass').fill(messenger.pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 15000 }),
    page.locator('#wp-submit').click(),
  ]);
}

async function openCenter(page) {
  const response = await page.goto(`${baseURL}/?page_id=${pageId}`, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  await expect(page.locator('.cvd-messenger-center.cvd-p03.cvd-premium-v2')).toBeVisible({ timeout: 15000 });
}

async function assertNoOverflow(page) {
  const layout = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
}

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status === testInfo.expectedStatus) return;
  await page.screenshot({ path: testInfo.outputPath('test-failed-messenger.png'), fullPage: true }).catch(() => {});
  const snapshot = await page.evaluate(() => ({
    href: window.location.href,
    width: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    view: document.querySelector('.cvd-messenger-center')?.dataset.cvdView || '',
    nav: Array.from(document.querySelectorAll('.cvd-messenger-nav a')).map((a) => ({ label: a.textContent.trim(), current: a.getAttribute('aria-current') })),
    stops: Array.from(document.querySelectorAll('[data-route-stop]')).map((stop) => ({
      id: stop.getAttribute('data-route-stop'),
      expanded: stop.classList.contains('is-expanded'),
      hasQuickAction: Boolean(stop.querySelector('.cvd-route-quick-action')),
    })),
  })).catch(() => null);
  if (snapshot) console.log('MESSENGER_DIAGNOSTIC ' + JSON.stringify(snapshot));
});

test('Hoy es un dashboard compacto y no duplica toda la operación', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await openCenter(page);

  const center = page.locator('.cvd-messenger-center');
  await expect(center).toHaveAttribute('data-cvd-view', 'hoy');
  await expect(page.locator('.cvd-messenger-today')).toBeVisible();
  await expect(page.locator('.cvd-today-brief')).toBeVisible();
  await expect(page.locator('.cvd-next-task')).toBeVisible();
  await expect(page.locator('.cvd-messenger-route')).toBeHidden();
  await expect(page.locator('.cvd-messenger-contacts')).toBeHidden();
  await expect(page.locator('.cvd-messenger-preparation')).toBeHidden();
  await assertNoOverflow(page);
});

test('Ruta concentra la entrega activa y usa progressive disclosure', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await openCenter(page);

  await page.getByRole('link', { name: 'Ruta', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'ruta');
  await expect(page.locator('.cvd-messenger-route')).toBeVisible();
  await expect(page.locator('#entregas')).toBeVisible();

  const card = page.locator(`[data-delivery-id="${orderId}"]`);
  await expect(card).toHaveClass(/is-current/);
  await expect(card.locator('a[href^="https://wa.me/"]')).toBeVisible();
  await expect(card.locator('a[href^="tel:"]')).toBeVisible();
  await expect(card.getByText('Navegar')).toBeVisible();
  await expect(card.locator('[data-confirm-delivery="delivered"]')).toContainText('Entregado');

  const stop = page.locator('[data-route-stop]').first();
  await expect(stop.locator('.cvd-route-quick')).toBeVisible();
  await expect(stop.locator('.cvd-route-details')).toBeHidden();
  await stop.locator('.cvd-route-detail-toggle').click();
  await expect(stop.locator('.cvd-route-details')).toBeVisible();
  await assertNoOverflow(page);
});

test('Más compacta contactos, preparación y asistente bajo demanda', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await login(page);
  await openCenter(page);

  await page.getByRole('link', { name: 'Más', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'mas');
  await expect(page.locator('.cvd-messenger-contacts')).toBeVisible();
  await expect(page.locator('.cvd-messenger-preparation')).toBeVisible();

  const contact = page.locator('.cvd-contact-list article').first();
  const more = contact.locator('.cvd-contact-more');
  if (await contact.locator('.cvd-contact-toggle').count()) {
    await expect(more).toBeHidden();
    await contact.locator('.cvd-contact-toggle').click();
    await expect(more).toBeVisible();
  }

  const assistant = page.locator('#asistente');
  await expect(assistant).toBeHidden();
  await page.getByRole('link', { name: 'Asistente', exact: true }).click();
  await expect(assistant).toBeVisible();
  await expect(assistant.locator('[data-assistant-question="missing"]')).toContainText('Qué falta');
  await assistant.locator('[data-assistant-question="missing"]').click();
  await expect(page.locator('.cvd-assistant-answer')).toBeVisible();
  await expect(page.locator('.cvd-assistant-answer p')).not.toHaveText('');
  await expect(page.locator('#preparar')).not.toContainText('_reduced_stock');
  await expect(page.locator('#preparar')).not.toContainText('_cvd_stock_reduction_sequence');
  await assertNoOverflow(page);
});

test('navegación inferior mantiene cuatro destinos y estado activo inequívoco', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await login(page);
  await openCenter(page);

  await expect(page.getByRole('link', { name: /Añadir vale/i })).toBeVisible();
  await expect(page.locator('.cvd-messenger-nav a')).toHaveCount(4);
  await expect(page.locator('.cvd-messenger-nav a[aria-current="page"]')).toHaveText('Hoy');
  await page.getByRole('link', { name: 'Dinero', exact: true }).click();
  await expect(page.locator('.cvd-messenger-nav a[aria-current="page"]')).toHaveText('Dinero');
  await assertNoOverflow(page);
});

for (const width of [320, 360, 375, 390, 414]) {
  test(`mensajero no desborda horizontalmente a ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await login(page);
    await openCenter(page);
    await assertNoOverflow(page);
    await page.getByRole('link', { name: 'Ruta', exact: true }).click();
    await assertNoOverflow(page);
    await page.getByRole('link', { name: 'Más', exact: true }).click();
    await assertNoOverflow(page);
  });
}
