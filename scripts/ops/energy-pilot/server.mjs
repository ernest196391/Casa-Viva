import {createServer} from 'node:http';
import {readFile,appendFile} from 'node:fs/promises';
import {randomUUID,createHash} from 'node:crypto';
import {assess} from './engine.mjs';
import {controlRequest} from './control-handoff.mjs';
const allowed=new Map([['/','index.html'],['/engine.mjs','engine.mjs']]);
export function makeServer({file,notify=async()=>{},proposals,visit,cases}){const visits=new Map();return createServer(async(req,res)=>{try{
  res.setHeader('Cache-Control','no-store');res.setHeader('X-Content-Type-Options','nosniff');
  const path=new URL(req.url,'http://localhost').pathname;
  res.setHeader('Referrer-Policy','no-referrer');
  if(req.method==='GET'&&path==='/proposal-ui.mjs'){res.setHeader('Content-Type','text/javascript; charset=utf-8');res.end(await readFile(new URL('proposal-ui.mjs',import.meta.url)));return;}
  const match=path.match(/^\/(proposal|proposal-data|proposal-visit)\/([-_\w]{43})$/);
  if(match){res.setHeader('X-Robots-Tag','noindex, nofollow');const p=proposals?.get(match[2]);if(!p){res.writeHead(404).end();return;}
    {let current;try{current=await cases?.get(p.caseId);}catch{}if(!current||current.status!=='approved'||current.version!==p.version||current.fingerprint!==p.approvalFingerprint||current.approval?.actorWpId!==1||current.approval?.fingerprint!==p.approvalFingerprint){res.writeHead(404).end();return;}}
    if(req.method==='GET'&&match[1]==='proposal'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end(await readFile(new URL('proposal.html',import.meta.url)));return;}
    if(req.method==='GET'&&match[1]==='proposal-data'){res.setHeader('Content-Type','application/json');const {caseId,approvalFingerprint,...safe}=p;res.end(JSON.stringify(safe));return;}
    if(req.method==='POST'&&match[1]==='proposal-visit'&&visit){let raw='';for await(const c of req){raw+=c;if(raw.length>1000){res.writeHead(413).end();return;}}const {selections}=JSON.parse(raw);if(!Array.isArray(selections)||selections.length!==p.houses.length||!selections.every((n,i)=>Number.isInteger(n)&&n>=0&&n<p.houses[i].options.length))throw Error('Selección inválida');const id=createHash('sha256').update(JSON.stringify([match[2],p.version,selections])).digest('hex');if(!visits.has(id))visits.set(id,Promise.resolve().then(()=>visit({id,caseId:p.caseId,version:p.version,selections})).catch(e=>{visits.delete(id);throw e;}));await visits.get(id);res.writeHead(204).end();return;}
    res.writeHead(404).end();return;}
  if(req.method==='GET'&&allowed.has(path)){res.setHeader('Content-Type',path==='/'?'text/html; charset=utf-8':'text/javascript; charset=utf-8');res.end(await readFile(new URL(allowed.get(path),import.meta.url)));return;}
  if(req.method!=='POST'||path!=='/cases'){res.writeHead(404).end();return;}
  let raw='';for await(const c of req){raw+=c;if(raw.length>25000){res.writeHead(413).end();return;}}const x=JSON.parse(raw);
  if(typeof x.name!=='string'||!x.name.trim()||x.name.length>80||typeof x.phone!=='string'||!/^\+?[\d ()-]{7,24}$/.test(x.phone)||typeof x.municipio!=='string'||!x.municipio.trim()||x.municipio.length>100)throw Error('Datos inválidos');
  const result=assess(x.houses);const id=randomUUID();const record={id,at:new Date().toISOString(),status:'pending_supervisor',name:x.name,phone:x.phone,municipio:x.municipio,budget:x.budget,houses:x.houses,result};
  if(cases)await cases.create(record);else await appendFile(file,JSON.stringify(record)+'\n',{mode:0o600});let notified=false;try{await notify({id,status:record.status,control:controlRequest(id)});notified=true;}catch{}res.writeHead(201,{'Content-Type':'application/json'}).end(JSON.stringify({id,notified}));
}catch{res.writeHead(400).end('Solicitud inválida');}})}
if(process.argv[1]&&import.meta.url===new URL(`file://${process.argv[1]}`).href)makeServer({file:process.env.ENERGY_CASES_FILE||'/tmp/casa-viva-energy-pilot.jsonl'}).listen(8787,'127.0.0.1');
