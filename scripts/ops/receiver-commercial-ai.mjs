import { closeSync, mkdirSync, openSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const limit = 250;
const day = 86400e3;
const unsafe = /\b(?:pedido|orden|comisi[oó]n|saldo|pago|pagos|cobro|transferencia|carrera|entrega|mensajer[ií]a|contrase[nñ]a|tarjeta|cliente|direcci[oó]n|contacto|ll[aá]mame)\b/i;
const catalogPhrase = /^[\p{L}\p{M}][\p{L}\p{M} -]{1,69}$/u;

export function cleanCatalogQuery(query) {
  return query.replace(/\s+(?:disponibles?|en\s+stock|en\s+existencia)[?!. ]*$/i, '')
    .replace(/[?!. ]+$/u, '').trim();
}

export function isCommissionQuestion(text) {
  return /^[¿\s]*(?:(?:hola|buen(?:os días|as tardes|as noches)|buenas)[,\s]+)?(?:me puede(?:s)? decir (?:si )?)?(?:tengo|cu[aá]ntas tengo de|cu[aá]nto tengo de|cu[aá]les son mis)\s+comisiones?(?:\s+pendientes?)?(?:\s+(?:a|por|de)\s+transferencia)?[\s?!.]*$/iu.test(text);
}

function readSettings(file) {
  const values = {};
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const match = /^\s*(OPENAI_API_KEY|CV_RECEIVER_AI_UNTIL)\s*=\s*(['"]?)([^'"#\s]+)\2\s*(?:#.*)?$/.exec(line);
    if (match) values[match[1]] = match[3];
  }
  return values;
}

function reserve(directory) {
  mkdirSync(directory, { recursive: true, mode: 0o700 });
  const used = new Set(readdirSync(directory));
  for (let i = 0; i < limit; i++) {
    const slot = String(i);
    if (used.has(slot)) continue;
    try {
      closeSync(openSync(join(directory, slot), 'wx', 0o600));
      return true;
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
    }
  }
  return false;
}

export async function alternateCatalogQuery(query, {
  fetcher = fetch,
  settingsFile = '/root/vivabot/.env',
  directory = '/root/backups/nexo-recovery/receiver/ai-catalog-budget',
  now = Date.now(),
} = {}) {
  if (!catalogPhrase.test(query) || unsafe.test(query)) return null;
  try {
    const settings = readSettings(settingsFile);
    const until = Date.parse(settings.CV_RECEIVER_AI_UNTIL);
    if (!Number.isFinite(until) || until <= now || until - now > 15 * day ||
        !/^sk-[A-Za-z0-9_-]+$/.test(settings.OPENAI_API_KEY ?? '') || !reserve(directory)) return null;
    const response = await fetcher('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { Authorization: 'Bearer ' + settings.OPENAI_API_KEY, 'Content-Type': 'application/json' },
      signal: AbortSignal.timeout(5000),
      body: JSON.stringify({
        model: 'gpt-4.1-mini',
        max_completion_tokens: 32,
        messages: [
          { role: 'system', content: 'Devuelve solo dos a cinco palabras de búsqueda de catálogo en español. Usa el singular o un sinónimo comercial. Sin precios, stock, nombres personales ni explicaciones.' },
          { role: 'user', content: query },
        ],
      }),
    });
    if (!response.ok) return null;
    const alternate = (await response.json()).choices?.[0]?.message?.content?.trim();
    return typeof alternate === 'string' && catalogPhrase.test(alternate) &&
      !unsafe.test(alternate) && alternate.toLowerCase() !== query.toLowerCase() ? alternate : null;
  } catch {
    return null;
  }
}
