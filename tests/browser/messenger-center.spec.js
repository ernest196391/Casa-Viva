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
  const layout = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
}

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status === testInfo.expectedStatus) return;
  await page.screenshot({ path: testInfo.outputPath('test-failed-messenger.png'), fullPage: true }).catch(() => {});
});

test('Hoy es un dashboard compacto y no duplica toda la operación', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page); await openCenter(page);
  const center = page.locator('.cvd-messenger-center');
  await expect(center).toHaveAttribute('data-cvd-view', 'hoy');
  await expect(page.locator('.cvd-messenger-today')).toBeVisible();
  await expect(page.locator('.cvd-today-brief')).toBeVisible();
  await expect(page.locator('.cvd-next-task')).toBeVisible();
  await expect(page.locator('.cvd-messenger-route')).toBeHidden();
  await expect(page.locator('.cvd-messenger-contacts')).toBeHidden();
  await expect(page.locator('.cvd-messenger-preparation')).toBeHidden();
  await expect(page.locator('#liquidaciones')).toBeHidden();
  await assertNoOverflow(page);
});

test('Ruta concentra la entrega activa y usa progressive disclosure', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page); await openCenter(page);
  await page.getByRole('link', { name: 'Ruta', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'ruta');
  await expect(page.locator('.cvd-messenger-route')).toBeVisible();
  await expect(page.locator('#liquidaciones')).toBeHidden();

  const stop = page.locator(`[data-route-stop="${orderId}"]`);
  await expect(stop.locator('.cvd-route-quick')).toBeVisible();
  await expect(stop.locator('.cvd-route-details')).toBeHidden();
  await stop.locator('.cvd-route-detail-toggle').click();
  await expect(stop.locator('.cvd-route-details')).toBeVisible();

  const card = stop.locator(`[data-delivery-id="${orderId}"]`);
  await expect(card).toHaveClass(/is-current/);
  await expect(card.locator('a[href^="https://wa.me/"]')).toBeVisible();
  await expect(card.locator('a[href^="tel:"]')).toBeVisible();
  await expect(card.getByText('Navegar')).toBeVisible();
  await expect(card.locator('[data-confirm-delivery="delivered"]')).toContainText('Entregado');
  await assertNoOverflow(page);
});

test('Más compacta contactos, preparación y asistente bajo demanda', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await login(page); await openCenter(page);
  await page.getByRole('link', { name: 'Más', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'mas');
  await expect(page.locator('.cvd-messenger-contacts')).toBeVisible();
  await expect(page.locator('.cvd-messenger-preparation')).toBeVisible();
  await expect(page.locator('#liquidaciones')).toBeHidden();
  const contact = page.locator('.cvd-contact-list article').first();
  if (await contact.locator('.cvd-contact-toggle').count()) {
    await expect(contact.locator('.cvd-contact-more')).toBeHidden();
    await contact.locator('.cvd-contact-toggle').click();
    await expect(contact.locator('.cvd-contact-more')).toBeVisible();
  }
  const assistant = page.locator('#asistente');
  await expect(assistant).toBeHidden();
  await page.locator('.cvd-messenger-launchpad a[href="#asistente"]').first().click();
  await expect(assistant).toBeVisible();
  await assistant.locator('[data-assistant-question="missing"]').click();
  await expect(page.locator('.cvd-assistant-answer')).toBeVisible();
  await expect(page.locator('#preparar')).not.toContainText('_reduced_stock');
  await assertNoOverflow(page);
});

test('Dinero concentra liquidaciones y navegación activa', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await login(page); await openCenter(page);
  await expect(page.locator('.cvd-messenger-nav a')).toHaveCount(4);
  await page.getByRole('link', { name: 'Dinero', exact: true }).click();
  await expect(page.locator('.cvd-messenger-nav a[aria-current="page"]')).toHaveText('Dinero');
  await expect(page.locator('#liquidaciones')).toBeVisible();
  await expect(page.locator('.cvd-messenger-today')).toBeHidden();
  await assertNoOverflow(page);
});

for (const width of [320, 360, 375, 390, 414]) {
  test(`mensajero no desborda horizontalmente a ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await login(page); await openCenter(page);
    await assertNoOverflow(page);
    for (const destination of ['Ruta', 'Dinero', 'Más']) {
      await page.getByRole('link', { name: destination, exact: true }).click();
      await assertNoOverflow(page);
    }
  });
}