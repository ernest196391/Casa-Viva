/* Triciclub · el mapa (Core 3.14.118, «como La Nave»). El mismo mapa de Cuba que Pa'llá (Protomaps en nuestro VPS,
 * MapLibre), con los colores de Triciclub. Un solo mapa a pantalla completa detrás de la app: no se vuelve a crear.
 * TcMapa.crear(el, [lat, lon]) → Promise<map> · TcMapa.yo(map, p) · TcMapa.pin(map, 'origen'|'destino', p|null)
 * TcMapa.triciclos(map, [[lat, lon]…]) · TcMapa.chofer(map, p|null) · TcMapa.ruta(map, a, b|null) · TcMapa.ver(map, [p…], abajo)
 * TcMapa.ir(map, p, zoom) · TcMapa.centro(map) → [lat, lon] · TcMapa.alMover(map, fn)   (p = [lat, lon]) */
(function () {
	'use strict';
	if (window.TcMapa) { return; }
	var GL = 'https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/';
	var PMT = 'https://cdn.jsdelivr.net/npm/pmtiles@4.3.0/dist/pmtiles.js';
	var BASE = 'https://179-236-242-212.sslip.io/mapa/';
	var TILES = BASE + 'cuba-20261031.pmtiles';
	var RUTA = 'https://179-236-242-212.sslip.io/route/v1/driving/';
	var VERDE = '#087a3d', TINTA = '#0B1A12', NARANJA = '#E8590C';
	var ready = null;

	function tag(kind, attrs) {
		return new Promise(function (ok, fail) {
			var n = document.createElement(kind);
			Object.keys(attrs).forEach(function (k) { n[k] = attrs[k]; });
			n.onload = ok; n.onerror = function () { fail(new Error('mapa')); };
			document.head.appendChild(n);
		});
	}
	function load() {
		if (ready) { return ready; }
		ready = Promise.all([
			tag('link', { rel: 'stylesheet', href: GL + 'maplibre-gl.min.css' }),
			window.maplibregl ? Promise.resolve() : tag('script', { src: GL + 'maplibre-gl.min.js' }),
			window.pmtiles ? Promise.resolve() : tag('script', { src: PMT })
		]).then(function () {
			var gl = window.maplibregl;
			if (!gl._tc) { gl.addProtocol('pmtiles', new window.pmtiles.Protocol().tile); gl._tc = true; }
			return gl;
		}, function (e) { ready = null; throw e; });
		return ready;
	}
	function ll(p) { return [p[1], p[0]]; }

	function style() {
		var name = ['coalesce', ['get', 'name:es'], ['get', 'name']];
		var road = function (kind) { return ['all', ['==', ['get', 'kind'], kind], ['!', ['boolean', ['get', 'is_tunnel'], false]]]; };
		var w = function (stops) { return ['interpolate', ['exponential', 1.6], ['zoom']].concat(stops); };
		var roads = [
			['minor_road', 13, '#FFFFFF', '#DCE5DE', [13, 0.4, 15, 3, 16, 7, 18, 22]],
			['medium_road', 11, '#FFFFFF', '#D2DDD5', [11, 0.5, 13, 1.6, 15, 4.5, 16, 9, 18, 28]],
			['major_road', 8, '#FFFFFF', '#C9D6CD', [8, 0.5, 12, 1.8, 15, 6, 16, 11, 18, 32]],
			['highway', 5, '#FFE7B3', '#E9C27A', [5, 0.5, 10, 1.6, 15, 7.5, 16, 13, 18, 36]]
		];
		var layers = [
			{ id: 'fondo', type: 'background', paint: { 'background-color': '#EEF3EF' } },
			{ id: 'tierra', type: 'fill', source: 'cuba', 'source-layer': 'earth', paint: { 'fill-color': '#EEF3EF' } },
			{ id: 'verde', type: 'fill', source: 'cuba', 'source-layer': 'landuse', filter: ['in', ['get', 'kind'], ['literal', ['park', 'garden', 'grass', 'wood', 'forest', 'cemetery', 'recreation_ground', 'pitch']]], paint: { 'fill-color': '#D8E9DC' } },
			{ id: 'agua', type: 'fill', source: 'cuba', 'source-layer': 'water', filter: ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]], paint: { 'fill-color': '#C9E1EC' } },
			{ id: 'casas', type: 'fill', source: 'cuba', 'source-layer': 'buildings', minzoom: 14, paint: { 'fill-color': '#E3EAE5' } }
		];
		roads.forEach(function (r) { layers.push({ id: 'b-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: road(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[3], 'line-gap-width': w(r[4]), 'line-width': 1 } }); });
		roads.forEach(function (r) { layers.push({ id: 'c-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: road(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[2], 'line-width': w(r[4]) } }); });
		layers.push(
			{ id: 'tc-ruta-borde', type: 'line', source: 'tc-ruta', filter: ['!=', ['get', 'p'], 1], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FFFFFF', 'line-width': 9 } },
			{ id: 'tc-ruta', type: 'line', source: 'tc-ruta', filter: ['!=', ['get', 'p'], 1], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': VERDE, 'line-width': 5 } },
			{ id: 'tc-ruta-prov', type: 'line', source: 'tc-ruta', filter: ['==', ['get', 'p'], 1], layout: { 'line-cap': 'round' }, paint: { 'line-color': VERDE, 'line-width': 3, 'line-opacity': 0.6, 'line-dasharray': [0.6, 2.2] } },
			{ id: 'calles', type: 'symbol', source: 'cuba', 'source-layer': 'roads', minzoom: 14, filter: ['all', ['has', 'name'], ['in', ['get', 'kind'], ['literal', ['minor_road', 'medium_road', 'major_road', 'highway']]]], layout: { 'symbol-placement': 'line', 'text-field': name, 'text-font': ['Noto Sans Regular'], 'text-size': ['interpolate', ['linear'], ['zoom'], 14, 10, 17, 13] }, paint: { 'text-color': '#5D6B62', 'text-halo-color': '#FFFFFF', 'text-halo-width': 1.6 } },
			{ id: 'barrios', type: 'symbol', source: 'cuba', 'source-layer': 'places', minzoom: 12, filter: ['in', ['get', 'kind'], ['literal', ['neighbourhood', 'macrohood']]], layout: { 'text-field': name, 'text-font': ['Noto Sans Medium'], 'text-size': 12, 'text-transform': 'uppercase', 'text-letter-spacing': 0.08 }, paint: { 'text-color': '#8A988F', 'text-halo-color': '#EEF3EF', 'text-halo-width': 1.4 } }
		);
		return {
			version: 8, glyphs: BASE + 'fonts/{fontstack}/{range}.pbf',
			sources: { cuba: { type: 'vector', url: 'pmtiles://' + TILES, attribution: '© OpenStreetMap' }, 'tc-ruta': { type: 'geojson', data: { type: 'FeatureCollection', features: [] } } },
			layers: layers
		};
	}

	var SVG = {
		yo: '<span class="tc-yo"><i></i></span>',
		origen: '<svg width="34" height="44" viewBox="0 0 34 44" aria-hidden="true"><path d="M17 42S3 27 3 16a14 14 0 1 1 28 0c0 11-14 26-14 26z" fill="' + VERDE + '" stroke="#fff" stroke-width="2.5"/><circle cx="17" cy="16" r="5.5" fill="#fff"/></svg>',
		destino: '<svg width="34" height="44" viewBox="0 0 34 44" aria-hidden="true"><path d="M17 42S3 27 3 16a14 14 0 1 1 28 0c0 11-14 26-14 26z" fill="' + NARANJA + '" stroke="#fff" stroke-width="2.5"/><rect x="11.5" y="10.5" width="11" height="11" rx="1.5" fill="#fff"/></svg>',
		tri: '<svg width="30" height="30" viewBox="0 0 30 30" aria-hidden="true"><circle cx="15" cy="15" r="13" fill="#fff" stroke="' + VERDE + '" stroke-width="2"/><g fill="none" stroke="' + VERDE + '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9h10v6H7z"/><path d="M7 15h15l-1.5 3H8z"/><path d="M19 9l3 6"/><circle cx="10" cy="20" r="2"/><circle cx="20" cy="20" r="2"/></g></svg>',
		chofer: '<svg width="40" height="40" viewBox="0 0 30 30" aria-hidden="true"><circle cx="15" cy="15" r="13.5" fill="' + VERDE + '" stroke="#fff" stroke-width="2"/><g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9h10v6H7z"/><path d="M7 15h15l-1.5 3H8z"/><path d="M19 9l3 6"/><circle cx="10" cy="20" r="2"/><circle cx="20" cy="20" r="2"/></g></svg>'
	};
	function css() {
		if (document.getElementById('tc-mapa-css')) { return; }
		var s = document.createElement('style'); s.id = 'tc-mapa-css';
		s.textContent = '.tc-mk{line-height:0}.tc-mk svg{display:block;filter:drop-shadow(0 2px 3px rgba(0,0,0,.3))}'
			+ '.tc-yo{display:block;width:18px;height:18px;border-radius:50%;background:#1A73E8;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);position:relative}'
			+ '.tc-yo i{position:absolute;inset:-12px;border-radius:50%;background:rgba(26,115,232,.18);animation:tc-yo 2s ease-out infinite}'
			+ '@keyframes tc-yo{0%{transform:scale(.4);opacity:1}100%{transform:scale(1.2);opacity:0}}'
			+ '.maplibregl-ctrl-attrib{font-size:9px;background:rgba(255,255,255,.6)!important}'
			+ '#tc-mapa-fondo .maplibregl-ctrl-top-left{top:auto;bottom:0;left:auto;right:0}'; // la firma de OpenStreetMap, lejos del menú
		document.head.appendChild(s);
	}
	function mk(gl, kind) { var el = document.createElement('div'); el.className = 'tc-mk'; el.innerHTML = SVG[kind]; return new gl.Marker({ element: el, anchor: kind === 'origen' || kind === 'destino' ? 'bottom' : 'center' }); }

	function crear(el, centro) {
		css();
		return load().then(function (gl) {
			if (el._tc) { return el._tc; }
			var map = new gl.Map({ container: el, style: style(), center: ll(centro), zoom: 15, minZoom: 6, maxZoom: 18, maxBounds: [[-86.5, 18.8], [-72.5, 24.4]], attributionControl: false, dragRotate: false, pitchWithRotate: false, pixelRatio: Math.min(window.devicePixelRatio || 1, 2) });
			map.touchZoomRotate.disableRotation();
			map.addControl(new gl.AttributionControl({ compact: true }), 'top-left');
			map._tc = { gl: gl, mk: {}, tri: [] };
			el._tc = map;
			return new Promise(function (ok) { var done = false; var fin = function () { if (!done) { done = true; ok(map); } }; map.once('load', fin); setTimeout(fin, 4000); });
		});
	}
	function marca(map, key, kind, p) {
		var m = map._tc.mk[key];
		if (!p) { if (m) { m.remove(); delete map._tc.mk[key]; } return; }
		if (!m) { m = map._tc.mk[key] = mk(map._tc.gl, kind).setLngLat(ll(p)).addTo(map); return; }
		m.setLngLat(ll(p));
	}
	function triciclos(map, list) {
		map._tc.tri.forEach(function (m) { m.remove(); });
		map._tc.tri = (list || []).map(function (p) { return mk(map._tc.gl, 'tri').setLngLat(ll(p)).addTo(map); });
	}
	function linea(map, coords, provisional) {
		var src = map.getSource && map.getSource('tc-ruta');
		var data = { type: 'FeatureCollection', features: coords && coords.length > 1 ? [{ type: 'Feature', properties: { p: provisional ? 1 : 0 }, geometry: { type: 'LineString', coordinates: coords.map(ll) } }] : [] };
		if (src) { src.setData(data); } else { map.once('load', function () { var s = map.getSource('tc-ruta'); if (s) { s.setData(data); } }); }
	}
	// Camino por las calles. 3.14.121: si el servidor ya lo mandó (`coords`), se pinta al momento. Si no, se pide a
	// nuestro OSRM (2 intentos de 8 s); mientras tanto, línea de PUNTOS recta (se nota que es provisional).
	function ruta(map, a, b, coords) {
		if (!a || !b) { linea(map, null); return Promise.resolve(null); }
		if (coords && coords.length > 1) { linea(map, coords); return Promise.resolve(true); }
		linea(map, [a, b], true);
		var pedir = function (intento) {
			var ctrl = window.AbortController ? new AbortController() : null;
			var t = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 8000);
			return fetch(RUTA + a[1] + ',' + a[0] + ';' + b[1] + ',' + b[0] + '?overview=full&geometries=geojson', { signal: ctrl ? ctrl.signal : undefined, credentials: 'omit' })
				.then(function (r) { return r.json(); })
				.then(function (d) { clearTimeout(t); var g = d && d.routes && d.routes[0] && d.routes[0].geometry; if (g && g.coordinates) { linea(map, g.coordinates.map(function (c) { return [c[1], c[0]]; })); return true; } throw new Error('ruta'); })
				.catch(function () { clearTimeout(t); return intento < 1 ? pedir(intento + 1) : null; });
		};
		return pedir(0);
	}
	function ver(map, pts, abajo) {
		pts = (pts || []).filter(Boolean);
		if (!pts.length) { return; }
		if (pts.length === 1) { map.easeTo({ center: ll(pts[0]), zoom: 16, padding: { top: 60, bottom: abajo || 0, left: 0, right: 0 } }); return; }
		var b = new map._tc.gl.LngLatBounds(ll(pts[0]), ll(pts[0]));
		pts.forEach(function (p) { b.extend(ll(p)); });
		map.fitBounds(b, { padding: { top: 80, bottom: (abajo || 0) + 40, left: 50, right: 50 }, maxZoom: 16, duration: 600 });
	}
	window.TcMapa = {
		crear: crear,
		yo: function (map, p) { marca(map, 'yo', 'yo', p); },
		pin: function (map, kind, p) { marca(map, kind, kind, p); },
		chofer: function (map, p) { marca(map, 'chofer', 'chofer', p); },
		triciclos: triciclos, ruta: ruta, ver: ver,
		ir: function (map, p, zoom, abajo) { map.easeTo({ center: ll(p), zoom: zoom || map.getZoom(), padding: { top: 0, bottom: abajo || 0, left: 0, right: 0 }, duration: 500 }); },
		centro: function (map) { var c = map.getCenter(); return [c.lat, c.lng]; },
		alMover: function (map, fn) { map.on('moveend', function () { fn(window.TcMapa.centro(map)); }); },
		borrar: function (map) { ['origen', 'destino', 'chofer'].forEach(function (k) { marca(map, k, k, null); }); linea(map, null); }
	};
})();
