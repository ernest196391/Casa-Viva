export const tariffs = Object.freeze({panelCost:50,panelSale:55,inverterCost:150,inverterSale:160});
export const appliances = {refrigerador:150,ventilador:50,luz:10,televisor:80,router:12};
export function assess(houses){
  if(!Array.isArray(houses)||houses.length<1||houses.length>5) throw Error('Viviendas inválidas');
  return houses.map((h,i)=>{
    if(!Array.isArray(h.loads)||!h.loads.length||h.loads.length>25) throw Error('Equipos inválidos');
    let wh=0,watts=0;
    for(const l of h.loads){let w=Number(l.watts),n=Number(l.quantity),hours=Number(l.hours);if(!Number.isFinite(w)||w<=0||w>20000||!Number.isInteger(n)||n<1||n>20||!Number.isFinite(hours)||hours<=0||hours>24)throw Error('Consumo inválido');wh+=w*n*hours;watts+=w*n;}
    return {house:i+1,wh,watts,nominalKwh:Math.ceil(wh/.8/.85/100)/10,requiresTechnicalReview:true};
  });
}
export function quote({items,panels=0,inverters=0,markup=.12,extraCost=0,approved=false}){
  if(!Array.isArray(items)||!items.length||![.10,.12].includes(markup)||![panels,inverters].every(n=>Number.isInteger(n)&&n>=0)||!Number.isFinite(extraCost)||extraCost<0)throw Error('Cotización inválida');
  const lines=items.map(x=>{if(!x.confirmed||!x.source||!x.model||!x.confirmedAt||new Date(x.confirmedAt).toISOString().slice(0,10)!==new Date().toISOString().slice(0,10)||!Number.isFinite(x.cost)||x.cost<=0||!Number.isInteger(x.quantity)||x.quantity<1)throw Error('Oferta no confirmada hoy');return {...x,sale:Math.round(x.cost*(1+markup)*100)/100};});
  const cost=lines.reduce((s,x)=>s+x.cost*x.quantity,0)+50*panels+150*inverters+extraCost;
  const total=Math.round((lines.reduce((s,x)=>s+x.sale*x.quantity,0)+55*panels+160*inverters+extraCost*(1+markup))*100)/100;
  return {lines,total,cost,contribution:Math.round((total-cost)*100)/100,markup,approved,technicalReviewRequired:true,installationWarranty:'1 año',equipmentWarranty:'Según confirmación del proveedor',validity:'Estimado sujeto a cambios'};
}
export function botReply({message,enabled=false,testContact=false,url}){
  if(!enabled||!testContact||!/(solar|energ[ií]a|apag[oó]n|inversor|ecoflow|panel)/i.test(message)) return null;
  if(!/^https:\/\/casaviva\.company\/energia\/?$/.test(url))throw Error('URL no autorizada');
  return `Cuéntanos qué necesitas mantener funcionando. Completa este diagnóstico y nuestro equipo revisará tu caso: ${url}`;
}
