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
  await expect(page.locator(`[data-delivery-id="${orderId}"]`)).toBeVisible({ timeout: 15000 });
}

test.afterEach(async ({ page }, testInfo) => {
  if (testInfo.status === testInfo.expectedStatus) return;
  await page.screenshot({ path: testInfo.outputPath('test-failed-messenger.png'), fullPage: true }).catch(() => {});
  const snapshot = await page.evaluate(() => ({
    href: window.location.href,
    width: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    title: document.querySelector('#entregas h2')?.textContent || '',
    shellClass: document.querySelector('.cvd-dashboard.cvd-app-shell')?.className || '',
    cards: Array.from(document.querySelectorAll('[data-delivery-id]')).map((card) => ({
      id: card.getAttribute('data-delivery-id'),
      status: card.getAttribute('data-delivery-status'),
      className: card.className,
      whatsapp: Boolean(card.querySelector('a[href^="https://wa.me/"]')),
      phone: Boolean(card.querySelector('a[href^="tel:"]')),
      navigate: Array.from(card.querySelectorAll('a')).some((link) => link.textContent.trim() === 'Navegar'),
      delivered: Boolean(card.querySelector('[data-confirm-delivery="delivered"]')),
    })),
  })).catch(() => null);
  if (snapshot) console.log('MESSENGER_DIAGNOSTIC ' + JSON.stringify(snapshot));
});

test('mensajero ve una entrega activa con acciones operativas directas', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await openCenter(page);

  const card = page.locator(`[data-delivery-id="${orderId}"]`);
  await expect(page.locator('#entregas h2')).toHaveText('Entrega activa');
  await expect(card).toHaveClass(/is-current/);
  await expect(card.locator('a[href^="https://wa.me/"]')).toBeVisible();
  await expect(card.locator('a[href^="tel:"]')).toBeVisible();
  await expect(card.getByText('Navegar')).toBeVisible();
  await expect(card.locator('[data-confirm-delivery="delivered"]')).toContainText('Entregado');

  const layout = await page.evaluate(() => {
    const shell = document.querySelector('.cvd-messenger-center');
    return shell ? { present: true, width: shell.clientWidth, scrollWidth: shell.scrollWidth } : { present: false, width: 0, scrollWidth: 0 };
  });
  expect(layout.present).toBeTruthy();
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
});
