/* Pa'llá · mapa propio del prototipo. Mismo mapa de Cuba del VPS (Protomaps + MapLibre 4.7.1 + pmtiles 4.3.0) con un
 * estilo minimalista (claro y oscuro). Regla de velocidad: la pantalla nunca espera al mapa.
 * PLANO DE RESERVA: mientras el mapa carga, o si no carga (sin red, ?nomapa, servidor bloqueado), se dibuja al momento un
 * plano propio en SVG (calles, ruta, pines y vehículos que se mueven). Pesa 0 KB de red.
 * PMapa.iniciar(el, tema, sinRed) → Promise<map|null>
 * PMapa.escena(cfg, abajoPx) · cfg = { ver:[p…], zoom, arriba, ruta:[p…], rutaQuieta, pins:[[tipo,p,etiqueta]…],
 *   mover:[[id,img,coords,ms,vuelta]…], conducir:[[id,img,coords,k0]…] } · PMapa.colocar(id, k) mueve un vehículo «conducido»
 *   (lo usa la barra de progreso) · PMapa.calle(p) · PMapa.mapa() · p = [lon, lat] (como MapLibre). */
(function () {
	'use strict';
	var GL = 'https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/';
	var PMT = 'https://cdn.jsdelivr.net/npm/pmtiles@4.3.0/dist/pmtiles.js';
	var SRV = (window.PV && window.PV.srv) || 'https://179-236-242-212.sslip.io'; // 10-nov: la da el servidor (opción cvd_tc_ruta_api)
	var TILES = SRV + '/mapa/cuba-20261031.pmtiles';
	var RUTA = SRV + '/route/v1/driving/';
	var NS = 'http://www.w3.org/2000/svg';
	var quieto = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var map = null, gl = null, tema = 'claro', marcas = {}, listo = null, rutaAnim = null;
	var raiz = null, plano = null, escenaAct = null, movs = {}, bucle = null;

	var C = {
		claro: { tierra: '#F3F2EE', verde: '#E3EBDD', agua: '#CFE0E8', casas: '#E8E6E0', calle: '#FFFFFF', borde: '#E3E1DA', grande: '#FFFFFF', bordeG: '#D8D5CB', texto: '#8E8C85', halo: '#F3F2EE', barrio: '#A3A199', ruta: '#0E0E0E', rutaB: '#FFFFFF' },
		oscuro: { tierra: '#141518', verde: '#16201A', agua: '#0C1A22', casas: '#1B1C20', calle: '#24262B', borde: '#1A1B1F', grande: '#2D2F35', bordeG: '#1A1B1F', texto: '#6E7076', halo: '#141518', barrio: '#5D5F65', ruta: '#F3F3EF', rutaB: '#0F0F10' }
	};

	function tag(kind, attrs) {
		return new Promise(function (ok, fail) {
			var n = document.createElement(kind);
			Object.keys(attrs).forEach(function (k) { n[k] = attrs[k]; });
			n.onload = ok; n.onerror = function () { fail(new Error('mapa')); };
			document.head.appendChild(n);
		});
	}

	function estilo() {
		var c = C[tema];
		var nombre = ['coalesce', ['get', 'name:es'], ['get', 'name']];
		var via = function (k) { return ['all', ['==', ['get', 'kind'], k], ['!', ['boolean', ['get', 'is_tunnel'], false]]]; };
		var ancho = function (s) { return ['interpolate', ['exponential', 1.6], ['zoom']].concat(s); };
		var vias = [
			['minor_road', 13, c.calle, c.borde, [13, 0.3, 15, 2, 16, 4.5, 18, 16]],
			['medium_road', 11, c.calle, c.borde, [11, 0.4, 13, 1.2, 15, 3.2, 16, 6, 18, 20]],
			['major_road', 9, c.grande, c.bordeG, [9, 0.4, 12, 1.4, 15, 4.5, 16, 8, 18, 24]],
			['highway', 6, c.grande, c.bordeG, [6, 0.4, 10, 1.2, 15, 5.5, 16, 10, 18, 28]]
		];
		var capas = [
			{ id: 'fondo', type: 'background', paint: { 'background-color': c.tierra } },
			{ id: 'tierra', type: 'fill', source: 'cuba', 'source-layer': 'earth', paint: { 'fill-color': c.tierra } },
			{ id: 'verde', type: 'fill', source: 'cuba', 'source-layer': 'landuse', filter: ['in', ['get', 'kind'], ['literal', ['park', 'garden', 'grass', 'wood', 'forest', 'cemetery', 'recreation_ground', 'pitch', 'golf_course', 'zoo']]], paint: { 'fill-color': c.verde } },
			{ id: 'agua', type: 'fill', source: 'cuba', 'source-layer': 'water', filter: ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]], paint: { 'fill-color': c.agua } },
			{ id: 'casas', type: 'fill', source: 'cuba', 'source-layer': 'buildings', minzoom: 15, paint: { 'fill-color': c.casas, 'fill-opacity': ['interpolate', ['linear'], ['zoom'], 15, 0, 16, 1] } }
		];
		vias.forEach(function (r) { capas.push({ id: 'b-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: via(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[3], 'line-gap-width': ancho(r[4]), 'line-width': 1 } }); });
		vias.forEach(function (r) { capas.push({ id: 'c-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: via(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[2], 'line-width': ancho(r[4]) } }); });
		capas.push(
			{ id: 'ruta-borde', type: 'line', source: 'ruta', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': c.rutaB, 'line-width': ['interpolate', ['linear'], ['zoom'], 12, 6, 16, 10] } },
			{ id: 'ruta', type: 'line', source: 'ruta', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': c.ruta, 'line-width': ['interpolate', ['linear'], ['zoom'], 12, 3, 16, 5] } },
			{ id: 'calles', type: 'symbol', source: 'cuba', 'source-layer': 'roads', minzoom: 15, filter: ['all', ['has', 'name'], ['in', ['get', 'kind'], ['literal', ['minor_road', 'medium_road', 'major_road', 'highway']]]], layout: { 'symbol-placement': 'line', 'text-field': nombre, 'text-font': ['Noto Sans Regular'], 'text-size': ['interpolate', ['linear'], ['zoom'], 15, 10, 17, 12] }, paint: { 'text-color': c.texto, 'text-halo-color': c.halo, 'text-halo-width': 1.4 } },
			{ id: 'barrios', type: 'symbol', source: 'cuba', 'source-layer': 'places', minzoom: 12, maxzoom: 16.5, filter: ['in', ['get', 'kind'], ['literal', ['neighbourhood', 'macrohood']]], layout: { 'text-field': nombre, 'text-font': ['Noto Sans Medium'], 'text-size': 11, 'text-transform': 'uppercase', 'text-letter-spacing': 0.12, 'text-max-width': 8 }, paint: { 'text-color': c.barrio, 'text-halo-color': c.halo, 'text-halo-width': 1.2 } }
		);
		return {
			version: 8, glyphs: SRV + '/mapa/fonts/{fontstack}/{range}.pbf',
			sources: { cuba: { type: 'vector', url: 'pmtiles://' + TILES, attribution: '© OpenStreetMap' }, ruta: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } } },
			layers: capas
		};
	}

	// Antes de bajar MapLibre (≈ 800 KB) se prueba si el servidor del mapa responde. Si no, se queda el plano de reserva.
	function probar() {
		return new Promise(function (ok) {
			var hecho = false, fin = function (v) { if (!hecho) { hecho = true; ok(v); } };
			setTimeout(function () { fin(false); }, 2500);
			try {
				fetch(TILES, { headers: { Range: 'bytes=0-15' }, credentials: 'omit', cache: 'force-cache' })
					.then(function (r) { fin(r.ok || r.status === 206); }).catch(function () { fin(false); });
			} catch (e) { fin(false); }
		});
	}

	function iniciar(el, t, sinRed) {
		tema = t === 'oscuro' ? 'oscuro' : 'claro';
		raiz = el; prepararPlano();
		if (listo) { return listo; }
		if (sinRed) { listo = Promise.resolve(null); return listo; }
		listo = new Promise(function (ok) { setTimeout(ok, 300); }).then(probar).then(function (si) {
			if (!si) { throw new Error('sin mapa'); }
			return Promise.all([
				tag('link', { rel: 'stylesheet', href: GL + 'maplibre-gl.min.css' }),
				window.maplibregl ? 0 : tag('script', { src: GL + 'maplibre-gl.min.js' }),
				window.pmtiles ? 0 : tag('script', { src: PMT })
			]);
		}).then(function () {
			gl = window.maplibregl;
			gl.addProtocol('pmtiles', new window.pmtiles.Protocol().tile);
			var m = new gl.Map({ container: el, style: estilo(), center: [-82.3812, 23.1402], zoom: 15, minZoom: 9, maxZoom: 18, maxBounds: [[-83.2, 22.6], [-81.6, 23.4]], attributionControl: false, dragRotate: false, pitchWithRotate: false, pixelRatio: Math.min(window.devicePixelRatio || 1, 2), fadeDuration: 150 });
			m.touchZoomRotate.disableRotation();
			m.addControl(new gl.AttributionControl({ compact: true }), 'bottom-left');
			return new Promise(function (ok) {
				var hecho = false;
				m.once('load', function () { if (!hecho) { hecho = true; map = m; el.classList.add('is-listo'); alLlegar(); ok(m); } });
				setTimeout(function () { if (!hecho) { hecho = true; ok(null); } }, 8000); // si no pinta en 8 s, sigue el plano
			});
		}).catch(function () { return null; });
		return listo;
	}

	// ---- Distancias y rumbo ----
	function dist(a, b) { var x = (b[0] - a[0]) * Math.cos((a[1] + b[1]) * Math.PI / 360), y = b[1] - a[1]; return Math.sqrt(x * x + y * y) * 111320; }
	function medir(c) { var acc = [0]; for (var i = 1; i < c.length; i++) { acc.push(acc[i - 1] + dist(c[i - 1], c[i])); } c._acc = acc; return acc[acc.length - 1]; }
	function rumbo(a, b) { var x = (b[0] - a[0]) * Math.cos(a[1] * Math.PI / 180), y = b[1] - a[1]; return (Math.atan2(x, y) * 180 / Math.PI + 360) % 360; }
	function punto(c, d) {
		var acc = c._acc || (medir(c), c._acc);
		for (var i = 1; i < c.length; i++) {
			if (acc[i] >= d) { var f = (d - acc[i - 1]) / ((acc[i] - acc[i - 1]) || 1); return { p: [c[i - 1][0] + (c[i][0] - c[i - 1][0]) * f, c[i - 1][1] + (c[i][1] - c[i - 1][1]) * f], r: rumbo(c[i - 1], c[i]), i: i }; }
		}
		var n = c.length; return { p: c[n - 1], r: rumbo(c[n - 2], c[n - 1]), i: n - 1 };
	}
	function cortar(c, largo, k) { var q = punto(c, largo * k); return c.slice(0, q.i).concat([q.p]); }
	function suave(a, b, f) { var d = ((b - a + 540) % 360) - 180; return (a + d * f + 360) % 360; }

	// =====================================================================================
	// PLANO DE RESERVA (SVG). Proyección simple: metros → píxeles alrededor del centro de la vista.
	// =====================================================================================
	function el(kind, attrs, padre) { var n = document.createElementNS(NS, kind); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); if (padre) { padre.appendChild(n); } return n; }
	function prepararPlano() {
		if (plano || !raiz) { return; }
		var div = document.createElement('div'); div.className = 'pm-plano'; div.setAttribute('aria-hidden', 'true');
		var svg = el('svg', { class: 'pm-plano__svg', width: '100%', height: '100%' });
		var calles = el('g', { class: 'pm-calles' }, svg), ruta = el('g', { class: 'pm-ruta' }, svg);
		var capas = document.createElement('div'); capas.className = 'pm-capas';
		div.appendChild(svg); div.appendChild(capas); raiz.appendChild(div);
		plano = { div: div, svg: svg, calles: calles, ruta: ruta, capas: capas, v: null };
	}
	var EJE = rumbo([-82.38105, 23.14031], [-82.38165, 23.14099]) * Math.PI / 180; // calles con letra del Vedado (≈ NO)
	function vista(cfg, abajo) {
		var W = raiz.clientWidth || 390, H = raiz.clientHeight || 844, pts = cfg.ver || [[-82.3812, 23.1402]];
		var lat0, lon0, mpp, arriba = cfg.arriba == null ? 90 : cfg.arriba, alto = Math.max(120, H - arriba - abajo - 30);
		if (cfg.zoom || pts.length === 1) {
			lon0 = pts[0][0]; lat0 = pts[0][1]; mpp = 78271.5 * Math.cos(lat0 * Math.PI / 180) / Math.pow(2, cfg.zoom || 16);
			return { W: W, H: H, lon0: lon0, lat0: lat0, mpp: mpp, cx: W / 2, cy: (H - abajo) / 2 };
		}
		var a = [180, 90, -180, -90]; pts.forEach(function (p) { a[0] = Math.min(a[0], p[0]); a[1] = Math.min(a[1], p[1]); a[2] = Math.max(a[2], p[0]); a[3] = Math.max(a[3], p[1]); });
		lon0 = (a[0] + a[2]) / 2; lat0 = (a[1] + a[3]) / 2;
		var kx = 111320 * Math.cos(lat0 * Math.PI / 180), dx = (a[2] - a[0]) * kx, dy = (a[3] - a[1]) * 111320;
		mpp = Math.max(dx / (W - 80), dy / alto, 78271.5 * Math.cos(lat0 * Math.PI / 180) / Math.pow(2, 16.2));
		return { W: W, H: H, lon0: lon0, lat0: lat0, mpp: mpp, cx: W / 2, cy: arriba + alto / 2 };
	}
	function px(p) { var v = plano.v; return [v.cx + (p[0] - v.lon0) * 111320 * Math.cos(v.lat0 * Math.PI / 180) / v.mpp, v.cy - (p[1] - v.lat0) * 111320 / v.mpp]; }
	function linea(g, a, b, cls) { el('line', { x1: a[0].toFixed(1), y1: a[1].toFixed(1), x2: b[0].toFixed(1), y2: b[1].toFixed(1), class: cls }, g); }
	function dibujarCalles(ruta, ancla) {
		var g = plano.calles, v = plano.v; g.textContent = '';
		var L = Math.hypot(v.W, v.H) * 1.2, paso = 95 / v.mpp, o = px(ancla);
		var ux = Math.sin(EJE), uy = -Math.cos(EJE), wx = -uy, wy = ux; // u = calles con letra; w = calles con número
		if (paso > 14) {
			var n = Math.ceil(L / paso);
			for (var i = -n; i <= n; i++) {
				var cls = i % 3 ? 'pm-c' : 'pm-c pm-c--g';
				linea(g, [o[0] + wx * i * paso - ux * L, o[1] + wy * i * paso - uy * L], [o[0] + wx * i * paso + ux * L, o[1] + wy * i * paso + uy * L], cls);
				linea(g, [o[0] + ux * i * paso * 1.05 - wx * L, o[1] + uy * i * paso * 1.05 - wy * L], [o[0] + ux * i * paso * 1.05 + wx * L, o[1] + uy * i * paso * 1.05 + wy * L], cls);
			}
		}
		// Cada tramo largo de la ruta es una calle de verdad: se prolonga para que la ruta siempre vaya por una calle.
		(ruta || []).forEach(function (p, j) {
			if (!j) { return; }
			var a = px(ruta[j - 1]), b = px(p), dx = b[0] - a[0], dy = b[1] - a[1], d = Math.hypot(dx, dy);
			if (d < 12) { return; }
			dx /= d; dy /= d; linea(g, [a[0] - dx * L, a[1] - dy * L], [b[0] + dx * L, b[1] + dy * L], 'pm-c pm-c--g');
		});
	}
	function rutaPlano(coords, animar) {
		var g = plano.ruta; g.textContent = '';
		if (!coords || coords.length < 2) { return; }
		var d = coords.map(function (p, i) { var q = px(p); return (i ? 'L' : 'M') + q[0].toFixed(1) + ' ' + q[1].toFixed(1); }).join('');
		el('path', { d: d, class: 'pm-ruta__b' }, g);
		var r = el('path', { d: d, class: 'pm-ruta__l' }, g);
		if (animar && !quieto && r.getTotalLength) {
			var l = r.getTotalLength(); r.style.strokeDasharray = l; r.style.strokeDashoffset = l;
			r.getBoundingClientRect(); r.style.transition = 'stroke-dashoffset 600ms cubic-bezier(.2,.8,.2,1)'; r.style.strokeDashoffset = 0;
		}
	}
	function capa(html, p, cls) {
		var d = document.createElement('div'); d.className = 'pm-marca ' + (cls || ''); d.innerHTML = html;
		var q = px(p); d.style.transform = 'translate(' + q[0].toFixed(1) + 'px,' + q[1].toFixed(1) + 'px)';
		if (q[0] > plano.v.W - 120) { d.classList.add('pm-izq'); } // etiqueta a la izquierda si no cabe
		plano.capas.appendChild(d); return d;
	}
	function htmlPin(tipo, etiqueta) { return '<span class="pm-pin pm-pin--' + tipo + '"></span>' + (etiqueta ? '<span class="pm-etq">' + etiqueta + '</span>' : ''); }
	function htmlVeh(img) { return '<img src="' + img + '" alt="" width="20" height="40">'; }

	// =====================================================================================
	// ESCENA: lo mismo en el plano (al momento) y en el mapa (cuando esté)
	// =====================================================================================
	function limpiar() {
		Object.keys(marcas).forEach(function (k) { marcas[k].remove(); }); marcas = {};
		movs = {};
		if (rutaAnim) { cancelAnimationFrame(rutaAnim); rutaAnim = null; }
		ponerRutaMapa(null);
		if (plano) { plano.capas.textContent = ''; plano.ruta.textContent = ''; }
	}
	function escena(cfg, abajo) {
		prepararPlano(); limpiar();
		escenaAct = { cfg: cfg, abajo: abajo || 0 };
		if (!plano) { return; }
		plano.v = vista(cfg, abajo || 0);
		var ancla = (cfg.ruta && cfg.ruta[0]) || (cfg.ver && cfg.ver[0]) || [-82.3812, 23.1402];
		dibujarCalles(cfg.ruta, ancla);
		rutaPlano(cfg.ruta, !cfg.rutaQuieta);
		(cfg.pins || []).forEach(function (p) { capa(htmlPin(p[0], p[2]), p[1], 'pm-' + p[0]); });
		(cfg.mover || []).forEach(function (v) { nuevoMov(v[0], v[1], v[2], v[3], v[4], false, 0); });
		(cfg.conducir || []).forEach(function (v) { nuevoMov(v[0], v[1], v[2], 0, false, true, v[3] || 0); });
		if (map) { escenaMapa(); }
		arrancar();
	}
	function nuevoMov(id, img, coords, ms, vuelta, ext, k) {
		if (!coords || coords.length < 2) { return; }
		var m = movs[id] = { img: img, c: coords, largo: medir(coords), ms: ms, vuelta: vuelta, ext: ext, k: k, t0: performance.now() - (k * ms || 0), r: null };
		if (!ext && quieto) { m.k = 0.35; }
		m.el = capa(htmlVeh(img), coords[0], 'pm-veh');
		ponerMov(m);
	}
	function ponerMov(m) {
		var q = punto(m.c, m.largo * Math.max(0, Math.min(1, m.k)));
		m.r = m.r == null ? q.r : suave(m.r, q.r, quieto ? 1 : 0.14);
		if (m.el && plano.v) { var s = px(q.p); m.el.style.transform = 'translate(' + s[0].toFixed(1) + 'px,' + s[1].toFixed(1) + 'px) rotate(' + m.r.toFixed(1) + 'deg)'; }
		if (m.mk) { m.mk.setLngLat(q.p); m.mk.setRotation(m.r); }
	}
	function arrancar() {
		if (bucle) { return; }
		var paso = function (t) {
			var hay = false;
			Object.keys(movs).forEach(function (id) {
				var m = movs[id]; if (m.ext || quieto) { return; }
				hay = true;
				var k = (t - m.t0) / m.ms;
				if (k >= 1) { if (m.vuelta) { m.t0 = t; k = 0; } else { k = 1; } }
				m.k = k; ponerMov(m);
			});
			bucle = hay ? requestAnimationFrame(paso) : null;
		};
		bucle = requestAnimationFrame(paso);
	}
	function colocar(id, k) { var m = movs[id]; if (m) { m.k = k; ponerMov(m); } }

	// ---- En el mapa de verdad ----
	function ponerRutaMapa(coords) {
		var s = map && map.getSource('ruta'); if (!s) { return; }
		s.setData({ type: 'FeatureCollection', features: coords && coords.length > 1 ? [{ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } }] : [] });
	}
	function rutaMapa(coords, animar) {
		if (!coords || animar === false || quieto) { ponerRutaMapa(coords); return; }
		var largo = medir(coords), t0 = performance.now(), ms = 600;
		var paso = function (t) {
			var k = Math.min(1, (t - t0) / ms); k = 1 - Math.pow(1 - k, 3);
			ponerRutaMapa(cortar(coords, largo, k));
			rutaAnim = k < 1 ? requestAnimationFrame(paso) : null;
		};
		rutaAnim = requestAnimationFrame(paso);
	}
	function marcaMapa(id, html, p, rot, cls) {
		var d = document.createElement('div'); d.className = 'pm-marca ' + (cls || ''); d.innerHTML = html;
		return (marcas[id] = new gl.Marker({ element: d, anchor: 'center', rotationAlignment: rot ? 'map' : 'viewport' }).setLngLat(p).addTo(map));
	}
	function escenaMapa() {
		var cfg = escenaAct.cfg, abajo = escenaAct.abajo;
		rutaMapa(cfg.ruta, !cfg.rutaQuieta);
		(cfg.pins || []).forEach(function (p, i) { marcaMapa('pin' + i, htmlPin(p[0], p[2]), p[1], false, 'pm-' + p[0]); });
		Object.keys(movs).forEach(function (id) { var m = movs[id]; m.mk = marcaMapa('veh:' + id, htmlVeh(m.img), m.c[0], true, 'pm-veh'); ponerMov(m); });
		var pts = cfg.ver;
		if (!pts || !pts.length) { return; }
		var pad = { top: cfg.arriba == null ? 90 : cfg.arriba, bottom: abajo + 30, left: 40, right: 40 };
		if (cfg.zoom || pts.length === 1) { map.jumpTo({ center: pts[0], zoom: cfg.zoom || 16, padding: { top: 0, bottom: abajo, left: 0, right: 0 } }); return; }
		var b = new gl.LngLatBounds(pts[0], pts[0]); pts.forEach(function (p) { b.extend(p); });
		// MapLibre SUMA la padding que dejó el jumpTo de arriba (inicio: la hoja, ~400 px) a la de fitBounds: en Viajar no cabía
		// («Map cannot fit…») y la ruta salía cortada. Se borra antes de encuadrar (revisión 7-nov).
		map.setPadding({ top: 0, bottom: 0, left: 0, right: 0 });
		map.fitBounds(b, { padding: pad, maxZoom: 16.2, duration: quieto ? 0 : 450 });
	}
	// Cuando el mapa llega tarde: se pasa la escena actual al mapa (el plano se oculta solo por CSS).
	function alLlegar() { if (map && escenaAct) { Object.keys(marcas).forEach(function (k) { marcas[k].remove(); }); marcas = {}; escenaMapa(); } }

	// Nombre de la calle más cercana (con nuestro motor de rutas): para «Fijar en el mapa».
	function calle(p) {
		var u = RUTA + p[0] + ',' + p[1] + ';' + p[0] + ',' + p[1] + '?overview=false';
		return fetch(u, { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) { return (d.waypoints && d.waypoints[0] && d.waypoints[0].name) || ''; }).catch(function () { return ''; });
	}

	// App real (5-nov): cuánto del camino lleva un punto (la posición del chofer), de 0 a 1, proyectándolo sobre la ruta.
	function avance(c, p) {
		if (!c || c.length < 2 || !p) { return 0; }
		var largo = c._acc ? c._acc[c._acc.length - 1] : medir(c), acc = c._acc, mejor = Infinity, k = 0;
		for (var i = 1; i < c.length; i++) {
			var a = c[i - 1], b = c[i], kx = Math.cos(a[1] * Math.PI / 180), dx = (b[0] - a[0]) * kx, dy = b[1] - a[1];
			var l2 = dx * dx + dy * dy, t = l2 ? Math.max(0, Math.min(1, (((p[0] - a[0]) * kx) * dx + (p[1] - a[1]) * dy) / l2)) : 0;
			var q = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t], d = dist(q, p);
			if (d < mejor) { mejor = d; k = (acc[i - 1] + (acc[i] - acc[i - 1]) * t) / (largo || 1); }
		}
		return Math.max(0, Math.min(1, k));
	}

	window.PMapa = {
		iniciar: iniciar,
		escena: escena, colocar: colocar, calle: calle, avance: avance, mapa: function () { return map; }
	};
})();
