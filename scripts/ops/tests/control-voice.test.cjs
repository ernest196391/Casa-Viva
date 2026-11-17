// Run: node --test scripts/ops/tests/control-voice.test.cjs
async function run(){
const {test}=await import('node:test');
const {default:assert}=await import('node:assert/strict');
const {default:fs}=await import('node:fs');
const {default:path}=await import('node:path');
const source=fs.readFileSync(path.join(__dirname,'../control-web/app.js'),'utf8');
const install=new Function(source.slice(source.indexOf('function installVoiceInput'),source.indexOf('const voiceInput='))+';return installVoiceInput;')();
class Element {
 constructor(){this.events={};this.attrs={};this.children=[];this.value='';this.hidden=false;}
 setAttribute(k,v){this.attrs[k]=v;}
 append(...children){this.children.push(...children);}
 after(node){this.next=node;}
 addEventListener(k,f){this.events[k]=f;}
 fire(k){this.events[k]?.();}
}
function fixture(){
 let last, consent=false;
 class Recognition{constructor(){last={start(){this.started=true;},stop(){this.stopped=true;},abort(){this.aborted=true;}};return last;}}
 const field=new Element(),form=new Element(),panel=new Element();
 const doc={createElement:()=>new Element(),createElementNS:()=>new Element()};
 const controller=install({form,field,panel,Recognition,confirmSpeech:()=>consent,doc});
 return {field,form,panel,controller,mic:field.next.children[1],allow:()=>consent=true,last:()=>last};
}
function finish(r,text){const result=[{transcript:text}];result.isFinal=true;r.onresult({resultIndex:0,results:[result]});r.onend();}
test('explicit permission and editable draft, no automatic submission',()=>{
 const f=fixture();f.mic.fire('click');assert.equal(f.last(),undefined);
 f.allow();f.field.value='Por favor';f.mic.fire('click');assert.equal(f.last().started,true);
 finish(f.last(),'revisa el sistema');assert.equal(f.field.value,'Por favor revisa el sistema');
});
test('typing, closing and submitting abort; late results ignored',()=>{
 const f=fixture();f.allow();f.mic.fire('click');let r=f.last();f.field.fire('input');assert.equal(r.aborted,true);finish(r,'ignorar');assert.equal(f.field.value,'');
 f.mic.fire('click');r=f.last();f.controller.cancel();assert.equal(r.aborted,true);
 f.mic.fire('click');r=f.last();f.form.fire('submit');assert.equal(r.aborted,true);
});
test('hidden panel never starts recording',()=>{
 const f=fixture();f.allow();f.panel.hidden=true;f.mic.fire('click');assert.equal(f.last(),undefined);
});
test('unsupported API provides keyboard fallback',()=>{
 const field=new Element();
 assert.equal(install({form:new Element(),field,panel:new Element(),doc:{createElement:()=>new Element()}}),null);
 assert.match(field.next.textContent,/teclado/);
});

}
run().catch(error=>{console.error(error);process.exitCode=1;});
