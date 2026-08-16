/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const portalRelative = process.env.GESTORA_PORTAL_RELATIVE;
const aUser = process.env.GESTORA_PORTAL_A_USER;
const aPassword = process.env.GESTORA_PORTAL_A_PASSWORD;
const aClient = process.env.GESTORA_PORTAL_A_CLIENT;
const aOrderId = process.env.GESTORA_PORTAL_A_ORDER_ID;
const bUser = process.env.GESTORA_PORTAL_B_USER;
const bPassword = process.env.GESTORA_PORTAL_B_PASSWORD;
const bClient = process.env.GESTORA_PORTAL_B_CLIENT;
const bOrderId = process.env.GESTORA_PORTAL_B_ORDER_ID;

async function login(page, user, password) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#wp-submit').click(),
  ]);
}

async function openPortal(page) {
  await page.goto(new URL(portalRelative, baseURL).toString(), { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.cvd-dashboard.cvd-app-shell')).toBeVisible();
}

function assertNoInternalData(text) {
  expect(text).not.toContain('_cvd_');
  expect(text).not.toContain('DO-NOT-RENDER-4F');
  expect(text).not.toContain('idempotency');
  expect(text).not.toContain('order_key');
  expect(text).not.toContain('identity_email');
}

async function assertNoPageOverflow(page) {
  const width = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
  expect(width.scroll).toBeLessThanOrEqual(width.client + 1);
}

async function assertFinancialMobileLabels(page) {
  const expected = [
    'Pedido',
    'Fecha',
    'Cliente',
    'Producto',
    'Importe',
    'Comisión base',
    'Margen propio',
    'Total',
    'Regla aplicada',
    'Estado',
  ];
  const labels = await page.locator('.cvd-history-panel tbody tr').first().locator('td').evaluateAll((cells) =>
    cells.map((cell) => getComputedStyle(cell, '::before').content.replace(/^['"]|['"]$/g, '')),
  );
  expect(labels).toEqual(expected);
}

test('Gestora A solo ve sus clientes y finanzas en móvil', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page, aUser, aPassword);
  await openPortal(page);

  const shell = page.locator('.cvd-dashboard.cvd-app-shell');
  await expect(shell).toContainText(aClient);
  await expect(shell).toContainText(`#${aOrderId}`);
  await expect(shell).toContainText('USD');
  await expect(shell).not.toContainText(bClient);
  await expect(shell).not.toContainText(`#${bOrderId}`);

  const text = await shell.innerText();
  assertNoInternalData(text);
  await assertFinancialMobileLabels(page);
  await assertNoPageOverflow(page);
});

test('Gestora B no hereda datos del portal de Gestora A', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page, bUser, bPassword);
  await openPortal(page);

  const shell = page.locator('.cvd-dashboard.cvd-app-shell');
  await expect(shell).toContainText(bClient);
  await expect(shell).toContainText(`#${bOrderId}`);
  await expect(shell).toContainText('CUP');
  await expect(shell).not.toContainText(aClient);
  await expect(shell).not.toContainText(`#${aOrderId}`);

  const text = await shell.innerText();
  assertNoInternalData(text);
  await assertFinancialMobileLabels(page);
  await assertNoPageOverflow(page);
});
