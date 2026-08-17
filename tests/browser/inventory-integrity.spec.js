/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const pageId = process.env.INVENTORY_PAGE_ID;
const productCode = process.env.INVENTORY_PRODUCT_CODE;
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

test('inventario obliga a reconciliar discrepancias y no ofrece ventas manuales', async ({ page }) => {
  await login(page);
  await page.goto(`${baseURL}/?page_id=${pageId}`, { waitUntil: 'domcontentloaded' });

  const movementType = page.locator('#cvd-movement-type');
  await expect(movementType).toBeAttached();
  await expect(movementType.locator('option[value="sale"]')).toHaveCount(0);
  await expect(movementType.locator('option[value="return"]')).toHaveCount(0);

  const integrity = page.locator('#cvd-inventory-integrity');
  await expect(integrity).toContainText('Reconciliación requerida', { timeout: 15000 });
  await expect(integrity).toContainText('Producto discrepancia visual 5C');

  await page.locator('#cvd-product-code').fill(productCode);
  await page.locator('#cvd-find-product').click();
  await expect(page.locator('#cvd-product-name')).toHaveText('Producto discrepancia visual 5C');
  await expect(page.locator('#cvd-product-stock')).toHaveText('3');

  await movementType.selectOption('entry');
  await page.locator('#cvd-movement-quantity').fill('1');
  await page.locator('#cvd-movement-reason').fill('Entrada bloqueada por discrepancia');
  await page.locator('#cvd-movement-form button[type="submit"]').click();
  await expect(page.locator('#cvd-movement-message')).toContainText('conteo físico', { timeout: 15000 });
  await expect(page.locator('#cvd-product-stock')).toHaveText('3');

  await movementType.selectOption('count');
  await page.locator('#cvd-movement-quantity').fill('3');
  await page.locator('#cvd-movement-reason').fill('Conteo físico confirmado');
  await page.locator('#cvd-movement-form button[type="submit"]').click();
  await expect(page.locator('#cvd-movement-message')).toContainText('Inventario actualizado', { timeout: 15000 });
  await expect(integrity).toContainText('Inventario conciliado', { timeout: 15000 });
});