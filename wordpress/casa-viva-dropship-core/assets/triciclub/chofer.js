/* Triciclub · la parte del chofer dentro de Pa'llá (/palla/triciclub, Core 3.14.117, Ernesto 3-nov, D129–D130).
 * Usa el MISMO enlace de Pa'llá (localStorage «palla.k» → cabecera X-Palla-Token) y el estilo de Pa'llá.
 * - Mi triciclo y MIS PRECIOS: precio por km, km del primer tramo, precio por km después, y un % extra por franja horaria.
 * - Disponible: sale en la lista de los clientes cerca (manda su posición cada minuto mientras esta pantalla está abierta).
 * - Pedido nuevo: 90 s para «Acepto» o «No puedo». Viaje: Llegué → Recogí (con el código del cliente) → Terminé → Cobré.
 * ES5 a propósito, un solo oyente de clics. */
(function () {
	'use strict';
	var C = window.TRICICLUB || {};
	var app = document.getElementById('app');
	if (!app) { return; }
	var API = C.api || '/wp-json/casa-viva/v1/triciclub/';
	var SERV = [['viaje', 'Viajes'], ['paquete', 'Paquetes'], ['carga', 'Carga']];
	var state = { token: '', hoy: null, blocked: null, toast: '', busy: false, editar: false, form: null, offline: false };
	var timers = { poll: 0, gps: 0, toast: 0, tick: 0 };

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function cup(n) { return String(Math.round(+n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
	function get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
	function toast(t) { state.toast = t; render(); clearTimeout(timers.toast); timers.toast = setTimeout(function () { state.toast = ''; render(); }, 3500); }
	function servTxt(s) { return { viaje: 'Viaje', paquete: 'Paquete', carga: 'Carga' }[s] || s; }

	function api(path, body) {
		var h = { 'Accept': 'application/json', 'X-Palla-Token': state.token };
		if (body) { h['Content-Type'] = 'application/json'; }
		return fetch(API + path, { method: body ? 'POST' : 'GET', headers: h, body: body ? JSON.stringify(body) : undefined, cache: 'no-store', credentials: 'omit' })
			.then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { state.offline = false; return { status: r.status, data: d || {} }; }); })
			.catch(function () { state.offline = true; return { status: 0, data: { message: 'Sin conexión.' } }; });
	}
	function bloqueado(res) {
		if (res.status === 401 || res.status === 403) { state.blocked = res.data.message || 'No puedes entrar ahora.'; render(); return true; }
		return false;
	}

	// ------------------------------------------------------------ Precio (la misma cuenta que el servidor, para ver al momento)
	function precio(perfil, km, extra) {
		var tramo = +perfil.km_tramo || 0, a = +perfil.precio_km || 0, b = +perfil.precio_km_despues || 0;
		var base = km <= tramo ? km * a : tramo * a + (km - tramo) * b;
		base = Math.max(a, base);
		return Math.ceil(Math.round(base * (100 + (+extra || 0))) / 100 / 10) * 10; // sin decimales sueltos (720,0000001 ≠ 730)
	}

	function load() {
		return api('chofer/hoy').then(function (res) {
			if (bloqueado(res)) { return; }
			if (res.data.ok) {
				var antes = state.hoy && state.hoy.pedidos ? state.hoy.pedidos.length : 0;
				state.hoy = res.data;
				if (!state.form || !state.sucio) { state.form = formDe(res.data); } // lo que está escribiendo no se pisa al ponerse al día
				if (res.data.pedidos.length > antes && navigator.vibrate) { try { navigator.vibrate([300, 120, 300, 120, 300]); } catch (e) {} }
				if (res.data.activo) { gpsViaje(); }
			}
			render(); programar();
		});
	}
	function programar() {
		clearTimeout(timers.poll);
		var h = state.hoy || {}, f = h.ficha || {};
		var cada = h.activo || (h.pedidos && h.pedidos.length) ? 5000 : (f.disponible ? 10000 : 30000);
		timers.poll = setTimeout(load, document.hidden ? Math.max(cada, 30000) : cada);
	}

	function formDe(h) {
		var f = h.ficha || {}, p = h.perfil || {};
		return {
			numero: f.numero || '', descripcion: f.descripcion || '', plazas: f.plazas || '', servicios: f.servicios || ['viaje', 'paquete', 'carga'],
			lot_numero: f.lot_numero || '', lot_vence: f.lot_vence || '', licencia_cat: f.licencia_cat || '', transferencia: f.transferencia || '',
			precio_km: p.precio_km || 300, km_tramo: p.km_tramo || 5, precio_km_despues: p.precio_km_despues || 150, extras: p.extras || {}
		};
	}

	function guardar() {
		var f = state.form;
		state.busy = true; render();
		api('chofer/perfil', f).then(function (res) {
			state.busy = false;
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo guardar.'); return; }
			state.editar = false; state.sucio = false; toast('Guardado. Así te ven los clientes.'); load();
		});
	}

	// ------------------------------------------------------------ Ubicación
	function posicion() {
		return new Promise(function (ok) {
			if (!navigator.geolocation) { ok(null); return; }
			navigator.geolocation.getCurrentPosition(function (p) { ok(p.coords); }, function () { ok(null); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
		});
	}
	function disponible(on) {
		state.busy = true; render();
		(on ? posicion() : Promise.resolve(null)).then(function (c) {
			if (on && !c) { state.busy = false; toast('Activa la ubicación del teléfono para salir en la lista.'); return; }
			api('chofer/disponible', { on: on, lat: c && c.latitude, lng: c && c.longitude }).then(function (res) {
				state.busy = false;
				if (bloqueado(res)) { return; }
				if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); return; }
				if (state.hoy && state.hoy.ficha) { state.hoy.ficha.disponible = on; }
				gpsLibre(); render(); programar();
			});
		});
	}
	// Mientras está disponible y sin viaje: su posición cada minuto (para salir en la lista de los clientes cerca).
	function gpsLibre() {
		clearInterval(timers.gps);
		var f = state.hoy && state.hoy.ficha;
		if (!f || !f.disponible || state.hoy.activo) { return; }
		timers.gps = setInterval(function () {
			posicion().then(function (c) { if (c) { api('chofer/disponible', { on: true, lat: c.latitude, lng: c.longitude }); } });
		}, 60000);
	}
	// Con viaje: cada 15 s, para que el cliente lo vea en el mapa.
	function gpsViaje() {
		clearInterval(timers.gps);
		var mandar = function () {
			var v = state.hoy && state.hoy.activo;
			if (!v) { clearInterval(timers.gps); gpsLibre(); return; }
			posicion().then(function (c) { if (c) { api('chofer/viajes/' + v.id + '/ubicacion', { lat: c.latitude, lng: c.longitude, precision: Math.round(c.accuracy || 0) }); } });
		};
		mandar();
		timers.gps = setInterval(mandar, 15000);
	}

	function responder(id, acepto) {
		state.busy = true; render();
		api('chofer/viajes/' + id + '/responder', { acepto: acepto }).then(function (res) {
			state.busy = false;
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); }
			load();
		});
	}
	function paso(id, accion, extra) {
		var body = { accion: accion };
		Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
		state.busy = true; render();
		api('chofer/viajes/' + id + '/estado', body).then(function (res) {
			state.busy = false;
			if (bloqueado(res)) { return; }
			if (!res.data.ok) { toast(res.data.message || 'No se pudo.'); render(); return; }
			if (accion === 'termine') { state.terminado = res.data.viaje; }
			load();
		});
	}

	// ------------------------------------------------------------ Pantallas
	function top() {
		return '<header class="pl-top"><div><div class="pl-brand">🛺 Triciclub</div><div class="pl-sub">' + esc('Hola, ' + ((state.hoy && state.hoy.yo.nombre) || '').split(' ')[0]) + '</div></div>'
			+ '<a class="pl-btn pl-btn--secondary" style="text-decoration:none" href="' + esc(C.palla || '/palla/') + '">Pa\'llá</a></header>'
			+ (state.offline ? '<div class="pl-offline" role="status">Sin conexión. Toca otra vez cuando vuelva la señal.</div>' : '');
	}

	function cuenta(hasta) { return Math.max(0, Math.round((new Date(hasta).getTime() - Date.now()) / 1000)); }

	function pedidoCard(p) {
		return '<article class="pl-card pl-card--brand"><div class="pl-row" style="align-items:center"><span class="pl-strong">🛺 Te piden un ' + esc(servTxt(p.servicio).toLowerCase()) + '</span><span class="tc-cuenta">' + cuenta(p.espera_hasta) + ' s</span></div>'
			+ '<div class="pl-muted">' + esc(p.origen.texto || 'Recogida') + ' → ' + esc(p.destino.texto || 'Destino') + '</div>'
			+ '<div class="pl-row"><span class="pl-muted">' + esc(String(p.km).replace('.', ',')) + ' km · unos ' + p.minutos + ' min · ' + (p.forma_pago === 'efectivo' ? 'efectivo' : 'transferencia') + '</span><span class="pl-strong pl-num">' + esc(cup(p.precio)) + '</span></div>'
			+ (p.nota ? '<div class="pl-note">«' + esc(p.nota) + '»</div>' : '')
			+ '<div class="pl-grid2"><button type="button" class="pl-btn pl-btn--primary" data-act="si" data-id="' + p.id + '"' + (state.busy ? ' disabled' : '') + '>Acepto</button><button type="button" class="pl-btn pl-btn--secondary" data-act="no" data-id="' + p.id + '"' + (state.busy ? ' disabled' : '') + '>No puedo</button></div></article>';
	}

	function nav(lat, lng) { return 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng + '&travelmode=driving'; }

	function viajeCard(v) {
		var c = v.cliente || {};
		var h = '<article class="pl-card pl-card--brand"><div class="pl-row" style="align-items:center"><span class="pl-strong">' + esc(servTxt(v.servicio)) + ' · ' + esc(cup(v.precio)) + '</span>'
			+ '<span class="pl-badge pl-badge--info">' + esc({ asignado: 'Ve a recoger', llego: 'Esperando al cliente', recogido: 'En viaje' }[v.estado] || v.estado) + '</span></div>'
			+ '<div class="pl-strong">' + esc(c.nombre || 'Cliente') + (c.nota ? ' · ★ ' + esc(String(c.nota).replace('.', ',')) : '') + '</div>'
			+ (c.telefono ? '<div class="pl-grid2"><a class="pl-btn pl-btn--secondary" style="text-decoration:none" href="tel:+' + esc(c.telefono) + '">Llamar</a><a class="pl-btn pl-btn--secondary" style="text-decoration:none" href="https://wa.me/' + esc(c.telefono) + '" target="_blank" rel="noopener">WhatsApp</a></div>' : '')
			+ '<div class="pl-muted">📍 Recoger: <span class="pl-strong">' + esc(v.origen.texto) + '</span> · <a href="' + esc(nav(v.origen.lat, v.origen.lng)) + '" target="_blank" rel="noopener">Navegar</a></div>'
			+ '<div class="pl-muted">🏁 Llevar a: <span class="pl-strong">' + esc(v.destino.texto) + '</span> · <a href="' + esc(nav(v.destino.lat, v.destino.lng)) + '" target="_blank" rel="noopener">Navegar</a></div>'
			+ (v.recibe ? '<div class="pl-muted">📦 Recibe: <span class="pl-strong">' + esc(v.recibe.nombre) + '</span> · <a href="tel:+' + esc(v.recibe.telefono) + '">' + esc(v.recibe.telefono) + '</a></div>' : '')
			+ (v.nota ? '<div class="pl-note">«' + esc(v.nota) + '»</div>' : '')
			+ '<div class="pl-muted">Cobras <span class="pl-strong">' + esc(cup(v.precio)) + '</span> ' + (v.forma_pago === 'efectivo' ? 'en efectivo' : 'por transferencia') + ' al terminar. Es el precio que vio el cliente: no se cambia.</div>';
		if (v.estado === 'asignado') {
			h += '<button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="llegue" data-id="' + v.id + '">Llegué</button>';
		}
		if (v.estado === 'asignado' || v.estado === 'llego') {
			h += '<label class="tc-lbl">Código que te dice el cliente<input id="tc-codigo" class="tc-in tc-codigo-in" inputmode="numeric" maxlength="4" autocomplete="off"></label>'
				+ '<button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="recogi" data-id="' + v.id + '">Recogí</button>'
				+ '<button type="button" class="pl-btn pl-btn--link" data-act="suelto" data-id="' + v.id + '">No puedo hacer este viaje</button>';
		}
		if (v.estado === 'recogido') { h += '<button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="termine" data-id="' + v.id + '">Terminé</button>'; }
		return h + '</article>';
	}

	function terminadoCard(v) {
		return '<article class="pl-card"><div class="pl-strong">✅ Viaje terminado</div><div class="pl-muted">Cobra <span class="pl-strong">' + esc(cup(v.precio)) + '</span> ' + (v.forma_pago === 'efectivo' ? 'en efectivo' : 'por transferencia') + '.</div>'
			+ (v.cobrado ? '<div class="pl-muted">Cobro anotado.</div>' : '<button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="cobre" data-id="' + v.id + '">Ya cobré ' + esc(cup(v.precio)) + '</button>')
			+ '<div class="pl-muted">¿Qué tal el cliente?</div><div class="pl-grid3">'
			+ [5, 3, 1].map(function (n) { return '<button type="button" class="pl-btn pl-btn--secondary" data-act="nota" data-id="' + v.id + '" data-n="' + n + '">' + { 5: '👍 Bien', 3: '😐 Regular', 1: '👎 Mal' }[n] + '</button>'; }).join('')
			+ '</div></article>';
	}

	function precios() {
		var f = state.form, h = state.hoy, ahora = h.franja && h.franja.id;
		var html = '<article class="pl-card"><div class="pl-strong">💰 Mis precios</div><div class="pl-muted">Los pones tú. El cliente ve el precio de cada triciclo según la hora y elige.</div>'
			+ '<div class="pl-grid3"><label class="tc-lbl">CUP por km<input class="tc-in" inputmode="numeric" data-f="precio_km" value="' + esc(f.precio_km) + '"></label>'
			+ '<label class="tc-lbl">hasta el km<input class="tc-in" inputmode="decimal" data-f="km_tramo" value="' + esc(f.km_tramo) + '"></label>'
			+ '<label class="tc-lbl">luego, CUP/km<input class="tc-in" inputmode="numeric" data-f="precio_km_despues" value="' + esc(f.precio_km_despues) + '"></label></div>'
			+ '<div class="pl-strong">Extra según la hora</div><div class="tc-franjas">';
		(h.franjas || []).forEach(function (fr) {
			var v = f.extras[fr.id] != null ? f.extras[fr.id] : fr.extra;
			html += '<div class="tc-franja' + (fr.id === ahora ? ' tc-franja--ahora' : '') + '"><span class="pl-strong">' + esc(fr.nombre) + (fr.id === ahora ? ' · ahora' : '') + '<small>' + esc(fr.desde) + '–' + esc(fr.hasta === '24:00' ? '00:00' : fr.hasta) + '</small></span>'
				+ '<span class="tc-pct"><input class="tc-in" inputmode="numeric" data-x="' + esc(fr.id) + '" value="' + esc(v) + '" aria-label="% extra ' + esc(fr.nombre) + '"></span></div>';
		});
		var extra = f.extras[ahora] != null ? f.extras[ahora] : 0;
		html += '</div><div class="pl-muted">Así cobras ahora (' + esc(h.franja ? h.franja.nombre.toLowerCase() : '') + '):</div><div class="tc-ejemplos">'
			+ [2, 5, 8].map(function (km) { return '<div>' + km + ' km<b class="pl-num">' + esc(cup(precio(f, km, extra))) + '</b></div>'; }).join('')
			+ '</div><button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="guardar"' + (state.busy ? ' disabled' : '') + '>Guardar mis precios</button></article>';
		return html;
	}

	function ficha() {
		var f = state.form, est = state.hoy.ficha ? state.hoy.ficha.estado : 'nuevo';
		var html = '<article class="pl-card"><div class="pl-strong">🛺 Mi triciclo</div>'
			+ (est === 'pendiente' ? '<div class="pl-alert pl-alert--warning">Triciclub está revisando tu triciclo y tu LOT. Te avisamos.</div>' : '')
			+ (est === 'suspendido' ? '<div class="pl-alert pl-alert--danger">Tu cuenta de Triciclub está parada (por ejemplo, la LOT venció). Llama a Triciclub.</div>' : '')
			+ '<div class="pl-grid2"><label class="tc-lbl">Número del triciclo<input class="tc-in" data-f="numero" maxlength="12" value="' + esc(f.numero) + '"></label>'
			+ '<label class="tc-lbl">Plazas<input class="tc-in" inputmode="numeric" data-f="plazas" value="' + esc(f.plazas) + '"></label></div>'
			+ '<label class="tc-lbl">Cómo es (color, toldo…)<input class="tc-in" data-f="descripcion" maxlength="80" value="' + esc(f.descripcion) + '"></label>'
			+ '<div class="tc-lbl">Qué haces<div class="tc-checks">' + SERV.map(function (s) { return '<label><input type="checkbox" data-s="' + s[0] + '"' + (f.servicios.indexOf(s[0]) !== -1 ? ' checked' : '') + '>' + s[1] + '</label>'; }).join('') + '</div></div>'
			+ '<div class="pl-grid2"><label class="tc-lbl">Nº de la LOT<input class="tc-in" data-f="lot_numero" maxlength="40" value="' + esc(f.lot_numero) + '"></label>'
			+ '<label class="tc-lbl">La LOT vence<input class="tc-in" type="date" data-f="lot_vence" value="' + esc(f.lot_vence) + '"></label></div>'
			+ '<div class="pl-grid2"><label class="tc-lbl">Licencia (categoría)<input class="tc-in" data-f="licencia_cat" maxlength="8" value="' + esc(f.licencia_cat) + '" placeholder="A"></label>'
			+ '<label class="tc-lbl">Tarjeta para transferencias<input class="tc-in" data-f="transferencia" maxlength="40" value="' + esc(f.transferencia) + '"></label></div>'
			+ '<button type="button" class="pl-btn pl-btn--primary pl-btn--block" data-act="guardar"' + (state.busy ? ' disabled' : '') + '>Guardar</button></article>';
		return html;
	}

	function render() {
		var html;
		if (state.blocked) {
			html = '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>Triciclub</h1><p>' + esc(state.blocked) + '</p><a class="pl-btn pl-btn--secondary" href="' + esc(C.palla || '/palla/') + '">Volver a Pa\'llá</a></main>';
		} else if (!state.hoy) {
			html = '<main class="pl-closed pl-loading" role="status"><div class="pl-logo" aria-hidden="true">P</div><div class="pl-bar" aria-hidden="true"></div><p class="pl-dots">Cargando Triciclub</p></main>';
		} else {
			var h = state.hoy, f = h.ficha;
			html = top() + '<main class="pl-main">';
			if (f && f.estado === 'aprobado') {
				html += '<article class="pl-card"><div class="tc-switch"><div><div class="pl-strong">' + (f.disponible ? '🟢 Disponible' : '⚪ No disponible') + '</div><div class="pl-muted">' + (f.disponible ? 'Sales en la lista de los clientes cerca. Deja esta pantalla abierta.' : 'Los clientes no te ven.') + '</div></div>'
					+ '<button type="button" class="pl-btn ' + (f.disponible ? 'pl-btn--secondary' : 'pl-btn--primary') + '" data-act="disp"' + (state.busy ? ' disabled' : '') + '>' + (f.disponible ? 'Parar' : 'Empezar') + '</button></div>'
					+ '<div class="pl-muted">Hoy: <span class="pl-strong">' + h.hoy.viajes + ' viajes · ' + esc(cup(h.hoy.cup)) + '</span></div></article>';
				(h.pedidos || []).forEach(function (p) { html += pedidoCard(p); });
				if (h.activo) { html += viajeCard(h.activo); }
				if (state.terminado) { html += terminadoCard(state.terminado); }
			}
			if (!f || f.estado !== 'aprobado' || state.editar) { html += ficha(); }
			else { html += '<button type="button" class="pl-btn pl-btn--link" data-act="editar">Cambiar los datos de mi triciclo</button>'; }
			html += precios() + '</main>';
		}
		var foco = document.activeElement && document.activeElement.getAttribute && (document.activeElement.getAttribute('data-f') || document.activeElement.getAttribute('data-x') || document.activeElement.id);
		var codigo = (document.getElementById('tc-codigo') || {}).value || '';
		app.innerHTML = '<div class="pl-shell">' + html + '</div>' + (state.toast ? '<div class="pl-toast" role="status">' + esc(state.toast) + '</div>' : '');
		var c = document.getElementById('tc-codigo'); if (c) { c.value = codigo; }
		if (foco) { var el = app.querySelector('[data-f="' + foco + '"],[data-x="' + foco + '"],#' + String(foco).replace(/[^\w-]/g, '')); if (el && el.focus) { el.focus(); } }
	}

	app.addEventListener('input', function (e) {
		var t = e.target, k = t.getAttribute('data-f'), x = t.getAttribute('data-x');
		if (!state.form) { return; }
		if (k || x) { state.sucio = true; }
		if (k) { state.form[k] = t.value; state.editar = state.editar || ['precio_km', 'km_tramo', 'precio_km_despues'].indexOf(k) === -1; }
		if (x) { state.form.extras[x] = Math.max(0, Math.min(100, parseInt(t.value, 10) || 0)); }
		if (k || x) { var ej = app.querySelector('.tc-ejemplos'); if (ej) { var h = state.hoy, ex = state.form.extras[h.franja.id] || 0; ej.innerHTML = [2, 5, 8].map(function (km) { return '<div>' + km + ' km<b class="pl-num">' + esc(cup(precio(state.form, km, ex))) + '</b></div>'; }).join(''); } }
	});
	app.addEventListener('change', function (e) {
		var s = e.target.getAttribute('data-s');
		if (s && state.form) {
			var l = state.form.servicios.filter(function (x) { return x !== s; });
			if (e.target.checked) { l.push(s); }
			state.form.servicios = l;
			state.sucio = true;
		}
	});
	app.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var a = el.getAttribute('data-act'), id = el.getAttribute('data-id');
		if (a === 'guardar') { guardar(); }
		else if (a === 'editar') { state.editar = true; render(); }
		else if (a === 'disp') { disponible(!(state.hoy.ficha && state.hoy.ficha.disponible)); }
		else if (a === 'si') { responder(id, true); }
		else if (a === 'no') { responder(id, false); }
		else if (a === 'llegue') { paso(id, 'llegue'); }
		else if (a === 'recogi') { var cod = (document.getElementById('tc-codigo') || {}).value || ''; if (cod.length !== 4) { toast('Pon el código de 4 números que te dice el cliente.'); return; } paso(id, 'recogi', { codigo: cod }); }
		else if (a === 'termine') { paso(id, 'termine'); }
		else if (a === 'suelto') { var m = window.prompt('¿Por qué no puedes? (el cliente lo verá)'); if (m !== null) { paso(id, 'suelto', { motivo: m }); } }
		else if (a === 'cobre') { api('chofer/viajes/' + id + '/estado', { accion: 'cobrado' }).then(function (res) { if (res.data.ok) { state.terminado = res.data.viaje; toast('Cobro anotado.'); render(); } else { toast(res.data.message || 'No se pudo.'); } }); }
		else if (a === 'nota') { api('chofer/viajes/' + id + '/valorar', { estrellas: +el.getAttribute('data-n') }).then(function () { state.terminado = null; toast('Gracias.'); render(); }); }
	});
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });
	timers.tick = setInterval(function () { if (state.hoy && state.hoy.pedidos && state.hoy.pedidos.length) { var n = app.querySelectorAll('.tc-cuenta'); var l = state.hoy.pedidos; for (var i = 0; i < n.length && i < l.length; i++) { n[i].textContent = cuenta(l[i].espera_hasta) + ' s'; } } }, 1000);

	var m = /(?:^#|&)k=([0-9a-f.]+)/i.exec(location.hash || '');
	if (m) { set('palla.k', m[1]); if (history.replaceState) { history.replaceState(null, '', location.pathname); } }
	state.token = get('palla.k') || '';
	if (!state.token) { state.blocked = 'Abre primero tu enlace de Pa\'llá en este teléfono.'; render(); return; }
	render(); load().then(gpsLibre);
})();
