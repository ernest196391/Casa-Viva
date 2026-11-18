import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../wordpress/casa-viva-dropship-core/assets/sales.js', import.meta.url), 'utf8');
const section = source.slice(source.indexOf('  var editDialog ='), source.indexOf('  // ---------- Dividir pedido'));
const handlers = {};
const payloads = [];
const message = {};
const submit = {};
const dialog = {addEventListener: (name, fn) => handlers[name] = fn, showModal(){}, close(){}, querySelector(){return message;}};
let order;
const context = vm.createContext({document:{createElement:()=>dialog,getElementById:()=>submit}, list:{parentNode:{appendChild(){}}}, message, escapeText:x=>String(x??''), cvdSales:{url:'/sales'}, api:async(url,options)=>{if(options.method==='GET')return order;payloads.push(JSON.parse(options.body));return {};}, load:async()=>{}});
vm.runInContext(section, context);
async function exercise(pickup, firstName, phone, newName, newPhone){
  order={id:1,number:1,editable:true,pickup,firstName,phone,lines:[{itemId:10,name:'Test',qty:1}],lastName:'',note:''};
  // Delivery branch uses the existing helper.
  context.changeText=()=>'';
  await context.openEdit(1);
  const name=dialog.innerHTML.match(/<input name="firstName"[^>]*>/)[0];
  const tel=dialog.innerHTML.match(/<input name="phone"[^>]*>/)[0];
  assert.equal(name.includes(' required'),Boolean(firstName));
  assert.equal(tel.includes(' required'),!pickup);
  const elements={firstName:{value:newName},lastName:{value:''},phone:{value:newPhone},note:{value:''}};
  await handlers.submit({preventDefault(){},target:{elements,querySelectorAll(){return [{dataset:{line:'10'},value:'2'}];}}});
  return payloads.at(-1);
}
let p=await exercise(true,'','','','');
assert.equal('firstName' in p,false);assert.equal('phone' in p,false);assert.equal(p.lines[0].qty,2);
p=await exercise(true,'','','Rosa','55555555');assert.equal(p.firstName,'Rosa');assert.equal(p.phone,'55555555');
p=await exercise(false,'Ana','55555555','Rosa','55555555');assert.equal(p.firstName,'Rosa');assert.equal(p.phone,'55555555');
console.log('3 scenarios passed: anonymous pickup, identity correction, delivery.');
