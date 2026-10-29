(function () {
  "use strict";
  var app = document.querySelector(".cvd-order-center");
  if (!app) return;
  var root = document.getElementById("cvd-order-center-root"), status = document.getElementById("cvd-order-center-status");
  var orderId = Number(app.dataset.orderId), timer = null, fingerprint = "";
  function esc(v) { var d=document.createElement("div"); d.textContent=v==null?"":String(v); return d.innerHTML; }
  function safeHref(v) { var value=String(v||""); return /^(https?:\/\/|tel:)/i.test(value)?esc(value):""; }
  function api(method, body) { return fetch(cvdOrderCenter.url + orderId, {method:method,credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":cvdOrderCenter.nonce,"X-CVD-Idempotency-Key":"center-"+orderId+"-"+Date.now()},body:body?JSON.stringify(body):undefined}).then(function(r){return r.json().then(function(j){if(!r.ok){var e=new Error(j.message||"No se pudo actualizar.");e.code=j.code;e.data=j.data;throw e;}return j;});}); }
  function card(title, body) { return '<section class="cvd-oc-card"><h2>'+esc(title)+'</h2>'+body+'</section>'; }
  function contactActions(p) {
    var actions=(p.customer&&p.customer.actions)||{}, links=[];
    if(actions.whatsapp_url) links.push('<a class="cvd-oc-contact is-whatsapp" href="'+safeHref(actions.whatsapp_url)+'" target="_blank" rel="noopener noreferrer" data-contact="whatsapp">WhatsApp</a>');
    if(actions.call_url) links.push('<a class="cvd-oc-contact" href="'+safeHref(actions.call_url)+'" data-contact="call">Llamar</a>');
    if(actions.navigation_url) links.push('<a class="cvd-oc-contact" href="'+safeHref(actions.navigation_url)+'" target="_blank" rel="noopener noreferrer" data-contact="navigate">Navegar</a>');
    return links.length?'<nav class="cvd-oc-contacts" aria-label="Acciones con el cliente">'+links.join('')+'</nav>':'';
  }
  // Gestora y mensajero con su WhatsApp (3.13.78, Nana: en tienda se anotan los dos).
  function waLink(phone) {
    var d=String(phone||"").replace(/\D/g,""); if(!d) return "";
    if(d.length===8) d="53"+d;
    var label=d.length===10&&d.indexOf("53")===0?"+53 "+d.slice(2,6)+" "+d.slice(6):"+"+d;
    return ' · <a href="https://wa.me/'+d+'" target="_blank" rel="noopener noreferrer">'+esc(label)+'</a>';
  }
  function peopleLines(p) {
    var people=p.people||{}, g=people.gestora, m=people.messenger;
    var gestora=g?esc(g.name)+waLink(g.phone):"Venta directa (sin gestor/a)", messenger;
    if(people.pickup) messenger="Recogida en tienda";
    else if(m&&m.state==="assigned") messenger=esc(m.name)+waLink(m.phone)+(m.byGestora?" (lo eligió quien vendió)":"");
    else if(m) messenger="Sin mensajero todavía · quien vendió pidió a "+esc(m.name)+waLink(m.phone);
    else messenger="Sin mensajero todavía";
    return '<p>Gestor/a: <strong>'+gestora+'</strong></p><p>Mensajero: <strong>'+messenger+'</strong></p>';
  }
  function commercialCards(p) {
    var cards=[];
    if(p.commission_summary) cards.push(card('Comisión','<p>Estado: '+esc(p.commission_summary.status)+'</p>'));
    return cards.join('');
  }
  function render(p) {
    var action=p.available_actions.find(function(a){return !a.blocked;});
    var warning=p.consistency.level!=="OK"?'<div class="cvd-oc-alert is-'+esc(p.consistency.level.toLowerCase())+'">'+(p.consistency.review_required?'Revisión requerida':'Revisar coherencia del pedido')+'</div>':'';
    var items=p.items.map(function(i){return '<article class="cvd-oc-item">'+(i.image?'<img src="'+esc(i.image)+'" alt="">':'')+'<div><strong>'+esc(i.name)+'</strong><small>'+esc(i.variation)+'</small><span>'+i.quantity+' × '+esc(i.price)+'</span></div></article>';}).join("");
    var timeline=p.timeline.events.map(function(e){return '<li><strong>'+esc(e.to_state||e.event_type)+'</strong><span>'+esc(e.timestamp)+' · '+esc(e.actor_role)+'</span></li>';}).join("");
    root.innerHTML='<header class="cvd-oc-head"><div><small>Pedido</small><h1>#'+esc(p.order.number)+'</h1></div><span class="cvd-oc-stage">'+esc(p.canonical_stage)+'</span></header>'+warning+
      '<div class="cvd-oc-grid">'+card('Cliente','<p><strong>'+esc(p.customer.name)+'</strong><br><a href="tel:'+esc(p.customer.phone)+'">'+esc(p.customer.phone)+'</a></p><p>'+esc(p.delivery.mode)+' · '+esc(p.delivery.address)+'</p>'+contactActions(p))+
      card('Gestor/a y mensajero',peopleLines(p))+
      card('Productos',items)+card('Operación','<p>Etapa: <strong>'+esc(p.operation.status)+'</strong></p>')+
      card('Mensajería','<p>'+esc(p.delivery.status||'No iniciada')+'</p><p>Tarifa: '+esc(p.pricing.shipping_cup)+' CUP</p>')+
      card('Dinero','<p>Estado: <strong>'+esc(p.payment.status)+'</strong></p><p>Método: '+esc(p.payment.method||'Sin registrar')+'</p>')+
      commercialCards(p)+
      (p.incident.active?card('Incidencia','<p>'+esc(p.incident.note||'Incidencia activa')+'</p><small>'+esc(p.incident.at)+'</small>'):'')+
      card('Historial','<ol class="cvd-oc-timeline">'+timeline+'</ol>'+(p.timeline.total>p.timeline.per_page?'<p>Mostrando '+p.timeline.per_page+' de '+p.timeline.total+'.</p>':''))+'</div>'+
      (action?'<div class="cvd-oc-primary"><button data-action="'+esc(action.id)+'">'+esc(action.label)+'</button></div>':'');
  }
  function load(force) { if(!orderId){status.textContent="Falta el pedido.";return;} api("GET").then(function(p){var next=JSON.stringify([p.canonical_stage,p.consistency.level,p.timeline.total,p.available_actions]);if(force||next!==fingerprint){fingerprint=next;render(p);}status.textContent="";}).catch(function(e){status.textContent=e.message;}); }
  // 3.14.76 (Ernesto 28-oct): «Confirmar efectivo recibido» de una entrega de otro día pregunta «¿Lo recibiste hoy?» y reenvía con la respuesta.
  root.addEventListener("click",function(e){var b=e.target.closest("[data-action]");if(!b)return;var label=b.textContent;if(!window.confirm("¿Confirmar: "+label+"?"))return;b.disabled=true;var body={action_id:b.dataset.action};api("POST",body).catch(function(err){if(!window.cvdCashDay||err.code!==window.cvdCashDay.code)throw err;return window.cvdCashDay.ask(err.data).then(function(reply){if(!reply)throw new Error("No se guardó: falta decir si el dinero entró hoy.");return api("POST",Object.assign({},body,reply));});}).then(function(r){render(r.projection);}).catch(function(err){status.textContent=err.message;}).finally(function(){b.disabled=false;});});
  document.addEventListener("visibilitychange",function(){if(!document.hidden)load(true);});
  load(true); timer=window.setInterval(function(){if(!document.hidden)load(false);},8000);
  window.addEventListener("pagehide",function(){window.clearInterval(timer);});
}());