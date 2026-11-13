/* Pa'llá transporte (5-nov-2026) · app del CLIENTE en /palla/ir/. Pantallas del diseño v3 aprobado (6-nov, D142:
 * diseno-v3/prototipo/app.js): inicio con 4 servicios (Viajar · Paquete · Comida · Carga); Viajar enseña los 7 vehículos con
 * su precio y el precio se toca para subirlo o bajarlo (pista B); la recogida es opcional («Desde…»). Paquete, Comida y Carga
 * usan la misma hoja de precio. Con los datos reales de la API casa-viva/v1/pv/… Sin librerías: window.PV (servidor) + window.PMapa (mapa.js) + window.PU
 * (comun.js). Token del cliente en localStorage «pv.k» → cabecera X-TC-Token (también vale #k=… en la URL).
 * En vivo: canal ntfy del viaje (PU.enVivo) + pregunta a la web cada 4 s buscando y cada 15 s en viaje (30 s oculta).
 * La primera pantalla sale al momento: el mapa (o su plano de reserva) llega después. Sin red, el pedido se guarda y sale
 * solo al volver la señal. */
(function () {
	'use strict';
	var C = window.PV || {}, U = window.PU;
	if (!U || !window.PMapa) { return; }
	var $ = U.$, $$ = U.$$, ico = U.ico, esc = U.esc, cup = U.cup, cupG = U.cupG, miles = U.miles, LS = U.LS;
	var pant = $('#pant'), marco = $('#marco'), mapaEl = $('#mapa');
	var TEMA = document.documentElement.dataset.tema;
	var CLASES = C.clases || {};
	var PALABRA = { tric: 'triciclo', taxi: 'taxi', moto: 'moto', mens: 'mensajero', viajar: 'vehículo' };
	var PESOS = { poco: ['Hasta 50 kg', 'cajas, sillas'], medio: ['50–150 kg', 'nevera, cama'], mucho: ['150–300 kg', 'de todo un poco'] };
	var ETAPAS = { paquete: ['Recogido', 'En camino', 'Entregado'], comida: ['Pagada', 'En camino', 'Entregada'], carga: ['Cargado', 'En camino', 'Entregado'] };
	var AYUDANTE = +C.ayudante || 500, TOPE_COMIDA = +C.comidaTope || 5000; // los manda el servidor (opciones cvd_pv_ayudante_cup / cvd_pv_comida_tope)
	var ICO_G = { tric: 'triciclo', taxi: 'coche', moto: 'moto', mens: 'paquete' };
	var COLOR_G = { tric: 'tric', taxi: 'taxi', moto: 'taxi', mens: 'mens' };
	var VEL = { taxi: 400, tric: 250, moto: 400, mens: 380 }; // metros por minuto en la ciudad (como el servidor)
	var HABANA = [-82.3812, 23.1402];
	var ACTIVOS = ['buscando', 'sin_chofer', 'asignado', 'llego', 'recogido'];

	// ---------- Entrada: token ----------
	var mk = /(?:^#|&)k=([A-Za-z0-9._-]+)/.exec(location.hash || '');
	if (mk) { LS.set('k', mk[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	var K = LS.get('k') || '';

	function api(path, body, metodo, intentos) {
		return U.api(path, body, { 'X-TC-Token': K }, metodo, intentos).then(function (r) {
			if (r.status === 401 && K) { K = ''; LS.set('k', null); ir('entrar'); }
			else if (r.status === 403 && /^pv_(closed|not_tester|blocked)$/.test(r.data.code || '')) { S.cerrado = r.data.message; ir('cerrado'); }
			return r;
		});
	}
	function qs(o) { return Object.keys(o).filter(function (k) { return o[k] != null && o[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); }

	// ---------- Estado (lo del camino se guarda en el móvil) ----------
	// modo (v3): viajar | paquete | comida | carga. envio: lo de Paquete, Comida (sitio, adelanto, cobrar, quién recibe) y Carga (peso, ayudante).
	var ENVIO0 = { que: '', tam: 'mano', recibe: '', tel: '', cobrar: false, cobrarCup: 0, nota: '', recoge: '', paga: false, pagaCup: 0, otro: false, peso: 'medio', ayuda: false };
	var PAS0 = { otro: false, nombre: '', tel: '' }; // 10-nov: Viajar «para otra persona»
	var S = { svc: 'tric', modo: 'viajar', grupo: 'tric', clase: '', origen: null, destino: null, nota: '', precio: 0, primera: false, pago: 'efectivo', para: 'destino', envio: ENVIO0, pas: PAS0, recAtras: 'viajar' };
	(function () { var g = LS.json('s'); if (g) { Object.keys(S).forEach(function (k) { if (g[k] != null) { S[k] = g[k]; } }); } S.primera = false; Object.keys(ENVIO0).forEach(function (k) { if (S.envio[k] == null) { S.envio[k] = ENVIO0[k]; } }); })();
	S.yo = LS.json('yo'); S.recientes = LS.json('recientes') || []; S.viaje = LS.json('viaje'); S.cot = null; S.estrellas = 0; S.ocultas = {};
	function guardar() { LS.set('s', JSON.stringify({ svc: S.svc, modo: S.modo, grupo: S.grupo, clase: S.clase, origen: S.origen, destino: S.destino, nota: S.nota, precio: S.precio, pago: S.pago, para: S.para, envio: S.envio, pas: S.pas, recAtras: S.recAtras })); }
	function ponerViaje(v) { S.viaje = v; LS.set('viaje', v ? JSON.stringify(v) : null); }

	// ---------- Utilidades ----------
	var timers = [];
	function T(fn, ms) { timers.push(setTimeout(fn, ms)); }
	function I(fn, ms) { timers.push(setInterval(fn, ms)); }
	function ir(id) { if (location.hash === '#' + id) { mostrar(true); } else { location.hash = id; } }
	function corto(t) { return String(t || 'Punto en el mapa').split(',')[0].split(' e/')[0]; }
	function grupoDe(clase) { return (CLASES[clase] && CLASES[clase].grupo) || 'tric'; }
	function imgDe(clase) { return (CLASES[clase] && CLASES[clase].img) || 'tric-electrico'; }
	function nombreDe(clase) { return (CLASES[clase] && CLASES[clase].nombre) || 'Pa\'llá'; }
	function esEnvio(v) { return v && v.clase === 'mensajero'; }
	function servDe(v) { return v.servicio || (esEnvio(v) ? 'paquete' : 'viaje'); }
	// v3: el nombre que ve el cliente de su pedido (los envíos, por su servicio; la comida, «Moto con caja térmica»).
	function nombreServ(v) { var s = servDe(v); return s === 'comida' ? 'Moto con caja térmica' : (s === 'carga' ? 'Triciclo de carga' : (s === 'paquete' ? (v.envio && v.envio.tam === 'grande' ? 'Triciclo de carga' : 'Mensajero') : nombreDe(v.clase))); }
	function imgServ(v) { var s = servDe(v); return s === 'comida' ? 'comida' : (s === 'carga' || (v.envio && v.envio.tam === 'grande') ? 'tric-carga' : imgDe(v.clase)); }
	function arriba10(x) { return Math.ceil(x / 10) * 10; }
	function dist(a, b) { var x = (b[0] - a[0]) * Math.cos((a[1] + b[1]) * Math.PI / 360), y = b[1] - a[1]; return Math.sqrt(x * x + y * y) * 111320; }
	function largo(c) { var l = 0; for (var i = 1; i < c.length; i++) { l += dist(c[i - 1], c[i]); } return l; }
	function cid() { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
	function nombreCorto(n) { return String(n || '').split(' ')[0]; }
	function vibrar(p) { try { if (navigator.vibrate) { navigator.vibrate(p); } } catch (e) { } }
	function waLink(tel, txt) { return 'https://wa.me/' + U.tel53(tel) + (txt ? '?text=' + encodeURIComponent(txt) : ''); }
	function gps() {
		return new Promise(function (ok) {
			if (!navigator.geolocation) { ok(null); return; }
			var hecho = false; setTimeout(function () { if (!hecho) { hecho = true; ok(null); } }, 9000);
			navigator.geolocation.getCurrentPosition(function (p) { if (!hecho) { hecho = true; ok([p.coords.longitude, p.coords.latitude]); } }, function () { if (!hecho) { hecho = true; ok(null); } }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
		});
	}
	// 10-nov: el servidor pega el punto a la calle más cercana (lat/lng ya en la calle; `pegado` si lo movió).
	function lugarDe(p) { return api('donde?' + qs({ lat: p[1].toFixed(6), lng: p[0].toFixed(6) }), null, 'GET', 3).then(function (r) { var d = r.data || {}; return { texto: d.texto || 'Punto en el mapa', barrio: d.barrio || '', lat: d.lat != null ? +d.lat : p[1], lng: d.lng != null ? +d.lng : p[0], pegado: !!d.pegado }; }); }
	function filaLugar(attrs, icono, n, d, fin) {
		return '<button type="button" class="p-fila" ' + attrs + '><span class="p-fila__ico">' + ico(icono) + '</span><span class="p-fila__txt"><b>' + n + '</b>' + (d ? '<span>' + d + '</span>' : '') + '</span>' + (fin ? '<span class="p-fila__fin">' + fin + '</span>' : '') + '</button>';
	}
	function esqueleto() { return '<div class="pa-esq-oferta" aria-hidden="true"><span class="p-esq" style="width:32px;height:32px;border-radius:50%"></span><span><span class="p-esq" style="width:58%;height:12px"></span><span class="p-esq" style="width:36%;height:10px;margin-top:8px"></span></span><span class="p-esq" style="width:52px;height:22px"></span></div>'; }
	function volver(h) { return '<a class="pa-volver" href="#' + h + '" aria-label="Volver">' + ico('atras') + '</a>'; }
	function volverR(h) { return '<a class="p-btn p-btn--redondo" href="#' + h + '" aria-label="Volver">' + ico('atras') + '</a>'; }

	// =====================================================================================
	// PANTALLAS
	// =====================================================================================
	var SCR = {};
	var LOGO = '<svg width="48" height="48" viewBox="0 0 96 96" aria-hidden="true"><rect width="96" height="96" rx="22" fill="#0E0E0E"/><path fill="#FFC21A" fill-rule="evenodd" d="M25 20h27a20 20 0 0 1 0 40h-9v16H25zM43 34v12h7a6 6 0 0 0 0-12z"/><path d="M43 76c10 0 24-2 34-12" fill="none" stroke="#FFC21A" stroke-width="5" stroke-linecap="round"/><circle cx="78" cy="63" r="5" fill="#fff"/></svg>';

	SCR.cerrado = {
		libre: true,
		html: function () {
			return '<div class="pa-llena">' + (K ? '' : '<div class="pa-cab">' + volver('entrar') + '</div>') + '<div class="pa-cuerpo" style="padding-top:' + (K ? 96 : 32) + 'px;align-items:center;text-align:center">' + LOGO
				+ '<h1 class="p-titulo">Pa\'llá</h1><p class="p-2">' + esc(S.cerrado || 'Pa\'llá todavía no está abierto. Muy pronto.') + '</p>'
				+ (C.telefono ? '<a class="p-btn p-btn--suave" href="' + waLink(C.telefono) + '">' + ico('whatsapp', 'p-ico--20') + 'Escríbenos</a>' : '') + '</div></div>';
		}
	};

	// ---------- Entrar ----------
	SCR.entrar = {
		libre: true,
		html: function () {
			return '<div class="pa-llena"><div class="pa-cuerpo" style="padding-top:64px">' + LOGO
				+ '<div><h1 class="p-titulo">Muévete por La Habana</h1><p class="p-2" style="margin:6px 0 0">Taxi, triciclo o mensajero. Tú pones el precio.</p></div>'
				+ '<div><label class="pa-etq" for="tel">Tu móvil</label>'
				+ '<div class="pa-tel"><span class="pref">+53</span><label class="p-campo"><input id="tel" inputmode="tel" autocomplete="tel-national" maxlength="12" value="' + esc(LS.get('tel') || '') + '" placeholder="5 123 4567" aria-label="Número de móvil"></label></div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p></div>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="wa">' + ico('whatsapp', 'p-ico--20') + 'Código por WhatsApp</button>'
				+ '<button type="button" class="pa-enlace" id="sms" style="border:0;background:none">¿Sin WhatsApp? Por SMS</button></div></div>';
		},
		init: function (r) {
			var tel = $('#tel', r), err = $('#err', r);
			var pedir = function (canal, b) {
				var d = tel.value.replace(/\D+/g, '');
				if (d.length === 10 && d.indexOf('53') === 0) { d = d.slice(2); }
				if (d.length !== 8 || d.charAt(0) !== '5') { err.hidden = false; err.innerHTML = ico('alerta') + '<span>Escribe tu móvil: 8 números, empieza por 5.</span>'; tel.focus(); return; }
				b.disabled = true; err.hidden = true;
				api('cliente/codigo', { telefono: d, canal: canal }).then(function (x) {
					b.disabled = false;
					if (x.data.ok) { LS.set('tel', d); S.canal = canal; ir('codigo'); return; }
					err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + esc(x.data.message || 'No se pudo. Prueba otra vez.') + '</span>';
				});
			};
			$('#wa', r).onclick = function () { pedir('whatsapp', this); };
			$('#sms', r).onclick = function () { pedir('sms', this); };
			tel.addEventListener('keydown', function (e) { if (e.key === 'Enter') { pedir('whatsapp', $('#wa', r)); } });
		}
	};

	SCR.codigo = {
		libre: true,
		html: function () {
			var t = LS.get('tel') || '';
			return '<div class="pa-llena"><div class="pa-cab">' + volver('entrar') + '</div>'
				+ '<div class="pa-cuerpo"><div><h1 class="p-titulo">Escribe el código</h1><p class="p-2" style="margin:6px 0 0">Llegó por ' + (S.canal === 'sms' ? 'SMS' : 'WhatsApp') + ' al ' + esc(U.telBonito(t)) + '.</p></div>'
				+ '<div class="pa-cajas" style="position:relative"><span></span><span></span><span></span><span></span>'
				+ '<input id="cod" inputmode="numeric" autocomplete="one-time-code" maxlength="4" aria-label="Código de 4 cifras" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;border:0"></div>'
				+ '<button type="button" class="pa-enlace" id="otra" style="border:0;background:none" disabled>Reenviar en 1:00</button>'
				+ '<div><label class="pa-etq" for="nom">¿Cómo te llamas?</label><label class="p-campo"><input id="nom" autocomplete="given-name" maxlength="80" value="' + esc((S.yo && S.yo.nombre) || '') + '"></label></div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="entra">Entrar</button></div></div>';
		},
		init: function (r) {
			var cod = $('#cod', r), cajas = $$('.pa-cajas span', r), err = $('#err', r), otra = $('#otra', r), seg = 60;
			var pintar = function () { var v = cod.value.replace(/\D+/g, '').slice(0, 4); cod.value = v; cajas.forEach(function (s, i) { s.textContent = v.charAt(i); s.classList.toggle('is-act', i === Math.min(3, v.length) && document.activeElement === cod); }); };
			cod.addEventListener('input', function () { pintar(); if (cod.value.length === 4) { $('#nom', r).focus(); } });
			cod.addEventListener('focus', pintar); cod.addEventListener('blur', pintar);
			I(function () { seg--; if (seg > 0) { otra.textContent = 'Reenviar en 0:' + String(seg).padStart(2, '0'); } else { otra.disabled = false; otra.textContent = 'Reenviar el código'; } }, 1000);
			otra.onclick = function () { otra.disabled = true; api('cliente/codigo', { telefono: LS.get('tel'), canal: S.canal || 'whatsapp' }).then(function (x) { U.tostada(x.data.ok ? x.data.message : (x.data.message || 'No se pudo.'), x.data.ok ? 'check' : 'alerta'); seg = 60; }); };
			$('#entra', r).onclick = function () {
				var b = this, nom = $('#nom', r).value.trim();
				if (cod.value.length !== 4) { err.hidden = false; err.innerHTML = ico('alerta') + '<span>Faltan cifras del código.</span>'; cod.focus(); return; }
				if (nom.length < 2) { err.hidden = false; err.innerHTML = ico('alerta') + '<span>Escribe tu nombre.</span>'; $('#nom', r).focus(); return; }
				b.disabled = true; err.hidden = true;
				U.api('cliente/entrar', { telefono: LS.get('tel'), codigo: cod.value, nombre: nom }).then(function (x) {
					b.disabled = false;
					if (!x.data.ok) { err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + esc(x.data.message || 'No se pudo.') + '</span>'; return; }
					K = x.data.token; LS.set('k', K); S.yo = x.data.yo; LS.set('yo', JSON.stringify(S.yo));
					arrancar();
				});
			};
			T(function () { cod.focus(); }, 200);
		}
	};

	// ---------- Inicio ----------
	function ptoOrigen() { return S.origen ? [S.origen.lng, S.origen.lat] : HABANA; }
	var cercanosCache = [];
	SCR.inicio = {
		mapa: function () { return { ver: [ptoOrigen()], zoom: 15.4, pins: S.origen ? [['yo', ptoOrigen()]] : [], conducir: cercanosCache }; },
		// v3: arriba, desde dónde sales (lo pone el GPS; se toca para cambiarlo) · 4 servicios grandes con su vehículo 3D ·
		// debajo, los 2 últimos sitios (desde uno, 2 toques: sitio → Pedir).
		html: function () {
			var s4 = function (modo, n, sub, imgs) {
				return '<button type="button" class="pa-s4" data-modo="' + modo + '"><b>' + n + '</b><span>' + sub + '</span>'
					+ imgs.map(function (i, k) { return '<img class="pa-s4__img pa-s4__img--' + (imgs.length > 1 ? k + 1 : 0) + '" src="' + U.veh(i) + '" alt="">'; }).join('') + '</button>';
			};
			var rec = (S.recientes || []).slice(0, 2).map(function (x, i) { return filaLugar('data-rec="' + i + '"', 'reloj', esc(corto(x.texto)), esc(x.texto.indexOf(',') > 0 ? x.texto.slice(x.texto.indexOf(',') + 1).trim() : '')); }).join('');
			var activo = S.viaje && ACTIVOS.indexOf(S.viaje.estado) >= 0;
			return '<div class="pa-arriba"><a class="p-btn p-btn--redondo" href="#perfil" aria-label="Menú">' + ico('menu') + '</a>'
				+ '<button type="button" class="pa-pastilla pa-desde" id="desde"><span class="pm-pin pm-pin--yo"></span><span id="desde-t">' + (S.origen ? esc(corto(S.origen.texto)) : 'Buscando dónde estás…') + '</span></button></div>'
				+ '<section class="pa-hoja pa-hoja--inicio" aria-label="¿Qué necesitas?"><div class="p-hoja__asa"></div>'
				+ (activo ? '<a class="p-aviso p-aviso--bien" href="#' + pantallaDe(S.viaje) + '" style="text-decoration:none;margin:0 0 12px">' + ico('ruta') + '<span>Tienes un pedido en marcha. Ver.</span></a>' : '')
				+ '<nav class="pa-svc4" aria-label="Servicios">'
				+ s4('viajar', 'Viajar', 'Taxi, triciclo, moto', ['taxi-basico', 'tric-electrico'])
				+ s4('paquete', 'Paquete', 'Mensajero', ['mensajero'])
				+ s4('comida', 'Comida', 'De donde sea', ['comida'])
				+ s4('carga', 'Carga', 'Hasta 300 kg', ['tric-carga'])
				+ '</nav>'
				+ (rec ? '<div class="pa-recientes">' + rec + '</div>' : '')
				+ '</section>';
		},
		init: function (r) {
			$$('[data-modo]', r).forEach(function (b) {
				b.onclick = function () {
					var m = b.dataset.modo; if (m !== S.modo) { S.envio.que = ''; } S.modo = m; S.precio = 0; S.cot = null;
					if (m === 'viajar') { S.svc = 'viajar'; S.para = 'destino'; guardar(); ir('destino'); return; }
					S.svc = 'mens'; guardar(); ir(m === 'paquete' ? 'envio' : m);
				};
			});
			$('#desde', r).onclick = function () { S.para = 'origen'; guardar(); ir('destino'); };
			$$('[data-rec]', r).forEach(function (b) { b.onclick = function () { var x = S.recientes[+b.dataset.rec]; S.modo = 'viajar'; S.svc = 'viajar'; S.destino = { texto: x.texto, lat: x.lat, lng: x.lng }; S.precio = 0; S.cot = null; if (S.origen) { S.recAtras = 'inicio'; guardar(); ir('recogida'); } else { guardar(); ir('destino'); } }; });
			if (!S.origen || Date.now() - (S.origenT || 0) > 600000) {
				gps().then(function (p) {
					if (!p) { var d = $('#desde-t'); if (d && !S.origen) { d.textContent = 'Toca para decir dónde estás'; } return; }
					S.origenT = Date.now();
					return lugarDe(p).then(function (l) { S.origen = l; S.cot = null; guardar(); var d = $('#desde-t'); if (d && actual === 'inicio') { d.textContent = corto(l.texto); escenaActual(); pintarCercanos(); } });
				});
			}
			pintarCercanos();
		}
	};
	function pintarCercanos() {
		if (!S.origen) { return; }
		api('cercanos?' + qs({ lat: S.origen.lat, lng: S.origen.lng }), null, 'GET', 2).then(function (x) {
			var pts = (x.data && x.data.puntos) || [];
			cercanosCache = pts.map(function (p, i) { var a = Math.random() * Math.PI * 2; return ['c' + i, U.veh(p.img, 'arriba'), [[p.lng, p.lat], [p.lng + Math.cos(a) * 0.0002, p.lat + Math.sin(a) * 0.0002]], 0]; });
			if (actual === 'inicio') { escenaActual(); }
		});
	}

	// ---------- Destino (y origen, Casa, Trabajo, puntos del envío) ----------
	function campoDe(para) { return { origen: 'origen', 'envio-o': 'origen', destino: 'destino', 'envio-d': 'destino', casa: 'casa', trabajo: 'trabajo' }[para] || 'destino'; }
	function ponerLugar(para, l) {
		var c = campoDe(para);
		if (c === 'casa' || c === 'trabajo') {
			var lug = {}; var ya = (S.yo && S.yo.lugares) || {}; Object.keys(ya).forEach(function (k) { lug[k] = ya[k]; });
			lug[c] = { texto: l.texto, lat: l.lat, lng: l.lng };
			if (S.yo) { S.yo.lugares = lug; LS.set('yo', JSON.stringify(S.yo)); }
			api('yo', { lugares: lug }).then(function (x) { if (x.data.ok) { S.yo = x.data.yo; LS.set('yo', JSON.stringify(S.yo)); U.tostada('Guardado'); } });
			return;
		}
		S[c] = { texto: l.texto, lat: l.lat, lng: l.lng }; if (c === 'origen') { S.origenT = Date.now(); }
		S.precio = 0; S.cot = null; guardar();
	}
	function trasElegir(para) {
		var c = campoDe(para);
		if (c === 'casa' || c === 'trabajo') { ir('perfil'); return; }
		if (para === 'envio-o' || para === 'envio-d') { ir(pantallaEnvio()); return; }
		if (c === 'origen' && !S.destino) { ir('inicio'); return; } // «Desde…» del inicio: cambia el origen y vuelve
		if (!S.origen) { S.para = 'origen'; guardar(); mostrar(true); return; }
		// 10-nov (Ernesto, «como La Nave»): al elegir el destino, se confirma la recogida en el mapa (mueves el mapa y
		// «Recoger aquí»); después, los precios. Si se cambia la recogida desde «Desde…», va directo a los precios.
		if (c === 'destino') { S.recAtras = 'destino'; guardar(); ir('recogida'); return; }
		ir('viajar');
	}
	function pantallaEnvio() { return S.modo === 'comida' || S.modo === 'carga' ? S.modo : 'envio'; }
	SCR.destino = {
		html: function () {
			var c = campoDe(S.para), dos = c === 'origen' || c === 'destino';
			var titulo = { origen: '¿Dónde te recogemos?', destino: S.para === 'envio-d' ? '¿A dónde lo llevamos?' : '¿A dónde vamos?', casa: 'Tu casa', trabajo: 'Tu trabajo' }[c];
			if (S.para === 'envio-o') { titulo = '¿Dónde lo recogemos?'; }
			var atras = S.para.indexOf('envio') === 0 ? pantallaEnvio() : (c === 'casa' || c === 'trabajo' ? 'perfil' : (c === 'origen' && S.destino && S.modo === 'viajar' ? 'viajar' : 'inicio'));
			var campo = function (id, val, aria, foco) { return '<label class="p-campo' + (foco ? ' is-foco' : '') + '"><input id="' + id + '" value="' + esc(val) + '" aria-label="' + aria + '" autocomplete="off" enterkeyhint="search"><button type="button" class="pa-volver" style="width:32px;height:32px" data-borrar="' + id + '" aria-label="Borrar">' + ico('cerrar', 'p-ico--16') + '</button></label>'; };
			return '<div class="pa-llena"><div class="pa-cab">' + volver(atras) + '<h1>' + titulo + '</h1></div>'
				+ '<div class="pa-cuerpo" style="gap:4px">'
				+ (dos ? '<div class="pa-od"><span class="pm-pin pm-pin--yo"></span><span class="pm-pin pm-pin--destino"></span>' + campo('qo', c === 'origen' ? '' : (S.origen ? S.origen.texto : ''), 'Recoger en', c === 'origen') + campo('qd', c === 'destino' ? '' : (S.destino ? S.destino.texto : ''), 'Llevar a', c === 'destino') + '</div>'
					: campo('qd', '', 'Dirección', true))
				+ '<div id="sug" style="margin-top:8px"></div></div>'
				+ '<div class="pa-fijo"><a class="p-fila" href="#fijar"><span class="p-fila__ico">' + ico('mapa') + '</span><span class="p-fila__txt"><b>Fijar en el mapa</b></span></a></div></div>';
		},
		init: function (r) {
			var c = campoDe(S.para), sug = $('#sug', r), q = $(c === 'origen' ? '#qo' : '#qd', r), espera = 0, n = 0, lista = [];
			var cerca = S.origen ? [S.origen.lng, S.origen.lat] : null;
			var resaltar = function (t, x) { var i = t.toLowerCase().indexOf(x.toLowerCase()); return i < 0 || !x ? esc(t) : esc(t.slice(0, i)) + '<em class="pa-resaltar">' + esc(t.slice(i, i + x.length)) + '</em>' + esc(t.slice(i + x.length)); };
			var base = function () {
				lista = [];
				var h = '';
				if (campoDe(S.para) === 'origen' || S.para === 'casa' || S.para === 'trabajo') { lista.push({ gps: true }); h += filaLugar('data-i="0"', 'gps', 'Donde estoy ahora', 'Con el GPS del móvil'); }
				var lug = (S.yo && S.yo.lugares) || {};
				[['casa', 'Casa'], ['trabajo', 'Trabajo']].forEach(function (x) { if (lug[x[0]] && S.para !== x[0]) { lista.push(lug[x[0]]); h += filaLugar('data-i="' + (lista.length - 1) + '"', x[0], x[1], esc(lug[x[0]].texto)); } });
				(S.recientes || []).forEach(function (x) { lista.push(x); h += filaLugar('data-i="' + (lista.length - 1) + '"', 'reloj', esc(corto(x.texto)), esc(x.texto)); });
				sug.innerHTML = h; enganchar();
			};
			var enganchar = function () {
				$$('[data-i]', sug).forEach(function (b) {
					b.onclick = function () {
						var x = lista[+b.dataset.i], para = S.para;
						if (x.gps) { b.disabled = true; gps().then(function (p) { b.disabled = false; if (!p) { U.tostada('Activa la ubicación del móvil', 'alerta'); return; } lugarDe(p).then(function (l) { ponerLugar(para, l); trasElegir(para); }); }); return; }
						ponerLugar(para, { texto: x.texto, lat: x.lat, lng: x.lng }); trasElegir(para);
					};
				});
			};
			var buscar = function () {
				var txt = q.value.trim(), mio = ++n;
				if (txt.length < 2) { base(); return; }
				api('sugerir?' + qs({ texto: txt, lat: cerca ? cerca[1] : '', lng: cerca ? cerca[0] : '' }), null, 'GET', 2).then(function (x) {
					if (mio !== n) { return; }
					lista = (x.data && x.data.sugerencias) || [];
					if (!lista.length) { sug.innerHTML = '<p class="p-aviso">' + ico('info') + (x.status === 0 ? 'Sin señal. Prueba otra vez.' : 'No lo encontramos. Prueba con calle y entrecalles, o fíjalo en el mapa.') + '</p>'; return; }
					sug.innerHTML = lista.map(function (s, i) {
						var d = cerca ? U.km(dist(cerca, [s.lng, s.lat]) / 1000) + ' km' : '';
						return filaLugar('data-i="' + i + '"', s.tipo === 'barrio' ? 'mapa' : 'pin', resaltar(s.texto, txt), esc(s.sub || ''), d);
					}).join('');
					enganchar();
				});
			};
			$$('input', r).forEach(function (inp) {
				inp.addEventListener('focus', function () {
					var nuevo = inp.id === 'qo' ? (S.para === 'envio-o' || S.para === 'envio-d' ? 'envio-o' : 'origen') : (S.para === 'envio-o' || S.para === 'envio-d' ? 'envio-d' : (S.para === 'origen' ? 'destino' : S.para));
					if (nuevo !== S.para) { S.para = nuevo; guardar(); }
					$$('.p-campo', r).forEach(function (l) { l.classList.toggle('is-foco', l.contains(inp)); });
					q = inp; buscar();
				});
				inp.addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(buscar, 250); });
				inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { clearTimeout(espera); buscar(); } });
			});
			$$('[data-borrar]', r).forEach(function (b) { b.onclick = function () { var i = $('#' + b.dataset.borrar, r); i.value = ''; i.focus(); base(); }; });
			base();
			T(function () { q.focus(); }, 150);
		}
	};

	// ---------- Fijar en el mapa (mueves el mapa, no el pin) ----------
	function pinCentro(arriba) { return '<div class="pa-pin-centro" style="top:calc(50% - ' + (arriba || 120) + 'px)"><span class="cabeza"></span><span class="palo"></span><span class="suelo"></span></div>'; }
	function escucharCentro(alSoltar) {
		var m = PMapa.mapa(); if (!m) { return false; }
		var pc = $('.pa-pin-centro'), n = 0, saltando = false;
		var a = function () { if (pc) { pc.classList.add('is-mueve'); } };
		var b = function () {
			if (pc) { pc.classList.remove('is-mueve'); }
			if (saltando) { saltando = false; return; } // fue el salto a la calle: la dirección ya está
			var el = $('.pa-pin-centro'); if (!el) { return; }
			var r = el.getBoundingClientRect(), mr = mapaEl.getBoundingClientRect();
			var punta = [r.left + r.width / 2 - mr.left, r.bottom - mr.top];
			var ll = m.unproject(punta), mio = ++n;
			alSoltar(null);
			lugarDe([ll.lng, ll.lat]).then(function (l) {
				if (mio !== n) { return; }
				// 10-nov (Ernesto): el alfiler no se queda dentro de un edificio: el mapa se mueve para que caiga en la calle.
				if (l.pegado) { var q = m.project([l.lng, l.lat]), dx = q.x - punta[0], dy = q.y - punta[1]; if (Math.abs(dx) > 3 || Math.abs(dy) > 3) { saltando = true; m.panBy([dx, dy], { duration: 300 }); } }
				alSoltar(l);
			});
		};
		m.on('movestart', a); m.on('moveend', b);
		timers.push({ off: function () { m.off('movestart', a); m.off('moveend', b); } });
		return true;
	}
	SCR.fijar = {
		mapa: function () { var p = S.para.indexOf('envio') === 0 || S.para === 'casa' || S.para === 'trabajo' ? (S.origen || null) : (campoDe(S.para) === 'origen' ? S.origen : (S.destino || S.origen)); return { ver: [p ? [p.lng, p.lat] : HABANA], zoom: 16.5, arriba: 0, listo: true }; },
		html: function () {
			var c = campoDe(S.para);
			return '<div class="pa-arriba">' + volverR('destino') + '<span class="pa-pastilla">Mueve el mapa</span></div>'
				+ pinCentro(140)
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div><p class="p-mini" style="margin:0 0 2px">' + (c === 'origen' ? 'Recoger en' : c === 'destino' ? 'Llevar a' : 'Guardar como ' + (c === 'casa' ? 'Casa' : 'Trabajo')) + '</p>'
				+ '<p class="p-h2" id="dir" style="margin:0">Mueve el mapa</p><p class="p-peq" id="barrio" style="margin:2px 0 16px">&nbsp;</p>'
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="aqui" disabled>' + (c === 'origen' ? 'Recoger aquí' : c === 'destino' ? 'Llevar aquí' : 'Guardar aquí') + '</button></section>';
		},
		listo: function (r) {
			var punto = null;
			var hay = escucharCentro(function (l) {
				punto = l;
				$('#aqui', r).disabled = !l;
				$('#dir', r).textContent = l ? corto(l.texto) : 'Buscando la calle…';
				$('#barrio', r).textContent = l ? (l.texto.indexOf(' e/') > 0 ? l.texto.slice(l.texto.indexOf(' e/') + 1) : '') + (l.barrio ? (l.texto.indexOf(' e/') > 0 ? ', ' : '') + l.barrio : '') : '';
			});
			if (!hay) { $('#dir', r).textContent = 'El mapa no cargó'; $('#barrio', r).textContent = 'Escribe la dirección.'; return; }
			PMapa.mapa().fire('moveend'); // el punto de partida también vale sin mover el mapa
			$('#aqui', r).onclick = function () { if (punto) { var para = S.para; ponerLugar(para, punto); trasElegir(para); } };
		}
	};

	// ---------- Recogida ----------
	SCR.recogida = {
		mapa: function () { return { ver: [ptoOrigen()], zoom: 17, arriba: 0, listo: true }; },
		html: function () {
			if (!S.origen || !S.destino) { return ''; }
			return '<div class="pa-arriba">' + volverR(S.recAtras || 'viajar') + '<span class="pa-pastilla">Mueve el mapa</span></div>'
				+ pinCentro(150)
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div><p class="p-h2" style="margin:0">¿Te recogemos aquí?</p>'
				+ '<p class="p-peq" style="margin:2px 0 14px" id="dir">' + esc(S.origen.texto) + '</p>'
				+ '<label class="p-campo" style="margin-bottom:12px">' + ico('nota', 'p-ico--20') + '<input id="nota" maxlength="200" value="' + esc(S.nota) + '" placeholder="Nota para el chofer" aria-label="Nota para el chofer"></label>'
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="ok">Recoger aquí</button>'
				+ '<a class="p-btn p-btn--texto p-btn--ancho" href="#destino" id="otra">Escribir otra dirección</a></section>';
		},
		init: function (r) {
			if (!S.origen || !S.destino) { S.para = S.origen ? 'destino' : 'origen'; ir('destino'); return; }
			$('#otra', r).onclick = function () { S.para = 'origen'; guardar(); };
			$('#nota', r).addEventListener('input', function () { S.nota = this.value; guardar(); });
			$('#ok', r).onclick = function () { S.nota = $('#nota', r).value.trim(); S.recAtras = 'viajar'; guardar(); ir('viajar'); };
		},
		listo: function (r) {
			escucharCentro(function (l) {
				$('#ok', r).disabled = !l;
				if (!l) { $('#dir', r).textContent = 'Buscando la calle…'; return; }
				S.origen = { texto: l.texto + (l.barrio ? ', ' + l.barrio : ''), lat: l.lat, lng: l.lng }; S.cot = null; guardar();
				$('#dir', r).textContent = S.origen.texto;
			});
		}
	};

	// ---------- 10-nov: ¿Quién viaja? (pedir para otra persona; el bot le manda el código por WhatsApp) ----------
	function filaQuien() {
		var p = S.pas || PAS0;
		return '<a class="pa-quien" href="#pasajero">' + ico('usuario', 'p-ico--20') + '<span>' + (p.otro && p.nombre ? 'Viaja <b>' + esc(nombreCorto(p.nombre)) + '</b>' : 'Viajo yo') + '</span><span class="pa-quien__cambiar">Cambiar</span></a>';
	}
	function codigoSubir(v, ch) {
		if (!v.pasajero) { return '<div class="pa-codigo"><span>Código al subir</span><b>' + esc(v.codigo) + '</b></div>'; }
		var n = nombreCorto(v.pasajero.nombre);
		var txt = 'Hola ' + n + ', te pedí un Pa\'llá. Te recoge ' + ((ch && ch.nombre) || 'el chofer') + (ch && ch.chapa ? ' (chapa ' + ch.chapa + ')' : '') + '. Código para subir: ' + v.codigo + '. Pagas al llegar: ' + miles(v.precio) + ' CUP.';
		return '<div class="pa-codigo"><span>Código de ' + esc(n) + '</span><b>' + esc(v.codigo) + '</b><a class="p-btn p-btn--suave p-btn--chico" href="' + waLink(v.pasajero.telefono, txt) + '">' + ico('whatsapp', 'p-ico--16') + 'Mandar</a></div>'
			+ '<p class="p-peq" style="margin:4px 0 0">' + (v.pasajero.avisado ? 'Ya se lo mandamos por WhatsApp.' : 'Mándaselo con el botón.') + ' Paga ' + esc(n) + ' al llegar.</p>';
	}
	SCR.pasajero = {
		html: function () {
			var p = S.pas || PAS0, yo = S.yo || {};
			var contactos = !!(navigator.contacts && navigator.contacts.select);
			return '<div class="pa-llena"><div class="pa-cab">' + volver('viajar') + '<h1>¿Quién viaja?</h1></div><div class="pa-cuerpo">'
				+ '<div class="pa-seg" data-seg="otro"><button type="button" data-v="0" aria-pressed="' + !p.otro + '">Yo' + (yo.nombre ? ' (' + esc(nombreCorto(yo.nombre)) + ')' : '') + '</button><button type="button" data-v="1" aria-pressed="' + !!p.otro + '">Otra persona</button></div>'
				+ '<div id="otroDatos" style="display:' + (p.otro ? '' : 'none') + '">'
				+ '<div><label class="pa-etq" for="pn">Nombre de quien viaja</label><label class="p-campo">' + ico('usuario', 'p-ico--20') + '<input id="pn" maxlength="80" autocomplete="off" value="' + esc(p.nombre) + '"></label></div>'
				+ '<div style="margin-top:12px"><label class="pa-etq" for="pt">Su móvil</label><div class="pa-tel"><span class="pref">+53</span><label class="p-campo"><input id="pt" inputmode="tel" maxlength="12" autocomplete="off" value="' + esc(p.tel) + '"></label></div></div>'
				+ (contactos ? '<button type="button" class="p-btn p-btn--suave p-btn--ancho" id="agenda" style="margin-top:12px">' + ico('personas', 'p-ico--20') + 'Elegir de mis contactos</button>' : '')
				+ '<p class="p-aviso" style="margin-top:12px">' + ico('whatsapp') + '<span>Le mandamos el código por WhatsApp. Paga al llegar.</span></p>'
				+ '</div><p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="listo">Listo</button></div></div>';
		},
		init: function (r) {
			var p = { otro: !!(S.pas && S.pas.otro), nombre: (S.pas && S.pas.nombre) || '', tel: (S.pas && S.pas.tel) || '' };
			engancharSeg(r, function (k, si) { p.otro = si; $('#otroDatos', r).style.display = si ? '' : 'none'; $('#err', r).hidden = true; if (si) { $('#pn', r).focus(); } });
			var ag = $('#agenda', r);
			if (ag) {
				ag.onclick = function () {
					navigator.contacts.select(['name', 'tel'], { multiple: false }).then(function (l) {
						var c = l && l[0]; if (!c) { return; }
						if (c.name && c.name[0]) { $('#pn', r).value = c.name[0]; }
						var t = ((c.tel || [])[0] || '').replace(/\D+/g, ''); if (t.length > 8 && t.slice(-8).charAt(0) === '5') { t = t.slice(-8); }
						if (t) { $('#pt', r).value = t; }
					}).catch(function () { });
				};
			}
			$('#listo', r).onclick = function () {
				if (p.otro) {
					p.nombre = $('#pn', r).value.trim(); p.tel = $('#pt', r).value.replace(/\D+/g, '');
					var t = p.tel.length === 10 && p.tel.indexOf('53') === 0 ? p.tel.slice(2) : p.tel;
					var m = p.nombre.length < 2 ? 'Escribe el nombre de quien viaja.' : (t.length !== 8 || t.charAt(0) !== '5') ? 'Su móvil: 8 números, empieza por 5.' : '';
					if (m) { var err = $('#err', r); err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + m + '</span>'; return; }
					p.tel = t;
				}
				S.pas = p.otro ? p : PAS0; guardar(); ir('viajar');
			};
		}
	};

	// ---------- Tipo y precio ----------
	// m = { grupo: 'viajar' | 'mens', tam, servicio: paquete|comida|carga, ayudante: 0|1 } (v3; las cotizaciones se guardan por clave)
	function claveCot(m) { return [m.grupo, m.tam || '', m.servicio || '', m.ayudante ? 1 : 0, S.origen && S.origen.lat, S.origen && S.origen.lng, S.destino && S.destino.lat, S.destino && S.destino.lng].join('|'); }
	function cotVale(m) { return S.cot && S.cot.k === claveCot(m) && S.cot.vence * 1000 > U.ahora() + 30000; }
	function cotizar(m) {
		if (cotVale(m)) { return Promise.resolve(S.cot); }
		var k = claveCot(m);
		return api('cotizar?' + qs({ grupo: m.grupo, olat: S.origen.lat, olng: S.origen.lng, dlat: S.destino.lat, dlng: S.destino.lng, tam: m.tam || '', servicio: m.servicio || '', ayudante: m.ayudante ? 1 : '' }), null, 'GET', 3).then(function (x) {
			if (!x.data.ok) { return { error: x.status === 0 ? 'No hay señal ahora mismo.' : (x.data.message || 'No se pudo calcular el precio.') }; }
			x.data.k = k; S.cot = x.data; return S.cot;
		});
	}
	// 10-nov: si el precio no llega (red móvil), un botón grande para volver a intentarlo sin salir de la pantalla.
	function errReintentar(el, msg) {
		el.innerHTML = '<div class="pa-reint"><p class="p-aviso p-aviso--error">' + ico('alerta') + '<span>' + esc(msg) + '</span></p>'
			+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" data-reint>Reintentar</button></div>';
		$('[data-reint]', el).onclick = function () { this.disabled = true; this.textContent = 'Probando…'; mostrar(true); };
	}
	function claseCot() { var l = (S.cot && S.cot.clases) || []; return l.filter(function (c) { return c.clase === S.clase; })[0] || l[0] || null; }
	// Ruta SIEMPRE por calles (la calcula el servidor). En Viajar cada clase tiene la suya (taxi por calles de carro, el
	// resto por el camino corto de bici: `lineas`). Si aún no llegó, no se dibuja ninguna línea (nunca una recta): se piden
	// las calles a nuestro motor de rutas y la pantalla se repinta al llegar.
	function rutaCot() {
		var q = claseCot(), bici = q ? q.perfil !== 'driving' : true;
		var lin = S.cot && (S.cot.lineas ? S.cot.lineas[bici ? 'cycling' : 'driving'] : S.cot.linea);
		var c = U.lineaLonLat(lin);
		var marca = bici ? 'pidiendoB' : 'pidiendoC';
		if (!c && S.cot && S.origen && S.destino && !S.cot[marca]) {
			S.cot[marca] = true;
			var cot = S.cot;
			U.ruta([S.origen.lng, S.origen.lat], [S.destino.lng, S.destino.lat], bici).then(function (r) {
				if (!r || cot !== S.cot) { return; }
				var l = r.map(function (p) { return [p[1], p[0]]; });
				if (cot.lineas) { cot.lineas[bici ? 'cycling' : 'driving'] = l; } else { cot.linea = l; }
				if (actual === 'viajar' || actual === 'envio-precio') { escenaActual(); }
			});
		}
		return c;
	}
	function sinRuta(etq) { return { ver: [ptoOrigen(), [S.destino.lng, S.destino.lat]], pins: [['origen', ptoOrigen()], ['destino', [S.destino.lng, S.destino.lat], etq || '']] }; }
	function precioDe(q) { var p = S.precio || q.justo; return Math.min(q.max, Math.max(q.min, p)); }

	// =====================================================================================
	// v3: la línea «precio · pago · llega» + la pista B (se toca el precio y ahí mismo salen − / +) + el botón amarillo.
	// cfg = { q (la clase cotizada: justo, min, max), llega(), boton(p), alPintar(p), alPedir() }. El precio va de 10 en 10
	// entre min y max (los da el servidor: −20 %…+50 %, nunca bajo el mínimo); el botón cambia a la vez.
	// =====================================================================================
	function difTxt(p, j) { return p === j ? 'Precio justo' : (p > j ? '+' : '−') + Math.round(Math.abs(p - j) / j * 100) + ' % · justo ' + miles(j); }
	function bloquePrecio(el, cfg) {
		var abierto = false;
		function pintar() {
			var q = cfg.q, p = precioDe(q), j = q.justo, h;
			if (abierto) {
				h = '<div class="pa-linea is-edita">'
					+ '<button type="button" class="p-btn p-btn--icono pa-pm" id="menos" aria-label="Bajar 10 CUP"' + (p <= q.min ? ' disabled' : '') + '>' + ico('menos') + '</button>'
					+ '<button type="button" class="pa-edita-n" id="listo-pr" aria-live="polite" aria-label="Listo"><b>' + cupG(p) + '</b><span>' + difTxt(p, j) + '</span></button>'
					+ '<button type="button" class="p-btn p-btn--icono pa-pm" id="mas" aria-label="Subir 10 CUP"' + (p >= q.max ? ' disabled' : '') + '>' + ico('mas') + '</button></div>';
			} else {
				var etq = p === j ? 'Precio justo' : 'Tu oferta · ' + (p > j ? '+' : '−') + Math.round(Math.abs(p - j) / j * 100) + ' %';
				h = '<div class="pa-linea">'
					+ '<button type="button" class="pa-celda pa-celda--pr is-toca" id="pr" aria-label="' + cup(p) + '. Toca para ofrecer otro precio"><b>' + cupG(p) + '</b><span>' + etq + ico('mas-menos', 'pa-mm') + '</span></button>'
					+ '<button type="button" class="pa-celda" id="pago"><b>' + nombrePago() + '</b><span>Pago' + ico('abajo', 'pa-mm') + '</span></button>'
					+ '<div class="pa-celda"><b>' + cfg.llega() + '</b><span>Llega</span></div></div>';
			}
			el.innerHTML = h + (S.cot && S.cot.noche ? '<p class="p-aviso" style="justify-content:center;margin:-4px 0 8px">' + ico('luna') + 'Tarifa de noche</p>' : '')
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="pedir">' + cfg.boton(p) + '</button>';
			var x;
			if ((x = $('#pr', el))) { x.onclick = function () { abierto = true; pintar(); }; }
			if ((x = $('#listo-pr', el))) { x.onclick = function () { abierto = false; pintar(); }; }
			if ((x = $('#menos', el))) { x.onclick = function () { poner(precioDe(cfg.q) - 10); }; }
			if ((x = $('#mas', el))) { x.onclick = function () { poner(precioDe(cfg.q) + 10); }; }
			if ((x = $('#pago', el))) { x.onclick = function () { hojaPago(pintar); }; }
			$('#pedir', el).onclick = function () { this.disabled = true; cfg.alPedir(precioDe(cfg.q)); };
			if (cfg.alPintar) { cfg.alPintar(p); }
		}
		function poner(v) { S.precio = Math.min(cfg.q.max, Math.max(cfg.q.min, v)); guardar(); pintar(); }
		pintar();
		// La primera vez, el precio «respira» una vez para que se note que se puede tocar.
		if (!LS.get('pista')) { T(function () { var c = $('#pr', el); if (c) { c.classList.add('is-late'); LS.set('pista', '1'); } }, 900); }
		return { pintar: pintar, cerrar: function () { abierto = false; } };
	}

	// ---------- Viajar: los 7 vehículos 3D con su precio (de más barato a más caro), como La Nave ----------
	SCR.viajar = {
		mapa: function () {
			var c = rutaCot(), q = claseCot(), et = esc(corto(S.destino.texto)) + (q ? ' · ' + q.minutos + ' min' : '');
			if (!c) { return sinRuta(et); }
			return { ruta: c, ver: c, arriba: 60, pins: [['origen', c[0]], ['destino', c[c.length - 1], et]] };
		},
		html: function () {
			if (!S.origen || !S.destino) { return ''; }
			return '<div class="pa-arriba">' + volverR('destino') + '</div>'
				+ '<section class="pa-hoja pa-hoja--viajar"><div class="p-hoja__asa"></div>'
				+ '<div class="pa-od2"><a href="#recogida"><span class="pm-pin pm-pin--yo"></span><span>Desde ' + esc(corto(S.origen.texto)) + '</span></a><a href="#destino" id="a-dest"><span class="pm-pin pm-pin--destino"></span><b>' + esc(corto(S.destino.texto)) + '</b></a></div>'
				+ filaQuien()
				+ '<div class="pa-carr" role="listbox" aria-label="Vehículos" id="carr">' + esqueleto() + '</div>'
				+ '<p class="pa-resumen-l" id="res"></p>'
				+ '<div id="bloque"></div></section>';
		},
		init: function (r) {
			if (!S.origen || !S.destino) { S.para = S.origen ? 'destino' : 'origen'; guardar(); ir('destino'); return; }
			S.modo = 'viajar'; S.svc = 'viajar'; guardar();
			$('#a-dest', r).onclick = function () { S.para = 'destino'; guardar(); };
			cotizar({ grupo: 'viajar' }).then(function (cq) {
				if (actual !== 'viajar') { return; }
				if (cq.error) { errReintentar($('#carr', r), cq.error); return; }
				var l = cq.clases;
				// Sale marcado el último vehículo que usó el cliente (1.ª vez: Triciclo eléctrico), o el que ya tocó.
				if (!l.some(function (c) { return c.clase === S.clase; })) { S.clase = cq.ultima || 'tric_electrico'; S.precio = 0; guardar(); }
				var carr = $('#carr', r);
				carr.innerHTML = l.map(function (c) {
					var k = CLASES[c.clase] || {};
					return '<button type="button" class="pa-v' + (c.llega == null ? ' is-nadie' : '') + '" role="option" data-t="' + c.clase + '" aria-selected="' + (S.clase === c.clase) + '">'
						+ '<img src="' + U.veh(k.img) + '" alt="" width="100" height="60"><b>' + esc(k.nombre) + '</b><span>' + miles(c.justo) + '</span></button>';
				}).join('');
				var cfg = {
					q: claseCot(),
					llega: function () { var q = claseCot(); return q.llega == null ? '—' : q.llega + ' min'; },
					boton: function () { return 'Pedir ' + esc(nombreDe(S.clase)); }, // 10-nov (auditoría): el precio ya sale 2 veces encima
					alPintar: function () { var q = claseCot(), k = CLASES[q.clase] || {}; $('#res', r).textContent = (k.plazas ? k.plazas + (/kg/.test(k.plazas) ? '' : (k.plazas === '1' ? ' plaza' : ' plazas')) + ' · ' : '') + q.minutos + ' min'; }, // 10-nov: el nombre ya está en la tarjeta y «nadie cerca» en «Llega —»
					alPedir: function (p) {
						var q = claseCot();
						pedir({ grupo: 'viajar', nombre: nombreDe(q.clase) }, { cid: cid(), firma: q.firma, precio: p, primera: 0, forma_pago: S.pago, origen_texto: S.origen.texto, destino_texto: S.destino.texto, nota: S.nota, pasajero: S.pas && S.pas.otro ? { nombre: S.pas.nombre, telefono: S.pas.tel } : null });
					}
				};
				var bloque = bloquePrecio($('#bloque', r), cfg);
				var sel = $('[aria-selected="true"]', carr);
				// Solo se desplaza si el elegido no se ve: los más baratos quedan a la vista.
				if (sel && sel.offsetLeft + sel.offsetWidth > carr.clientWidth) { carr.style.scrollBehavior = 'auto'; carr.scrollLeft = sel.offsetLeft - (carr.clientWidth - sel.offsetWidth) / 2; carr.style.scrollBehavior = ''; }
				$$('[data-t]', carr).forEach(function (b) {
					b.onclick = function () {
						var antes = claseCot();
						S.clase = b.dataset.t; S.precio = 0; guardar();
						$$('[data-t]', carr).forEach(function (x) { x.setAttribute('aria-selected', x === b); });
						cfg.q = claseCot(); bloque.cerrar(); bloque.pintar();
						if (antes.perfil !== cfg.q.perfil || antes.minutos !== cfg.q.minutos) { escenaActual(); } // taxi ↔ triciclo: otra ruta
					};
				});
				escenaActual();
			});
		}
	};
	// Las pantallas de la v2 (tipo → precio) quedan como entrada a Viajar (enlaces viejos o móviles con el estado guardado).
	SCR.tipo = SCR.precio = { html: function () { return ''; }, init: function () { T(function () { ir('viajar'); }, 0); } };
	function nombrePago(p) { return (p || S.pago) === 'transferencia' ? 'Transfermóvil' : 'Efectivo'; }
	function hojaPago(alElegir) {
		var op = function (v, i, n) { return '<button type="button" class="p-fila" data-pago="' + v + '"><span class="p-fila__ico">' + ico(i) + '</span><span class="p-fila__txt"><b>' + n + '</b></span>' + (S.pago === v ? ico('check', 'p-ico--20') : '') + '</button>'; };
		U.hojaSobre('<p class="p-h2" style="margin:0 0 8px">¿Cómo pagas?</p>' + op('efectivo', 'efectivo', 'Efectivo') + op('transferencia', 'transfer', 'Transfermóvil'), function (h) {
			$$('[data-pago]', h).forEach(function (b) { b.onclick = function () { S.pago = b.dataset.pago; guardar(); h.remove(); if (alElegir) { alElegir(); } }; });
		});
	}


	// ---------- Pedir (se guarda en el móvil; si no hay red, sale solo al volver) ----------
	var reintento = 0;
	function pedir(meta, body) { LS.set('pend', JSON.stringify({ meta: meta, body: body, t: Date.now() })); enviarPend(); }
	function enviarPend(otraVez) {
		var p = LS.json('pend'); clearTimeout(reintento);
		if (!p || !K) { return; }
		return api('viajes', p.body).then(function (x) {
			if (x.status === 0) { if (actual !== 'sinconexion') { ir('sinconexion'); } reintento = setTimeout(enviarPend, 8000); return; }
			if (x.data.ok) {
				LS.set('pend', null); S.precio = 0; S.pas = PAS0; guardar(); ponerViaje(x.data.viaje); seguir();
				if (actual === 'sinconexion') { var b = $('.pa-banda'); if (b) { b.classList.add('is-bien'); b.innerHTML = ico('check', 'p-ico--20') + '<span>Volvió la señal. Enviamos tu pedido.</span>'; } }
				ir(pantallaDe(x.data.viaje)); return;
			}
			if (x.data.code === 'precio_vencido' && !otraVez && S.origen && S.destino) {
				S.cot = null; // el precio caducó (p. ej. esperando señal): se vuelve a mirar y sale con la misma oferta
				return cotizar(p.meta).then(function (q) {
					var cl = q && !q.error && q.clases.filter(function (c) { return c.clase === S.clase; })[0];
					if (!cl) { LS.set('pend', null); U.tostada('El precio caducó. Vuelve a mirarlo.', 'alerta'); ir('inicio'); return; }
					p.body.firma = cl.firma; p.body.precio = Math.min(cl.max, Math.max(cl.min, p.body.precio)); LS.set('pend', JSON.stringify(p)); return enviarPend(true);
				});
			}
			LS.set('pend', null);
			U.tostada(x.data.message || 'No se pudo pedir.', 'alerta');
			if (x.data.code === 'ya_tienes') { cargarYo(); } else { mostrar(true); }
		});
	}
	window.addEventListener('online', function () { enviarPend(); });

	SCR.sinconexion = {
		mapa: function () { return { offline: true }; },
		html: function () {
			var p = LS.json('pend'), b = p && p.body, lug = (S.yo && S.yo.lugares) || {};
			var pal = p ? (p.meta.nombre || PALABRA[p.meta.grupo] || 'vehículo') : '';
			var que = b ? (b.envio ? 'Envío a ' + corto(b.destino_texto) : pal.charAt(0).toUpperCase() + pal.slice(1) + ' a ' + corto(b.destino_texto)) : '';
			var txt = b ? 'Hola, quiero un ' + (b.envio ? 'mensajero' : pal.toLowerCase()) + ' de ' + b.origen_texto + ' a ' + b.destino_texto + ' por ' + cup(b.precio) + '.' : '';
			return '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
				+ (b ? filaLugar('disabled', 'reloj', esc(que), cup(b.precio) + ' · guardado', '') : '')
				+ (C.telefono ? '<div class="pa-dos"><a class="p-btn p-btn--suave p-btn--chico" href="' + waLink(C.telefono, txt) + '">' + ico('whatsapp', 'p-ico--20') + 'Por WhatsApp</a><a class="p-btn p-btn--suave p-btn--chico" href="tel:+' + U.tel53(C.telefono) + '">' + ico('telefono', 'p-ico--20') + 'Llamar</a></div>' : '')
				+ '<p class="pa-etq">En tu móvil</p>'
				+ (lug.casa ? filaLugar('data-ir="inicio"', 'casa', 'Casa', esc(lug.casa.texto)) : '') + (lug.trabajo ? filaLugar('data-ir="inicio"', 'trabajo', 'Trabajo', esc(lug.trabajo.texto)) : '')
				+ filaLugar('data-ir="viajes"', 'lista', 'Mis viajes', 'Los últimos')
				+ (b ? '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="borrar">No pedir</button>' : '')
				+ '</section>';
		},
		init: function (r) {
			U.sinRed(true); var s = $('.pa-banda span'); if (s) { s.textContent = 'Sin señal. Tu pedido sale solo cuando vuelva.'; }
			$$('[data-ir]', r).forEach(function (b) { b.onclick = function () { ir(b.dataset.ir); }; });
			var x = $('#borrar', r); if (x) { x.onclick = function () { LS.set('pend', null); clearTimeout(reintento); ir('inicio'); }; }
			if (!LS.json('pend')) { T(function () { ir('inicio'); }, 1500); }
		}
	};

	// ---------- Ofertas en vivo (viaje y envío) ----------
	function caraO(ch) { return ch && ch.foto ? '<img class="p-cara p-oferta__cara" src="' + esc(ch.foto) + '" alt="" style="object-fit:cover">' : '<span class="p-cara p-oferta__cara">' + esc(U.iniciales(ch && ch.nombre)) + '</span>'; }
	function deslizar(el, alQuitar) {
		var x0 = null, dx = 0;
		el.addEventListener('pointerdown', function (e) { if (e.target.closest('button')) { return; } x0 = e.clientX; dx = 0; try { el.setPointerCapture(e.pointerId); } catch (er) { } el.style.transition = 'none'; });
		el.addEventListener('pointermove', function (e) { if (x0 === null) { return; } dx = e.clientX - x0; el.style.transform = 'translateX(' + dx + 'px)'; el.style.opacity = 1 - Math.min(1, Math.abs(dx) / 240); });
		var fin = function () { if (x0 === null) { return; } x0 = null; el.style.transition = 'transform 180ms, opacity 180ms'; if (Math.abs(dx) > 90) { el.style.transform = 'translateX(' + (dx > 0 ? 420 : -420) + 'px)'; el.style.opacity = 0; setTimeout(alQuitar, 180); } else { el.style.transform = ''; el.style.opacity = ''; } };
		el.addEventListener('pointerup', fin); el.addEventListener('pointercancel', fin);
	}
	SCR.buscando = {
		viaje: true,
		mapa: function () { var v = S.viaje, p = [v.origen.lng, v.origen.lat]; return { ver: [p], zoom: 15.2, pins: [['origen', p]], conducir: cercanosCache }; },
		html: function () {
			var v = S.viaje;
			return '<div class="pa-radar" aria-hidden="true"><i></i><i></i><i></i></div>'
				+ '<div class="pa-arriba"><button type="button" class="pa-pastilla" id="cancelar">' + ico('cerrar', 'p-ico--16') + 'Cancelar</button></div>'
				+ '<section class="pa-hoja" style="max-height:74%"><div class="p-hoja__asa"></div>'
				+ '<div class="pa-tuya"><div><p class="p-mini" style="margin:0 0 4px">Tu oferta · ' + esc(nombreServ(v)) + '</p><b id="tuya">' + cup(v.oferta) + '</b></div><button type="button" class="p-btn p-btn--suave p-btn--chico" id="subir">' + ico('mas', 'p-ico--16') + '10 %</button></div>'
				// v3: «Coger la 1.ª oferta» vive aquí (donde importa). Al encenderlo, si ya hay un «Acepto», se coge al momento.
				+ '<div class="pa-op" style="border-top:0;border-bottom:1px solid var(--linea)"><b>Aceptar al primero</b><button type="button" class="p-interruptor" role="switch" aria-checked="' + !!v.primera + '" id="primera" aria-label="Coger la primera oferta que acepte mi precio"></button></div>'
				+ '<div class="pa-ofertas" id="ofertas"></div><div id="esq">' + esqueleto() + esqueleto() + '</div></section>';
		},
		init: function (r) {
			var v = S.viaje;
			$('#primera', r).onclick = function () {
				var b = this, on = b.getAttribute('aria-checked') !== 'true';
				b.setAttribute('aria-checked', on); b.disabled = true;
				api('viajes/' + S.viaje.uuid + '/primera', { on: on ? 1 : 0 }).then(function (x) {
					b.disabled = false;
					if (!x.data.ok) { b.setAttribute('aria-checked', !on); U.tostada(x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.'), 'alerta'); return; }
					cambio(x.data.viaje);
				});
			};
			api('cercanos?' + qs({ lat: v.origen.lat, lng: v.origen.lng, grupo: grupoDe(v.clase) })).then(function (x) {
				cercanosCache = ((x.data && x.data.puntos) || []).map(function (p, i) { var a = Math.random() * 6.28; return ['c' + i, U.veh(p.img, 'arriba'), [[p.lng, p.lat], [p.lng + Math.cos(a) * 0.0002, p.lat + Math.sin(a) * 0.0002]], 0]; });
				if (actual === 'buscando') { escenaActual(); }
			});
			$('#cancelar', r).onclick = function () { cancelar(this); };
			$('#subir', r).onclick = function () {
				var b = this, w = S.viaje, nuevo = Math.min(w.max, arriba10(w.oferta * 1.1));
				if (nuevo <= w.oferta) { U.tostada('Ya está en el máximo', 'info'); return; }
				b.disabled = true;
				api('viajes/' + w.uuid + '/precio', { precio: nuevo }).then(function (x) { b.disabled = false; if (x.data.ok) { cambio(x.data.viaje); U.tostada('Subiste a ' + cup(x.data.viaje.oferta), 'flecha'); } else { U.tostada(x.data.message || 'No se pudo.', 'alerta'); } });
			};
			I(function () {
				$$('.p-oferta', r).forEach(function (el) {
					var s = U.seg(el.dataset.vence); $('.p-oferta__tiempo', el).style.transform = 'scaleX(' + Math.max(0, Math.min(1, s / 60)).toFixed(3) + ')';
					if (s <= 0) { el.remove(); marcarMejor(r); }
				});
			}, 1000);
			this.actualizar(r, v);
		},
		actualizar: function (r, v) {
			$('#tuya', r).textContent = cup(v.oferta);
			var pri = $('#primera', r); if (pri && !pri.disabled) { pri.setAttribute('aria-checked', !!v.primera); }
			$('#subir', r).hidden = v.oferta >= v.max;
			var cont = $('#ofertas', r), ids = {};
			(v.ofertas || []).forEach(function (o) {
				ids[o.id] = 1;
				if (S.ocultas[o.id] || $('[data-id="' + o.id + '"]', cont)) { return; }
				var el = document.createElement('article');
				el.className = 'p-oferta'; el.dataset.id = o.id; el.dataset.p = o.precio; el.dataset.m = o.llega; el.dataset.vence = o.vence;
				el.innerHTML = caraO(o.chofer)
					+ '<div class="p-oferta__q">' + esc(o.chofer.nombre) + ' ' + U.estr(o.chofer.nota) + '</div>'
					+ '<div class="p-oferta__precio">' + miles(o.precio) + (o.pct ? '<small>+' + o.pct + ' %</small>' : '') + '</div>'
					+ '<div class="p-oferta__s">' + esc(o.chofer.tipo) + (o.chofer.color ? ' ' + esc(o.chofer.color.toLowerCase()) : '') + ' · ' + o.llega + ' min</div>'
					+ '<button type="button" class="p-btn p-btn--suave" data-si>Aceptar</button>'
					+ '<i class="p-oferta__tiempo" aria-hidden="true" style="transform:scaleX(' + Math.max(0, Math.min(1, U.seg(o.vence) / 60)).toFixed(3) + ')"></i>';
				cont.insertBefore(el, cont.firstChild);
				vibrar(40);
				deslizar(el, function () { S.ocultas[o.id] = 1; el.remove(); marcarMejor(r); });
				$('[data-si]', el).onclick = function () {
					var b = this; b.disabled = true;
					api('viajes/' + v.uuid + '/elegir', { oferta: o.id }).then(function (x) {
						if (x.data.viaje) { cambio(x.data.viaje); }
						if (!x.data.ok) { b.disabled = false; U.tostada(x.data.message || 'Esa oferta ya no vale.', 'alerta'); }
					});
				};
			});
			$$('.p-oferta', cont).forEach(function (el) { if (!ids[el.dataset.id]) { el.remove(); } });
			marcarMejor(r);
			var n = $$('.p-oferta', cont).length, esq = $('#esq', r);
			if (v.estado === 'sin_chofer') {
				var mas = Math.min(v.max, arriba10(v.oferta * 1.1));
				esq.innerHTML = '<p class="p-aviso" style="margin:12px 0">' + ico('info') + 'Nadie aceptó todavía.</p>'
					+ (mas > v.oferta ? '<button type="button" class="p-btn p-btn--marca p-btn--ancho" data-otra="' + mas + '">Subir a ' + miles(mas) + '</button>' : '')
					+ '<button type="button" class="p-btn p-btn--texto p-btn--ancho" data-otra="' + v.oferta + '">Buscar otra vez</button>';
				$$('[data-otra]', esq).forEach(function (b) { b.onclick = function () { b.disabled = true; api('viajes/' + v.uuid + '/precio', { precio: +b.dataset.otra }).then(function (x) { if (x.data.ok) { cambio(x.data.viaje); } else { b.disabled = false; U.tostada(x.data.message || 'No se pudo.', 'alerta'); } }); }; });
			} else {
				var faltan = n >= 3 ? 0 : (n >= 2 ? 1 : 2); // esqueletos mientras llegan ofertas
				if (esq.children.length !== faltan || $$('.pa-esq-oferta', esq).length !== faltan) { esq.innerHTML = faltan === 2 ? esqueleto() + esqueleto() : (faltan ? esqueleto() : ''); }
			}
			var radar = $('.pa-radar', r); if (radar) { radar.hidden = v.estado === 'sin_chofer'; }
		}
	};
	// El amarillo solo en la mejor oferta (la más barata; si empatan, la que llega antes). Las demás, gris.
	function marcarMejor(r) {
		var arts = $$('.p-oferta', r), mejor = null;
		arts.forEach(function (a) { if (!mejor || +a.dataset.p < +mejor.dataset.p || (+a.dataset.p === +mejor.dataset.p && +a.dataset.m < +mejor.dataset.m)) { mejor = a; } });
		arts.forEach(function (a) { $('[data-si]', a).className = 'p-btn ' + (a === mejor ? 'p-btn--marca' : 'p-btn--suave'); });
	}
	function cancelar(b) {
		U.hojaSobre('<p class="p-h2" style="margin:0 0 4px">¿Cancelar?</p><p class="p-peq" style="margin:0 0 16px">' + (S.viaje && S.viaje.chofer ? 'Tu chofer ya va en camino.' : 'Dejamos de buscar.') + '</p>'
			+ '<button type="button" class="p-btn p-btn--tinta p-btn--ancho" id="si">Sí, cancelar</button><button type="button" class="p-btn p-btn--texto p-btn--ancho" data-cerrar>No</button>', function (h) {
			$('#si', h).onclick = function () {
				this.disabled = true;
				api('viajes/' + S.viaje.uuid + '/cancelar', { motivo: '' }).then(function (x) {
					h.remove();
					if (x.data.ok) { ponerViaje(null); dejar(); U.tostada('Cancelado'); ir('inicio'); return; }
					U.tostada(x.data.message || 'No se pudo.', 'alerta'); refrescar();
				});
			};
		});
	}

	// ---------- El chofer viene / en viaje / envío ----------
	var tramo = null; // { clave, c:[[lon,lat]…], largo }
	// La ruta del tramo (chofer → recogida, o recogida → destino) la manda el SERVIDOR, por calles (`viene` / `linea`);
	// si el chofer se desvía, el servidor manda otra y aquí se cambia. Nunca se dibuja una recta: sin ruta, solo los puntos.
	function firmaLinea(c) { return c ? c.length + ':' + c[0][0].toFixed(4) + ',' + c[0][1].toFixed(4) : ''; }
	function deEsteTramo(v) { return !!tramo && tramo.clave.indexOf(v.uuid + (v.estado === 'recogido' ? 'r' : 'a') + '|') === 0; }
	function prepararTramo(v) {
		var rec = v.estado === 'recogido', base = v.uuid + (rec ? 'r' : 'a') + '|';
		var srv = U.lineaLonLat(rec ? v.linea : v.viene);
		if (srv) {
			if (!tramo || tramo.clave !== base + firmaLinea(srv)) { tramo = { clave: base + firmaLinea(srv), c: srv, largo: largo(srv) }; }
			return Promise.resolve(tramo);
		}
		if (deEsteTramo(v)) { return Promise.resolve(tramo); }
		var a = rec ? U.P(v.origen) : (v.pos ? [v.pos.lng, v.pos.lat] : null), b = rec ? U.P(v.destino) : U.P(v.origen);
		if (!a) { return Promise.resolve(null); }
		return U.ruta(a, b, grupoDe(v.clase) !== 'taxi').then(function (c) {
			if (c && c.length > 1 && !deEsteTramo(v)) { tramo = { clave: base + firmaLinea(c), c: c, largo: largo(c) }; }
			return deEsteTramo(v) ? tramo : null;
		});
	}
	function escenaTramo(v) {
		var rec = v.estado === 'recogido', img = U.veh(imgDe(v.clase), 'arriba');
		var fin = rec ? [v.destino.lng, v.destino.lat] : [v.origen.lng, v.origen.lat];
		var et = rec ? esc(corto(v.destino.texto)) : (esEnvio(v) ? 'Recogida' : (v.pasajero ? esc(nombreCorto(v.pasajero.nombre)) : 'Tú'));
		if (!deEsteTramo(v)) {
			var pins = [[rec ? 'destino' : 'origen', fin, et]], cond = [];
			if (v.pos) { cond.push(['chofer', img, [[v.pos.lng, v.pos.lat], [v.pos.lng + 0.0001, v.pos.lat]], 0]); }
			return { ver: v.pos ? [fin, [v.pos.lng, v.pos.lat]] : [fin], zoom: v.pos ? 0 : 16, pins: pins, conducir: cond };
		}
		return { ver: tramo.c, ruta: tramo.c, rutaQuieta: true, pins: [[rec ? 'destino' : 'origen', fin, et]], conducir: [['chofer', img, tramo.c, kDe(v)]] };
	}
	function kDe(v) {
		if (v.estado === 'llego') { return 1; }
		if (!deEsteTramo(v)) { return 0; }
		if (!v.pos) { return 0; }
		return PMapa.avance(tramo.c, [v.pos.lng, v.pos.lat]);
	}
	function minutosResto(v, k) { return deEsteTramo(v) ? Math.max(0, Math.ceil(tramo.largo * (1 - k) / (VEL[grupoDe(v.clase)] || 250))) : (v.estado === 'recogido' ? v.minutos : null); }
	function moverBarra(r, v) {
		var k = kDe(v), m = minutosResto(v, k);
		U.pintarBarra(r, k, 'chofer');
		var eta = $('#eta', r), llega = $('[data-llega]', r);
		if (v.estado === 'llego' || !eta) { return; }
		// 10-nov (auditoría): minutos, nunca la hora del reloj (el del móvil suele estar mal y confunde).
		if (v.estado === 'recogido') { if (m != null) { eta.textContent = (esEnvio(v) ? 'Llega en ' : 'Llegas en ') + Math.max(1, m) + ' min'; if (llega) { llega.textContent = U.km(v.km) + ' km'; } } return; }
		eta.textContent = m == null ? 'Viene hacia ti' : (m <= 0 ? 'Llegando' : (esEnvio(v) ? 'Recoge en ' : 'Llega en ') + m + ' min');
		if (llega) { llega.textContent = ''; }
	}
	function filaChofer(ch, sub) {
		var tel = ch.telefono ? U.tel53(ch.telefono) : '';
		return '<div class="pa-chofer">' + U.cara(ch) + '<div class="pa-chofer__q"><b>' + esc(ch.nombre) + ' ' + U.estr(ch.nota) + '</b><span>' + sub + '</span></div>'
			+ (tel ? '<a class="p-btn p-btn--icono" href="tel:+' + tel + '" aria-label="Llamar">' + ico('telefono', 'p-ico--20') + '</a><a class="p-btn p-btn--icono" href="' + waLink(tel) + '" aria-label="Mensaje por WhatsApp">' + ico('whatsapp', 'p-ico--20') + '</a>' : '') + '</div>';
	}
	function subChofer(ch, v) { return esc(ch.tipo) + (ch.color ? ' ' + esc(ch.color.toLowerCase()) : '') + (ch.chapa ? ' · ' + esc(ch.chapa) : '') + (ch.lot_ok && grupoDe(v.clase) === 'taxi' ? ' · LOT al día' : ''); }
	function hojaAyuda() {
		U.hojaSobre('<p class="p-h2" style="margin:0 0 8px">¿Necesitas ayuda?</p>'
			+ '<a class="p-fila" href="tel:106"><span class="p-fila__ico" style="color:var(--error)">' + ico('telefono') + '</span><span class="p-fila__txt"><b>Policía (106)</b><span>Si estás en peligro</span></span></a>'
			+ '<button type="button" class="p-fila" id="comp2"><span class="p-fila__ico">' + ico('compartir') + '</span><span class="p-fila__txt"><b>Avisar a alguien</b><span>Le mandas quién te lleva</span></span></button>'
			+ (C.telefono ? '<a class="p-fila" href="' + waLink(C.telefono, 'Hola, necesito ayuda con mi viaje de Pa\'llá.') + '"><span class="p-fila__ico">' + ico('whatsapp') + '</span><span class="p-fila__txt"><b>Hablar con Pa\'llá</b><span>Te contesta una persona</span></span></a>' : ''), function (h) {
			$('#comp2', h).onclick = function () { h.remove(); compartir(); };
		});
	}
	function compartir() {
		var v = S.viaje; if (!v || !v.chofer) { return; }
		var ch = v.chofer, txt = 'Voy en Pa\'llá con ' + ch.nombre + ' (' + ch.tipo + (ch.color ? ' ' + ch.color.toLowerCase() : '') + (ch.chapa ? ', chapa ' + ch.chapa : '') + ')' + (ch.telefono ? ', tel. +' + U.tel53(ch.telefono) : '') + '. De ' + corto(v.origen.texto) + ' a ' + corto(v.destino.texto) + '.';
		if (navigator.share) { navigator.share({ text: txt }).catch(function () { }); return; }
		location.href = 'https://wa.me/?text=' + encodeURIComponent(txt);
	}
	function arribaViaje(atras) {
		return '<div class="pa-arriba">' + (atras ? volverR(atras) : '<span></span>')
			+ '<span class="pa-arriba__der"><button type="button" class="pa-pastilla" id="comp">' + ico('compartir', 'p-ico--16') + 'Compartir</button><button type="button" class="pa-pastilla pa-ayuda" id="ayuda">' + ico('escudo', 'p-ico--16') + 'Ayuda</button></span></div>';
	}
	function engancharViaje(r) { $('#ayuda', r).onclick = hojaAyuda; $('#comp', r).onclick = compartir; }
	function eta(mini, grande, precioN, sub) {
		return '<div class="pa-eta"><div><p class="p-mini" id="eta-mini">' + mini + '</p><b id="eta">' + grande + '</b></div><div><b class="pa-eta__pr">' + cup(precioN) + '</b><span id="eta-sub">' + sub + '</span></div></div>';
	}
	function espera(v) { // 5 min gratis desde «Llegué»; luego 50 CUP por minuto empezado (como el servidor)
		var s = Math.max(0, (U.ahora() - Date.parse(v.llego)) / 1000);
		return s < 300 ? { gratis: 300 - s, cup: 0 } : { gratis: 0, cup: Math.ceil((s - 300) / 60) * 50 };
	}
	function pintarEspera(r, v) {
		if (v.estado !== 'llego' || !v.llego) { return; }
		var e = espera(v), mm = Math.floor(e.gratis / 60), ss = Math.floor(e.gratis % 60);
		$('#eta', r).textContent = e.gratis ? 'Espera gratis ' + mm + ':' + String(ss).padStart(2, '0') : 'Espera +' + cup(e.cup);
		$('.pa-eta__pr', r).textContent = cup(v.precio + e.cup);
		$('#eta-sub', r).textContent = e.cup ? 'Precio + espera' : 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase();
	}
	function pantallaViaje(paso) { // paso: 'viene' | 'viaje' | 'envio'
		return {
			viaje: true,
			mapa: function () { return escenaTramo(S.viaje); },
			html: function () {
				var v = S.viaje, ch = v.chofer || { nombre: 'Tu chofer' }, g = grupoDe(v.clase), llego = v.estado === 'llego';
				if (paso === 'envio') {
					var e = v.envio || {}, rec = v.estado === 'recogido', sv = servDe(v), et = ETAPAS[sv] || ETAPAS.paquete;
					var yo = U.tel53(e.telefono) === U.tel53((S.yo && S.yo.telefono) || ''); // Comida y Carga: casi siempre recibe el mismo cliente
					var txtWa = 'Hola ' + nombreCorto(e.recibe) + ', te mandé ' + e.que + ' con Pa\'llá. Te lo lleva ' + ch.nombre + '. Cuando llegue, dale este código: ' + e.codigo + '.';
					var antes = sv === 'comida' && !e.adelanto ? 'Recogiendo' : (sv === 'comida' ? 'Pagando' : (sv === 'carga' ? 'Cargando' : 'Recogiendo'));
					var recibo = sv === 'comida' && e.adelanto ? '<p class="p-aviso pa-aviso-env" style="margin:14px 0 0">' + ico('camara') + '<span>' + (e.recibo ? esc(nombreCorto(ch.nombre)) + ' pagó <b>' + cup(e.adelanto) + '</b> · <a href="' + esc(e.recibo) + '" target="_blank" rel="noopener">foto del recibo</a>' : esc(nombreCorto(ch.nombre)) + ' paga <b>' + cup(e.adelanto) + '</b> al recoger y te manda la foto del recibo') + '</span></p>' : '';
					return arribaViaje('inicio') + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
						+ '<ol class="pa-etapas" aria-label="Estado del envío"><li class="' + (rec ? 'is-hecho' : 'is-ahora') + '">' + (rec ? et[0] : antes) + '</li><li class="' + (rec ? 'is-ahora' : '') + '">' + et[1] + '</li><li>' + et[2] + '</li></ol>'
						+ eta(esc(e.que) + ' · ' + (yo ? 'para ti' : 'para ' + esc(nombreCorto(e.recibe))), llego ? (sv === 'carga' ? 'Cargando' : 'Recogiendo') : 'Calculando…', v.total, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
						+ U.barra(imgServ(v), '')
						+ recibo
						+ filaChofer(ch, subChofer(ch, v))
						+ '<div class="pa-codigo"><span>' + (yo ? 'Tu código' : 'Código de ' + esc(nombreCorto(e.recibe))) + '</span><b>' + esc(e.codigo) + '</b>' + (yo ? '' : '<a class="p-btn p-btn--suave p-btn--chico" href="' + waLink(e.telefono, txtWa) + '">' + ico('whatsapp', 'p-ico--16') + 'Mandar</a>') + '</div></section>';
				}
				if (paso === 'viene') {
					return arribaViaje() + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
						+ eta(v.pasajero ? (llego ? 'El chofer llegó por ' + esc(nombreCorto(v.pasajero.nombre)) : 'Va a recoger a ' + esc(nombreCorto(v.pasajero.nombre))) : (llego ? 'Tu chofer llegó' : 'Tu ' + PALABRA[g] + ' viene'), llego ? '' : 'Calculando…', v.precio, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
						+ U.barra(imgDe(v.clase), '')
						+ filaChofer(ch, subChofer(ch, v))
						+ codigoSubir(v, ch)
						+ '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="cancelar">Cancelar</button></section>';
				}
				return arribaViaje() + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
					+ eta('Camino a ' + esc(corto(v.destino.texto)), 'Llegas en ' + Math.max(1, v.minutos) + ' min', v.total, v.espera ? 'Incluye ' + cup(v.espera) + ' de espera' : 'Fijo')
					+ '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="yallegue">Ya llegué</button>'
					+ U.barra(imgDe(v.clase), U.km(v.km) + ' km')
					+ filaChofer(ch, subChofer(ch, v)) + '</section>';
			},
			init: function (r) {
				engancharViaje(r);
				var yl = $('#yallegue', r); // 10-nov (auditoría): si el chofer no toca «Terminé», el cliente cierra el viaje
				if (yl) { yl.onclick = function () { var b = this; b.disabled = true; api('viajes/' + S.viaje.uuid + '/llegue', {}).then(function (x) { b.disabled = false; if (x.data.viaje) { cambio(x.data.viaje); } else { U.tostada(x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.'), 'alerta'); } }); }; }
				var c = $('#cancelar', r); if (c) { c.onclick = function () { cancelar(this); }; }
				var self = this;
				prepararTramo(S.viaje).then(function () { if (actual && SCR[actual] === self && S.viaje) { escenaActual(); moverBarra(r, S.viaje); } });
				moverBarra(r, S.viaje); pintarEspera(r, S.viaje);
				I(function () { if (S.viaje) { pintarEspera(r, S.viaje); } }, 1000);
			},
			actualizar: function (r, v) {
				var antes = tramo && tramo.clave;
				if (paso === 'envio' && $$('.pa-etapas li', r)[0].className !== (v.estado === 'recogido' ? 'is-hecho' : 'is-ahora')) { mostrar(true); return; }
				if (paso === 'envio' && v.envio && v.envio.adelanto && !!v.envio.recibo !== !!$('.pa-aviso-env a', r)) { mostrar(true); return; } // llegó la foto del recibo
				if (paso === 'viene' && !!$('#eta-mini', r).textContent.match(/llegó/) !== (v.estado === 'llego')) { mostrar(true); return; }
				prepararTramo(v).then(function () { if (tramo && tramo.clave !== antes) { escenaActual(); } moverBarra(r, v); });
			},
			alPos: function (r, v) { var antes = tramo && tramo.clave; prepararTramo(v).then(function () { if (tramo && tramo.clave !== antes) { escenaActual(); } moverBarra(r, v); }); }
		};
	}
	SCR.viene = pantallaViaje('viene');
	SCR.viaje = pantallaViaje('viaje');
	SCR['envio-seguimiento'] = pantallaViaje('envio');

	// ---------- Llegada / Entregado: pagar y valorar ----------
	function bloquePago(v) {
		var ch = v.chofer || {}, q = esc(nombreCorto(ch.nombre)), tr = ch.transferencia || '';
		return '<div class="pa-seg" role="group" aria-label="Forma de pago"><button type="button" data-pg="efectivo" aria-pressed="' + (S.pagoFin !== 'transferencia') + '">Efectivo</button><button type="button" data-pg="transferencia" aria-pressed="' + (S.pagoFin === 'transferencia') + '">Transfermóvil</button></div>'
			+ '<div id="tm" class="pa-tm" style="display:' + (S.pagoFin === 'transferencia' ? 'flex' : 'none') + '"><div><span class="p-peq">' + (tr ? 'Tarjeta de ' + q : 'Pídele a ' + q + ' su tarjeta') + '</span><b class="p-num">' + esc(tr || '—') + '</b></div>' + (tr ? '<button type="button" class="p-btn p-btn--suave p-btn--chico" id="copiar">' + ico('copiar', 'p-ico--16') + 'Copiar</button>' : '') + '</div>';
	}
	function engancharPago(r, v) {
		$$('[data-pg]', r).forEach(function (b) { b.onclick = function () { S.pagoFin = b.dataset.pg; $$('[data-pg]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); $('#tm', r).style.display = S.pagoFin === 'transferencia' ? 'flex' : 'none'; }; });
		var c = $('#copiar', r);
		if (c) { c.onclick = function () { var t = (v.chofer.transferencia || '').replace(/\s+/g, ''); (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { U.tostada('Número copiado'); }, function () { U.tostada(t, 'info'); }); }; }
	}
	function monto(mini, n) { return '<div class="pa-monto"><p class="p-mini">' + mini + '</p><span class="p-precio">' + cupG(n) + '</span></div>'; }
	function valorarYSalir(r, v, b) {
		var fin = function () { ponerViaje(null); dejar(); U.tostada('Gracias. Viaje guardado.'); ir('viajes'); };
		if (!S.estrellas || v.valorado) { fin(); return; }
		var etq = $$('[data-etq][aria-pressed="true"]', r).map(function (x) { return x.dataset.etq; });
		var pr = $('[data-prop][aria-pressed="true"]', r);
		b.disabled = true;
		api('viajes/' + v.uuid + '/valorar', { estrellas: S.estrellas, etiquetas: etq, propina: pr ? +pr.dataset.prop : 0 }).then(function (x) { if (x.status === 0) { b.disabled = false; U.tostada('Sin señal. Prueba otra vez.', 'alerta'); return; } S.estrellas = 0; fin(); });
	}
	SCR.llegada = {
		viaje: true,
		html: function () {
			var v = S.viaje, ch = v.chofer || {}, q = esc(nombreCorto(ch.nombre));
			S.pagoFin = S.pagoFin || v.forma_pago;
			return '<div class="pa-llena"><div class="pa-cuerpo" style="padding-top:32px">'
				+ '<div class="pa-hecho">' + ico('check', 'p-ico--20') + '<div><p class="p-h2" style="margin:0">' + (v.pasajero ? esc(nombreCorto(v.pasajero.nombre)) + ' llegó a ' : 'Llegaste a ') + esc(corto(v.destino.texto)) + '</p><p class="p-peq" style="margin:0">' + (v.terminado ? U.hhmm(new Date(v.terminado)) : U.hora(0)) + ' · ' + U.km(v.km) + ' km con ' + esc(ch.nombre || '') + '</p></div></div>'
				+ monto((v.pasajero ? 'Paga ' + esc(nombreCorto(v.pasajero.nombre)) + ' a ' : 'A pagar a ') + q + (v.espera ? ' · ' + cup(v.precio) + ' + ' + cup(v.espera) + ' de espera' : ''), v.total)
				+ bloquePago(v)
				+ (v.valorado ? '' : '<div><p class="pa-etq" style="text-align:center">¿Qué tal el viaje?</p>' + U.estrellas(S.estrellas) + '</div>'
					+ '<div id="tras" class="pa-tras"' + (S.estrellas ? '' : ' hidden') + '><div class="pa-chips" style="justify-content:center"><button type="button" class="p-chip" data-etq="puntual">Puntual</button><button type="button" class="p-chip" data-etq="amable">Amable</button><button type="button" class="p-chip" data-etq="buen_vehiculo">Buen vehículo</button><button type="button" class="p-chip" data-etq="manejo_bien">Manejó bien</button></div>'
					+ '</div>') // 10-nov (auditoría): sin propina (el chofer nunca la veía)
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="listo">Listo</button></div></div>';
		},
		init: function (r) {
			var v = S.viaje, tras = $('#tras', r);
			engancharPago(r, v);
			U.engancharEstrellas(r, function (n) { S.estrellas = n; if (tras) { tras.hidden = false; } });
			$$('[data-etq]', r).forEach(function (c) { c.onclick = function () { c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') !== 'true'); }; });
			$$('[data-prop]', r).forEach(function (c) { c.onclick = function () { $$('[data-prop]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === c); }); }; });
			$('#listo', r).onclick = function () { valorarYSalir(r, v, this); };
		}
	};
	SCR['envio-entregado'] = {
		viaje: true,
		html: function () {
			var v = S.viaje, ch = v.chofer || {}, e = v.envio || {}, sv = servDe(v);
			var yo = U.tel53(e.telefono) === U.tel53((S.yo && S.yo.telefono) || '');
			// v3 Comida: si el mensajero adelantó la comida, en la puerta se paga la comida + el envío (un solo número).
			var comida = sv === 'comida' && e.cobrar && yo, total = v.total + (comida ? e.cobrar : 0);
			S.pagoFin = S.pagoFin || v.forma_pago;
			return '<div class="pa-llena"><div class="pa-cab"><a class="pa-volver" href="#inicio" aria-label="Volver">' + ico('atras') + '</a><h1>' + (ETAPAS[sv] || ETAPAS.paquete)[2] + '</h1></div><div class="pa-cuerpo">'
				+ '<div class="pa-hecho">' + ico('check', 'p-ico--20') + '<p style="margin:0">' + (yo ? 'Lo recibiste' : esc(nombreCorto(e.recibe) || 'Lo') + ' lo recibió') + ' a las ' + (v.terminado ? U.hhmm(new Date(v.terminado)) : U.hora(0)) + ' · código ' + esc(e.codigo) + '</p></div>'
				+ (e.foto ? '<figure style="margin:0"><img class="pa-foto" src="' + esc(e.foto) + '" alt="Foto de la entrega" loading="lazy" style="object-fit:cover"><figcaption class="p-peq" style="margin-top:6px">Foto de ' + esc(ch.nombre || 'tu mensajero') + (e.recibo ? ' · <a href="' + esc(e.recibo) + '" target="_blank" rel="noopener">foto del recibo</a>' : '') + '</figcaption></figure>' : '')
				+ (comida ? '<div class="pa-desglose"><span>Comida' + (e.adelanto ? ' · la adelantó ' + esc(nombreCorto(ch.nombre)) : '') + '</span><b>' + miles(e.cobrar) + '</b><span>Envío</span><b>' + miles(v.total) + '</b></div>' : '')
				+ '<div class="pa-total"><span>A pagar</span><span class="p-precio" style="font-size:28px">' + cupG(total) + '</span></div>'
				+ bloquePago(v)
				+ (v.valorado ? '' : U.estrellas(S.estrellas))
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="listo">Listo</button></div></div>';
		},
		init: function (r) {
			var v = S.viaje;
			engancharPago(r, v);
			U.engancharEstrellas(r, function (n) { S.estrellas = n; });
			$('#listo', r).onclick = function () { valorarYSalir(r, v, this); };
		}
	};

	// ---------- Mis viajes (tocar = repetir) ----------
	SCR.viajes = {
		html: function () {
			return '<div class="pa-llena"><div class="pa-cab">' + volver('inicio') + '<h1>Mis pedidos</h1></div><div class="pa-cuerpo" style="gap:0" id="lista">' + pintarViajes(LS.json('viajes')) + '</div></div>';
		},
		init: function (r) {
			var enganchar = function () {
				$$('[data-rep]', r).forEach(function (a) {
					a.onclick = function () {
						var x = (LS.json('viajes') || [])[+a.dataset.rep]; if (!x) { return; }
						S.origen = x.origen; S.destino = x.destino; S.precio = 0; S.cot = null;
						if (x.clase === 'mensajero') { S.svc = 'mens'; S.modo = servDe(x); guardar(); ir(pantallaEnvio()); return; }
						S.modo = 'viajar'; S.svc = 'viajar'; S.clase = x.clase; S.recAtras = 'viajes'; guardar(); ir('recogida');
					};
				});
			};
			enganchar();
			api('viajes').then(function (x) { if (x.data.ok && actual === 'viajes') { LS.set('viajes', JSON.stringify(x.data.viajes)); $('#lista', r).innerHTML = pintarViajes(x.data.viajes); enganchar(); } });
		}
	};
	function pintarViajes(l) {
		if (!l) { return '<div style="display:flex;flex-direction:column;gap:12px">' + esqueleto() + esqueleto() + '</div>'; }
		if (!l.length) { return '<p class="p-aviso">' + ico('info') + 'Todavía no tienes viajes.</p><a class="p-btn p-btn--marca p-btn--ancho" href="#inicio" style="margin-top:16px">Pedir uno</a>'; }
		var hoy = new Date(U.ahora()); hoy.setHours(0, 0, 0, 0);
		var lunes = new Date(hoy); lunes.setDate(hoy.getDate() - ((hoy.getDay() + 6) % 7));
		var grupo = '', h = '', DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
		l.forEach(function (x, i) {
			var f = new Date(x.fecha), gr = f >= hoy ? 'Hoy' : (f >= lunes ? 'Esta semana' : 'Antes');
			if (gr !== grupo) { h += '<p class="pa-etq"' + (grupo ? ' style="margin-top:20px"' : '') + '>' + gr + '</p>'; grupo = gr; }
			var cuando = gr === 'Hoy' ? U.hhmm(f) : (gr === 'Esta semana' ? DIAS[f.getDay()] + ' ' + U.hhmm(f) : f.getDate() + '/' + (f.getMonth() + 1));
			var estado = x.estado === 'cancelado' ? ' · cancelado' : (x.estado === 'sin_chofer' ? ' · sin chofer' : '');
			var sv = servDe(x), SV = { paquete: 'Paquete', comida: 'Comida', carga: 'Carga' };
			var t = x.clase === 'mensajero' ? (x.que ? x.que + ' a ' : (sv === 'paquete' ? 'Envío a ' : SV[sv] + ' a ')) + corto(x.destino.texto) : corto(x.destino.texto);
			h += '<button type="button" class="p-fila pa-viaje pa-pedido-h" data-rep="' + i + '"><img src="' + U.veh(imgServ(x)) + '" alt="" width="56" height="34"><span class="p-fila__txt"><b>' + esc(t) + '</b><span>' + esc(SV[sv] || nombreDe(x.clase)) + ' · ' + cuando + (x.chofer ? ' · ' + esc(nombreCorto(x.chofer)) : '') + (x.foto ? ' · con foto' : '') + estado + '</span></span><span class="p-fila__fin">' + (x.estado === 'terminado' ? cup(x.total) : '') + '</span></button>';
		});
		return h + '<p class="p-aviso" style="margin-top:20px">' + ico('guardado') + '</p>';
	}

	// ---------- Perfil ----------
	SCR.perfil = {
		html: function () {
			var t = LS.get('tema') || 'auto', yo = S.yo || {}, lug = yo.lugares || {};
			var op = function (v, n) { return '<button type="button" data-tema="' + v + '" aria-pressed="' + (t === v) + '">' + n + '</button>'; };
			var fila = function (attrs, i, b, s) { return '<button type="button" class="p-fila" ' + attrs + '><span class="p-fila__ico">' + ico(i) + '</span><span class="p-fila__txt"><b>' + b + '</b>' + (s ? '<span>' + s + '</span>' : '') + '</span></button>'; };
			return '<div class="pa-llena"><div class="pa-cab">' + volver('inicio') + '<h1>Perfil</h1></div><div class="pa-cuerpo" style="gap:4px">'
				+ '<div class="pa-yo"><span class="p-cara" style="width:48px;height:48px;font-size:16px">' + esc(U.iniciales(yo.nombre)) + '</span><div><p class="p-h2" style="margin:0">' + esc(yo.nombre || '') + '</p><p class="p-peq" style="margin:0">' + esc(U.telBonito(yo.telefono || LS.get('tel'))) + (yo.nota != null ? ' · ★ ' + String(yo.nota).replace('.', ',') : '') + '</p></div></div>'
				+ '<p class="pa-etq">Mis lugares</p>' + fila('data-lugar="casa"', 'casa', 'Casa', lug.casa ? esc(lug.casa.texto) : 'Toca para ponerla') + fila('data-lugar="trabajo"', 'trabajo', 'Trabajo', lug.trabajo ? esc(lug.trabajo.texto) : 'Toca para ponerlo')
				+ '<p class="pa-etq">Seguridad</p>' + fila('id="ayuda"', 'escudo', 'Ayuda en un viaje', 'Policía 106 y Pa\'llá')
				+ '<p class="pa-etq">Pantalla</p><div class="pa-seg" style="grid-template-columns:1.4fr 1fr 1fr">' + op('auto', 'Auto') + op('claro', 'Claro') + op('oscuro', 'Oscuro') + '</div>'
				+ '<p class="pa-etq">Más</p>' + fila('id="pago"', S.pago === 'transferencia' ? 'transfer' : 'efectivo', 'Pago preferido', nombrePago()) + '<a class="p-fila" href="#viajes"><span class="p-fila__ico">' + ico('lista') + '</span><span class="p-fila__txt"><b>Mis viajes</b></span></a>'
				+ '<button type="button" class="p-fila" id="salir" style="color:var(--error)"><span class="p-fila__ico" style="color:var(--error)">' + ico('salir') + '</span><span class="p-fila__txt"><b>Salir</b></span></button>'
				+ '</div></div>';
		},
		init: function (r) {
			$$('[data-tema]', r).forEach(function (b) { b.onclick = function () { LS.set('tema', b.dataset.tema); location.reload(); }; });
			$$('[data-lugar]', r).forEach(function (b) { b.onclick = function () { S.para = b.dataset.lugar; guardar(); ir('destino'); }; });
			$('#ayuda', r).onclick = hojaAyuda;
			$('#pago', r).onclick = function () { hojaPago(function () { mostrar(true); }); };
			$('#salir', r).onclick = function () { K = ''; LS.set('k', null); LS.set('yo', null); LS.set('viaje', null); LS.set('viajes', null); LS.set('pend', null); S.yo = null; S.viaje = null; dejar(); ir('entrar'); };
		}
	};

	// ---------- Mensajero (el cliente manda algo) ----------
	SCR.envio = {
		html: function () {
			var e = S.envio;
			var tam = function (k, i, n, s) { return '<button type="button" data-tam="' + k + '" aria-pressed="' + (e.tam === k) + '">' + ico(i) + '<b>' + n + '</b><span>' + s + '</span></button>'; };
			var campo = function (para, val, aria, ph) { return '<button type="button" class="p-campo" data-para="' + para + '" aria-label="' + aria + '" style="text-align:left;width:100%;min-height:52px;font:inherit;color:' + (val ? 'var(--tinta)' : 'var(--tinta-2)') + '">' + esc(val || ph) + '</button>'; };
			return '<div class="pa-llena">' + cabSvc('Paquete', 'mensajero') + '<div class="pa-cuerpo">'
				+ '<div class="pa-od"><span class="pm-pin"></span><span class="pm-pin pm-pin--destino"></span>'
				+ campo('envio-o', S.origen && S.origen.texto, 'Recoger en', '¿Dónde lo recogemos?') + campo('envio-d', S.destino && S.destino.texto, 'Llevar a', '¿A dónde lo llevamos?') + '</div>'
				+ '<div><label class="pa-etq" for="que">¿Qué mandas?</label><label class="p-campo"><input id="que" maxlength="120" value="' + esc(e.que) + '" placeholder="Llaves, un sobre…"></label></div>'
				+ '<div><p class="pa-etq">Tamaño</p><div class="pa-tamano">' + tam('mano', 'llave', 'En la mano', 'llaves, sobre') + tam('mochila', 'paquete', 'Mochila', 'hasta 10 kg') + tam('grande', 'caja', 'Grande', 'en triciclo') + '</div></div>'
				+ '<p class="p-aviso">' + ico('info') + 'Nada prohibido. Si mandas dinero, dilo.</p>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="seguir">Seguir</button></div></div>';
		},
		init: function (r) {
			S.svc = 'mens'; S.modo = 'paquete'; guardar();
			$('#que', r).addEventListener('input', function () { S.envio.que = this.value; guardar(); });
			$$('[data-para]', r).forEach(function (b) { b.onclick = function () { S.para = b.dataset.para; guardar(); ir('destino'); }; });
			$$('[data-tam]', r).forEach(function (b) { b.onclick = function () { S.envio.tam = b.dataset.tam; S.precio = 0; guardar(); $$('[data-tam]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); }; });
			if (!S.origen) { gps().then(function (p) { if (p && !S.origen) { lugarDe(p).then(function (l) { S.origen = l; guardar(); if (actual === 'envio') { mostrar(true); } }); } }); }
			$('#seguir', r).onclick = function () {
				var err = $('#err', r), m = !S.origen ? 'Falta dónde lo recogemos.' : !S.destino ? 'Falta a dónde lo llevamos.' : S.envio.que.trim().length < 2 ? 'Escribe qué mandas.' : '';
				if (m) { err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + m + '</span>'; return; }
				ir('envio-recibe');
			};
		}
	};

	SCR['envio-recibe'] = {
		html: function () {
			var e = S.envio;
			return '<div class="pa-llena"><div class="pa-cab">' + volver('envio') + '<h1>¿Quién lo recibe?</h1></div><div class="pa-cuerpo">'
				+ '<div><label class="pa-etq" for="rn">Nombre</label><label class="p-campo">' + ico('usuario', 'p-ico--20') + '<input id="rn" maxlength="80" autocomplete="off" value="' + esc(e.recibe) + '"></label></div>'
				+ '<div><label class="pa-etq" for="rt">Teléfono</label><div class="pa-tel"><span class="pref">+53</span><label class="p-campo"><input id="rt" inputmode="tel" maxlength="12" autocomplete="off" value="' + esc(e.tel) + '"></label></div>'
				+ '<p class="p-peq" style="margin:8px 0 0">Le mandas su código por WhatsApp cuando salga.</p></div>'
				+ '<div><p class="pa-etq">¿Cobrar al entregar?</p><div class="pa-seg"><button type="button" data-c="0" aria-pressed="' + !e.cobrar + '">No</button><button type="button" data-c="1" aria-pressed="' + !!e.cobrar + '">Sí, cobrar</button></div></div>'
				+ '<label class="p-campo" id="cob" style="display:' + (e.cobrar ? 'flex' : 'none') + '"><input id="cobn" inputmode="numeric" value="' + (e.cobrarCup ? miles(e.cobrarCup) : '') + '" aria-label="Cuánto cobrar" placeholder="0"><span class="p-cup">CUP</span></label>'
				+ '<div><label class="pa-etq" for="rnota">Nota</label><label class="p-campo">' + ico('nota', 'p-ico--20') + '<input id="rnota" maxlength="200" value="' + esc(e.nota) + '" placeholder="Apto., cómo tocar…"></label></div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="ver">Ver precio</button></div></div>';
		},
		init: function (r) {
			$$('[data-c]', r).forEach(function (b) { b.onclick = function () { S.envio.cobrar = b.dataset.c === '1'; guardar(); $$('[data-c]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); $('#cob', r).style.display = S.envio.cobrar ? 'flex' : 'none'; }; });
			var leer = function () { var e = S.envio; e.recibe = $('#rn', r).value.trim(); e.tel = $('#rt', r).value.replace(/\D+/g, ''); e.cobrarCup = +$('#cobn', r).value.replace(/\D+/g, '') || 0; e.nota = $('#rnota', r).value.trim(); guardar(); };
			$$('input', r).forEach(function (i) { i.addEventListener('change', leer); });
			$('#ver', r).onclick = function () {
				leer();
				var e = S.envio, t = e.tel.length === 10 && e.tel.indexOf('53') === 0 ? e.tel.slice(2) : e.tel, err = $('#err', r);
				var m = e.recibe.length < 2 ? 'Escribe el nombre de quien recibe.' : (t.length !== 8 || t.charAt(0) !== '5') ? 'El teléfono: 8 números, empieza por 5.' : (e.cobrar && !e.cobrarCup) ? 'Escribe cuánto cobrar.' : '';
				if (m) { err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + m + '</span>'; return; }
				if (!S.origen || !S.destino) { ir('envio'); return; }
				ir('envio-precio');
			};
		}
	};

	// ---------- v3: piezas de Paquete, Comida y Carga ----------
	function cabSvc(t, img) { return '<div class="pa-cab">' + volver('inicio') + '<h1>' + t + '</h1><img class="pa-cab__veh" src="' + U.veh(img) + '" alt=""></div>'; }
	function campoPunto(para, val, aria, ph) { return '<button type="button" class="p-campo" data-para="' + para + '" aria-label="' + aria + '" style="text-align:left;width:100%;min-height:52px;font:inherit;color:' + (val ? 'var(--tinta)' : 'var(--tinta-2)') + '">' + esc(val || ph) + '</button>'; }
	function seg(clave, no, si, val) { return '<div class="pa-seg" data-seg="' + clave + '"><button type="button" data-v="0" aria-pressed="' + !val + '">' + no + '</button><button type="button" data-v="1" aria-pressed="' + !!val + '">' + si + '</button></div>'; }
	function campoCup(id, n, ver, etq) { return '<label class="p-campo pa-cupcampo" id="' + id + '" style="display:' + (ver ? 'flex' : 'none') + '">' + ico('efectivo', 'p-ico--20') + '<input inputmode="numeric" value="' + (n ? miles(n) : '') + '" placeholder="0" aria-label="' + etq + '"><span class="p-cup">CUP</span></label>'; }
	function leerCup(r, id) { var i = $('#' + id + ' input', r); return i ? (+i.value.replace(/\D+/g, '') || 0) : 0; }
	function engancharSeg(r, alCambiar) {
		$$('[data-seg]', r).forEach(function (g) {
			$$('[data-v]', g).forEach(function (b) {
				b.onclick = function () { $$('[data-v]', g).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); alCambiar(g.dataset.seg, b.dataset.v === '1'); guardar(); };
			});
		});
	}
	function verSi(r, id, si) { var e = $('#' + id, r); if (e) { e.style.display = si ? '' : 'none'; } }
	function errEnvio(r, m) { var err = $('#err', r); err.hidden = false; err.innerHTML = ico('alerta') + '<span>' + m + '</span>'; }
	function puntosEnvio(r) {
		$$('[data-para]', r).forEach(function (b) { b.onclick = function () { S.para = b.dataset.para; guardar(); ir('destino'); }; });
		if (!S.origen && S.modo !== 'comida') { gps().then(function (p) { if (p && !S.origen) { lugarDe(p).then(function (l) { S.origen = l; guardar(); if (actual === pantallaEnvio()) { mostrar(true); } }); } }); }
		if (S.modo === 'comida' && !S.destino) { gps().then(function (p) { if (p && !S.destino) { lugarDe(p).then(function (l) { S.destino = l; guardar(); if (actual === 'comida') { mostrar(true); } }); } }); } // la comida viene a donde estás
	}
	function quienRecibe(e) { return e.otro ? { n: e.recibe, t: e.tel } : { n: (S.yo && S.yo.nombre) || '', t: (S.yo && S.yo.telefono) || '' }; }

	// ---------- Comida: funciona como un envío (sin menú). Recibe «tú» (se puede cambiar). ----------
	SCR.comida = {
		html: function () {
			var e = S.envio, yo = S.yo || {};
			return '<div class="pa-llena">' + cabSvc('Comida', 'comida') + '<div class="pa-cuerpo">'
				+ '<div class="pa-od"><span class="pm-pin"></span><span class="pm-pin pm-pin--destino"></span>'
				+ campoPunto('envio-o', S.origen && S.origen.texto, 'Recoger en', 'Recoger en (restaurante o casa)') + campoPunto('envio-d', S.destino && S.destino.texto, 'Llevar a', 'Llevar a') + '</div>'
				+ '<div><label class="pa-etq" for="sitio">Nombre del sitio</label><label class="p-campo"><input id="sitio" maxlength="120" value="' + esc(e.recoge) + '" placeholder="Ej.: Pizzería Obispo"></label></div>'
				+ '<div><label class="pa-etq" for="que">¿Qué es?</label><label class="p-campo"><input id="que" maxlength="120" value="' + esc(e.que) + '" placeholder="Ej.: 2 pizzas, ya encargadas"></label></div>'
				+ '<div><p class="pa-etq">¿El mensajero la paga al recoger?</p>' + seg('paga', 'No, ya está pagada', 'Sí, la paga', e.paga) + '</div>'
				+ campoCup('pagaN', e.pagaCup, e.paga, 'Cuánto paga al recoger')
				+ '<p class="p-peq" id="tope" style="margin:-8px 0 0;display:' + (e.paga ? '' : 'none') + '">Máximo ' + cup(TOPE_COMIDA) + '.</p>'
				+ '<div><p class="pa-etq">¿Cobrar al entregar?</p>' + seg('cobrar', 'No', 'Sí, cobrar', e.cobrar || e.paga) + '</div>'
				+ '<p class="p-aviso" id="cobraT" style="display:' + (e.paga ? 'flex' : 'none') + '">' + ico('efectivo') + '<span>Cobra la comida + el envío</span></p>'
				+ campoCup('cobraN', e.cobrarCup, e.cobrar && !e.paga, 'Cuánto cobrar al entregar')
				+ '<button type="button" class="p-fila pa-recibe" id="recibe"><span class="p-fila__ico">' + ico('usuario') + '</span><span class="p-fila__txt"><b>' + (e.otro ? 'Recibe ' + esc(e.recibe || '…') : 'Recibe ' + esc(nombreCorto(yo.nombre) || '') + ' (tú)') + '</b><span>' + esc(U.telBonito(e.otro ? e.tel : yo.telefono)) + '</span></span><span class="p-fila__fin">Cambiar</span></button>'
				+ '<div id="otro" style="display:' + (e.otro ? '' : 'none') + '"><label class="p-campo" style="margin-bottom:8px">' + ico('usuario', 'p-ico--20') + '<input id="rn" maxlength="80" value="' + esc(e.recibe) + '" placeholder="Nombre" aria-label="Nombre de quien recibe"></label>'
				+ '<div class="pa-tel"><span class="pref">+53</span><label class="p-campo"><input id="rt" inputmode="tel" maxlength="12" value="' + esc(e.tel) + '" placeholder="Teléfono" aria-label="Teléfono de quien recibe"></label></div></div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="ver">Ver precio</button></div></div>';
		},
		init: function (r) {
			S.svc = 'mens'; S.modo = 'comida'; guardar();
			puntosEnvio(r);
			var e = S.envio;
			var ver = function () { verSi(r, 'pagaN', e.paga); verSi(r, 'tope', e.paga); verSi(r, 'cobraT', e.paga); verSi(r, 'cobraN', e.cobrar && !e.paga); };
			engancharSeg(r, function (k, v) {
				if (k === 'paga') { e.paga = v; if (v) { e.cobrar = true; $$('[data-seg="cobrar"] [data-v]', r).forEach(function (x) { x.setAttribute('aria-pressed', x.dataset.v === '1'); }); } }
				if (k === 'cobrar') { e.cobrar = v; if (!v && e.paga) { e.cobrar = true; $$('[data-seg="cobrar"] [data-v]', r).forEach(function (x) { x.setAttribute('aria-pressed', x.dataset.v === '1'); }); U.tostada('Si el mensajero paga la comida, se la devuelves al entregar.', 'info'); } }
				ver();
			});
			var leer = function () { e.recoge = $('#sitio', r).value.trim(); e.que = $('#que', r).value.trim(); e.pagaCup = leerCup(r, 'pagaN'); e.cobrarCup = leerCup(r, 'cobraN'); e.recibe = $('#rn', r).value.trim(); e.tel = $('#rt', r).value.replace(/\D+/g, ''); guardar(); };
			$$('input', r).forEach(function (i) { i.addEventListener('change', leer); });
			$('#recibe', r).onclick = function () { e.otro = !e.otro; guardar(); mostrar(true); };
			$('#ver', r).onclick = function () {
				leer();
				var t = e.tel.length === 10 && e.tel.indexOf('53') === 0 ? e.tel.slice(2) : e.tel;
				var m = !S.origen ? 'Falta dónde se recoge.' : !S.destino ? 'Falta a dónde la llevamos.' : e.que.length < 2 ? 'Escribe qué comida es.'
					: (e.paga && !e.pagaCup) ? 'Escribe cuánto paga el mensajero.' : (e.paga && e.pagaCup > TOPE_COMIDA) ? 'El mensajero adelanta como mucho ' + cup(TOPE_COMIDA) + '. Si es más, paga tú por Transfermóvil.'
					: (e.cobrar && !e.paga && !e.cobrarCup) ? 'Escribe cuánto cobrar.' : (e.otro && (e.recibe.length < 2 || t.length !== 8 || t.charAt(0) !== '5')) ? 'Escribe nombre y teléfono de quien recibe (8 números, empieza por 5).' : '';
				if (m) { errEnvio(r, m); return; }
				ir('envio-precio');
			};
		}
	};

	// ---------- Carga: triciclo de carga hasta 300 kg. El peso no cambia el precio; el ayudante suma un precio fijo. ----------
	SCR.carga = {
		html: function () {
			var e = S.envio;
			var peso = function (k) { return '<button type="button" data-peso="' + k + '" aria-pressed="' + (e.peso === k) + '"><b>' + PESOS[k][0] + '</b><span>' + PESOS[k][1] + '</span></button>'; };
			return '<div class="pa-llena">' + cabSvc('Carga', 'tric-carga') + '<div class="pa-cuerpo">'
				+ '<div class="pa-od"><span class="pm-pin"></span><span class="pm-pin pm-pin--destino"></span>'
				+ campoPunto('envio-o', S.origen && S.origen.texto, 'Recoger en', 'Recoger en') + campoPunto('envio-d', S.destino && S.destino.texto, 'Llevar a', 'Llevar a') + '</div>'
				+ '<div><label class="pa-etq" for="que">¿Qué llevas?</label><label class="p-campo"><input id="que" maxlength="120" value="' + esc(e.que) + '" placeholder="Ej.: un refrigerador y 2 sillas"></label></div>'
				+ '<div><p class="pa-etq">Peso aproximado</p><div class="pa-tamano pa-peso">' + peso('poco') + peso('medio') + peso('mucho') + '</div></div>'
				+ '<div><p class="pa-etq">¿Ayuda para cargar?</p>' + seg('ayuda', 'No, cargo yo', 'Sí · +' + miles(AYUDANTE) + ' CUP', e.ayuda) + '</div>'
				+ '<p class="p-aviso">' + ico('info') + '<span>Más de 300 kg o una mudanza: ' + (C.telefono ? '<a href="' + waLink(C.telefono, 'Hola, quiero mover una carga de más de 300 kg (o una mudanza) con Pa\'llá.') + '">escríbenos</a>' : 'escríbenos') + '.</span></p>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="ver">Ver precio</button></div></div>';
		},
		init: function (r) {
			S.svc = 'mens'; S.modo = 'carga'; guardar();
			puntosEnvio(r);
			var e = S.envio;
			$('#que', r).addEventListener('input', function () { e.que = this.value; guardar(); });
			$$('[data-peso]', r).forEach(function (b) { b.onclick = function () { e.peso = b.dataset.peso; guardar(); $$('[data-peso]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); }; });
			engancharSeg(r, function (k, v) { e.ayuda = v; S.precio = 0; });
			$('#ver', r).onclick = function () {
				e.que = $('#que', r).value.trim(); guardar();
				var m = !S.origen ? 'Falta dónde recogemos.' : !S.destino ? 'Falta a dónde lo llevamos.' : e.que.length < 2 ? 'Escribe qué llevas.' : !PESOS[e.peso] ? 'Di cuánto pesa más o menos.' : '';
				if (m) { errEnvio(r, m); return; }
				ir('envio-precio');
			};
		}
	};

	// ---------- Precio de Paquete, Comida y Carga: la MISMA hoja que Viajar (vehículo 3D + precio · pago · llega + botón) ----------
	function metaEnvio() {
		var e = S.envio, m = S.modo;
		if (m === 'carga') { return { grupo: 'mens', tam: 'grande', servicio: 'carga', ayudante: e.ayuda ? 1 : 0 }; }
		if (m === 'comida') { return { grupo: 'mens', tam: 'mochila', servicio: 'comida' }; }
		return { grupo: 'mens', tam: e.tam, servicio: 'paquete' };
	}
	SCR['envio-precio'] = {
		mapa: function () { var et = S.modo === 'paquete' ? esc(nombreCorto(S.envio.recibe)) : ''; var c = rutaCot(); return c ? { ruta: c, ver: c, arriba: 60, pins: [['origen', c[0]], ['destino', c[c.length - 1], et]] } : sinRuta(et); },
		html: function () { return '<div class="pa-arriba">' + volverR(S.modo === 'paquete' ? 'envio-recibe' : pantallaEnvio()) + '</div><section class="pa-hoja pa-hoja--viajar"><div class="p-hoja__asa"></div><div id="cuerpo">' + esqueleto() + '</div></section>'; },
		init: function (r) {
			if (!S.origen || !S.destino) { ir(pantallaEnvio()); return; }
			var e = S.envio, modo = S.modo === 'comida' || S.modo === 'carga' ? S.modo : 'paquete', meta = metaEnvio();
			cotizar(meta).then(function (cq) {
				if (actual !== 'envio-precio') { return; }
				if (cq.error) { errReintentar($('#cuerpo', r), cq.error); return; }
				var q = cq.clases[0], carga = modo === 'carga' || (modo === 'paquete' && e.tam === 'grande'); S.clase = 'mensajero';
				var img = modo === 'comida' ? 'comida' : (carga ? 'tric-carga' : 'mensajero'), quien = quienRecibe(e);
				var sub = modo === 'comida' ? (e.otro ? 'Para ' + esc(nombreCorto(quien.n)) : 'Para ti') : (modo === 'carga' ? (e.ayuda ? 'Con ayudante' : 'Cargas tú') : 'Para ' + esc(nombreCorto(e.recibe)));
				var aviso;
				if (modo === 'comida') { aviso = '<p class="p-aviso pa-aviso-env" id="puerta">' + ico('efectivo') + '<span></span></p>'; }
				else if (modo === 'carga') { aviso = '<p class="p-aviso pa-aviso-env">' + ico('caja') + '<span>' + PESOS[e.peso][0] + (e.ayuda ? ' · ayudante incluido (+' + miles(q.extra || AYUDANTE) + ')' : '') + '</span></p>'; }
				else { aviso = '<p class="p-aviso pa-aviso-env">' + ico('camara') + '<span>Código para ' + esc(nombreCorto(e.recibe)) + ' y foto al entregar' + (e.cobrar && e.cobrarCup ? ' · cobra ' + miles(e.cobrarCup) : '') + '</span></p>'; }
				$('#cuerpo', r).innerHTML = '<div class="pa-heroe"><img src="' + U.veh(img) + '" alt="" width="120" height="80"><div><b>' + esc(e.que) + '</b><span>' + sub + ' · ' + U.km(q.km) + ' km · ' + q.minutos + ' min</span></div></div>'
					+ aviso + '<div id="bloque"></div>';
				escenaActual();
				var adel = modo === 'comida' && e.paga ? e.pagaCup : 0, cobrar = modo === 'comida' ? (e.paga ? e.pagaCup : (e.cobrar ? e.cobrarCup : 0)) : (e.cobrar ? e.cobrarCup : 0);
				bloquePrecio($('#bloque', r), {
					q: q,
					llega: function () { return q.llega == null ? '—' : q.llega + ' min'; },
					boton: function (p) { return 'Pedir ' + (carga ? 'triciclo' : 'mensajero') + ' · ' + cup(p); },
					alPintar: function (p) {
						var el = $('#puerta span', r); if (!el) { return; }
						el.innerHTML = cobrar && !e.otro ? 'En la puerta pagas <b>' + cup(cobrar + p) + '</b> · comida ' + miles(cobrar) + ' + envío' : (cobrar ? 'Al entregar cobra ' + cup(cobrar) + ' + el envío' : 'La comida ya está pagada');
					},
					alPedir: function (p) {
						var t = String(quien.t || '').replace(/\D+/g, ''); if (t.length === 10 && t.indexOf('53') === 0) { t = t.slice(2); }
						var env = { que: e.que, recibe_nombre: modo === 'paquete' ? e.recibe : quien.n, recibe_telefono: modo === 'paquete' ? (e.tel.length === 10 ? e.tel.slice(2) : e.tel) : t, cobrar_cup: cobrar };
						if (modo === 'comida') { env.recoge = e.recoge; env.adelanto_cup = adel; }
						if (modo === 'carga') { env.peso = e.peso; }
						meta.nombre = modo === 'paquete' ? 'mensajero' : modo;
						pedir(meta, { cid: cid(), firma: q.firma, precio: p, primera: 0, forma_pago: S.pago, origen_texto: S.origen.texto, destino_texto: S.destino.texto, nota: modo === 'paquete' ? e.nota : '', envio: env });
					}
				});
			});
		}
	};

	// =====================================================================================
	// EN VIVO: canal ntfy del viaje + preguntar a la web
	// =====================================================================================
	var vivo = null, vivoUuid = '', poll = 0, pidiendo = false;
	function pantallaDe(v) {
		if (!v) { return 'inicio'; }
		var e = esEnvio(v);
		switch (v.estado) {
			case 'buscando': case 'sin_chofer': return 'buscando';
			case 'asignado': case 'llego': return e ? 'envio-seguimiento' : 'viene';
			case 'recogido': return e ? 'envio-seguimiento' : 'viaje';
			case 'terminado': return e ? 'envio-entregado' : 'llegada';
			default: return 'inicio';
		}
	}
	function seguir() {
		var v = S.viaje;
		if (!v || ACTIVOS.indexOf(v.estado) < 0) { dejar(); return; }
		if (vivoUuid !== v.uuid) {
			dejar(); vivoUuid = v.uuid; tramo = null;
			vivo = U.enVivo(v.canal, function (m) {
				if (m.t === 'pos' && S.viaje) { S.viaje.pos = { lat: +m.lat, lng: +m.lng, hace: 0 }; alPos(); return; }
				refrescar();
			});
		}
		programar();
	}
	function dejar() { if (vivo) { vivo.cerrar(); } vivo = null; vivoUuid = ''; clearTimeout(poll); }
	function programar() {
		clearTimeout(poll);
		var v = S.viaje; if (!v || ACTIVOS.indexOf(v.estado) < 0) { return; }
		if (v.estado === 'sin_chofer') { return; } // 10-nov (auditoría): «Nadie aceptó» ya no pregunta sin fin; sube o busca otra vez
		var ms = document.hidden ? 30000 : (v.estado === 'buscando' ? 4000 : 15000);
		poll = setTimeout(function () { refrescar().then(programar); }, ms);
	}
	function refrescar() {
		var v = S.viaje; if (!v || pidiendo) { return Promise.resolve(); }
		pidiendo = true;
		return api('viajes/' + v.uuid + '?rev=' + v.rev).then(function (x) {
			pidiendo = false;
			if (x.status === 404) { ponerViaje(null); dejar(); ir('inicio'); return; }
			if (!x.data.ok) { return; }
			if (x.data.igual) { if (S.viaje && x.data.viene) { S.viaje.viene = x.data.viene; } if (x.data.pos && S.viaje) { S.viaje.pos = x.data.pos; alPos(); } return; }
			if (x.data.viaje) { cambio(x.data.viaje); }
		});
	}
	function alPos() { var s = SCR[actual]; if (s && s.alPos && S.viaje) { s.alPos(pant, S.viaje); } }
	function cambio(nuevo) {
		var antes = S.viaje;
		ponerViaje(nuevo);
		var dest = pantallaDe(nuevo), e = esEnvio(nuevo);
		if (antes && antes.estado !== nuevo.estado) {
			if (nuevo.estado === 'asignado' && antes.estado === 'buscando' && nuevo.primera) { U.tostada('Cogimos la 1.ª oferta: ' + cup(nuevo.precio)); vibrar([80, 60, 80]); }
			else if (nuevo.estado === 'asignado' && (antes.estado === 'buscando' || antes.estado === 'sin_chofer')) { vibrar([80, 60, 80]); }
			if (nuevo.estado === 'llego') { U.tostada(e ? 'Tu mensajero llegó a recoger.' : (nuevo.pasajero ? 'El chofer llegó a recoger a ' + nombreCorto(nuevo.pasajero.nombre) + '.' : 'Tu chofer llegó. Dile el código ' + nuevo.codigo + '.')); vibrar([200, 100, 200]); }
			if (nuevo.estado === 'buscando' && (antes.estado === 'asignado' || antes.estado === 'llego')) { U.tostada('Tu chofer no puede ir. Buscamos otro.', 'info'); vibrar(200); tramo = null; }
			if (nuevo.estado === 'cancelado') { U.tostada('El viaje se canceló.', 'info'); ponerViaje(null); dejar(); ir('inicio'); return; }
		}
		if (actual !== dest) { ir(dest); } else if (SCR[actual] && SCR[actual].actualizar) { SCR[actual].actualizar(pant, nuevo); }
		seguir();
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { refrescar().then(programar); } else { programar(); } });

	// =====================================================================================
	// MOTOR: mostrar pantalla + mapa (o plano de reserva)
	// =====================================================================================
	var actual = null;
	function limpiar() {
		timers.forEach(function (t) { if (t && t.off) { t.off(); } else { clearTimeout(t); clearInterval(t); } });
		timers = [];
		$$('.pa-sobre').forEach(function (t) { t.remove(); });
	}
	function escenaActual() {
		var s = SCR[actual]; if (!s || !s.mapa) { return; }
		var cfg = s.mapa(); if (!cfg || cfg.offline) { return; }
		var hoja = $('.pa-hoja', pant);
		PMapa.escena(cfg, hoja ? hoja.offsetHeight : 0);
	}
	function mostrar(mismo) {
		var id = decodeURIComponent(location.hash.slice(1)) || (K ? pantallaDe(S.viaje && ACTIVOS.indexOf(S.viaje.estado) >= 0 ? S.viaje : null) : 'entrar');
		if (!SCR[id]) { id = 'inicio'; }
		if (!K && !SCR[id].libre) { id = 'entrar'; }
		if (SCR[id].viaje && !S.viaje) { id = 'inicio'; }
		limpiar();
		var s = SCR[id]; actual = id;
		pant.innerHTML = s.html();
		var cfg = s.mapa ? s.mapa() : null;
		marco.classList.toggle('sin-mapa', !cfg);
		marco.classList.toggle('sin-red', !!(cfg && cfg.offline));
		if (cfg && !cfg.offline) {
			var hoja = $('.pa-hoja', pant);
			PMapa.escena(cfg, hoja ? hoja.offsetHeight : 0);
			if (cfg.listo && s.listo) { PMapa.iniciar(mapaEl, TEMA).then(function () { if (actual === id) { s.listo(pant); } }); }
		}
		if (s.init) { s.init(pant); }
		var titulos = { inicio: '', entrar: 'Entrar', codigo: 'Código', destino: 'Destino', fijar: 'Fijar en el mapa', recogida: 'Recogida', pasajero: 'Quién viaja', tipo: 'Viajar', precio: 'Viajar', viajar: 'Viajar', comida: 'Comida', carga: 'Carga', buscando: 'Buscando', viene: 'Tu chofer viene', viaje: 'En viaje', llegada: 'Pagar', viajes: 'Mis pedidos', perfil: 'Perfil', envio: 'Paquete', 'envio-recibe': 'Quién recibe', 'envio-precio': 'Precio', 'envio-seguimiento': 'Envío', 'envio-entregado': 'Entregado', sinconexion: 'Sin señal' };
		document.title = (titulos[id] ? titulos[id] + ' · ' : '') + 'Pa\'llá';
		if (!mismo) { pant.scrollTop = 0; }
	}

	function cargarYo() {
		return api('yo').then(function (x) {
			if (!x.data.ok) { return; }
			S.yo = x.data.yo; S.recientes = x.data.recientes || []; LS.set('yo', JSON.stringify(S.yo)); LS.set('recientes', JSON.stringify(S.recientes));
			var a = x.data.activo;
			if (a) { ponerViaje(a); seguir(); var d = pantallaDe(a); if (actual !== d) { ir(d); } else if (SCR[actual].actualizar) { SCR[actual].actualizar(pant, a); } return; }
			// sin viaje activo: lo guardado vale solo si está por pagar/valorar en esta pantalla
			if (S.viaje && ACTIVOS.indexOf(S.viaje.estado) >= 0) {
				return api('viajes/' + S.viaje.uuid).then(function (y) { if (y.data.viaje) { cambio(y.data.viaje); } else { ponerViaje(null); if (SCR[actual] && SCR[actual].viaje) { ir('inicio'); } } });
			}
			if (actual === 'inicio') { mostrar(true); }
		});
	}
	function arrancar() {
		ir(location.hash && SCR[location.hash.slice(1)] && !SCR[location.hash.slice(1)].libre ? location.hash.slice(1) : (S.viaje && ACTIVOS.indexOf(S.viaje.estado) >= 0 ? pantallaDe(S.viaje) : 'inicio'));
		if (S.viaje) { seguir(); }
		cargarYo().then(function () { if (LS.json('pend')) { enviarPend(); } });
	}

	PMapa.iniciar(mapaEl, TEMA); // el plano sale al momento; el mapa de verdad (si hay red) entra después
	window.addEventListener('hashchange', function () { mostrar(); });
	U.sw();
	if (K) { arrancar(); } else { if (!location.hash || !SCR[location.hash.slice(1)] || !SCR[location.hash.slice(1)].libre) { history.replaceState && history.replaceState(null, '', location.pathname + '#entrar'); } mostrar(); }
})();
