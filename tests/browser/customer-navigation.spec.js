/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const productId = Number(process.env.CUSTOMER_NAV_PRODUCT_ID);

test('cliente ve navegación móvil persistente y badge de carrito reactivo', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseURL}/tienda/`, { waitUntil: 'domcontentloaded' });

  let nav = page.locator('.cvd-customer-nav');
  await expect(nav).toBeVisible();
  for (const label of ['Inicio', 'Categorías', 'Carrito', 'Pedidos', 'Cuenta']) {
    await expect(nav.getByText(label, { exact: true })).toBeVisible();
  }

  await page.goto(`${baseURL}/?add-to-cart=${productId}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('[data-cvd-cart-count]')).toHaveText('1');

  nav = page.locator('.cvd-customer-nav');
  const cartLink = nav.locator('.cvd-customer-nav__item--cart');
  const cartUrl = await cartLink.getAttribute('href');
  expect(cartUrl).toBeTruthy();
  await page.goto(cartUrl, { waitUntil: 'domcontentloaded' });

  const quantity = page.locator('.woocommerce-cart-form input.qty').first();
  await expect(quantity).toBeVisible();
  await quantity.fill('2');
  await quantity.dispatchEvent('input');
  await expect(page.locator('[data-cvd-cart-count]')).toHaveText('2');

  nav = page.locator('.cvd-customer-nav');
  const box = await nav.boundingBox();
  expect(box).toBeTruthy();
  expect(Math.abs((box.y + box.height) - 844)).toBeLessThanOrEqual(2);
});
