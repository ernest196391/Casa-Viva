/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const salesPage = process.env.ORDER_CENTER_SALES_PAGE_ID;
const pickupId = process.env.ORDER_CENTER_PICKUP_ID;
const clerk = { user: process.env.ORDER_CENTER_CLERK_USER, pass: process.env.ORDER_CENTER_CLERK_PASSWORD };

async function login(page) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(clerk.user);
  await page.locator('#user_pass').fill(clerk.pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 15000 }),
    page.locator('#wp-submit').click(),
  ]);
}

test('dependienta completa una recogida con entrega física y cobro confirmados', async ({ page }) => {
  await login(page);
  await page.goto(`${baseURL}/?page_id=${salesPage}&order=${pickupId}`, { waitUntil: 'domcontentloaded' });

  const card = page.locator('.cvd-sale-card', { hasText: `Pedido #${pickupId}` }).first();
  await expect(card).toBeVisible({ timeout: 15000 });
  await expect(card.getByText('Listo para recoger')).toBeVisible();
  await expect(card.getByText('No aplica')).toBeVisible();

  await card.getByRole('button', { name: 'Entregar al cliente' }).click();
  const dialog = page.locator('#cvd-money-dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('heading', { name: 'Confirmar recogida y cobro' })).toBeVisible();
  await dialog.locator('#cvd-money-method').selectOption('cash_usd');
  await dialog.locator('#cvd-money-usd').fill('25');
  await dialog.locator('#cvd-handover-confirmed').check();
  await dialog.locator('#cvd-money-confirmed').check();
  await dialog.getByRole('button', { name: 'Completar' }).click();

  await expect(dialog).toBeHidden({ timeout: 15000 });
  await expect(card.getByText('Dinero recibido · completado')).toBeVisible({ timeout: 15000 });
  await expect(card.getByRole('button', { name: 'Entregar al cliente' })).toHaveCount(0);
});