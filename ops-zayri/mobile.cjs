const { chromium } = require('playwright');
const fs = require('node:fs'), crypto = require('node:crypto'), path = require('node:path'), assert = require('node:assert/strict');
const tmp=process.env.RUNNER_TEMP, cfg=JSON.parse(fs.readFileSync(path.join(tmp,'browser.json'))), root='https://casaviva.company';
const expected='27959029d4b7ed24ce4a0713696143bb69231bb8cfb405a08e933ce99f529ae6';
const hash = b => crypto.createHash('sha256').update(b).digest('hex');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  async function openProduct(){
   const ctx=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2,serviceWorkers:'block'});
   await ctx.addCookies(cfg.cookies);
   const page=await ctx.newPage(); page.setDefaultTimeout(40000);
   await page.goto(root+'/?cvd_contenido=1',{waitUntil:'domcontentloaded'});
   await page.locator('#cv-search input[type=search]').fill('Cortinero extensible');
   await page.locator('#cv-search button[type=submit],#cv-search button:not([type])').first().click();
   const item=page.locator('#cv-items button').filter({hasText:/122.*213.*espiral/i}).first();
   await item.click();await page.locator('#cv-editor').waitFor();
   if(!/espiral/i.test(await page.locator('#cv-name').inputValue()))throw Error('Producto equivocado.');
   return {ctx,page};
  }
  let {page,ctx}=await openProduct();
  await page.locator('#cv-photo').setInputFiles(path.join(tmp,'reference.jpg'));
  await page.waitForFunction(()=>document.querySelector('#cv-message').textContent.includes('Foto preparada'));
  await page.locator('#cv-provider').selectOption('cloudflare');await page.locator('#cv-layout').selectOption('spec');
  await page.locator('#cv-format').selectOption('1920');
  const apiWait=page.waitForResponse(r=>decodeURIComponent(r.url()).includes('/contenido/ia/generate'),{timeout:140000});
  await page.locator('#cv-generate').click();
  const response=await apiWait;if(response.status()!==200)throw Error('Cloudflare devolvió HTTP '+response.status());
  const card=page.locator('#cv-variants article').last();
  await card.locator('a[download]').waitFor();
  const generated=await card.locator('a[download]').getAttribute('href');
  assert.ok(generated.startsWith('data:image/png;base64,'));
  const bytes=Buffer.from(generated.split(',')[1],'base64');
  assert.equal(bytes.readUInt32BE(16),576); assert.equal(bytes.readUInt32BE(20),1024);
  await card.getByRole('button',{name:'Guardar para revisar después'}).click();
  await page.waitForFunction(()=>document.querySelector('#cv-ai-status').textContent.includes('guardada en tu perfil'));
  await ctx.close();({page,ctx}=await openProduct());
  const review=page.locator('#cv-variants article').filter({hasText:'Guardada para revisar'}).first();
  await review.locator('a[download]').waitFor();
  assert.equal(await review.locator('a[download]').getAttribute('href'),generated);
  console.log('PASS Móvil de 390 px, perfil de Zayri, referencia real, generación Cloudflare, composición PNG 576×1024, recuperación privada en sesión nueva.');
  const approved=fs.readFileSync(path.join(tmp,'approved.png'));assert.equal(hash(approved),expected);
  // Restore the exact previously approved file after exercising the normal mobile review workflow.
  await page.evaluate(async ({id,base64})=>{
    const base=window.cvContent.base;
    const headers={'X-WP-Nonce':window.cvContent.nonce};
    const url=new URL(base+'products',location.href);url.searchParams.set('id',id);
    const res=await fetch(url,{headers});if(!res.ok)throw Error('No se puede leer el producto.');
    const product=await res.json();const body=new FormData();body.append('id',id);body.append('version',product.version);
    body.append('photo',await (await fetch('data:image/png;base64,'+base64)).blob(),'cortinero-revisado.png');
    const result=await fetch(base+'review',{method:'POST',headers,body});
    if(!result.ok)throw Error('No se pudo dejar la propuesta aprobada.');
  },{id:cfg.id,base64:approved.toString('base64')});
  await ctx.close();({page,ctx}=await openProduct());
  const saved=page.locator('#cv-variants article').filter({hasText:'Guardada para revisar'}).first();
  await saved.getByRole('button',{name:/Elegir opción .* como foto principal/}).waitFor();
  const href=await saved.locator('a[download]').getAttribute('href');
  assert.equal(hash(Buffer.from(href.split(',')[1],'base64')),expected);
  console.log('PASS Imagen aprobada disponible para elegir en el perfil de Zayri, SHA-256 '+expected);
 }finally{await browser.close();}
})().catch(e=>{console.error(String(e.message).replace(/data:image[^\s]*/g,'[imagen]').slice(0,1400));process.exitCode=1;});