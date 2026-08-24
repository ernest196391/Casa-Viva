/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const pageId = Number(process.env.MESSENGER_CENTER_PAGE_ID);
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

test('auditoría visual móvil de Hoy, Ruta y Más', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  const response = await page.goto(`${baseURL}/?page_id=${pageId}`, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  await expect(page.locator('.cvd-messenger-center.cvd-premium-v2')).toBeVisible({ timeout: 15000 });

  await page.screenshot({ path: testInfo.outputPath('messenger-hoy-390.png'), fullPage: true });

  await page.getByRole('link', { name: 'Ruta', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'ruta');
  await page.screenshot({ path: testInfo.outputPath('messenger-ruta-390.png'), fullPage: true });

  await page.getByRole('link', { name: 'Más', exact: true }).click();
  await expect(page.locator('.cvd-messenger-center')).toHaveAttribute('data-cvd-view', 'mas');
  await page.screenshot({ path: testInfo.outputPath('messenger-mas-390.png'), fullPage: true });

  const layout = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
});
