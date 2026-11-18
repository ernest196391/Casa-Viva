import {createServer} from 'node:http';
import {readFile,appendFile} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import {assess} from './engine.mjs';
const allowed=new Map([['/','index.html'],['/engine.mjs','engine.mjs']]);
export function makeServer({file,notify=async()=>{}}){return createServer(async(req,res)=>{try{
  res.setHeader('Cache-Control','no-store');res.setHeader('X-Content-Type-Options','nosniff');
  const path=new URL(req.url,'http://localhost').pathname;
  if(req.method==='GET'&&allowed.has(path)){res.setHeader('Content-Type',path==='/'?'text/html; charset=utf-8':'text/javascript; charset=utf-8');res.end(await readFile(new URL(allowed.get(path),import.meta.url)));return;}
  if(req.method!=='POST'||path!=='/cases'){res.writeHead(404).end();return;}
  let raw='';for await(const c of req){raw+=c;if(raw.length>25000){res.writeHead(413).end();return;}}const x=JSON.parse(raw);
  if(typeof x.name!=='string'||!x.name.trim()||x.name.length>80||typeof x.phone!=='string'||!/^\+?[\d ()-]{7,24}$/.test(x.phone)||typeof x.municipio!=='string'||!x.municipio.trim()||x.municipio.length>100)throw Error('Datos inválidos');
  const result=assess(x.houses);const id=randomUUID();const record={id,at:new Date().toISOString(),status:'pending_supervisor',name:x.name,phone:x.phone,municipio:x.municipio,budget:x.budget,houses:x.houses,result};
  await appendFile(file,JSON.stringify(record)+'\n',{mode:0o600});let notified=false;try{await notify({id,status:record.status});notified=true;}catch{}res.writeHead(201,{'Content-Type':'application/json'}).end(JSON.stringify({id,notified}));
}catch{res.writeHead(400).end('Solicitud inválida');}})}
if(process.argv[1]&&import.meta.url===new URL(`file://${process.argv[1]}`).href)makeServer({file:process.env.ENERGY_CASES_FILE||'/tmp/casa-viva-energy-pilot.jsonl'}).listen(8787,'127.0.0.1');
