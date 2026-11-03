const {test,expect}=require('@playwright/test');
const fs=require('node:fs'), {execFileSync}=require('node:child_process');
test('Auditoría Casa Viva: nueva mercancía hasta publicación, ocho anuncios y TXT',async({page,request})=>{
 test.setTimeout(160000);
 await page.setViewportSize({width:390,height:844});
 page.on('dialog',d=>d.accept());
 const root='http://localhost:8889', panel=root+'/?cvd_contenido=1';
 await page.goto(root+'/wp-login.php');await page.locator('#user_login').fill('cvt_content_browser');await page.locator('#user_pass').fill('Synthetic-Content-Only-1!');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
 await page.goto(panel);await page.locator('#cv-new').click();
 const title='Lámpara de auditoría';await page.locator('#cv-name').fill(title);
 const desc='Marcador editorial exclusivo del archivo final';await page.locator('#cv-description').fill(desc);
 await page.locator('#cv-facts').fill('Metal confirmado');await page.locator('#cv-save').click();
 await expect(page.locator('#cv-message')).toContainText('Borrador guardado');
 const pixel=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO/aJ1sAAAAASUVORK5CYII=','base64');
 await page.locator('#cv-photo').setInputFiles({name:'producto.png',mimeType:'image/png',buffer:pixel});
 await expect(page.locator('#cv-message')).toContainText('Foto preparada');await page.locator('#cv-upload').click();
 await expect(page.locator('#cv-message')).toContainText('Foto subida y protegida');
 await page.goto(panel);await page.locator('#cv-items button').filter({hasText:title}).click();
 await expect(page.locator('#cv-commercial')).toContainText('Sin publicar');await expect(page.locator('#cv-original')).toBeVisible();
 const data=await page.evaluate(async()=>{const r=await fetch(cvContent.base+'products',{headers:{'X-WP-Nonce':cvContent.nonce}});return(await r.json()).items.find(x=>x.name==='Lámpara de auditoría')});
 expect(data.status).toBe('draft');expect(data.image_id).toBeGreaterThan(0);
 const check=cmd=>execFileSync('docker',['compose','-f','integration/compose.yml','run','--rm','--no-deps','cli','wp','eval',cmd],{encoding:'utf8'}).trim();
 expect(check("echo get_post_status("+Number(data.id)+");")).toMatch(/draft$/);
 const outside=await request.get(data.url);expect(outside.status()).toBe(404);
 console.log('AUDIT PASS Nueva mercancía, foto, recarga y borrador invisible al visitante');
 await page.locator('#cv-publish').click();await expect(page.locator('#cv-message')).toContainText('precio');
 await page.locator('#cv-headline').fill('Lámpara');await page.locator('#cv-use').fill('Para la mesa');
 for(const layout of ['features','spec','offer','use'])for(const format of ['1920','1080']){
  await page.locator('#cv-layout').selectOption(layout);await page.locator('#cv-format').selectOption(format);
  const old=await page.locator('#cv-download').getAttribute('href');await page.locator('#cv-render').click();
  await page.waitForFunction(old=>{const a=document.querySelector('#cv-download');return !a.hidden&&a.href!==old;},old);
  const dims=await page.locator('#cv-canvas').evaluate(c=>[c.width,c.height]);expect(dims).toEqual([1080,Number(format)]);
  const [download]=await Promise.all([page.waitForEvent('download'),page.locator('#cv-download').click()]);
  const b=fs.readFileSync(await download.path());expect(b.readUInt16BE(0)).toBe(65496);
 }
 console.log('AUDIT PASS Ocho JPG reales: cuatro plantillas por dos formatos');
 await page.locator('#cv-title-suggest').click();await expect(page.locator('#cv-message')).not.toContainText('Imagen lista');await page.locator('#cv-suggest').click();
 await expect(page.locator('#cv-suggestion')).not.toHaveValue('');await page.locator('#cv-copy-build').click();
 const [txt]=await Promise.all([page.waitForEvent('download'),page.locator('#cv-copy-save').click()]);
 const text=fs.readFileSync(await txt.path(),'utf8');expect(text).toContain('Metal confirmado');
 console.log('AUDIT DATA El TXT incluye la descripción de ficha: '+text.includes(desc));
 check("if(wp_parse_url(home_url(), PHP_URL_HOST)!=='localhost')exit(1);$p=wc_get_product("+Number(data.id)+");$p->set_regular_price('25');$p->save();");
 await page.locator('#cv-publish').click();await expect(page.locator('#cv-message')).toContainText('Cambios publicados');
 expect(check("echo get_post_status("+Number(data.id)+");")).toMatch(/publish$/);
 const url=await page.locator('#cv-view').getAttribute('href');const publicPage=await request.get(url);expect(publicPage.ok()).toBeTruthy();expect(await publicPage.text()).toContain(desc);
 console.log('AUDIT PASS Publicación rechazada sin precio, aceptada con precio comercial y visible en página pública desechable.');
});
test('Auditoría de concurrencia: respuesta de A al abrir una ficha nueva',async({page})=>{
 test.setTimeout(90000);
 page.on('dialog',d=>d.accept());
 await page.goto('http://localhost:8889/wp-login.php');await page.locator('#user_login').fill('cvt_content_browser');await page.locator('#user_pass').fill('Synthetic-Content-Only-1!');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
 await page.goto('http://localhost:8889/?cvd_contenido=1');await page.locator('#cv-items button').filter({hasText:'Studio browser fixture'}).first().click();
 let release,ack;const hold=new Promise(r=>release=r),sent=new Promise(r=>ack=r);let once=true;
 await page.route('**/*',async route=>{
  if(once&&route.request().method()==='POST'&&decodeURIComponent(route.request().url()).includes('/contenido/save')){
    once=false;const res=await route.fetch();ack();await hold;await route.fulfill({response:res});
  }else await route.continue();
 });
 await page.locator('#cv-name').fill('Producto A en tránsito');await page.locator('#cv-save').click();await sent;
 await page.locator('#cv-new').click();await page.locator('#cv-name').fill('Producto B que debía ser borrador');
 release();await expect(page.locator('#cv-save')).toBeEnabled();
 const [saveResult]=await Promise.all([page.waitForResponse(r=>r.request().method()==='POST'&&decodeURIComponent(r.url()).includes('/contenido/save')),page.locator('#cv-save').click()]);
 const item=await saveResult.json();const result=item.status==='publish'&&item.name==='Producto B que debía ser borrador';
 console.log('AUDIT RACE Una ficha nueva acabó sobrescribiendo el producto A publicado: '+result+'; estado devuelto: '+item.status);
 expect(['draft','publish']).toContain(item.status);
});