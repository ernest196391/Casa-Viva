const $=id=>document.getElementById(id);
const state={data:null,filter:'all'};
const statusLabel={new:'Nueva',triaged:'Lista',running:'En curso',verifying:'Verificando',waiting_approval:'Decisión',waiting_ai:'Esperando IA',waiting_external:'Esperando',blocked:'Bloqueada',failed:'Falló',done:'Terminada',superseded:'Sustituida',cancelled:'Cancelada'};
const riskLabel={safe:'Seguro',guarded:'Controlado',sensitive:'Sensible',critical:'Crítico'};
const stamp=v=>{if(!v)return 'Sin hora';const d=new Date(v);return d.toLocaleString('es-CU',{dateStyle:'short',timeStyle:'short'});};
function el(tag,text='',cls=''){const n=document.createElement(tag);if(text!==null)n.textContent=text;if(cls)n.className=cls;return n;}
function empty(target,text){target.replaceChildren(el('p',text,'empty'));}
function pill(text,cls=''){return el('span',text,'pill '+cls);}
function taskCard(x){
  const a=el('article','', 'card priority-'+(x.priority||'p3'));
  const top=el('div','', 'card-top');
  const title=el('h3','#'+x.id+' · '+(x.title||'Tarea'));
  const badges=el('div','', 'badges');
  badges.append(pill(String(x.priority||'p3').toUpperCase(),x.priority||''),pill(statusLabel[x.status]||x.status||'—'),pill(riskLabel[x.risk]||x.risk||''));
  top.append(title,badges);a.append(top);
  if(x.summary)a.append(el('p',x.summary,'card-copy'));
  if(x.detail)a.append(el('p',x.detail,'card-error'));
  const meta=el('div','', 'meta');meta.append(el('span',x.source||'Control OS'),el('span',stamp(x.at)));a.append(meta);return a;
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
  let detail='';if(typeof x.detail==='string')detail=x.detail;else if(x.detail&&typeof x.detail==='object')detail=Object.entries(x.detail).slice(0,3).map(([k,v])=>k+': '+String(v)).join(' · ');
  if(detail)a.append(el('p',detail));a.append(el('small',(x.provider||'Control OS')+' · '+stamp(x.at)));return a;
}
function render(data){
  state.data=data;const age=(Date.now()-Date.parse(data.at))/1000;
  $('freshness').textContent=Number.isFinite(age)&&age<90?'Ahora':'Actualizado '+stamp(data.at);
  $('connection').classList.toggle('bad',!Number.isFinite(age)||age>180);
  $('error').hidden=Number.isFinite(age)&&age<=180;if(!$('error').hidden)$('error').textContent='El estado lleva más de 3 minutos sin actualizarse.';
  const s=data.summary||{};$('m-attention').textContent=s.attention??0;$('m-decisions').textContent=s.decisions??0;$('m-lennys').textContent=s.lennys??0;$('m-errors').textContent=s.errors??0;
  const decisions=Array.isArray(data.decisions)?data.decisions:[];$('decision-count').textContent=decisions.length;$('decisions-list').replaceChildren(...decisions.map(decisionCard));if(!decisions.length)empty($('decisions-list'),'No tienes decisiones pendientes ahora.');
  const today=Array.isArray(data.today)?data.today:[];$('today-list').replaceChildren(...today.map(taskCard));if(!today.length)empty($('today-list'),'No hay trabajo prioritario abierto.');
  renderTasks();
  const l=Array.isArray(data.lennys)?data.lennys:[];$('lennys-count').textContent=l.length;$('lennys-list').replaceChildren(...l.map(lennysCard));if(!l.length)empty($('lennys-list'),'No hay asuntos activos de Lennys.');
  const sys=data.system||{};$('system-badge').textContent=sys.ok?'Operativo':'Revisar';$('system-badge').classList.toggle('bad',!sys.ok);
  const grid=$('system-grid');grid.replaceChildren();
  const rows=[['VivaBot',sys.vivabot?.status||'desconocido',sys.vivabot?.sha?'SHA '+sys.vivabot.sha:''],['Control Agent',sys.bridge?.status||'desconocido',sys.bridge?.version?'v'+sys.bridge.version+' · cada '+(sys.bridge.poll_seconds||'?')+' s':''],['Centro móvil',sys.control_sync?.status||'desconocido',sys.control_sync?.at?stamp(sys.control_sync.at):'']];
  for(const row of rows){const a=el('article','', 'system-card');a.append(el('small',row[0]),el('strong',row[1]),el('span',row[2]));grid.append(a);}
  const act=Array.isArray(data.activity)?data.activity:[];$('activity-list').replaceChildren(...act.map(activityCard));if(!act.length)empty($('activity-list'),'Sin actividad reciente.');
}
function renderTasks(){
  const all=Array.isArray(state.data?.tasks)?state.data.tasks:[];let rows=all;
  if(state.filter==='p0p1')rows=all.filter(x=>['p0','p1'].includes(x.priority));
  if(state.filter==='dev')rows=all.filter(x=>x.source==='control-private'||/program|desarroll|código|codigo/i.test(x.title||'')||x.status==='verifying');
  if(state.filter==='approval')rows=all.filter(x=>x.approval_required||x.status==='waiting_approval');
  $('task-count').textContent=rows.length;$('tasks-list').replaceChildren(...rows.map(taskCard));if(!rows.length)empty($('tasks-list'),'No hay tareas en este filtro.');
}
let loading=false;
async function refresh(){
  if(loading)return;loading=true;$('refresh').classList.add('spin');
  try{const r=await fetch('./?action=state',{cache:'no-store'});if(r.status===401){location.reload();return;}const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo consultar el estado.');render(b.data);}catch(e){$('error').hidden=false;$('error').textContent=e.message;$('connection').classList.add('bad');}finally{loading=false;$('refresh').classList.remove('spin');}
}
$('refresh').addEventListener('click',refresh);
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('selected',x===b));document.querySelectorAll('.view').forEach(v=>v.hidden=v.id!==b.dataset.tab);}));
document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>{state.filter=b.dataset.filter;document.querySelectorAll('[data-filter]').forEach(x=>x.classList.toggle('selected',x===b));renderTasks();}));

$('decisions-list').addEventListener('click',async e=>{
  const btn=e.target.closest('[data-decision]');if(!btn)return;
  const card=btn.closest('.card');const feedback=card.querySelector('.decision-feedback');
  const approval=Number(btn.dataset.approval);const decision=btn.dataset.decision;
  if(!approval||!['approve','reject'].includes(decision))return;
  card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=true);
  feedback.textContent=decision==='approve'?'Guardando aprobación…':'Guardando rechazo…';
  try{
    const r=await fetch('./?action=decision',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({approval_id:approval,decision})});
    const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar la decisión.');
    feedback.textContent='✓ '+b.data.message+' Se aplicará en el siguiente ciclo.';
    setTimeout(refresh,12000);
  }catch(err){feedback.textContent=err.message;card.querySelectorAll('[data-decision]').forEach(x=>x.disabled=false);}
});

$('task-form').addEventListener('submit',async e=>{
  e.preventDefault();const btn=e.currentTarget.querySelector('button');btn.disabled=true;$('task-feedback').textContent='Guardando…';
  try{const r=await fetch('./?action=task',{method:'POST',headers:{'Content-Type':'application/json','X-CV-Nonce':document.querySelector('meta[name=cv-nonce]').content},body:JSON.stringify({text:$('task-text').value})});const b=await r.json();if(!r.ok||!b.success)throw Error(b.data?.message||'No se pudo guardar.');$('task-feedback').textContent='✓ '+b.data.message;$('task-text').value='';setTimeout(refresh,4000);}catch(err){$('task-feedback').textContent=err.message;}finally{btn.disabled=false;}
});
refresh();setInterval(refresh,15000);