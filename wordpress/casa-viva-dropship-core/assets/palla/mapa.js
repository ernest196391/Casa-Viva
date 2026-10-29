/* Pa'llá · mapa del recorrido (Core 3.14.72.2, Ernesto 28-oct: «como Mandao»).
 * PallaMapa.ruta(el, ruta)    → el seguimiento (gestora y cliente): tienda 🏪, cliente 🏠, mensajero 🛵 y la línea
 *                                amarilla con el camino que ya hizo; si va al cliente, una línea de puntos hasta allí.
 * PallaMapa.tienda(el, lista) → la tienda: todos los mensajeros en camino, cada uno con su carrera.
 * Leaflet (cdnjs) y el mapa de OpenStreetMap se cargan solo cuando hay algo que enseñar. Sin Google. */
(function () {
	'use strict';
	if (window.PallaMapa) { return; }
	var LEAFLET = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';
	var ready = null;
	function load() {
		if (window.L) { return Promise.resolve(window.L); }
		if (ready) { return ready; }
		ready = new Promise(function (ok, fail) {
			var css = document.createElement('link');
			css.rel = 'stylesheet';
			css.href = LEAFLET + 'leaflet.min.css';
			document.head.appendChild(css);
			var js = document.createElement('script');
			js.src = LEAFLET + 'leaflet.min.js';
			js.onload = function () { ok(window.L); };
			js.onerror = function () { ready = null; fail(new Error('mapa')); };
			document.head.appendChild(js);
		});
		return ready;
	}
	function pin(L, emoji, title) {
		return L.divIcon({ className: 'pl-pin', html: '<span title="' + (title || '') + '" style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:#fff;border:2px solid #121212;font-size:18px;box-shadow:0 1px 4px rgba(0,0,0,.25)">' + emoji + '</span>', iconSize: [34, 34], iconAnchor: [17, 17] });
	}
	function base(L, el) {
		if (el._pallaMap) { return el._pallaMap; }
		if (!el.style.height) { el.style.height = '260px'; }
		el.style.borderRadius = '12px';
		el.style.overflow = 'hidden';
		var map = L.map(el, { zoomControl: true, attributionControl: true, scrollWheelZoom: false });
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
		map._layer = L.layerGroup().addTo(map);
		map._fitted = false;
		el._pallaMap = map;
		return map;
	}
	function ago(s) {
		if (s < 90) { return 'ahora'; }
		var m = Math.round(s / 60);
		return m < 60 ? 'hace ' + m + ' min' : 'hace más de una hora';
	}

	/** Seguimiento de un pedido. Devuelve el texto de debajo («Daiquel va hacia ti · llega en ~8 min · ahora»). */
	function ruta(el, r) {
		return load().then(function (L) {
			var map = base(L, el), g = map._layer, pts = [];
			g.clearLayers();
			if (r.store) { L.marker(r.store, { icon: pin(L, '🏪', 'Casa Viva') }).addTo(g); pts.push(r.store); }
			if (r.dest) { L.marker(r.dest, { icon: pin(L, '🏠', 'Entrega') }).addTo(g); pts.push(r.dest); }
			if (r.trail && r.trail.length > 1) { L.polyline(r.trail, { color: '#FFC21A', weight: 6, opacity: .95 }).addTo(g); }
			if (r.pos) {
				var here = [r.pos.lat, r.pos.lon];
				var to = r.stage === 'tienda' ? r.store : r.dest;
				if (to && !r.arrived) { L.polyline([here, to], { color: '#121212', weight: 2, dashArray: '4 8', opacity: .6 }).addTo(g); }
				L.marker(here, { icon: pin(L, '🛵', r.name), zIndexOffset: 1000 }).addTo(g);
				pts.push(here);
			}
			if (!map._fitted && pts.length) {
				if (pts.length === 1) { map.setView(pts[0], 16); } else { map.fitBounds(pts, { padding: [30, 30], maxZoom: 16 }); }
				map._fitted = true;
			}
			setTimeout(function () { map.invalidateSize(); }, 50);
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
		return load().then(function (L) {
			var map = base(L, el), g = map._layer, pts = [];
			g.clearLayers();
			list.forEach(function (m) {
				if (!m.pos) { return; }
				var here = [m.pos.lat, m.pos.lon];
				L.marker(here, { icon: pin(L, '🛵', m.name) }).addTo(g).bindTooltip(m.name + ' · #' + m.number + ' · ' + (m.stage === 'tienda' ? 'viene a la tienda' : 'va a ' + m.zone) + ' · ' + ago(m.pos.ago), { direction: 'top', offset: [0, -16] });
				pts.push(here);
				if (m.dest && m.stage !== 'tienda') { L.marker(m.dest, { icon: pin(L, '🏠', '#' + m.number) }).addTo(g); L.polyline([here, m.dest], { color: '#121212', weight: 2, dashArray: '4 8', opacity: .5 }).addTo(g); pts.push(m.dest); }
			});
			if (!map._fitted && pts.length) { map.fitBounds(pts, { padding: [30, 30], maxZoom: 15 }); map._fitted = true; }
			setTimeout(function () { map.invalidateSize(); }, 50);
			return pts.length;
		});
	}

	/** 3.14.74.3: el cliente marca su casa (toca el mapa o arrastra la 🏠). → { put([lat, lon]), get() → [lat, lon] | null } */
	function marcar(el, start) {
		return load().then(function (L) {
			var map = base(L, el), mk = null;
			map.setView(start, 15);
			setTimeout(function () { map.invalidateSize(); }, 50);
			function put(ll) {
				if (mk) { mk.setLatLng(ll); } else { mk = L.marker(ll, { icon: pin(L, '🏠', 'Tu casa'), draggable: true }).addTo(map._layer); }
			}
			map.on('click', function (e) { put(e.latlng); });
			return {
				put: function (ll) { put(ll); map.setView(ll, 17); },
				get: function () { if (!mk) { return null; } var p = mk.getLatLng(); return [p.lat, p.lng]; }
			};
		});
	}

	window.PallaMapa = { ruta: ruta, tienda: tienda, marcar: marcar };
})();
