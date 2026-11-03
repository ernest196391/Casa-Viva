/* Triciclub · app del cliente (Core 3.14.117, Ernesto 3-nov, D129–D130).
 * Entrar con el teléfono (código por WhatsApp) → elegir Viaje / Paquete / Carga, dónde recoger y a dónde llevar →
 * ver los triciclos libres cerca, cada uno con SU precio para la hora de ahora (lo pone cada chofer) → elegir uno →
 * esperar que acepte (90 s) → seguirlo con el código de seguridad → pagar el precio que vio y valorar.
 * Sin señal: el pedido se guarda en el teléfono y sale solo al volver (mismo `cid`: nunca se pide dos veces).
 * ES5 a propósito (teléfonos viejos), un solo oyente de clics, todo el HTML escapado con esc(). */
(function () {
	'use strict';
	var C = window.TRICICLUB || {};
	var app = document.getElementById('app');
	if (!app) { return; }
	var API = C.api || '/wp-json/casa-viva/v1/triciclub/';
	var PFX = 'tc.';
	var SERVICIOS = [
		{ id: 'viaje', nombre: 'Viaje', sub: 'Para ti y tu familia', icon: '<circle cx="9" cy="5" r="2"/><path d="M7 21l2-7 3 3v5M9 14l-1-5 5 2 3 3"/>' },
		{ id: 'paquete', nombre: 'Paquete', sub: 'Mandados y envíos', icon: '<path d="M3 8l9-5 9 5v8l-9 5-9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>' },
		{ id: 'carga', nombre: 'Carga', sub: 'Mudanzas y cosas grandes', icon: '<rect x="3" y="9" width="12" height="8" rx="1"/><path d="M15 12h3l3 3v2h-6"/><circle cx="7" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>' }
	];
	var TAGS = [['puntual', 'Puntual'], ['amable', 'Amable'], ['limpio', 'Triciclo limpio'], ['cuidado', 'Cuidó mis cosas']];

	var state = {
		token: '', yo: null, view: 'cargando', paso: 'telefono', telefono: '', toast: '', busy: false, offline: !navigator.onLine,
		servicio: 'viaje', origen: { texto: '', lat: null, lng: null, err: '' }, destino: { texto: '', lat: null, lng: null, err: '' },
		cerca: null, pago: 'efectivo', nota: '', recibe: { nombre: '', telefono: '' },
		viaje: null, estrellas: 5, tags: { puntual: true }, historial: null, pendiente: null
	};
	var timers = { poll: 0, toast: 0, cola: 0, tick: 0 };

	// ------------------------------------------------------------ Utilidades
	var store = {
		get: function (k) { try { return window.localStorage.getItem(PFX + k); } catch (e) { return null; } },
		set: function (k, v) { try { if (v === null) { window.localStorage.removeItem(PFX + k); } else { window.localStorage.setItem(PFX + k, v); } } catch (e) {} },
		json: function (k, d) { try { var v = JSON.parse(store.get(k) || 'null'); return v === null ? d : v; } catch (e) { return d; } }
	};
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function cup(n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
	function newCid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
	function svg(path, size, color) { return '<svg width="' + (size || 22) + '" height="' + (size || 22) + '" viewBox="0 0 24 24" fill="none" stroke="' + (color || 'currentColor') + '" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>'; }
	var I = {
		cuenta: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		candado: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		gps: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
		tel: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		compartir: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
		escudo: '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
		atras: '<path d="M15 18l-6-6 6-6"/>',
		inicio: '<path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>',
		lista: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		senal: '<path d="M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0M12 19h.01M3 3l18 18"/>',
		bien: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
		estrella: '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/>'
	};
	function toast(t) { state.toast = t; render(); clearTimeout(timers.toast); timers.toast = setTimeout(function () { state.toast = ''; render(); }, 3500); }
	function servicioDe(id) { for (var i = 0; i < SERVICIOS.length; i++) { if (SERVICIOS[i].id === id) { return SERVICIOS[i]; } } return SERVICIOS[0]; }
	function iniciales(n) { var p = String(n || '').split(/\s+/); return ((p[0] || '?').charAt(0) + (p[1] || '').charAt(0)).toUpperCase(); }

	// ------------------------------------------------------------ Red
	function api(path, body) {
		var headers = { 'Accept': 'application/json' };
		if (state.token) { headers['X-TC-Token'] = state.token; }
		if (body) { headers['Content-Type'] = 'application/json'; }
		return fetch(API + path, { method: body ? 'POST' : 'GET', headers: headers, body: body ? JSON.stringify(body) : undefined, cache: 'no-store', credentials: 'omit' })
			.then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { state.offline = false; return { status: r.status, data: d || {} }; }); })
			.catch(function () { state.offline = true; return { status: 0, data: { message: 'Sin conexión.' } }; });
	}
	function salir(res) {
		if (res.status === 401) { store.set('k', null); state.token = ''; state.view = 'entrar'; state.paso = 'telefono'; render(); return true; }
		if (res.status === 403) { state.view = 'cerrado'; state.cerrado = res.data.message || 'No puedes entrar ahora.'; render(); return true; }
		return false;
	}

	// ------------------------------------------------------------ Lugares guardados (solo en este teléfono)
	function recordar(p) {
		if (!p || !p.texto || p.lat === null) { return; }
		var l = store.json('lugares', []).filter(function (x) { return x.texto !== p.texto; });
		l.unshift({ texto: p.texto, lat: p.lat, lng: p.lng });
		store.set('lugares', JSON.stringify(l.slice(0, 5)));
	}

	function buscarLugar(cual) {
		var p = state[cual];
		if (!p.texto || p.texto.length < 3) { p.err = 'Escribe calle y entrecalles, o una esquina.'; render(); return Promise.resolve(false); }
		p.err = ''; p.buscando = true; render();
		return api('lugar?texto=' + encodeURIComponent(p.texto)).then(function (res) {
			p.buscando = false;
			if (salir(res)) { return false; }
			if (res.data.ok) { p.lat = res.data.lat; p.lng = res.data.lng; p.err = ''; render(); return true; }
			p.lat = p.lng = null; p.err = res.data.message || 'No encontramos esa dirección.'; render(); return false;
		});
	}

	function miUbicacion() {
		if (!navigator.geolocation) { toast('Este teléfono no da la ubicación. Escribe la dirección.'); return; }
		state.origen.err = ''; state.origen.buscando = true; render();
		navigator.geolocation.getCurrentPosition(function (pos) {
			state.origen = { texto: 'Donde estoy ahora', lat: pos.coords.latitude, lng: pos.coords.longitude, err: '' };
			render();
		}, function () {
			state.origen.buscando = false; state.origen.err = 'No pudimos ver tu ubicación. Escribe la dirección.'; render();
		}, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
	}

	// ------------------------------------------------------------ Pedido
	function verTriciclos() {
		var pasos = [];
		['origen', 'destino'].forEach(function (k) { if (state[k].lat === null) { pasos.push(k); } });
		var listo = pasos.length ? Promise.all(pasos.map(buscarLugar)) : Promise.resolve([true]);
		return listo.then(function (oks) {
			if (oks.indexOf(false) !== -1 || state.origen.lat === null || state.destino.lat === null) { return; }
			state.busy = true; state.view = 'elegir'; state.cerca = null; render();
			var q = 'cerca?servicio=' + state.servicio + '&olat=' + state.origen.lat + '&olng=' + state.origen.lng + '&dlat=' + state.destino.lat + '&dlng=' + state.destino.lng;
			return api(q).then(function (res) {
				state.busy = false;
				if (salir(res)) { return; }
				if (!res.data.ok) { state.view = 'inicio'; toast(res.data.message || (res.status ? 'No se pudo.' : 'Sin conexión. Prueba otra vez.')); return; }
				state.cerca = res.data; state.cerca.recibido = Date.now();
				recordar(state.origen); recordar(state.destino);
				render();
			});
		});
	}

	function elegir(i) {
		var t = state.cerca && state.cerca.triciclos[i];
		if (!t || state.busy) { return; }
		if (state.servicio === 'paquete' && (!state.recibe.nombre || !state.recibe.telefono)) { toast('Pon quién recibe el paquete y su teléfono.'); return; }
		var pedido = {
			cid: newCid(), firma: t.firma, forma_pago: state.pago, nota: state.nota,
			origen_texto: state.origen.texto, destino_texto: state.destino.texto,
			recibe_nombre: state.recibe.nombre, recibe_telefono: state.recibe.telefono,
			_resumen: { nombre: t.nombre, precio: t.precio, origen: state.origen.texto, destino: state.destino.texto, servicio: state.servicio }
		};
		store.set('cola', JSON.stringify(pedido));
		enviar(pedido);
	}

	function enviar(pedido) {
		state.busy = true; render();
		var body = {}; Object.keys(pedido).forEach(function (k) { if (k.charAt(0) !== '_') { body[k] = pedido[k]; } });
		return api('viajes', body).then(function (res) {
			state.busy = false;
			if (res.status === 0) { state.pendiente = pedido; state.view = 'sinred'; render(); programarCola(); return; }
			store.set('cola', null); state.pendiente = null;
			if (salir(res)) { return; }
			if (!res.data.ok) {
				toast(res.data.message || 'No se pudo pedir.');
				if (res.data.code === 'ya_tienes') { cargarYo(); return; }
				state.view = 'elegir'; verTriciclos(); return;
			}
			abrirViaje(res.data.viaje);
		});
	}

	function programarCola() {
		clearTimeout(timers.cola);
		timers.cola = setTimeout(function () { var p = store.json('cola', null); if (p) { enviar(p); } }, 15000);
	}

	function abrirViaje(v) {
		state.viaje = v; state.view = 'viaje'; state.estrellas = 5; state.tags = { puntual: true };
		location.hash = '#viaje/' + v.uuid;
		render(); seguir();
	}

	function seguir() {
		clearTimeout(timers.poll);
		var v = state.viaje;
		if (!v || ['terminado', 'cancelado', 'rechazado'].indexOf(v.estado) !== -1) { return; }
		var cada = document.hidden ? 30000 : (v.estado === 'esperando' ? 4000 : 10000);
		timers.poll = setTimeout(function () {
			api('viajes/' + v.uuid).then(function (res) {
				if (salir(res)) { return; }
				if (res.data.ok) {
					var antes = state.viaje && state.viaje.estado;
					state.viaje = res.data.viaje;
					if (antes !== state.viaje.estado && navigator.vibrate) { try { navigator.vibrate([200, 80, 200]); } catch (e) {} }
					if (state.view === 'viaje') { render(); }
				}
				seguir();
			});
		}, cada);
	}

	function cancelar() {
		if (!state.viaje || !window.confirm('¿Seguro que quieres cancelar?')) { return; }
		api('viajes/' + state.viaje.uuid + '/cancelar', { motivo: 'el cliente canceló' }).then(function (res) {
			if (salir(res)) { return; }
			if (res.data.ok) { state.viaje = res.data.viaje; render(); } else { toast(res.data.message || 'No se pudo cancelar.'); }
		});
	}

	function valorar() {
		var v = state.viaje;
		var tags = Object.keys(state.tags).filter(function (k) { return state.tags[k]; });
		api('viajes/' + v.uuid + '/valorar', { estrellas: state.estrellas, etiquetas: tags }).then(function () {
			toast('¡Gracias! Te esperamos en el próximo viaje.');
			nuevo();
		});
	}

	function nuevo() {
		state.viaje = null; state.cerca = null; state.view = 'inicio';
		state.destino = { texto: '', lat: null, lng: null, err: '' };
		state.nota = ''; state.recibe = { nombre: '', telefono: '' };
		location.hash = '#inicio'; render();
	}

	function compartir() {
		var v = state.viaje, ch = v && v.chofer;
		var txt = 'Voy en Triciclub con ' + (ch ? ch.nombre + ' (triciclo Nº ' + (ch.numero || '—') + ')' : 'un triciclo') + ' de ' + v.origen.texto + ' a ' + v.destino.texto + '.';
		if (navigator.share) { navigator.share({ title: 'Mi viaje en Triciclub', text: txt }).catch(function () {}); return; }
		window.open('https://wa.me/?text=' + encodeURIComponent(txt), '_blank', 'noopener');
	}

	// ------------------------------------------------------------ Entrar
	function pedirCodigo() {
		var t = (document.getElementById('tc-tel') || {}).value || state.telefono;
		state.telefono = t; state.busy = true; render();
		api('cliente/codigo', { telefono: t }).then(function (res) {
			state.busy = false;
			if (res.data.ok) { state.paso = 'codigo'; render(); toast(res.data.message); return; }
			if (res.status === 403) { salir(res); return; }
			toast(res.data.message || 'No se pudo. Prueba otra vez.'); render();
		});
	}

	function entrar() {
		var codigo = (document.getElementById('tc-cod') || {}).value || '';
		var nombre = (document.getElementById('tc-nom') || {}).value || '';
		var ok = !!(document.getElementById('tc-ok') || {}).checked;
		if (!nombre.trim()) { toast('Pon tu nombre para que el chofer sepa a quién busca.'); return; }
		state.busy = true; render();
		api('cliente/entrar', { telefono: state.telefono, codigo: codigo, nombre: nombre, consentimiento: ok }).then(function (res) {
			state.busy = false;
			if (!res.data.ok) { if (res.status === 403) { salir(res); return; } toast(res.data.message || 'No se pudo entrar.'); render(); return; }
			state.token = res.data.token; store.set('k', state.token); state.yo = res.data.yo;
			state.view = 'inicio'; render();
		});
	}

	// ------------------------------------------------------------ Pantallas
	function top() {
		return '<header class="tc-top"><img src="' + esc(C.logo) + '" alt="Triciclub" width="152" height="30">'
			+ '<a class="tc-icon-btn" href="#historial" aria-label="Mis viajes">' + svg(I.cuenta) + '</a></header>';
	}
	function sinRed() { return state.offline ? '<div class="tc-sinred" role="status">' + svg(I.senal, 20, '#FFB37A') + 'Sin internet ahora mismo</div>' : ''; }
	function nav(actual) {
		return '<nav class="tc-nav" aria-label="Menú"><a href="#inicio"' + (actual === 'inicio' ? ' aria-current="page"' : '') + '>' + svg(I.inicio) + 'Inicio</a>'
			+ '<a href="#historial"' + (actual === 'historial' ? ' aria-current="page"' : '') + '>' + svg(I.lista) + 'Mis viajes</a></nav>';
	}

	function screenEntrar() {
		var h = '<main class="tc-main"><img src="' + esc(C.logo) + '" alt="Triciclub" width="200" height="39" style="margin:24px 0 8px">';
		if (state.paso === 'telefono') {
			h += '<h1 class="tc-h1">Entra con tu móvil</h1><p class="tc-muted">Te mandamos un código por WhatsApp. Sin contraseñas.</p>'
				+ '<label class="tc-field"><span>Tu móvil</span><input id="tc-tel" class="tc-input" type="tel" inputmode="tel" autocomplete="tel" placeholder="5X XXX XXX" value="' + esc(state.telefono) + '"></label>'
				+ '<button type="button" class="tc-btn" data-act="codigo"' + (state.busy ? ' disabled' : '') + '>Mandarme el código</button>';
		} else {
			h += '<h1 class="tc-h1">Pon el código</h1><p class="tc-muted">Lo mandamos por WhatsApp al ' + esc(state.telefono) + '.</p>'
				+ '<label class="tc-field"><span>Código de 6 números</span><input id="tc-cod" class="tc-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>'
				+ '<label class="tc-field"><span>Tu nombre</span><input id="tc-nom" class="tc-input" type="text" autocomplete="given-name" maxlength="80"></label>'
				+ '<label class="tc-check"><input id="tc-ok" type="checkbox"> Acepto que Triciclub me escriba sobre mis viajes y ofertas. (Puedes no marcarlo.)</label>'
				+ '<button type="button" class="tc-btn" data-act="entrar"' + (state.busy ? ' disabled' : '') + '>Entrar</button>'
				+ '<button type="button" class="tc-btn tc-btn--link" data-act="otro-tel">Cambiar el número o pedir otro código</button>';
		}
		return h + '<p class="tc-small">¿Problemas? Llama a Triciclub: <a href="tel:+' + esc(C.telefono) + '">+' + esc(C.telefono) + '</a></p></main>';
	}

	function campoLugar(cual, etiqueta, ph) {
		var p = state[cual];
		var estado = p.buscando ? '<span class="tc-small">Buscando…</span>' : (p.err ? '<span class="tc-ruta__err">' + esc(p.err) + '</span>' : (p.lat !== null ? '<span class="tc-ruta__ok">✓ Encontrado</span>' : ''));
		return '<div class="tc-ruta__fila"><span class="tc-ruta__punto' + (cual === 'destino' ? ' tc-ruta__punto--dest' : '') + '"></span>'
			+ '<label class="tc-ruta__txt"><small>' + etiqueta + '</small><input data-lugar="' + cual + '" value="' + esc(p.texto) + '" placeholder="' + esc(ph) + '" autocomplete="street-address">' + estado + '</label>'
			+ (cual === 'origen' ? '<button type="button" class="tc-icon-btn" data-act="gps" aria-label="Usar mi ubicación">' + svg(I.gps, 20, '#087a3d') + '</button>' : '')
			+ '</div>';
	}

	function screenInicio() {
		var yo = state.yo || {};
		var h = top() + sinRed() + '<main class="tc-main"><h1 class="tc-h1">Hola' + (yo.nombre ? ', ' + esc(yo.nombre.split(' ')[0]) : '') + '.<br>¿Qué necesitas mover?</h1>';
		h += '<div class="tc-servicios" role="group" aria-label="Servicio">';
		SERVICIOS.forEach(function (s) {
			h += '<button type="button" class="tc-serv" data-act="servicio" data-id="' + s.id + '" aria-pressed="' + (state.servicio === s.id) + '">' + svg(s.icon, 28, '#087a3d') + '<b>' + s.nombre + '</b><span>' + s.sub + '</span></button>';
		});
		h += '</div><div class="tc-ruta">' + campoLugar('origen', 'Recoger en', 'Calle y entrecalles') + campoLugar('destino', 'Llevar a', '¿A dónde vamos?') + '</div>';
		var lugares = store.json('lugares', []);
		if (lugares.length) {
			h += '<div class="tc-chips" aria-label="Tus lugares">';
			lugares.forEach(function (l, i) { h += '<button type="button" class="tc-chip" data-act="lugar" data-id="' + i + '">' + esc(l.texto) + '</button>'; });
			h += '</div>';
		}
		h += '<div class="tc-aviso">' + svg(I.candado, 24, '#B4430A') + '<span><b>Precio fijo.</b> Cada chofer pone su precio y lo ves antes de pedir. Lo que eliges es lo que pagas.</span></div>';
		h += '<button type="button" class="tc-btn" data-act="ver"' + (state.busy ? ' disabled' : '') + '>Ver triciclos libres</button></main>';
		return h + nav('inicio');
	}

	function screenElegir() {
		var c = state.cerca, s = servicioDe(state.servicio);
		var h = '<header class="tc-top"><a class="tc-icon-btn" href="#inicio" aria-label="Volver">' + svg(I.atras) + '</a><span class="tc-strong">' + s.nombre + '</span><span style="width:44px"></span></header>' + sinRed() + '<main class="tc-main">';
		h += '<div class="tc-small">' + esc(state.origen.texto) + ' → ' + esc(state.destino.texto) + (c ? '<br>' + esc(String(c.km).replace('.', ',')) + ' km · unos ' + c.minutos + ' min · ' + esc(c.franja.nombre) : '') + '</div>';
		if (!c) { return h + '<div class="tc-card" role="status"><p class="tc-muted" style="margin:0">Buscando triciclos libres cerca…</p></div></main>'; }
		if (state.servicio === 'paquete') {
			h += '<div class="tc-card"><span class="tc-h2">¿Quién recibe el paquete?</span>'
				+ '<label class="tc-field"><span>Nombre</span><input class="tc-input" data-campo="recibe.nombre" value="' + esc(state.recibe.nombre) + '" maxlength="80"></label>'
				+ '<label class="tc-field"><span>Teléfono</span><input class="tc-input" type="tel" inputmode="tel" data-campo="recibe.telefono" value="' + esc(state.recibe.telefono) + '"></label></div>';
		}
		h += '<div class="tc-seg" role="group" aria-label="Forma de pago"><button type="button" data-act="pago" data-id="efectivo" aria-pressed="' + (state.pago === 'efectivo') + '">Efectivo CUP</button><button type="button" data-act="pago" data-id="transferencia" aria-pressed="' + (state.pago === 'transferencia') + '">Transferencia</button></div>';
		h += '<label class="tc-field"><span>Nota para el chofer (opcional)</span><input class="tc-input" data-campo="nota" value="' + esc(state.nota) + '" maxlength="200" placeholder="Ej.: voy con dos maletas"></label>';
		var l = c.triciclos || [];
		if (!l.length) {
			return h + '<div class="tc-card"><span class="tc-h2">No hay triciclos libres cerca ahora</span><p class="tc-muted" style="margin:0">Prueba en unos minutos o llama a Triciclub.</p>'
				+ '<button type="button" class="tc-btn tc-btn--sec tc-btn--small" data-act="ver">Buscar otra vez</button><a class="tc-btn tc-btn--small" href="tel:+' + esc(C.telefono) + '">Llamar a Triciclub</a></div></main>';
		}
		h += '<h2 class="tc-h2">' + l.length + (l.length === 1 ? ' triciclo libre' : ' triciclos libres') + ' · precio por el triciclo completo</h2>';
		l.forEach(function (t, i) {
			h += '<article class="tc-tri"><div class="tc-tri__cab"><div class="tc-avatar" aria-hidden="true">' + esc(iniciales(t.nombre)) + '</div>'
				+ '<div class="tc-tri__quien"><span class="tc-strong">' + esc(t.nombre) + '</span><span class="tc-small">' + (t.nota ? '★ ' + esc(String(t.nota).replace('.', ',')) + ' · ' : '') + t.viajes + ' viajes · llega en ' + t.llega + ' min</span></div>'
				+ '<span class="tc-precio tc-num">' + esc(cup(t.precio).replace(' CUP', '')) + ' <small>CUP</small></span></div>'
				+ '<div class="tc-row"><span class="tc-small">' + esc([t.numero ? 'Nº ' + t.numero : '', t.descripcion, t.plazas ? t.plazas + ' plazas' : ''].filter(Boolean).join(' · ')) + '</span>'
				+ (t.lot_ok ? '<span class="tc-badge">' + svg(I.escudo, 14, '#05592C') + 'LOT al día</span>' : '') + '</div>'
				+ (t.extra ? '<span class="tc-small">Incluye +' + t.extra + ' % por ser ' + esc(c.franja.nombre.toLowerCase()) + '</span>' : '')
				+ '<button type="button" class="tc-btn tc-btn--small" data-act="elegir" data-id="' + i + '"' + (state.busy ? ' disabled' : '') + '>Pedir a ' + esc(t.nombre.split(' ')[0]) + ' · ' + esc(cup(t.precio)) + '</button></article>';
		});
		return h + '<p class="tc-small">Los precios valen 10 minutos.</p></main>';
	}

	function screenSinRed() {
		var p = state.pendiente || store.json('cola', null) || {}, r = p._resumen || {};
		return '<div class="tc-shell">' + sinRed() + '<main class="tc-main"><h1 class="tc-h1">Tranquilo: tu pedido está guardado.</h1>'
			+ '<p class="tc-muted">Se manda solo en cuanto vuelva la señal. No tienes que escribir nada otra vez.</p>'
			+ '<div class="tc-card"><div class="tc-row"><span class="tc-strong">' + esc(servicioDe(r.servicio).nombre) + ' con ' + esc(r.nombre || '') + '</span><span class="tc-badge tc-badge--naranja">ESPERANDO SEÑAL</span></div>'
			+ '<span class="tc-small">' + esc(r.origen || '') + ' → ' + esc(r.destino || '') + '</span><span class="tc-strong">' + esc(cup(r.precio)) + '</span></div>'
			+ '<a class="tc-btn" href="tel:+' + esc(C.telefono) + '">' + svg(I.tel, 22, '#fff') + 'Llamar a Triciclub</a>'
			+ '<button type="button" class="tc-btn tc-btn--sec" data-act="reintentar">Probar ahora</button>'
			+ '<button type="button" class="tc-btn tc-btn--link" data-act="olvidar">Olvidar este pedido</button></main></div>';
	}

	function chofer(v) {
		var ch = v.chofer || {};
		return '<section class="tc-card" aria-label="Tu chofer"><div class="tc-tri__cab"><div class="tc-avatar" aria-hidden="true">' + esc(iniciales(ch.nombre)) + '</div>'
			+ '<div class="tc-tri__quien"><span class="tc-strong">' + esc(ch.nombre || 'Tu chofer') + '</span><span class="tc-small">' + (ch.nota ? '★ ' + esc(String(ch.nota).replace('.', ',')) + ' · ' : '') + (ch.viajes || 0) + ' viajes</span></div>'
			+ (ch.lot_ok ? '<span class="tc-badge">' + svg(I.escudo, 14, '#05592C') + 'LOT al día</span>' : '') + '</div>'
			+ '<div class="tc-row tc-small"><span>' + esc(ch.descripcion || 'Triciclo eléctrico') + '</span><span class="tc-strong">' + (ch.numero ? 'Nº ' + esc(ch.numero) : '') + '</span></div></section>';
	}

	function screenViaje() {
		var v = state.viaje;
		if (!v) { return screenInicio(); }
		var h = '';
		if (v.estado === 'terminado') { return screenLlego(v); }
		if (v.estado === 'esperando') {
			var falta = Math.max(0, Math.round((new Date(v.espera_hasta).getTime() - Date.now()) / 1000));
			h = top() + sinRed() + '<main class="tc-main"><h1 class="tc-h1">Esperando que ' + esc((v.chofer && v.chofer.nombre || 'el chofer').split(' ')[0]) + ' acepte</h1>'
				+ '<p class="tc-muted">Tiene ' + falta + ' segundos. Si no puede, eliges otro triciclo.</p><div class="tc-espera" aria-hidden="true"><div style="width:' + Math.round(100 * falta / 90) + '%"></div></div>'
				+ chofer(v) + '<div class="tc-row"><span class="tc-muted">' + esc(servicioDe(v.servicio).nombre) + ' · ' + (v.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="tc-strong">' + esc(cup(v.precio)) + ' fijo</span></div>'
				+ '<button type="button" class="tc-btn tc-btn--link" data-act="cancelar">Cancelar el pedido</button></main>';
			return h;
		}
		if (v.estado === 'rechazado' || v.estado === 'cancelado') {
			var quien = v.estado === 'rechazado' ? esc((v.chofer && v.chofer.nombre || 'El chofer').split(' ')[0]) + ' no puede ahora' : 'Viaje cancelado';
			return top() + '<main class="tc-main"><h1 class="tc-h1">' + quien + '</h1><p class="tc-muted">' + (v.estado === 'rechazado' ? 'No pasa nada: elige otro triciclo libre.' : (v.motivo ? esc(v.motivo) : 'No se cobra nada.')) + '</p>'
				+ (v.estado === 'rechazado' ? '<button type="button" class="tc-btn" data-act="otro">Elegir otro triciclo</button>' : '')
				+ '<button type="button" class="tc-btn tc-btn--sec" data-act="nuevo">Volver al inicio</button></main>';
		}
		var titulo = { asignado: 'Tu triciclo viene', llego: '¡Ya llegó! Sal a buscarlo', recogido: 'En camino' }[v.estado] || 'Tu viaje';
		var tel = v.chofer && v.chofer.telefono;
		h = '<div class="tc-shell"><main class="tc-main" style="padding-top:0"><div class="tc-mapa" id="tc-mapa" aria-label="Mapa del viaje"></div>'
			+ '<div class="tc-row"><h1 class="tc-h1" style="font-size:24px">' + titulo + '</h1><span class="tc-badge">' + (v.estado === 'recogido' ? 'Hacia ' + esc(v.destino.texto) : 'En ' + esc(v.origen.texto)) + '</span></div>' + sinRed()
			+ chofer(v);
		if (v.estado !== 'recogido') { h += '<section class="tc-codigo" aria-label="Código de seguridad"><span>Antes de subir, pídele al chofer que te diga este código</span><b>' + esc(v.codigo) + '</b></section>'; }
		h += '<div class="tc-acciones">' + (tel ? '<a class="tc-accion" href="tel:+' + esc(tel) + '">' + svg(I.tel, 20, '#087a3d') + 'Llamar</a>' : '<span class="tc-accion">' + svg(I.tel, 20, '#9AA79F') + 'Llamar</span>')
			+ (tel ? '<a class="tc-accion" href="https://wa.me/' + esc(tel) + '" target="_blank" rel="noopener">' + svg('<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>', 20, '#087a3d') + 'WhatsApp</a>' : '<span class="tc-accion">WhatsApp</span>')
			+ '<button type="button" class="tc-accion" data-act="compartir">' + svg(I.compartir, 20, '#087a3d') + 'Compartir</button></div>';
		h += '<div class="tc-row"><span class="tc-muted">' + esc(servicioDe(v.servicio).nombre) + ' · ' + (v.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="tc-strong">' + esc(cup(v.precio)) + ' fijo</span></div>';
		if (v.estado !== 'recogido') { h += '<button type="button" class="tc-btn tc-btn--link" data-act="cancelar">Cancelar el pedido</button>'; }
		return h + '</main></div>';
	}

	function screenLlego(v) {
		var ch = v.chofer || {};
		var h = '<div class="tc-shell"><main class="tc-main" style="padding-top:0"><div class="tc-hecho">' + svg(I.bien, 40, '#9FE3BC') + '<h1 class="tc-h1">¡Llegaste!</h1><p>' + esc(v.destino.texto) + '</p></div>'
			+ '<section class="tc-card"><div class="tc-row"><span class="tc-strong">Págale a ' + esc((ch.nombre || 'tu chofer').split(' ')[0]) + '</span><span class="tc-precio tc-num">' + esc(cup(v.precio)) + '</span></div>'
			+ '<span class="tc-small" style="color:#05592C;font-weight:700">✓ El mismo precio que viste al pedir · ' + (v.forma_pago === 'efectivo' ? 'en efectivo' : 'por transferencia' + (ch.transferencia ? ' a ' + esc(ch.transferencia) : '')) + '</span></section>';
		if (v.valorado) { return h + '<button type="button" class="tc-btn" data-act="nuevo">Listo</button></main></div>'; }
		h += '<h2 class="tc-h2">¿Qué tal el viaje?</h2><div class="tc-estrellas" role="radiogroup" aria-label="Nota del viaje">';
		for (var n = 1; n <= 5; n++) {
			h += '<button type="button" role="radio" aria-checked="' + (n === state.estrellas) + '" aria-label="' + n + ' de 5" data-act="estrella" data-id="' + n + '"><svg width="38" height="38" viewBox="0 0 24 24" stroke="#E8590C" stroke-width="1.6" stroke-linejoin="round" fill="' + (n <= state.estrellas ? '#F2A516' : 'none') + '" aria-hidden="true">' + I.estrella + '</svg></button>';
		}
		h += '</div><div class="tc-tags">';
		TAGS.forEach(function (t) { h += '<button type="button" data-act="tag" data-id="' + t[0] + '" aria-pressed="' + !!state.tags[t[0]] + '">' + t[1] + '</button>'; });
		return h + '</div><button type="button" class="tc-btn" data-act="valorar">Listo</button></main></div>';
	}

	function screenHistorial() {
		var h = top() + sinRed() + '<main class="tc-main"><h1 class="tc-h1">Mis viajes</h1>';
		if (!state.historial) { return h + '<p class="tc-muted">Cargando…</p></main>' + nav('historial'); }
		if (!state.historial.length) { h += '<p class="tc-muted">Todavía no has hecho viajes.</p>'; }
		var nombres = { esperando: 'Esperando', asignado: 'En camino', llego: 'Llegó', recogido: 'En viaje', terminado: 'Terminado', cancelado: 'Cancelado', rechazado: 'No aceptado' };
		state.historial.forEach(function (v) {
			h += '<a class="tc-card" style="text-decoration:none;color:inherit" href="#viaje/' + esc(v.uuid) + '"><div class="tc-row"><span class="tc-strong">' + esc(servicioDe(v.servicio).nombre) + ' · ' + esc(cup(v.precio)) + '</span><span class="tc-badge' + (v.estado === 'terminado' ? '' : ' tc-badge--naranja') + '">' + esc(nombres[v.estado] || v.estado) + '</span></div>'
				+ '<span class="tc-small">' + esc(v.origen.texto) + ' → ' + esc(v.destino.texto) + '</span><span class="tc-small">' + esc(new Date(v.creado).toLocaleString('es', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })) + '</span></a>';
		});
		h += '<button type="button" class="tc-btn tc-btn--link" data-act="salir">Salir de este teléfono</button>';
		return h + '</main>' + nav('historial');
	}

	function screenCerrado() {
		return '<main class="tc-cerrado"><img src="' + esc(C.logo) + '" alt="Triciclub" width="220" height="43"><h1 class="tc-h1">Muy pronto</h1><p class="tc-muted">' + esc(state.cerrado || '') + '</p></main>';
	}

	function cargando() {
		return '<main class="tc-carga" role="status"><div class="tc-carga__logo"><img src="' + esc(C.logo) + '" alt="Triciclub" width="220" height="43"></div><div class="tc-carga__via" aria-hidden="true"><svg class="tc-carga__tri" width="60" height="46" viewBox="0 0 60 46" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h34v18H6z"/><path d="M6 24h48l-4 8H8z"/><path d="M44 6l10 18"/><circle cx="14" cy="36" r="6"/><circle cx="46" cy="36" r="6"/></svg></div><p class="tc-carga__txt">Buscando triciclos cerca de ti</p></main>';
	}

	var mapa = { el: null, cargado: false };
	function montarMapa() {
		var el = document.getElementById('tc-mapa'), v = state.viaje;
		if (!el || !v) { return; }
		var go = function () {
			if (!window.PallaMapa) { return; }
			var dest = v.estado === 'recogido' ? [v.destino.lat, v.destino.lng] : [v.origen.lat, v.origen.lng];
			window.PallaMapa.ruta(el, { dest: dest, pos: v.pos, name: v.chofer && v.chofer.nombre, stage: 'casa', arrived: v.estado === 'llego' }).catch(function () {});
		};
		if (window.PallaMapa) { go(); return; }
		if (mapa.cargado) { return; }
		mapa.cargado = true;
		var s = document.createElement('script'); s.src = C.mapaUrl; s.onload = go; document.head.appendChild(s);
	}

	function render() {
		var html;
		if (state.view === 'cargando') { html = cargando(); }
		else if (state.view === 'cerrado') { html = screenCerrado(); }
		else if (state.view === 'entrar') { html = '<div class="tc-shell">' + screenEntrar() + '</div>'; }
		else if (state.view === 'sinred') { html = screenSinRed(); }
		else if (state.view === 'elegir') { html = '<div class="tc-shell">' + screenElegir() + '</div>'; }
		else if (state.view === 'viaje') { html = screenViaje(); if (html.indexOf('tc-shell') === -1) { html = '<div class="tc-shell">' + html + '</div>'; } }
		else if (state.view === 'historial') { html = '<div class="tc-shell">' + screenHistorial() + '</div>'; }
		else { html = '<div class="tc-shell">' + screenInicio() + '</div>'; }
		var foco = document.activeElement && document.activeElement.getAttribute && (document.activeElement.getAttribute('data-lugar') || document.activeElement.getAttribute('data-campo') || document.activeElement.id);
		var pos = foco && document.activeElement.selectionStart;
		var keep = document.getElementById('tc-mapa');
		var escrito = {}; // lo que se está escribiendo en los campos sin estado (teléfono, código, nombre) no se pierde al repintar
		Array.prototype.forEach.call(app.querySelectorAll('input[id]'), function (i) { escrito[i.id] = i.type === 'checkbox' ? i.checked : i.value; });
		app.innerHTML = html + (state.toast ? '<div class="tc-toast" role="status">' + esc(state.toast) + '</div>' : '');
		Object.keys(escrito).forEach(function (id) { var i = document.getElementById(id); if (i) { if (i.type === 'checkbox') { i.checked = escrito[id]; } else if (escrito[id]) { i.value = escrito[id]; } } });
		var nuevoMapa = document.getElementById('tc-mapa'); // el mapa ya pintado se queda (no se vuelve a cargar cada 10 s)
		if (keep && nuevoMapa && nuevoMapa.parentNode) { nuevoMapa.parentNode.replaceChild(keep, nuevoMapa); }
		if (foco) {
			var el = app.querySelector('[data-lugar="' + foco + '"],[data-campo="' + foco + '"],#' + foco.replace(/[^\w-]/g, ''));
			if (el && el.focus) { el.focus(); try { el.setSelectionRange(pos, pos); } catch (e) {} }
		}
		montarMapa();
	}

	// ------------------------------------------------------------ Eventos
	app.addEventListener('input', function (e) {
		var t = e.target, l = t.getAttribute('data-lugar'), c = t.getAttribute('data-campo');
		if (l) { state[l].texto = t.value; state[l].lat = state[l].lng = null; state[l].err = ''; }
		if (c === 'nota') { state.nota = t.value; }
		if (c === 'recibe.nombre') { state.recibe.nombre = t.value; }
		if (c === 'recibe.telefono') { state.recibe.telefono = t.value; }
	});
	app.addEventListener('change', function (e) { var l = e.target.getAttribute('data-lugar'); if (l && state[l].texto.length >= 3) { buscarLugar(l); } });
	app.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var a = el.getAttribute('data-act'), id = el.getAttribute('data-id');
		if (a === 'codigo') { pedirCodigo(); }
		else if (a === 'entrar') { entrar(); }
		else if (a === 'otro-tel') { state.paso = 'telefono'; render(); }
		else if (a === 'servicio') { state.servicio = id; render(); }
		else if (a === 'gps') { miUbicacion(); }
		else if (a === 'lugar') {
			var lg = store.json('lugares', [])[+id];
			if (lg) { var k = state.origen.lat === null ? 'origen' : 'destino'; state[k] = { texto: lg.texto, lat: lg.lat, lng: lg.lng, err: '' }; render(); }
		}
		else if (a === 'ver') { verTriciclos(); }
		else if (a === 'pago') { state.pago = id; render(); }
		else if (a === 'elegir') { elegir(+id); }
		else if (a === 'cancelar') { cancelar(); }
		else if (a === 'compartir') { compartir(); }
		else if (a === 'otro') { state.viaje = null; location.hash = '#inicio'; verTriciclos(); }
		else if (a === 'nuevo') { nuevo(); }
		else if (a === 'estrella') { state.estrellas = +id; render(); }
		else if (a === 'tag') { state.tags[id] = !state.tags[id]; render(); }
		else if (a === 'valorar') { valorar(); }
		else if (a === 'reintentar') { var p = store.json('cola', null); if (p) { enviar(p); } }
		else if (a === 'olvidar') { store.set('cola', null); state.pendiente = null; nuevo(); }
		else if (a === 'salir') { if (window.confirm('¿Salir de Triciclub en este teléfono?')) { store.set('k', null); state.token = ''; state.view = 'entrar'; state.paso = 'telefono'; render(); } }
	});

	window.addEventListener('hashchange', ruta);
	window.addEventListener('online', function () { state.offline = false; var p = store.json('cola', null); if (p) { enviar(p); } else { render(); } });
	window.addEventListener('offline', function () { state.offline = true; render(); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden && state.viaje) { seguir(); } });
	timers.tick = setInterval(function () { if (state.view === 'viaje' && state.viaje && state.viaje.estado === 'esperando') { render(); } }, 1000);

	function ruta() {
		if (!state.token) { return; }
		var h = location.hash || '';
		var m = /^#viaje\/([a-f0-9-]{36})$/.exec(h);
		if (m && (!state.viaje || state.viaje.uuid !== m[1])) {
			api('viajes/' + m[1]).then(function (res) { if (salir(res)) { return; } if (res.data.ok) { abrirViaje(res.data.viaje); } });
			return;
		}
		if (h === '#historial') {
			state.view = 'historial'; state.historial = null; render();
			api('viajes').then(function (res) { if (salir(res)) { return; } state.historial = res.data.viajes || []; render(); });
			return;
		}
		if (h === '#inicio' || h === '') { if (state.view !== 'viaje' || !state.viaje || ['terminado', 'cancelado', 'rechazado'].indexOf(state.viaje.estado) !== -1) { state.view = 'inicio'; render(); } }
	}

	function cargarYo() {
		return api('yo').then(function (res) {
			if (salir(res)) { return; }
			if (res.status === 0) { state.view = store.json('cola', null) ? 'sinred' : 'inicio'; render(); programarCola(); return; }
			state.yo = res.data.yo;
			if (res.data.activo) { abrirViaje(res.data.activo); return; }
			var p = store.json('cola', null);
			if (p) { enviar(p); return; }
			state.view = 'inicio'; ruta(); render();
		});
	}

	// ------------------------------------------------------------ Arranque
	var m = /(?:^#|&)k=([0-9a-f.]+)/i.exec(location.hash || '');
	if (m) { store.set('k', m[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname + location.search + '#inicio'); } }
	state.token = store.get('k') || '';
	if ('serviceWorker' in navigator && C.sw && location.protocol === 'https:') { navigator.serviceWorker.register(C.sw, { scope: C.scope || './' }).catch(function () {}); }
	render();
	if (!state.token) { state.view = 'entrar'; render(); } else { cargarYo(); }
})();
