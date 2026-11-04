/* Triciclub · app del cliente (Core 3.14.118). Rehecha el 3-nov siguiendo cómo funciona La Nave DE VERDAD
 * (visto en el móvil de Ernesto: docs/nexo-business/apps/triciclub/recon-lanave/RECON.md):
 *  1. Abre en el MAPA con tu punto (GPS) y los triciclos libres cerca. Abajo «¿A dónde vamos?», Casa, Trabajo y recientes.
 *  2. Buscar: el origen ya viene puesto («Calle 23 e/ L y M»); mientras escribes salen calles y barrios; «Fijar ubicación
 *     en el mapa» SIEMPRE a mano. Nunca te bloquea.
 *  3. Confirmar en el mapa el punto exacto de recogida.
 *  4. Elegir: Viaje / Paquete / Carga, el triciclo más barato (o el más cercano) con SU precio — lo pone cada chofer y no
 *     cambia al llegar —, pago, llegada y un solo botón «Pedir». «Ver los N triciclos» para elegir otro.
 *  5. Esperar que acepte (90 s) → seguirlo en el mapa con el código de seguridad → llegar, pagar lo que viste y valorar.
 * Sin señal: el pedido queda en el teléfono y sale solo al volver (mismo `cid`). ES5, un solo oyente, todo con esc(). */
(function () {
	'use strict';
	var C = window.TRICICLUB || {};
	var app = document.getElementById('app');
	if (!app) { return; }
	var API = C.api || '/wp-json/casa-viva/v1/triciclub/';
	var HABANA = C.habana || [23.125, -82.38];
	var SERV = [
		{ id: 'viaje', nombre: 'Viaje', sub: 'Personas', icon: '<circle cx="9" cy="5" r="2"/><path d="M7 21l2-7 3 3v5M9 14l-1-5 5 2 3 3"/>' },
		{ id: 'paquete', nombre: 'Paquete', sub: 'Mandados', icon: '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>' },
		{ id: 'carga', nombre: 'Carga', sub: 'Mudanzas', icon: '<rect x="3" y="9" width="12" height="8" rx="1"/><path d="M15 12h3l3 3v2h-6"/><circle cx="7" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>' }
	];
	var TAGS = [['puntual', 'Puntual'], ['amable', 'Amable'], ['limpio', 'Triciclo limpio'], ['cuidado', 'Cuidó mis cosas']];
	var I = {
		menu: '<path d="M4 7h16M4 12h16M4 17h16"/>', atras: '<path d="M15 18l-6-6 6-6"/>', gps: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
		pin: '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>', casa: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
		trabajo: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>', reloj: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		calle: '<path d="M4 20L10 4M20 20L14 4M12 6v2M12 11v2M12 16v2"/>', tel: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		compartir: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
		escudo: '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>', candado: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		senal: '<path d="M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0M12 19h.01M3 3l18 18"/>', bien: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
		estrella: '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/>', cerrar: '<path d="M6 6l12 12M18 6L6 18"/>',
		wa: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'
	};

	var S = {
		token: '', yo: null, view: 'cargando', paso: 'telefono', telefono: '', toast: '', busy: false, offline: !navigator.onLine,
		gps: null, gpsError: '', origen: null, destino: null, // {texto, sub, lat, lng}
		campo: 'destino', texto: '', sugerencias: [], fijar: null, fijarTexto: '', // fijar = 'origen' | 'destino'
		servicio: 'viaje', orden: 'barato', cerca: null, elegido: 0, verTodos: false, pago: 'efectivo', nota: '', notaAbierta: false, recibe: { nombre: '', telefono: '' },
		viaje: null, estrellas: 5, tags: { puntual: true }, historial: null, cerrado: ''
	};
	var T = { poll: 0, toast: 0, cola: 0, tick: 0, sug: 0, libres: 0, donde: 0 };
	var mapa = null, mapaEl = null;

	// ------------------------------------------------------------ Utilidades
	var store = {
		get: function (k) { try { return window.localStorage.getItem('tc.' + k); } catch (e) { return null; } },
		set: function (k, v) { try { if (v === null) { window.localStorage.removeItem('tc.' + k); } else { window.localStorage.setItem('tc.' + k, v); } } catch (e) {} },
		json: function (k, d) { try { var v = JSON.parse(store.get(k) || 'null'); return v === null ? d : v; } catch (e) { return d; } }
	};
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function cup(n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
	function svg(p, size, color) { return '<svg width="' + (size || 22) + '" height="' + (size || 22) + '" viewBox="0 0 24 24" fill="none" stroke="' + (color || 'currentColor') + '" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; }
	function cid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
	function toast(t) { S.toast = t; render(); clearTimeout(T.toast); T.toast = setTimeout(function () { S.toast = ''; render(); }, 3500); }
	function serv(id) { for (var i = 0; i < SERV.length; i++) { if (SERV[i].id === id) { return SERV[i]; } } return SERV[0]; }
	function ini(n) { var p = String(n || '').split(/\s+/); return ((p[0] || '?').charAt(0) + (p[1] || '').charAt(0)).toUpperCase(); }
	function p2(o) { return o ? [o.lat, o.lng] : null; }
	function enCuba(lat, lng) { return lat >= 19.5 && lat <= 23.5 && lng >= -85 && lng <= -74; }
	function corto(t) { return String(t || '').split(',')[0]; }

	function api(path, body) {
		var h = { 'Accept': 'application/json' };
		if (S.token) { h['X-TC-Token'] = S.token; }
		if (body) { h['Content-Type'] = 'application/json'; }
		var ctrl = window.AbortController ? new AbortController() : null, corte = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 15000); // con mala red, nunca colgada
		return fetch(API + path, { method: body ? 'POST' : 'GET', headers: h, body: body ? JSON.stringify(body) : undefined, cache: 'no-store', credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
			.then(function (r) { clearTimeout(corte); return r; }, function (e) { clearTimeout(corte); throw e; })
			.then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { S.offline = false; return { status: r.status, data: d || {} }; }); })
			.catch(function () { S.offline = true; return { status: 0, data: { message: 'Sin conexión.' } }; });
	}
	function salir(res) {
		if (res.status === 401) { store.set('k', null); S.token = ''; S.view = 'entrar'; S.paso = 'telefono'; render(); return true; }
		if (res.status === 403) { S.view = 'cerrado'; S.cerrado = res.data.message || 'No puedes entrar ahora.'; render(); return true; }
		return false;
	}

	// ------------------------------------------------------------ Mapa (uno solo, detrás de todo)
	function abajo() { var s = app.querySelector('.tc-hoja'); return s ? s.offsetHeight : 0; }
	function conMapa(fn) {
		if (mapa) { fn(mapa); return; }
		if (!window.TcMapa || !mapaEl) { return; }
		window.TcMapa.crear(mapaEl, p2(S.origen) || S.gps || HABANA).then(function (m) {
			if (!m) { return; }
			mapa = m;
			window.TcMapa.alMover(mapa, movido);
			fn(mapa);
		}).catch(function () { mapaEl.innerHTML = '<div class="tc-sinmapa">Este teléfono no puede enseñar el mapa. Escribe las direcciones.</div>'; });
	}
	function pintarMapa() {
		conMapa(function (m) {
			var M = window.TcMapa, v = S.viaje;
			M.yo(m, S.gps);
			if (S.view === 'fijar') { M.borrar(m); M.triciclos(m, []); return; }
			if (S.view === 'viaje' && v) {
				M.triciclos(m, []);
				var o = [v.origen.lat, v.origen.lng], d = [v.destino.lat, v.destino.lng], pos = v.pos ? [v.pos.lat, v.pos.lon] : null;
				M.pin(m, 'origen', v.estado === 'recogido' ? null : o); M.pin(m, 'destino', d); M.chofer(m, pos);
				var clave = v.uuid + v.estado;
				if (m._tcVista !== clave) { m._tcVista = clave; M.ruta(m, v.estado === 'recogido' ? (pos || o) : o, d); M.ver(m, [pos, v.estado === 'recogido' ? null : o, d], abajo()); }
				return;
			}
			M.chofer(m, null);
			M.pin(m, 'origen', p2(S.origen));
			M.pin(m, 'destino', S.view === 'elegir' ? p2(S.destino) : null);
			if (S.view === 'elegir' && S.origen && S.destino) {
				var k = S.origen.lat + ',' + S.origen.lng + '>' + S.destino.lat + ',' + S.destino.lng;
				if (m._tcVista !== k) { m._tcVista = k; M.ruta(m, p2(S.origen), p2(S.destino)); M.ver(m, [p2(S.origen), p2(S.destino)], abajo()); }
			} else if (S.view === 'mapa') {
				if (m._tcVista !== 'mapa') { m._tcVista = 'mapa'; M.ruta(m, null, null); if (S.origen || S.gps) { M.ir(m, p2(S.origen) || S.gps, 16, abajo()); } }
			}
		});
	}
	function movido(c) { // solo cuenta en «fijar»: el pin está quieto en el centro y se mueve el mapa
		if (S.view !== 'fijar') { return; }
		S.fijarTexto = 'Buscando la dirección…'; pintarFijar();
		clearTimeout(T.donde);
		T.donde = setTimeout(function () {
			api('donde?lat=' + c[0].toFixed(6) + '&lng=' + c[1].toFixed(6)).then(function (res) {
				if (S.view !== 'fijar') { return; }
				S.fijarTexto = res.data.ok ? res.data.texto + (res.data.barrio ? ', ' + res.data.barrio : '') : 'Punto en el mapa';
				S.fijarPunto = c; pintarFijar();
			});
		}, 350);
	}
	function pintarFijar() { var el = document.getElementById('tc-fijar-txt'); if (el) { el.textContent = S.fijarTexto; } }
	function libres() { // los triciclos libres en el mapa de inicio (cada 30 s)
		clearTimeout(T.libres);
		var p = p2(S.origen) || S.gps;
		if (S.view === 'mapa' && p && S.token) {
			api('libres?lat=' + p[0] + '&lng=' + p[1]).then(function (res) { if (res.data.ok && S.view === 'mapa') { conMapa(function (m) { window.TcMapa.triciclos(m, res.data.triciclos); }); } });
		}
		T.libres = setTimeout(libres, 30000);
	}

	// ------------------------------------------------------------ GPS → origen («Calle 23 e/ L y M»)
	function gps() {
		if (!navigator.geolocation) { S.gpsError = 'sin'; render(); return; }
		navigator.geolocation.watchPosition(function (pos) {
			var c = pos.coords;
			if (!enCuba(c.latitude, c.longitude)) { S.gpsError = 'fuera'; render(); return; }
			var primera = !S.gps;
			S.gps = [c.latitude, c.longitude]; S.gpsError = '';
			if (primera && !S.origen) { origenDesde(S.gps, true); }
			pintarMapa();
		}, function () { S.gpsError = 'no'; render(); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
	}
	function origenDesde(p, auto) {
		S.origen = { texto: 'Tu ubicación', sub: '', lat: p[0], lng: p[1], auto: !!auto };
		render(); pintarMapa(); libres();
		api('donde?lat=' + p[0].toFixed(6) + '&lng=' + p[1].toFixed(6)).then(function (res) {
			if (res.data.ok && S.origen && S.origen.lat === p[0]) { S.origen.texto = res.data.texto; S.origen.sub = res.data.barrio; render(); }
		});
	}

	// ------------------------------------------------------------ Buscar y sugerencias
	function abrirBuscar(campo) {
		S.campo = campo || 'destino'; S.texto = ''; S.sugerencias = []; S.sugEstado = ''; S.view = 'buscar'; render();
		setTimeout(function () { var i = document.getElementById('tc-q'); if (i) { i.focus(); } }, 50);
	}
	function sugerir() {
		clearTimeout(T.sug);
		var q = S.texto.trim();
		if (q.length < 2) { S.sugerencias = []; S.sugEstado = ''; render(); return; }
		S.sugEstado = 'buscando'; pintarSugEstado();
		T.sug = setTimeout(function () {
			var p = p2(S.origen) || S.gps;
			api('sugerir?texto=' + encodeURIComponent(q) + (p ? '&lat=' + p[0] + '&lng=' + p[1] : '')).then(function (res) {
				if (S.view !== 'buscar' || S.texto.trim() !== q) { return; }
				S.sugerencias = res.data.sugerencias || [];
				S.sugEstado = res.status === 0 ? 'sinred' : 'listo'; render();
			});
		}, 500);
	}
	function pintarSugEstado() { var e = document.getElementById('tc-sug-estado'); if (e && !(S.sugerencias || []).length) { e.textContent = 'Buscando…'; } }
	function elegirLugar(l) {
		var lugar = { texto: l.texto, sub: l.sub || '', lat: l.lat, lng: l.lng };
		// Una calle o un barrio es una zona, no un punto: se abre el mapa allí para poner el pin exacto (como La Nave).
		if (l.tipo === 'calle' || l.tipo === 'barrio') { abrirFijar(S.campo, [l.lat, l.lng]); return; }
		ponerLugar(S.campo, lugar);
	}
	function ponerLugar(campo, lugar) {
		S[campo] = lugar;
		if (campo === 'destino') { recordar(lugar); }
		if (campo === 'origen') { S.view = S.destino ? 'confirmar' : 'mapa'; if (S.view === 'confirmar') { abrirFijar('origen', p2(S.origen), true); return; } render(); pintarMapa(); return; }
		abrirFijar('origen', p2(S.origen) || S.gps || HABANA, true); // paso de La Nave: «Confirma el punto de origen»
	}
	function abrirFijar(campo, p, confirmar) {
		S.fijar = campo; S.confirmar = !!confirmar; S.view = 'fijar'; S.fijarTexto = 'Mueve el mapa hasta el punto exacto'; S.fijarPunto = p;
		render();
		conMapa(function (m) { m._tcVista = ''; window.TcMapa.borrar(m); window.TcMapa.ir(m, p, 17); setTimeout(function () { movido(window.TcMapa.centro(m)); }, 600); });
	}
	function confirmarFijar() {
		var c = mapa ? window.TcMapa.centro(mapa) : S.fijarPunto;
		if (!c) { return; }
		var partes = String(S.fijarTexto || '').split(', ');
		var lugar = { texto: /Buscando|Mueve/.test(S.fijarTexto) ? 'Punto en el mapa' : partes[0], sub: partes.slice(1).join(', '), lat: c[0], lng: c[1] };
		S[S.fijar] = lugar;
		if (S.fijar === 'destino') { recordar(lugar); abrirFijar('origen', p2(S.origen) || S.gps || c, true); return; }
		if (S.destino) { verTriciclos(); return; }
		S.view = 'mapa'; render(); pintarMapa();
	}
	function recordar(l) {
		var r = store.json('recientes', []).filter(function (x) { return x.texto !== l.texto; });
		r.unshift({ texto: l.texto, sub: l.sub, lat: l.lat, lng: l.lng });
		store.set('recientes', JSON.stringify(r.slice(0, 6)));
	}
	function guardado(tipo) { return store.json(tipo, null); }

	// ------------------------------------------------------------ Elegir triciclo y pedir
	function verTriciclos() {
		if (!S.origen || !S.destino) { return; }
		S.view = 'elegir'; S.cerca = null; S.elegido = 0; S.verTodos = false; render(); pintarMapa();
		api('cerca?servicio=' + S.servicio + '&olat=' + S.origen.lat + '&olng=' + S.origen.lng + '&dlat=' + S.destino.lat + '&dlng=' + S.destino.lng).then(function (res) {
			if (salir(res)) { return; }
			if (!res.data.ok) { S.cerca = { error: res.data.message || (res.status ? 'No se pudo.' : 'Sin conexión.'), triciclos: [] }; render(); return; }
			S.cerca = res.data; S.cerca.recibido = Date.now(); ordenar(); render(); pintarMapa();
		});
	}
	function ordenar() {
		if (!S.cerca || !S.cerca.triciclos) { return; }
		S.cerca.triciclos.sort(S.orden === 'cerca' ? function (a, b) { return a.lejos_m - b.lejos_m; } : function (a, b) { return a.precio - b.precio || a.lejos_m - b.lejos_m; });
		S.elegido = 0;
	}
	function pedir() {
		var t = S.cerca && S.cerca.triciclos[S.elegido];
		if (!t || S.busy) { return; }
		if (Date.now() - (S.cerca.recibido || 0) > 9 * 60000) { toast('Los precios caducaron. Los busco otra vez.'); verTriciclos(); return; }
		if (S.servicio === 'paquete' && (!S.recibe.nombre || !S.recibe.telefono)) { toast('Pon quién recibe el paquete y su teléfono.'); return; }
		var p = { cid: cid(), firma: t.firma, forma_pago: S.pago, nota: S.nota, origen_texto: S.origen.texto + (S.origen.sub ? ', ' + S.origen.sub : ''), destino_texto: S.destino.texto + (S.destino.sub ? ', ' + S.destino.sub : ''), recibe_nombre: S.recibe.nombre, recibe_telefono: S.recibe.telefono, _r: { nombre: t.nombre, precio: t.precio, origen: S.origen.texto, destino: S.destino.texto, servicio: S.servicio } };
		store.set('cola', JSON.stringify(p));
		enviar(p);
	}
	function enviar(p) {
		S.busy = true; render();
		var b = {}; Object.keys(p).forEach(function (k) { if (k.charAt(0) !== '_') { b[k] = p[k]; } });
		return api('viajes', b).then(function (res) {
			S.busy = false;
			if (res.status === 0) { S.pendiente = p; S.view = 'sinred'; render(); clearTimeout(T.cola); T.cola = setTimeout(function () { var q = store.json('cola', null); if (q) { enviar(q); } }, 15000); return; }
			store.set('cola', null); S.pendiente = null;
			if (salir(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo pedir.'); if (res.data.code === 'ya_tienes') { cargarYo(); return; } verTriciclos(); return; }
			abrirViaje(res.data.viaje);
		});
	}
	function abrirViaje(v) { S.viaje = v; S.view = 'viaje'; S.estrellas = 5; S.tags = { puntual: true }; if (location.hash !== '#viaje/' + v.uuid) { location.hash = '#viaje/' + v.uuid; } render(); pintarMapa(); seguir(); }
	function seguir() {
		clearTimeout(T.poll);
		var v = S.viaje;
		if (!v || ['terminado', 'cancelado', 'rechazado'].indexOf(v.estado) !== -1) { return; }
		T.poll = setTimeout(function () {
			api('viajes/' + v.uuid).then(function (res) {
				if (salir(res)) { return; }
				if (res.data.ok) {
					var antes = S.viaje && S.viaje.estado;
					S.viaje = res.data.viaje;
					if (antes !== S.viaje.estado && navigator.vibrate) { try { navigator.vibrate([200, 80, 200]); } catch (e) {} }
					if (S.view === 'viaje') { render(); pintarMapa(); }
				}
				seguir();
			});
		}, document.hidden ? 30000 : (v.estado === 'esperando' ? 4000 : 8000));
	}
	function cancelar() {
		if (!S.viaje || !window.confirm('¿Seguro que quieres cancelar?')) { return; }
		api('viajes/' + S.viaje.uuid + '/cancelar', { motivo: 'el cliente canceló' }).then(function (res) { if (salir(res)) { return; } if (res.data.ok) { S.viaje = res.data.viaje; render(); } else { toast(res.data.message || 'No se pudo cancelar.'); } });
	}
	function valorar() {
		var tags = Object.keys(S.tags).filter(function (k) { return S.tags[k]; });
		api('viajes/' + S.viaje.uuid + '/valorar', { estrellas: S.estrellas, etiquetas: tags }).then(function () { toast('¡Gracias! Te esperamos en el próximo viaje.'); inicio(); });
	}
	function inicio() {
		S.viaje = null; S.cerca = null; S.destino = null; S.nota = ''; S.notaAbierta = false; S.recibe = { nombre: '', telefono: '' };
		S.view = 'mapa'; if (location.hash !== '#inicio') { location.hash = '#inicio'; }
		if (S.gps) { origenDesde(S.gps, true); } else { render(); pintarMapa(); }
	}
	function compartir() {
		var v = S.viaje, ch = v && v.chofer;
		var txt = 'Voy en Triciclub con ' + (ch ? ch.nombre + ' (triciclo Nº ' + (ch.numero || '—') + ')' : 'un triciclo') + ' de ' + v.origen.texto + ' a ' + v.destino.texto + '.';
		if (navigator.share) { navigator.share({ title: 'Mi viaje en Triciclub', text: txt }).catch(function () {}); return; }
		window.open('https://wa.me/?text=' + encodeURIComponent(txt), '_blank', 'noopener');
	}

	// ------------------------------------------------------------ Entrar
	function pedirCodigo() {
		S.telefono = (document.getElementById('tc-tel') || {}).value || S.telefono; S.busy = true; render();
		api('cliente/codigo', { telefono: S.telefono }).then(function (res) {
			S.busy = false;
			if (res.data.ok) { S.paso = 'codigo'; render(); toast(res.data.message); return; }
			if (res.status === 403) { salir(res); return; }
			toast(res.data.message || 'No se pudo. Prueba otra vez.');
		});
	}
	function entrar() {
		var codigo = (document.getElementById('tc-cod') || {}).value || '', nombre = (document.getElementById('tc-nom') || {}).value || '', ok = !!(document.getElementById('tc-ok') || {}).checked;
		if (!nombre.trim()) { toast('Pon tu nombre para que el chofer sepa a quién busca.'); return; }
		S.busy = true; render();
		api('cliente/entrar', { telefono: S.telefono, codigo: codigo, nombre: nombre, consentimiento: ok }).then(function (res) {
			S.busy = false;
			if (!res.data.ok) { if (res.status === 403) { salir(res); return; } toast(res.data.message || 'No se pudo entrar.'); return; }
			S.token = res.data.token; store.set('k', S.token); S.yo = res.data.yo; S.view = 'mapa'; render(); gps(); libres();
		});
	}

	// ------------------------------------------------------------ Pantallas
	function sinRed() { return S.offline ? '<div class="tc-sinred" role="status">' + svg(I.senal, 18, '#FFB37A') + 'Sin internet ahora mismo</div>' : ''; }
	function lugarLinea(l, cls) { return '<span class="tc-lugar"><span class="tc-ruta__punto' + (cls || '') + '"></span><span class="tc-lugar__t"><b>' + esc(corto(l.texto)) + '</b>' + (l.sub ? '<small>' + esc(l.sub) + '</small>' : '') + '</span></span>'; }

	function screenEntrar() {
		var h = '<div class="tc-pagina"><main class="tc-main"><img src="' + esc(C.logo) + '" alt="Triciclub" width="200" height="39" style="margin:24px 0 8px">';
		if (S.paso === 'telefono') {
			h += '<h1 class="tc-h1">Entra con tu móvil</h1><p class="tc-muted">Te mandamos un código por WhatsApp. Sin contraseñas.</p>'
				+ '<label class="tc-field"><span>Tu móvil</span><input id="tc-tel" class="tc-input" type="tel" inputmode="tel" autocomplete="tel" placeholder="5X XXX XXX" value="' + esc(S.telefono) + '"></label>'
				+ '<button type="button" class="tc-btn" data-act="codigo"' + (S.busy ? ' disabled' : '') + '>Mandarme el código</button>';
		} else {
			h += '<h1 class="tc-h1">Pon el código</h1><p class="tc-muted">Lo mandamos por WhatsApp al ' + esc(S.telefono) + '.</p>'
				+ '<label class="tc-field"><span>Código de 6 números</span><input id="tc-cod" class="tc-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>'
				+ '<label class="tc-field"><span>Tu nombre</span><input id="tc-nom" class="tc-input" autocomplete="given-name" maxlength="80"></label>'
				+ '<label class="tc-check"><input id="tc-ok" type="checkbox"> Acepto que Triciclub me escriba sobre mis viajes y ofertas. (Puedes no marcarlo.)</label>'
				+ '<button type="button" class="tc-btn" data-act="entrar"' + (S.busy ? ' disabled' : '') + '>Entrar</button>'
				+ '<button type="button" class="tc-btn tc-btn--link" data-act="otro-tel">Cambiar el número o pedir otro código</button>';
		}
		return h + '<p class="tc-small">¿Problemas? Llama a Triciclub: <a href="tel:+' + esc(C.telefono) + '">+' + esc(C.telefono) + '</a></p></main></div>';
	}

	function barraArriba(atras) {
		return '<div class="tc-arriba">' + (atras ? '<button type="button" class="tc-redondo" data-act="' + atras + '" aria-label="Volver">' + svg(I.atras) + '</button>' : '<a class="tc-redondo" href="#historial" aria-label="Mis viajes">' + svg(I.menu) + '</a>')
			+ '<img class="tc-arriba__logo" src="' + esc(C.logo) + '" alt="Triciclub" width="122" height="24"><span style="width:44px"></span></div>';
	}

	function screenMapa() {
		var o = S.origen, rec = store.json('recientes', []), casa = guardado('casa'), trab = guardado('trabajo');
		var h = barraArriba('') + sinRed()
			+ '<button type="button" class="tc-redondo tc-centrar" data-act="centrar" aria-label="Centrar en mi ubicación">' + svg(I.gps, 22, '#087a3d') + '</button>'
			+ '<section class="tc-hoja" aria-label="Pedir un triciclo"><div class="tc-asa"></div>';
		h += '<button type="button" class="tc-origen-mini" data-act="cambiar-origen">' + (o ? lugarLinea(o) + '<span class="tc-small">Cambiar</span>' : '<span class="tc-ruta__punto"></span><span>' + (S.gpsError ? 'No vemos tu ubicación. <b>Toca para marcar dónde te recogemos</b>' : 'Buscando tu ubicación…') + '</span>') + '</button>';
		h += '<button type="button" class="tc-buscar" data-act="buscar">' + svg(I.pin, 20, '#E8590C') + '<span>¿A dónde vamos?</span></button>';
		h += '<div class="tc-atajos"><button type="button" class="tc-atajo" data-act="ir-casa">' + svg(I.casa, 20, '#087a3d') + '<span>' + (casa ? 'Casa' : 'Poner casa') + '</span></button>'
			+ '<button type="button" class="tc-atajo" data-act="ir-trabajo">' + svg(I.trabajo, 20, '#087a3d') + '<span>' + (trab ? 'Trabajo' : 'Poner trabajo') + '</span></button></div>';
		if (rec.length) {
			h += '<div class="tc-chips" aria-label="Recientes">';
			rec.forEach(function (r, i) { h += '<button type="button" class="tc-chip" data-act="reciente" data-id="' + i + '">' + svg(I.reloj, 16, '#4A5D52') + esc(corto(r.texto)) + '</button>'; });
			h += '</div>';
		}
		return h + '</section>';
	}

	function screenBuscar() {
		var o = S.origen, q = S.texto.trim(), h = '<section class="tc-hoja tc-hoja--alta" aria-label="Buscar">';
		h += '<div class="tc-buscar-cab"><button type="button" class="tc-redondo" data-act="volver-mapa" aria-label="Volver">' + svg(I.atras) + '</button><span class="tc-strong">' + (S.campo === 'origen' ? '¿Dónde te recogemos?' : '¿A dónde vamos?') + '</span></div>';
		if (S.campo === 'destino') { h += '<button type="button" class="tc-origen-mini" data-act="cambiar-origen">' + (o ? lugarLinea(o) : '<span class="tc-ruta__punto"></span><span>Marca dónde te recogemos</span>') + '<span class="tc-small">Origen</span></button>'; }
		h += '<label class="tc-q"><span class="tc-ruta__punto' + (S.campo === 'destino' ? ' tc-ruta__punto--dest' : '') + '"></span><input id="tc-q" data-campo="q" value="' + esc(S.texto) + '" placeholder="' + (S.campo === 'destino' ? 'Calle, esquina o barrio' : 'Dónde estás') + '" autocomplete="off" enterkeyhint="search"></label>';
		h += '<div class="tc-lista">';
		(S.sugerencias || []).forEach(function (s, i) {
			h += '<button type="button" class="tc-item" data-act="sug" data-id="' + i + '">' + svg(s.tipo === 'direccion' ? I.pin : I.calle, 20, '#4A5D52') + '<span><b>' + esc(s.texto) + '</b><small>' + esc(s.tipo === 'calle' ? (s.sub ? s.sub + ' · ' : '') + 'pon el punto en el mapa' : s.sub) + '</small></span></button>';
		});
		var aviso = q.length < 2 || (S.sugerencias || []).length ? '' : (S.sugEstado === 'buscando' ? 'Buscando…' : (S.sugEstado === 'sinred' ? 'Sin conexión ahora. Ponla en el mapa: 👇' : (S.sugEstado === 'listo' ? 'No la encontramos escrita. Ponla en el mapa: 👇' : '')));
		h += '<p class="tc-small" id="tc-sug-estado" role="status" style="padding:8px 4px;margin:0">' + esc(aviso) + '</p>';
		h += '<button type="button" class="tc-item tc-item--fijar" data-act="fijar">' + svg(I.pin, 20, '#087a3d') + '<span><b>Fijar ubicación en el mapa</b></span></button>';
		if (S.campo === 'destino' && !q) {
			var casa = guardado('casa'), trab = guardado('trabajo');
			h += '<h2 class="tc-h2">Mis destinos</h2>'
				+ (casa ? '<button type="button" class="tc-item" data-act="ir-casa">' + svg(I.casa, 20, '#4A5D52') + '<span><b>Casa</b><small>' + esc(casa.texto) + '</small></span></button>' : '')
				+ (trab ? '<button type="button" class="tc-item" data-act="ir-trabajo">' + svg(I.trabajo, 20, '#4A5D52') + '<span><b>Trabajo</b><small>' + esc(trab.texto) + '</small></span></button>' : '');
			var rec = store.json('recientes', []);
			if (rec.length) {
				h += '<h2 class="tc-h2">Recientes</h2>';
				rec.forEach(function (r, i) { h += '<button type="button" class="tc-item" data-act="reciente" data-id="' + i + '">' + svg(I.reloj, 20, '#4A5D52') + '<span><b>' + esc(corto(r.texto)) + '</b><small>' + esc(r.sub || '') + '</small></span></button>'; });
			}
			if (!casa && !trab && !rec.length) { h += '<p class="tc-small">Aquí saldrán tus destinos. Para guardar Casa o Trabajo, ponlos en el mapa la primera vez.</p>'; }
		}
		return h + '</div></section>';
	}

	function screenFijar() {
		var titulo = S.fijar === 'origen' ? (S.confirmar ? 'Confirma el punto de recogida' : 'Marca dónde te recogemos') : (S.guardarComo ? 'Marca tu ' + S.guardarComo : 'Marca a dónde vas');
		return barraArriba('volver-fijar')
			+ '<div class="tc-pin-fijo" aria-hidden="true">' + (S.fijar === 'origen' ? '<svg width="40" height="52" viewBox="0 0 34 44"><path d="M17 42S3 27 3 16a14 14 0 1 1 28 0c0 11-14 26-14 26z" fill="#087a3d" stroke="#fff" stroke-width="2.5"/><circle cx="17" cy="16" r="5.5" fill="#fff"/></svg>' : '<svg width="40" height="52" viewBox="0 0 34 44"><path d="M17 42S3 27 3 16a14 14 0 1 1 28 0c0 11-14 26-14 26z" fill="#E8590C" stroke="#fff" stroke-width="2.5"/><rect x="11.5" y="10.5" width="11" height="11" rx="1.5" fill="#fff"/></svg>') + '</div>'
			+ '<button type="button" class="tc-redondo tc-centrar" data-act="centrar" aria-label="Ir a mi ubicación">' + svg(I.gps, 22, '#087a3d') + '</button>'
			+ '<section class="tc-hoja"><div class="tc-asa"></div><span class="tc-small">' + esc(titulo) + '</span>'
			+ '<p class="tc-fijar-txt" id="tc-fijar-txt" role="status">' + esc(S.fijarTexto) + '</p>'
			+ '<p class="tc-small">Mueve el mapa con el dedo: el punto se queda en el centro.</p>'
			+ '<button type="button" class="tc-btn" data-act="confirmar-fijar">' + (S.fijar === 'origen' ? (S.confirmar ? 'Confirma el punto de recogida' : 'Recoger aquí') : (S.guardarComo ? 'Guardar' : 'Ir aquí')) + '</button></section>';
	}

	function tarjetaTri(t, i, sel) {
		return '<button type="button" class="tc-opcion' + (sel ? ' tc-opcion--sel' : '') + '" data-act="elegir-tri" data-id="' + i + '" aria-pressed="' + sel + '">'
			+ '<span class="tc-avatar" aria-hidden="true">' + esc(ini(t.nombre)) + '</span>'
			+ '<span class="tc-opcion__q"><b>' + esc(t.nombre) + (t.nota ? ' · ★ ' + esc(String(t.nota).replace('.', ',')) : '') + '</b><small>' + esc([t.numero ? 'Nº ' + t.numero : '', t.descripcion, 'llega en ' + t.llega + ' min'].filter(Boolean).join(' · ')) + (t.lot_ok ? ' · LOT ✓' : '') + '</small></span>'
			+ '<span class="tc-opcion__p">' + esc(cup(t.precio)) + (t.extra ? '<small>+' + t.extra + ' %</small>' : '') + '</span></button>';
	}

	function screenElegir() {
		var c = S.cerca, h = barraArriba('volver-mapa') + sinRed() + '<section class="tc-hoja" aria-label="Elegir triciclo"><div class="tc-asa"></div>';
		h += '<div class="tc-dos">' + lugarLinea(S.origen) + lugarLinea(S.destino, ' tc-ruta__punto--dest') + '</div>';
		h += '<div class="tc-carrusel" role="radiogroup" aria-label="Servicio">';
		SERV.forEach(function (s) { h += '<button type="button" role="radio" class="tc-cat" data-act="servicio" data-id="' + s.id + '" aria-checked="' + (S.servicio === s.id) + '">' + svg(s.icon, 26, S.servicio === s.id ? '#fff' : '#087a3d') + '<b>' + s.nombre + '</b><small>' + s.sub + '</small></button>'; });
		h += '</div>';
		if (!c) { return h + '<p class="tc-muted" role="status">Buscando triciclos libres cerca…</p></section>'; }
		var l = c.triciclos || [];
		if (!l.length) {
			return h + '<p class="tc-strong">' + esc(c.error || 'No hay triciclos libres cerca ahora.') + '</p><p class="tc-small">Prueba en unos minutos o llama a Triciclub.</p>'
				+ '<div class="tc-dos-btn"><button type="button" class="tc-btn tc-btn--sec" data-act="otra-vez">Buscar otra vez</button><a class="tc-btn" href="tel:+' + esc(C.telefono) + '">Llamar</a></div></section>';
		}
		var t = l[S.elegido] || l[0];
		h += '<div class="tc-row tc-small"><span>' + esc(String(c.km).replace('.', ',')) + ' km · ' + esc(c.franja.nombre) + ' · precio por el triciclo entero</span>'
			+ (l.length > 1 ? '<span class="tc-orden"><button type="button" data-act="orden" data-id="barato" aria-pressed="' + (S.orden === 'barato') + '">Más barato</button><button type="button" data-act="orden" data-id="cerca" aria-pressed="' + (S.orden === 'cerca') + '">Más cerca</button></span>' : '') + '</div>';
		if (S.verTodos) { l.forEach(function (x, i) { h += tarjetaTri(x, i, i === S.elegido); }); }
		else { h += tarjetaTri(t, S.elegido, true) + (l.length > 1 ? '<button type="button" class="tc-btn tc-btn--link tc-btn--verde" data-act="ver-todos">Ver los ' + l.length + ' triciclos libres</button>' : ''); }
		if (S.servicio === 'paquete') {
			h += '<div class="tc-dos-campos"><input class="tc-input" data-campo="recibe.nombre" value="' + esc(S.recibe.nombre) + '" placeholder="Quién recibe" aria-label="Quién recibe el paquete" maxlength="80">'
				+ '<input class="tc-input" type="tel" inputmode="tel" data-campo="recibe.telefono" value="' + esc(S.recibe.telefono) + '" placeholder="Su teléfono" aria-label="Teléfono de quien recibe"></div>';
		}
		h += '<div class="tc-tres"><span>' + svg(I.candado, 18, '#087a3d') + '<b>' + esc(cup(t.precio)) + '</b><small>Precio fijo</small></span>'
			+ '<button type="button" data-act="pago">' + svg('<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>', 18, '#087a3d') + '<b>' + (S.pago === 'efectivo' ? 'Efectivo' : 'Transferencia') + '</b><small>Toca para cambiar</small></button>'
			+ '<span>' + svg(I.reloj, 18, '#087a3d') + '<b>' + t.llega + ' min</b><small>Llegada</small></span></div>';
		h += S.notaAbierta ? '<input class="tc-input" data-campo="nota" value="' + esc(S.nota) + '" maxlength="200" placeholder="Nota para el chofer (ej.: voy con dos maletas)" aria-label="Nota para el chofer">' : '<button type="button" class="tc-btn tc-btn--link tc-btn--verde" data-act="nota">+ Nota para el chofer</button>';
		return h + '<button type="button" class="tc-btn" data-act="pedir"' + (S.busy ? ' disabled' : '') + '>Pedir triciclo · ' + esc(cup(t.precio)) + '</button></section>';
	}

	function chofer(v) {
		var ch = v.chofer || {};
		return '<div class="tc-tri__cab"><span class="tc-avatar" aria-hidden="true">' + esc(ini(ch.nombre)) + '</span>'
			+ '<span class="tc-opcion__q"><b>' + esc(ch.nombre || 'Tu chofer') + (ch.nota ? ' · ★ ' + esc(String(ch.nota).replace('.', ',')) : '') + '</b><small>' + esc([ch.numero ? 'Nº ' + ch.numero : '', ch.descripcion].filter(Boolean).join(' · ')) + '</small></span>'
			+ (ch.lot_ok ? '<span class="tc-badge">' + svg(I.escudo, 14, '#05592C') + 'LOT</span>' : '') + '</div>';
	}

	function screenViaje() {
		var v = S.viaje;
		if (!v) { return screenMapa(); }
		if (v.estado === 'terminado') { return screenLlego(v); }
		var h = barraArriba('') + sinRed() + '<section class="tc-hoja" aria-label="Tu viaje"><div class="tc-asa"></div>';
		if (v.estado === 'esperando') {
			var falta = Math.max(0, Math.round((new Date(v.espera_hasta).getTime() - Date.now()) / 1000));
			return h + '<h1 class="tc-h2" style="font-size:20px">Esperando que ' + esc((v.chofer && v.chofer.nombre || 'el chofer').split(' ')[0]) + ' acepte</h1>'
				+ '<div class="tc-espera" aria-hidden="true"><div style="width:' + Math.round(100 * falta / 90) + '%"></div></div><p class="tc-small">Tiene ' + falta + ' segundos. Si no puede, eliges otro.</p>'
				+ chofer(v) + '<div class="tc-row"><span class="tc-muted">' + esc(serv(v.servicio).nombre) + ' · ' + (v.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="tc-strong">' + esc(cup(v.precio)) + ' fijo</span></div>'
				+ '<button type="button" class="tc-btn tc-btn--link" data-act="cancelar">Cancelar</button></section>';
		}
		if (v.estado === 'rechazado' || v.estado === 'cancelado') {
			return h + '<h1 class="tc-h2" style="font-size:20px">' + (v.estado === 'rechazado' ? esc((v.chofer && v.chofer.nombre || 'El chofer').split(' ')[0]) + ' no puede ahora' : 'Viaje cancelado') + '</h1>'
				+ '<p class="tc-muted">' + (v.estado === 'rechazado' ? 'No pasa nada: elige otro triciclo.' : (v.motivo ? esc(v.motivo) : 'No se cobra nada.')) + '</p>'
				+ (v.estado === 'rechazado' ? '<button type="button" class="tc-btn" data-act="otro">Elegir otro triciclo</button>' : '') + '<button type="button" class="tc-btn tc-btn--sec" data-act="nuevo">Volver al inicio</button></section>';
		}
		var titulo = { asignado: 'Tu triciclo viene', llego: '¡Ya llegó! Sal a buscarlo', recogido: 'En camino a ' + corto(v.destino.texto) }[v.estado] || 'Tu viaje';
		var tel = v.chofer && v.chofer.telefono;
		h += '<h1 class="tc-h2" style="font-size:20px">' + esc(titulo) + '</h1>' + chofer(v);
		if (v.estado !== 'recogido') { h += '<div class="tc-codigo" aria-label="Código de seguridad"><span>Antes de subir, el chofer te dice este código</span><b>' + esc(v.codigo) + '</b></div>'; }
		h += '<div class="tc-acciones">' + (tel ? '<a class="tc-accion" href="tel:+' + esc(tel) + '">' + svg(I.tel, 20, '#087a3d') + 'Llamar</a><a class="tc-accion" href="https://wa.me/' + esc(tel) + '" target="_blank" rel="noopener">' + svg(I.wa, 20, '#087a3d') + 'WhatsApp</a>' : '')
			+ '<button type="button" class="tc-accion" data-act="compartir">' + svg(I.compartir, 20, '#087a3d') + 'Compartir</button></div>';
		h += '<div class="tc-row"><span class="tc-muted">' + esc(serv(v.servicio).nombre) + ' · ' + (v.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="tc-strong">' + esc(cup(v.precio)) + ' fijo</span></div>';
		if (v.estado !== 'recogido') { h += '<button type="button" class="tc-btn tc-btn--link" data-act="cancelar">Cancelar</button>'; }
		return h + '</section>';
	}

	function screenLlego(v) {
		var ch = v.chofer || {};
		var h = '<div class="tc-pagina"><main class="tc-main" style="padding-top:0"><div class="tc-hecho">' + svg(I.bien, 40, '#9FE3BC') + '<h1 class="tc-h1">¡Llegaste!</h1><p>' + esc(v.destino.texto) + '</p></div>'
			+ '<section class="tc-card"><div class="tc-row"><span class="tc-strong">Págale a ' + esc((ch.nombre || 'tu chofer').split(' ')[0]) + '</span><span class="tc-precio">' + esc(cup(v.precio)) + '</span></div>'
			+ '<span class="tc-small" style="color:#05592C;font-weight:700">✓ El mismo precio que viste al pedir · ' + (v.forma_pago === 'efectivo' ? 'en efectivo' : 'por transferencia' + (ch.transferencia ? ' a ' + esc(ch.transferencia) : '')) + '</span></section>';
		if (v.valorado) { return h + '<button type="button" class="tc-btn" data-act="nuevo">Listo</button></main></div>'; }
		h += '<h2 class="tc-h2">¿Qué tal el viaje?</h2><div class="tc-estrellas" role="radiogroup" aria-label="Nota del viaje">';
		for (var n = 1; n <= 5; n++) { h += '<button type="button" role="radio" aria-checked="' + (n === S.estrellas) + '" aria-label="' + n + ' de 5" data-act="estrella" data-id="' + n + '"><svg width="38" height="38" viewBox="0 0 24 24" stroke="#E8590C" stroke-width="1.6" stroke-linejoin="round" fill="' + (n <= S.estrellas ? '#F2A516' : 'none') + '" aria-hidden="true">' + I.estrella + '</svg></button>'; }
		h += '</div><div class="tc-tags">';
		TAGS.forEach(function (t) { h += '<button type="button" data-act="tag" data-id="' + t[0] + '" aria-pressed="' + !!S.tags[t[0]] + '">' + t[1] + '</button>'; });
		return h + '</div><button type="button" class="tc-btn" data-act="valorar">Listo</button></main></div>';
	}

	function screenSinRed() {
		var p = S.pendiente || store.json('cola', null) || {}, r = p._r || {};
		return '<div class="tc-pagina">' + sinRed() + '<main class="tc-main"><h1 class="tc-h1">Tranquilo: tu pedido está guardado.</h1><p class="tc-muted">Se manda solo en cuanto vuelva la señal.</p>'
			+ '<div class="tc-card"><div class="tc-row"><span class="tc-strong">' + esc(serv(r.servicio).nombre) + ' con ' + esc(r.nombre || '') + '</span><span class="tc-badge tc-badge--naranja">ESPERANDO SEÑAL</span></div>'
			+ '<span class="tc-small">' + esc(r.origen || '') + ' → ' + esc(r.destino || '') + '</span><span class="tc-strong">' + esc(cup(r.precio)) + '</span></div>'
			+ '<a class="tc-btn" href="tel:+' + esc(C.telefono) + '">Llamar a Triciclub</a><button type="button" class="tc-btn tc-btn--sec" data-act="reintentar">Probar ahora</button><button type="button" class="tc-btn tc-btn--link" data-act="olvidar">Olvidar este pedido</button></main></div>';
	}

	function screenHistorial() {
		var h = '<div class="tc-pagina"><header class="tc-top"><a class="tc-redondo" href="#inicio" aria-label="Volver">' + svg(I.atras) + '</a><span class="tc-strong">Mis viajes</span><span style="width:44px"></span></header><main class="tc-main">';
		if (!S.historial) { return h + '<p class="tc-muted">Cargando…</p></main></div>'; }
		if (!S.historial.length) { h += '<p class="tc-muted">Todavía no has hecho viajes.</p>'; }
		var nombres = { esperando: 'Esperando', asignado: 'En camino', llego: 'Llegó', recogido: 'En viaje', terminado: 'Terminado', cancelado: 'Cancelado', rechazado: 'No aceptado' };
		S.historial.forEach(function (v) {
			h += '<a class="tc-card" style="text-decoration:none;color:inherit" href="#viaje/' + esc(v.uuid) + '"><div class="tc-row"><span class="tc-strong">' + esc(serv(v.servicio).nombre) + ' · ' + esc(cup(v.precio)) + '</span><span class="tc-badge' + (v.estado === 'terminado' ? '' : ' tc-badge--naranja') + '">' + esc(nombres[v.estado] || v.estado) + '</span></div>'
				+ '<span class="tc-small">' + esc(corto(v.origen.texto)) + ' → ' + esc(corto(v.destino.texto)) + '</span></a>';
		});
		var casa = guardado('casa'), trab = guardado('trabajo');
		h += '<h2 class="tc-h2">Mis lugares</h2><button type="button" class="tc-item" data-act="poner-casa">' + svg(I.casa, 20, '#4A5D52') + '<span><b>Casa</b><small>' + esc(casa ? casa.texto : 'Sin poner: toca para marcarla') + '</small></span></button>'
			+ '<button type="button" class="tc-item" data-act="poner-trabajo">' + svg(I.trabajo, 20, '#4A5D52') + '<span><b>Trabajo</b><small>' + esc(trab ? trab.texto : 'Sin poner: toca para marcarlo') + '</small></span></button>';
		return h + '<button type="button" class="tc-btn tc-btn--link" data-act="salir">Salir de este teléfono</button></main></div>';
	}

	function cargando() {
		return '<main class="tc-carga" role="status"><div class="tc-carga__logo"><img src="' + esc(C.logo) + '" alt="Triciclub" width="220" height="43"></div><div class="tc-carga__via" aria-hidden="true"><svg class="tc-carga__tri" width="60" height="46" viewBox="0 0 60 46" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h34v18H6z"/><path d="M6 24h48l-4 8H8z"/><path d="M44 6l10 18"/><circle cx="14" cy="36" r="6"/><circle cx="46" cy="36" r="6"/></svg></div><p class="tc-carga__txt">Buscando triciclos cerca de ti</p></main>';
	}

	var conFondo = { mapa: 1, buscar: 1, fijar: 1, elegir: 1, viaje: 1 };
	function render() {
		var v = S.view, html;
		if (v === 'cargando') { html = cargando(); }
		else if (v === 'cerrado') { html = '<main class="tc-cerrado"><img src="' + esc(C.logo) + '" alt="Triciclub" width="220" height="43"><h1 class="tc-h1">Muy pronto</h1><p class="tc-muted">' + esc(S.cerrado) + '</p></main>'; }
		else if (v === 'entrar') { html = screenEntrar(); }
		else if (v === 'sinred') { html = screenSinRed(); }
		else if (v === 'historial') { html = screenHistorial(); }
		else if (v === 'buscar') { html = screenBuscar(); }
		else if (v === 'fijar' || v === 'confirmar') { html = screenFijar(); }
		else if (v === 'elegir') { html = screenElegir(); }
		else if (v === 'viaje') { html = screenViaje(); }
		else { html = screenMapa(); }
		var fondo = !!conFondo[v] && !(v === 'viaje' && S.viaje && S.viaje.estado === 'terminado');
		var antes = document.body.classList.contains('tc-con-mapa');
		document.body.classList.toggle('tc-con-mapa', fondo);
		if (fondo && !antes && mapa) { setTimeout(function () { mapa.resize(); }, 30); } // el mapa estaba escondido: que se vuelva a medir
		var activo = document.activeElement, campo = activo && activo.getAttribute && (activo.getAttribute('data-campo') || activo.id), pos = campo && activo.selectionStart;
		var escrito = {};
		Array.prototype.forEach.call(app.querySelectorAll('input[id]'), function (i) { escrito[i.id] = i.type === 'checkbox' ? i.checked : i.value; });
		app.innerHTML = html + (S.toast ? '<div class="tc-toast" role="status">' + esc(S.toast) + '</div>' : '');
		Object.keys(escrito).forEach(function (id) { var i = document.getElementById(id); if (i && id !== 'tc-q') { if (i.type === 'checkbox') { i.checked = escrito[id]; } else if (escrito[id]) { i.value = escrito[id]; } } });
		if (campo) { var el = app.querySelector('[data-campo="' + campo + '"]') || document.getElementById(campo); if (el && el.focus) { el.focus(); try { el.setSelectionRange(pos, pos); } catch (e) {} } }
		if (fondo) { pintarMapa(); }
	}

	// ------------------------------------------------------------ Eventos
	app.addEventListener('input', function (e) {
		var t = e.target, c = t.getAttribute('data-campo');
		if (c === 'q') { S.texto = t.value; sugerir(); }
		if (c === 'nota') { S.nota = t.value; }
		if (c === 'recibe.nombre') { S.recibe.nombre = t.value; }
		if (c === 'recibe.telefono') { S.recibe.telefono = t.value; }
	});
	app.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.id === 'tc-q' && S.sugerencias.length) { elegirLugar(S.sugerencias[0]); } });
	app.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var a = el.getAttribute('data-act'), id = el.getAttribute('data-id');
		var lugarGuardado = function (tipo) { var g = guardado(tipo); if (g) { S.campo = 'destino'; ponerLugar('destino', g); } else { S.guardarComo = tipo; abrirFijar('destino', p2(S.origen) || S.gps || HABANA); } };
		if (a === 'codigo') { pedirCodigo(); }
		else if (a === 'entrar') { entrar(); }
		else if (a === 'otro-tel') { S.paso = 'telefono'; render(); }
		else if (a === 'buscar') { abrirBuscar('destino'); }
		else if (a === 'cambiar-origen') { if (S.gpsError && !S.origen) { abrirFijar('origen', S.gps || HABANA); } else { abrirBuscar('origen'); } }
		else if (a === 'centrar') { if (S.gps) { conMapa(function (m) { window.TcMapa.ir(m, S.gps, 17); }); } else { toast('No vemos tu ubicación: activa el GPS del teléfono.'); } }
		else if (a === 'volver-mapa') { S.view = 'mapa'; S.guardarComo = ''; conMapa(function (m) { m._tcVista = ''; }); render(); }
		else if (a === 'volver-fijar') { S.view = S.destino && S.fijar === 'origen' ? 'buscar' : 'mapa'; S.guardarComo = ''; if (S.view === 'buscar') { S.campo = 'destino'; } conMapa(function (m) { m._tcVista = ''; }); render(); }
		else if (a === 'sug') { elegirLugar(S.sugerencias[+id]); }
		else if (a === 'fijar') { abrirFijar(S.campo, p2(S[S.campo]) || p2(S.origen) || S.gps || HABANA); }
		else if (a === 'confirmar-fijar') {
			if (S.guardarComo) { var c = mapa ? window.TcMapa.centro(mapa) : S.fijarPunto; var g = { texto: String(S.fijarTexto).split(', ')[0], sub: String(S.fijarTexto).split(', ').slice(1).join(', '), lat: c[0], lng: c[1] }; store.set(S.guardarComo, JSON.stringify(g)); toast((S.guardarComo === 'casa' ? 'Casa' : 'Trabajo') + ' guardado.'); S.guardarComo = ''; S.view = 'mapa'; render(); return; }
			confirmarFijar();
		}
		else if (a === 'reciente') { var r = store.json('recientes', [])[+id]; if (r) { if (S.view === 'buscar' && S.campo === 'origen') { ponerLugar('origen', r); } else { ponerLugar('destino', r); } } }
		else if (a === 'ir-casa') { lugarGuardado('casa'); }
		else if (a === 'ir-trabajo') { lugarGuardado('trabajo'); }
		else if (a === 'poner-casa' || a === 'poner-trabajo') { S.guardarComo = a === 'poner-casa' ? 'casa' : 'trabajo'; location.hash = '#inicio'; abrirFijar('destino', p2(S.origen) || S.gps || HABANA); }
		else if (a === 'servicio') { S.servicio = id; verTriciclos(); }
		else if (a === 'orden') { S.orden = id; ordenar(); render(); }
		else if (a === 'elegir-tri') { S.elegido = +id; S.verTodos = false; render(); }
		else if (a === 'ver-todos') { S.verTodos = true; render(); }
		else if (a === 'pago') { S.pago = S.pago === 'efectivo' ? 'transferencia' : 'efectivo'; render(); }
		else if (a === 'nota') { S.notaAbierta = true; render(); var n = app.querySelector('[data-campo="nota"]'); if (n) { n.focus(); } }
		else if (a === 'otra-vez') { verTriciclos(); }
		else if (a === 'pedir') { pedir(); }
		else if (a === 'cancelar') { cancelar(); }
		else if (a === 'compartir') { compartir(); }
		else if (a === 'otro') { S.viaje = null; location.hash = '#inicio'; verTriciclos(); }
		else if (a === 'nuevo') { inicio(); }
		else if (a === 'estrella') { S.estrellas = +id; render(); }
		else if (a === 'tag') { S.tags[id] = !S.tags[id]; render(); }
		else if (a === 'valorar') { valorar(); }
		else if (a === 'reintentar') { var q = store.json('cola', null); if (q) { enviar(q); } }
		else if (a === 'olvidar') { store.set('cola', null); S.pendiente = null; inicio(); }
		else if (a === 'salir') { if (window.confirm('¿Salir de Triciclub en este teléfono?')) { store.set('k', null); S.token = ''; S.view = 'entrar'; S.paso = 'telefono'; render(); } }
	});
	window.addEventListener('hashchange', ruta);
	window.addEventListener('online', function () { S.offline = false; var p = store.json('cola', null); if (p) { enviar(p); } else { render(); } });
	window.addEventListener('offline', function () { S.offline = true; render(); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden && S.viaje) { seguir(); } });
	T.tick = setInterval(function () { if (S.view === 'viaje' && S.viaje && S.viaje.estado === 'esperando') { render(); } }, 1000);

	function ruta() {
		if (!S.token) { return; }
		var h = location.hash || '', m = /^#viaje\/([a-f0-9-]{36})$/.exec(h);
		if (m && (!S.viaje || S.viaje.uuid !== m[1])) { api('viajes/' + m[1]).then(function (res) { if (salir(res)) { return; } if (res.data.ok) { abrirViaje(res.data.viaje); } }); return; }
		if (h === '#historial') { S.view = 'historial'; S.historial = null; render(); api('viajes').then(function (res) { if (salir(res)) { return; } S.historial = res.data.viajes || []; render(); }); return; }
		if ((h === '#inicio' || h === '') && S.view === 'historial') { S.view = 'mapa'; render(); }
	}
	function cargarYo() {
		return api('yo').then(function (res) {
			if (salir(res)) { return; }
			if (res.status === 0) { S.view = store.json('cola', null) ? 'sinred' : 'mapa'; render(); return; }
			S.yo = res.data.yo;
			if (res.data.activo) { abrirViaje(res.data.activo); return; }
			var p = store.json('cola', null);
			if (p) { enviar(p); return; }
			S.view = 'mapa'; render(); ruta();
		});
	}

	// ------------------------------------------------------------ Arranque
	var mk = /(?:^#|&)k=([0-9a-f.]+)/i.exec(location.hash || '');
	if (mk) { store.set('k', mk[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname + location.search + '#inicio'); } }
	S.token = store.get('k') || '';
	mapaEl = document.createElement('div'); mapaEl.id = 'tc-mapa-fondo'; mapaEl.setAttribute('aria-label', 'Mapa'); document.body.insertBefore(mapaEl, app);
	if ('serviceWorker' in navigator && C.sw && location.protocol === 'https:') { navigator.serviceWorker.register(C.sw, { scope: C.scope || './' }).catch(function () {}); }
	var s = document.createElement('script'); s.src = C.mapaUrl; s.onload = function () { if (S.view !== 'cargando' && conFondo[S.view]) { pintarMapa(); } }; document.head.appendChild(s);
	render();
	if (!S.token) { S.view = 'entrar'; render(); } else { cargarYo().then(function () { gps(); libres(); }); }
})();
