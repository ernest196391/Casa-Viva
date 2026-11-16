/* Pa'llá transporte (5-nov-2026) · app del CHOFER en /palla/chofer/ (taxi, triciclo, moto y mensajero de envíos).
 * Pantallas del diseño v2 aprobado (prototipo/app.js, «ch-…») con los datos reales de la API casa-viva/v1/pv/chofer/…
 * Mismo enlace que Pa'llá: localStorage «palla.k» → cabecera X-Palla-Token (también vale #k=… en la URL).
 * En vivo: tema callado del chofer por ntfy (PU.enVivo(hoy.tema)) + pregunta a la web. Posición cada 30 s en línea y
 * cada 10 s con viaje. Suena y vibra con cada pedido nuevo.
 * v3 (6-nov, D142): etiquetas del pedido («Comida · adelanta 2.400», «Carga · 50–150 kg · con ayudante»), foto del recibo
 * al recoger la comida que adelanta y el interruptor «Puedo adelantar dinero». Las pantallas no cambian.
 * Reparto (16-nov, D157; solo con cvd_pv_reparto = si, si no el servidor no manda «reparto»): «Te toca» (Aceptar/Rechazar),
 * «¿Cuándo sales?», programados con «Ya no puedo», «¿Sigues?» con «Sigo», «Lo lleva…», personas de confianza en Mi perfil
 * y la vista limitada de la persona de confianza (enlace #a=…: solo Recogí, Entregado y Problema). */
(function () {
	'use strict';
	var C = window.PV || {}, U = window.PU;
	if (!U || !window.PMapa) { return; }
	var $ = U.$, $$ = U.$$, ico = U.ico, esc = U.esc, cup = U.cup, cupG = U.cupG, miles = U.miles, LS = U.LS;
	var pant = $('#pant'), marco = $('#marco'), mapaEl = $('#mapa');
	var TEMA = document.documentElement.dataset.tema;
	var CLASES = C.clases || {};
	var TAM = C.tamanos || { mano: 'En la mano', mochila: 'Mochila', grande: 'Grande' };
	var VEL = { taxi: 400, tric: 250, moto: 400, mens: 380 };

	// ---------- Token (el mismo enlace de Pa'llá) ----------
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { if (v == null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) { } }
	var mk = /(?:^#|&)k=([0-9A-Za-z._-]+)/.exec(location.hash || '');
	if (mk) { lsSet('palla.k', mk[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	var K = lsGet('palla.k') || '';
	var ma = /(?:^#|&)a=(a[0-9]+\.[0-9]+\.[a-f0-9]{32})/.exec(location.hash || '');
	if (ma) { lsSet('pv.a', ma[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	var AY = K ? '' : (lsGet('pv.a') || ''); // Reparto: persona de confianza (sin enlace de mensajero en este móvil)

	var H = LS.json('ch.hoy'); // lo último de chofer/hoy (se ve aunque no haya señal)
	var S = { cobrar: LS.json('ch.cobrar'), pos: null, visto: {}, perdidos: {}, msg: '' };
	function api(path, body, metodo) {
		return U.api(path, body, { 'X-Palla-Token': K }, metodo).then(function (r) {
			if (r.status === 401) { S.msg = r.data.message || 'Tu enlace ya no vale. Pide uno nuevo por WhatsApp.'; K = ''; ir('ch-sin'); }
			else if (r.status === 403 && /^pv_(closed|not_tester)$/.test(r.data.code || '')) { S.msg = r.data.message; ir('ch-cerrado'); }
			return r;
		});
	}

	// ---------- Utilidades ----------
	var timers = [];
	function T(fn, ms) { timers.push(setTimeout(fn, ms)); }
	function I(fn, ms) { timers.push(setInterval(fn, ms)); }
	function ir(id) { if (location.hash === '#' + id) { mostrar(true); } else { location.hash = id; } }
	function corto(t) { return String(t || 'Punto en el mapa').split(',')[0].split(' e/')[0]; }
	function nombreCorto(n) { return String(n || '').split(' ')[0]; }
	function grupoDe(clase) { return (CLASES[clase] && CLASES[clase].grupo) || 'tric'; }
	function imgDe(clase) { return (CLASES[clase] && CLASES[clase].img) || 'tric-electrico'; }
	function metros(m) { return m < 1000 ? (Math.round(m / 50) * 50 || 50) + ' m' : U.km(m / 1000) + ' km'; }
	function vibrar(p) { try { if (navigator.vibrate) { navigator.vibrate(p); } } catch (e) { } }
	function waLink(tel, txt) { return 'https://wa.me/' + U.tel53(tel) + (txt ? '?text=' + encodeURIComponent(txt) : ''); }
	function nombrePago(p) { return p === 'transferencia' ? 'Transfermóvil' : 'Efectivo'; }
	function dist(a, b) { var x = (b[0] - a[0]) * Math.cos((a[1] + b[1]) * Math.PI / 360), y = b[1] - a[1]; return Math.sqrt(x * x + y * y) * 111320; }
	function largo(c) { var l = 0; for (var i = 1; i < c.length; i++) { l += dist(c[i - 1], c[i]); } return l; }
	function guardarH() { LS.set('ch.hoy', H ? JSON.stringify(H) : null); appSync(); }
	// 10-nov: dentro de la app Android «Pa'llá Chofer» (puente window.PallaApp) la web le dice su enlace, si está En línea y
	// si lleva un viaje: la app suena con la pantalla apagada y manda la posición en segundo plano (la web no puede).
	var APP = window.PallaApp && typeof window.PallaApp.setToken === 'function' ? window.PallaApp : null, appYa = {};
	function appSync() {
		if (!APP || !K) { return; }
		try {
			if (appYa.k !== K) { appYa.k = K; APP.setToken(K); }
			var on = !!(H && H.ficha && H.ficha.disponible), act = !!(H && H.activo);
			if (appYa.on !== on) { appYa.on = on; APP.setAvailable(on, 0); }
			if (appYa.act !== act && APP.setActive) { appYa.act = act; APP.setActive(act); }
		} catch (e) { }
	}

	// Sonido del pedido nuevo (WebAudio, sin archivos). El navegador lo deja sonar tras el primer toque en la pantalla.
	var ac = null;
	function desbloquear() { try { ac = ac || new (window.AudioContext || window.webkitAudioContext)(); if (ac.state === 'suspended') { ac.resume(); } } catch (e) { ac = null; } }
	document.addEventListener('pointerdown', desbloquear);
	function sonar() {
		vibrar([300, 120, 300]);
		if (!ac) { return; }
		try {
			[0, 0.22, 0.44].forEach(function (t, i) {
				var o = ac.createOscillator(), g = ac.createGain(), t0 = ac.currentTime + t;
				o.frequency.value = i === 2 ? 1320 : 880;
				g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.35, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.18);
				o.connect(g); g.connect(ac.destination); o.start(t0); o.stop(t0 + 0.2);
			});
		} catch (e) { }
	}
	// Pantalla encendida mientras está en línea (si el móvil lo deja).
	var luz = null;
	function pantallaEncendida(si) {
		if (window.PallaApp && window.PallaApp.setToken) { return; } // 10-nov (auditoría): en la app no hace falta (y gasta batería)
		try {
			if (si && !luz && navigator.wakeLock) { navigator.wakeLock.request('screen').then(function (l) { luz = l; l.addEventListener('release', function () { luz = null; }); }).catch(function () { }); }
			if (!si && luz) { luz.release(); luz = null; }
		} catch (e) { }
	}

	// ---------- GPS y posición ----------
	var vigia = null, envio = 0;
	function gpsUna() {
		return new Promise(function (ok) {
			if (!navigator.geolocation) { ok(null); return; }
			var hecho = false; setTimeout(function () { if (!hecho) { hecho = true; ok(S.pos); } }, 10000);
			navigator.geolocation.getCurrentPosition(function (p) { if (!hecho) { hecho = true; S.pos = { lat: p.coords.latitude, lng: p.coords.longitude, prec: Math.round(p.coords.accuracy || 0) }; ok(S.pos); } }, function () { if (!hecho) { hecho = true; ok(null); } }, { enableHighAccuracy: true, timeout: 9000, maximumAge: 30000 });
		});
	}
	function seguirGps() {
		var hace = H && H.ficha && (H.ficha.disponible || H.activo);
		if (!hace) { if (vigia !== null && navigator.geolocation) { navigator.geolocation.clearWatch(vigia); } vigia = null; clearTimeout(envio); pantallaEncendida(false); return; }
		pantallaEncendida(true);
		if (vigia === null && navigator.geolocation) {
			vigia = navigator.geolocation.watchPosition(function (p) { S.pos = { lat: p.coords.latitude, lng: p.coords.longitude, prec: Math.round(p.coords.accuracy || 0) }; alPos(); }, function () { }, { enableHighAccuracy: true, maximumAge: 10000 });
		}
		if (!envio) { mandarPos(); }
	}
	function mandarPos() {
		clearTimeout(envio); envio = 0;
		if (!H || !H.ficha || !(H.ficha.disponible || H.activo)) { return; }
		if (window.PallaApp && window.PallaApp.setToken && !document.hidden) { envio = setTimeout(mandarPos, 60000); return; } // 10-nov: en la app la manda el servicio (una sola fuente)
		var sig = function () { envio = setTimeout(mandarPos, H && H.activo ? 10000 : 30000); };
		if (!S.pos) { sig(); return; }
		api('chofer/posicion', { lat: S.pos.lat.toFixed(6), lng: S.pos.lng.toFixed(6), precision: S.pos.prec }).then(sig, sig);
	}
	function alPos() { var s = SCR[actual]; if (s && s.alPos) { s.alPos(pant); } }

	// =====================================================================================
	// PANTALLAS
	// =====================================================================================
	var SCR = {};
	function cara(px) { var f = H && H.ficha; return f && f.foto ? '<img class="p-cara" src="' + esc(f.foto) + '" alt="" style="object-fit:cover' + (px ? ';width:' + px + 'px;height:' + px + 'px' : '') + '">' : '<span class="p-cara"' + (px ? ' style="width:' + px + 'px;height:' + px + 'px;font-size:16px"' : '') + '>' + esc(U.iniciales(H && H.nombre)) + '</span>'; }
	function cabChofer(t, atras) {
		return '<div class="pa-cab">' + (atras ? '<a class="pa-volver" href="#' + atras + '" aria-label="Volver">' + ico('atras') + '</a>' : '<span style="width:12px"></span>')
			+ '<h1>' + t + '</h1><a class="pa-volver" href="#ch-perfil" aria-label="Mi perfil">' + cara() + '</a></div>';
	}
	function eta(mini, grande, precioN, sub) {
		return '<div class="pa-eta"><div><p class="p-mini">' + mini + '</p><b id="eta">' + grande + '</b></div><div><b class="pa-eta__pr">' + cup(precioN) + '</b><span id="eta-sub">' + sub + '</span></div></div>';
	}
	function filaPersona(n, nota, sub, tel) {
		var t = tel ? U.tel53(tel) : '';
		return '<div class="pa-chofer"><span class="p-cara">' + esc(U.iniciales(n)) + '</span><div class="pa-chofer__q"><b>' + esc(n) + (nota === false ? '' : ' ' + U.estr(nota)) + '</b><span>' + sub + '</span></div>'
			+ (t ? '<a class="p-btn p-btn--icono" href="tel:+' + t + '" aria-label="Llamar">' + ico('telefono', 'p-ico--20') + '</a><a class="p-btn p-btn--icono" href="' + waLink(t) + '" aria-label="Mensaje por WhatsApp">' + ico('whatsapp', 'p-ico--20') + '</a>' : '') + '</div>';
	}
	function monto(mini, n) { return '<div class="pa-monto"><p class="p-mini">' + mini + '</p><span class="p-precio">' + cupG(n) + '</span></div>'; }
	function cajasCodigo(etq) {
		return '<p class="pa-etq" style="text-align:center;margin-top:12px">' + etq + '</p><div class="pa-cajas" style="position:relative;margin-bottom:16px"><span></span><span></span><span></span><span></span>'
			+ '<input id="cod" inputmode="numeric" autocomplete="off" maxlength="4" aria-label="Código de 4 cifras" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;border:0"></div>';
	}
	function engancharCajas(r, alCambiar) {
		var cod = $('#cod', r), cajas = $$('.pa-cajas span', r); if (!cod) { return; }
		var pintar = function () { var v = cod.value.replace(/\D+/g, '').slice(0, 4); cod.value = v; cajas.forEach(function (s, i) { s.textContent = v.charAt(i); s.classList.toggle('is-act', i === Math.min(3, v.length) && document.activeElement === cod); }); if (alCambiar) { alCambiar(v); } };
		cod.addEventListener('input', pintar); cod.addEventListener('focus', pintar); cod.addEventListener('blur', pintar);
	}
	function avisoErr(r, m) { var e = $('#err', r); if (!e) { U.tostada(m, 'alerta'); return; } e.hidden = false; e.innerHTML = ico('alerta') + '<span>' + esc(m) + '</span>'; }

	SCR['ch-sin'] = {
		libre: true,
		html: function () {
			return '<div class="pa-llena"><div class="pa-cuerpo" style="padding-top:96px;align-items:center;text-align:center">'
				+ '<h1 class="p-titulo">Pa\'llá · Chofer</h1><p class="p-2">' + esc(S.msg || 'Abre esta página con tu enlace de Pa\'llá.') + '</p>'
				+ (C.telefono ? '<a class="p-btn p-btn--marca" href="' + waLink(C.telefono, 'Hola, necesito mi enlace de chofer de Pa\'llá.') + '">' + ico('whatsapp', 'p-ico--20') + 'Pedir mi enlace</a>' : '')
				+ '<a class="pa-enlace" href="' + esc(C.alta) + '">¿Todavía no eres chofer? Date de alta</a></div></div>';
		}
	};
	SCR['ch-cerrado'] = {
		libre: true,
		html: function () { return '<div class="pa-llena"><div class="pa-cuerpo" style="padding-top:96px;align-items:center;text-align:center"><h1 class="p-titulo">Pa\'llá · Chofer</h1><p class="p-2">' + esc(S.msg || 'Pa\'llá todavía no está abierto.') + '</p></div></div>'; }
	};
	SCR['ch-alta'] = {
		html: function () {
			var e = H ? H.estado : 'nuevo';
			var txt = { revision: 'Estamos revisando tus papeles. Te avisamos por WhatsApp.', suspendido: 'Tu cuenta de chofer está parada. Escríbenos por WhatsApp.', pendiente: 'Falta algo en tu alta. Míralo y vuelve a mandarla.' }[e] || 'Todavía no eres chofer de Pa\'llá. Termina tu alta.';
			return '<div class="pa-llena">' + cabChofer('Hola, ' + esc(nombreCorto(H && H.nombre))) + '<div class="pa-cuerpo"><p class="p-aviso">' + ico('info') + esc(txt) + '</p></div>'
				+ '<div class="pa-pie"><a class="p-btn p-btn--marca p-btn--ancho" href="' + esc((H && H.alta) || C.alta) + '">' + (e === 'revision' ? 'Ver mi alta' : 'Terminar mi alta') + '</a></div></div>';
		}
	};

	// ---------- Conectarse ----------
	SCR['ch-conectar'] = {
		html: function () {
			var f = H.ficha, on = f.disponible, g = grupoDe(f.clase), n = (H.pedidos || []).length, cu = H.cuenta || {};
			var op = function (id, b, s, v) { return '<div class="pa-op"><div><b>' + b + '</b><span>' + s + '</span></div><button type="button" class="p-interruptor" role="switch" aria-checked="' + v + '" data-op="' + id + '" aria-label="' + b + '"></button></div>'; };
			var ops = (g !== 'mens' ? op('pasaje', 'Pasaje', esc(f.tipo) + (f.plazas ? ' · ' + f.plazas + ' plazas' : ''), f.pasaje) : '') + (g === 'tric' || g === 'moto' ? op('envios', 'Envíos', g === 'moto' || f.clase === 'tric_bici' ? 'En la mano o mochila' : '', f.envios) : '')
				// v3: solo quien lo marca ve la comida que hay que pagar al recoger (y le vuelve al entregar).
				+ (g === 'mens' || f.envios ? op('adelanta', 'Puedo adelantar dinero', 'Hasta ' + cup(f.adelanta_tope || 5000), !!f.adelanta) : '');
			return '<div class="pa-llena">' + cabChofer('Hola, ' + esc(nombreCorto(H.nombre))) + '<div class="pa-cuerpo">'
				+ '<div class="pa-total"><div><p class="p-mini">Hoy</p><span class="p-precio">' + cupG(H.hoy.cup) + '</span></div><a class="p-btn p-btn--suave p-btn--chico" href="#ch-ganancias">' + H.hoy.viajes + (H.hoy.viajes === 1 ? ' viaje' : ' viajes') + ico('sigue', 'p-ico--16') + '</a></div>'
				+ '<button type="button" class="pa-conectar ' + (on ? 'is-on' : '') + '" id="con" aria-pressed="' + on + '">' + ico('encender') + '<span>' + (on ? 'En línea' : 'Conectarme') + '</span></button>'
				+ (cu.pausa ? '<a class="p-aviso p-aviso--error" href="#ch-deuda" style="text-decoration:none">' + ico('alerta') + '<span>Debes ' + cup(cu.debe) + ': solo te llegan viajes por Transfermóvil.</span></a>' : '')
				+ '<div id="prog">' + programadosHtml() + '</div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ (ops ? '<div><p class="pa-etq">Qué acepto hoy</p>' + ops + '</div>' : '')
				+ '</div>' + (on ? '<div class="pa-pie"><a class="p-btn p-btn--marca p-btn--ancho" href="#ch-pedidos">' + (n ? 'Ver pedidos cerca (' + n + ')' : 'Ver pedidos cerca') + '</a></div>' : '') + '</div>';
		},
		init: function (r) {
			var mandar = function (on, extra, b) {
				var cuerpo = { on: on ? 1 : 0 };
				Object.keys(extra || {}).forEach(function (k) { cuerpo[k] = extra[k]; });
				var seguir = function (p) {
					if (on && !p) { if (b) { b.disabled = false; } avisoErr(r, 'Activa la ubicación del teléfono para recibir pedidos.'); return; }
					if (p) { cuerpo.lat = p.lat.toFixed(6); cuerpo.lng = p.lng.toFixed(6); }
					api('chofer/disponible', cuerpo).then(function (x) {
						if (b) { b.disabled = false; }
						if (!x.data.ok) { avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
						H.ficha.disponible = !!x.data.disponible;
						if (extra && 'pasaje' in extra) { H.ficha.pasaje = !!extra.pasaje; }
						if (extra && 'envios' in extra) { H.ficha.envios = !!extra.envios; }
						if (extra && 'adelanta' in extra) { H.ficha.adelanta = !!extra.adelanta; }
						guardarH(); seguirGps(); mostrar(true); cargar();
					});
				};
				if (on) { (S.pos ? Promise.resolve(S.pos) : gpsUna()).then(seguir); } else { seguir(null); }
			};
			$('#con', r).onclick = function () { desbloquear(); this.disabled = true; mandar(!H.ficha.disponible, null, this); };
			$$('[data-op]', r).forEach(function (b) {
				b.onclick = function () { var v = b.getAttribute('aria-checked') !== 'true', x = {}; x[b.dataset.op] = v ? 1 : 0; b.setAttribute('aria-checked', v); mandar(H.ficha.disponible, x); };
			});
			engancharProg(r);
		},
		actualizar: function (r) {
			var a = $('.pa-pie a', r), n = (H.pedidos || []).length; if (a) { a.textContent = n ? 'Ver pedidos cerca (' + n + ')' : 'Ver pedidos cerca'; }
			var pg = $('#prog', r), h = programadosHtml(); if (pg && pg.dataset.h !== h) { pg.dataset.h = h; pg.innerHTML = h; engancharProg(r); }
		}
	};

	// ---------- Pedidos con oferta ----------
	function firma(p) { return p.id + '|' + p.oferta + '|' + (p.mia ? p.mia.estado + p.mia.precio : ''); }
	function tarjeta(p) {
		var cli = p.cliente || { nombre: 'Cliente' }, env = p.envio;
		var quien = esc(cli.nombre) + (p.pide ? ' (lo pide ' + esc(p.pide) + ')' : '') + ' ' + (cli.nota != null ? '★ ' + String(cli.nota).replace('.', ',') : (cli.viajes ? '' : '· nuevo')) + (env ? ' · ' + esc((TAM[env.tam] || '').toLowerCase()) + ' · ' + esc(env.que) : (cli.viajes ? ' · ' + cli.viajes + ' viajes' : '')) + (p.forma_pago === 'transferencia' ? ' · Transfermóvil' : '');
		var bots = (p.contra || []).map(function (c) { return '<button type="button" class="' + (c.pct ? '' : 'acepto') + '" data-pct="' + c.pct + '">' + (c.pct ? miles(c.precio) + '<small>+' + c.pct + ' %</small>' : 'Acepto<small>' + miles(c.precio) + '</small>') + '</button>'; }).join('');
		var mia = p.mia && p.mia.estado === 'activa';
		return '<article class="pa-pedido' + (mia ? ' is-enviado' : '') + '" data-id="' + p.id + '" data-firma="' + esc(firma(p)) + '" data-hasta="' + esc(mia ? p.mia.vence : p.hasta) + '" data-total="' + (mia ? 60 : 240) + '">'
			+ '<div class="arriba"><div><span class="p-precio" style="font-size:30px">' + cupG(p.oferta) + '</span><div class="p-peq">' + quien + '</div></div><span class="p-sello">' + (env ? ({ comida: 'Comida', carga: 'Carga' }[p.servicio] || 'Envío') : 'Pasaje') + '</span></div>'
			// v3: «Comida · adelanta 2.400», «Carga · 50–150 kg · con ayudante» (lo manda el servidor)
			+ (p.etiqueta && p.etiqueta !== 'Pasaje' && p.etiqueta !== 'Paquete' ? '<p class="p-aviso' + (env && env.adelanto ? ' p-aviso--aviso' : '') + '" style="margin:8px 0 0">' + ico(p.servicio === 'carga' ? 'caja' : 'efectivo') + '<span><b>' + esc(p.etiqueta) + '</b>' + (env && env.recoge ? ' · en ' + esc(env.recoge) : '') + '</span></p>' : '')
			+ '<div class="od"><span class="pm-pin"></span><b>' + esc(corto(p.origen.texto)) + '</b><small>A ' + metros(p.lejos_m) + ' de ti</small><span class="pm-pin pm-pin--destino"></span><b>' + esc(corto(p.destino.texto)) + '</b><small>Viaje de ' + U.km(p.km) + ' km</small></div>'
			+ (p.nota ? '<p class="p-aviso">' + ico('nota') + '«' + esc(p.nota) + '»</p>' : '')
			+ '<div class="pa-contra">' + bots + '</div>'
			+ (mia ? '<p class="p-aviso">' + ico('reloj') + '<span>Enviaste <b>' + cup(p.mia.precio) + '</b>. Esperando…</span></p>' : '')
			+ '<i class="p-oferta__tiempo" aria-hidden="true"></i></article>';
	}
	SCR['ch-pedidos'] = {
		html: function () {
			return '<div class="pa-llena">' + cabChofer('Pedidos cerca', 'ch-conectar') + '<div class="pa-cuerpo" style="gap:0" id="lista"></div></div>';
		},
		init: function (r) {
			this.actualizar(r);
			I(function () {
				$$('.pa-pedido', r).forEach(function (el) {
					var s = U.seg(el.dataset.hasta), t = $('.p-oferta__tiempo', el);
					if (t) { t.style.transform = 'scaleX(' + Math.max(0, Math.min(1, s / (+el.dataset.total || 240))).toFixed(3) + ')'; }
				});
			}, 1000);
		},
		actualizar: function (r) {
			var lista = $('#lista', r), ped = H.pedidos || [], ids = {};
			if (!H.ficha.disponible) { lista.innerHTML = '<p class="p-aviso" style="margin-top:8px">' + ico('info') + 'Conéctate para recibir pedidos.</p><a class="p-btn p-btn--marca p-btn--ancho" href="#ch-conectar" style="margin-top:16px">Conectarme</a>'; return; }
			var vacio = $('[data-vacio]', lista); if (vacio && ped.length) { vacio.remove(); }
			ped.forEach(function (p, i) {
				ids[p.id] = 1;
				var el = $('[data-id="' + p.id + '"]', lista);
				if (el && el.dataset.firma === firma(p) && !el.classList.contains('is-perdido')) { return; }
				var tmp = document.createElement('div'); tmp.innerHTML = tarjeta(p); var nuevo = tmp.firstChild;
				if (el) { lista.replaceChild(nuevo, el); } else { var ref = $$('.pa-pedido', lista)[i]; lista.insertBefore(nuevo, ref || null); }
				engancharPedido(r, nuevo, p);
			});
			$$('.pa-pedido', lista).forEach(function (el) {
				if (ids[el.dataset.id] || el.classList.contains('is-perdido')) { return; }
				perder(el, el.classList.contains('is-enviado') ? 'Lo cogió otro chofer.' : 'Ya no está.');
			});
			if (!ped.length && !$$('.pa-pedido', lista).length && !$('[data-vacio]', lista)) {
				lista.innerHTML = '<div data-vacio><p class="p-aviso" style="margin-top:8px">' + ico('campana') + 'Sin pedidos ahora. Te avisamos con un sonido.</p>' + (H.cuenta && H.cuenta.pausa ? '<a class="p-aviso p-aviso--error" href="#ch-deuda" style="text-decoration:none;margin-top:8px">' + ico('alerta') + '<span>Por la deuda, solo ves viajes por Transfermóvil.</span></a>' : '') + '</div>';
			}
		}
	};
	function perder(el, txt) {
		el.classList.add('is-perdido');
		var c = $('.pa-contra', el); if (c) { c.outerHTML = '<p class="p-peq" style="margin:0">' + esc(txt) + '</p>'; }
		$$('.p-aviso', el).forEach(function (a) { if (a.textContent.indexOf('Enviaste') >= 0) { a.remove(); } });
		setTimeout(function () { el.remove(); }, 3000);
	}
	function engancharPedido(r, el, p) {
		$$('[data-pct]', el).forEach(function (b) {
			b.onclick = function () {
				desbloquear();
				var pct = +b.dataset.pct; el.classList.add('is-enviado');
				api('chofer/pedidos/' + p.id + '/ofertar', { pct: pct }).then(function (x) {
					if (!x.data.ok) {
						if (x.status === 0) { el.classList.remove('is-enviado'); U.tostada('Sin señal. Prueba otra vez.', 'alerta'); return; }
						perder(el, x.data.message || 'Ya no está.'); return;
					}
					if (x.data.elegido) { H.activo = x.data.activo; H.pedidos = []; S.pideSalida = x.data.activo.id; guardarH(); U.tostada('¡Aceptó ' + cup(x.data.activo.precio) + '! Ve a recoger.'); vibrar([120, 60, 120]); seguirGps(); mandarPos(); ir('ch-recoger'); return; }
					p.mia = { precio: x.data.oferta.precio, pct: x.data.oferta.pct, estado: 'activa', vence: x.data.oferta.vence };
					var tmp = document.createElement('div'); tmp.innerHTML = tarjeta(p); var nuevo = tmp.firstChild;
					el.parentNode.replaceChild(nuevo, el); engancharPedido(r, nuevo, p);
				});
			};
		});
	}

	// ---------- Reparto (D157): «Te toca», «¿Cuándo sales?», programados, «¿Sigues?», «Lo lleva…» ----------
	function R() { return (H && H.reparto) || null; }
	function turnoDe(h) { h = h || H; var t = h && h.reparto && h.reparto.turno && h.reparto.turno[0]; return t && U.seg(t.turno_hasta) > 0 ? t : null; }
	function nomNeg(v) { var rp = v && v.reparto; return (rp && rp.negocio && rp.negocio.nombre) || (v && v.cliente && v.cliente.nombre) || 'el negocio'; }
	function soltarId(id, b) {
		if (b) { b.disabled = true; }
		api('chofer/viajes/' + id + '/estado', { accion: 'suelto', motivo: 'Ya no puedo' }).then(function (x) {
			if (b) { b.disabled = false; }
			if (!x.data.ok) { U.tostada(x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.'), 'alerta'); return; }
			if (H.activo && H.activo.id === id) { H.activo = null; guardarH(); }
			U.tostada('Soltado. Lo coge otro.', 'info'); ir('ch-conectar'); cargar();
		});
	}
	function programadosHtml() {
		var p = (R() && R().programados) || []; if (!p.length) { return ''; }
		return '<div><p class="pa-etq">Programados</p>' + p.map(function (v) {
			var rp = v.reparto || {};
			return '<div class="p-fila"><span class="p-fila__ico">' + ico('reloj') + '</span><span class="p-fila__txt"><b>Sale ' + esc(rp.sale_hora) + ' · ' + esc(nomNeg(v)) + '</b><span>' + esc(corto(v.destino.texto)) + (rp.lleva ? ' · lo lleva ' + esc(nombreCorto(rp.lleva.nombre)) : '') + '</span></span>'
				+ '<button type="button" class="p-btn p-btn--suave p-btn--chico" data-nopuedo="' + v.id + '">Ya no puedo</button></div>';
		}).join('') + '</div>';
	}
	function engancharProg(r) { $$('[data-nopuedo]', r).forEach(function (b) { b.onclick = function () { soltarId(+b.dataset.nopuedo, b); }; }); }
	function hojaSalida(v) {
		var s = v && v.reparto && v.reparto.salida; if (!s) { return; }
		var horas = (s.horas || []).slice(0, 16);
		U.hojaSobre('<p class="p-h2" style="margin:0 0 4px">¿Cuándo sales?</p><p class="p-peq" style="margin:0 0 12px">Recoger de ' + esc(s.desde) + ' a ' + esc(s.hasta) + '</p>'
			+ (s.ahora ? '<button type="button" class="p-btn p-btn--marca p-btn--ancho" data-cuando="ahora">Ahora</button>' : '')
			+ (horas.length ? '<div class="pa-chips" style="margin-top:12px">' + horas.map(function (h) { return '<button type="button" class="p-chip" data-cuando="' + esc(h.hora) + '">' + esc(h.hora) + '</button>'; }).join('') + '</div>' : '')
			+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>', function (h) {
			$$('[data-cuando]', h).forEach(function (b) {
				b.onclick = function () {
					b.disabled = true;
					api('chofer/viajes/' + v.id + '/salida', { cuando: b.dataset.cuando }).then(function (x) {
						b.disabled = false;
						if (!x.data.ok) { avisoErr(h, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
						h.remove();
						var w = x.data.viaje, prog = w.reparto && w.reparto.programado;
						U.tostada(prog ? 'Sales a las ' + w.reparto.sale_hora + '. Te avisamos antes.' : '¡Dale! Ve a recoger.');
						H.activo = prog ? null : w; guardarH(); ir(pantallaDe()); mostrar(true); cargar();
					});
				};
			});
		});
	}
	function aceptarTurno(t, b) {
		desbloquear(); if (b) { b.disabled = true; }
		api('chofer/turnos/' + t.id + '/aceptar', {}).then(function (x) {
			if (b) { b.disabled = false; }
			if (!x.data.ok) { U.tostada(x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'Ya no está.'), 'alerta'); if (H.reparto) { H.reparto.turno = []; } ir(pantallaDe()); cargar(); return; }
			H.activo = x.data.viaje; if (H.reparto) { H.reparto.turno = []; } S.pideSalida = H.activo.id; guardarH(); vibrar([120, 60, 120]); seguirGps(); mandarPos();
			ir(pantallaDe());
		});
	}
	function hojaLleva(v) {
		var l = (v.reparto && v.reparto.confianza) || [];
		U.hojaSobre('<p class="p-h2" style="margin:0 0 12px">¿Quién lo lleva?</p>'
			+ '<button type="button" class="p-fila" data-lleva="0"><span class="p-fila__txt"><b>Lo llevo yo</b></span></button>'
			+ l.map(function (c) { return '<button type="button" class="p-fila" data-lleva="' + c.id + '"><span class="p-fila__txt"><b>' + esc(c.nombre) + '</b><span>Recoge y entrega. El dinero es tuyo.</span></span></button>'; }).join('')
			+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>', function (h) {
			$$('[data-lleva]', h).forEach(function (b) {
				b.onclick = function () {
					api('chofer/viajes/' + v.id + '/lleva', { confianza_id: +b.dataset.lleva }).then(function (x) {
						if (!x.data.ok) { avisoErr(h, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
						h.remove(); H.activo = x.data.viaje; guardarH(); mostrar(true);
						U.tostada(+b.dataset.lleva ? 'Lo ve en su enlace. Avísale tú.' : 'Lo llevas tú.');
					});
				};
			});
		});
	}
	/** Lo de Reparto en «Ir a recoger»: negocio, ¿Sigues?, ¿Cuándo sales?, Lo lleva… */
	function repRecoger(v) {
		var rp = v.reparto; if (!rp) { return ''; }
		var h = '';
		if (rp.sale_hora && !rp.sigue) {
			h += '<p class="p-aviso p-aviso--aviso" style="margin:10px 0 4px">' + ico('reloj') + '<span><b>¿Sigues? Sales a las ' + esc(rp.sale_hora) + '</b></span></p>'
				+ '<div class="pa-dos"><button type="button" class="p-btn p-btn--marca" id="sigo">Sigo</button><button type="button" class="p-btn p-btn--suave" id="nopuedo">Ya no puedo</button></div>';
		} else if (rp.sale_hora) {
			h += '<p class="p-peq" style="margin:8px 0 0">' + ico('reloj', 'p-ico--16') + ' Sales a las ' + esc(rp.sale_hora) + '</p>';
		}
		if (rp.salida) { h += '<button type="button" class="p-btn p-btn--suave p-btn--ancho" id="cuando" style="margin-top:10px">' + ico('reloj', 'p-ico--20') + '¿Cuándo sales?</button>'; }
		if (rp.lleva) { h += '<button type="button" class="p-btn p-btn--texto" id="lleva" style="align-self:flex-start">Lo lleva ' + esc(nombreCorto(rp.lleva.nombre)) + ' · cambiar</button>'; }
		else if (rp.confianza && rp.confianza.length) { h += '<button type="button" class="p-btn p-btn--texto" id="lleva" style="align-self:flex-start">Lo lleva…</button>'; }
		return h;
	}
	function engancharRep(r, v) {
		var b;
		if ((b = $('#sigo', r))) { b.onclick = function () { var x0 = this; x0.disabled = true; api('chofer/viajes/' + v.id + '/sigo', {}).then(function (x) { x0.disabled = false; if (!x.data.ok) { avisoErr(r, x.data.message || 'No se pudo.'); return; } H.activo = x.data.viaje; guardarH(); mostrar(true); U.tostada('¡Dale! Te esperan.'); }); }; }
		if ((b = $('#nopuedo', r))) { b.onclick = function () { soltarId(v.id, this); }; }
		if ((b = $('#cuando', r))) { b.onclick = function () { hojaSalida(v); }; }
		if ((b = $('#lleva', r))) { b.onclick = function () { hojaLleva(v); }; }
		if (S.pideSalida === v.id) { S.pideSalida = 0; if (v.reparto && v.reparto.salida) { hojaSalida(v); } }
	}
	SCR['ch-tetoca'] = {
		html: function () {
			var t = turnoDe(); if (!t) { return '<div class="pa-llena">' + cabChofer('Te toca') + '<div class="pa-cuerpo"><p class="p-aviso">' + ico('info') + 'Ya no está.</p></div></div>'; }
			var rp = t.reparto || {}, pu = rp.punto || {}, env = t.envio || {};
			return '<div class="pa-llena">' + cabChofer('Te toca') + '<div class="pa-cuerpo">'
				+ '<article class="pa-pedido" data-hasta="' + esc(t.turno_hasta) + '" data-total="' + ((R() && R().turno_seg) || 45) + '"><div class="arriba"><div><span class="p-precio" style="font-size:30px">' + cupG(t.oferta) + '</span><div class="p-peq">' + esc(nomNeg(t)) + (env.que ? ' · ' + esc(env.que) : '') + '</div></div><span class="p-sello">Encargo</span></div>'
				+ '<div class="od"><span class="pm-pin"></span><b>' + esc(pu.nombre || corto(t.origen.texto)) + '</b><small>A ' + metros(t.lejos_m) + ' de ti</small><span class="pm-pin pm-pin--destino"></span><b>' + esc(corto(t.destino.texto)) + '</b><small>Viaje de ' + U.km(t.km) + ' km</small></div>'
				+ (rp.ventana && rp.ventana.desde ? '<p class="p-aviso">' + ico('reloj') + '<span>Entregar de ' + esc(rp.ventana.desde) + (rp.ventana.hasta ? ' a ' + esc(rp.ventana.hasta) : '') + '</span></p>' : '')
				+ (rp.cobro && rp.cobro.total ? '<p class="p-aviso">' + ico('efectivo') + '<span>Cobra ' + cup(rp.cobro.total) + ' al entregar</span></p>' : '')
				+ (t.nota ? '<p class="p-aviso">' + ico('nota') + '«' + esc(t.nota) + '»</p>' : '')
				+ '<i class="p-oferta__tiempo" aria-hidden="true"></i></article></div>'
				+ '<div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="si">Aceptar</button><button type="button" class="p-btn p-btn--texto p-btn--ancho" id="no">Rechazar</button></div></div>';
		},
		init: function (r) {
			var t = turnoDe(); if (!t) { T(function () { ir(pantallaDe()); }, 1200); return; }
			$('#si', r).onclick = function () { aceptarTurno(t, this); };
			$('#no', r).onclick = function () { this.disabled = true; api('chofer/turnos/' + t.id + '/rechazar', {}).then(function () { if (H.reparto) { H.reparto.turno = []; } guardarH(); ir(pantallaDe()); cargar(); }); };
			I(function () {
				var el = $('.pa-pedido', r); if (!el) { return; }
				var s = U.seg(el.dataset.hasta), b = $('.p-oferta__tiempo', el);
				if (b) { b.style.transform = 'scaleX(' + Math.max(0, Math.min(1, s / (+el.dataset.total || 45))).toFixed(3) + ')'; }
				if (s <= 0) { if (H.reparto) { H.reparto.turno = []; } ir(pantallaDe()); cargar(); }
			}, 1000);
		}
	};

	// ---------- Ir a recoger / en viaje ----------
	var tramo = null;
	// La ruta (a la recogida o al destino) la manda el SERVIDOR por calles (`viene` / `linea`) y la rehace si te desvías.
	// Nunca se dibuja una recta: sin ruta, solo el punto.
	function firmaLinea(c) { return c ? c.length + ':' + c[0][0].toFixed(4) + ',' + c[0][1].toFixed(4) : ''; }
	function deEsteTramo(v) { return !!tramo && !!v && tramo.clave.indexOf(v.id + (v.estado === 'recogido' ? 'r' : 'a') + '|') === 0; }
	function prepararTramo(v) {
		var rec = v.estado === 'recogido', base = v.id + (rec ? 'r' : 'a') + '|';
		var srv = U.lineaLonLat(rec ? v.linea : v.viene);
		if (srv) {
			if (!tramo || tramo.clave !== base + firmaLinea(srv)) { tramo = { clave: base + firmaLinea(srv), c: srv, largo: largo(srv) }; }
			return Promise.resolve(tramo);
		}
		if (deEsteTramo(v)) { return Promise.resolve(tramo); }
		var b = rec ? U.P(v.destino) : U.P(v.origen), a = rec ? U.P(v.origen) : (S.pos ? [S.pos.lng, S.pos.lat] : null);
		if (!a) { return Promise.resolve(null); }
		return U.ruta(a, b, grupoDe(v.clase) !== 'taxi').then(function (c) {
			if (c && c.length > 1 && !deEsteTramo(v)) { tramo = { clave: base + firmaLinea(c), c: c, largo: largo(c) }; }
			return deEsteTramo(v) ? tramo : null;
		});
	}
	function escenaViaje() {
		var v = H.activo, rec = v.estado === 'recogido', fin = rec ? [v.destino.lng, v.destino.lat] : [v.origen.lng, v.origen.lat];
		var quien = v.envio ? (rec ? nombreCorto(v.envio.recibe) : 'Recogida') : (rec ? corto(v.destino.texto) : nombreCorto(v.cliente && v.cliente.nombre));
		var img = U.veh(imgDe(v.clase), 'arriba'), pins = [[rec ? 'destino' : 'origen', fin, esc(quien)]];
		if (!deEsteTramo(v)) { return { ver: S.pos ? [fin, [S.pos.lng, S.pos.lat]] : [fin], zoom: S.pos ? 0 : 16, pins: pins }; }
		return { ver: tramo.c, ruta: tramo.c, rutaQuieta: true, pins: pins, conducir: [['yo-ch', img, tramo.c, S.pos ? PMapa.avance(tramo.c, [S.pos.lng, S.pos.lat]) : 0]] };
	}
	function moverYo(r) {
		var v = H.activo; if (!v || !deEsteTramo(v) || !S.pos) { return; }
		var k = PMapa.avance(tramo.c, [S.pos.lng, S.pos.lat]); PMapa.colocar('yo-ch', k);
		var m = Math.max(0, Math.ceil(tramo.largo * (1 - k) / (VEL[grupoDe(v.clase)] || 250))), e = $('#eta-min', r);
		if (e) { e.textContent = m <= 0 ? 'ya casi' : m + ' min'; }
	}
	function navegar(v) { var p = v.estado === 'recogido' ? v.destino : v.origen; return 'geo:' + p.lat + ',' + p.lng + '?q=' + p.lat + ',' + p.lng; }
	function accion(r, a, extra, b) {
		var v = H.activo, cuerpo = { accion: a };
		Object.keys(extra || {}).forEach(function (k) { cuerpo[k] = extra[k]; });
		if (b) { b.disabled = true; }
		// 10-nov (auditoría): sin señal, Llegué/Recogí/Terminé se repiten solos (3 veces, cada 4 s); el servidor ignora el repetido.
		var intento = function (k) {
			return api('chofer/viajes/' + v.id + '/estado', cuerpo).then(function (x) {
				if (x.status !== 0 || k >= 3) { return x; }
				U.tostada('Sin señal. Lo repito…', 'alerta');
				return new Promise(function (ok) { setTimeout(ok, 4000); }).then(function () { return intento(k + 1); });
			});
		};
		return intento(1).then(function (x) {
			if (b) { b.disabled = false; }
			if (!x.data.ok) {
				avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.'));
				if (x.status === 404 || (x.status === 409 && /cancel/i.test(x.data.message || ''))) { H.activo = null; guardarH(); T(function () { ir('ch-conectar'); }, 1800); }
				return null;
			}
			if (x.data.suelto) { H.activo = null; guardarH(); U.tostada('Soltaste el viaje. Lo coge otro chofer.', 'info'); ir('ch-conectar'); return null; }
			var w = x.data.viaje;
			if (w.estado === 'terminado') { H.activo = null; S.cobrar = w; LS.set('ch.cobrar', JSON.stringify(w)); guardarH(); tramo = null; ir('ch-cobrar'); return w; }
			// 10-nov (Redmi): tras «Recogí» se repintaba la pantalla de la URL (#ch-recoger, con «Llegué») 2–3 s hasta la
			// siguiente consulta. Ahora va directo a la que toca (Llevando a…).
			H.activo = w; guardarH(); var d = pantallaDe(); if (actual !== d) { ir(d); } else { mostrar(true); } return w;
		});
	}
	function hojaSoltar(r) {
		var mot = ['Se me rompió', 'Está muy lejos', 'No encuentro al cliente', 'Otro motivo'];
		U.hojaSobre('<p class="p-h2" style="margin:0 0 4px">¿No puedes ir?</p><p class="p-peq" style="margin:0 0 12px">El pedido vuelve a buscar otro chofer.</p>'
			+ mot.map(function (m) { return '<button type="button" class="p-fila" data-mot="' + esc(m) + '"><span class="p-fila__txt"><b>' + esc(m) + '</b></span></button>'; }).join('')
			+ '<button type="button" class="p-btn p-btn--texto p-btn--ancho" data-cerrar>Sí puedo ir</button>', function (h) {
			$$('[data-mot]', h).forEach(function (b) { b.onclick = function () { h.remove(); accion(r, 'suelto', { motivo: b.dataset.mot }); }; });
		});
	}
	function esperaTxt(v) { // 5 min gratis desde «Llegué»; luego 50 CUP por minuto empezado (como el servidor)
		var s = Math.max(0, (U.ahora() - Date.parse(v.llego)) / 1000);
		if (s < 300) { var g = 300 - s; return 'Espera gratis ' + Math.floor(g / 60) + ':' + String(Math.floor(g % 60)).padStart(2, '0'); }
		return 'Espera +' + cup(Math.ceil((s - 300) / 60) * 50);
	}

	SCR['ch-recoger'] = {
		viaje: true,
		mapa: escenaViaje,
		html: function () {
			var v = H.activo, cli = v.cliente || { nombre: 'Cliente' }, n = esc(nombreCorto(cli.nombre)), llegue = v.estado === 'llego', env = v.envio;
			// v3: la etiqueta del pedido y, en la comida que adelanta, cuánto paga y la foto del recibo (sin foto no sale).
			var etq = v.etiqueta && v.etiqueta !== 'Pasaje' && v.etiqueta !== 'Paquete' ? '<p class="p-aviso' + (env && env.adelanto ? ' p-aviso--aviso' : '') + '" style="margin:10px 0 4px">' + ico(v.servicio === 'carga' ? 'caja' : 'efectivo') + '<span><b>' + esc(v.etiqueta) + '</b>' + (env && env.recoge ? ' · en ' + esc(env.recoge) : '') + (env && env.adelanto ? '. Te lo devuelven al entregar.' : '') + '</span></p>' : '';
			var adel = env && env.adelanto;
			var recibo = adel ? '<label class="p-btn p-btn--suave p-btn--ancho" style="margin-top:12px">' + ico('camara', 'p-ico--20') + '<span id="recibo-t">' + (env.recibo ? 'Recibo ✓ · repetir' : 'Foto del recibo (' + cup(env.adelanto) + ')') + '</span><input type="file" id="recibo" accept="image/*" capture="environment" hidden></label>' : '';
			var abajo = !llegue
				? filaPersona(cli.nombre, cli.nota, (env ? 'Manda: ' + esc(env.que) : 'Pasajero' + (v.pide ? ' · lo pidió ' + esc(v.pide) : '')) + (cli.viajes ? ' · ' + cli.viajes + ' viajes' : ''), cli.telefono) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="llegue" style="margin-top:14px">Llegué</button>'
				: (env ? filaPersona(cli.nombre, cli.nota, 'Manda: ' + esc(env.que), cli.telefono) + recibo + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="recogi" style="margin-top:14px"' + (adel && !env.recibo ? ' disabled' : '') + '>' + ico('check', 'p-ico--20') + ({ comida: 'Recogí la comida', carga: 'Cargado' }[v.servicio] || 'Recogí el paquete') + '</button>'
					: cajasCodigo('Pide el código a ' + n) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="recogi" disabled>' + ico('check', 'p-ico--20') + 'Recogí a ' + n + '</button>');
			return '<div class="pa-arriba"><span></span><a class="pa-pastilla" href="' + navegar(v) + '">' + ico('navegar', 'p-ico--16') + 'Navegar</a></div>'
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
				+ eta(llegue ? 'Ya llegaste' : 'Recoge a ' + n + ' · <span id="eta-min">…</span>', esc(corto(v.origen.texto)), v.total, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
				+ (llegue ? '<p class="p-peq" id="espera" style="margin:6px 0 0">' + esperaTxt(v) + '</p>' : '')
				+ etq
				+ (v.nota ? '<p class="p-aviso" style="margin:10px 0 4px">' + ico('nota') + '«' + esc(v.nota) + '»</p>' : '')
				+ repRecoger(v)
				+ abajo
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="soltar">' + (llegue ? 'Soltar viaje' : 'No puedo ir') + '</button></section>';
		},
		init: function (r) {
			var v = H.activo;
			var l = $('#llegue', r); if (l) { l.onclick = function () { accion(r, 'llegue', null, this).then(function (w) { if (w) { U.tostada('Le avisamos a ' + nombreCorto(w.cliente && w.cliente.nombre)); } }); }; }
			var rc = $('#recogi', r), fr = $('#recibo', r);
			engancharCajas(r, function (c) { if (rc) { rc.disabled = c.length !== 4; } });
			if (fr) { // v3: foto del recibo de la comida (mismo camino que la foto de la entrega, con tipo=recibo)
				fr.onchange = function () {
					var f = fr.files && fr.files[0]; if (!f) { return; }
					$('#recibo-t', r).textContent = 'Subiendo la foto…';
					var fd = new FormData(); fd.append('foto', f); fd.append('tipo', 'recibo');
					api('chofer/viajes/' + v.id + '/foto', fd).then(function (x) {
						if (!x.data.ok) { $('#recibo-t', r).textContent = 'Foto del recibo'; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No llegó la foto.')); return; }
						v.envio.recibo = true; guardarH(); $('#recibo-t', r).textContent = 'Recibo ✓ · repetir'; U.tostada('Foto del recibo guardada'); if (rc) { rc.disabled = false; }
					});
				};
			}
			if (rc) { rc.onclick = function () { var c = $('#cod', r); accion(r, 'recogi', c ? { codigo: c.value } : null, this); }; }
			$('#soltar', r).onclick = function () { hojaSoltar(r); };
			engancharRep(r, v);
			if (v.estado === 'llego') { I(function () { var e = $('#espera', r); if (e) { e.textContent = esperaTxt(v); } }, 1000); }
			prepararTramo(v).then(function () { if (actual === 'ch-recoger') { escenaActual(); moverYo(r); } });
		},
		alPos: function (r) { var antes = tramo && tramo.clave; prepararTramo(H.activo).then(function () { if (tramo && tramo.clave !== antes) { escenaActual(); } moverYo(r); }); }
	};

	SCR['ch-viaje'] = {
		viaje: true,
		mapa: escenaViaje,
		html: function () {
			var v = H.activo, cli = v.cliente || { nombre: 'Cliente' }, env = v.envio;
			if (env) {
				var rn = esc(nombreCorto(env.recibe) || 'quien recibe');
				return '<div class="pa-arriba"><span></span><a class="pa-pastilla" href="' + navegar(v) + '">' + ico('navegar', 'p-ico--16') + 'Navegar</a></div>'
					+ '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
					+ eta('Entregar a ' + rn + ' · ' + U.km(v.km) + ' km', esc(corto(v.destino.texto)), v.total, nombrePago(v.forma_pago))
					+ (v.etiqueta && v.etiqueta !== 'Paquete' ? '<p class="p-aviso" style="margin:10px 0 4px">' + ico(v.servicio === 'carga' ? 'caja' : 'efectivo') + '<span><b>' + esc(v.etiqueta) + '</b></span></p>' : '')
					+ (env.cobrar ? '<p class="p-aviso p-aviso--aviso" style="margin:10px 0 4px">' + ico('efectivo') + '<span>Cobra <b>' + miles(env.cobrar) + '</b>' + (v.servicio === 'comida' ? ' + envío' : '') + (v.reparto && v.reparto.cobro.devolver ? ' · devuelve ' + miles(v.reparto.cobro.devolver) + ' a ' + esc(nomNeg(v)) : '') + '</span></p>' : '')
					+ (v.reparto && v.reparto.lleva ? '<p class="p-peq" style="margin:6px 0 0">Lo lleva ' + esc(nombreCorto(v.reparto.lleva.nombre)) + '</p>' : '')
					+ filaPersona(env.recibe || 'Quien recibe', false, 'Recibe · ' + esc(env.que), env.telefono)
					+ '<label class="p-btn p-btn--suave p-btn--ancho" style="margin-top:12px">' + ico('camara', 'p-ico--20') + '<span id="foto-t">' + (env.foto ? 'Foto ✓ · repetir' : 'Foto de entrega') + '</span><input type="file" id="foto" accept="image/*" capture="environment" hidden></label>'
					+ cajasCodigo('Pide el código a ' + rn)
					+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
					+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="fin" disabled>' + ico('check', 'p-ico--20') + 'Entregado</button></section>';
			}
			return '<div class="pa-arriba"><span></span><a class="pa-pastilla" href="' + navegar(v) + '">' + ico('navegar', 'p-ico--16') + 'Navegar</a></div>'
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
				+ eta('Llevando a ' + esc(nombreCorto(cli.nombre)) + ' · ' + U.km(v.km) + ' km', esc(corto(v.destino.texto)), v.total, v.espera ? 'Incluye ' + cup(v.espera) + ' de espera' : nombrePago(v.forma_pago))
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="fin" style="margin-top:16px">Terminé el viaje</button></section>';
		},
		init: function (r) {
			var v = H.activo, fin = $('#fin', r), foto = $('#foto', r);
			var listo = function () { if (v.envio) { fin.disabled = !(v.envio.foto && $('#cod', r).value.length === 4); } };
			engancharCajas(r, listo);
			if (foto) {
				foto.onchange = function () {
					var f = foto.files && foto.files[0]; if (!f) { return; }
					$('#foto-t', r).textContent = 'Subiendo la foto…';
					var fd = new FormData(); fd.append('foto', f);
					api('chofer/viajes/' + v.id + '/foto', fd).then(function (x) {
						if (!x.data.ok) { $('#foto-t', r).textContent = 'Foto de entrega'; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No llegó la foto.')); return; }
						v.envio.foto = true; guardarH(); $('#foto-t', r).textContent = 'Foto ✓ · repetir'; U.tostada('Foto guardada'); listo();
					});
				};
			}
			fin.onclick = function () { var c = $('#cod', r); accion(r, 'termine', c ? { codigo: c.value } : null, this); };
			listo();
			prepararTramo(v).then(function () { if (actual === 'ch-viaje') { escenaActual(); moverYo(r); } });
		},
		alPos: function (r) { moverYo(r); }
	};

	// ---------- Cobrar ----------
	SCR['ch-cobrar'] = {
		html: function () {
			var v = S.cobrar, cli = v.cliente || { nombre: 'Cliente' }, n = esc(nombreCorto(cli.nombre));
			if (v.reparto) { // Reparto: tu envío, lo que te paga el negocio y lo que le devuelves
				var c = v.reparto.cobro || {}, ng = esc(nomNeg(v));
				return '<div class="pa-llena">' + cabChofer('Entregado') + '<div class="pa-cuerpo">' + monto('Tu envío', v.precio)
					+ (c.envio_cliente ? '<p class="p-aviso">' + ico('efectivo') + '<span>Cobraste ' + cup(c.envio_cliente) + ' del envío.</span></p>' : '')
					+ (c.envio_negocio ? '<p class="p-aviso">' + ico('efectivo') + '<span>' + ng + ' te paga ' + cup(c.envio_negocio) + '.</span></p>' : '')
					+ (c.devolver ? '<p class="p-aviso p-aviso--aviso">' + ico('alerta') + '<span>Devuelve ' + cup(c.devolver) + ' a ' + ng + '.</span></p>' : '')
					+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="cob">' + ico('check', 'p-ico--20') + 'Listo</button><p class="p-aviso p-aviso--error" id="err" hidden></p></div></div>';
			}
			return '<div class="pa-llena">' + cabChofer('Cobrar') + '<div class="pa-cuerpo">'
				+ monto('Cobra a ' + n + ' · ' + nombrePago(v.forma_pago).toLowerCase(), v.total)
				+ (v.espera ? '<p class="p-peq" style="text-align:center;margin:-8px 0 0">' + cup(v.precio) + ' + ' + cup(v.espera) + ' de espera</p>' : '')
				+ (v.forma_pago === 'transferencia' ? '<p class="p-peq" style="text-align:center;margin:-8px 0 0">Espera el SMS del banco.</p>' : '')
				+ (v.envio && v.envio.cobrar ? '<p class="p-aviso">' + ico('info') + '<span>Los ' + cup(v.envio.cobrar) + ' que cobraste al entregar son de ' + n + '.</span></p>' : '')
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="cob">' + ico('check', 'p-ico--20') + 'Cobrado</button>'
				+ (v.valorado ? '' : '<div><p class="pa-etq" style="text-align:center">¿Qué tal ' + n + '?</p>' + U.estrellas(0) + '</div>'
					+ '<div class="pa-chips" style="justify-content:center"><button type="button" class="p-chip" data-etq="puntual">Puntual</button><button type="button" class="p-chip" data-etq="amable">Amable</button><button type="button" class="p-chip" data-etq="problema">Problema</button></div>')
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '<button type="button" class="p-btn p-btn--texto" style="align-self:center" id="nopago">No me pagó</button></div></div>';
		},
		init: function (r) {
			var v = S.cobrar, est = 0; // 10-nov (auditoría): sin 5★ por defecto
			U.engancharEstrellas(r, function (n) { est = n; });
			$$('[data-etq]', r).forEach(function (c) { c.onclick = function () { c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') !== 'true'); }; });
			var terminar = function (txt, icono, a) { S.cobrar = null; LS.set('ch.cobrar', null); U.tostada(txt, icono); ir(a); cargar(); };
			var valorar = function () { if (v.valorado || !est) { return Promise.resolve(); } return api('chofer/viajes/' + v.id + '/valorar', { estrellas: est, etiquetas: $$('[data-etq][aria-pressed="true"]', r).map(function (x) { return x.dataset.etq; }) }); };
			$('#cob', r).onclick = function () {
				var b = this; b.disabled = true;
				api('chofer/viajes/' + v.id + '/estado', { accion: 'cobrado' }).then(function (x) {
					if (!x.data.ok) { b.disabled = false; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
					valorar().then(function () { terminar('Viaje terminado. +' + cup(v.total), 'check', 'ch-ganancias'); });
				});
			};
			if ($('#nopago', r)) $('#nopago', r).onclick = function () {
				var b = this; b.disabled = true;
				api('chofer/viajes/' + v.id + '/estado', { accion: 'nopago' }).then(function (x) {
					if (!x.data.ok) { b.disabled = false; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
					terminar('Avisamos a Pa\'llá. Te llamamos.', 'info', 'ch-conectar'); // 10-nov: «No me pagó» no valora
				});
			};
		}
	};

	// ---------- Ganancias ----------
	SCR['ch-ganancias'] = {
		html: function () { return '<div class="pa-llena">' + cabChofer('Ganancias', 'ch-conectar') + '<div class="pa-cuerpo" id="cuerpo"><span class="p-esq" style="height:48px;width:60%"></span><span class="p-esq" style="height:110px"></span></div></div>'; },
		init: function (r) {
			var pintar = function (s, verHoy) {
				var DIAS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'], DIA = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'], tope = Math.max.apply(null, s.dias.concat([1]));
				var barras = s.dias.map(function (v, i) { return '<div class="' + (i === s.hoy ? 'is-hoy' : '') + '"><i style="height:' + Math.max(4, v / tope * 100) + '%"></i><span>' + DIAS[i] + '</span></div>'; }).join('');
				var cu = H.cuenta || {};
				var fila = function (i, b, sub, pr, h) { return '<' + (h ? 'a href="' + h + '"' : 'div') + ' class="p-fila"><span class="p-fila__ico">' + ico(i) + '</span><span class="p-fila__txt"><b>' + b + '</b><span>' + sub + '</span></span><span class="p-fila__fin">' + pr + '</span></' + (h ? 'a' : 'div') + '>'; };
				var ICO = { tric: 'triciclo', taxi: 'coche', moto: 'moto', mens: 'paquete' };
				$('#cuerpo', r).innerHTML = '<div class="pa-seg"><button type="button" data-ver="hoy" aria-pressed="' + verHoy + '">Hoy</button><button type="button" data-ver="semana" aria-pressed="' + !verHoy + '">Semana</button></div>'
					+ (verHoy ? '<div><p class="p-mini">Hoy, ' + DIA[s.hoy] + '</p><span class="p-precio">' + cupG(s.dias[s.hoy]) + '</span><p class="p-peq" style="margin:4px 0 0">' + H.hoy.viajes + (H.hoy.viajes === 1 ? ' viaje' : ' viajes') + (H.ficha.nota != null ? ' · ★ ' + String(H.ficha.nota).replace('.', ',') : '') + '</p></div>'
						: '<div><p class="p-mini">Esta semana</p><span class="p-precio">' + cupG(s.total) + '</span><p class="p-peq" style="margin:4px 0 0">' + s.viajes + (s.viajes === 1 ? ' viaje' : ' viajes') + '</p></div>')
					+ '<div><div class="pa-total"><b>Esta semana</b><b class="p-num">' + cup(s.total) + '</b></div><div class="pa-barras">' + barras + '</div></div>'
					+ (cu.pct ? '<p class="p-aviso">' + ico('info') + 'Comisión ' + cu.pct + ' %.</p>' : '<p class="p-aviso p-aviso--bien">' + ico('check') + 'Comisión 0 % en el piloto.</p>')
					+ '<div><p class="pa-etq">Últimos viajes</p>'
					+ (s.ultimos.length ? s.ultimos.map(function (u) { return fila(ICO[grupoDe(u.clase)] || 'triciclo', esc(u.clase === 'mensajero' ? 'Envío a ' + corto(u.destino) : corto(u.destino)), U.hhmm(new Date(u.hora)) + (u.cliente ? ' · ' + esc(u.cliente) : '') + ' · ' + nombrePago(u.pago).toLowerCase(), cup(u.total)); }).join('') : '<p class="p-peq">Esta semana todavía no.</p>')
					+ fila('moneda', 'Lo que debo a Pa\'llá', cu.debe ? cup(cu.debe) : 'Nada', ico('sigue', 'p-ico--20'), '#ch-deuda')
					+ '</div>';
				$$('[data-ver]', r).forEach(function (b) { b.onclick = function () { pintar(s, b.dataset.ver === 'hoy'); }; });
			};
			api('chofer/semana').then(function (x) { if (x.data.ok && actual === 'ch-ganancias') { pintar(x.data, true); } else if (actual === 'ch-ganancias') { $('#cuerpo', r).innerHTML = '<p class="p-aviso">' + ico('sin-senal') + 'Sin señal. Prueba en un rato.</p>'; } });
		}
	};

	// ---------- Lo que debo ----------
	SCR['ch-deuda'] = {
		html: function () { return '<div class="pa-llena">' + cabChofer('Lo que debo', 'ch-ganancias') + '<div class="pa-cuerpo" id="cuerpo"><span class="p-esq" style="height:48px;width:60%"></span></div><div class="pa-pie"><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="tm" disabled>' + ico('transfer', 'p-ico--20') + 'Pagar por Transfermóvil</button></div></div>'; },
		init: function (r) {
			var pintar = function (cu) {
				H.cuenta = cu; guardarH();
				var EST = { pendiente: ' · por revisar', rechazado: ' · rechazado' };
				var lineas = (cu.lineas || []).slice(0, 8).map(function (l) { return '<div class="p-fila"><span class="p-fila__ico">' + ico(l.tipo === 'pago' ? 'transfer' : 'moneda') + '</span><span class="p-fila__txt"><b>' + (l.tipo === 'pago' ? 'Pagaste' : 'Comisión') + '</b><span>' + new Date(l.fecha).getDate() + '/' + (new Date(l.fecha).getMonth() + 1) + (l.ref ? ' · ' + esc(l.ref) : '') + (EST[l.estado] || '') + '</span></span><span class="p-fila__fin">' + cup(Math.abs(l.cup)) + '</span></div>'; }).join('');
				$('#cuerpo', r).innerHTML = '<div><p class="p-mini">Comisión ' + cu.pct + ' %' + (cu.pct ? '' : ' en el piloto') + '</p><span class="p-precio">' + cupG(cu.debe) + '</span>' + (cu.debe ? '' : '<p class="p-peq" style="margin:4px 0 0">No debes nada.</p>') + '</div>'
					+ (cu.tope ? '<div><div class="pa-medidor"><i style="width:' + Math.min(100, cu.debe / cu.tope * 100) + '%"></i></div><p class="p-peq" style="margin:8px 0 0">Tope ' + cup(cu.tope) + ': después se pausan solo los viajes en efectivo.</p></div>' : '')
					+ (cu.pausa ? '<p class="p-aviso p-aviso--error">' + ico('alerta') + 'Llegaste al tope: ahora solo te llegan viajes por Transfermóvil.</p>' : '')
					+ (lineas ? '<div><p class="pa-etq">Últimos movimientos</p>' + lineas + '</div>' : '');
				$('#tm', r).disabled = !cu.debe;
			};
			if (H.cuenta) { pintar(H.cuenta); }
			api('chofer/cuenta').then(function (x) { if (x.data.ok && actual === 'ch-deuda') { pintar(x.data.cuenta); } });
			$('#tm', r).onclick = function () {
				var cu = H.cuenta;
				U.hojaSobre('<p class="p-h2" style="margin:0 0 12px">Pagar ' + cup(cu.debe) + '</p><ol class="pa-pasos">'
					+ '<li><span class="n">1</span><div><b>Abre Transfermóvil</b><span>Pagos → Transferencia</span></div></li>'
					+ '<li><span class="n">2</span><div><b>Tarjeta de Pa\'llá</b><span class="p-num">' + esc(cu.cobro || 'Pídela por WhatsApp') + '</span></div></li>'
					+ '<li><span class="n">3</span><div><b>Nota: ' + esc(cu.nota) + '</b><span>Luego escribe aquí el número del SMS del banco</span></div></li></ol>'
					+ '<div class="pa-dos" style="margin:8px 0 12px"><label class="p-campo"><input id="pcup" inputmode="numeric" value="' + cu.debe + '" aria-label="Cuánto pagaste"><span class="p-cup">CUP</span></label><label class="p-campo"><input id="pref" maxlength="60" placeholder="N.º de la transferencia" aria-label="Número de la transferencia"></label></div>'
					+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
					+ '<button type="button" class="p-btn p-btn--tinta p-btn--ancho" id="pague">Ya pagué</button>', function (h) {
					$('#pague', h).onclick = function () {
						var b = this, c = +$('#pcup', h).value.replace(/\D+/g, ''), ref = $('#pref', h).value.trim();
						if (!c) { avisoErr(h, 'Escribe cuánto pagaste.'); return; }
						if (ref.length < 4) { avisoErr(h, 'Escribe el número de la transferencia (sale en el SMS del banco).'); return; }
						b.disabled = true;
						api('chofer/pago', { cup: c, ref: ref }).then(function (x) {
							b.disabled = false;
							if (!x.data.ok) { avisoErr(h, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
							h.remove(); pintar(x.data.cuenta); U.tostada('Pago apuntado. Lo revisamos.');
						});
					};
				});
			};
		}
	};

	// ---------- Perfil y vehículo ----------
	SCR['ch-perfil'] = {
		html: function () {
			var f = H.ficha, MES = ['ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.', 'jul.', 'ago.', 'sep.', 'oct.', 'nov.', 'dic.'], d = f.desde ? new Date(f.desde) : null;
			var ICO = { carne: 'carne', selfie: 'selfie', vehiculo: 'camara', licencia: 'llave', lot: 'nota' };
			var doc = function (p) {
				var sub = p.ok ? (p.id === 'lot' && p.vence ? 'Vale hasta el ' + p.vence.split('-').reverse().join('-') : 'Recibido') : (p.obligatorio ? 'Obligatorio' : 'Opcional');
				return '<div class="p-fila"><span class="p-fila__ico">' + ico(ICO[p.id] || 'nota') + '</span><span class="p-fila__txt"><b>' + esc(p.nombre) + '</b><span>' + sub + '</span></span><span class="p-sello">' + (p.ok ? ico('check') + 'Bien' : 'Falta') + '</span></div>';
			};
			return '<div class="pa-llena">' + cabChofer('Mi perfil', 'ch-conectar') + '<div class="pa-cuerpo" style="gap:4px">'
				+ '<div class="pa-yo">' + cara(48) + '<div><p class="p-h2" style="margin:0">' + esc(H.nombre) + '</p><p class="p-peq" style="margin:0">' + (f.nota != null ? '★ ' + String(f.nota).replace('.', ',') + ' · ' : '') + f.viajes + ' viajes' + (d ? ' · desde ' + MES[d.getMonth()] + ' ' + d.getFullYear() : '') + '</p></div></div>'
				+ '<div class="pa-resumen" style="margin:8px 0"><img src="' + U.veh(f.img) + '" alt="" width="88" height="50"><div><b>' + esc((CLASES[f.clase] && grupoDe(f.clase) === 'taxi' ? 'Taxi ' : grupoDe(f.clase) === 'tric' ? 'Triciclo ' : '') + f.tipo) + '</b><span>' + [f.color, f.plazas ? f.plazas + ' plazas' : '', f.chapa].filter(Boolean).map(esc).join(' · ') + '</span></div></div>'
				+ '<p class="pa-etq">Papeles</p>' + (f.papeles || []).map(doc).join('')
				+ '<a class="p-fila" href="#ch-deuda" style="border-top:1px solid var(--linea);margin-top:12px"><span class="p-fila__ico">' + ico('moneda') + '</span><span class="p-fila__txt"><b>Lo que debo a Pa\'llá</b></span>' + ico('sigue', 'p-ico--20') + '</a>'
				+ '<a class="p-fila" href="' + esc(C.alta) + '"><span class="p-fila__ico">' + ico('editar') + '</span><span class="p-fila__txt"><b>Cambiar mis papeles</b></span>' + ico('sigue', 'p-ico--20') + '</a>'
				+ confianzaHtml()
				+ (APP && APP.probarSonido ? '<button type="button" class="p-fila" id="probar" style="width:100%;border:0;background:none;font:inherit;text-align:left"><span class="p-fila__ico">' + ico('campana') + '</span><span class="p-fila__txt"><b>Probar el sonido</b><span>Suena como un pedido nuevo. Tócalo para callarlo.</span></span></button>' : '')
				+ '</div></div>';
		},
		init: function (r) { var b = $('#probar', r); if (b) { b.onclick = function () { try { APP.probarSonido(); } catch (e) { } }; } engancharConfianza(r); }
	};
	// Reparto: «Personas de confianza» (el mensajero la registra; Pa'llá la aprueba y le da su enlace).
	function confianzaHtml() {
		var l = R() && R().confianza; if (!l) { return ''; }
		var EST = { pendiente: 'Por aprobar', aprobada: 'Aprobada', rechazada: 'No aprobada' };
		return '<p class="pa-etq" style="margin-top:12px">Personas de confianza</p>'
			+ l.map(function (c) { return '<div class="p-fila"><span class="p-fila__ico">' + ico('selfie') + '</span><span class="p-fila__txt"><b>' + esc(c.nombre) + '</b><span>' + esc(EST[c.estado] || c.estado) + '</span></span><button type="button" class="p-btn p-btn--texto p-btn--chico" data-quitar="' + c.id + '">Quitar</button></div>'; }).join('')
			+ '<button type="button" class="p-btn p-btn--suave p-btn--ancho" id="conf-mas" style="margin-top:8px">Añadir persona</button>';
	}
	function engancharConfianza(r) {
		var guardar = function (cuerpo, h) {
			return api('chofer/confianza', cuerpo).then(function (x) {
				if (!x.data.ok) { avisoErr(h || r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
				if (h) { h.remove(); } if (H.reparto) { H.reparto.confianza = x.data.confianza; } guardarH(); mostrar(true);
			});
		};
		$$('[data-quitar]', r).forEach(function (b) { b.onclick = function () { b.disabled = true; guardar({ quitar: +b.dataset.quitar }); }; });
		var mas = $('#conf-mas', r); if (!mas) { return; }
		mas.onclick = function () {
			U.hojaSobre('<p class="p-h2" style="margin:0 0 4px">Persona de confianza</p><p class="p-peq" style="margin:0 0 12px">Lleva tus encargos. El dinero sigue siendo tuyo.</p>'
				+ '<label class="p-campo"><input id="cn" maxlength="80" placeholder="Nombre y apellido" aria-label="Nombre"></label>'
				+ '<div class="pa-dos" style="margin:8px 0"><label class="p-campo"><input id="ct" inputmode="tel" maxlength="12" placeholder="Móvil" aria-label="Móvil"></label><label class="p-campo"><input id="cc" inputmode="numeric" maxlength="4" placeholder="Carné: 4 últimas" aria-label="4 últimas cifras del carné"></label></div>'
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p><button type="button" class="p-btn p-btn--marca p-btn--ancho" id="cg">Mandar a aprobar</button>', function (h) {
				$('#cg', h).onclick = function () { guardar({ nombre: $('#cn', h).value.trim(), telefono: $('#ct', h).value, carne4: $('#cc', h).value }, h); };
			});
		};
	}

	// =====================================================================================
	// DATOS EN VIVO: chofer/hoy + tema ntfy callado
	// =====================================================================================
	var vivo = null, poll = 0, cargando = false;
	function pantallaDe(h) {
		h = h === undefined ? H : h;
		if (!h) { return 'ch-conectar'; }
		if (h.estado !== 'aprobado') { return 'ch-alta'; }
		if (S.cobrar) { return 'ch-cobrar'; }
		if (h.activo) { return h.activo.estado === 'recogido' ? 'ch-viaje' : 'ch-recoger'; }
		if (turnoDe(h)) { return 'ch-tetoca'; } // Reparto: le toca un encargo
		return 'ch-conectar';
	}
	function cargar() {
		if (cargando || !K) { return Promise.resolve(); }
		cargando = true;
		return api('chofer/hoy').then(function (x) {
			cargando = false;
			if (!x.data.ok) { return; }
			var antes = H, nuevos = 0;
			H = x.data; guardarH();
			if (H.tema && !vivo) {
				vivo = U.enVivo(H.tema, function (m) {
					if (m.t === 'perdio') { var el = $('.pa-pedido[data-id="' + m.id + '"]'); if (el && !el.classList.contains('is-perdido')) { perder(el, 'Lo cogió otro chofer.'); } }
					if (m.t === 'cancelado') { U.tostada('El cliente canceló.', 'info'); vibrar(300); }
					if (m.t === 'elegido') { vibrar([120, 60, 120]); }
					cargar();
				});
			}
			seguirGps();
			if (!!H.activo !== !!(antes && antes.activo)) { mandarPos(); } // al coger o soltar un viaje, la posición sale ya (y luego cada 10 s)
			if (H.estado !== 'aprobado') { if (actual !== 'ch-alta') { ir('ch-alta'); } return; }
			(H.pedidos || []).forEach(function (p) { if (!S.visto[p.id]) { S.visto[p.id] = 1; if (antes) { nuevos++; } } });
			var tn = turnoDe(); if (tn && !S.visto['t' + tn.id]) { S.visto['t' + tn.id] = 1; nuevos++; } // Reparto: «Te toca» suena
			if (nuevos && !H.activo) { sonar(); }
			if (H.activo && antes && !antes.activo) { U.tostada('¡Te eligieron! Ve a recoger.'); }
			if (!H.activo && antes && antes.activo && !S.cobrar) { U.tostada('El viaje se canceló.', 'info'); tramo = null; }
			// Se cambia de pantalla solo si cambió lo que toca (así puede mirar su perfil durante un viaje).
			var debe = pantallaDe(), tocaba = pantallaDe(antes), fijas = ['ch-recoger', 'ch-viaje', 'ch-cobrar', 'ch-alta'];
			if (actual !== debe && (debe !== tocaba || fijas.indexOf(actual) >= 0)) { ir(debe); return; }
			if (actual === debe && H.activo && antes && antes.activo && antes.activo.estado !== H.activo.estado) { mostrar(true); return; }
			if (SCR[actual] && SCR[actual].actualizar) { SCR[actual].actualizar(pant); }
		}, function () { cargando = false; });
	}
	function programar() {
		clearTimeout(poll);
		var ms = document.hidden ? 30000 : (H && H.activo ? 15000 : (H && H.ficha && H.ficha.disponible ? (vivo && vivo.vivo() ? 20000 : 8000) : 30000));
		poll = setTimeout(function () { cargar().then(programar, programar); }, ms);
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { cargar().then(programar); } else { programar(); } });

	// =====================================================================================
	// MOTOR
	// =====================================================================================
	var actual = null;
	function limpiar() {
		timers.forEach(function (t) { if (t && t.off) { t.off(); } else { clearTimeout(t); clearInterval(t); } });
		timers = [];
		$$('.pa-sobre').forEach(function (t) { t.remove(); });
	}
	function escenaActual() {
		var s = SCR[actual]; if (!s || !s.mapa) { return; }
		var hoja = $('.pa-hoja', pant);
		PMapa.escena(s.mapa(), hoja ? hoja.offsetHeight : 0);
	}
	function mostrar(mismo) {
		var id = decodeURIComponent(location.hash.slice(1)) || pantallaDe();
		if (!SCR[id]) { id = pantallaDe(); }
		if (!K && !SCR[id].libre) { id = 'ch-sin'; }
		if (K && !H && !SCR[id].libre) { // primera vez sin datos: espera a chofer/hoy
			pant.innerHTML = '<div class="pa-carga" role="img" aria-label="Pa\'llá"><b>Pa\'llá</b></div>'; marco.classList.add('sin-mapa'); actual = null; return;
		}
		if (H && H.estado !== 'aprobado' && !SCR[id].libre) { id = 'ch-alta'; }
		if (SCR[id].viaje && !(H && H.activo)) { id = pantallaDe(); }
		if (id === 'ch-cobrar' && !S.cobrar) { id = pantallaDe(); }
		limpiar();
		var s = SCR[id]; actual = id;
		pant.innerHTML = s.html();
		var cfg = s.mapa ? s.mapa() : null;
		marco.classList.toggle('sin-mapa', !cfg);
		if (cfg) { var hoja = $('.pa-hoja', pant); PMapa.escena(cfg, hoja ? hoja.offsetHeight : 0); }
		if (s.init) { s.init(pant); }
		document.title = (id === 'ch-conectar' ? '' : ((pant.querySelector('h1') || {}).textContent || '') + ' · ') + 'Pa\'llá · Chofer';
		if (!mismo) { pant.scrollTop = 0; }
	}

	// =====================================================================================
	// Reparto: LA PERSONA DE CONFIANZA (enlace #a=…). Solo sus viajes: Recogí, Entregado (código + foto) y Problema.
	// =====================================================================================
	function ayudaApp() {
		marco.classList.add('sin-mapa');
		var D = null, firmaA = '';
		var apiA = function (path, body) { return U.api(path, body, { 'X-Pv-Ayuda': AY }); };
		var caja = function (m) { pant.innerHTML = '<div class="pa-llena"><div class="pa-cuerpo" style="padding-top:96px;align-items:center;text-align:center"><h1 class="p-titulo">Pa\'llá</h1><p class="p-2">' + esc(m) + '</p></div></div>'; };
		var tarjetaA = function (v) {
			var ng = v.negocio || {}, e = v.envio || {}, rec = v.estado === 'recogido', rn = nombreCorto(e.recibe) || 'quien recibe';
			return '<article class="pa-pedido"><div class="arriba"><div><b class="p-h2">' + esc(rec ? 'Entregar a ' + rn : 'Recoger en ' + ((v.punto && v.punto.nombre) || ng.nombre)) + '</b><div class="p-peq">' + esc(ng.nombre) + ' · ' + esc(e.que) + (v.sale_hora ? ' · sale ' + esc(v.sale_hora) : '') + '</div></div></div>'
				+ '<div class="od"><span class="pm-pin"></span><b>' + esc(corto(v.origen.texto)) + '</b><small></small><span class="pm-pin pm-pin--destino"></span><b>' + esc(corto(v.destino.texto)) + '</b><small></small></div>'
				+ (rec ? filaPersona(e.recibe || 'Quien recibe', false, 'Recibe', e.telefono) : filaPersona(ng.nombre, false, 'Negocio', ng.telefono))
				+ (rec && e.cobrar ? '<p class="p-aviso p-aviso--aviso">' + ico('efectivo') + '<span>Cobra <b>' + miles(e.cobrar) + '</b></span></p>' : '')
				+ (rec ? '<label class="p-btn p-btn--suave p-btn--ancho" style="margin-top:12px">' + ico('camara', 'p-ico--20') + '<span id="ft">' + (e.foto ? 'Foto ✓ · repetir' : 'Foto de entrega') + '</span><input type="file" id="foto" accept="image/*" capture="environment" hidden></label>'
					+ cajasCodigo('Pide el código a ' + esc(rn)) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="fin" disabled>' + ico('check', 'p-ico--20') + 'Entregado</button>'
					: '<a class="p-btn p-btn--suave p-btn--ancho" style="margin-top:12px" href="geo:' + v.origen.lat + ',' + v.origen.lng + '?q=' + v.origen.lat + ',' + v.origen.lng + '">' + ico('navegar', 'p-ico--20') + 'Navegar</a>'
					+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="recogi" style="margin-top:8px">' + ico('check', 'p-ico--20') + 'Recogí</button>')
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p><button type="button" class="p-btn p-btn--texto" id="problema">Tengo un problema</button></article>';
		};
		var pintar = function () {
			var v = D.viajes[0], r = pant;
			pant.innerHTML = '<div class="pa-llena"><div class="pa-cab"><span style="width:12px"></span><h1>Hola, ' + esc(nombreCorto(D.nombre)) + '</h1><span style="width:12px"></span></div><div class="pa-cuerpo">'
				+ '<p class="p-peq" style="margin:0">Llevas encargos de ' + esc(D.titular) + '.</p>'
				+ (v ? tarjetaA(v) + (D.viajes.length > 1 ? '<p class="p-peq">Y ' + (D.viajes.length - 1) + ' más después.</p>' : '') : '<p class="p-aviso">' + ico('campana') + 'Nada por ahora. ' + esc(D.titular) + ' te avisa.</p>')
				+ '</div></div>';
			if (!v) { return; }
			var paso = function (a, extra, b) {
				var cuerpo = { accion: a }; Object.keys(extra || {}).forEach(function (k) { cuerpo[k] = extra[k]; });
				if (b) { b.disabled = true; }
				apiA('ayuda/viajes/' + v.id + '/estado', cuerpo).then(function (x) {
					if (b) { b.disabled = false; }
					if (!x.data.ok) { avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
					U.tostada(a === 'termine' ? '¡Entregado!' : (a === 'recogi' ? 'Recogido. Ve a entregar.' : 'Le avisamos a ' + D.titular + '.'), a === 'problema' ? 'info' : 'check');
					firmaA = ''; cargarA();
				});
			};
			var fin = $('#fin', r), ft = $('#foto', r), rc = $('#recogi', r);
			var listo = function () { if (fin) { fin.disabled = !(v.envio.foto && $('#cod', r).value.length === 4); } };
			engancharCajas(r, listo);
			if (rc) { rc.onclick = function () { paso('recogi', null, this); }; }
			if (fin) { fin.onclick = function () { paso('termine', { codigo: $('#cod', r).value }, this); }; }
			if (ft) {
				ft.onchange = function () {
					var f = ft.files && ft.files[0]; if (!f) { return; }
					$('#ft', r).textContent = 'Subiendo la foto…';
					var fd = new FormData(); fd.append('foto', f);
					apiA('ayuda/viajes/' + v.id + '/foto', fd).then(function (x) {
						if (!x.data.ok) { $('#ft', r).textContent = 'Foto de entrega'; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No llegó la foto.')); return; }
						v.envio.foto = true; $('#ft', r).textContent = 'Foto ✓ · repetir'; listo();
					});
				};
			}
			$('#problema', r).onclick = function () {
				var mot = ['No abren', 'No encuentro la casa', 'No contestan', 'Otro problema'];
				U.hojaSobre('<p class="p-h2" style="margin:0 0 12px">¿Qué pasa?</p>' + mot.map(function (m) { return '<button type="button" class="p-fila" data-mot="' + esc(m) + '"><span class="p-fila__txt"><b>' + esc(m) + '</b></span></button>'; }).join('')
					+ '<button type="button" class="p-btn p-btn--texto p-btn--ancho" data-cerrar>Nada, sigo</button>', function (h) {
					$$('[data-mot]', h).forEach(function (b) { b.onclick = function () { h.remove(); paso('problema', { motivo: b.dataset.mot }); }; });
				});
			};
		};
		var cargarA = function () {
			return apiA('ayuda/hoy').then(function (x) {
				if (x.status === 401 || x.status === 403) { lsSet('pv.a', null); caja(x.data.message || 'Tu enlace ya no vale.'); return; }
				if (!x.data.ok) { if (!D) { caja('Sin señal. Prueba en un rato.'); } return; }
				var f = JSON.stringify((x.data.viajes || []).map(function (v) { return [v.id, v.estado, v.envio && v.envio.foto]; }));
				if (D && f === firmaA) { return; }
				if (D && x.data.viajes.length > D.viajes.length) { vibrar([300, 120, 300]); }
				D = x.data; firmaA = f; pintar();
			});
		};
		caja('Cargando…');
		cargarA();
		setInterval(function () { if (!document.hidden) { cargarA(); } }, 20000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { cargarA(); } });
	}
	if (AY) { ayudaApp(); return; }

	PMapa.iniciar(mapaEl, TEMA);
	window.addEventListener('hashchange', function () { mostrar(); });
	U.sw();
	mostrar();
	if (K) { cargar().then(function () { if (!actual) { mostrar(); } else if (!location.hash) { ir(pantallaDe()); } programar(); }); }
})();
