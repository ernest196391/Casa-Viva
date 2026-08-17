/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');

const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const pageId = Number(process.env.ORDER_CENTER_PAGE_ID);
const incidentId = Number(process.env.ORDER_CENTER_INCIDENT_ID);
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

async function openOrder(page) {
  const url = pageId > 0
    ? `${baseURL}/?page_id=${pageId}&order_id=${incidentId}`
    : `${baseURL}/centro-pedido/?order_id=${incidentId}`;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  expect(response && response.ok()).toBeTruthy();
  await expect(page.locator('.cvd-oc-head')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('#cvd-structured-incidents')).toBeVisible({ timeout: 15000 });
}

async function submitIncident(page, buttonName) {
  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' && response.url().includes('/structured-incidents/'),
    { timeout: 15000 }
  );
  await page.locator('#cvd-structured-incidents').getByRole('button', { name: buttonName }).click();
  const response = await responsePromise;
  expect(response.ok()).toBeTruthy();
  return response.json();
}

test('dependienta abre y resuelve incidencia estructurada sin cambiar la etapa', async ({ page }) => {
  await login(page);
  await openOrder(page);

  await expect(page.locator('.cvd-oc-stage')).toContainText(/READY/i);
  const panel = page.locator('#cvd-structured-incidents');
  await expect(panel.getByRole('button', { name: 'Registrar incidencia' })).toBeVisible();
  await expect(panel.locator('select[name="reason"]')).toContainText('Cliente no recoge');
  await panel.locator('select[name="reason"]').selectOption('customer_no_show');
  await panel.locator('textarea[name="note"]').fill('Cliente no acudió a la recogida acordada.');
  const opened = await submitIncident(page, 'Registrar incidencia');
  expect(opened.transition.success).toBeTruthy();
  expect(opened.incident.active.active).toBeTruthy();

  await expect(page.locator('#cvd-structured-incidents')).toContainText('Cliente no recoge', { timeout: 15000 });
  await expect(page.locator('.cvd-oc-stage')).toContainText(/READY/i);

  const activePanel = page.locator('#cvd-structured-incidents');
  const restConfig = await page.evaluate(() => ({
    url: window.cvdStructuredIncidents.url,
    nonce: window.cvdStructuredIncidents.nonce,
  }));
  await activePanel.locator('textarea[name="note"]').fill('Cliente confirmó nueva hora de recogida.');
  const resolved = await submitIncident(page, 'Resolver incidencia');
  expect(resolved.transition.success).toBeTruthy();
  expect(resolved.incident.active.active).toBeFalsy();
  expect(resolved.incident.historyCount).toBe(2);

  await expect(page.locator('#cvd-structured-incidents').getByRole('button', { name: 'Registrar incidencia' })).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.cvd-oc-stage')).toContainText(/READY/i);

  // Resolution schedules a page reload. Audit through the API request context
  // using configuration captured before resolution so navigation cannot race it.
  const auditResponse = await page.request.get(`${restConfig.url}${incidentId}`, {
    headers: { 'X-WP-Nonce': restConfig.nonce },
  });
  expect(auditResponse.ok()).toBeTruthy();
  const audit = await auditResponse.json();
  expect(audit.active.active).toBeFalsy();
  expect(audit.historyCount).toBe(2);
});
