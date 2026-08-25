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

test('Qué hay de nuevo devuelve un resumen operativo con datos de la jornada', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  const response = await page.goto(`${baseURL}/?page_id=${pageId}`, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  await expect(page.locator('.cvd-messenger-center.cvd-premium-v2')).toBeVisible({ timeout: 15000 });

  await page.getByRole('link', { name: 'Asistente', exact: true }).click();
  const assistant = page.locator('#asistente');
  await expect(assistant).toBeVisible();
  const input = assistant.locator('form input');
  await input.fill('Qué hay de nuevo');
  await assistant.locator('form button[type="submit"], form button').last().click();

  const answer = assistant.locator('.cvd-assistant-answer');
  await expect(answer).toBeVisible();
  await expect(answer).toContainText('Ahora mismo', { timeout: 15000 });
  await expect(answer).toContainText('entregas');
  await expect(answer).not.toContainText('Puedo consultar contactos');

  const layout = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth }));
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
});
