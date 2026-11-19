import {quote} from './engine.mjs';

export const sources = Object.freeze([
  {id:'ricardo',name:'Ricardo',priority:1,kind:'private'},
  {id:'lhj',name:'LHJ',priority:2,kind:'private'},
  {id:'excalibur',name:'Excalibur',priority:3,kind:'public',url:'https://excalibur-cuba.com/'},
  ...['Electros','Electrodomésticos de Sergio','Electro','Todo lo que necesitas','Electrodomésticos'].map((name,i)=>({id:`group-${i}`,name,priority:4+i,kind:'private'})),
  {id:'revolico',name:'Revolico',priority:9,kind:'public',url:'https://www.revolico.com/'}
]);
const categories=new Set(['panel','inverter','battery','breaker','cable','connector','mount','box','powerstation','other']);
const dateInCuba=(value)=>new Intl.DateTimeFormat('en-CA',{timeZone:'America/Havana',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(value));
export function evaluateOffer(o,now=new Date()){
  const source=sources.find(s=>s.id===o.sourceId);
  if(!source||!categories.has(o.category)||typeof o.model!=='string'||!o.model.trim())return {eligible:false,reason:'identity'};
  if(source.id==='excalibur'&&['inverter','battery','powerstation'].includes(o.category))return {eligible:false,reason:'reported_unavailable'};
  if(!o.confirmed||!Number.isFinite(Date.parse(o.confirmedAt))||Date.parse(o.confirmedAt)>+now||dateInCuba(o.confirmedAt)!==dateInCuba(now))return {eligible:false,reason:'fresh_confirmation_required'};
  if(!Number.isInteger(o.available)||o.available<1||!Number.isFinite(o.cost)||o.cost<=0||o.currency!=='USD'||typeof o.evidence!=='string'||!o.evidence.trim())return {eligible:false,reason:'stock_price_evidence'};
  return {eligible:true,source,offer:o};
}
export function rankOffers(offers,category,quantity=1,now=new Date()){
  if(!Number.isInteger(quantity)||quantity<1)throw Error('Cantidad inválida');
  return offers.map(o=>evaluateOffer(o,now)).filter(x=>x.eligible&&x.offer.category===category&&x.offer.available>=quantity).sort((a,b)=>a.source.priority-b.source.priority||a.offer.cost-b.offer.cost);
}
export function requestAvailability({category,model,quantity}){
  if(!categories.has(category)||typeof model!=='string'||!model.trim()||!Number.isInteger(quantity)||quantity<1)throw Error('Solicitud inválida');
  return `Hola. ¿Tienen disponible ${model}, cantidad ${quantity}? Por favor confirmen precio en USD, modelo exacto, cantidad, garantía y cuándo podemos recogerlo. Gracias.`;
}
export function draftProposal({caseId,selections,panels=0,inverters=0,markup=.12,extraCost=0,technicalReview=false},now=new Date()){
  if(typeof caseId!=='string'||!caseId||!Array.isArray(selections)||!selections.length)throw Error('Caso inválido');
  const used=new Map();
  const items=selections.map(({offer,quantity})=>{const x=evaluateOffer(offer,now);if(!x.eligible||!Number.isInteger(quantity)||quantity<1)throw Error('Oferta no válida');const key=`${offer.sourceId}:${offer.model}:${offer.category}`;const total=(used.get(key)||0)+quantity;if(total>offer.available)throw Error('Existencias insuficientes');used.set(key,total);return {...offer,quantity,source:x.source.name};});
  if(panels>items.filter(o=>o.category==='panel').reduce((s,o)=>s+o.quantity,0)||inverters>items.filter(o=>o.category==='inverter').reduce((s,o)=>s+o.quantity,0))throw Error('Mano de obra sin equipo seleccionado');
  const q=quote({items,panels,inverters,markup,extraCost},now);
  return {caseId,status:'draft',technicalReview:technicalReview===true,total:q.total,internal:q,installationWarranty:'1 año',items:items.map((o,i)=>({model:o.model,quantity:o.quantity,price:q.lines[i].sale,warranty:o.warranty||'Por confirmar con proveedor'})),labor:{panels:panels*55,inverters:inverters*160},extras:Math.round(extraCost*(1+markup)*100)/100};
}
export function approveProposal(draft,{authorized=false,reviewer='',technicalReview=false}={}){
  if(!authorized||!reviewer.trim()||!technicalReview||draft.status!=='draft')throw Error('Aprobación humana y revisión técnica requeridas');
  return {caseId:draft.caseId,status:'approved_estimate',items:draft.items,labor:draft.labor,extras:draft.extras,total:draft.total,installationWarranty:draft.installationWarranty,validity:'Sujeto a cambios y disponibilidad; visita técnica requerida'};
}
