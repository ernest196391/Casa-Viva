/* eslint-disable @typescript-eslint/no-require-imports */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const dir = path.join(process.cwd(), 'scripts/ops/control-web');
const source = fs.readFileSync(path.join(dir, 'index.php'), 'utf8');
// This is a synthetic DOM contract test, not a WordPress authentication test.
// PHP permission predicates are separately exercised by control-web-access.php.
const markup = source.slice(source.lastIndexOf('<!doctype html>')).replace(/<\?php[\s\S]*?\?>/g, '');
const tabs = ['home','work','today','incidents','orders','conversations','developers','ai','system'];
function fixture() {
  const at = new Date().toISOString();
  return {at, approval_complete:true, pending_complete:true, decisions:[],
    tasks:[{id:8,title:'Revisión de prueba',status:'waiting_approval',executor:'dev',source:'control-private',created_at:at}],
    reviews_blocked:[{id:8,title:'Revisión de prueba',status:'waiting_approval'}],
    findings:[{title:'Incidencia de prueba',detail:'Texto sintético',priority:'p1'}],lennys:[],
    system:{ok:true},programmer:{worker_status:'active'},activity:[]};
}
for (const width of [320,360,390,768]) {
  test('Control OS: navegación y seguridad visual a '+width+'px', async ({page}) => {
    await page.setViewportSize({width,height:800});
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const data=fixture();
    await page.route('http://control.test/**',async route=>{
      const url=new URL(route.request().url());
      if(url.searchParams.get('action')==='state')return route.fulfill({json:{success:true,data}});
      if(url.pathname.endsWith('app.js'))return route.fulfill({body:fs.readFileSync(path.join(dir,'app.js')),contentType:'application/javascript'});
      if(url.pathname.endsWith('style.css'))return route.fulfill({body:fs.readFileSync(path.join(dir,'style.css')),contentType:'text/css'});
      return route.fulfill({body:markup.replace('name="cv-access" content=""','name="cv-access" content="write"'),contentType:'text/html'});
    });
    await page.goto('http://control.test/control/');
    await expect(page.locator('#home-list .overview-card').first()).toBeVisible();
    for (const tab of tabs) {
      await page.locator('[data-tab="'+tab+'"]').click();
      await expect(page.locator('#'+tab)).toBeVisible();
      const dims=await page.evaluate(()=>({client:document.documentElement.clientWidth,scroll:document.documentElement.scrollWidth}));
      expect(dims.scroll,tab).toBeLessThanOrEqual(dims.client+1);
    }
    await page.locator('[data-tab="today"]').click();
    await expect(page.locator('#decision-count')).toHaveText('0');
    await expect(page.locator('[data-decision]')).toHaveCount(0);
    await expect(page.locator('#approval-review-list')).toContainText('Revisión de prueba');
    await page.locator('[data-tab="developers"]').click();
    await expect(page.locator('#developer-list')).toContainText('Revisión de prueba');
    expect(errors).toEqual([]);
  });
}
