/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const ordersRelative = process.env.CUSTOMER_ORDERS_RELATIVE;
const user = process.env.CUSTOMER_ORDERS_USER;
const password = process.env.CUSTOMER_ORDERS_PASSWORD;
const activeId = process.env.CUSTOMER_ORDERS_ACTIVE_ID;
const finishedId = process.env.CUSTOMER_ORDERS_FINISHED_ID;

async function login(page) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#wp-submit').click(),
  ]);
}

test('Pedidos separa activos y terminados y abre el pedido', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.goto(new URL(ordersRelative, baseURL).toString(), { waitUntil: 'domcontentloaded' });

  const center = page.locator('.cvd-customer-orders');
  await expect(center).toBeVisible();
  await expect(center.getByRole('heading', { name: 'Activos' })).toBeVisible();
  await expect(center.getByRole('heading', { name: 'Terminados' })).toBeVisible();

  const active = page.locator(`[data-cvd-customer-order="${activeId}"]`);
  const finished = page.locator(`[data-cvd-customer-order="${finishedId}"]`);
  await expect(active).toContainText('Preparando pedido');
  await expect(active).toContainText('Entrega a domicilio');
  await expect(finished).toContainText('Completado');
  await expect(active.getByRole('link', { name: 'Ver pedido' })).toHaveAttribute('href', /view-order/);

  const badge = page.locator('[data-cvd-orders-count]');
  await expect(badge).toHaveText('1');

  const width = await center.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.client + 1);
});
