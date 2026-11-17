const $=id=>document.getElementById(id);
const canWrite=document.querySelector('meta[name=cv-access]')?.content==='write';
// A presentation boundary only: old records remain in the canonical queue.
const CLEAN_START='2026-11-14T22:24:32.392Z';
const state={data:null,filter:'new',pending:null};
const closed=new Set(['done','failed','cancelled','superseded']);
const statusLabel={new:'Recibida',triaged:'Pendiente de revisión',running:'En curso',verifying:'En revisión técnica',waiting_approval:'Pendiente de aprobación',waiting_ai:'Esperando programación',waiting_external:'Esperando respuesta',blocked:'Necesita revisión',failed:'No se pudo completar',done:'Terminada',superseded:'Sustituida',cancelled:'Cancelada'};
const riskLabel={safe:'Bajo',guarded:'Moderado',sensitive:'Sensible',critical:'Crítico'};
const stamp=v=>Number.isFinite(Date.parse(v))?new Date(v).toLocaleString('es-CU',{timeZone:'America/Havana',dateStyle:'short',timeStyle:'short'}):'Sin fecha verificable';
const fresh=v=>Number.isFinite(Date.parse(v))&&Math.abs(Date.now()-Date.parse(v))<=180000;
function el(tag,text='',cls=''){const n=document.createElement(tag);if(text!==null)n.textContent=text;if(cls)n.className=cls;return n;}
function empty(target,text){target.replaceChildren(el('p',text,'empty'));}
function pill(text,cls=''){return el('span',text,'pill '+cls);}
function isNewRequest(x){return ['control-private','whatsapp-owner'].includes(x.source)&&Date.parse(x.created_at||x.at)>=Date.parse(CLEAN_START);}
function taskCard(x){
 const a=el('article','', 'card');
 const top=el('div','', 'card-top');
 let label=statusLabel[x.status]||'Pendiente de revisión';
 if(x.status==='waiting_approval'&&!state.data.decisions.some(d=>d.task_id===x.id))label='Aprobación no visible en esta muestra';
 top.append(el('h3','#'+x.id+' · '+(x.title||'Solicitud')),pill(label));a.append(top);
 if(x.summary){
  const summary=String(x.summary);
  if(summary.length>1600){
   a.append(el('p',summary.split('\n').slice(0,2).join('\n'),'card-copy'));
   const report=el('details');report.append(el('summary','Leer informe completo'),el('p',summary,'card-copy'));a.append(report);
  }else a.append(el('p',summary,'card-copy'));
 }
 else if(!closed.has(x.status))a.append(el('p',x.status==='triaged'?'Solicitud guardada. Todavía no se ha ejecutado.':'Esperando el siguiente paso.','card-copy'));
 if(x.detail)a.append(el('p',x.detail,'card-error'));
 const details=el('details');details.append(el('summary','Ver detalles'));
 details.append(el('p','Estado: '+label+' · Riesgo: '+(riskLabel[x.risk]||'Sin clasificar')+' · Origen: '+(x.source||'Control OS'),'card-copy'));
 if(x.executor)details.append(el('p','Ejecutor asignado: '+x.executor,'card-copy'));
 a.append(details,el('div',stamp(x.created_at||x.at),'meta'));return a;
}
function decisionCard(x){
  const a=el('article','', 'card decision');const top=el('div','', 'card-top');
  top.append(el('h3','Tarea #'+(x.task_id||'—')+' · '+(x.title||'Decisión')),pill('Requiere decisión','warning'));a.append(top);
  if(x.warning)a.append(el('p',x.warning,'card-copy'));
  const impact=x.impact&&typeof x.impact==='object'?Object.values(x.impact).filter(v=>typeof v==='string').join(' · '):'';
  if(impact)a.append(el('small',impact,'impact'));
  if(!Number.isSafeInteger(Number(x.approval_id))||Number(x.approval_id)<=0||!x.task_id){a.append(el('p','Sin vínculo verificable. Revisar el registro original.','card-copy'));return a;}
  if(!canWrite||state.data.approval_complete!==true||!fresh(state.data.at)||x.actionable!==true||x.task_status!=='waiting_approval'){a.append(el('p','Consulta únicamente: acceso, vigencia, estado o integridad sin confirmar.','card-copy'));return a;}
  const actions=el('div','', 'decision-actions');
  const approve=el('button','Aprobar','approve-btn');approve.type='button';approve.dataset.decision='approve';approve.dataset.approval=String(x.approval_id||'');
  const reject=el('button','Rechazar','reject-btn');reject.type='button';reject.dataset.decision='reject';reject.dataset.approval=String(x.approval_id||'');
  actions.append(reject,approve);a.append(actions,el('p','', 'decision-feedback'),el('div',stamp(x.created_at),'meta'));return a;
}
function lennysCard(x){
  const a=el('article','', 'card priority-'+(x.priority||'p2'));const top=el('div','', 'card-top');
  top.append(el('h3',x.subject||'Asunto de Lennys'),pill(String(x.priority||'p2').toUpperCase(),x.priority||''));a.append(top);
  if(x.summary)a.append(el('p',x.summary,'card-copy'));a.append(el('div',stamp(x.last_activity_at),'meta'));return a;
}
function activityCard(x){
  const a=el('article','', 'activity');a.append(el('strong',(x.title||'Evento').replaceAll('_',' ')));
  let detail='';if(typeof x.detail==='string')detail=x.detail;else if(x.detail&&typeof x.detail==='object')detail=Object.entries(x.detail).slice(0,3).map(([k,v])=>k+': '+(v&&typeof v==='object'?JSON.stringify(v):String(v))).join(' · ');
  if(detail)a.append(el('p',detail));a.append(el('small',(x.provider||'Control OS')+' · '+stamp(x.at)));return a;
}

function showTab(name){
 document.querySelectorAll('[data-tab]').forEach(b=>{const selected=b.dataset.tab===name;b.classList.toggle('selected',selected);b.setAttribute('aria-pressed',String(selected));});
 document.querySelectorAll('.view').forEach(v=>v.hidden=v.id!==name);
 const group=document.querySelector('[data-tab="'+name+'"]')?.closest('[data-nav-group]')?.dataset.navGroup;
 document.querySelectorAll('[data-group]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.group===group)));
 document.querySelectorAll('[data-nav-group]').forEach(g=>g.classList.toggle('group-active',g.dataset.navGroup===group));
}
function setFilter(name){
 state.filter=name;document.querySelectorAll('[data-filter]').forEach(b=>b.classList.toggle('selected',b.dataset.filter===name));renderTasks();
}
function renderTasks(){
 const all=Array.isArray(state.data?.tasks)?state.data.tasks:[];
 let rows=state.filter==='pending'?all.filter(x=>!['done','cancelled','superseded'].includes(x.status)):all.filter(x=>state.filter==='history'?!isNewRequest(x):isNewRequest(x));
 if(state.filter==='done')rows=rows.filter(x=>closed.has(x.status));
 const term=$('task-search').value.trim().toLocaleLowerCase('es');
 if(term)rows=rows.filter(x=>('#'+x.id+' '+x.title+' '+(x.summary||'')).toLocaleLowerCase('es').includes(term));
 rows.sort((a,b)=>Date.parse(b.created_at||b.at)-Date.parse(a.created_at||a.at));
 const labels={new:'Tus solicitudes nuevas',pending:'Todas las pendientes recibidas',done:'Solicitudes nuevas finalizadas',history:'Historial anterior'};
 $('list-help').textContent=state.filter==='history'?'Muestra del historial conservado. Las tareas anteriores siguen registradas; esta vista no las cancela ni las ejecuta.':'Tus solicitudes nuevas aparecerán aquí, con la más reciente primero. Lo anterior está en Historial anterior.';
 $('list-title').textContent=labels[state.filter];$('task-count').textContent=rows.length;
 if(state.filter==='pending')$('list-help').textContent=state.data?.pending_complete===true?'Pendientes consultadas sin corte temporal, incluidos los fallos que requieren revisión.':'Muestra parcial: no se puede certificar que estén todas las pendientes.';
 $('tasks-list').replaceChildren(...rows.map(taskCard));
 if(!rows.length)empty($('tasks-list'),term?'No hay coincidencias en esta vista.':state.filter==='new'?'Todo listo para empezar. Pulsa Salud del sistema o escribe tu primera solicitud.':'No hay solicitudes en esta vista.');
 $('legacy-details').hidden=state.filter!=='history';
}
function renderOperationalViews(data){
 const tasks=Array.isArray(data.tasks)?data.tasks:[];
 const overview=[['incidents','Incidencias',Array.isArray(data.findings)?data.findings.length+' en la muestra':'Fuente no disponible'],['today','Aprobaciones',data.approval_complete===true?data.decisions.length+' formales pendientes':'Consulta incompleta'],['today','Revisiones sin aprobación',Array.isArray(data.reviews_blocked)?data.reviews_blocked.length+' identificadas':'Sin comprobación'],['work','Pendientes',data.pending_complete===true?tasks.filter(x=>!['done','cancelled','superseded'].includes(x.status)).length+' registradas':'Muestra parcial']];
 $('home-list').replaceChildren(...overview.map(([tab,title,detail])=>{const card=el('button','','card overview-card');card.type='button';card.append(el('span',title,'cv-kpi-label'),el('strong',detail,'cv-kpi-value'));card.addEventListener('click',()=>{showTab(tab);if(tab==='work')setFilter('pending');});return card;}));
 $('coverage').textContent='Fuente: cola de Control OS · '+stamp(data.at)+'. '+(data.coverage?.tasks||'Muestra limitada; no es el total histórico.');
 const findings=Array.isArray(data.findings)?data.findings:null;
 const incidents=$('incidents-list');
 if(findings?.length)incidents.replaceChildren(...findings.map(x=>{
  const a=el('article','','card');a.append(el('h3',x.title||'Incidencia'),pill(String(x.priority||'Sin prioridad')),el('p',x.detail||'Sin detalle','card-copy'));return a;
 }));else empty(incidents,findings?'No hay incidencias en la muestra recibida.':'Fuente de incidencias no disponible.');
 const pending=Array.isArray(data.reviews_blocked)?data.reviews_blocked:[];
 const target=$('approval-review-list');
 if(pending.length)target.replaceChildren(...pending.map(taskCard));else empty(target,data.approval_complete===true&&data.pending_complete===true?'No hay revisiones sin aprobación formal en esta consulta.':'No se puede certificar la ausencia de revisiones sin aprobación.');
 const conversations=Array.isArray(data.lennys)?data.lennys:null;
 const c=$('conversation-list');
 if(conversations?.length)c.replaceChildren(...conversations.map(lennysCard));else empty(c,conversations?'Sin conversaciones en esta muestra.':'Fuente no disponible.');
 const dev=tasks.filter(x=>x.status==='waiting_ai'||(typeof x.executor==='string'&&/^(dev|dev-worker|qwen|opencode|claude-code)$/i.test(x.executor)));
 const d=$('developer-list');
 if(dev.length)d.replaceChildren(...dev.map(taskCard));else empty(d,'Sin tareas de programación identificables en esta muestra.');
 const programmer=data.programmer||{}, heartbeat=programmer.heartbeat;
 $('developer-status').textContent='Servicio Dev Worker: '+({active:'activo',inactive:'inactivo',failed:'falló'}[programmer.worker_status]||'sin comprobar')+'. Un servicio activo no confirma que haya una tarea ejecutándose.';
 $('local-status').textContent=heartbeat?'Último aviso local: '+stamp(heartbeat.at)+' · Modelo declarado: '+(heartbeat.modelo||'sin dato')+'. El aviso no certifica disponibilidad actual.':'Sin aviso registrado del programador local.';
 $('ai-status').textContent='Presupuesto adicional autorizado: 0 USD. Gasto, cuotas y renovaciones: sin datos certificados; no se interpretan como cero.';
 const inventory=[['VivaBot',data.system?.vivabot?.status==='online'?'Servicio conectado; modelo y consumo sin datos.':'Estado no confirmado.']];
 if(heartbeat)inventory.push(['Ollama / Qwen',(heartbeat.modelo||'Modelo sin dato')+' · Declarado el '+stamp(heartbeat.at)]);
 $('ai-list').replaceChildren(...inventory.map(([name,detail])=>{const card=el('article','','card');card.append(el('h3',name),el('p',detail,'card-copy'));return card;}));
}
function render(data){
 state.data=data;data.decisions=Array.isArray(data.decisions)?data.decisions:[];
 const stale=!fresh(data.at);
 $('freshness').textContent=stale?'Estado sin actualizar':'Actualizado '+stamp(data.at);
 $('connection').classList.toggle('bad',stale);$('error').hidden=!stale;
 if(stale)$('error').textContent='No hemos recibido un estado reciente. Pulsa Actualizar; no vuelvas a enviar la misma solicitud.';
 $('decision-count').textContent=data.decisions.length;
 $('decisions-list').replaceChildren(...data.decisions.map(decisionCard));
 if(!data.decisions.length)empty($('decisions-list'),data.approval_complete?'No hay aprobaciones formales pendientes en esta consulta.':'No se puede certificar la lista de aprobaciones.');
 $('approval-integrity').textContent=data.approval_complete?'Consulta de aprobaciones dentro del límite comprobado.':'Auditoría incompleta: no se habilitan decisiones web.';
 renderOperationalViews(data);
 renderTasks();
 const l=Array.isArray(data.lennys)?data.lennys:[];$('lennys-list').replaceChildren(...l.map(lennysCard));
 if(!l.length)empty($('lennys-list'),'No hay mensajes anteriores en esta muestra.');
 const sys=data.system||{};$('system-badge').textContent=stale?'Sin estado reciente':sys.ok?'Servicios conectados':'Revisar conexión';
 $('system-badge').classList.toggle('bad',stale||!sys.ok);
 const labels={online:'Conectado',active:'Activo',offline:'Desconectado',stopped:'Detenido',inactive:'Inactivo'};
 const rows=[['WhatsApp · VivaBot',sys.vivabot?.status],['Puente de operaciones',sys.bridge?.status],['Sincronización del panel',sys.control_sync?.status]];
 $('system-grid').replaceChildren(...rows.map(([name,status])=>{const a=el('article','', 'system-card');a.append(el('small',name),el('strong',labels[status]||'Sin comprobar'));return a;}));
 const act=Array.isArray(data.activity)?data.activity:[];$('activity-list').replaceChildren(...act.map(activityCard));
 if(state.pending){
  const task=(data.tasks||[]).find(x=>x.request_id===state.pending.id);
  if(task){$('task-feedback').textContent='Solicitud #'+task.id+' · '+(statusLabel[task.status]||'Recibida')+'. '+(closed.has(task.status)?'Resultado disponible abajo.':'Puedes seguir su estado abajo.');if(closed.has(task.status))state.pending=null;}
  else $('task-feedback').textContent='Solicitud recibida. Esperando que la cola asigne su número; no hace falta enviarla otra vez.';
 }
}
let loading=false,readFailures=0;
async function refresh(){
 if(loading)return;loading=true;$('refresh').classList.add('spin');
 const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),30000);
 try{const r=await fetch('./?action=state',{cache:'no-store',signal:controller.signal});if(r.status===401)throw Error('Tu sesión venció. Recarga la página e inicia sesión.');const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo consultar el estado.');render(b.data);readFailures=0;}
 catch(e){
 readFailures++;
 const recent=state.data&&Date.now()-Date.parse(state.data.at)<180000;
 if(recent&&readFailures<3){$('error').hidden=true;$('freshness').textContent='Reintentando · último estado '+stamp(state.data.at);}
 else{$('error').hidden=false;$('error').textContent=e.name==='AbortError'?'La conexión está tardando. Puedes consultar de nuevo sin reenviar la solicitud.':e.message;$('freshness').textContent='Sin conexión con el estado';$('connection').classList.add('bad');}
 }
 finally{clearTimeout(timer);loading=false;$('refresh').classList.remove('spin');}
}
$('refresh').addEventListener('click',refresh);
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>showTab(b.dataset.tab)));
document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>setFilter(b.dataset.filter)));
$('task-search').addEventListener('input',renderTasks);
document.querySelectorAll('[data-query]').forEach(b=>b.addEventListener('click',()=>{$('task-text').value=b.dataset.query;$('task-form').requestSubmit();}));
$('decisions-list').addEventListener('click',async e=>{
 const btn=e.target.closest('[data-decision]');if(!btn)return;
 const card=btn.closest('.card'),feedback=card.querySelector('.decision-feedback');
 const approval=Number(btn.dataset.approval),decision=btn.dataset.decision;
 const record=state.data?.decisions.find(x=>Number(x.approval_id)===approval);
 if(!canWrite||state.data?.approval_complete!==true||!fresh(state.data?.at)||!Number.isSafeInteger(approval)||approval<=0||!['approve','reject'].includes(decision)||record?.actionable!==true)return;
 card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=true);feedback.textContent='Registrando decisión…';
 try{const r=await fetch('./?action=decision',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({approval_id:approval,decision})});const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar la decisión.');feedback.textContent='Decisión recibida. Esperando confirmación del registro; no implica un despliegue.';setTimeout(refresh,12000);}
 catch(err){feedback.textContent=err.message;card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=false);}
});
$('task-form').addEventListener('submit',async e=>{
 e.preventDefault();const buttons=[$('task-form').querySelector('button'),...document.querySelectorAll('[data-query]')];buttons.forEach(b=>b.disabled=true);$('task-feedback').textContent='Enviando solicitud…';
 try{const r=await fetch('./?action=task',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({text:$('task-text').value})});const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar.');state.pending={id:b.data.id};$('task-feedback').textContent='Solicitud recibida. Asignando número…';$('task-text').value='';$('task-search').value='';showTab('work');setFilter('new');refresh();setTimeout(refresh,4000);setTimeout(refresh,12000);}
 catch(err){$('task-feedback').textContent=err instanceof TypeError?'No pude confirmar la recepción. Pulsa Actualizar y revisa la lista antes de reenviar.':err.message;}
 finally{buttons.forEach(b=>b.disabled=false);}
});
document.querySelectorAll('[data-group]').forEach(b=>b.addEventListener('click',()=>{const first=document.querySelector('[data-nav-group="'+b.dataset.group+'"] [data-tab]');if(first)showTab(first.dataset.tab);}));
function toggleAssistant(open){$('assistant-panel').hidden=!open;document.querySelectorAll('.cv-assistant-toggle').forEach(b=>b.setAttribute('aria-expanded',String(open)));if(open){$('assistant-panel').scrollIntoView({block:'nearest'});if(canWrite)$('task-text').focus();}}
document.querySelectorAll('.cv-assistant-toggle').forEach(b=>b.addEventListener('click',()=>toggleAssistant($('assistant-panel').hidden)));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('assistant-panel').hidden)toggleAssistant(false);});
showTab('home');refresh();setInterval(refresh,15000);

