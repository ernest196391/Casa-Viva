import {randomBytes,createHash} from 'node:crypto';
const digest=s=>createHash('sha256').update(s).digest('hex');
const money=n=>{if(!Number.isFinite(n)||n<0)throw Error('Importe inválido');return Math.round(n*100);};
const clean=s=>String(s??'').slice(0,300);
function option(o){
  const p=o.estimate;if(p?.status!=='approved_estimate'||!Array.isArray(p.items)||!p.items.length)throw Error('Alternativa sin aprobar');
  const items=p.items.map(i=>{if(!Number.isInteger(i.quantity)||i.quantity<1)throw Error('Cantidad inválida');return {model:clean(i.model),quantity:i.quantity,price:money(i.price)/100,warranty:clean(i.warranty)};});
  const labor={panels:money(p.labor.panels)/100,inverters:money(p.labor.inverters)/100};const extras=money(p.extras)/100;
  const total=items.reduce((s,i)=>s+money(i.price)*i.quantity,0)+money(labor.panels)+money(labor.inverters)+money(extras);if(total!==money(p.total))throw Error('Total inconsistente');
  return {label:clean(o.label),items,labor,extras,total:total/100,installationWarranty:clean(p.installationWarranty),autonomy:clean(o.autonomy),assumptions:clean(o.assumptions),coverage:clean(o.coverage),exclusions:clean(o.exclusions)};
}
export function createProposalStore({clock=()=>Date.now()}={}){
  const entries=new Map();return {
    issue({houses,version,approvedBy,expiresInMs=86400000}){
      if(!approvedBy||!Number.isInteger(version)||version<1||!Array.isArray(houses)||!houses.length||houses.length>5||!Number.isFinite(expiresInMs)||expiresInMs<=0||expiresInMs>86400000)throw Error('Aprobación o vigencia inválida');
      const safe=houses.map((h,i)=>{if(!Array.isArray(h.options)||!h.options.length||h.options.length>3)throw Error('Alternativas inválidas');return {label:`Vivienda ${i+1}`,options:h.options.map(option)};});
      const token=randomBytes(32).toString('base64url');const expires=clock()+expiresInMs;entries.set(digest(token),{version,expires,houses:safe});return {token,expires};
    },
    get(token){if(typeof token!=='string'||!/^[-_\w]{43}$/.test(token))return null;const p=entries.get(digest(token));return p&&p.expires>clock()?structuredClone(p):null;},
    revoke(token){entries.delete(digest(token));}
  };
}
