// Pa'llá · ruta.js (PallaRuta.get). Uso: node artifacts/tests/test-palla-ruta.mjs [--live]
// --live además pide la ruta de prueba al motor de verdad (VPS): Conill A esq. 45 → Calle 23 entre N y O.
import fs from 'node:fs';
import vm from 'node:vm';

const src = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/palla/ruta.js', 'utf8');
const TIENDA = [23.1143, -82.39774];
const CLIENTE = [23.1393, -82.38015];

function load(fetchImpl) {
  const calls = [];
  const window = { PALLA: {}, AbortController };
  const ctx = vm.createContext({
    window, AbortController, setTimeout, clearTimeout, Promise, Math, Date, isFinite,
    fetch: fetchImpl ? (url, opts) => { calls.push(url); return fetchImpl(url, opts); } : undefined,
  });
  vm.runInContext(src, ctx);
  return { R: window.PallaRuta, calls };
}

// polyline6 de [[23.1143,-82.39774],[23.1393,-82.38015]] hecha a mano para la prueba.
function encode(points) {
  let out = '', pLat = 0, pLon = 0;
  const one = (v) => { v = v < 0 ? ~(v << 1) : v << 1; while (v >= 32) { out += String.fromCharCode((32 | (v & 31)) + 63); v >>= 5; } out += String.fromCharCode(v + 63); };
  for (const [la, lo] of points) { const a = Math.round(la * 1e6), b = Math.round(lo * 1e6); one(a - pLat); one(b - pLon); pLat = a; pLon = b; }
  return out;
}
const okBody = { code: 'Ok', routes: [{ geometry: encode([TIENDA, [23.12, -82.39], CLIENTE]), duration: 462.2, distance: 4657.4 }] };
const reply = (body, ok = true) => Promise.resolve({ ok, json: () => Promise.resolve(body) });

const checks = [];
const check = (name, ok) => checks.push([name, !!ok]);

{ // ruta buena: coords en [lat, lon], minutos y km redondeados, URL en orden lon,lat
  const { R, calls } = load(() => reply(okBody));
  const r = await R.get(TIENDA, CLIENTE);
  check('devuelve coords [lat, lon]', r && r.coords.length === 3 && Math.abs(r.coords[0][0] - 23.1143) < 1e-6 && Math.abs(r.coords[2][1] + 82.38015) < 1e-6);
  check('minutos y km', r && r.min === 8 && r.km === 4.7);
  check('pide lon,lat al motor', /\/route\/v1\/driving\/-82\.39774,23\.1143;-82\.38015,23\.1393\?/.test(calls[0]) && /polyline6/.test(calls[0]));
  await R.get([23.11431, -82.39771], CLIENTE); // ~4 m: misma ruta
  check('caché en memoria', calls.length === 1);
}
{ // fallos → null, nunca error
  const { R } = load(() => Promise.reject(new Error('sin red')));
  check('sin red → null', (await R.get(TIENDA, CLIENTE)) === null);
  const b = load(() => reply({ code: 'NoRoute' }));
  check('sin ruta → null', (await b.R.get(TIENDA, CLIENTE)) === null);
  const c = load(() => reply({}, false));
  check('HTTP malo → null', (await c.R.get(TIENDA, CLIENTE)) === null);
  const d = load(() => reply(okBody));
  check('punto raro → null', (await d.R.get(null, CLIENTE)) === null && (await d.R.get(['x', 1], CLIENTE)) === null && d.calls.length === 0);
  const e = load(undefined);
  check('sin fetch → null', (await e.R.get(TIENDA, CLIENTE)) === null);
}
{ // fallo no se repite enseguida (no martillar el VPS)
  const { R, calls } = load(() => Promise.reject(new Error('sin red')));
  await R.get(TIENDA, CLIENTE); await R.get(TIENDA, CLIENTE);
  check('fallo reciente no se repite', calls.length === 1);
}

if (process.argv.includes('--live')) {
  const { R } = load((url, opts) => fetch(url, opts));
  const r = await R.get(TIENDA, CLIENTE);
  console.log('live:', r && { puntos: r.coords.length, min: r.min, km: r.km });
  check('live: 3–8 km y 5–20 min', r && r.km >= 3 && r.km <= 8 && r.min >= 5 && r.min <= 20 && r.coords.length > 10);
}

const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error(`FAIL ruta.js: ${failed.join(', ')}`);
  process.exit(1);
}
console.log(`OK: ruta.js (${checks.length} comprobaciones).`);
