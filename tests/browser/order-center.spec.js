/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const pageId = Number(process.env.ORDER_CENTER_PAGE_ID);
const ids = {
  new: Number(process.env.ORDER_CENTER_NEW_ID),
  ready: Number(process.env.ORDER_CENTER_READY_ID),
  handed: Number(process.env.ORDER_CENTER_HANDED_ID),
  conflict: Number(process.env.ORDER_CENTER_CONFLICT_ID),
};
const admin = { user: process.env.ORDER_CENTER_ADMIN_USER, pass: process.env.ORDER_CENTER_ADMIN_PASSWORD };
const clerk = { user: process.env.ORDER_CENTER_CLERK_USER, pass: process.env.ORDER_CENTER_CLERK_PASSWORD };
const shotDir = path.join(process.cwd(), 'test-results', 'order-center');
fs.mkdirSync(shotDir, { recursive: true });

async function login(page, credentials) {
  await page.goto(`${baseURL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(credentials.user);
  await page.locator('#user_pass').fill(credentials.pass);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 15000 }),
    page.locator('#wp-submit').click(),
  ]);
  expect(new URL(page.url()).pathname).not.toContain('wp-login.php');
}

async function openOrder(page, id) {
  const orderCenterURL = pageId > 0
    ? `${baseURL}/?page_id=${pageId}&order_id=${id}`
    : `${baseURL}/centro-pedido/?order_id=${id}`;
  const response = await page.goto(orderCenterURL, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  expect(new URL(page.url()).pathname).not.toContain('wp-login.php');
  await expect(page.locator('.cvd-oc-head')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.cvd-oc-stage')).not.toHaveText('');
  await expect(page.locator('.cvd-oc-card').filter({ hasText: 'Productos' })).toBeVisible();
  await expect(page.locator('.cvd-oc-card').filter({ hasText: 'Historial' })).toBeVisible();
}

async function assertLayout(page) {
  const layout = await page.evaluate(() => {
    const width = window.innerWidth;
    const doc = document.documentElement;
    const candidates = [...document.querySelectorAll('.cvd-oc-card, .cvd-oc-head, .cvd-oc-primary button')];
    const bad = candidates.map((el) => {
      const r = el.getBoundingClientRect();
      return { tag: el.tagName, left: r.left, right: r.right, width: r.width };
    }).filter((r) => r.left < -1 || r.right > width + 1 || r.width > width + 1);
    return { width, scrollWidth: doc.scrollWidth, bad };
  });
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.width + 1);
  expect(layout.bad).toEqual([]);
}

for (const viewport of [
  { name: '360', width: 360, height: 800 },
  { name: '390', width: 390, height: 844 },
  { name: '430', width: 430, height: 932 },
  { name: 'desktop', width: 1440, height: 900 },
]) {
  test(`render real sin overflow ${viewport.name}`, async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page, admin);
    await openOrder(page, ids.ready);
    await assertLayout(page);
    expect(errors).toEqual([]);
    await page.screenshot({ path: path.join(shotDir, `order-center-${viewport.name}.png`), fullPage: true });
    await context.close();
  });
}

test('estados ready, handed_over y conflict se proyectan correctamente', async ({ page }) => {
  await login(page, admin);
  await openOrder(page, ids.ready);
  await expect(page.locator('.cvd-oc-stage')).toContainText(/READY/i);

  await openOrder(page, ids.handed);
  await expect(page.locator('.cvd-oc-stage')).toHaveText('ON_THE_WAY_TO_CUSTOMER');
  await expect(page.locator('.cvd-oc-card').filter({ hasText: 'Mensajería' })).toContainText('cvt_messenger');

  await openOrder(page, ids.conflict);
  await expect(page.getByText('Revisión requerida')).toBeVisible();
  await expect(page.locator('.cvd-oc-primary button')).toHaveCount(0);
});

test('dependienta no recibe diagnósticos internos ni id de gestora', async ({ page }) => {
  await login(page, clerk);
  await openOrder(page, ids.handed);
  const payload = await page.evaluate(async (id) => {
    const response = await fetch(`${window.cvdOrderCenter.url}${id}`, {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.cvdOrderCenter.nonce },
    });
    return response.json();
  }, ids.handed);
  expect(payload.gestora.id).toBeUndefined();
  expect(payload.consistency.reasons).toEqual([]);
  expect(documentTextHasTechnicalIds(await page.locator('body').innerText())).toBeFalsy();
});

function documentTextHasTechnicalIds(text) {
  return /\bgestora[_ -]?id\b|\bactor[_ -]?id\b|\binternal\b/i.test(text);
}

test('acción real new a preparing y polling actualizan la vista', async ({ page }) => {
  await login(page, clerk);
  await openOrder(page, ids.new);
  await expect(page.locator('.cvd-oc-stage')).toHaveText('CREATED');
  const primary = page.locator('.cvd-oc-primary button');
  await expect(primary).toHaveText('Comenzar preparación');
  page.once('dialog', (dialog) => dialog.accept());
  await primary.click();
  await expect(page.locator('.cvd-oc-stage')).toHaveText('PREPARING');
  await expect(page.locator('.cvd-oc-primary button')).toHaveText('Pedido listo');

  await page.evaluate(async (id) => {
    const response = await fetch(`${window.cvdOrderCenter.url}${id}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.cvdOrderCenter.nonce,
        'X-CVD-Idempotency-Key': `browser-ready-${id}`,
      },
      body: JSON.stringify({ action_id: 'operation_ready' }),
    });
    if (!response.ok) throw new Error(await response.text());
  }, ids.new);

  await expect(page.locator('.cvd-oc-stage')).toHaveText(/READY/, { timeout: 12000 });
  await expect(page.locator('.cvd-oc-card').filter({ hasText: 'Historial' })).toContainText(/ready/i);
});
