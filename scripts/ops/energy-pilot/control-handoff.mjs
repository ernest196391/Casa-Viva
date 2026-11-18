import {createHash} from 'node:crypto';
import {readFile,writeFile,rename,mkdir} from 'node:fs/promises';
import {join} from 'node:path';
const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const hash=x=>createHash('sha256').update(JSON.stringify(x)).digest('hex');
// Private pilot adapter. The existing authenticated Control OS task form is the only owner-request writer.
export function controlRequest(caseId){if(!uuid.test(caseId))throw Error('Expediente inválido');return {reference:`energy:${caseId}`,url:'https://casaviva.company/control/',text:`ENERGÍA PILOTO · Expediente ${caseId}. Revisar diagnóstico privado, validar disponibilidad, compatibilidad y cotización. Preparar borrador para aprobación expresa de Ernesto. No cotizar ni enviar al cliente automáticamente.`};}
export function proposalFingerprint(draft){return hash(draft);}
export function assertControlApproval({decision,caseId,version,fingerprint,actorWpId}){
 if(actorWpId!==1||!uuid.test(caseId)||!Number.isInteger(version)||version<1||typeof fingerprint!=='string'||!/^\w{64}$/.test(fingerprint)||!decision||decision.status!=='approved'||decision.actorWpId!==1||decision.caseId!==caseId||decision.version!==version||decision.fingerprint!==fingerprint)throw Error('Aprobación de Ernesto no vinculada a esta versión');
 return true;
}
// Atomic private records, single-process pilot. Never store this directory under web root.
export function createCaseRepository(directory){
 let queue=Promise.resolve();const locked=fn=>{const next=queue.then(fn);queue=next.catch(()=>{});return next;};
 const path=id=>{if(!uuid.test(id))throw Error('Expediente inválido');return join(directory,id+'.json');};
 const read=async id=>JSON.parse(await readFile(path(id),'utf8'));
 const save=async r=>{await mkdir(directory,{recursive:true,mode:0o700});const p=path(r.id);await writeFile(p+'.tmp',JSON.stringify(r),{mode:0o600});await rename(p+'.tmp',p);return structuredClone(r);};
 return {get:read,create(record){return locked(async()=>{try{return await read(record.id);}catch(e){if(e.code!=='ENOENT')throw e;}return save({id:record.id,record,status:'pending_supervisor',version:0});});},draft(id,draft){return locked(async()=>{const r=await read(id);if(r.status==='approved')throw Error('Reabrir antes de cambiar');r.version++;r.draft=draft;r.fingerprint=proposalFingerprint(draft);r.status='waiting_approval';return save(r);});},approve(id,decision,actorWpId){return locked(async()=>{const r=await read(id);if(r.status!=='waiting_approval')throw Error('Estado inválido');assertControlApproval({decision,caseId:id,version:r.version,fingerprint:r.fingerprint,actorWpId});r.status='approved';r.approval=decision;return save(r);});},reopen(id,actorWpId){return locked(async()=>{if(actorWpId!==1)throw Error('Permiso');const r=await read(id);r.status='pending_supervisor';delete r.approval;return save(r);});}};
}
