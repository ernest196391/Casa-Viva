/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const ordersRelative = process.env.CUSTOMER_ORDERS_RELATIVE;
const user = process.env.CUSTOMER_ORDERS_USER;
const password = process.env.CUSTOMER_ORDERS_PASSWORD;
const activeId = process.env.CUSTOMER_ORDERS_ACTIVE_ID;

async function login(page) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#wp-submit').click(),
  ]);
}

test('Ayuda contextual abre soporte para el pedido visible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.goto(new URL(ordersRelative, baseURL).toString(), { waitUntil: 'domcontentloaded' });

  const active = page.locator(`[data-cvd-customer-order="${activeId}"]`);
  const detailHref = await active.getByRole('link', { name: 'Ver pedido' }).getAttribute('href');
  expect(detailHref).toBeTruthy();
  await page.goto(new URL(detailHref, baseURL).toString(), { waitUntil: 'domcontentloaded' });

  const support = page.locator(`[data-cvd-customer-order-support="${activeId}"]`);
  await expect(support).toBeVisible();
  await expect(support.getByRole('heading', { name: 'Habla con Casa Viva sobre este pedido' })).toBeVisible();

  const whatsapp = support.getByRole('link', { name: 'Escribir por WhatsApp' });
  const call = support.getByRole('link', { name: 'Llamar' });
  await expect(whatsapp).toHaveAttribute('href', /^https:\/\/wa\.me\/5355550101\?text=/);
  await expect(call).toHaveAttribute('href', 'tel:+5355550101');

  const href = await whatsapp.getAttribute('href');
  const decoded = decodeURIComponent(href || '');
  expect(decoded).toContain('necesito ayuda con mi pedido #');
  expect(decoded).toContain('Estado: Preparando pedido.');
  expect(decoded).not.toContain('order_key');
  expect(decoded).not.toContain('0000000000');

  const width = await support.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
  expect(width.scroll).toBeLessThanOrEqual(width.client + 1);
});
