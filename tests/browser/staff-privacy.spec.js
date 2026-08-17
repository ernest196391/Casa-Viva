/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const admin = { user: process.env.ORDER_CENTER_ADMIN_USER, pass: process.env.ORDER_CENTER_ADMIN_PASSWORD };
const clerk = { user: process.env.ORDER_CENTER_CLERK_USER, pass: process.env.ORDER_CENTER_CLERK_PASSWORD };

async function login(page, credentials) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(credentials.user);
  await page.locator('#user_pass').fill(credentials.pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 15000 }),
    page.locator('#wp-submit').click(),
  ]);
}

async function openSales(page) {
  const response = await page.goto(`${baseURL}/ventas/`, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  await expect(page.locator('.cvd-sale-card').first()).toBeVisible({ timeout: 15000 });
}

test('dependienta opera pedidos sin datos financieros o administrativos', async ({ page }) => {
  await login(page, clerk);
  await openSales(page);

  await expect(page.locator('.cvd-sale-data b', { hasText: 'Gestora' })).toHaveCount(0);
  await expect(page.locator('.cvd-sale-data b', { hasText: 'Comisión' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Ver pedido' })).toHaveCount(0);

  const payload = await page.evaluate(async () => {
    const response = await fetch(window.cvdSales.url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.cvdSales.nonce },
    });
    return response.json();
  });

  expect(payload.orders.length).toBeGreaterThan(0);
  for (const order of payload.orders) {
    expect(order.gestora).toBeUndefined();
    expect(order.commission).toBeUndefined();
    expect(order.commissionStatus).toBeUndefined();
    expect(order.adminUrl).toBeUndefined();
    expect(order.customer).toBeDefined();
    expect(order.products).toBeDefined();
    expect(order.actions).toBeDefined();
  }
});

test('administración conserva contexto comercial y acceso al pedido', async ({ page }) => {
  await login(page, admin);
  await openSales(page);

  await expect(page.locator('.cvd-sale-data b', { hasText: 'Gestora' }).first()).toBeVisible();
  await expect(page.locator('.cvd-sale-data b', { hasText: 'Comisión' }).first()).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ver pedido' }).first()).toBeVisible();

  const payload = await page.evaluate(async () => {
    const response = await fetch(window.cvdSales.url, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.cvdSales.nonce },
    });
    return response.json();
  });

  expect(payload.orders.length).toBeGreaterThan(0);
  expect(payload.orders[0].gestora).toBeDefined();
  expect(payload.orders[0].commission).toBeDefined();
  expect(payload.orders[0].commissionStatus).toBeDefined();
  expect(payload.orders[0].adminUrl).toBeDefined();
});
