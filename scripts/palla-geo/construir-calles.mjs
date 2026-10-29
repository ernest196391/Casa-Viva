#!/usr/bin/env node
/* Pa'llá · las calles de La Habana para sacar la ubicación desde la dirección (Ernesto 29-oct: «sin que el cliente tenga
 * que enviárnosla»). Lee lo que da OpenStreetMap (Overpass, JSON) y deja un archivo pequeño que lee el Core
 * (CVD_Geo_Direccion): las esquinas (dónde se cruzan dos calles), unos puntos de cada calle y los barrios.
 *
 *   1) Bajar las calles (una vez al mes basta):
 *      curl -s -m 500 -H "Accept: application/json" --data-urlencode "data=$(cat scripts/palla-geo/consulta.overpass)" \
 *        https://overpass-api.de/api/interpreter -o habana-osm.json
 *   2) node scripts/palla-geo/construir-calles.mjs habana-osm.json habana-calles.json
 *   3) Subirlo a wp-content/uploads/cvd-geo/habana-calles.json (fuera del plugin: los despliegues no lo tocan).
 *
 * La forma de escribir los nombres (norm) tiene que ser la misma que CVD_Geo_Direccion::norm() en PHP. */
import fs from 'node:fs';

const STOP = new Set(['calle', 'c', 'ave', 'av', 'avda', 'avenida', 'calzada', 'calz', 'cz', 'de', 'del', 'la', 'las', 'los', 'el']);
const WORDS = { primer: '1', primera: '1', primero: '1', segunda: '2', segundo: '2', tercera: '3', tercero: '3', cuarta: '4', cuarto: '4', quinta: '5', quinto: '5', sexta: '6', sexto: '6', septima: '7', septimo: '7', setima: '7', octava: '8', octavo: '8', novena: '9', noveno: '9', decima: '10', decimo: '10', gral: 'general', sto: 'santo', sta: 'santa', sn: 'san' };

export function norm(s) {
	let t = String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
	t = t.replace(/['’´`‘]/g, '').replace(/[^a-z0-9]+/g, ' ');
	// 5ta → 5 · 3ra A / 3raA / 3-A → 3 a · 1ero → 1
	t = t.replace(/\b(\d+)(?:ra|ro|era|ero|da|do|ta|to|ma|mo|va|vo|na|no)?(?:\s?([a-z])\b)?(?![a-z])/g, (m, d, l) => (l ? d + ' ' + l : d));
	const tok = t.split(' ').filter(Boolean).map((w) => WORDS[w] || w);
	const keep = tok.filter((w) => !STOP.has(w));
	return (keep.length ? keep : tok.slice(-1)).join(' ');
}

function names(tags) {
	const out = new Set();
	for (const k of ['name', 'alt_name', 'old_name', 'official_name', 'loc_name', 'short_name']) {
		for (const raw of String(tags[k] || '').split(';')) {
			const v = raw.trim();
			if (!v) continue;
			const m = v.match(/^(.*?)\s*\((.*)\)\s*$/); // «Padre Varela (Belascoaín)» → los dos
			for (const n of m ? [m[1], m[2]] : [v]) { const k2 = norm(n); if (k2) out.add(k2); }
		}
	}
	return [...out];
}

function meters(a, b, c, d) {
	const r = 6371000, x = (c - a) * Math.PI / 180, y = (d - b) * Math.PI / 180;
	const h = Math.sin(x / 2) ** 2 + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(y / 2) ** 2;
	return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
}
const r5 = (v) => Math.round(v * 1e5) / 1e5;

function build(osm) {
	const nodes = new Map(), ways = [], places = [];
	for (const e of osm.elements) {
		if (e.type === 'node' && e.lat) nodes.set(e.id, [e.lat, e.lon]);
		if (e.type === 'way' && e.tags) ways.push(e);
		if (e.type !== 'way' && e.tags && e.tags.name) {
			const ll = e.type === 'node' ? [e.lat, e.lon] : e.center ? [e.center.lat, e.center.lon] : null;
			if (ll) for (const n of names({ name: e.tags.name, alt_name: e.tags.alt_name })) places.push([n, r5(ll[0]), r5(ll[1])]);
		}
	}
	const idx = new Map(), list = [];
	const id = (n) => { if (!idx.has(n)) { idx.set(n, list.length); list.push(n); } return idx.get(n); };
	const atNode = new Map(), street = {};
	const push = (arr, ll, min) => { for (let i = 0; i < arr.length; i += 2) if (meters(arr[i], arr[i + 1], ll[0], ll[1]) < min) return; arr.push(r5(ll[0]), r5(ll[1])); };
	for (const w of ways) {
		const ns = names(w.tags).map(id);
		if (!ns.length || !w.nodes) continue;
		for (const nd of w.nodes) { if (!atNode.has(nd)) atNode.set(nd, new Set()); for (const n of ns) atNode.get(nd).add(n); }
		const mid = nodes.get(w.nodes[Math.floor(w.nodes.length / 2)]);
		if (mid) for (const n of ns) push(street[n] || (street[n] = []), mid, 120);
	}
	const x = {};
	const corner = (a, b, ll) => { if (a === b) return; const k = Math.min(a, b) + ',' + Math.max(a, b); push(x[k] || (x[k] = []), ll, 40); };
	for (const [nd, set] of atNode) {
		if (set.size < 2) continue;
		const ll = nodes.get(nd);
		if (!ll) continue;
		const s = [...set];
		for (let i = 0; i < s.length; i++) for (let j = i + 1; j < s.length; j++) corner(s[i], s[j], ll);
	}
	// Calles que en el mapa llegan a la otra pero sin compartir el punto (pasa: «160A» con «57»): la punta de una a ≤ 20 m
	// de un tramo de la otra también es esquina.
	const G = 0.0005, cell = (la, lo) => Math.floor(la / G) + ':' + Math.floor(lo / G), grid = new Map();
	for (const w of ways) {
		const ns = names(w.tags).map(id);
		if (!ns.length || !w.nodes) continue;
		for (let i = 0; i + 1 < w.nodes.length; i++) {
			const a = nodes.get(w.nodes[i]), b = nodes.get(w.nodes[i + 1]);
			if (!a || !b) continue;
			const seg = [a, b, ns];
			for (let la = Math.floor(Math.min(a[0], b[0]) / G); la <= Math.floor(Math.max(a[0], b[0]) / G); la++)
				for (let lo = Math.floor(Math.min(a[1], b[1]) / G); lo <= Math.floor(Math.max(a[1], b[1]) / G); lo++) {
					const k = la + ':' + lo; if (!grid.has(k)) grid.set(k, []); grid.get(k).push(seg);
				}
		}
	}
	const kx = Math.cos(23.1 * Math.PI / 180);
	for (const w of ways) {
		const ns = names(w.tags).map(id);
		if (!ns.length || !w.nodes) continue;
		for (const end of [w.nodes[0], w.nodes[w.nodes.length - 1]]) {
			const p = nodes.get(end);
			if (!p) continue;
			const [cla, clo] = cell(p[0], p[1]).split(':').map(Number);
			for (let la = cla - 1; la <= cla + 1; la++) for (let lo = clo - 1; lo <= clo + 1; lo++) {
				for (const [a, b, os] of grid.get(la + ':' + lo) || []) {
					if (os.some((o) => ns.includes(o))) continue;
					const dx = (b[1] - a[1]) * kx, dy = b[0] - a[0], L = dx * dx + dy * dy;
					const t = L ? Math.max(0, Math.min(1, (((p[1] - a[1]) * kx) * dx + (p[0] - a[0]) * dy) / L)) : 0;
					const q = [a[0] + t * dy, a[1] + t * (b[1] - a[1])];
					if (meters(p[0], p[1], q[0], q[1]) > 20) continue;
					for (const n of ns) for (const o of os) corner(n, o, p);
				}
			}
		}
	}
	return { v: 1, built: new Date().toISOString().slice(0, 10), fuente: '© OpenStreetMap (ODbL)', n: list, x, s: street, p: places };
}

if (process.argv[1] && process.argv[1].endsWith('construir-calles.mjs')) {
	const [inp, out] = process.argv.slice(2);
	if (!inp || !out) { console.error('uso: construir-calles.mjs habana-osm.json habana-calles.json'); process.exit(2); }
	const data = build(JSON.parse(fs.readFileSync(inp, 'utf8')));
	fs.writeFileSync(out, JSON.stringify(data));
	console.log(`calles ${data.n.length} · esquinas ${Object.keys(data.x).length} · barrios ${data.p.length} · ${(fs.statSync(out).size / 1e6).toFixed(1)} MB`);
}
