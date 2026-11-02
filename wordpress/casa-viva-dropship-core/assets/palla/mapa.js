/* Pa'llá · mapa del recorrido (Core 3.14.72.2, Ernesto 28-oct: «como Mandao»).
 * PallaMapa.ruta(el, ruta)    → el seguimiento (gestora y cliente): tienda, cliente, mensajero y la línea amarilla con el
 *                                camino que ya hizo; si va al cliente, una línea de puntos hasta allí.
 * PallaMapa.tienda(el, lista) → la tienda: todos los mensajeros en camino, cada uno con su carrera.
 * 1-nov (Ernesto: «que se vea de 15 mil USD»): mapa PROPIO. Ya no usamos las teselas públicas de OpenStreetMap (su norma no
 * deja tirar de ellas desde una app): el mapa de Cuba (Protomaps, datos OSM) vive en nuestro VPS como un solo archivo
 * PMTiles y lo pinta MapLibre con el estilo Casa Viva (claro, limpio, calles legibles, negro #121212 y amarillo #FFC21A).
 * MapLibre (cdnjs) y pmtiles (jsdelivr) se cargan solo cuando hay algo que enseñar. Sin Google.
 * El camino por las calles lo da ruta.js (nuestro OSRM del VPS); mientras no llega, o sin señal, la línea de puntos recta. */
(function () {
	'use strict';
	if (window.PallaMapa) { return; }
	var GL = 'https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/';
	var PMT = 'https://cdn.jsdelivr.net/npm/pmtiles@4.3.0/dist/pmtiles.js';
	// El mapa lleva la fecha en el nombre: al subir uno nuevo al VPS se cambia aquí (y la caché del teléfono no se mezcla).
	var BASE = window.PALLA_MAPA_BASE || 'https://179-236-242-212.sslip.io/mapa/';
	var TILES = BASE + 'cuba-20261031.pmtiles';
	var HABANA = [23.125, -82.38];
	var NEGRO = '#121212', AMARILLO = '#FFC21A';
	var ready = null, rutaReady = null;
	var SELF = (document.currentScript && document.currentScript.src) || '';
	function tag(kind, attrs) {
		return new Promise(function (ok, fail) {
			var n = document.createElement(kind);
			Object.keys(attrs).forEach(function (k) { n[k] = attrs[k]; });
			n.onload = ok;
			n.onerror = function () { fail(new Error('mapa')); };
			document.head.appendChild(n);
		});
	}
	function load() {
		if (window.maplibregl && window.maplibregl._palla) { return Promise.resolve(window.maplibregl); }
		if (ready) { return ready; }
		css();
		ready = Promise.all([
			tag('link', { rel: 'stylesheet', href: GL + 'maplibre-gl.min.css' }),
			window.maplibregl ? Promise.resolve() : tag('script', { src: GL + 'maplibre-gl.min.js' }),
			window.pmtiles ? Promise.resolve() : tag('script', { src: PMT })
		]).then(function () {
			var gl = window.maplibregl;
			protocol(gl);
			gl._palla = true;
			return gl;
		}, function (e) { ready = null; throw e; });
		return ready;
	}

	// ruta.js (Core 3.14.108): en la app viene en PALLA.rutaUrl; en el seguimiento está junto a este archivo.
	function rutaJs() {
		if (window.PallaRuta) { return Promise.resolve(window.PallaRuta); }
		if (rutaReady) { return rutaReady; }
		var src = (window.PALLA && window.PALLA.rutaUrl) || SELF.replace(/mapa\.js(\?[^#]*)?$/, 'ruta.js$1');
		if (!/ruta\.js/.test(src)) { return Promise.resolve(null); }
		rutaReady = tag('script', { src: src }).then(function () { return window.PallaRuta || null; }, function () { rutaReady = null; return null; });
		return rutaReady;
	}
	function protocol(gl) {
		if (gl._palla) { gl.removeProtocol('pmtiles'); }
		gl.addProtocol('pmtiles', new window.pmtiles.Protocol().tile);
	}

	// ── Estilo «Casa Viva»: crema claro, agua y parques suaves, calles blancas, autopistas en amarillo suave, pocos nombres.
	function style() {
		var name = ['coalesce', ['get', 'name:es'], ['get', 'name']];
		var R = 'Noto Sans Regular', M = 'Noto Sans Medium', I = 'Noto Sans Italic';
		var green = ['park', 'national_park', 'nature_reserve', 'protected_area', 'garden', 'golf_course', 'grass', 'wood', 'forest', 'scrub', 'cemetery', 'village_green', 'recreation_ground', 'playground', 'pitch', 'meadow', 'grassland'];
		var road = function (kind, z) { return ['all', ['==', ['get', 'kind'], kind], ['!', ['boolean', ['get', 'is_tunnel'], false]]].concat(z ? [['>=', ['zoom'], z]] : []); };
		var w = function (stops) { return ['interpolate', ['exponential', 1.6], ['zoom']].concat(stops); };
		var roads = [ // [kind, desde zoom, color, borde, anchos]
			['path', 15, '#FBFAF7', '#E6E0D4', [15, 0.6, 18, 4]],
			['minor_road', 13, '#FFFFFF', '#E0D9CC', [13, 0.4, 15, 3, 16, 7, 18, 22]],
			['medium_road', 11, '#FFFFFF', '#D8D0C1', [11, 0.5, 13, 1.6, 15, 4.5, 16, 9, 18, 28]],
			['major_road', 8, '#FFFFFF', '#D2C8B6', [8, 0.5, 12, 1.8, 15, 6, 16, 11, 18, 32]],
			['highway', 5, '#FFE29A', '#E3B743', [5, 0.5, 10, 1.6, 15, 7.5, 16, 13, 18, 36]]
		];
		var layers = [
			{ id: 'fondo', type: 'background', paint: { 'background-color': '#F6F3EC' } },
			{ id: 'tierra', type: 'fill', source: 'cuba', 'source-layer': 'earth', paint: { 'fill-color': '#F6F3EC' } },
			{ id: 'verde-lejos', type: 'fill', source: 'cuba', 'source-layer': 'landcover', filter: ['in', ['get', 'kind'], ['literal', ['forest', 'grassland', 'scrub']]], paint: { 'fill-color': '#E6EDDC', 'fill-opacity': 0.6 } },
			{ id: 'verde', type: 'fill', source: 'cuba', 'source-layer': 'landuse', filter: ['in', ['get', 'kind'], ['literal', green]], paint: { 'fill-color': '#E0EBD3' } },
			{ id: 'arena', type: 'fill', source: 'cuba', 'source-layer': 'landuse', filter: ['in', ['get', 'kind'], ['literal', ['beach', 'sand']]], paint: { 'fill-color': '#F3EBD6' } },
			{ id: 'plazas', type: 'fill', source: 'cuba', 'source-layer': 'landuse', filter: ['in', ['get', 'kind'], ['literal', ['pedestrian', 'hospital', 'school', 'university', 'college']]], paint: { 'fill-color': '#F0ECE4' } },
			{ id: 'agua', type: 'fill', source: 'cuba', 'source-layer': 'water', filter: ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]], paint: { 'fill-color': '#CFE3EE' } },
			{ id: 'rios', type: 'line', source: 'cuba', 'source-layer': 'water', filter: ['in', ['geometry-type'], ['literal', ['LineString', 'MultiLineString']]], paint: { 'line-color': '#CFE3EE', 'line-width': w([10, 0.5, 16, 3]) } },
			{ id: 'casas', type: 'fill', source: 'cuba', 'source-layer': 'buildings', minzoom: 14, paint: { 'fill-color': '#ECE7DE', 'fill-outline-color': '#E0D9CD', 'fill-opacity': ['interpolate', ['linear'], ['zoom'], 14, 0, 15, 1] } },
			{ id: 'tren', type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: 12, filter: ['==', ['get', 'kind'], 'rail'], paint: { 'line-color': '#D6CFC2', 'line-width': 1, 'line-dasharray': [3, 3] } }
		];
		roads.forEach(function (r) { // primero todos los bordes, luego las calles: los cruces quedan limpios
			layers.push({ id: 'borde-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: road(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[3], 'line-gap-width': w(r[4]), 'line-width': ['interpolate', ['linear'], ['zoom'], 12, 0.5, 16, 1.2] } });
		});
		roads.forEach(function (r) {
			layers.push({ id: 'calle-' + r[0], type: 'line', source: 'cuba', 'source-layer': 'roads', minzoom: r[1], filter: road(r[0]), layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': r[2], 'line-width': w(r[4]) } });
		});
		layers.push(
			// Lo que falta por las calles (como Uber): negro con borde blanco. Lo ya hecho: borde negro y amarillo Casa Viva.
			{ id: 'pl-camino-borde', type: 'line', source: 'pl-camino', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FFFFFF', 'line-width': 9 } },
			{ id: 'pl-camino', type: 'line', source: 'pl-camino', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': NEGRO, 'line-width': 5 } },
			{ id: 'pl-hecho-borde', type: 'line', source: 'pl-hecho', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': NEGRO, 'line-width': 9, 'line-opacity': 0.85 } },
			{ id: 'pl-hecho', type: 'line', source: 'pl-hecho', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': AMARILLO, 'line-width': 5.5 } },
			{ id: 'pl-falta', type: 'line', source: 'pl-falta', layout: { 'line-cap': 'round' }, paint: { 'line-color': NEGRO, 'line-width': 3, 'line-opacity': 0.65, 'line-dasharray': [0.6, 2.2] } },
			{ id: 'nombre-agua', type: 'symbol', source: 'cuba', 'source-layer': 'water', minzoom: 11, filter: ['all', ['has', 'name'], ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]]], layout: { 'text-field': name, 'text-font': [I], 'text-size': 12, 'text-max-width': 8 }, paint: { 'text-color': '#7EA5BA', 'text-halo-color': '#F6F3EC', 'text-halo-width': 1 } },
			{ id: 'nombre-calle', type: 'symbol', source: 'cuba', 'source-layer': 'roads', minzoom: 14, filter: ['all', ['has', 'name'], ['in', ['get', 'kind'], ['literal', ['minor_road', 'medium_road', 'major_road', 'highway']]]], layout: { 'symbol-placement': 'line', 'text-field': name, 'text-font': [R], 'text-size': ['interpolate', ['linear'], ['zoom'], 14, 10, 17, 13], 'text-max-angle': 30, 'symbol-spacing': 280 }, paint: { 'text-color': '#6F6A62', 'text-halo-color': '#FFFFFF', 'text-halo-width': 1.6 } },
			{ id: 'sitios', type: 'symbol', source: 'cuba', 'source-layer': 'pois', minzoom: 15.5, filter: ['all', ['has', 'name'], ['in', ['get', 'kind'], ['literal', ['hospital', 'university', 'stadium', 'marketplace', 'bus_station', 'ferry_terminal', 'museum', 'theatre', 'park']]], ['<=', ['coalesce', ['get', 'min_zoom'], 0], ['+', ['zoom'], 1]]], layout: { 'text-field': name, 'text-font': [R], 'text-size': 10.5, 'text-max-width': 7 }, paint: { 'text-color': '#8D877D', 'text-halo-color': '#F6F3EC', 'text-halo-width': 1.4 } },
			{ id: 'barrios', type: 'symbol', source: 'cuba', 'source-layer': 'places', minzoom: 12, filter: ['in', ['get', 'kind'], ['literal', ['neighbourhood', 'macrohood']]], layout: { 'text-field': name, 'text-font': [M], 'text-size': ['interpolate', ['linear'], ['zoom'], 12, 10, 16, 13], 'text-transform': 'uppercase', 'text-letter-spacing': 0.08, 'text-max-width': 7 }, paint: { 'text-color': '#9A9389', 'text-halo-color': '#F6F3EC', 'text-halo-width': 1.4 } },
			{ id: 'pueblos', type: 'symbol', source: 'cuba', 'source-layer': 'places', filter: ['all', ['==', ['get', 'kind'], 'locality'], ['<=', ['coalesce', ['get', 'min_zoom'], 0], ['+', ['zoom'], 1]]], layout: { 'text-field': name, 'text-font': [M], 'text-size': ['interpolate', ['linear'], ['zoom'], 6, 11, 12, 15], 'text-max-width': 8 }, paint: { 'text-color': NEGRO, 'text-halo-color': '#F6F3EC', 'text-halo-width': 1.6 } }
		);
		var empty = { type: 'FeatureCollection', features: [] };
		return {
			version: 8,
			glyphs: BASE + 'fonts/{fontstack}/{range}.pbf',
			sources: {
				cuba: { type: 'vector', url: 'pmtiles://' + TILES, attribution: '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>' },
				'pl-hecho': { type: 'geojson', data: empty },
				'pl-falta': { type: 'geojson', data: empty },
				'pl-camino': { type: 'geojson', data: empty }
			},
			layers: layers
		};
	}

	// ── Marcadores propios (SVG): moto vista desde arriba (gira con el rumbo), tienda amarilla, casa negra.
	function css() {
		if (document.getElementById('pl-mapa-css')) { return; }
		var s = document.createElement('style');
		s.id = 'pl-mapa-css';
		s.textContent = '.pl-mk{position:relative;display:block;line-height:0}'
			+ '.pl-mk svg{display:block;filter:drop-shadow(0 2px 3px rgba(0,0,0,.28))}'
			+ '.pl-moto{width:44px;height:44px}.pl-moto svg{transition:transform .6s ease}'
			+ '.pl-moto i{position:absolute;inset:-7px;border-radius:50%;background:rgba(255,194,26,.38);animation:pl-pulso 2.2s ease-out infinite}'
			+ '@keyframes pl-pulso{0%{transform:scale(.55);opacity:.95}100%{transform:scale(1.3);opacity:0}}'
			+ '@media (prefers-reduced-motion:reduce){.pl-moto i{animation:none;opacity:.35}.pl-moto svg{transition:none}}'
			+ '.pl-chip{position:absolute;left:50%;top:100%;margin-top:3px;transform:translateX(-50%);white-space:nowrap;background:#121212;color:#fff;font:600 11px/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:4px 8px;border-radius:999px;box-shadow:0 1px 3px rgba(0,0,0,.25)}'
			+ '.pl-chip--claro{background:#fff;color:#121212;border:1px solid #121212}'
			+ '.maplibregl-ctrl-attrib{font-size:10px;background:rgba(255,255,255,.55)!important}.maplibregl-ctrl-attrib a{color:#5c5850!important}'
			+ '.pl-fm .maplibregl-ctrl-group{display:none}.pl-fm .maplibregl-ctrl-attrib{font-size:9px;opacity:.6}'
			+ '.pl-fm .maplibregl-ctrl-bottom-right{top:56px;bottom:auto}' // a pantalla completa la tarjeta tapa abajo: la firma, bajo «Navegar»
			+ '.pl-mapa-sin{display:flex;align-items:center;justify-content:center;height:100%;padding:16px;text-align:center;background:#F6F3EC;color:#6F6A62;font:14px/1.4 system-ui,sans-serif}';
		document.head.appendChild(s);
	}
	var SVG = {
		moto: '<svg width="44" height="44" viewBox="0 0 44 44" aria-hidden="true">'
			+ '<circle cx="22" cy="22" r="19" fill="#FFC21A" stroke="#fff" stroke-width="3"/>'
			+ '<path d="M22 5.2l3.2 4.1h-6.4z" fill="#121212"/>' // la punta: hacia dónde va
			+ '<rect x="19.6" y="10.6" width="4.8" height="7" rx="2.4" fill="#121212"/>' // rueda de delante
			+ '<rect x="13.2" y="15.6" width="17.6" height="2.6" rx="1.3" fill="#121212"/>' // manillar
			+ '<path d="M18.2 18.6h7.6c1 0 1.8.8 1.8 1.8v6.2c0 2.2-1.6 3.9-3.6 4.3h-4c-2-.4-3.6-2.1-3.6-4.3v-6.2c0-1 .8-1.8 1.8-1.8z" fill="#121212"/>' // cuerpo
			+ '<rect x="20.1" y="20.4" width="3.8" height="6.4" rx="1.9" fill="#FFC21A" opacity=".55"/>' // asiento
			+ '<rect x="19.6" y="29.8" width="4.8" height="6.6" rx="2.4" fill="#121212"/>' // rueda de atrás
			+ '</svg>',
		tienda: '<svg width="38" height="48" viewBox="0 0 38 48" aria-hidden="true">'
			+ '<path d="M19 46.5S3 29.6 3 18a16 16 0 1 1 32 0c0 11.6-16 28.5-16 28.5z" fill="#FFC21A" stroke="#121212" stroke-width="2"/>'
			+ '<path d="M10.5 12.5h17l1.6 4.6a2.6 2.6 0 0 1-4.9 1.3 2.6 2.6 0 0 1-5.2 0 2.6 2.6 0 0 1-5.2 0 2.6 2.6 0 0 1-4.9-1.3z" fill="#121212"/>' // toldo
			+ '<path d="M12 20.4h14v6.8a1 1 0 0 1-1 1H13a1 1 0 0 1-1-1z" fill="#121212"/><rect x="17" y="22.6" width="4" height="5.6" rx=".6" fill="#FFC21A"/>' // local y puerta
			+ '</svg>',
		casa: '<svg width="38" height="48" viewBox="0 0 38 48" aria-hidden="true">'
			+ '<path d="M19 46.5S3 29.6 3 18a16 16 0 1 1 32 0c0 11.6-16 28.5-16 28.5z" fill="#121212" stroke="#fff" stroke-width="2"/>'
			+ '<path d="M19 9.6l9.4 8.2h-2.6v9.4a1 1 0 0 1-1 1h-3.6v-6h-4.4v6h-3.6a1 1 0 0 1-1-1v-9.4H9.6z" fill="#fff"/>'
			+ '<circle cx="19" cy="18.6" r="1.3" fill="#FFC21A"/>'
			+ '</svg>'
	};
	function mk(gl, kind, title, chip) {
		var el = document.createElement('div');
		el.className = 'pl-mk ' + (kind === 'moto' ? 'pl-moto' : 'pl-pinm');
		el.title = title || '';
		el.innerHTML = (kind === 'moto' ? '<i></i>' : '') + SVG[kind] + (chip ? '<span class="pl-chip' + (kind === 'moto' ? '' : ' pl-chip--claro') + '"></span>' : '');
		if (chip) { el.querySelector('.pl-chip').textContent = chip; }
		return new gl.Marker({ element: el, anchor: kind === 'moto' ? 'center' : 'bottom' });
	}
	function ll(p) { return [p[1], p[0]]; } // [lat, lon] → [lon, lat]
	function put(map, key, gl, kind, at, title, chip) { // reutiliza el marcador (no parpadea al refrescar)
		var all = map._pl.mk, m = all[key];
		if (!m || m._plKind !== kind + '|' + (chip || '')) {
			if (m) { m.remove(); }
			m = all[key] = mk(gl, kind, title, chip);
			m._plKind = kind + '|' + (chip || '');
			m.setLngLat(ll(at)).addTo(map);
		} else {
			m.setLngLat(ll(at));
			m.getElement().title = title || '';
		}
		m._plSeen = true;
		return m;
	}
	function sweep(map) { // quita los marcadores que ya no están
		Object.keys(map._pl.mk).forEach(function (k) { var m = map._pl.mk[k]; if (!m._plSeen) { m.remove(); delete map._pl.mk[k]; } else { m._plSeen = false; } });
	}
	// El rumbo de la moto: de dónde venía a dónde está (si se movió más de 8 m); si no, se queda como estaba.
	function heading(map, key, at) {
		var last = map._pl.last[key], deg = map._pl.deg[key];
		if (last && dist(last, at) > 8) { deg = bearing(last, at); }
		if (!last || dist(last, at) > 8) { map._pl.last[key] = at; }
		if (deg !== undefined) { map._pl.deg[key] = deg; }
		return deg;
	}
	function turn(m, deg) { if (deg !== undefined) { m.getElement().querySelector('svg').style.transform = 'rotate(' + Math.round(deg) + 'deg)'; } }
	function rad(d) { return d * Math.PI / 180; }
	function dist(a, b) {
		var dLat = rad(b[0] - a[0]), dLon = rad(b[1] - a[1]);
		var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
		return 2 * 6371000 * Math.asin(Math.sqrt(h));
	}
	function bearing(a, b) {
		var y = Math.sin(rad(b[1] - a[1])) * Math.cos(rad(b[0]));
		var x = Math.cos(rad(a[0])) * Math.sin(rad(b[0])) - Math.sin(rad(a[0])) * Math.cos(rad(b[0])) * Math.cos(rad(b[1] - a[1]));
		return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
	}
	function lines(coords) { return { type: 'FeatureCollection', features: coords.filter(function (c) { return c && c.length > 1; }).map(function (c) { return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: c.map(ll) } }; }) }; }
	function draw(map, hecho, falta, camino) { // las líneas van en cuanto el estilo está listo (los marcadores no esperan)
		map._pl.lines = [hecho, falta, camino || []];
		if (!map._pl.styled) { return; }
		map.getSource('pl-hecho').setData(lines(hecho));
		map.getSource('pl-falta').setData(lines(falta));
		map.getSource('pl-camino').setData(lines(camino || []));
	}
	// El camino por las calles de from a to → { coords, min, km } o null. Se pide de nuevo al cambiar el destino o al
	// moverse más de 80 m; mientras llega sirve el anterior. Al llegar, se vuelven a pintar las líneas (map._pl.paint).
	function calles(map, from, to) {
		var r = map._pl.camino, key = to.join(',');
		if (r && r.key === key && dist(r.from, from) < 80) { return r.res; }
		if (!map._pl.pidiendo) {
			map._pl.pidiendo = true;
			rutaJs().then(function (R) { return R ? R.get(from, to) : null; }).catch(function () { return null; }).then(function (res) {
				map._pl.pidiendo = false;
				if (!res) { return; } // sin ruta: sigue la recta (ruta.js no repite un fallo antes de 30 s)
				map._pl.camino = { key: key, from: from, res: res };
				if (map._pl.paint) { map._pl.paint(); }
			});
		}
		return r && r.key === key ? r.res : null;
	}
	function por(c, from, to) { return [[from].concat(c.coords, [to])]; }

	function base(gl, el) {
		if (el._pallaMap) { return el._pallaMap; }
		if (!el.style.height) { el.style.height = '260px'; }
		el.style.borderRadius = '12px';
		el.style.overflow = 'hidden';
		var pos = el.style.position;
		var map;
		try {
			map = new gl.Map({
				container: el, style: style(), center: ll(HABANA), zoom: 12, minZoom: 5, maxZoom: 18,
				maxBounds: [[-86.5, 18.8], [-72.5, 24.4]], attributionControl: false, dragRotate: false, pitchWithRotate: false,
				pixelRatio: Math.min(window.devicePixelRatio || 1, 2), fadeDuration: 150
			});
		} catch (e) { // teléfono sin WebGL: un aviso en vez de un cuadro gris (los textos siguen saliendo)
			el.innerHTML = '<div class="pl-mapa-sin">Este teléfono no puede enseñar el mapa. Usa «Ir».</div>';
			el._pallaMap = null;
			return null;
		}
		if (pos) { el.style.position = pos; } // .maplibregl-map pone position:relative; la pantalla completa va en absolute
		map.scrollZoom.disable(); // como antes: la rueda del ratón no hace zoom (la página sigue bajando)
		map.touchZoomRotate.disableRotation();
		map.keyboard.disableRotation();
		map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-right');
		map.addControl(new gl.AttributionControl({ compact: false }), 'bottom-right');
		map._pl = { mk: {}, last: {}, deg: {}, styled: false, lines: null, fitted: false };
		map.on('style.load', function () { map._pl.styled = true; var l = map._pl.lines; if (l) { draw(map, l[0], l[1], l[2]); } });
		map.on('error', function (e) {
			if (window.console) { console.warn('mapa:', e && e.error ? e.error.message || e.error : e); }
			if (e && e.sourceId === 'cuba') { again(map); }
		});
		window.addEventListener('online', function () { map._pl.wait = 0; again(map, true); });
		el._pallaMap = map;
		return map;
	}
	// Con la señal de Cuba un trozo del mapa puede no llegar; MapLibre no lo vuelve a pedir hasta que mueves el mapa. Aquí se
	// quitan los trozos fallidos y se piden otra vez (a los 5 s, 10 s, 20 s, 30 s… o en cuanto vuelve la señal).
	function again(map, now) {
		if (map._pl.retry) { if (!now) { return; } clearTimeout(map._pl.retry); }
		map._pl.wait = Math.min((map._pl.wait || 2500) * 2, 30000);
		map._pl.retry = setTimeout(function () {
			map._pl.retry = null;
			var src = map.getSource('cuba'), sc = map.style && map.style.sourceCaches && map.style.sourceCaches.cuba;
			protocol(window.maplibregl); // pmtiles recuerda los fallos: un lector nuevo vuelve a pedir de verdad
			if (src && !src.tileBounds && src.setUrl) { src.setUrl(src.url); return; } // ni la cabecera llegó
			if (!sc || !sc._removeTile) { return; } // (MapLibre 4.7.1: SourceCache._removeTile)
			var bad = Object.keys(sc._tiles).filter(function (id) { return sc._tiles[id].state === 'errored'; });
			bad.forEach(function (id) { sc._removeTile(id); });
			if (bad.length) { map.jumpTo({ center: map.getCenter() }); } else { map._pl.wait = 0; } // mover = volver a pedir
		}, now ? 300 : map._pl.wait);
	}
	function size(map) { map.resize(); setTimeout(function () { map.resize(); }, 50); }
	// Encuadra varios puntos. pad = {top, bottom, left, right}; si el cuadro es pequeño, se achica para que quepa.
	// → false si no se pudo (cuadro fuera de la página o sin tamaño): quien llama lo vuelve a intentar en la siguiente vuelta.
	function fit(map, el, pts, p, maxZoom, animate) {
		size(map);
		var h = el.clientHeight, w = el.clientWidth;
		if (h < 80 || w < 80) { return false; }
		if (p.top + p.bottom > h - 60 || p.left + p.right > w - 60) { p = pad(24); }
		var b = pts.reduce(function (acc, p) { return [[Math.min(acc[0][0], p[1]), Math.min(acc[0][1], p[0])], [Math.max(acc[1][0], p[1]), Math.max(acc[1][1], p[0])]]; }, [[180, 90], [-180, -90]]);
		var cam = map.cameraForBounds(b, { padding: p, maxZoom: maxZoom }) || map.cameraForBounds(b, { padding: pad(16), maxZoom: maxZoom });
		if (!cam) { if (window.console) { console.warn('mapa: no se pudo encuadrar', w + 'x' + h); } return false; }
		map._pl.busy = Date.now() + (animate ? 900 : 0); // mientras dura, «seguirte» no lo corta (se quedaba lejos)
		map.easeTo({ center: cam.center, zoom: cam.zoom, duration: animate ? 700 : 0 });
		return true;
	}
	// Red de seguridad: la moto y el destino se ven uno encima del otro estando a más de 150 m → el zoom está mal, encuadrar.
	function juntos(map, a, b) {
		if (map._pl.busy > Date.now() || dist(a, b) < 150) { return false; }
		var pa = map.project(ll(a)), pb = map.project(ll(b));
		return Math.abs(pa.x - pb.x) + Math.abs(pa.y - pb.y) < 60;
	}
	// El primer GPS del teléfono suele venir a kilómetros; luego se corrige. Si desde el último encuadre te has movido más de la
	// mitad de lo que te faltaba (y más de 300 m), se encuadra otra vez: corrige ese primer punto y se acerca al llegar.
	function salto(st, me, to) {
		if (!st.fitMe || st.busy > Date.now()) { return false; }
		var d = dist(st.fitMe, me);
		return d > 300 && d > dist(st.fitMe, to) / 2;
	}
	function pad(n) { return { top: n, bottom: n, left: n, right: n }; }
	function ago(s) {
		if (s < 90) { return 'ahora'; }
		var m = Math.round(s / 60);
		return m < 60 ? 'hace ' + m + ' min' : 'hace más de una hora';
	}

	/** Seguimiento de un pedido. Devuelve el texto de debajo («Daiquel va hacia ti · llega en ~8 min · ahora»). */
	function ruta(el, r) {
		return load().then(function (gl) {
			var map = base(gl, el), pts = [], here = r.pos ? [r.pos.lat, r.pos.lon] : null;
			if (map) {
				if (r.store) { put(map, 'tienda', gl, 'tienda', r.store, 'Casa Viva'); pts.push(r.store); }
				if (r.dest) { put(map, 'casa', gl, 'casa', r.dest, 'Entrega'); pts.push(r.dest); }
				var to = r.stage === 'tienda' ? r.store : r.dest, hecho = r.trail && r.trail.length > 1 ? [r.trail] : [];
				map._pl.paint = function () {
					var c = here && to && !r.arrived ? calles(map, here, to) : null;
					draw(map, hecho, c || !here || !to || r.arrived ? [] : [[here, to]], c ? por(c, here, to) : []);
				};
				map._pl.paint();
				if (here) {
					var trail = r.trail || [];
					if (trail.length > 1 && !map._pl.last.yo) { map._pl.last.yo = trail[trail.length - 2]; }
					turn(put(map, 'yo', gl, 'moto', here, r.name), heading(map, 'yo', here));
					pts.push(here);
				}
				sweep(map);
				if (!map._pl.fitted && pts.length) {
					if (pts.length === 1) { size(map); map.jumpTo({ center: ll(pts[0]), zoom: 15 }); map._pl.fitted = el.clientHeight >= 80; } else { map._pl.fitted = fit(map, el, pts, { top: 60, bottom: 36, left: 36, right: 56 }, 15); } // los pines miden 48 px y el + − va arriba a la derecha
				} else { size(map); }
			}
			var who = r.name || 'El mensajero';
			if (r.arrived) { return '📍 ' + who + ' ya llegó.'; }
			var txt = who + (r.stage === 'tienda' ? ' va a recoger el pedido a la tienda' : ' va en camino');
			if (r.eta) { txt += ' · llega en ~' + r.eta + ' min'; }
			if (r.pos) { txt += ' · ' + ago(r.pos.ago); } else { txt += ' · todavía sin ubicación'; }
			return txt;
		});
	}

	/** La tienda: [{name, number, zone, stage, pos:{lat,lon,ago}, dest}] */
	function tienda(el, list) {
		return load().then(function (gl) {
			var map = base(gl, el), pts = [], falta = [];
			if (!map) { return list.filter(function (m) { return m.pos; }).length; }
			list.forEach(function (m, i) {
				if (!m.pos) { return; }
				var here = [m.pos.lat, m.pos.lon], key = 'm' + (m.number || i);
				var title = m.name + ' · #' + m.number + ' · ' + (m.stage === 'tienda' ? 'viene a la tienda' : 'va a ' + m.zone) + ' · ' + ago(m.pos.ago);
				turn(put(map, key, gl, 'moto', here, title, m.name), heading(map, key, here));
				pts.push(here);
				if (m.dest && m.stage !== 'tienda') { put(map, key + 'c', gl, 'casa', m.dest, '#' + m.number, '#' + m.number); falta.push([here, m.dest]); pts.push(m.dest); }
			});
			draw(map, [], falta);
			sweep(map);
			if (!map._pl.fitted && pts.length) {
				if (pts.length === 1) { size(map); map.jumpTo({ center: ll(pts[0]), zoom: 14 }); map._pl.fitted = el.clientHeight >= 80; } else { map._pl.fitted = fit(map, el, pts, { top: 60, bottom: 48, left: 48, right: 56 }, 14); } // + los nombres debajo de cada moto
			} else { size(map); }
			return pts.length;
		});
	}

	/** 3.14.74.3: el cliente marca su casa (toca el mapa o arrastra la casa). → { put([lat, lon]), get() → [lat, lon] | null } */
	function marcar(el, start) {
		return load().then(function (gl) {
			var map = base(gl, el), m = null;
			if (!map) { throw new Error('mapa'); }
			size(map);
			map.jumpTo({ center: ll(start), zoom: 14 });
			function poner(at) {
				if (m) { m.setLngLat(ll(at)); return; }
				m = mk(gl, 'casa', 'Tu casa');
				m.setDraggable(true);
				m.setLngLat(ll(at)).addTo(map);
			}
			map.on('click', function (e) { poner([e.lngLat.lat, e.lngLat.lng]); });
			return {
				put: function (at) { poner(at); map.jumpTo({ center: ll(at), zoom: 16 }); },
				get: function () { if (!m) { return null; } var p = m.getLngLat(); return [p.lat, p.lng]; }
			};
		});
	}

	/**
	 * 3.14.99 (Ernesto 30-oct, «como Mandao: un mapa en la app con tu motor y adónde vas»): el mapa de la carrera en la app
	 * del mensajero. me = [lat, lon] del GPS del propio teléfono (no se manda a ningún sitio) o null; to = [lat, lon] de la
	 * tienda o del cliente; kind = tienda | casa. → «15 min · 3,4 km». La navegación paso a paso la hace «Ir».
	 */
	function mensajero(el, me, to, kind) {
		return load().then(function (gl) {
			var map = base(gl, el), pts = [], c = null;
			var big = el.offsetHeight > 400; // a pantalla completa, la tarjeta de abajo tapa ~300 px: que no queden debajo
			if (map) {
				// Pantalla completa (Ernesto 30-oct, «como Uber»): el mapa te sigue; si lo mueves con el dedo deja de seguirte hasta 🎯.
				if (!map._pl.fm) { map._pl.fm = true; map._pl.follow = true; map.on('dragstart', function () { map._pl.follow = false; }); }
				if (to) { put(map, 'to', gl, kind === 'tienda' ? 'tienda' : 'casa', to, kind === 'tienda' ? 'Casa Viva' : 'Cliente'); pts.push(to); }
				map._pl.paint = function () {
					var c = me && to ? calles(map, me, to) : null;
					draw(map, [], c || !me || !to ? [] : [[me, to]], c ? por(c, me, to) : []);
					return c;
				};
				c = map._pl.paint();
				if (me) { turn(put(map, 'yo', gl, 'moto', me, 'Tú'), heading(map, 'yo', me)); pts.push(me); }
				sweep(map);
				// Se encuadra al empezar, cuando llega tu ubicación y cuando cambia el destino (de la tienda al cliente): así se acerca.
				// 1-nov (teléfono de Ernesto, #6060): si la app redibuja mientras carga, el cuadro está un momento fuera de la página
				// (alto 0) y el encuadre no hace nada; antes se daba por hecho y el mapa se quedaba en toda La Habana. Ahora solo
				// cuenta si se pudo, y se repite si el cuadro cambia mucho de alto (pantalla completa ↔ pequeño).
				var key = to ? to.join(',') : '', st = map._pl, h = el.clientHeight;
				var p = big ? { top: 130, bottom: 330, left: 52, right: 52 } : { top: 60, bottom: 36, left: 40, right: 40 }; // la moto mide 44 px
				var off = big ? [0, -110] : [0, 0]; // tu moto un poco por encima de la tarjeta
				size(map);
				if (h < 80) {
					// fuera de la página: nada que encuadrar todavía
				} else if (pts.length && (!st.fitted || me && !st.withMe || st.toKey !== key || Math.abs(h - (st.h || 0)) > 120 || st.follow && me && to && (juntos(map, me, to) || salto(st, me, to)))) {
					var ok = true;
					if (pts.length === 1) { map.easeTo({ center: ll(pts[0]), zoom: 15, offset: off, duration: 0 }); } else { ok = fit(map, el, pts, p, 16, st.fitted && st.h === h); }
					if (ok) { st.fitted = true; st.withMe = !!me; st.toKey = key; st.h = h; st.fitMe = me; }
				} else if (me && st.follow && !(st.busy > Date.now())) {
					// Te sigue: con destino, la moto y el destino siempre a la vista (se acerca según llegas); sin destino, centrada.
					if (to) { fit(map, el, pts, p, 16, true); } else { map.easeTo({ center: ll(me), offset: off, duration: 800 }); }
				}
				st.to = to; st.pad = p;
				if (!pts.length && !st.fitted && h >= 80) { map.jumpTo({ center: ll(HABANA), zoom: 12 }); } // sin GPS ni punto: La Habana (no un mapa gris)
			}
			if (!to) { return me ? 'Sin punto en el mapa' : ''; }
			if (!me) { return 'Buscando tu ubicación…'; }
			if (c) { return c.min + ' min · ' + (c.km < 0.95 ? Math.round(c.km * 100) * 10 + ' m' : c.km.toFixed(1).replace('.', ',') + ' km'); } // por las calles
			var a = dist(me, to);
			var km = a < 950 ? Math.round(a / 10) * 10 + ' m' : (a / 1000).toFixed(1).replace('.', ',') + ' km';
			var min = Math.max(1, Math.round(a * 1.35 / 300)); // en moto: ~300 m/min y las calles alargan la recta un 35 %
			return min + ' min · ' + km; // 3.14.103: corto (el destino va debajo)
		});
	}

	/** 🎯: vuelve a seguirte y te centra. */
	function centrar(el, me) {
		var map = el && el._pallaMap; if (!map) { return; }
		map._pl.follow = true;
		if (me && map._pl.to && fit(map, el, [me, map._pl.to], map._pl.pad || pad(36), 16, true)) { return; } // tú y adónde vas
		if (me) { map.easeTo({ center: ll(me), zoom: Math.max(map.getZoom(), 15), offset: el.offsetHeight > 400 ? [0, -110] : [0, 0], duration: 700 }); }
	}

	window.PallaMapa = { ruta: ruta, tienda: tienda, marcar: marcar, mensajero: mensajero, centrar: centrar };
})();
