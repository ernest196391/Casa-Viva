import {createHash} from 'node:crypto';
import {patchCatalogIntent} from './receiver-catalog-intent-candidate.mjs';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
const {parse}=createRequire(import.meta.url)('internal/deps/acorn/acorn/dist/acorn');
const [receiver,dialog,actions,confirm,products]=JSON.parse(readFileSync(0,'utf8'));
assert.equal(createHash('sha256').update(receiver).digest('hex'),'b4c6477da70dff515367038ba6abf6dbe722b323e05227216d160dddb0dca92f');
function extract(source,names){
 const nodes=parse(source,{ecmaVersion:'latest',sourceType:'module'}).body.map(x=>x.declaration??x);
 return names.map(name=>{
  const n=nodes.find(x=>x.id?.name===name || x.declarations?.some(d=>d.id.name===name));
  assert.ok(n,name); return source.slice(n.start,n.end);
 }).join('\n');
}
function build(d){
 const code=[
 extract(receiver,['WEB','classify','strip','catalogLine','answer']),
 extract(d,['naturalIntent','dialogText']),
 extract(actions,['actionCommand']),
 'const acceptWithNumber = () => null;'
 ].join('\n');
 return new Function(code+'\nreturn {classify,answer};')();
}
const now=Date.now();
const message=conversation=>({key:{remoteJid:'10000000001@s.whatsapp.net',fromMe:false,id:'CV-SYNTHETIC'},message:{conversation},messageTimestamp:Math.floor(now/1000)});
const question='Hola, ¿qué ventiladores recargables tienen disponibles?';
assert.equal(build(dialog).classify(message(question),now,''),null);
const candidate=build(patchCatalogIntent(dialog));
const greeting=candidate.classify(message('Hola'),now,'');
assert.equal(greeting.kind,'dialog'); assert.equal(greeting.topic,'hello');
assert.match(await candidate.answer(greeting,()=>{throw Error('unexpected catalog call')}),/Casa Viva/);
const command=candidate.classify(message('pasar #6802 a Pedro'),now,'');
assert.equal(command.kind,'valepass'); assert.equal(command.order,6802);
const intent=candidate.classify(message(question),now,'');
assert.equal(intent.kind,'search'); assert.equal(intent.query,'ventilador recargable');
let calls=0;
const reply=await candidate.answer(intent,async (url,options)=>{
 calls++;
 assert.equal(url.origin,'https://casaviva.company'); assert.equal(url.pathname,'/wp-json/wc/store/v1/products');
 assert.equal(url.searchParams.get('search'),'ventilador recargable'); assert.equal(url.searchParams.get('per_page'),'3');
 assert.equal(options.method??'GET','GET');
 return {ok:true,json:async()=>products};
});
assert.equal(calls,1);
const sku=products.find(p=>p.id===1019);
assert.ok(sku && sku.is_in_stock===true);
assert.match(reply,/72\.00 USD/); assert.ok(/^MK-\d+$/i.test(String(sku.sku||'')) || sku.is_on_backorder===true);
assert.match(reply,/Bajo pedido: confirmar antes de cobrar/); assert.match(reply,/RA12RSL/);
console.log(JSON.stringify({baseline:'no_intent',greeting:greeting.topic,dispatch:command.kind,catalogCalls:calls,reply}));
