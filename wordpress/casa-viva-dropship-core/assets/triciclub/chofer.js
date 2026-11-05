/* Triciclub · la parte del chofer dentro de Pa'llá (/palla/triciclub). 3.14.122 (Ernesto 3-nov, «lado del chofer»):
 * rehecha como la app del cliente y como lo que se vio de La Nave Conductor (inicio en el mapa, saldo/hoy arriba, un
 * botón grande para conectarse, menú con perfil y precios). Lo que NO se pudo ver de La Nave (pedido entrante, viaje)
 * está diseñado aquí: pedido con cuenta atrás de 90 s y el mapa con la recogida, el destino y la ruta por calles.
 * Mismo enlace que Pa'llá (localStorage «palla.k» → X-Palla-Token). ES5, un solo oyente de clics. */
(function () {
	'use strict';
	var C = window.TRICICLUB || {};
	var app = document.getElementById('app');
	if (!app) { return; }
	var API = C.api || '/wp-json/casa-viva/v1/triciclub/';
	var HABANA = C.habana || [23.125, -82.38];
	var TIPOS = C.tipos || [];
	var MOTORES = [['pedal', 'Pedal'], ['electrico', 'Eléctrico'], ['gasolina', 'Gasolina']];
	// 3.14.130: «Qué haces» en tres botones; los paquetes van siempre (caben en cualquier triciclo).
	var USOS = [['pasaje', 'Pasaje', ['viaje', 'paquete']], ['carga', 'Carga', ['carga', 'paquete']], ['dos', 'Los dos', ['viaje', 'paquete', 'carga']]];
	// 3.14.132: con verificación (como QvaPay): carné + selfie sosteniendo el carné, con la cámara (capture), no de la galería.
	var FOTOS = C.verificar
		? [['carne', 'Tu carné', 'por delante, que se lea', 'environment'], ['selfie', 'Tu cara (selfie)', 'de frente, sin gafas ni gorra', 'user'], ['triciclo', 'Tu triciclo', 'de frente o de lado', 'environment'], ['licencia', 'Licencia', 'si la tienes', 'environment']]
		: [['perfil', 'Tu foto', 'tu cara, de frente', 'user'], ['triciclo', 'Tu triciclo', 'de frente o de lado', ''], ['licencia', 'Licencia', 'si la tienes', '']];
	// 5-nov (Pa'llá transporte): taxi y mensajero. El taxi pide además la LOT y la licencia es obligatoria.
	function grupoDe(tipo) { var t = TIPOS.filter(function (x) { return x.id === tipo; })[0]; return t ? (t.grupo || 'tric') : ''; }
	function fotosDe(tipo) {
		var g = grupoDe(tipo);
		return FOTOS.map(function (p) {
			if (p[0] === 'triciclo' && g && g !== 'tric') { return ['triciclo', g === 'taxi' ? 'Tu carro' : 'Tu moto o bici', 'de lado, entero', p[3]]; }
			if (p[0] === 'licencia' && g === 'taxi') { return ['licencia', 'Licencia', 'obligatoria en taxi', p[3]]; }
			return p;
		}).concat(g === 'taxi' ? [['lot', 'Tu LOT', 'obligatoria en taxi', 'environment']] : []);
	}
	var S = { token: '', hoy: null, blocked: null, toast: '', busy: false, hoja: '', form: null, sucio: false, offline: false, gps: null, terminado: null, subiendo: {} };
	var T = { poll: 0, gps: 0, toast: 0, tick: 0 };
	var mapa = null, mapaEl = null;

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function cup(n) { if (typeof n === 'number' && isNaN(n)) { return '—'; } return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
	function get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
	function toast(t) { S.toast = t; render(); clearTimeout(T.toast); T.toast = setTimeout(function () { S.toast = ''; render(); }, 3500); }
	function servTxt(s) { return { viaje: 'Viaje', paquete: 'Paquete', carga: 'Carga' }[s] || s; }
	function svg(p, size, color) { return '<svg width="' + (size || 22) + '" height="' + (size || 22) + '" viewBox="0 0 24 24" fill="none" stroke="' + (color || 'currentColor') + '" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>'; }
	var I = { atras: '<path d="M15 18l-6-6 6-6"/>', menu: '<path d="M4 7h16M4 12h16M4 17h16"/>', tel: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>', wa: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>', ir: '<path d="M3 11l18-8-8 18-2-8z"/>', poder: '<path d="M12 3v9M6.3 7.3a8 8 0 1 0 11.4 0"/>', gps: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>' };

	function api(path, body) {
		var h = { 'Accept': 'application/json', 'X-Palla-Token': S.token };
		if (body) { h['Content-Type'] = 'application/json'; }
		var ctrl = window.AbortController ? new AbortController() : null, corte = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 15000); // con mala red, nunca colgada
		return fetch(API + path, { method: body ? 'POST' : 'GET', headers: h, body: body ? JSON.stringify(body) : undefined, cache: 'no-store', credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
			.then(function (r) { clearTimeout(corte); return r; }, function (e) { clearTimeout(corte); throw e; })
			.then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { S.offline = false; return { status: r.status, data: d || {} }; }); })
			.catch(function () { S.offline = true; return { status: 0, data: { message: 'Sin conexión.' } }; });
	}
	function bloqueado(res) { if (res.status === 401 || res.status === 403) { S.blocked = res.data.message || 'No puedes entrar ahora.'; render(); return true; } return false; }

	// ------------------------------------------------------------ Precio (la misma cuenta que el servidor)
	function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isFinite(n) ? n : NaN; }
	// 3.14.123 (prueba del 4-nov): «abc» o vacío daban precios falsos en la vista previa y se guardaban sin avisar.
	function erroresPrecio(f) {
		var e = [];
		if (!(num(f.precio_km) >= 50 && num(f.precio_km) <= 3000)) { e.push('CUP por km: entre 50 y 3.000'); }
		if (!(num(f.km_tramo) >= 1 && num(f.km_tramo) <= 50)) { e.push('hasta el km: entre 1 y 50'); }
		if (!(num(f.precio_km_despues) >= 50 && num(f.precio_km_despues) <= 3000)) { e.push('luego, CUP/km: entre 50 y 3.000'); }
		return e;
	}
	function precio(perfil, km, extra) {
		var tramo = num(perfil.km_tramo) || 0, a = num(perfil.precio_km) || 0, b = num(perfil.precio_km_despues) || 0;
		if (erroresPrecio(perfil).length) { return NaN; }
		var base = km <= tramo ? km * a : tramo * a + (km - tramo) * b;
		base = Math.max(a, base);
		return Math.ceil(Math.round(base * (100 + (+extra || 0))) / 100 / 10) * 10;
	}
	function formDe(h) {
		var f = h.ficha || {}, p = h.perfil || {};
		return { nombre: f.propio && /^Chofer \d+$/.test(f.nombre || '') ? '' : (f.nombre || ''), tipo: f.tipo || '', motor: f.motor || '', carga_kg: f.carga_kg || '', numero: f.numero || '', descripcion: f.descripcion || '', plazas: f.plazas || '', servicios: f.servicios || ['viaje', 'paquete', 'carga'], lot_numero: f.lot_numero || '', lot_vence: f.lot_vence || '', chapa: f.chapa || '', color: f.color || '', licencia_cat: f.licencia_cat || '', transferencia: f.transferencia || '', precio_km: p.precio_km || 300, km_tramo: p.km_tramo || 5, precio_km_despues: p.precio_km_despues || 150, extras: p.extras || {} };
	}

	// ------------------------------------------------------------ Datos
	function load() {
		return api('chofer/hoy').then(function (res) {
			if (bloqueado(res)) { return; }
			if (res.data.ok) {
				var antes = S.hoy && S.hoy.pedidos ? S.hoy.pedidos.length : 0;
				avisarDesaparecidos(S.hoy, res.data);
				var era = S.hoy && S.hoy.ficha && S.hoy.ficha.estado, es = res.data.ficha && res.data.ficha.estado;
				if (era === 'verificando' && es && es !== 'verificando') { S.hoja = ''; S.sucio = false; toast(es === 'aprobado' ? '✅ ¡Aprobado! Toca «Conectarme» para recibir viajes.' : (es === 'revision' ? 'Una persona mirará tus datos. Te avisamos.' : 'Hay algo que corregir: míralo arriba.')); }
				S.hoy = res.data;
				// 5-nov: con Pa'llá transporte encendido, el chofer aprobado trabaja en la app nueva (/palla/chofer/).
				if (C.pvChofer && res.data.pv && es === 'aprobado' && !res.data.activo) { location.replace(C.pvChofer); return; }
				if (!S.form || !S.sucio) {
					S.form = formDe(res.data);
					var bo = borrador(), fe = res.data.ficha && res.data.ficha.estado;
					if (bo && fe !== 'aprobado' && fe !== 'verificando' && fe !== 'revision') { Object.keys(bo).forEach(function (k) { S.form[k] = bo[k]; }); S.sucio = true; }
				}
				if (res.data.pedidos.length > antes) { alarma(true); if (navigator.vibrate) { try { navigator.vibrate([300, 120, 300, 120, 300]); } catch (e) {} } }
				if (!res.data.pedidos.length && audio.timer) { alarma(false); }
				if (res.data.activo) { gpsViaje(); } else { gpsLibre(); }
				if (!S.appAvisado && res.data.ficha && (res.data.ficha.disponible || res.data.activo)) { S.appAvisado = true; appAvisos(); }
			}
			render(); programar();
		});
	}
	// 3.14.124 (Ernesto 4-nov «avisos al móvil»): con la pantalla abierta, alarma sonora al entrar un pedido (como el
	// «Activar sonido» de Pa'llá). El navegador solo deja sonar después de un toque: cualquier toque la habilita.
	var audio = { ctx: null, timer: 0 };
	function audioCtx() { try { if (!audio.ctx) { var A = window.AudioContext || window.webkitAudioContext; if (A) { audio.ctx = new A(); } } if (audio.ctx && audio.ctx.state === 'suspended') { audio.ctx.resume(); } } catch (e) {} return audio.ctx; }
	function pitido(f, t0, dur) { var c = audio.ctx, o = c.createOscillator(), g = c.createGain(); o.type = 'square'; o.frequency.value = f; g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur); o.connect(g); g.connect(c.destination); o.start(t0); o.stop(t0 + dur + 0.02); }
	function alarma(on) {
		clearInterval(audio.timer); audio.timer = 0;
		if (!on || !audioCtx() || audio.ctx.state !== 'running') { return; }
		var sonar = function () { var t = audio.ctx.currentTime; pitido(880, t, 0.18); pitido(1175, t + 0.22, 0.18); pitido(880, t + 0.44, 0.25); };
		sonar(); audio.timer = setInterval(sonar, 1600);
		setTimeout(function () { alarma(false); }, 30000); // como mucho 30 s
	}
	function sonidoListo() { return !!(audio.ctx && audio.ctx.state === 'running'); }
	document.addEventListener('pointerdown', function () { audioCtx(); if (audio.timer) { alarma(false); } }, true);

	// 3.14.123 (prueba del 4-nov): si el cliente cancela, el viaje o el pedido desaparecía sin decir nada. Ahora se avisa.
	function avisarDesaparecidos(viejo, nuevo) {
		if (!viejo) { return; }
		var ids = function (h) { var o = {}; (h.pedidos || []).forEach(function (p) { o[p.id] = p; }); if (h.activo) { o[h.activo.id] = h.activo; } return o; };
		var a = ids(viejo), b = ids(nuevo), yo = S.yoHice || {};
		Object.keys(a).forEach(function (id) {
			if (b[id] || yo[id]) { return; }
			var p = a[id], vencido = p.estado === 'esperando' && p.espera_hasta && new Date(p.espera_hasta).getTime() <= Date.now() + 2000;
			toast(vencido ? 'Se acabó el tiempo de ese pedido.' : (p.estado === 'esperando' ? 'El cliente canceló el pedido.' : 'El cliente canceló el viaje. No hace falta que vayas.'));
			if (navigator.vibrate) { try { navigator.vibrate([400, 150, 400]); } catch (e) {} }
		});
	}
	function programar() {
		clearTimeout(T.poll);
		var h = S.hoy || {}, f = h.ficha || {};
		var cada = h.activo || (h.pedidos && h.pedidos.length) ? 5000 : (f.disponible || f.estado === 'verificando' ? 8000 : 30000);
		T.poll = setTimeout(load, document.hidden ? Math.max(cada, 30000) : cada);
	}
	function guardar() {
		var e = erroresPrecio(S.form);
		if (e.length) { toast('Revisa: ' + e.join(' · ')); return; }
		S.busy = true; render();
		api('chofer/perfil', S.form).then(function (res) {
			S.busy = false;
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo guardar.'); return; }
			S.sucio = false; S.hoja = ''; toast('Guardado. Así te ven los clientes.'); load();
		});
	}

	// 3.14.130: alta de choferes. La foto se achica aquí (máx. 1280 px, JPEG): con datos móviles cuesta poco subirla.
	function achicar(file, lado) {
		return new Promise(function (ok) {
			if (!window.URL || !document.createElement('canvas').toBlob) { ok(file); return; }
			var img = new Image(), url = URL.createObjectURL(file);
			img.onload = function () {
				var k = Math.min(1, lado / Math.max(img.width, img.height)), c = document.createElement('canvas');
				c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
				c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
				c.toBlob(function (b) { ok(b || file); }, 'image/jpeg', 0.8);
			};
			img.onerror = function () { URL.revokeObjectURL(url); ok(file); };
			img.src = url;
		});
	}
	// 3.14.133 (Ernesto 4-nov: «tomé la foto y no cargó y no sé qué pasó»): la pantalla se repintaba cada 30 s con la cámara
	// abierta y el <input> de la foto se cambiaba por otro: la foto llegaba a uno que ya no estaba y se perdía sin aviso.
	// Ahora, como los bancos: cámara dentro de la página con guía (no se sale a otra app, que en Android puede recargar la
	// página), la foto se ve al momento con aviso si está oscura o movida, y se sube con barra de % y reintento sin repetirla.
	// Si el móvil no deja usar la cámara aquí, un <input> fijo fuera de la pantalla que se repinta (no se pierde).
	var FOTO = {}; // cual → { local: url de la vista previa, estado: subiendo|lista|error, pct, blob, aviso, msg }
	function datoFoto(cual) { return fotosDe(S.form && S.form.tipo).filter(function (p) { return p[0] === cual; })[0] || [cual, cual, '', '']; }
	function calidad(canvas) { // en el móvil, gratis: luz media y nitidez (varianza del laplaciano) en 160 px
		try {
			var w = 160, h = Math.max(1, Math.round(canvas.height * 160 / canvas.width)), c = document.createElement('canvas');
			c.width = w; c.height = h; var cx = c.getContext('2d'); cx.drawImage(canvas, 0, 0, w, h);
			var d = cx.getImageData(0, 0, w, h).data, g = new Float32Array(w * h), suma = 0, i, x, y;
			for (i = 0; i < w * h; i++) { g[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2]; suma += g[i]; }
			var lap = 0, n = 0;
			for (y = 1; y < h - 1; y++) { for (x = 1; x < w - 1; x++) { i = y * w + x; var v = 4 * g[i] - g[i - 1] - g[i + 1] - g[i - w] - g[i + w]; lap += v * v; n++; } }
			var luz = suma / (w * h), nitidez = n ? lap / n : 999;
			return luz < 55 ? 'Está oscura: busca más luz.' : (luz > 235 ? 'Tiene demasiada luz o reflejo.' : (nitidez < 30 ? 'Parece movida: sujeta el móvil quieto.' : ''));
		} catch (e) { return ''; }
	}
	function subir(cual, blob) {
		var f = FOTO[cual] = FOTO[cual] || {};
		f.blob = blob; f.estado = 'subiendo'; f.pct = 0; f.msg = '';
		pintarFoto(cual);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', API + 'chofer/foto');
		xhr.setRequestHeader('X-Palla-Token', S.token);
		xhr.timeout = 120000;
		xhr.upload.onprogress = function (e) { if (e.lengthComputable) { f.pct = Math.min(99, Math.round(100 * e.loaded / e.total)); pintarFoto(cual); } };
		xhr.onload = function () {
			var d = {}; try { d = JSON.parse(xhr.responseText); } catch (e) {}
			if (xhr.status === 200 && d.ok) { f.estado = 'lista'; f.blob = null; if (navigator.vibrate) { try { navigator.vibrate(40); } catch (e) {} } load(); }
			else { f.estado = 'error'; f.msg = d.message || 'No se subió.'; }
			pintarFoto(cual);
		};
		xhr.onerror = xhr.ontimeout = function () { f.estado = 'error'; f.msg = 'Sin conexión.'; pintarFoto(cual); };
		var fd = new FormData(); fd.append('cual', cual); fd.append('foto', blob, cual + '.jpg');
		xhr.send(fd);
	}
	function usarFoto(cual, canvas) { // canvas ya achicado → vista previa al momento + aviso de calidad + subir
		var f = FOTO[cual] = FOTO[cual] || {};
		f.aviso = calidad(canvas);
		canvas.toBlob(function (b) {
			if (!b) { toast('No se pudo leer la foto.'); return; }
			if (f.local) { URL.revokeObjectURL(f.local); }
			f.local = URL.createObjectURL(b);
			subir(cual, b);
		}, 'image/jpeg', 0.82);
	}
	function lienzo(fuente, ancho, alto, lado) {
		var k = Math.min(1, lado / Math.max(ancho, alto)), c = document.createElement('canvas');
		c.width = Math.round(ancho * k); c.height = Math.round(alto * k);
		c.getContext('2d').drawImage(fuente, 0, 0, c.width, c.height);
		return c;
	}
	// El <input> de reserva vive fuera de #app: los repintados no lo cambian y la foto siempre llega.
	var archivo = document.createElement('input');
	archivo.type = 'file'; archivo.accept = 'image/*'; archivo.hidden = true; document.body.appendChild(archivo);
	archivo.addEventListener('change', function () {
		var cual = archivo.getAttribute('data-cual'), file = archivo.files && archivo.files[0];
		archivo.value = '';
		if (!cual || !file) { return; }
		var img = new Image(), url = URL.createObjectURL(file);
		img.onload = function () { var c = lienzo(img, img.naturalWidth, img.naturalHeight, 1280); URL.revokeObjectURL(url); usarFoto(cual, c); };
		img.onerror = function () { URL.revokeObjectURL(url); subir(cual, file); };
		img.src = url;
	});
	function elegirArchivo(cual, camara) { // camara = abrir la cámara del móvil; si no, la galería (fotos que ya tiene)
		var p = datoFoto(cual);
		archivo.setAttribute('data-cual', cual);
		if (camara && p[3]) { archivo.setAttribute('capture', p[3]); } else { archivo.removeAttribute('capture'); }
		archivo.click();
	}
	// La cámara dentro de la página, con la guía de cada foto.
	var CAM = { el: null, stream: null, cual: '', facing: 'environment', foto: null };
	var GUIA = { carne: ['carne', 'Pon tu carné dentro del recuadro, por delante y que se lea.'], licencia: ['carne', 'Pon tu licencia dentro del recuadro.'], selfie: ['selfie', 'Tu cara dentro del óvalo, de frente y con buena luz.'], perfil: ['selfie', 'Tu cara dentro del óvalo.'], triciclo: ['ancho', 'Que se vea el triciclo entero, de frente o de lado.'] };
	function camaraUI() {
		if (CAM.el) { return CAM.el; }
		var el = document.createElement('div');
		el.className = 'tc-cam'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Cámara'); el.hidden = true;
		el.innerHTML = '<video class="tc-cam__video" playsinline muted autoplay></video><img class="tc-cam__foto" alt="" hidden><div class="tc-cam__guia"></div>'
			+ '<p class="tc-cam__txt" role="status"></p><div class="tc-cam__botones"></div>';
		el.addEventListener('click', function (e) {
			var b = e.target.closest('[data-cam]'); if (!b) { return; }
			var a = b.getAttribute('data-cam');
			if (a === 'cerrar') { cerrarCamara(); }
			else if (a === 'disparar') { disparar(); }
			else if (a === 'girar') { CAM.facing = CAM.facing === 'user' ? 'environment' : 'user'; encender(); }
			else if (a === 'repetir') { CAM.foto = null; pintarCamara(); }
			else if (a === 'usar') { var c = CAM.foto, cual = CAM.cual; cerrarCamara(); usarFoto(cual, c); }
			else if (a === 'galeria') { var q = CAM.cual; cerrarCamara(); elegirArchivo(q); }
		});
		document.body.appendChild(el);
		return (CAM.el = el);
	}
	function pintarCamara() {
		var el = CAM.el, g = GUIA[CAM.cual] || ['ancho', ''], foto = el.querySelector('.tc-cam__foto'), video = el.querySelector('.tc-cam__video');
		el.querySelector('.tc-cam__guia').className = 'tc-cam__guia tc-cam__guia--' + g[0] + (CAM.foto ? ' tc-cam__guia--fuera' : '');
		video.classList.toggle('tc-cam__video--espejo', CAM.facing === 'user');
		foto.hidden = !CAM.foto;
		var aviso = CAM.foto ? calidad(CAM.foto) : '';
		if (CAM.foto) { foto.src = CAM.foto.toDataURL('image/jpeg', 0.7); }
		el.querySelector('.tc-cam__txt').innerHTML = CAM.foto ? (aviso ? '⚠ ' + esc(aviso) + ' Mejor repítela.' : '✓ Se ve bien. ¿La usamos?') : esc(g[1]);
		el.querySelector('.tc-cam__botones').innerHTML = CAM.foto
			? '<button type="button" class="tc-cam__b" data-cam="repetir">Repetir</button><button type="button" class="tc-cam__b tc-cam__b--si" data-cam="usar">Usar foto</button>'
			: '<button type="button" class="tc-cam__b tc-cam__b--chico" data-cam="cerrar">Cerrar</button><button type="button" class="tc-cam__b tc-cam__b--chico" data-cam="galeria">Galería</button><button type="button" class="tc-cam__disparo" data-cam="disparar" aria-label="Sacar la foto"><span></span></button><button type="button" class="tc-cam__b tc-cam__b--chico" data-cam="girar" aria-label="Cambiar de cámara">↺</button>';
	}
	function encender() {
		if (CAM.stream) { CAM.stream.getTracks().forEach(function (t) { t.stop(); }); CAM.stream = null; }
		var el = camaraUI(), video = el.querySelector('.tc-cam__video');
		el.querySelector('.tc-cam__txt').textContent = 'Abriendo la cámara…';
		navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: CAM.facing, width: { ideal: 1920 }, height: { ideal: 1440 } } }).then(function (st) {
			if (el.hidden) { st.getTracks().forEach(function (t) { t.stop(); }); return; }
			CAM.stream = st; video.srcObject = st; var pl = video.play(); if (pl && pl.catch) { pl.catch(function () {}); }
			pintarCamara();
		}, function () { // sin permiso o sin cámara: la de siempre (o la galería)
			var q = CAM.cual; cerrarCamara(); toast('La cámara no se pudo abrir aquí: se abre la del móvil.'); elegirArchivo(q, true);
		});
	}
	function abrirCamara(cual) {
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { elegirArchivo(cual, true); return; }
		var el = camaraUI(), p = datoFoto(cual);
		CAM.cual = cual; CAM.foto = null; CAM.facing = p[3] === 'user' ? 'user' : 'environment';
		el.hidden = false; document.body.classList.add('tc-cam-abierta');
		pintarCamara(); encender();
	}
	function disparar() {
		var v = CAM.el.querySelector('.tc-cam__video');
		if (!v.videoWidth) { return; }
		CAM.foto = lienzo(v, v.videoWidth, v.videoHeight, 1280);
		if (navigator.vibrate) { try { navigator.vibrate(30); } catch (e) {} }
		pintarCamara();
	}
	function cerrarCamara() {
		if (CAM.stream) { CAM.stream.getTracks().forEach(function (t) { t.stop(); }); CAM.stream = null; }
		if (CAM.el) { CAM.el.hidden = true; CAM.el.querySelector('.tc-cam__video').srcObject = null; }
		document.body.classList.remove('tc-cam-abierta');
	}
	function htmlFoto(p, url) { // el recuadro de una foto: vista previa, barra de subida, lista o reintentar
		var f = FOTO[p[0]] || {}, img = f.local || url, est = f.estado || (url ? 'lista' : '');
		var pie = est === 'subiendo' ? 'Subiendo ' + (f.pct || 0) + ' %' : (est === 'error' ? '✖ ' + (f.msg || 'No se subió') + ' Toca para reintentar' : (est === 'lista' ? (f.aviso ? '⚠ ' + f.aviso : '✓ Lista · toca para cambiarla') : p[2]));
		return '<button type="button" class="tc-foto tc-foto--' + (est || 'vacia') + '" data-act="foto" data-id="' + p[0] + '">'
			+ '<span class="tc-foto__img">' + (img ? '<img src="' + esc(img) + '" alt="">' : '<span class="tc-foto__mas" aria-hidden="true">📷</span>')
			+ (est === 'subiendo' ? '<span class="tc-foto__barra"><span style="width:' + (f.pct || 0) + '%"></span></span>' : '')
			+ (est === 'lista' && !f.aviso ? '<span class="tc-foto__ok" aria-hidden="true">✓</span>' : '') + '</span>'
			+ '<b>' + esc(p[1]) + '</b><small role="status">' + esc(pie) + '</small></button>';
	}
	function pintarFoto(cual) { // solo ese recuadro, sin repintar toda la pantalla (no se pierde lo que escribes)
		var b = app.querySelector('[data-act="foto"][data-id="' + cual + '"]'), p = datoFoto(cual);
		if (!b) { return; }
		var fi = (S.hoy && S.hoy.ficha) || {}, t = document.createElement('div');
		t.innerHTML = htmlFoto(p, (fi.fotos || {})[cual]);
		b.parentNode.replaceChild(t.firstChild, b);
	}
	// Lo que escribe el chofer queda en el móvil hasta enviarlo (si Android recarga la página, no se pierde).
	function guardarBorrador() { if (S.form && S.sucio) { set('tc.borrador', JSON.stringify(S.form)); } }
	function borrador() { try { return JSON.parse(get('tc.borrador') || 'null'); } catch (e) { return null; } }
	function enviarAlta() {
		var subiendo = Object.keys(FOTO).filter(function (k) { return FOTO[k].estado === 'subiendo'; });
		if (subiendo.length) { toast('Espera a que terminen de subir las fotos.'); return; }
		var e = erroresPrecio(S.form);
		if (e.length) { toast('Revisa: ' + e.join(' · ')); return; }
		S.busy = true; render();
		api('chofer/perfil', S.form).then(function (res) {
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { S.busy = false; toast(res.data.message || 'No se pudo guardar.'); return; }
			S.sucio = false;
			api('chofer/alta', {}).then(function (r) {
				S.busy = false;
				if (bloqueado(r)) { return; }
				if (!r.data.ok) { toast(r.data.message || 'No se pudo.'); load(); return; }
				set('tc.borrador', '');
				if (r.data.estado === 'verificando') { toast('Enviado. Comprobamos tus datos en 1 o 2 minutos.'); load(); return; }
				S.hoja = ''; toast('¡Listo! Ya eres chofer de Triciclub. Toca «Conectarme» para recibir viajes.'); load();
			});
		});
	}
	function usoDe(servicios) {
		var v = servicios.indexOf('viaje') !== -1, c = servicios.indexOf('carga') !== -1;
		return v && c ? 'dos' : (c ? 'carga' : (v ? 'pasaje' : ''));
	}
	function iconoTipo(t, size, color) { return '<svg width="' + size + '" height="' + Math.round(size * 0.75) + '" viewBox="0 0 32 24" fill="none" stroke="' + color + '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + t.icono + '</svg>'; }

	// ------------------------------------------------------------ GPS
	function vigilarGps() {
		if (!navigator.geolocation) { return; }
		navigator.geolocation.watchPosition(function (p) { S.gps = [p.coords.latitude, p.coords.longitude]; S.gpsPrec = p.coords.accuracy; pintarMapa(); }, function () {}, { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 });
	}
	function posicion() {
		if (S.gps) { return Promise.resolve({ latitude: S.gps[0], longitude: S.gps[1], accuracy: S.gpsPrec || 0 }); }
		return new Promise(function (ok) {
			if (!navigator.geolocation) { ok(null); return; }
			navigator.geolocation.getCurrentPosition(function (p) { ok(p.coords); }, function () { ok(null); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
		});
	}
	function conectar(on) {
		S.busy = true; render();
		(on ? posicion() : Promise.resolve(null)).then(function (c) {
			if (on && !c) { S.busy = false; toast('Activa la ubicación del teléfono para salir en el mapa de los clientes.'); return; }
			api('chofer/disponible', { on: on, lat: c && c.latitude, lng: c && c.longitude }).then(function (res) {
				S.busy = false;
				if (bloqueado(res)) { return; }
				if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); return; }
				if (S.hoy && S.hoy.ficha) { S.hoy.ficha.disponible = on; }
				if (on) { appAvisos(); }
				toast(on ? 'Conectado: ya sales a los clientes cerca.' : 'Desconectado.');
				gpsLibre(); render(); programar();
			});
		});
	}
	// 3.14.124: dentro de la app de Pa'llá para Android, su servicio de avisos (ntfy, sin Google) solo corre si la web le
	// dice «disponible». Al conectarse a Triciclub se lo decimos, para que el pedido le llegue con la pantalla apagada.
	// Al desconectarse NO se apaga: podría seguir disponible para las carreras de Pa'llá.
	function appAvisos() { try { if (window.PallaApp && window.PallaApp.setAvailable) { window.PallaApp.setAvailable(true, 0); } } catch (e) {} }
	function gpsLibre() { // conectado y sin viaje: su posición cada minuto
		clearInterval(T.gps); T.gpsModo = 'libre';
		var f = S.hoy && S.hoy.ficha;
		if (!f || !f.disponible) { return; }
		T.gps = setInterval(function () { posicion().then(function (c) { if (c) { api('chofer/disponible', { on: true, lat: c.latitude, lng: c.longitude }); } }); }, 60000);
	}
	function gpsViaje() { // con viaje: cada 15 s, para el mapa del cliente
		if (T.gpsModo === 'viaje') { return; }
		clearInterval(T.gps); T.gpsModo = 'viaje';
		var mandar = function () {
			var v = S.hoy && S.hoy.activo;
			if (!v) { T.gpsModo = ''; gpsLibre(); return; }
			posicion().then(function (c) { if (c) { api('chofer/viajes/' + v.id + '/ubicacion', { lat: c.latitude, lng: c.longitude, precision: Math.round(c.accuracy || 0) }); } });
		};
		mandar(); T.gps = setInterval(mandar, 15000);
	}

	// ------------------------------------------------------------ Botones del viaje
	function responder(id, acepto) {
		S.yoHice = S.yoHice || {}; if (!acepto) { S.yoHice[id] = 1; }
		S.busy = true; render();
		api('chofer/viajes/' + id + '/responder', { acepto: acepto }).then(function (res) { S.busy = false; if (bloqueado(res)) { return; } if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); } load(); });
	}
	function paso(id, accion, extra) {
		var body = { accion: accion }; Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
		if (accion === 'termine' || accion === 'suelto') { S.yoHice = S.yoHice || {}; S.yoHice[id] = 1; }
		S.busy = true; render();
		api('chofer/viajes/' + id + '/estado', body).then(function (res) {
			S.busy = false;
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); render(); return; }
			if (accion === 'termine') { S.terminado = res.data.viaje; }
			if (accion === 'cobrado') { S.terminado = res.data.viaje; }
			load();
		});
	}

	// ------------------------------------------------------------ Mapa
	function conMapa(fn) {
		if (mapa) { fn(mapa); return; }
		if (!window.TcMapa || !mapaEl) { return; }
		window.TcMapa.crear(mapaEl, S.gps || HABANA).then(function (m) { if (m) { mapa = m; fn(m); } }).catch(function () { mapaEl.innerHTML = '<div class="tc-sinmapa">Este teléfono no puede enseñar el mapa.</div>'; });
	}
	function abajo() { var s = app.querySelector('.tc-hoja'); return s ? s.offsetHeight : 0; }
	function pintarMapa() {
		conMapa(function (m) {
			var M = window.TcMapa, h = S.hoy || {}, v = h.activo || (h.pedidos && h.pedidos[0]) || null;
			M.chofer(m, S.gps); M.yo(m, null);
			if (v) {
				var o = [v.origen.lat, v.origen.lng], d = [v.destino.lat, v.destino.lng];
				M.pin(m, 'origen', v.estado === 'recogido' ? null : o); M.pin(m, 'destino', d);
				var k = v.id + v.estado + (v.linea ? '+l' : '');
				if (m._tcVista !== k) { m._tcVista = k; M.ruta(m, o, d, v.linea); M.ver(m, [S.gps, v.estado === 'recogido' ? null : o, d], abajo()); }
				return;
			}
			M.pin(m, 'origen', null); M.pin(m, 'destino', null);
			if (m._tcVista !== 'libre') { m._tcVista = 'libre'; M.ruta(m, null, null); if (S.gps) { M.ir(m, S.gps, 15, abajo()); } }
		});
	}

	// ------------------------------------------------------------ Pantallas
	function cuenta(hasta) { return Math.max(0, Math.round((new Date(hasta).getTime() - Date.now()) / 1000)); }
	function navUrl(lat, lng) { return 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng + '&travelmode=driving'; }

	function arriba() {
		var h = S.hoy || {}, d = h.hoy || { viajes: 0, cup: 0 };
		var propio = h.ficha && h.ficha.propio;
		return '<div class="tc-arriba">' + (propio ? '<span class="tc-redondo" style="visibility:hidden"></span>' : '<a class="tc-redondo" href="' + esc(C.palla || '/palla/') + '" aria-label="Volver a Pa\'llá">' + svg(I.atras) + '</a>')
			+ '<span class="tc-saldo"><b>' + esc(cup(d.cup)) + '</b><small>hoy · ' + d.viajes + (d.viajes === 1 ? ' viaje' : ' viajes') + '</small></span>'
			+ '<button type="button" class="tc-redondo" data-act="menu" aria-label="Mi triciclo y mis precios">' + svg(I.menu) + '</button></div>'
			+ (S.offline ? '<div class="tc-sinred" role="status">Sin conexión. Toca otra vez cuando vuelva la señal.</div>' : '');
	}

	function hojaLibre(f) {
		var h = S.hoy, fr = h.franja || {}, ex = (h.perfil && h.perfil.extras && h.perfil.extras[fr.id]) || 0;
		if (!f.disponible) {
			return '<section class="tc-hoja tc-hoja--corta tc-chofer-hoja"><div class="tc-asa"></div>'
				+ '<button type="button" class="tc-conectar" data-act="conectar"' + (S.busy ? ' disabled' : '') + '>' + svg(I.poder, 34, '#fff') + '<b>Conectarme</b></button>'
				+ '<p class="tc-muted tc-centro">Estás <b>desconectado</b>: los clientes no te ven.</p>'
				+ '<p class="tc-small tc-centro">Ahora: ' + esc(fr.nombre || '') + (ex ? ' (+' + ex + ' %)' : '') + ' · 5 km = ' + esc(cup(precio(h.perfil, 5, ex))) + ' · <button type="button" class="tc-btn--link tc-enlace" data-act="menu">Mis precios</button></p></section>';
		}
		return '<section class="tc-hoja tc-hoja--corta tc-chofer-hoja"><div class="tc-asa"></div>'
			+ '<p class="tc-enlinea"><span class="tc-punto-vivo" aria-hidden="true"></span><b>En línea</b> · esperando viajes</p>'
			+ (sonidoListo() ? '<p class="tc-small tc-centro">🔊 Sonará una alarma cuando te pidan un viaje. Con la pantalla apagada te llega el aviso de la app de Pa\'llá.</p>'
				: '<button type="button" class="tc-btn tc-btn--sec tc-btn--small" data-act="sonido">🔊 Activar el sonido de los pedidos</button>')
			+ '<p class="tc-small tc-centro">Ahora: ' + esc(fr.nombre || '') + (ex ? ' (+' + ex + ' %)' : '') + ' · 5 km = ' + esc(cup(precio(h.perfil, 5, ex))) + '</p>'
			+ '<button type="button" class="tc-btn tc-btn--sec" data-act="desconectar"' + (S.busy ? ' disabled' : '') + '>Desconectarme</button></section>';
	}

	function hojaPedido(p) {
		var s = cuenta(p.espera_hasta);
		return '<section class="tc-hoja tc-hoja--corta tc-chofer-hoja" aria-label="Pedido nuevo"><div class="tc-asa"></div>'
			+ '<div class="tc-row"><span class="tc-strong">🛺 Te piden un ' + esc(servTxt(p.servicio).toLowerCase()) + '</span><span class="tc-cuenta-aro" style="--p:' + Math.round(100 * s / 90) + '"><b class="tc-cuenta">' + s + '</b></span></div>'
			+ '<p class="tc-trayecto"><span class="tc-ruta__punto"></span><b>' + esc(String(p.origen.texto).split(',')[0]) + '</b><span aria-hidden="true">→</span><span class="tc-ruta__punto tc-ruta__punto--dest"></span><b>' + esc(String(p.destino.texto).split(',')[0]) + '</b></p>'
			+ '<div class="tc-row"><span class="tc-muted">' + esc(String(p.km).replace('.', ',')) + ' km · ~' + p.minutos + ' min · ' + (p.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="tc-precio">' + esc(cup(p.precio)) + '</span></div>'
			+ (p.nota ? '<p class="tc-small">«' + esc(p.nota) + '»</p>' : '')
			+ '<div class="tc-dos-btn"><button type="button" class="tc-btn tc-btn--sec" data-act="no" data-id="' + p.id + '"' + (S.busy ? ' disabled' : '') + '>No puedo</button><button type="button" class="tc-btn" data-act="si" data-id="' + p.id + '"' + (S.busy ? ' disabled' : '') + '>Acepto</button></div></section>';
	}

	function hojaViaje(v) {
		var c = v.cliente || {}, sig = v.estado === 'recogido' ? v.destino : v.origen;
		var titulo = { asignado: 'Ve a recoger', llego: 'Esperando al cliente', recogido: 'Lleva al cliente' }[v.estado] || 'Viaje';
		var h = '<section class="tc-hoja tc-hoja--corta tc-chofer-hoja" aria-label="Tu viaje"><div class="tc-asa"></div>'
			+ '<div class="tc-row"><span class="tc-strong" style="font-size:18px">' + titulo + '</span><span class="tc-precio" style="font-size:20px">' + esc(cup(v.precio)) + '</span></div>'
			+ '<p class="tc-trayecto"><span class="tc-ruta__punto' + (v.estado === 'recogido' ? ' tc-ruta__punto--dest' : '') + '"></span><b style="max-width:none">' + esc(sig.texto) + '</b></p>'
			+ '<div class="tc-row"><span class="tc-muted">' + esc(c.nombre || 'Cliente') + (c.nota ? ' · ★ ' + esc(String(c.nota).replace('.', ',')) : '') + ' · ' + (v.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span></div>'
			+ (v.nota ? '<p class="tc-small">«' + esc(v.nota) + '»</p>' : '')
			+ (v.recibe ? '<p class="tc-small">📦 Recibe: <b>' + esc(v.recibe.nombre) + '</b> · <a href="tel:+' + esc(v.recibe.telefono) + '">' + esc(v.recibe.telefono) + '</a></p>' : '')
			+ '<div class="tc-pildoras">' + (c.telefono ? '<a class="tc-pildora" href="tel:+' + esc(c.telefono) + '">' + svg(I.tel, 16, '#087a3d') + 'Llamar</a><a class="tc-pildora" href="https://wa.me/' + esc(c.telefono) + '" target="_blank" rel="noopener">' + svg(I.wa, 16, '#087a3d') + 'WhatsApp</a>' : '')
			+ '<a class="tc-pildora" href="' + esc(navUrl(sig.lat, sig.lng)) + '" target="_blank" rel="noopener">' + svg(I.ir, 16, '#087a3d') + 'Navegar</a></div>';
		if (v.estado === 'asignado') { h += '<button type="button" class="tc-btn" data-act="llegue" data-id="' + v.id + '"' + (S.busy ? ' disabled' : '') + '>Llegué</button>'; }
		if (v.estado === 'llego' || v.estado === 'asignado') {
			h += '<div class="tc-codigo-fila"><input id="tc-codigo" class="tc-input tc-codigo-in" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="Código" aria-label="Código que te dice el cliente">'
				+ '<button type="button" class="tc-btn" data-act="recogi" data-id="' + v.id + '"' + (S.busy ? ' disabled' : '') + '>Recogí</button></div>'
				+ '<button type="button" class="tc-btn tc-btn--link" data-act="suelto" data-id="' + v.id + '">No puedo hacer este viaje</button>';
		}
		if (v.estado === 'recogido') { h += '<button type="button" class="tc-btn" data-act="termine" data-id="' + v.id + '"' + (S.busy ? ' disabled' : '') + '>Terminé</button>'; }
		return h + '<p class="tc-small tc-centro">Cobras ' + esc(cup(v.precio)) + ' al terminar: es el precio que vio el cliente.</p></section>';
	}

	function hojaTerminado(v) {
		return '<section class="tc-hoja tc-hoja--corta tc-chofer-hoja"><div class="tc-asa"></div><p class="tc-strong" style="font-size:18px">✅ Viaje terminado</p>'
			+ '<div class="tc-row"><span class="tc-muted">Cobra ' + (v.forma_pago === 'efectivo' ? 'en efectivo' : 'por transferencia') + '</span><span class="tc-precio">' + esc(cup(v.precio)) + '</span></div>'
			+ (v.cobrado ? '<p class="tc-small">Cobro anotado.</p>' : '<button type="button" class="tc-btn" data-act="cobre" data-id="' + v.id + '">Ya cobré ' + esc(cup(v.precio)) + '</button>')
			+ '<p class="tc-small">¿Qué tal el cliente?</p><div class="tc-dos-btn" style="grid-template-columns:repeat(3,minmax(0,1fr))">'
			+ [5, 3, 1].map(function (n) { return '<button type="button" class="tc-btn tc-btn--sec tc-btn--small" data-act="nota" data-id="' + v.id + '" data-n="' + n + '">' + { 5: '👍 Bien', 3: '😐 Regular', 1: '👎 Mal' }[n] + '</button>'; }).join('')
			+ '</div></section>';
	}

	function hojaMenu() {
		var f = S.form, h = S.hoy, est = h.ficha ? h.ficha.estado : 'nuevo', ahora = h.franja && h.franja.id;
		var x = '<section class="tc-hoja tc-hoja--alta tc-chofer-menu"><div class="tc-buscar-cab">' + (est === 'aprobado' ? '<button type="button" class="tc-redondo" data-act="cerrar-menu" aria-label="Volver">' + svg(I.atras) + '</button>' : '') + '<span class="tc-strong">' + (est === 'aprobado' ? 'Mi triciclo y mis precios' : 'Hazte chofer de Triciclub') + '</span></div>';
		var motivo = h.ficha && h.ficha.motivo;
		if (est === 'verificando') { x += '<p class="tc-aviso" role="status">⏳ Estamos comprobando tu carné, tu selfie y tu triciclo. Tarda 1 o 2 minutos: deja esta pantalla abierta.</p>'; }
		else if (est === 'revision') { x += '<p class="tc-aviso" role="status">👀 Una persona de Triciclub está mirando tus datos. Te avisamos por WhatsApp.</p>'; }
		else if (est !== 'aprobado' && est !== 'suspendido') { x += '<p class="tc-aviso">' + (motivo ? '↩ Corrige esto y vuelve a enviar: <b>' + esc(motivo) + '</b>' : 'Pon tu triciclo, tu carné y tus fotos, toca «Enviar y empezar» y en 1 o 2 minutos puedes recibir viajes.') + '</p>'; }
		if (est === 'suspendido') { x += '<p class="tc-aviso">Tu cuenta está parada' + (h.ficha && h.ficha.motivo ? ': ' + esc(h.ficha.motivo) : '') + '. Escribe a Triciclub por WhatsApp.</p>'; }
		if (est !== 'aprobado') { x += bloqueTriciclo(f, est); }
		x += '<h2 class="tc-h2">💰 Mis precios</h2><p class="tc-small">Los pones tú' + (est !== 'aprobado' ? ' (puedes cambiarlos cuando quieras)' : '') + '. El cliente ve el de cada triciclo según la hora y elige.</p>'
			+ '<div class="tc-tres-campos"><label class="tc-field"><span>CUP por km</span><input class="tc-input" inputmode="numeric" data-f="precio_km" value="' + esc(f.precio_km) + '"></label>'
			+ '<label class="tc-field"><span>hasta el km</span><input class="tc-input" inputmode="decimal" data-f="km_tramo" value="' + esc(f.km_tramo) + '"></label>'
			+ '<label class="tc-field"><span>luego, CUP/km</span><input class="tc-input" inputmode="numeric" data-f="precio_km_despues" value="' + esc(f.precio_km_despues) + '"></label></div>'
			+ '<p class="tc-strong" style="margin:4px 0 0">Extra según la hora</p>';
		(h.franjas || []).forEach(function (fr) {
			var v = f.extras[fr.id] != null ? f.extras[fr.id] : fr.extra;
			x += '<div class="tc-franja' + (fr.id === ahora ? ' tc-franja--ahora' : '') + '"><span><b>' + esc(fr.nombre) + (fr.id === ahora ? ' · ahora' : '') + '</b><small>' + esc(fr.desde) + '–' + esc(fr.hasta === '24:00' ? '00:00' : fr.hasta) + '</small></span><span class="tc-pct"><input class="tc-input" inputmode="numeric" data-x="' + esc(fr.id) + '" value="' + esc(v) + '" aria-label="% extra ' + esc(fr.nombre) + '"></span></div>';
		});
		var ex = f.extras[ahora] != null ? f.extras[ahora] : 0;
		x += '<div class="tc-ejemplos">' + [2, 5, 8].map(function (km) { return '<div>' + km + ' km<b>' + esc(cup(precio(f, km, ex))) + '</b></div>'; }).join('') + '</div>';
		x += (est === 'aprobado' ? bloqueTriciclo(f, est) : '<h2 class="tc-h2">🛺 Más datos (si quieres)</h2>')
			+ '<label class="tc-field"><span>Número (si tiene)</span><input class="tc-input" data-f="numero" maxlength="12" value="' + esc(f.numero) + '"></label>'
			+ '<label class="tc-field"><span>Cómo es (color, toldo…)</span><input class="tc-input" data-f="descripcion" maxlength="80" value="' + esc(f.descripcion) + '"></label>'
			+ '<div class="tc-dos-campos"><label class="tc-field"><span>Nº de la LOT</span><input class="tc-input" data-f="lot_numero" maxlength="40" value="' + esc(f.lot_numero) + '"></label><label class="tc-field"><span>La LOT vence</span><input class="tc-input" type="date" data-f="lot_vence" value="' + esc(f.lot_vence) + '"></label></div>'
			+ '<div class="tc-dos-campos"><label class="tc-field"><span>Licencia</span><input class="tc-input" data-f="licencia_cat" maxlength="8" value="' + esc(f.licencia_cat) + '" placeholder="A"></label><label class="tc-field"><span>Tarjeta (transferencias)</span><input class="tc-input" data-f="transferencia" maxlength="40" value="' + esc(f.transferencia) + '"></label></div>'
			+ (est === 'aprobado' ? '<button type="button" class="tc-btn" data-act="guardar"' + (S.busy ? ' disabled' : '') + '>Guardar</button>'
				: '<button type="button" class="tc-btn" data-act="alta"' + (S.busy || est === 'verificando' || est === 'revision' ? ' disabled' : '') + '>Enviar y empezar</button><p class="tc-small tc-centro">' + (C.verificar ? 'Comprobamos tu carné y tus fotos solos, en 1 o 2 minutos.' : 'Con todo puesto quedas aprobado al momento.') + '</p>')
			+ '</section>';
		return x;
	}

	// 3.14.130: el tipo (como lo elige el cliente), el motor, qué hace, cuántos lleva y las fotos.
	function bloqueTriciclo(f, est) {
		var fi = (S.hoy && S.hoy.ficha) || {}, fotos = fi.fotos || {}, uso = usoDe(f.servicios || []);
		var x = '<h2 class="tc-h2">🛺 Mi triciclo</h2>';
		if (est !== 'aprobado' && (fi.propio || !fi.nombre)) { x += '<label class="tc-field"><span>Tu nombre</span><input class="tc-input" data-f="nombre" maxlength="60" autocomplete="name" value="' + esc(f.nombre) + '"></label>'; }
		x += '<p class="tc-strong" style="margin:4px 0 0">¿Qué tienes?</p><div class="tc-tipos">';
		TIPOS.forEach(function (t) { var on = f.tipo === t.id; x += '<button type="button" class="tc-tipo' + (on ? ' tc-tipo--sel' : '') + '" data-act="tipo" data-id="' + t.id + '" aria-pressed="' + on + '">' + iconoTipo(t, 40, on ? '#fff' : '#087a3d') + '<b>' + esc(t.nombre) + '</b><small>' + esc(t.sub) + '</small></button>'; });
		var gr = grupoDe(f.tipo);
		x += '</div>';
		if (gr === 'taxi') { // 5-nov (Pa'llá): chapa y color del carro; licencia y LOT obligatorias (fotos abajo)
			x += '<div class="tc-dos-campos"><label class="tc-field"><span>Chapa del carro</span><input class="tc-input" data-f="chapa" maxlength="16" value="' + esc(f.chapa) + '" placeholder="P123456"></label><label class="tc-field"><span>Color</span><input class="tc-input" data-f="color" maxlength="20" value="' + esc(f.color) + '"></label></div>';
		}
		if (!gr || gr === 'tric') {
			x += '<p class="tc-strong" style="margin:4px 0 0">Motor</p><div class="tc-elige">';
			MOTORES.forEach(function (m) { x += '<button type="button" class="tc-elige__b' + (f.motor === m[0] ? ' tc-elige__b--sel' : '') + '" data-act="motor" data-id="' + m[0] + '">' + m[1] + '</button>'; });
			x += '</div>';
		}
		x += '<p class="tc-strong" style="margin:4px 0 0">¿Qué haces?</p><div class="tc-elige">';
		USOS.forEach(function (u) { x += '<button type="button" class="tc-elige__b' + (uso === u[0] ? ' tc-elige__b--sel' : '') + '" data-act="uso" data-id="' + u[0] + '">' + u[1] + '</button>'; });
		x += '</div><div class="tc-dos-campos">'
			+ (uso !== 'carga' ? '<label class="tc-field"><span>¿Cuántas personas llevas?</span><input class="tc-input" inputmode="numeric" data-f="plazas" value="' + esc(f.plazas) + '"></label>' : '')
			+ (uso === 'carga' || uso === 'dos' ? '<label class="tc-field"><span>¿Cuántos kilos cargas?</span><input class="tc-input" inputmode="numeric" data-f="carga_kg" value="' + esc(f.carga_kg) + '"></label>' : '') + '</div>';
		x += '<p class="tc-strong" style="margin:4px 0 0">Fotos</p><div class="tc-fotos">';
		fotosDe(f.tipo).forEach(function (p) { x += htmlFoto(p, fotos[p[0]]); });
		x += '</div>';
		if (est !== 'aprobado' && fi.faltan && fi.faltan.length && fi.enviado) { x += '<p class="tc-small">Falta: ' + esc(fi.faltan.join(', ')) + '.</p>'; }
		return x;
	}

	function render() {
		var html, h = S.hoy, conMapaFondo = false;
		if (S.blocked) {
			html = '<main class="tc-cerrado"><h1 class="tc-h1">Triciclub</h1><p class="tc-muted">' + esc(S.blocked) + '</p><a class="tc-btn tc-btn--sec" href="' + esc(C.palla || '/palla/') + '">Volver a Pa\'llá</a></main>';
		} else if (!h) {
			html = '<main class="tc-carga" role="status"><div class="tc-carga__via" aria-hidden="true"><svg class="tc-carga__tri" width="60" height="46" viewBox="0 0 60 46" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6h34v18H6z"/><path d="M6 24h48l-4 8H8z"/><path d="M44 6l10 18"/><circle cx="14" cy="36" r="6"/><circle cx="46" cy="36" r="6"/></svg></div><p class="tc-carga__txt">Cargando Triciclub</p></main>';
		} else {
			var f = h.ficha;
			if (!f || f.estado !== 'aprobado' || S.hoja === 'menu') { html = hojaMenu(); }
			else {
				conMapaFondo = true;
				var p = (h.pedidos || [])[0];
				html = arriba() + '<button type="button" class="tc-redondo tc-centrar" data-act="centrar" aria-label="Centrar en mi ubicación">' + svg(I.gps, 22, '#087a3d') + '</button>'
					+ (h.activo ? hojaViaje(h.activo) : (p ? hojaPedido(p) : (S.terminado ? hojaTerminado(S.terminado) : hojaLibre(f))));
			}
		}
		var antes = document.body.classList.contains('tc-con-mapa');
		document.body.classList.toggle('tc-con-mapa', conMapaFondo);
		var foco = document.activeElement && document.activeElement.getAttribute && (document.activeElement.getAttribute('data-f') || document.activeElement.getAttribute('data-x') || document.activeElement.id);
		var codigo = (document.getElementById('tc-codigo') || {}).value || '';
		app.innerHTML = html + (S.toast ? '<div class="tc-toast" role="status">' + esc(S.toast) + '</div>' : '');
		var c = document.getElementById('tc-codigo'); if (c) { c.value = codigo; }
		if (foco) { var el = app.querySelector('[data-f="' + foco + '"],[data-x="' + foco + '"],#' + String(foco).replace(/[^\w-]/g, '')); if (el && el.focus) { el.focus(); } }
		if (conMapaFondo) { if (!antes && mapa) { setTimeout(function () { mapa.resize(); }, 30); } pintarMapa(); }
		guardarBorrador();
	}

	// ------------------------------------------------------------ Eventos
	app.addEventListener('input', function (e) {
		var t = e.target, k = t.getAttribute('data-f'), x = t.getAttribute('data-x');
		if (!S.form) { return; }
		if (k || x) { S.sucio = true; }
		if (k) { S.form[k] = t.value; guardarBorrador(); }
		if (x) { S.form.extras[x] = Math.max(0, Math.min(100, parseInt(t.value, 10) || 0)); }
		if (k || x) { var ej = app.querySelector('.tc-ejemplos'); if (ej) { var ex = S.form.extras[S.hoy.franja.id] || 0; ej.innerHTML = [2, 5, 8].map(function (km) { return '<div>' + km + ' km<b>' + esc(cup(precio(S.form, km, ex))) + '</b></div>'; }).join(''); } }
	});
	app.addEventListener('change', function (e) {
		var s = e.target.getAttribute('data-s');
		if (s && S.form) { var l = S.form.servicios.filter(function (x) { return x !== s; }); if (e.target.checked) { l.push(s); } S.form.servicios = l; S.sucio = true; }
	});
	app.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var a = el.getAttribute('data-act'), id = el.getAttribute('data-id');
		if (a === 'menu') { S.hoja = 'menu'; render(); }
		else if (a === 'cerrar-menu') { S.hoja = ''; S.sucio = false; S.form = formDe(S.hoy); render(); }
		else if (a === 'guardar') { guardar(); }
		else if (a === 'alta') { enviarAlta(); }
		else if (a === 'foto') { var fx = FOTO[id] || {}; if (fx.estado === 'subiendo') { return; } if (fx.estado === 'error' && fx.blob) { subir(id, fx.blob); } else { abrirCamara(id); } }
		else if (a === 'tipo' && S.form) {
			var tp = TIPOS.filter(function (t) { return t.id === id; })[0] || {};
			S.form.tipo = id; if (tp.motor) { S.form.motor = tp.motor; }
			if (id === 'carga') { S.form.servicios = ['carga', 'paquete']; } else if (usoDe(S.form.servicios) === 'carga') { S.form.servicios = ['viaje', 'paquete']; }
			S.sucio = true; render();
		}
		else if (a === 'motor' && S.form) { S.form.motor = id; S.sucio = true; render(); }
		else if (a === 'uso' && S.form) { S.form.servicios = (USOS.filter(function (u) { return u[0] === id; })[0] || USOS[0])[2].slice(); S.sucio = true; render(); }
		else if (a === 'conectar') { audioCtx(); conectar(true); }
		else if (a === 'sonido') { audioCtx(); setTimeout(function () { var t = audio.ctx && audio.ctx.currentTime; if (t !== undefined && sonidoListo()) { pitido(880, t, 0.15); } render(); }, 150); }
		else if (a === 'desconectar') { conectar(false); }
		else if (a === 'centrar') { if (S.gps) { conMapa(function (m) { window.TcMapa.ir(m, S.gps, 16, abajo()); }); } else { toast('Activa la ubicación del teléfono.'); } }
		else if (a === 'si') { responder(id, true); }
		else if (a === 'no') { responder(id, false); }
		else if (a === 'llegue') { paso(id, 'llegue'); }
		else if (a === 'recogi') { var cod = (document.getElementById('tc-codigo') || {}).value || ''; if (cod.length !== 4) { toast('Pon el código de 4 números que te dice el cliente.'); return; } paso(id, 'recogi', { codigo: cod }); }
		else if (a === 'termine') { paso(id, 'termine'); }
		else if (a === 'suelto') { var m = window.prompt('¿Por qué no puedes? (el cliente lo verá)'); if (m !== null) { paso(id, 'suelto', { motivo: m }); } }
		else if (a === 'cobre') { paso(id, 'cobrado'); }
		else if (a === 'nota') { api('chofer/viajes/' + id + '/valorar', { estrellas: +el.getAttribute('data-n') }).then(function () { S.terminado = null; toast('Gracias.'); render(); }); }
	});
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });
	T.tick = setInterval(function () { // la cuenta atrás del pedido, sin repintar todo
		var p = S.hoy && S.hoy.pedidos && S.hoy.pedidos[0], n = app.querySelector('.tc-cuenta'), aro = app.querySelector('.tc-cuenta-aro');
		if (p && n) { var s = cuenta(p.espera_hasta); n.textContent = s; if (aro) { aro.style.setProperty('--p', Math.round(100 * s / 90)); } }
	}, 1000);

	// ------------------------------------------------------------ Arranque
	var mk = /(?:^#|&)k=([0-9a-f.]+)/i.exec(location.hash || '');
	if (mk) { set('palla.k', mk[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	S.token = get('palla.k') || '';
	mapaEl = document.createElement('div'); mapaEl.id = 'tc-mapa-fondo'; mapaEl.setAttribute('aria-label', 'Mapa'); document.body.insertBefore(mapaEl, app);
	if (!S.token) { S.blocked = 'Abre primero tu enlace de Pa\'llá en este teléfono.'; render(); return; }
	if (C.mapaUrl) { var sc = document.createElement('script'); sc.src = C.mapaUrl; sc.onload = function () { if (document.body.classList.contains('tc-con-mapa')) { pintarMapa(); } }; document.head.appendChild(sc); }
	vigilarGps();
	render(); load();
})();
