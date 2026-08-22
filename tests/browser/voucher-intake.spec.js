/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');
const baseURL = process.env.ORDER_CENTER_BASE_URL || 'http://localhost:8889';
const gestora = { user: process.env.GESTORA_PORTAL_A_USER, pass: process.env.GESTORA_PORTAL_A_PASSWORD };
test('entrada de vales degrada sin crear pedidos cuando NEXO falla', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto(`${baseURL}/wp-login.php`); await page.locator('#user_login').fill(gestora.user); await page.locator('#user_pass').fill(gestora.pass);
  await Promise.all([page.waitForURL(url=>!url.pathname.includes('wp-login.php')),page.locator('#wp-submit').click()]);
  await page.route('**/wp-json/casa-viva/v1/voucher/parse', route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'NEXO no está disponible. No se creó ningún pedido.'})}));
  await page.goto(`${baseURL}/interpretar-vale/`,{waitUntil:'domcontentloaded'});
  await page.locator('#cvd-voucher-text').fill('Vale sintético de prueba con cliente, producto, teléfono y dirección; no contiene PII real.');
  await page.locator('[data-voucher-parse]').click();
  await expect(page.locator('[role=status]')).toContainText('No se creó ningún pedido');
  await expect(page.locator('[data-voucher-review]')).toBeHidden();
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth); expect(overflow).toBeLessThanOrEqual(1);
});
