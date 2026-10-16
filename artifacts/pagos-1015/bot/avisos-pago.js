// Avisos de PAGOS DE COMISIONES (Core 3.14.23; Ernesto 15-oct). Copia de src/avisos-pedido.js, sin reglas propias:
// Core ya manda el texto escrito y a quién. Cada minuto: GET /bot/payout-avisos → buzón (crm_outbox, created_by
// «pagos») → POST /bot/payout-avisos/{id}/done. Un aviso una sola vez: Core lo tiene pendiente hasta el «done» y aquí se
// mira que el mismo texto no esté ya en el buzón (mismo teléfono, 3 días).
// Interruptor crm_settings «avisos_pago»: si (por defecto) | prueba (todo a Ernesto como vista previa) | no.
// Para instalar (en /root/vivabot, NO hecho aún):
//   1) copiar este archivo a src/avisos-pago.js
//   2) en src/index.js: import { initAvisosPago, startAvisosPagoLoop } from './avisos-pago.js';
//      junto a initAvisosPedido(...) → initAvisosPago(<el mismo objeto>);  y junto a startAvisosPedidoLoop() → startAvisosPagoLoop();
//   3) pm2 restart (como siempre). Primer día recomendado: crm_settings avisos_pago = 'prueba'.
import { db } from './crm.js';
import { core, dispatchEnabled } from './dispatch.js';

let ctx; // { business, notifyOwner }
let running = false;

export function initAvisosPago(c) { ctx = c; }

async function mode() {
  const { data, error } = await db.from('crm_settings').select('value').eq('business_id', ctx.business.id).eq('key', 'avisos_pago').maybeSingle();
  if (error) throw new Error(error.message);
  const v = String(data?.value || 'si').trim().toLowerCase().replace('sí', 'si');
  return ['si', 'prueba', 'no'].includes(v) ? v : 'prueba';
}

const p8 = (x) => String(x || '').replace(/\D/g, '').slice(-8);

/** Mete el texto en el buzón salvo que ya esté. → 'nuevo' | 'ya estaba' | 'ignorar' */
async function enqueue(a) {
  const phone = a.phone.length === 8 ? `53${a.phone}` : a.phone;
  const own = [process.env.BOT_PHONE, process.env.OWNER_PHONE].map(p8).filter(Boolean);
  if (own.includes(p8(phone))) { await ctx.notifyOwner(`(como ${a.role}) ${a.body}`); return 'nuevo'; }
  const { data: c } = await db.from('crm_contacts').select('bot_mode').eq('business_id', ctx.business.id).like('phone', `%${p8(phone)}`).limit(1);
  if (c?.[0]?.bot_mode === 'ignorar') return 'ignorar';
  const since = new Date(Date.now() - 3 * 86400e3).toISOString();
  const { data: dup, error: e1 } = await db.from('crm_outbox').select('id').eq('business_id', ctx.business.id).eq('created_by', 'pagos')
    .like('to_phone', `%${p8(phone)}`).eq('body', a.body).gte('created_at', since).limit(1);
  if (e1) throw new Error(e1.message);
  if (dup?.length) return 'ya estaba';
  const { error } = await db.from('crm_outbox').insert({ business_id: ctx.business.id, to_phone: phone, body: a.body, kind: 'texto', status: 'pendiente', created_by: 'pagos' });
  if (error) throw new Error(error.message);
  return 'nuevo';
}

/** Una vuelta. dryRun = no escribe nada: devuelve lo que haría. */
export async function avisosPagoOnce({ dryRun = false } = {}) {
  const m = await mode();
  if (m === 'no') return [];
  const { avisos = [] } = await core('/bot/payout-avisos');
  const report = [];
  for (const a of avisos) {
    report.push({ id: a.id, pago: a.payoutId, role: a.role, to: `…${p8(a.phone).slice(-4)}`, body: a.body });
    if (dryRun) continue;
    if (m === 'prueba' || a.test) {
      await ctx.notifyOwner(`👀 *Vista previa* (avisos_pago = ${a.test ? 'cuenta de prueba' : 'prueba'}) · pago #${a.payoutId} → ${a.role} ${a.name} (…${p8(a.phone).slice(-4)}):\n\n${a.body}`);
      await core(`/bot/payout-avisos/${a.id}/done`, { result: 'skipped', note: 'vista previa a Ernesto' });
      continue;
    }
    const r = await enqueue(a);
    await core(`/bot/payout-avisos/${a.id}/done`, { result: r === 'ignorar' ? 'skipped' : 'sent', note: `${a.role} …${p8(a.phone).slice(-4)} ${r}` });
    console.log(`[avisos pago] #${a.payoutId} ${a.kind} → ${a.role} …${p8(a.phone).slice(-4)}: ${r}`);
  }
  return report;
}

export function startAvisosPagoLoop() {
  if (!dispatchEnabled()) { console.log('[avisos pago] desactivado (falta WOO_URL o CURRU_KEY)'); return; }
  setInterval(async () => {
    if (running) return;
    running = true;
    try { await avisosPagoOnce(); } catch (e) { console.error('[avisos pago]', e.message); } finally { running = false; }
  }, 60e3);
}
