/* Pa'llá · ruta por calles (Core 3.14.108, Ernesto 1-nov: «de 15 mil USD»).
 * PallaRuta.get([lat, lon], [lat, lon]) → Promise<{ coords: [[lat, lon]...], min, km } | null>
 *   El camino real por las calles (para dibujarlo en el mapa) y los minutos y kilómetros reales.
 *   Lo calcula NUESTRO motor de rutas (OSRM con el mapa de Cuba, en el VPS); sin Google.
 *   Nunca falla: sin conexión, sin respuesta en 8 s o con un punto raro devuelve null (y quien lo use
 *   sigue con la línea recta). Guarda en memoria las rutas ya pedidas (~10 m de margen por el GPS).
 * La voz y el giro a giro siguen en OsmAnd/Maps con «Navegar». */
(function () {
	'use strict';
	if (window.PallaRuta) { return; }
	var C = window.PALLA || {};
	var API = (C.rutaApi || 'https://179-236-242-212.sslip.io').replace(/\/+$/, '');
	var WAIT = 8000;      // ms; con mala conexión, mejor la recta que esperar
	var KEEP = 60;        // rutas en memoria
	var RETRY = 30000;    // ms antes de volver a pedir una ruta que falló
	var cache = {};       // clave → { at, p: Promise }
	var order = [];

	function point(p) {
		if (!p || p.length < 2) { return null; }
		var lat = +p[0], lon = +p[1];
		if (!isFinite(lat) || !isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) { return null; }
		return [lat, lon];
	}

	// Polyline con 6 decimales (lo que manda OSRM con geometries=polyline6) → [[lat, lon]...]
	function decode(str) {
		var out = [], i = 0, lat = 0, lon = 0;
		while (i < str.length) {
			for (var k = 0; k < 2; k++) {
				var shift = 0, res = 0, b;
				do { b = str.charCodeAt(i++) - 63; res |= (b & 31) << shift; shift += 5; } while (b >= 32 && i < str.length);
				var d = (res & 1) ? ~(res >> 1) : (res >> 1);
				if (k === 0) { lat += d; } else { lon += d; }
			}
			out.push([lat / 1e6, lon / 1e6]);
		}
		return out;
	}

	function ask(a, b) {
		var url = API + '/route/v1/driving/' + a[1] + ',' + a[0] + ';' + b[1] + ',' + b[0] + '?overview=full&geometries=polyline6';
		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, WAIT);
		var late = new Promise(function (ok) { setTimeout(function () { ok(null); }, WAIT + 500); });
		var req = fetch(url, { signal: ctrl ? ctrl.signal : undefined, credentials: 'omit', referrerPolicy: 'no-referrer' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) {
				var r = j && j.code === 'Ok' && j.routes && j.routes[0];
				if (!r || typeof r.geometry !== 'string') { return null; }
				var coords = decode(r.geometry);
				if (coords.length < 2) { return null; }
				return { coords: coords, min: Math.max(1, Math.round(r.duration / 60)), km: Math.round(r.distance / 100) / 10 };
			});
		return Promise.race([req, late])
			.catch(function () { return null; })
			.then(function (res) { clearTimeout(timer); return res; });
	}

	function get(from, to) {
		try {
			var a = point(from), b = point(to);
			if (!a || !b || typeof fetch !== 'function') { return Promise.resolve(null); }
			var key = a[0].toFixed(4) + ',' + a[1].toFixed(4) + ';' + b[0].toFixed(4) + ',' + b[1].toFixed(4);
			var hit = cache[key];
			if (hit && (hit.ok !== false || Date.now() - hit.at < RETRY)) { return hit.p; }
			var entry = { at: Date.now(), ok: null, p: null };
			entry.p = ask(a, b).then(function (res) { entry.ok = !!res; entry.at = Date.now(); return res; });
			if (!hit) { order.push(key); }
			cache[key] = entry;
			while (order.length > KEEP) { delete cache[order.shift()]; }
			return entry.p;
		} catch (e) {
			return Promise.resolve(null);
		}
	}

	window.PallaRuta = { get: get, _decode: decode };
}());
