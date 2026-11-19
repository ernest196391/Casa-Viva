import {randomBytes,createHash} from 'node:crypto';
import {mkdirSync,readFileSync,writeFileSync,renameSync} from 'node:fs';
import {dirname} from 'node:path';
const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
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
export function createProposalStore({clock=()=>Date.now(),file}={}){
  let entries=new Map();if(file){try{entries=new Map(JSON.parse(readFileSync(file,'utf8')));}catch(e){if(e.code!=='ENOENT')throw e;}}
  const persist=()=>{if(!file)return;mkdirSync(dirname(file),{recursive:true,mode:0o700});writeFileSync(file+'.tmp',JSON.stringify([...entries]),{mode:0o600});renameSync(file+'.tmp',file);};return {
    issue({houses,version,approvedBy,caseId,approvalFingerprint,expiresInMs=86400000}){
      if(approvedBy!==1||!uuid.test(caseId)||typeof approvalFingerprint!=='string'||!/^[a-f0-9]{64}$/.test(approvalFingerprint)||!Number.isInteger(version)||version<1||!Array.isArray(houses)||!houses.length||houses.length>5||!Number.isFinite(expiresInMs)||expiresInMs<=0||expiresInMs>86400000)throw Error('Aprobación o vigencia inválida');
      const safe=houses.map((h,i)=>{if(!Array.isArray(h.options)||!h.options.length||h.options.length>3)throw Error('Alternativas inválidas');return {label:`Vivienda ${i+1}`,options:h.options.map(option)};});
      const token=randomBytes(32).toString('base64url');const expires=clock()+expiresInMs;entries.set(digest(token),{version,expires,houses:safe,caseId,approvalFingerprint});persist();return {token,expires};
    },
    get(token){if(typeof token!=='string'||!/^[-_\w]{43}$/.test(token))return null;const p=entries.get(digest(token));return p&&p.expires>clock()?structuredClone(p):null;},
    revoke(token){entries.delete(digest(token));persist();},
    reference(token){const p=entries.get(digest(token));return p?{caseId:p.caseId,version:p.version}:null;},
    revokeCase(caseId){for(const [key,p] of entries)if(p.caseId===caseId)entries.delete(key);persist();}
  };
}
