const $=id=>document.getElementById(id);
// A presentation boundary only: old records remain in the canonical queue.
const CLEAN_START='2026-11-14T22:24:32.392Z';
const state={data:null,filter:'new',pending:null};
const closed=new Set(['done','failed','cancelled','superseded']);
const statusLabel={new:'Recibida',triaged:'Pendiente de revisión',running:'En curso',verifying:'En revisión técnica',waiting_approval:'Pendiente de aprobación',waiting_ai:'Esperando programación',waiting_external:'Esperando respuesta',blocked:'Necesita revisión',failed:'No se pudo completar',done:'Terminada',superseded:'Sustituida',cancelled:'Cancelada'};
const riskLabel={safe:'Bajo',guarded:'Moderado',sensitive:'Sensible',critical:'Crítico'};
const stamp=v=>v?new Date(v).toLocaleString('es-CU',{timeZone:'America/Havana',dateStyle:'short',timeStyle:'short'}):'Sin fecha';
function el(tag,text='',cls=''){const n=document.createElement(tag);if(text!==null)n.textContent=text;if(cls)n.className=cls;return n;}
function empty(target,text){target.replaceChildren(el('p',text,'empty'));}
function pill(text,cls=''){return el('span',text,'pill '+cls);}
function isNewRequest(x){return ['control-private','whatsapp-owner'].includes(x.source)&&Date.parse(x.created_at||x.at)>=Date.parse(CLEAN_START);}
function taskCard(x){
 const a=el('article','', 'card');a.id='task-'+x.id;
 const top=el('div','', 'card-top');
 let label=statusLabel[x.status]||'Pendiente de revisión';
 if(x.status==='waiting_approval'&&!state.data.decisions.some(d=>d.task_id===x.id))label='Revisión anterior pendiente';
 top.append(el('h3','#'+x.id+' · '+(x.title||'Solicitud')),pill(label));a.append(top);
 if(x.summary)a.append(el('p',x.summary,'card-copy'));
 else if(!closed.has(x.status))a.append(el('p',x.status==='triaged'?'Solicitud guardada. Todavía no se ha ejecutado.':'Esperando el siguiente paso.','card-copy'));
 if(x.detail)a.append(el('p',x.detail,'card-error'));
 const details=el('details');details.append(el('summary','Ver detalles'));
 details.append(el('p','Estado: '+label+' · Riesgo: '+(riskLabel[x.risk]||'Sin clasificar')+' · Origen: '+(x.source||'Control OS'),'card-copy'));
 a.append(details,el('div',stamp(x.created_at||x.at),'meta'));return a;
}
function decisionCard(x){
  const a=el('article','', 'card decision');const top=el('div','', 'card-top');
  top.append(el('h3','Tarea #'+(x.task_id||'—')+' · '+(x.title||'Decisión')),pill('Requiere decisión','warning'));a.append(top);
  if(x.warning)a.append(el('p',x.warning,'card-copy'));
  const impact=x.impact&&typeof x.impact==='object'?Object.values(x.impact).filter(v=>typeof v==='string').join(' · '):'';
  if(impact)a.append(el('small',impact,'impact'));
  const actions=el('div','', 'decision-actions');
  const approve=el('button','Aprobar','approve-btn');approve.type='button';approve.dataset.decision='approve';approve.dataset.approval=String(x.approval_id||'');
  const reject=el('button','Rechazar','reject-btn');reject.type='button';reject.dataset.decision='reject';reject.dataset.approval=String(x.approval_id||'');
  actions.append(approve,reject);a.append(actions,el('p','', 'decision-feedback'),el('div',stamp(x.created_at),'meta'));return a;
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
}
function setFilter(name){
 state.filter=name;document.querySelectorAll('[data-filter]').forEach(b=>b.classList.toggle('selected',b.dataset.filter===name));renderTasks();
}
function renderTasks(){
 const all=Array.isArray(state.data?.tasks)?state.data.tasks:[];
 let rows=all.filter(x=>state.filter==='history'?!isNewRequest(x):isNewRequest(x));
 if(state.filter==='pending')rows=rows.filter(x=>!closed.has(x.status));
 if(state.filter==='done')rows=rows.filter(x=>closed.has(x.status));
 const term=$('task-search').value.trim().toLocaleLowerCase('es');
 if(term)rows=rows.filter(x=>('#'+x.id+' '+x.title+' '+(x.summary||'')).toLocaleLowerCase('es').includes(term));
 rows.sort((a,b)=>Date.parse(b.created_at||b.at)-Date.parse(a.created_at||a.at));
 const labels={new:'Tus solicitudes nuevas',pending:'Solicitudes nuevas pendientes',done:'Solicitudes nuevas finalizadas',history:'Historial anterior'};
 $('list-title').textContent=labels[state.filter];$('task-count').textContent=rows.length;
 $('tasks-list').replaceChildren(...rows.map(taskCard));
 if(!rows.length)empty($('tasks-list'),term?'No hay coincidencias en esta vista.':state.filter==='new'?'Todo listo para empezar. Pulsa Salud del sistema o escribe tu primera solicitud.':'No hay solicitudes en esta vista.');
 $('legacy-details').hidden=state.filter!=='history';
}
function render(data){
 state.data=data;data.decisions=Array.isArray(data.decisions)?data.decisions:[];
 const age=(Date.now()-Date.parse(data.at))/1000;const stale=!Number.isFinite(age)||age>180;
 $('freshness').textContent=stale?'Estado sin actualizar':'Actualizado '+stamp(data.at);
 $('connection').classList.toggle('bad',stale);$('error').hidden=!stale;
 if(stale)$('error').textContent='No hemos recibido un estado reciente. Pulsa Actualizar; no vuelvas a enviar la misma solicitud.';
 $('decision-count').textContent=data.decisions.length;
 $('decisions-list').replaceChildren(...data.decisions.map(decisionCard));
 if(!data.decisions.length)empty($('decisions-list'),'No tienes aprobaciones pendientes.');
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
let loading=false;
async function refresh(){
 if(loading)return;loading=true;$('refresh').classList.add('spin');
 const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),10000);
 try{const r=await fetch('./?action=state',{cache:'no-store',signal:controller.signal});if(r.status===401)throw Error('Tu sesión venció. Recarga la página e inicia sesión.');const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo consultar el estado.');render(b.data);}
 catch(e){$('error').hidden=false;$('error').textContent=e.name==='AbortError'?'La conexión está tardando. Pulsa Actualizar para volver a consultar.':e.message;$('freshness').textContent='Sin conexión con el estado';$('connection').classList.add('bad');}
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
 if(!approval||!['approve','reject'].includes(decision))return;
 card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=true);feedback.textContent='Registrando decisión…';
 try{const r=await fetch('./?action=decision',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({approval_id:approval,decision})});const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar la decisión.');feedback.textContent='Decisión recibida. Esperando confirmación del registro; no implica un despliegue.';setTimeout(refresh,12000);}
 catch(err){feedback.textContent=err.message;card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=false);}
});
$('task-form').addEventListener('submit',async e=>{
 e.preventDefault();const buttons=[$('task-form').querySelector('button'),...document.querySelectorAll('[data-query]')];buttons.forEach(b=>b.disabled=true);$('task-feedback').textContent='Enviando solicitud…';
 try{const r=await fetch('./?action=task',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({text:$('task-text').value})});const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar.');state.pending={id:b.data.id};$('task-feedback').textContent='Solicitud recibida. Asignando número…';$('task-text').value='';$('task-search').value='';showTab('work');setFilter('new');refresh();setTimeout(refresh,4000);setTimeout(refresh,12000);}
 catch(err){$('task-feedback').textContent=err.message;}
 finally{buttons.forEach(b=>b.disabled=false);}
});
refresh();setInterval(refresh,15000);
