#!/usr/bin/env node
import {readFile,mkdir,writeFile,rename,rm} from 'node:fs/promises';
import {resolve,join} from 'node:path';
import {createHash} from 'node:crypto';
import {pathToFileURL} from 'node:url';

const maxBytes=8*1024*1024;
function type(data){if(data.subarray(0,3).equals(Buffer.from([255,216,255])))return 'jpg';if(data.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])))return 'png';if(data.toString('ascii',0,4)==='RIFF'&&data.toString('ascii',8,12)==='WEBP')return 'webp';throw Error('Formato de imagen no permitido')}
function safeUrl(input,hosts){const url=new URL(input);if(url.protocol!=='https:'||!hosts.has(url.hostname)||url.username||url.password||url.search||url.hash||url.port)throw Error('Origen no permitido: '+url.hostname);return url}
export async function prepare(manifestFile,directory,allowedHosts=['casaviva.company']){
 const offer=JSON.parse(await readFile(manifestFile,'utf8'));
 if(!Array.isArray(offer.items)||!offer.items.length)throw Error('Sin artículos');
 const hosts=new Set(allowedHosts),dst=resolve(directory,'assets','products');await mkdir(dst,{recursive:true});
 const result=[];const ids=new Set();
 for(const p of offer.items){
  if(!/^[a-zA-Z0-9][\w.-]{1,79}$/.test(p.id)||ids.has(p.id))throw Error('SKU inseguro o repetido');ids.add(p.id);
  if(!p.imageVerified||!p.imageSource)throw Error(p.id+': verificar visualmente imagen y fuente antes de descargar');
  const url=safeUrl(p.imageUrl,hosts);
  const ctrl=new AbortController();const timer=setTimeout(()=>ctrl.abort(),12000);
  let response;try{response=await fetch(url,{redirect:'manual',signal:ctrl.signal,headers:{accept:'image/jpeg,image/png,image/webp'}})}finally{clearTimeout(timer)}
  if(!response.ok||response.status>=300&&response.status<400)throw Error(p.id+': HTTP '+response.status);
  const expected=Number(response.headers.get('content-length'));if(Number.isFinite(expected)&&expected>maxBytes)throw Error(p.id+': imagen muy grande');
  const reader=response.body?.getReader();if(!reader)throw Error('Respuesta vacía');
  let size=0;const chunks=[];for(;;){const {done,value}=await reader.read();if(done)break;size+=value.length;if(size>maxBytes){await reader.cancel();throw Error(p.id+': imagen demasiado grande')}chunks.push(Buffer.from(value))}
  const bytes=Buffer.concat(chunks);const ext=type(bytes);
  const filename=p.id+'.'+ext,target=join(dst,filename),temp=target+'.tmp-'+process.pid;
  try{await writeFile(temp,bytes,{flag:'wx',mode:0o644});await rename(temp,target)}finally{await rm(temp,{force:true}).catch(()=>{})}
  result.push({sku:p.id,file:'assets/products/'+filename,sha256:createHash('sha256').update(bytes).digest('hex'),source:url.href,bytes:bytes.length});
 }
 await writeFile(join(resolve(directory),'assets-manifest.json'),JSON.stringify({offerId:offer.offerId,images:result},null,2)+'\n');
 return result;
}
if(process.argv[1]&&import.meta.url===pathToFileURL(resolve(process.argv[1])).href){const [file,dst,...hosts]=process.argv.slice(2);if(!file||!dst){console.error('Uso: node prepare-assets.mjs oferta.json directorio [host-autorizado ...]');process.exit(2)}prepare(file,dst,hosts.length?hosts:['casaviva.company']).then(x=>console.log(JSON.stringify(x,null,2))).catch(e=>{console.error(e.message);process.exitCode=1})}