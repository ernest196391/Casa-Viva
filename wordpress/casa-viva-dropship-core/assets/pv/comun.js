/* Pa'llá transporte (5-nov-2026) · piezas comunes del cliente (/palla/ir/) y del chofer (/palla/chofer/).
 * Sin librerías. window.PV (config del servidor) + window.PMapa (mapa.js). Expone window.PU. */
(function () {
	'use strict';
	var C = window.PV || {};
	var B = C.base || '';
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var QUIETO = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var desfase = 0; // reloj del servidor − reloj del móvil (ms)

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function ico(n, c) { return '<svg class="p-ico ' + (c || '') + '" aria-hidden="true"><use href="' + B + 'iconos.svg?v=' + encodeURIComponent(C.v || '') + '#' + n + '"/></svg>'; }
	function veh(img, v) { return B + '3d/' + (img || 'tric-electrico') + '-' + (v || 'lado') + '.webp'; }
	function miles(n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
	function cup(n) { return miles(n) + ' CUP'; }
	function cupG(n) { return miles(n) + '<small>CUP</small>'; }
	function km(x) { return String(Math.round((+x || 0) * 10) / 10).replace('.', ','); }
	function hhmm(d) { return d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0'); }
	// Hora REAL (la del servidor): en Cuba muchos móviles tienen la hora puesta a mano y mal (5-nov: el Redmi iba 1 h adelantado).
	function hora(minMas) { return hhmm(new Date(ahora() + (minMas || 0) * 60000)); }
	function ahora() { return Date.now() + desfase; }
	function seg(iso) { return iso ? Math.round((Date.parse(iso) - ahora()) / 1000) : 0; }
	function estr(e) { return e == null ? '<span class="pa-estr">Nuevo</span>' : '<span class="pa-estr">' + ico('estrella-llena', 'p-ico--16') + String(e).replace('.', ',') + '</span>'; }
	function iniciales(n) { return String(n || '?').split(/\s+/).map(function (x) { return x.charAt(0); }).join('').slice(0, 2).toUpperCase(); }
	function cara(ch, px) {
		var st = px ? ' style="width:' + px + 'px;height:' + px + 'px"' : '';
		return ch && ch.foto ? '<img class="p-cara" src="' + esc(ch.foto) + '" alt=""' + st + ' style="object-fit:cover' + (px ? ';width:' + px + 'px;height:' + px + 'px' : '') + '">' : '<span class="p-cara"' + st + '>' + esc(iniciales(ch && ch.nombre)) + '</span>';
	}
	function tel53(t) { var d = String(t || '').replace(/\D+/g, ''); return d.length === 8 ? '53' + d : d; }
	function telBonito(t) { var d = tel53(t); return d.length === 10 ? '+53 ' + d.charAt(2) + ' ' + d.substr(3, 3) + ' ' + d.substr(6) : d; }

	var LS = {
		get: function (k) { try { return localStorage.getItem('pv.' + k); } catch (e) { return null; } },
		set: function (k, v) { try { if (v == null) { localStorage.removeItem('pv.' + k); } else { localStorage.setItem('pv.' + k, v); } } catch (e) { } },
		json: function (k) { try { return JSON.parse(LS.get(k) || 'null'); } catch (e) { return null; } }
	};

	/* Llamada a la API: nunca se queda colgada (15 s), y dice si no hubo red (status 0).
	 * 10-nov (prueba en el Redmi con 4G Nauta): en la red móvil cubana a veces una petición se queda sin respuesta
	 * aunque el servidor contesta en 0,2 s. Las consultas que solo leen (cotizar, sugerir, donde, cercanos) pasan
	 * `intentos` = 3: cada intento corta a los 8 s y se repite tras 1 s y 2 s (máximo ~27 s). Lo que cambia algo
	 * (pedir, elegir, cancelar…) NO se repite solo. */
	function api(path, body, cabeceras, metodo, intentos) {
		var n = Math.max(1, intentos || 1);
		if (n === 1 || body || (metodo && metodo !== 'GET')) { return api1(path, body, cabeceras, metodo, 15000); }
		var prueba = function (k) {
			return api1(path, null, cabeceras, 'GET', 8000).then(function (r) {
				if (r.status !== 0 || k >= n) { return r; }
				return new Promise(function (ok) { setTimeout(ok, 1000 * k); }).then(function () { return prueba(k + 1); });
			});
		};
		return prueba(1);
	}
	function api1(path, body, cabeceras, metodo, ms) {
		var h = { 'Accept': 'application/json' };
		Object.keys(cabeceras || {}).forEach(function (k) { if (cabeceras[k]) { h[k] = cabeceras[k]; } });
		var esForm = typeof FormData !== 'undefined' && body instanceof FormData;
		if (body && !esForm) { h['Content-Type'] = 'application/json'; }
		var ctrl = window.AbortController ? new AbortController() : null;
		var corte = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, ms);
		return fetch(C.api + path, { method: metodo || (body ? 'POST' : 'GET'), headers: h, body: body ? (esForm ? body : JSON.stringify(body)) : undefined, cache: 'no-store', credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
			.then(function (r) {
				clearTimeout(corte);
				var d = Date.parse(r.headers.get('Date') || ''); if (d) { desfase = d - Date.now(); }
				return r.json().catch(function () { return {}; }).then(function (j) { PU.sinRed(false); return { status: r.status, data: j || {} }; });
			}, function () { clearTimeout(corte); PU.sinRed(true); return { status: 0, data: { message: 'Sin señal.' } }; });
	}

	function tostada(txt, icono) {
		var v = $('.pa-tostada'); if (v) { v.remove(); }
		var t = document.createElement('div'); t.className = 'pa-tostada'; t.setAttribute('role', 'status');
		t.innerHTML = ico(icono || 'check', 'p-ico--20') + '<span>' + esc(txt) + '</span>';
		($('#marco') || document.body).appendChild(t); setTimeout(function () { t.remove(); }, 2800);
	}
	function hojaSobre(html, alAbrir) {
		var h = document.createElement('div'); h.className = 'pa-sobre';
		h.innerHTML = '<div class="pa-velo" data-cerrar></div><section class="pa-hoja" style="z-index:11"><div class="p-hoja__asa"></div>' + html + '</section>';
		($('#marco') || document.body).appendChild(h);
		$$('[data-cerrar]', h).forEach(function (x) { x.addEventListener('click', function () { h.remove(); }); });
		if (alAbrir) { alAbrir(h); }
		return h;
	}

	/* Barra de progreso del viaje (como Yandex Go): el vehículo encima, % y hora de llegada. */
	function barra(img, llegaTxt) {
		return '<div class="p-progreso" role="progressbar" aria-label="Recorrido" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">'
			+ '<div class="p-progreso__pista"><i class="p-progreso__lleno"></i><span class="p-progreso__veh"><img src="' + veh(img) + '" alt=""></span></div>'
			+ '<div class="p-progreso__txt"><span><b data-pct>0 %</b> recorrido</span><span data-llega>' + esc(llegaTxt) + '</span></div></div>';
	}
	/* Mueve la barra hasta k (0→1) suave (sin saltos cuando llega una posición nueva). */
	function pintarBarra(r, k, idMapa) {
		var box = $('.p-progreso', r); if (!box) { return; }
		k = Math.max(0, Math.min(1, k || 0));
		var desde = +(box.dataset.k || 0), t0 = performance.now(), ms = QUIETO ? 0 : 900;
		box.dataset.k = k;
		var lleno = $('.p-progreso__lleno', box), vh = $('.p-progreso__veh', box), pct = $('[data-pct]', box);
		function paso(t) {
			var f = ms ? Math.min(1, (t - t0) / ms) : 1, x = desde + (k - desde) * (1 - Math.pow(1 - f, 3));
			lleno.style.transform = 'scaleX(' + x.toFixed(4) + ')';
			vh.style.left = 'calc(' + (x * 100).toFixed(2) + '% - ' + (x * 36).toFixed(1) + 'px)';
			pct.textContent = Math.round(x * 100) + ' %'; box.setAttribute('aria-valuenow', Math.round(x * 100));
			if (idMapa && window.PMapa) { PMapa.colocar(idMapa, x); }
			if (f < 1) { requestAnimationFrame(paso); }
		}
		requestAnimationFrame(paso);
	}

	function estrellas(n) { var h = ''; for (var i = 1; i <= 5; i++) { h += '<button type="button" aria-label="' + i + ' estrellas" class="' + (i <= n ? 'is-on' : '') + '" data-e="' + i + '"><svg viewBox="0 0 24 24"><use href="' + B + 'iconos.svg?v=' + encodeURIComponent(C.v || '') + '#estrella-llena"/></svg></button>'; } return '<div class="pa-estrellas">' + h + '</div>'; }
	function engancharEstrellas(r, alTocar) { $$('[data-e]', r).forEach(function (b) { b.onclick = function () { var n = +b.dataset.e; $$('[data-e]', r).forEach(function (x) { x.classList.toggle('is-on', +x.dataset.e <= n); }); if (alTocar) { alTocar(n); } }; }); }

	/* Ruta por calles con nuestro OSRM del VPS (pocas veces: al empezar cada tramo). [[lon,lat]…] o null. */
	var rutas = {};
	function ruta(a, b, bici) {
		var k = (bici ? 'b' : 'c') + a.map(function (v) { return v.toFixed(4); }).join(',') + ';' + b.map(function (v) { return v.toFixed(4); }).join(',');
		if (rutas[k]) { return Promise.resolve(rutas[k]); }
		var u = 'https://179-236-242-212.sslip.io/route/v1/' + (bici ? 'cycling' : 'driving') + '/' + a[0].toFixed(6) + ',' + a[1].toFixed(6) + ';' + b[0].toFixed(6) + ',' + b[1].toFixed(6) + '?overview=full&geometries=geojson';
		return Promise.race([
			fetch(u, { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) { var c = d && d.routes && d.routes[0] && d.routes[0].geometry && d.routes[0].geometry.coordinates; if (c && c.length > 1) { rutas[k] = c; return c; } return null; }),
			new Promise(function (ok) { setTimeout(function () { ok(null); }, 5000); })
		]).catch(function () { return null; });
	}
	function lineaLonLat(linea) { return linea && linea.length > 1 ? linea.map(function (p) { return [p[1], p[0]]; }) : null; }
	function P(o) { return o ? [+o.lng, +o.lat] : null; }

	/* En vivo: escucha el canal de ntfy (EventSource) y, si se corta, pregunta a la web. alLlegar(mensaje|null). */
	function enVivo(topic, alLlegar) {
		var es = null, vivo = false, cerrado = false;
		if (topic && C.ntfy && window.EventSource) {
			try {
				es = new EventSource(C.ntfy + '/' + topic + '/sse');
				es.onopen = function () { vivo = true; };
				es.onerror = function () { vivo = false; };
				es.onmessage = function (ev) {
					var m = null; try { var j = JSON.parse(ev.data); if (j && j.event === 'message') { m = JSON.parse(j.message); } } catch (e) { }
					if (m && !cerrado) { alLlegar(m); }
				};
			} catch (e) { es = null; }
		}
		return { vivo: function () { return vivo; }, cerrar: function () { cerrado = true; if (es) { es.close(); } } };
	}

	var avisoRed = null;
	window.PU = {
		$: $, $$: $$, esc: esc, ico: ico, veh: veh, miles: miles, cup: cup, cupG: cupG, km: km, hora: hora, hhmm: hhmm, seg: seg, ahora: ahora,
		estr: estr, cara: cara, iniciales: iniciales, tel53: tel53, telBonito: telBonito, LS: LS, api: api, tostada: tostada, hojaSobre: hojaSobre,
		barra: barra, pintarBarra: pintarBarra, estrellas: estrellas, engancharEstrellas: engancharEstrellas, ruta: ruta, lineaLonLat: lineaLonLat, P: P,
		enVivo: enVivo, QUIETO: QUIETO,
		/* Banda arriba «Sin señal…» (no tapa nada; se quita sola al volver). */
		sinRed: function (si) {
			var marco = $('#marco'); if (!marco) { return; }
			if (si && !avisoRed) {
				avisoRed = document.createElement('div'); avisoRed.className = 'pa-banda'; avisoRed.setAttribute('role', 'status');
				avisoRed.innerHTML = ico('sin-senal', 'p-ico--20') + '<span>Sin señal. Seguimos probando.</span>';
				marco.appendChild(avisoRed);
			} else if (!si && avisoRed) { var b = avisoRed; avisoRed = null; b.classList.add('is-bien'); b.innerHTML = ico('check', 'p-ico--20') + '<span>Volvió la señal.</span>'; setTimeout(function () { b.remove(); }, 1500); }
		},
		sw: function () { if ('serviceWorker' in navigator && C.sw) { navigator.serviceWorker.register(C.sw, { scope: C.scope }).catch(function () { }); } }
	};
})();
