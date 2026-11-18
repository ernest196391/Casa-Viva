#!/usr/bin/env node
import {readFile,stat} from 'node:fs/promises';
import {resolve,sep} from 'node:path';
import {pathToFileURL} from 'node:url';

const errors=[];
const fail=(msg)=>errors.push(msg);
const validDate=x=>typeof x==='string'&&Number.isFinite(Date.parse(x));
const validHttps=x=>{try{const u=new URL(x);return u.protocol==='https:'&&!u.username&&!u.password}catch{return false}};
export async function validate(manifestFile,assetDir,{draft=false}={}){
 errors.length=0;
 let offer;try{offer=JSON.parse(await readFile(manifestFile,'utf8'))}catch(e){throw Error('Manifiesto inválido: '+e.message)}
 if(!offer.offerId||!/^[a-z0-9][a-z0-9-]{2,79}$/i.test(offer.offerId))fail('offerId inválido');
 if(!offer.title||typeof offer.title!=='string')fail('Falta título');
 if(!validDate(offer.updatedAt))fail('updatedAt inválido');
 if(typeof offer.currency!=='string'||!['USD','CUP','EUR'].includes(offer.currency))fail('Moneda inválida');
 if(!offer.payment?.method||!offer.payment?.status)fail('Falta estado del pago');
 if(!offer.delivery?.status)fail('Falta estado de la entrega');
 if(!/^\d{9,15}$/.test(offer.contact?.whatsapp||''))fail('WhatsApp inválido');
 if(!Array.isArray(offer.items)||!offer.items.length)fail('Debe haber artículos');
 let assetManifest=null;
 if(assetDir&&!draft)try{assetManifest=JSON.parse(await readFile(resolve(assetDir,'assets-manifest.json'),'utf8'))}catch{fail('Falta assets-manifest.json')}
 const ids=new Set();
 for(const item of offer.items||[]){
  const id=item?.id||'sin-id';
  if(ids.has(id))fail('SKU duplicado: '+id);ids.add(id);
  if(!/^[a-z0-9][a-z0-9_.-]{1,79}$/i.test(id))fail('SKU inválido: '+id);
  if(typeof item.title!=='string'||!item.title.trim())fail(id+': falta título');
  if(!validHttps(item.productUrl))fail(id+': URL de producto HTTPS obligatoria');
  if(!Number.isSafeInteger(item.priceCents)||item.priceCents<0)fail(id+': precio en centavos inválido');
  if(!Number.isSafeInteger(item.packSize)||item.packSize<1)fail(id+': packSize inválido');
  if(!validDate(item.priceVerifiedAt)||!item.source)fail(id+': falta precio verificado y fuente');
  if(!['confirmed','pending','unavailable'].includes(item.availability))fail(id+': disponibilidad inválida');
  if(item.availability==='confirmed'&&!validDate(item.availabilityVerifiedAt))fail(id+': falta fecha del stock');
  if(!draft&&item.availability!=='confirmed')fail(id+': solo artículos disponibles se pueden ofertar');
  if(!draft&&(!validHttps(item.imageUrl)||item.imageVerified!==true||!item.imageSource))fail(id+': foto original no verificada');
  if(assetDir&&!draft){
   const img=assetManifest?.images?.find(x=>x.sku===id);
   if(!img||!/^assets\/products\/[a-z0-9_.-]+\.(jpg|png|webp)$/i.test(img.file))fail(id+': falta registro de imagen local');
   const f=resolve(assetDir,img?.file||'assets/products/'+id+'.jpg');
   const base=resolve(assetDir)+sep;
   if(!f.startsWith(base))fail(id+': ruta insegura');
   else try{const meta=await stat(f);if(!meta.isFile()||meta.size===0)fail(id+': imagen vacía')}catch{fail(id+': falta foto descargada '+f)}
  }
 }
 return {ok:errors.length===0,errors:[...errors],items:offer.items?.length||0};
}
if(process.argv[1]&&import.meta.url===pathToFileURL(resolve(process.argv[1])).href){
 const file=process.argv[2],dir=process.argv[3],draft=process.argv.includes('--draft');
 if(!file){console.error('Uso: node validate-offer.mjs oferta.json [directorio] [--draft]');process.exit(2)}
 try{const result=await validate(file,dir,{draft});console.log(JSON.stringify(result,null,2));if(!result.ok)process.exitCode=1}catch(e){console.error(e.message);process.exitCode=1}
}