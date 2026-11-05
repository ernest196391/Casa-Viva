/* Pa'llá transporte (5-nov-2026) · app del CHOFER en /palla/chofer/ (taxi, triciclo, moto y mensajero de envíos).
 * Pantallas del diseño v2 aprobado (prototipo/app.js, «ch-…») con los datos reales de la API casa-viva/v1/pv/chofer/…
 * Mismo enlace que Pa'llá: localStorage «palla.k» → cabecera X-Palla-Token (también vale #k=… en la URL).
 * En vivo: tema callado del chofer por ntfy (PU.enVivo(hoy.tema)) + pregunta a la web. Posición cada 30 s en línea y
 * cada 10 s con viaje. Suena y vibra con cada pedido nuevo. */
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
	function guardarH() { LS.set('ch.hoy', H ? JSON.stringify(H) : null); }

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
			var ops = (g !== 'mens' ? op('pasaje', 'Pasaje', esc(f.tipo) + (f.plazas ? ' · ' + f.plazas + ' plazas' : ''), f.pasaje) : '') + (g === 'tric' || g === 'moto' ? op('envios', 'Envíos', g === 'moto' || f.clase === 'tric_bici' ? 'En la mano o mochila' : 'Lo que quepa en el triciclo', f.envios) : '');
			return '<div class="pa-llena">' + cabChofer('Hola, ' + esc(nombreCorto(H.nombre))) + '<div class="pa-cuerpo">'
				+ '<div class="pa-total"><div><p class="p-mini">Hoy</p><span class="p-precio">' + cupG(H.hoy.cup) + '</span></div><a class="p-btn p-btn--suave p-btn--chico" href="#ch-ganancias">' + H.hoy.viajes + (H.hoy.viajes === 1 ? ' viaje' : ' viajes') + ico('sigue', 'p-ico--16') + '</a></div>'
				+ '<button type="button" class="pa-conectar ' + (on ? 'is-on' : '') + '" id="con" aria-pressed="' + on + '">' + ico('encender') + '<span>' + (on ? 'En línea' : 'Conectarme') + '</span></button>'
				+ (cu.pausa ? '<a class="p-aviso p-aviso--error" href="#ch-deuda" style="text-decoration:none">' + ico('alerta') + '<span>Debes ' + cup(cu.debe) + ': solo te llegan viajes por Transfermóvil.</span></a>' : '')
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
						guardarH(); seguirGps(); mostrar(true); cargar();
					});
				};
				if (on) { (S.pos ? Promise.resolve(S.pos) : gpsUna()).then(seguir); } else { seguir(null); }
			};
			$('#con', r).onclick = function () { desbloquear(); this.disabled = true; mandar(!H.ficha.disponible, null, this); };
			$$('[data-op]', r).forEach(function (b) {
				b.onclick = function () { var v = b.getAttribute('aria-checked') !== 'true', x = {}; x[b.dataset.op] = v ? 1 : 0; b.setAttribute('aria-checked', v); mandar(H.ficha.disponible, x); };
			});
		},
		actualizar: function (r) { var a = $('.pa-pie a', r), n = (H.pedidos || []).length; if (a) { a.textContent = n ? 'Ver pedidos cerca (' + n + ')' : 'Ver pedidos cerca'; } }
	};

	// ---------- Pedidos con oferta ----------
	function firma(p) { return p.id + '|' + p.oferta + '|' + (p.mia ? p.mia.estado + p.mia.precio : ''); }
	function tarjeta(p) {
		var cli = p.cliente || { nombre: 'Cliente' }, env = p.envio;
		var quien = esc(cli.nombre) + ' ' + (cli.nota != null ? '★ ' + String(cli.nota).replace('.', ',') : '· nuevo') + (env ? ' · ' + esc((TAM[env.tam] || '').toLowerCase()) + ' · ' + esc(env.que) : (cli.viajes ? ' · ' + cli.viajes + ' viajes' : '')) + (p.forma_pago === 'transferencia' ? ' · Transfermóvil' : '');
		var bots = (p.contra || []).map(function (c) { return '<button type="button" class="' + (c.pct ? '' : 'acepto') + '" data-pct="' + c.pct + '">' + (c.pct ? miles(c.precio) + '<small>+' + c.pct + ' %</small>' : 'Acepto<small>' + miles(c.precio) + '</small>') + '</button>'; }).join('');
		var mia = p.mia && p.mia.estado === 'activa';
		return '<article class="pa-pedido' + (mia ? ' is-enviado' : '') + '" data-id="' + p.id + '" data-firma="' + esc(firma(p)) + '" data-hasta="' + esc(mia ? p.mia.vence : p.hasta) + '" data-total="' + (mia ? 60 : 240) + '">'
			+ '<div class="arriba"><div><span class="p-precio" style="font-size:30px">' + cupG(p.oferta) + '</span><div class="p-peq">' + quien + '</div></div><span class="p-sello">' + (env ? 'Envío' : 'Pasaje') + '</span></div>'
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
					if (x.data.elegido) { H.activo = x.data.activo; H.pedidos = []; guardarH(); U.tostada('¡Aceptó ' + cup(x.data.activo.precio) + '! Ve a recoger.'); vibrar([120, 60, 120]); seguirGps(); mandarPos(); ir('ch-recoger'); return; }
					p.mia = { precio: x.data.oferta.precio, pct: x.data.oferta.pct, estado: 'activa', vence: x.data.oferta.vence };
					var tmp = document.createElement('div'); tmp.innerHTML = tarjeta(p); var nuevo = tmp.firstChild;
					el.parentNode.replaceChild(nuevo, el); engancharPedido(r, nuevo, p);
				});
			};
		});
	}

	// ---------- Ir a recoger / en viaje ----------
	var tramo = null;
	function prepararTramo(v) {
		var rec = v.estado === 'recogido', clave = v.id + (rec ? 'r' : 'a');
		if (tramo && tramo.clave === clave) { return Promise.resolve(tramo); }
		var b = rec ? U.P(v.destino) : U.P(v.origen), a = rec ? U.P(v.origen) : (S.pos ? [S.pos.lng, S.pos.lat] : null);
		if (!a) { return Promise.resolve(null); }
		var linea = rec ? U.lineaLonLat(v.linea) : null;
		return (linea ? Promise.resolve(linea) : U.ruta(a, b)).then(function (c) { c = c && c.length > 1 ? c : [a, b]; tramo = { clave: clave, c: c, largo: largo(c) }; return tramo; });
	}
	function escenaViaje() {
		var v = H.activo, rec = v.estado === 'recogido', fin = rec ? [v.destino.lng, v.destino.lat] : [v.origen.lng, v.origen.lat];
		var quien = v.envio ? (rec ? nombreCorto(v.envio.recibe) : 'Recogida') : (rec ? corto(v.destino.texto) : nombreCorto(v.cliente && v.cliente.nombre));
		var img = U.veh(imgDe(v.clase), 'arriba'), pins = [[rec ? 'destino' : 'origen', fin, esc(quien)]];
		if (!tramo || tramo.clave !== v.id + (rec ? 'r' : 'a')) { return { ver: S.pos ? [fin, [S.pos.lng, S.pos.lat]] : [fin], zoom: S.pos ? 0 : 16, pins: pins }; }
		return { ver: tramo.c, ruta: tramo.c, rutaQuieta: true, pins: pins, conducir: [['yo-ch', img, tramo.c, S.pos ? PMapa.avance(tramo.c, [S.pos.lng, S.pos.lat]) : 0]] };
	}
	function moverYo(r) {
		var v = H.activo; if (!v || !tramo || !S.pos) { return; }
		var k = PMapa.avance(tramo.c, [S.pos.lng, S.pos.lat]); PMapa.colocar('yo-ch', k);
		var m = Math.max(0, Math.ceil(tramo.largo * (1 - k) / (VEL[grupoDe(v.clase)] || 250))), e = $('#eta-min', r);
		if (e) { e.textContent = m <= 0 ? 'ya casi' : m + ' min'; }
	}
	function navegar(v) { var p = v.estado === 'recogido' ? v.destino : v.origen; return 'geo:' + p.lat + ',' + p.lng + '?q=' + p.lat + ',' + p.lng; }
	function accion(r, a, extra, b) {
		var v = H.activo, cuerpo = { accion: a };
		Object.keys(extra || {}).forEach(function (k) { cuerpo[k] = extra[k]; });
		if (b) { b.disabled = true; }
		return api('chofer/viajes/' + v.id + '/estado', cuerpo).then(function (x) {
			if (b) { b.disabled = false; }
			if (!x.data.ok) {
				avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.'));
				if (x.status === 404 || (x.status === 409 && /cancel/i.test(x.data.message || ''))) { H.activo = null; guardarH(); T(function () { ir('ch-conectar'); }, 1800); }
				return null;
			}
			if (x.data.suelto) { H.activo = null; guardarH(); U.tostada('Soltaste el viaje. Lo coge otro chofer.', 'info'); ir('ch-conectar'); return null; }
			var w = x.data.viaje;
			if (w.estado === 'terminado') { H.activo = null; S.cobrar = w; LS.set('ch.cobrar', JSON.stringify(w)); guardarH(); tramo = null; ir('ch-cobrar'); return w; }
			H.activo = w; guardarH(); mostrar(true); return w;
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
			var abajo = !llegue
				? filaPersona(cli.nombre, cli.nota, (env ? 'Manda: ' + esc(env.que) : 'Pasajero') + (cli.viajes ? ' · ' + cli.viajes + ' viajes' : ''), cli.telefono) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="llegue" style="margin-top:14px">Llegué</button>'
				: (env ? filaPersona(cli.nombre, cli.nota, 'Manda: ' + esc(env.que), cli.telefono) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="recogi" style="margin-top:14px">' + ico('check', 'p-ico--20') + 'Recogí el paquete</button>'
					: cajasCodigo('Pide el código a ' + n) + '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="recogi" disabled>' + ico('check', 'p-ico--20') + 'Recogí a ' + n + '</button>');
			return '<div class="pa-arriba"><span></span><a class="pa-pastilla" href="' + navegar(v) + '">' + ico('navegar', 'p-ico--16') + 'Navegar</a></div>'
				+ '<section class="pa-hoja"><div class="p-hoja__asa"></div>'
				+ eta(llegue ? 'Ya llegaste' : 'Recoge a ' + n + ' · <span id="eta-min">…</span>', esc(corto(v.origen.texto)), v.total, 'Fijo · ' + nombrePago(v.forma_pago).toLowerCase())
				+ (llegue ? '<p class="p-peq" id="espera" style="margin:6px 0 0">' + esperaTxt(v) + '</p>' : '')
				+ (v.nota ? '<p class="p-aviso" style="margin:10px 0 4px">' + ico('nota') + '«' + esc(v.nota) + '»</p>' : '')
				+ abajo
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '<button type="button" class="p-btn p-btn--texto pa-cancelar" id="soltar">No puedo ir</button></section>';
		},
		init: function (r) {
			var v = H.activo;
			var l = $('#llegue', r); if (l) { l.onclick = function () { accion(r, 'llegue', null, this).then(function (w) { if (w) { U.tostada('Le avisamos a ' + nombreCorto(w.cliente && w.cliente.nombre)); } }); }; }
			var rc = $('#recogi', r);
			engancharCajas(r, function (c) { if (rc) { rc.disabled = c.length !== 4; } });
			if (rc) { rc.onclick = function () { var c = $('#cod', r); accion(r, 'recogi', c ? { codigo: c.value } : null, this); }; }
			$('#soltar', r).onclick = function () { hojaSoltar(r); };
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
					+ (env.cobrar ? '<p class="p-aviso p-aviso--aviso" style="margin:10px 0 4px">' + ico('efectivo') + '<span>Cobra a ' + rn + ' <b>' + cup(env.cobrar) + '</b> al entregar.</span></p>' : '')
					+ filaPersona(env.recibe || 'Quien recibe', false, 'Recibe · ' + esc(env.que), env.telefono)
					+ '<label class="p-btn p-btn--suave p-btn--ancho" style="margin-top:12px">' + ico('camara', 'p-ico--20') + '<span id="foto-t">' + (env.foto ? 'Foto hecha · otra' : 'Hacer la foto de la entrega') + '</span><input type="file" id="foto" accept="image/*" capture="environment" hidden></label>'
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
						if (!x.data.ok) { $('#foto-t', r).textContent = 'Hacer la foto de la entrega'; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No llegó la foto.')); return; }
						v.envio.foto = true; guardarH(); $('#foto-t', r).textContent = 'Foto hecha · otra'; U.tostada('Foto guardada'); listo();
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
			return '<div class="pa-llena">' + cabChofer('Cobrar') + '<div class="pa-cuerpo">'
				+ monto('Cobra a ' + n + ' · ' + nombrePago(v.forma_pago).toLowerCase(), v.total)
				+ (v.espera ? '<p class="p-peq" style="text-align:center;margin:-8px 0 0">' + cup(v.precio) + ' + ' + cup(v.espera) + ' de espera</p>' : '')
				+ '<p class="p-peq" style="text-align:center;margin:-8px 0 0">Si paga por Transfermóvil, espera el SMS del banco.</p>'
				+ (v.envio && v.envio.cobrar ? '<p class="p-aviso">' + ico('info') + '<span>Los ' + cup(v.envio.cobrar) + ' que cobraste al entregar son de ' + n + '.</span></p>' : '')
				+ '<button type="button" class="p-btn p-btn--marca p-btn--ancho" id="cob">' + ico('check', 'p-ico--20') + 'Cobrado</button>'
				+ (v.valorado ? '' : '<div><p class="pa-etq" style="text-align:center">¿Qué tal ' + n + '?</p>' + U.estrellas(5) + '</div>'
					+ '<div class="pa-chips" style="justify-content:center"><button type="button" class="p-chip" data-etq="puntual" aria-pressed="true">Puntual</button><button type="button" class="p-chip" data-etq="amable">Amable</button><button type="button" class="p-chip" data-etq="problema">Problema</button></div>')
				+ '<p class="p-aviso p-aviso--error" id="err" hidden></p>'
				+ '<button type="button" class="p-btn p-btn--texto" style="align-self:center" id="nopago">No me pagó</button></div></div>';
		},
		init: function (r) {
			var v = S.cobrar, est = 5;
			U.engancharEstrellas(r, function (n) { est = n; });
			$$('[data-etq]', r).forEach(function (c) { c.onclick = function () { c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') !== 'true'); }; });
			var terminar = function (txt, icono, a) { S.cobrar = null; LS.set('ch.cobrar', null); U.tostada(txt, icono); ir(a); cargar(); };
			var valorar = function () { if (v.valorado) { return Promise.resolve(); } return api('chofer/viajes/' + v.id + '/valorar', { estrellas: est, etiquetas: $$('[data-etq][aria-pressed="true"]', r).map(function (x) { return x.dataset.etq; }) }); };
			$('#cob', r).onclick = function () {
				var b = this; b.disabled = true;
				api('chofer/viajes/' + v.id + '/estado', { accion: 'cobrado' }).then(function (x) {
					if (!x.data.ok) { b.disabled = false; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
					valorar().then(function () { terminar('Viaje terminado. +' + cup(v.total), 'check', 'ch-ganancias'); });
				});
			};
			$('#nopago', r).onclick = function () {
				var b = this; b.disabled = true;
				api('chofer/viajes/' + v.id + '/estado', { accion: 'nopago' }).then(function (x) {
					if (!x.data.ok) { b.disabled = false; avisoErr(r, x.status === 0 ? 'Sin señal. Prueba otra vez.' : (x.data.message || 'No se pudo.')); return; }
					valorar().then(function () { terminar('Avisamos a Pa\'llá. Te llamamos.', 'info', 'ch-conectar'); });
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
				+ '</div></div>';
		}
	};

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

	PMapa.iniciar(mapaEl, TEMA);
	window.addEventListener('hashchange', function () { mostrar(); });
	U.sw();
	mostrar();
	if (K) { cargar().then(function () { if (!actual) { mostrar(); } else if (!location.hash) { ir(pantallaDe()); } programar(); }); }
})();
