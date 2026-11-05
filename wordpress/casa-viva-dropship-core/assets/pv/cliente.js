/* Pa'llá transporte (5-nov-2026) · app del CLIENTE en /palla/ir/. Pantallas del diseño v2 aprobado (prototipo/app.js) con
 * los datos reales de la API casa-viva/v1/pv/… Sin librerías: window.PV (servidor) + window.PMapa (mapa.js) + window.PU
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
	var PALABRA = { tric: 'triciclo', taxi: 'taxi', moto: 'moto', mens: 'mensajero' };
	var ICO_G = { tric: 'triciclo', taxi: 'coche', moto: 'moto', mens: 'paquete' };
	var COLOR_G = { tric: 'tric', taxi: 'taxi', moto: 'taxi', mens: 'mens' };
	var VEL = { taxi: 400, tric: 250, moto: 400, mens: 380 }; // metros por minuto en la ciudad (como el servidor)
	var HABANA = [-82.3812, 23.1402];
	var ACTIVOS = ['buscando', 'sin_chofer', 'asignado', 'llego', 'recogido'];

	// ---------- Entrada: token ----------
	var mk = /(?:^#|&)k=([A-Za-z0-9._-]+)/.exec(location.hash || '');
	if (mk) { LS.set('k', mk[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	var K = LS.get('k') || '';

	function api(path, body, metodo) {
		return U.api(path, body, { 'X-TC-Token': K }, metodo).then(function (r) {
			if (r.status === 401 && K) { K = ''; LS.set('k', null); ir('entrar'); }
			else if (r.status === 403 && /^pv_(closed|not_tester|blocked)$/.test(r.data.code || '')) { S.cerrado = r.data.message; ir('cerrado'); }
			return r;
		});
	}
	function qs(o) { return Object.keys(o).filter(function (k) { return o[k] != null && o[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); }

	// ---------- Estado (lo del camino se guarda en el móvil) ----------
	var S = { svc: 'tric', grupo: 'tric', clase: '', origen: null, destino: null, nota: '', precio: 0, primera: false, pago: 'efectivo', para: 'destino', envio: { que: '', tam: 'mano', recibe: '', tel: '', cobrar: false, cobrarCup: 0, nota: '' } };
	(function () { var g = LS.json('s'); if (g) { Object.keys(S).forEach(function (k) { if (g[k] != null) { S[k] = g[k]; } }); } S.primera = false; })();
	S.yo = LS.json('yo'); S.recientes = LS.json('recientes') || []; S.viaje = LS.json('viaje'); S.cot = null; S.estrellas = 0; S.ocultas = {};
	function guardar() { LS.set('s', JSON.stringify({ svc: S.svc, grupo: S.grupo, clase: S.clase, origen: S.origen, destino: S.destino, nota: S.nota, precio: S.precio, pago: S.pago, para: S.para, envio: S.envio })); }
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
	function lugarDe(p) { return api('donde?' + qs({ lat: p[1].toFixed(6), lng: p[0].toFixed(6) })).then(function (r) { return { texto: (r.data && r.data.texto) || 'Punto en el mapa', barrio: (r.data && r.data.barrio) || '', lat: p[1], lng: p[0] }; }); }
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
		html: function () {
			var serv = function (s, n, i) { return '<button type="button" class="pa-serv" data-s="' + s + '" aria-pressed="' + (S.svc === s) + '">' + ico(i, 'p-ico--20') + n + '</button>'; };
			var mens = S.svc === 'mens';
			var chip = function (k, i, n) { return '<button type="button" class="p-chip" data-lugar="' + k + '">' + ico(i, 'p-ico--16') + n + '</button>'; };
			var rec = (S.recientes || []).slice(0, 3).map(function (x, i) { return filaLugar('data-rec="' + i + '"', 'reloj', esc(corto(x.texto)), esc(x.texto.indexOf(',') > 0 ? x.texto.slice(x.texto.indexOf(',') + 1).trim() : '')); }).join('');
			var activo = S.viaje && ACTIVOS.indexOf(S.viaje.estado) >= 0;
			return '<div class="pa-arriba"><a class="p-btn p-btn--redondo" href="#perfil" aria-label="Menú">' + ico('menu') + '</a></div>'
				+ '<section class="pa-hoja" aria-label="Pedir"><div class="p-hoja__asa"></div>'
				+ (activo ? '<a class="p-aviso p-aviso--bien" href="#' + pantallaDe(S.viaje) + '" style="text-decoration:none;margin:0 0 12px">' + ico('ruta') + '<span>Tienes un ' + PALABRA[grupoDe(S.viaje.clase)] + ' en marcha. Ver.</span></a>' : '')
				+ '<div class="pa-servicios">' + serv('taxi', 'Taxi', 'coche') + serv('tric', 'Triciclo', 'triciclo') + serv('mens', 'Mensajero', 'paquete') + '</div>'
				+ '<a class="pa-buscar" href="#' + (mens ? 'envio' : 'destino') + '" id="buscar"><i>' + ico('buscar', 'p-ico--20') + '</i><div><b>' + (mens ? '¿Qué mandamos?' : '¿A dónde vamos?') + '</b><span id="desde">' + (S.origen ? 'Desde ' + esc(corto(S.origen.texto)) : 'Buscando dónde estás…') + '</span></div></a>'
				+ '<div class="pa-fila-chips">' + chip('casa', 'casa', 'Casa') + chip('trabajo', 'trabajo', 'Trabajo') + '</div>'
				+ rec
				+ '</section>';
		},
		init: function (r) {
			$$('.pa-serv', r).forEach(function (b) { b.onclick = function () { S.svc = b.dataset.s; if (S.svc !== 'mens') { S.grupo = S.svc; S.clase = ''; } S.precio = 0; guardar(); mostrar(true); pintarCercanos(); }; });
			$('#buscar', r).addEventListener('click', function () { S.para = 'destino'; guardar(); });
			var siguiente = function () { ir(S.svc === 'mens' ? 'envio' : (S.origen ? 'recogida' : 'destino')); };
			$$('[data-lugar]', r).forEach(function (b) {
				b.onclick = function () {
					var l = ((S.yo && S.yo.lugares) || {})[b.dataset.lugar];
					if (!l) { S.para = b.dataset.lugar; guardar(); ir('destino'); return; }
					S.destino = { texto: l.texto, lat: l.lat, lng: l.lng }; S.precio = 0; guardar(); siguiente();
				};
			});
			$$('[data-rec]', r).forEach(function (b) { b.onclick = function () { var x = S.recientes[+b.dataset.rec]; S.destino = { texto: x.texto, lat: x.lat, lng: x.lng }; S.precio = 0; guardar(); siguiente(); }; });
			if (!S.origen || Date.now() - (S.origenT || 0) > 600000) {
				gps().then(function (p) {
					if (!p) { var d = $('#desde'); if (d && !S.origen) { d.textContent = 'Toca para decir dónde estás'; } return; }
					S.origenT = Date.now();
					return lugarDe(p).then(function (l) { S.origen = l; guardar(); var d = $('#desde'); if (d && actual === 'inicio') { d.textContent = 'Desde ' + corto(l.texto); escenaActual(); pintarCercanos(); } });
				});
			}
			pintarCercanos();
		}
	};
	function pintarCercanos() {
		if (!S.origen) { return; }
		var g = S.svc === 'mens' ? 'mens' : S.svc;
		api('cercanos?' + qs({ lat: S.origen.lat, lng: S.origen.lng, grupo: g })).then(function (x) {
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
		if (para === 'envio-o' || para === 'envio-d') { ir('envio'); return; }
		if (c === 'origen' && !S.destino) { S.para = 'destino'; guardar(); mostrar(true); return; }
		if (!S.origen) { S.para = 'origen'; guardar(); mostrar(true); return; }
		ir('recogida');
	}
	SCR.destino = {
		html: function () {
			var c = campoDe(S.para), dos = c === 'origen' || c === 'destino';
			var titulo = { origen: '¿Dónde te recogemos?', destino: S.para === 'envio-d' ? '¿A dónde lo llevamos?' : '¿A dónde vamos?', casa: 'Tu casa', trabajo: 'Tu trabajo' }[c];
			if (S.para === 'envio-o') { titulo = '¿Dónde lo recogemos?'; }
			var atras = S.para.indexOf('envio') === 0 ? 'envio' : (c === 'casa' || c === 'trabajo' ? 'perfil' : 'inicio');
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
				api('sugerir?' + qs({ texto: txt, lat: cerca ? cerca[1] : '', lng: cerca ? cerca[0] : '' })).then(function (x) {
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
		var pc = $('.pa-pin-centro'), n = 0;
		var a = function () { if (pc) { pc.classList.add('is-mueve'); } };
		var b = function () {
			if (pc) { pc.classList.remove('is-mueve'); }
			var el = $('.pa-pin-centro'); if (!el) { return; }
			var r = el.getBoundingClientRect(), mr = mapaEl.getBoundingClientRect();
			var ll = m.unproject([r.left + r.width / 2 - mr.left, r.bottom - mr.top]), mio = ++n;
			alSoltar(null);
			lugarDe([ll.lng, ll.lat]).then(function (l) { if (mio === n) { alSoltar(l); } });
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
			return '<div class="pa-arriba">' + volverR('destino') + '<span class="pa-pastilla">A ' + esc(corto(S.destino.texto)) + '</span></div>'
				+ pinCentro(150)
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div><p class="p-h2" style="margin:0">¿Te recogemos aquí?</p>'
				+ '<p class="p-peq" style="margin:2px 0 14px" id="dir">' + esc(S.origen.texto) + '</p>'
				+ '<label class="p-campo" style="margin-bottom:12px">' + ico('nota', 'p-ico--20') + '<input id="nota" maxlength="200" value="' + esc(S.nota) + '" placeholder="Nota para el chofer" aria-label="Nota para el chofer"></label>'
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="ok">Confirmar recogida</button></section>';
		},
		init: function (r) {
			if (!S.origen || !S.destino) { S.para = S.origen ? 'destino' : 'origen'; ir('destino'); return; }
			$('#nota', r).addEventListener('input', function () { S.nota = this.value; guardar(); });
			$('#ok', r).onclick = function () { S.nota = $('#nota', r).value.trim(); guardar(); ir('tipo'); };
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

	// ---------- Tipo y precio ----------
	function claveCot(g, tam) { return [g, tam || '', S.origen && S.origen.lat, S.origen && S.origen.lng, S.destino && S.destino.lat, S.destino && S.destino.lng].join('|'); }
	function cotVale(g, tam) { return S.cot && S.cot.k === claveCot(g, tam) && S.cot.vence * 1000 > U.ahora() + 30000; }
	function cotizar(g, tam) {
		if (cotVale(g, tam)) { return Promise.resolve(S.cot); }
		var k = claveCot(g, tam);
		return api('cotizar?' + qs({ grupo: g, olat: S.origen.lat, olng: S.origen.lng, dlat: S.destino.lat, dlng: S.destino.lng, tam: tam || '' })).then(function (x) {
			if (!x.data.ok) { return { error: x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo calcular el precio.') }; }
			x.data.k = k; S.cot = x.data; return S.cot;
		});
	}
	function claseCot() { var l = (S.cot && S.cot.clases) || []; return l.filter(function (c) { return c.clase === S.clase; })[0] || l[0] || null; }
	function rutaCot() { var c = S.cot && U.lineaLonLat(S.cot.linea); return c || (S.origen && S.destino ? [[S.origen.lng, S.origen.lat], [S.destino.lng, S.destino.lat]] : null); }
	function precioDe(q) { var p = S.precio || q.justo; return Math.min(q.max, Math.max(q.min, p)); }

	SCR.tipo = {
		mapa: function () {
			var c = rutaCot(); if (!c) { return { ver: [ptoOrigen()], zoom: 15 }; }
			var q = claseCot();
			return { ruta: c, ver: c, pins: [['origen', c[0]], ['destino', c[c.length - 1], esc(corto(S.destino.texto)) + (q ? ' · ' + q.minutos + ' min' : '')]] };
		},
		html: function () {
			var g = S.grupo === 'taxi' || S.grupo === 'moto' ? S.grupo : 'tric';
			var pest = function (k, n) { return '<button type="button" aria-pressed="' + (g === k) + '" data-g="' + k + '">' + n + '</button>'; };
			return '<div class="pa-arriba">' + volverR('recogida') + '</div>'
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div><div class="p-pest">' + pest('tric', 'Triciclo') + pest('taxi', 'Taxi') + pest('moto', 'Moto') + '</div>'
				+ '<div class="pa-tipos" id="tipos">' + esqueleto() + esqueleto() + '</div>'
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="elegir" disabled>Elegir</button></section>';
		},
		init: function (r) {
			if (!S.origen || !S.destino) { ir('destino'); return; }
			var g = S.grupo === 'taxi' || S.grupo === 'moto' ? S.grupo : 'tric';
			$$('[data-g]', r).forEach(function (b) { b.onclick = function () { S.grupo = b.dataset.g; S.svc = S.grupo === 'taxi' ? 'taxi' : 'tric'; S.clase = ''; S.precio = 0; guardar(); mostrar(true); }; });
			var pintar = function () {
				var l = S.cot.clases;
				if (!l.some(function (c) { return c.clase === S.clase; })) { S.clase = (l.filter(function (c) { return c.libres; })[0] || l[0]).clase; }
				$('#tipos', r).innerHTML = l.map(function (c) {
					var k = CLASES[c.clase] || {};
					return '<button type="button" class="pa-tipo" data-t="' + c.clase + '" aria-pressed="' + (S.clase === c.clase) + '"><img src="' + U.veh(k.img) + '" alt="" width="72" height="41"><span><b>' + esc(k.nombre) + '</b><span>' + ico(c.clase === 'tric_carga' ? 'caja' : 'usuario', 'p-ico--16') + esc(k.plazas) + ' · ' + (c.llega != null ? c.llega + ' min' : 'nadie cerca') + '</span></span><span class="pr">' + cupG(c.justo) + '</span></button>';
				}).join('');
				$('#elegir', r).disabled = false; $('#elegir', r).textContent = 'Elegir ' + nombreDe(S.clase);
				$$('[data-t]', r).forEach(function (b) { b.onclick = function () { S.clase = b.dataset.t; S.precio = 0; guardar(); $$('[data-t]', r).forEach(function (x) { x.setAttribute('aria-pressed', x === b); }); $('#elegir', r).textContent = 'Elegir ' + nombreDe(S.clase); }; });
				escenaActual();
			};
			cotizar(g).then(function (q) {
				if (actual !== 'tipo') { return; }
				if (q.error) { $('#tipos', r).innerHTML = '<p class="p-aviso p-aviso--error">' + ico('alerta') + esc(q.error) + '</p>'; return; }
				pintar();
			});
			$('#elegir', r).onclick = function () { S.primera = false; guardar(); ir('precio'); };
		}
	};

	// Control del precio: − / + de 10 en 10 entre min y max (los da el servidor: −20 %…+50 %, nunca bajo el mínimo).
	function controlPrecio(q, p) {
		var j = q.justo;
		var dif = p === j ? 'Precio justo' : (p > j ? '+' : '−') + Math.round(Math.abs(p - j) / j * 100) + ' % · justo ' + miles(j);
		return '<div class="pa-precio"><button type="button" class="p-btn p-btn--icono pa-pm" id="menos" aria-label="Bajar 10 CUP"' + (p <= q.min ? ' disabled' : '') + '>' + ico('menos') + '</button>'
			+ '<div class="pa-precio-n" aria-live="polite"><span class="p-precio">' + cupG(p) + '</span><span>' + dif + '</span></div>'
			+ '<button type="button" class="p-btn p-btn--icono pa-pm" id="mas" aria-label="Subir 10 CUP"' + (p >= q.max ? ' disabled' : '') + '>' + ico('mas') + '</button></div>';
	}
	function engancharPrecio(r, q, alCambiar) {
		var poner = function (v) { S.precio = Math.min(q.max, Math.max(q.min, v)); guardar(); $('#ctl', r).innerHTML = controlPrecio(q, S.precio); engancharPrecio(r, q, alCambiar); alCambiar(S.precio); };
		$('#menos', r).onclick = function () { poner(precioDe(q) - 10); };
		$('#mas', r).onclick = function () { poner(precioDe(q) + 10); };
	}
	function nombrePago(p) { return (p || S.pago) === 'transferencia' ? 'Transfermóvil' : 'Efectivo'; }
	function hojaPago(alElegir) {
		var op = function (v, i, n) { return '<button type="button" class="p-fila" data-pago="' + v + '"><span class="p-fila__ico">' + ico(i) + '</span><span class="p-fila__txt"><b>' + n + '</b></span>' + (S.pago === v ? ico('check', 'p-ico--20') : '') + '</button>'; };
		U.hojaSobre('<p class="p-h2" style="margin:0 0 8px">¿Cómo pagas?</p>' + op('efectivo', 'efectivo', 'Efectivo') + op('transferencia', 'transfer', 'Transfermóvil'), function (h) {
			$$('[data-pago]', h).forEach(function (b) { b.onclick = function () { S.pago = b.dataset.pago; guardar(); h.remove(); if (alElegir) { alElegir(); } }; });
		});
	}

	SCR.precio = {
		mapa: function () { var c = rutaCot(); return c ? { ruta: c, rutaQuieta: true, ver: c, arriba: 70, pins: [['origen', c[0]], ['destino', c[c.length - 1]]] } : { ver: [ptoOrigen()], zoom: 15 }; },
		html: function () {
			return '<div class="pa-arriba">' + volverR('tipo') + '</div>'
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div><div id="cuerpo">' + esqueleto() + '</div></section>';
		},
		init: function (r) {
			if (!S.origen || !S.destino) { ir('destino'); return; }
			cotizar(S.grupo === 'taxi' || S.grupo === 'moto' ? S.grupo : 'tric').then(function (cq) {
				if (actual !== 'precio') { return; }
				if (cq.error) { $('#cuerpo', r).innerHTML = '<p class="p-aviso p-aviso--error">' + ico('alerta') + esc(cq.error) + '</p>'; return; }
				var q = claseCot(); S.clase = q.clase; var g = grupoDe(q.clase);
				$('#cuerpo', r).innerHTML = '<div class="pa-resumen"><img src="' + U.veh(imgDe(q.clase)) + '" alt="" width="56" height="32"><div><b>' + esc(nombreDe(q.clase)) + '</b><span>' + esc(corto(S.destino.texto)) + ' · ' + U.km(q.km) + ' km · ' + q.minutos + ' min</span></div></div>'
					+ '<div id="ctl">' + controlPrecio(q, precioDe(q)) + '</div>'
					+ (cq.noche ? '<p class="p-aviso" style="justify-content:center;margin:0 0 4px">' + ico('luna') + 'Tarifa de noche</p>' : '')
					+ '<div class="pa-op"><b>Coger la 1.ª oferta</b><button type="button" class="p-interruptor" role="switch" aria-checked="' + S.primera + '" id="primera" aria-label="Coger la primera oferta"></button></div>'
					+ '<button type="button" class="pa-op" id="pago">' + ico(S.pago === 'transferencia' ? 'transfer' : 'efectivo', 'p-ico--20') + '<b>' + nombrePago() + '</b>' + ico('sigue', 'p-ico--20') + '</button>'
					+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="pedir" style="margin-top:12px">Pedir ' + PALABRA[g] + ' · ' + cup(precioDe(q)) + '</button>';
				escenaActual();
				engancharPrecio(r, q, function (p) { $('#pedir', r).textContent = 'Pedir ' + PALABRA[g] + ' · ' + cup(p); });
				$('#primera', r).onclick = function () { S.primera = !S.primera; this.setAttribute('aria-checked', S.primera); };
				$('#pago', r).onclick = function () { hojaPago(function () { mostrar(true); }); };
				$('#pedir', r).onclick = function () { this.disabled = true; pedir({ grupo: g, tam: '' }, { cid: cid(), firma: q.firma, precio: precioDe(q), primera: S.primera ? 1 : 0, forma_pago: S.pago, origen_texto: S.origen.texto, destino_texto: S.destino.texto, nota: S.nota }); };
			});
		}
	};

	// ---------- Pedir (se guarda en el móvil; si no hay red, sale solo al volver) ----------
	var reintento = 0;
	function pedir(meta, body) { LS.set('pend', JSON.stringify({ meta: meta, body: body, t: Date.now() })); enviarPend(); }
	function enviarPend(otraVez) {
		var p = LS.json('pend'); clearTimeout(reintento);
		if (!p || !K) { return; }
		return api('viajes', p.body).then(function (x) {
			if (x.status === 0) { if (actual !== 'sinconexion') { ir('sinconexion'); } reintento = setTimeout(enviarPend, 8000); return; }
			if (x.data.ok) {
				LS.set('pend', null); S.precio = 0; guardar(); ponerViaje(x.data.viaje); seguir();
				if (actual === 'sinconexion') { var b = $('.pa-banda'); if (b) { b.classList.add('is-bien'); b.innerHTML = ico('check', 'p-ico--20') + '<span>Volvió la señal. Enviamos tu pedido.</span>'; } }
				ir(pantallaDe(x.data.viaje)); return;
			}
			if (x.data.code === 'precio_vencido' && !otraVez && S.origen && S.destino) {
				S.cot = null; // el precio caducó (p. ej. esperando señal): se vuelve a mirar y sale con la misma oferta
				return cotizar(p.meta.grupo, p.meta.tam).then(function (q) {
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
			var que = b ? (b.envio ? 'Envío a ' + corto(b.destino_texto) : PALABRA[p.meta.grupo].charAt(0).toUpperCase() + PALABRA[p.meta.grupo].slice(1) + ' a ' + corto(b.destino_texto)) : '';
			var txt = b ? 'Hola, quiero un ' + (b.envio ? 'mensajero' : PALABRA[p.meta.grupo]) + ' de ' + b.origen_texto + ' a ' + b.destino_texto + ' por ' + cup(b.precio) + '.' : '';
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
				+ '<div class="pa-tuya"><div><p class="p-mini" style="margin:0 0 4px">Tu oferta · ' + esc(nombreDe(v.clase)) + '</p><b id="tuya">' + cup(v.oferta) + '</b></div><button type="button" class="p-btn p-btn--suave p-btn--chico" id="subir">' + ico('mas', 'p-ico--16') + '10 %</button></div>'
				+ '<div class="pa-ofertas" id="ofertas"></div><div id="esq">' + esqueleto() + esqueleto() + '</div></section>';
		},
		init: function (r) {
			var v = S.viaje;
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
			$('#subir', r).hidden = v.oferta >= v.max;
			var cont = $('#ofertas', r), ids = {};
			(v.ofertas || []).forEach(function (o) {
				ids[o.id] = 1;
				if (S.ocultas[o.id] || $('[data-id="' + o.id + '"]', cont)) { return; }
				var el = document.createElement('article');
				el.className = 'p-oferta'; el.dataset.id = o.id; el.dataset.p = o.precio; el.dataset.m = o.llega; el.dataset.vence = o.vence;
				el.innerHTML = caraO(o.chofer)
					+ '<div class="p-oferta__q">' + esc(o.chofer.nombre) + ' ' + U.estr(o.chofer.nota) + '</div>'
					+ '<div class="p-oferta__precio">' + miles(o.precio) + '<small>' + (o.pct ? '+' + o.pct + ' %' : 'tu precio') + '</small></div>'
					+ '<div class="p-oferta__s">' + esc(o.chofer.tipo) + (o.chofer.color ? ' ' + esc(o.chofer.color.toLowerCase()) : '') + ' · ' + o.llega + ' min</div>'
					+ '<button type="button" class="p-btn p-btn--suave" data-si>Aceptar ' + miles(o.precio) + '</button>'
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
					+ (mas > v.oferta ? '<button type="button" class="p-btn p-btn--marca p-btn--ancho" data-otra="' + mas + '">Subir a ' + cup(mas) + ' y buscar</button>' : '')
					+ '<button type="button" class="p-btn p-btn--texto p-btn--ancho" data-otra="' + v.oferta + '">Buscar otra vez a ' + cup(v.oferta) + '</button>';
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
	function prepararTramo(v) {
		var rec = v.estado === 'recogido';
		var a = rec ? U.P(v.origen) : (v.pos ? [v.pos.lng, v.pos.lat] : null), b = rec ? U.P(v.destino) : U.P(v.origen);
		var clave = v.uuid + (rec ? 'r' : 'a');
		if (tramo && tramo.clave === clave) { return Promise.resolve(tramo); }
		if (!a) { return Promise.resolve(null); }
		var linea = rec ? U.lineaLonLat(v.linea) : null;
		return (linea ? Promise.resolve(linea) : U.ruta(a, b)).then(function (c) { c = c && c.length > 1 ? c : [a, b]; tramo = { clave: clave, c: c, largo: largo(c) }; return tramo; });
	}
	function escenaTramo(v) {
		var rec = v.estado === 'recogido', img = U.veh(imgDe(v.clase), 'arriba');
		var fin = rec ? [v.destino.lng, v.destino.lat] : [v.origen.lng, v.origen.lat];
		var et = rec ? esc(corto(v.destino.texto)) : (esEnvio(v) ? 'Recogida' : 'Tú');
		if (!tramo || tramo.clave !== v.uuid + (rec ? 'r' : 'a')) {
			var pins = [[rec ? 'destino' : 'origen', fin, et]], cond = [];
			if (v.pos) { cond.push(['chofer', img, [[v.pos.lng, v.pos.lat], [v.pos.lng + 0.0001, v.pos.lat]], 0]); }
			return { ver: v.pos ? [fin, [v.pos.lng, v.pos.lat]] : [fin], zoom: v.pos ? 0 : 16, pins: pins, conducir: cond };
		}
		return { ver: tramo.c, ruta: tramo.c, rutaQuieta: true, pins: [[rec ? 'destino' : 'origen', fin, et]], conducir: [['chofer', img, tramo.c, kDe(v)]] };
	}
	function kDe(v) {
		if (v.estado === 'llego') { return 1; }
		if (!tramo) { return 0; }
		if (!v.pos) { return 0; }
		return PMapa.avance(tramo.c, [v.pos.lng, v.pos.lat]);
	}
	function minutosResto(v, k) { return tramo ? Math.max(0, Math.ceil(tramo.largo * (1 - k) / (VEL[grupoDe(v.clase)] || 250))) : (v.estado === 'recogido' ? v.minutos : null); }
	function moverBarra(r, v) {
		var k = kDe(v), m = minutosResto(v, k);
		U.pintarBarra(r, k, 'chofer');
		var eta = $('#eta', r), llega = $('[data-llega]', r);
		if (v.estado === 'llego' || !eta) { return; }
		if (v.estado === 'recogido') { if (m != null) { eta.textContent = (esEnvio(v) ? 'Llega a las ' : 'Llegas a las ') + U.hora(m); if (llega) { llega.textContent = U.km(v.km) + ' km'; } } return; }
		eta.textContent = m == null ? 'Viene hacia ti' : (m <= 0 ? 'Llegando' : (esEnvio(v) ? 'Recoge en ' : 'Llega en ') + m + ' min');
		if (llega) { llega.textContent = m == null ? '' : 'Llega ' + U.hora(m); }
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
					var e = v.envio || {}, rec = v.estado === 'recogido';
					var txtWa = 'Hola ' + nombreCorto(e.recibe) + ', te mandé ' + e.que + ' con Pa\'llá. Te lo lleva ' + ch.nombre + '. Cuando llegue, dale este código: ' + e.codigo + '.';
					return arribaViaje('inicio') + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
						+ '<ol class="pa-etapas" aria-label="Estado del envío"><li class="' + (rec ? 'is-hecho' : 'is-ahora') + '">' + (rec ? 'Recogido' : 'Recogiendo') + '</li><li class="' + (rec ? 'is-ahora' : '') + '">En camino</li><li>Entregado</li></ol>'
						+ eta(esc(e.que) + ' · para ' + esc(nombreCorto(e.recibe)), llego ? 'Recogiendo' : 'Calculando…', v.total, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
						+ U.barra(imgDe(v.clase), '')
						+ filaChofer(ch, subChofer(ch, v))
						+ '<div class="pa-codigo"><span>Código de ' + esc(nombreCorto(e.recibe)) + '</span><b>' + esc(e.codigo) + '</b><a class="p-btn p-btn--suave p-btn--chico" href="' + waLink(e.telefono, txtWa) + '">' + ico('whatsapp', 'p-ico--16') + 'Mandar</a></div></section>';
				}
				if (paso === 'viene') {
					return arribaViaje() + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
						+ eta(llego ? 'Tu chofer llegó' : 'Tu ' + PALABRA[g] + ' viene', llego ? '' : 'Calculando…', v.precio, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
						+ U.barra(imgDe(v.clase), '')
						+ filaChofer(ch, subChofer(ch, v))
						+ '<div class="pa-codigo"><span>Código al subir</span><b>' + esc(v.codigo) + '</b></div>'
						+ '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="cancelar">Cancelar</button></section>';
				}
				return arribaViaje() + '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
					+ eta('Camino a ' + esc(corto(v.destino.texto)), 'Llegas a las ' + U.hora(v.minutos), v.total, v.espera ? 'Incluye ' + cup(v.espera) + ' de espera' : 'Fijo · no cambia')
					+ U.barra(imgDe(v.clase), U.km(v.km) + ' km')
					+ filaChofer(ch, subChofer(ch, v)) + '</section>';
			},
			init: function (r) {
				engancharViaje(r);
				var c = $('#cancelar', r); if (c) { c.onclick = function () { cancelar(this); }; }
				var self = this;
				prepararTramo(S.viaje).then(function () { if (actual && SCR[actual] === self && S.viaje) { escenaActual(); moverBarra(r, S.viaje); } });
				moverBarra(r, S.viaje); pintarEspera(r, S.viaje);
				I(function () { if (S.viaje) { pintarEspera(r, S.viaje); } }, 1000);
			},
			actualizar: function (r, v) {
				var antes = tramo && tramo.clave;
				if (paso === 'envio' && $$('.pa-etapas li', r)[0].className !== (v.estado === 'recogido' ? 'is-hecho' : 'is-ahora')) { mostrar(true); return; }
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
				+ '<div class="pa-hecho">' + ico('check', 'p-ico--20') + '<div><p class="p-h2" style="margin:0">Llegaste a ' + esc(corto(v.destino.texto)) + '</p><p class="p-peq" style="margin:0">' + (v.terminado ? U.hhmm(new Date(v.terminado)) : U.hora(0)) + ' · ' + U.km(v.km) + ' km con ' + esc(ch.nombre || '') + '</p></div></div>'
				+ monto('A pagar a ' + q + (v.espera ? ' · ' + cup(v.precio) + ' + ' + cup(v.espera) + ' de espera' : ' · lo acordado'), v.total)
				+ bloquePago(v)
				+ (v.valorado ? '' : '<div><p class="pa-etq" style="text-align:center">¿Qué tal el viaje?</p>' + U.estrellas(S.estrellas) + '</div>'
					+ '<div id="tras" class="pa-tras"' + (S.estrellas ? '' : ' hidden') + '><div class="pa-chips" style="justify-content:center"><button type="button" class="p-chip" data-etq="puntual">Puntual</button><button type="button" class="p-chip" data-etq="amable">Amable</button><button type="button" class="p-chip" data-etq="buen_vehiculo">Buen vehículo</button><button type="button" class="p-chip" data-etq="manejo_bien">Manejó bien</button></div>'
					+ '<p class="pa-etq" style="text-align:center;margin-top:16px">Propina para ' + q + '</p><div class="pa-chips" style="justify-content:center"><button type="button" class="p-chip" data-prop="10">+10</button><button type="button" class="p-chip" data-prop="20">+20</button><button type="button" class="p-chip" data-prop="50">+50</button><button type="button" class="p-chip" data-prop="0" aria-pressed="true">Nada</button></div></div>')
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
			var v = S.viaje, ch = v.chofer || {}, e = v.envio || {};
			S.pagoFin = S.pagoFin || v.forma_pago;
			return '<div class="pa-llena"><div class="pa-cab"><a class="pa-volver" href="#inicio" aria-label="Volver">' + ico('atras') + '</a><h1>Entregado</h1></div><div class="pa-cuerpo">'
				+ '<div class="pa-hecho">' + ico('check', 'p-ico--20') + '<p style="margin:0">' + esc(nombreCorto(e.recibe) || 'Lo') + ' lo recibió a las ' + (v.terminado ? U.hhmm(new Date(v.terminado)) : U.hora(0)) + ' · código ' + esc(e.codigo) + '</p></div>'
				+ (e.foto ? '<figure style="margin:0"><img class="pa-foto" src="' + esc(e.foto) + '" alt="Foto de la entrega" loading="lazy" style="object-fit:cover"><figcaption class="p-peq" style="margin-top:6px">Foto de ' + esc(ch.nombre || 'tu mensajero') + '</figcaption></figure>' : '')
				+ '<div class="pa-total"><span>A pagar</span><span class="p-precio" style="font-size:28px">' + cupG(v.total) + '</span></div>'
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
			return '<div class="pa-llena"><div class="pa-cab">' + volver('inicio') + '<h1>Mis viajes</h1></div><div class="pa-cuerpo" style="gap:0" id="lista">' + pintarViajes(LS.json('viajes')) + '</div></div>';
		},
		init: function (r) {
			var enganchar = function () {
				$$('[data-rep]', r).forEach(function (a) {
					a.onclick = function () {
						var x = (LS.json('viajes') || [])[+a.dataset.rep]; if (!x) { return; }
						S.origen = x.origen; S.destino = x.destino; S.precio = 0; S.cot = null;
						if (x.clase === 'mensajero') { S.svc = 'mens'; guardar(); ir('envio'); return; }
						S.grupo = x.grupo; S.svc = x.grupo === 'taxi' ? 'taxi' : 'tric'; S.clase = x.clase; guardar(); ir('precio');
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
		var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
		var lunes = new Date(hoy); lunes.setDate(hoy.getDate() - ((hoy.getDay() + 6) % 7));
		var grupo = '', h = '', DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
		l.forEach(function (x, i) {
			var f = new Date(x.fecha), gr = f >= hoy ? 'Hoy' : (f >= lunes ? 'Esta semana' : 'Antes');
			if (gr !== grupo) { h += '<p class="pa-etq"' + (grupo ? ' style="margin-top:20px"' : '') + '>' + gr + '</p>'; grupo = gr; }
			var cuando = gr === 'Hoy' ? U.hhmm(f) : (gr === 'Esta semana' ? DIAS[f.getDay()] + ' ' + U.hhmm(f) : f.getDate() + '/' + (f.getMonth() + 1));
			var estado = x.estado === 'cancelado' ? ' · cancelado' : (x.estado === 'sin_chofer' ? ' · sin chofer' : '');
			var t = x.clase === 'mensajero' ? 'Envío a ' + corto(x.destino.texto) : corto(x.destino.texto);
			h += '<button type="button" class="p-fila pa-viaje" data-rep="' + i + '"><span class="p-fila__ico" style="color:var(--' + COLOR_G[x.grupo] + ')">' + ico(ICO_G[x.grupo] || 'triciclo') + '</span><span class="p-fila__txt"><b>' + esc(t) + '</b><span>' + esc(nombreDe(x.clase)) + ' · ' + cuando + (x.chofer ? ' · ' + esc(nombreCorto(x.chofer)) : '') + (x.foto ? ' · con foto' : '') + estado + '</span></span><span class="p-fila__fin">' + (x.estado === 'terminado' ? cup(x.total) : '') + '</span></button>';
		});
		return h + '<p class="p-aviso" style="margin-top:20px">' + ico('guardado') + 'Se ven aunque no haya señal.</p>';
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
				+ '<p class="pa-etq">Pantalla</p><div class="pa-seg" style="grid-template-columns:1.4fr 1fr 1fr">' + op('auto', 'De noche') + op('claro', 'Claro') + op('oscuro', 'Oscuro') + '</div>'
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
			return '<div class="pa-llena"><div class="pa-cab">' + volver('inicio') + '<h1>Mandar algo</h1></div><div class="pa-cuerpo">'
				+ '<div class="pa-od"><span class="pm-pin"></span><span class="pm-pin pm-pin--destino"></span>'
				+ campo('envio-o', S.origen && S.origen.texto, 'Recoger en', '¿Dónde lo recogemos?') + campo('envio-d', S.destino && S.destino.texto, 'Llevar a', '¿A dónde lo llevamos?') + '</div>'
				+ '<div><label class="pa-etq" for="que">¿Qué mandas?</label><label class="p-campo"><input id="que" maxlength="120" value="' + esc(e.que) + '" placeholder="Llaves, un sobre…"></label></div>'
				+ '<div><p class="pa-etq">Tamaño</p><div class="pa-tamano">' + tam('mano', 'llave', 'En la mano', 'llaves, sobre') + tam('mochila', 'paquete', 'Mochila', 'hasta 10 kg') + tam('grande', 'caja', 'Grande', 'en triciclo') + '</div></div>'
				+ '<p class="p-aviso">' + ico('info') + 'Nada prohibido. Si mandas dinero, dilo.</p>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '</div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="seguir">Seguir</button></div></div>';
		},
		init: function (r) {
			S.svc = 'mens'; guardar();
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

	SCR['envio-precio'] = {
		mapa: function () { var c = rutaCot(); return c ? { ruta: c, ver: c, arriba: 70, pins: [['origen', c[0]], ['destino', c[c.length - 1], esc(nombreCorto(S.envio.recibe))]] } : { ver: [ptoOrigen()], zoom: 15 }; },
		html: function () { return '<div class="pa-arriba">' + volverR('envio-recibe') + '</div><section class="pa-hoja"><div class="p-hoja__asa"></div><div id="cuerpo">' + esqueleto() + '</div></section>'; },
		init: function (r) {
			if (!S.origen || !S.destino) { ir('envio'); return; }
			var e = S.envio;
			cotizar('mens', e.tam).then(function (cq) {
				if (actual !== 'envio-precio') { return; }
				if (cq.error) { $('#cuerpo', r).innerHTML = '<p class="p-aviso p-aviso--error">' + ico('alerta') + esc(cq.error) + '</p>'; return; }
				var q = cq.clases[0]; S.clase = 'mensajero';
				$('#cuerpo', r).innerHTML = '<div class="pa-resumen"><img src="' + U.veh(e.tam === 'grande' ? 'tric-carga' : 'mensajero') + '" alt="" width="56" height="32"><div><b>' + esc(e.que) + '</b><span>' + esc(corto(S.destino.texto)) + ' · ' + U.km(q.km) + ' km</span></div></div>'
					+ '<div id="ctl">' + controlPrecio(q, precioDe(q)) + '</div>'
					+ '<p class="p-aviso" style="justify-content:center;margin:4px 0 8px">' + ico('camara') + 'Foto al entregar</p>'
					+ '<button type="button" class="pa-op" id="pago">' + ico(S.pago === 'transferencia' ? 'transfer' : 'efectivo', 'p-ico--20') + '<b>' + nombrePago() + '</b>' + ico('sigue', 'p-ico--20') + '</button>'
					+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="pedir" style="margin-top:12px">Buscar mensajero · ' + cup(precioDe(q)) + '</button>';
				escenaActual();
				engancharPrecio(r, q, function (p) { $('#pedir', r).textContent = 'Buscar mensajero · ' + cup(p); });
				$('#pago', r).onclick = function () { hojaPago(function () { mostrar(true); }); };
				$('#pedir', r).onclick = function () {
					this.disabled = true;
					var t = e.tel.length === 10 ? e.tel.slice(2) : e.tel;
					pedir({ grupo: 'mens', tam: e.tam }, { cid: cid(), firma: q.firma, precio: precioDe(q), primera: 0, forma_pago: S.pago, origen_texto: S.origen.texto, destino_texto: S.destino.texto, nota: e.nota, envio: { que: e.que, recibe_nombre: e.recibe, recibe_telefono: t, cobrar_cup: e.cobrar ? e.cobrarCup : 0 } });
				};
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
		var ms = document.hidden ? 30000 : (v.estado === 'buscando' || v.estado === 'sin_chofer' ? 4000 : 15000);
		poll = setTimeout(function () { refrescar().then(programar); }, ms);
	}
	function refrescar() {
		var v = S.viaje; if (!v || pidiendo) { return Promise.resolve(); }
		pidiendo = true;
		return api('viajes/' + v.uuid + '?rev=' + v.rev).then(function (x) {
			pidiendo = false;
			if (x.status === 404) { ponerViaje(null); dejar(); ir('inicio'); return; }
			if (!x.data.ok) { return; }
			if (x.data.igual) { if (x.data.pos && S.viaje) { S.viaje.pos = x.data.pos; alPos(); } return; }
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
			if (nuevo.estado === 'llego') { U.tostada(e ? 'Tu mensajero llegó a recoger.' : 'Tu chofer llegó. Dile el código ' + nuevo.codigo + '.'); vibrar([200, 100, 200]); }
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
		var titulos = { inicio: '', entrar: 'Entrar', codigo: 'Código', destino: 'Destino', fijar: 'Fijar en el mapa', recogida: 'Recogida', tipo: 'Elegir', precio: 'Precio', buscando: 'Buscando', viene: 'Tu chofer viene', viaje: 'En viaje', llegada: 'Pagar', viajes: 'Mis viajes', perfil: 'Perfil', envio: 'Mandar algo', 'envio-recibe': 'Quién recibe', 'envio-precio': 'Precio del envío', 'envio-seguimiento': 'Envío', 'envio-entregado': 'Entregado', sinconexion: 'Sin señal' };
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
