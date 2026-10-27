/* Pa'llá 3.14.61 · app del mensajero de Casa Viva (PWA). Plan: assets/palla/README.md.
 * Pantallas: 1 Carreras disponibles · 2 Mi carrera · 3 Confirmar entregado · 4 Mi dinero de hoy · 5 Sin conexión (Ayuda).
 * Rutas con «#» (el botón Atrás del teléfono funciona): #carreras · #carrera/ID · #entregado/ID · #problema/ID ·
 * #suelto/ID · #dinero · #ayuda · #ayuda/ID · #ayuda/oferta-ID.
 * Sin conexión: las acciones de la carrera van a una cola (IndexedDB) y se mandan solas al volver la señal, con el
 * mismo cid (el servidor no las repite). «La cojo» sin red NO va a la cola: se enseña la pantalla 5. */
(function () {
	'use strict';
	var C = window.PALLA || {};
	// Modo práctica (/palla/?practica=1, practica.js): misma app con carreras inventadas; nada toca pedidos reales.
	var P = /[?&]practica=1(?:&|$)/.test(location.search) ? window.PALLA_PRACTICA || null : null;
	var PFX = P ? 'palla.p.' : 'palla.';
	var API = C.api || '/wp-json/casa-viva/v1/palla/';
	var app = document.getElementById('app');
	if (!app) { return; }

	var state = { token: '', hoy: null, offline: false, blocked: null, toast: '', form: {}, busy: '', queue: [], loading: true, done: {} };
	var toastTimer = 0;

	// ------------------------------------------------------------ Utilidades
	var store = {
		get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { if (v === null) { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) {} }
	};
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0; }
	function money(a, cur) {
		var dec = cur === 'CUP' || Math.abs(a - Math.round(a)) < 0.005 ? 0 : 2;
		var parts = a.toFixed(dec).split('.');
		return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (dec ? ',' + parts[1] : '') + ' ' + cur;
	}
	function moneyText(by) { var out = []; ['USD', 'CUP', 'EUR'].forEach(function (c) { if (by && by[c] > 0) { out.push(money(by[c], c)); } }); return out.join(' + '); }
	function addMoney(a, b) { var o = {}; [a, b].forEach(function (x) { Object.keys(x || {}).forEach(function (k) { o[k] = Math.round(((o[k] || 0) + (+x[k] || 0)) * 100) / 100; }); }); return o; }
	function newCid() { return 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }

	var ICONS = {
		pedidos: '<path d="M4 7l8-4 8 4v10l-8 4-8-4z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
		dinero: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M7 9.5v5M17 9.5v5"/>',
		pregunta: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14M12 17.5v.01"/>',
		bien: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		aviso: '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.01"/>',
		cerrar: '<path d="M6 6l12 12M18 6L6 18"/>',
		tienda: '<path d="M4 10l1.5-5h13L20 10M4 10v10h16V10M4 10h16M9 20v-5h6v5"/>',
		mensaje: '<path d="M4 5h16v11H9l-5 4z"/>',
		sincronizar: '<path d="M20 11a8 8 0 0 0-14.7-3.5M4 4v4h4M4 13a8 8 0 0 0 14.7 3.5M20 20v-4h-4"/>',
		tel: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
		mapa: '<path d="M12 21s-6-5.5-6-11a6 6 0 0 1 12 0c0 5.5-6 11-6 11z"/><circle cx="12" cy="10" r="2"/>',
		senal: '<path d="M2 8.5a15 15 0 0 1 20 0M5.5 12a10 10 0 0 1 13 0M9 15.5a5 5 0 0 1 6 0M12 19v.01M3 3l18 18"/>'
	};
	function icon(name, size) { return '<svg width="' + (size || 24) + '" height="' + (size || 24) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>'; }
	function badge(tone, text) { return '<span class="pl-badge pl-badge--' + tone + '">' + esc(text) + '</span>'; }

	// ------------------------------------------------------------ Cola sin conexión (IndexedDB; si no hay, localStorage)
	var Q = {
		db: null,
		open: function () {
			return new Promise(function (resolve) {
				if (!window.indexedDB) { resolve(null); return; }
				// Si IndexedDB no contesta (bloqueado, modo privado raro), la cola sigue en localStorage.
				var timer = setTimeout(function () { resolve(null); }, 1500);
				var done = function (db) { clearTimeout(timer); Q.db = db; resolve(db); };
				try {
					var rq = window.indexedDB.open(P ? 'palla-practica' : 'palla', 1);
					rq.onupgradeneeded = function () { rq.result.createObjectStore('cola', { keyPath: 'cid' }); };
					rq.onsuccess = function () { done(rq.result); };
					rq.onerror = rq.onblocked = function () { done(null); };
				} catch (e) { done(null); }
			});
		},
		tx: function (mode, fn) {
			return new Promise(function (resolve) {
				try { var t = Q.db.transaction('cola', mode); var r = fn(t.objectStore('cola')); t.oncomplete = function () { resolve(r && r.result); }; t.onerror = function () { resolve(null); }; } catch (e) { resolve(null); }
			});
		},
		all: function () {
			if (!Q.db) { try { return Promise.resolve(JSON.parse(store.get(PFX + 'cola') || '[]')); } catch (e) { return Promise.resolve([]); } }
			return Q.tx('readonly', function (s) { return s.getAll(); }).then(function (r) { return (r || []).sort(function (a, b) { return a.at - b.at; }); });
		},
		put: function (item) {
			if (!Q.db) { return Q.all().then(function (l) { l.push(item); store.set(PFX + 'cola', JSON.stringify(l)); }); }
			return Q.tx('readwrite', function (s) { return s.put(item); });
		},
		del: function (cid) {
			if (!Q.db) { return Q.all().then(function (l) { store.set(PFX + 'cola', JSON.stringify(l.filter(function (x) { return x.cid !== cid; }))); }); }
			return Q.tx('readwrite', function (s) { return s.delete(cid); });
		}
	};

	// ------------------------------------------------------------ Red
	function api(path, body) {
		if (P) { return P.request(path, body); }
		var headers = { 'Accept': 'application/json', 'X-Palla-Token': state.token };
		if (body) { headers['Content-Type'] = 'application/json'; }
		return fetch(API + path, { method: body ? 'POST' : 'GET', headers: headers, body: body ? JSON.stringify(body) : undefined, cache: 'no-store', credentials: 'omit' })
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, offline: r.headers.get('X-Palla-Offline') === '1', data: data || {} }; });
			});
	}
	function blockedFrom(res) {
		if (res.status === 401 || res.status === 403) { state.blocked = { code: res.data.code || '', message: res.data.message || 'No puedes entrar ahora.' }; return true; }
		return false;
	}

	function findCarrera(id) { var l = (state.hoy && state.hoy.carreras) || []; for (var i = 0; i < l.length; i++) { if (String(l[i].id) === String(id)) { return l[i]; } } return null; }
	function findOffer(id) { var l = (state.hoy && state.hoy.ofertas) || []; for (var i = 0; i < l.length; i++) { if (String(l[i].id) === String(id)) { return l[i]; } } return null; }
	function putCarrera(card) {
		if (!state.hoy || !card) { return; }
		var l = state.hoy.carreras.filter(function (c) { return String(c.id) !== String(card.id); });
		if (['pickup', 'route', 'incident', 'delivered'].indexOf(card.stage) !== -1) { l.push(card); }
		state.hoy.carreras = l;
		state.hoy.ofertas = state.hoy.ofertas.filter(function (o) { return String(o.id) !== String(card.id); });
	}

	// Lo que está en la cola se ve ya hecho (hasta que el servidor conteste).
	function applyQueue() {
		if (!state.hoy) { return; }
		state.queue.forEach(function (q) {
			var c = findCarrera(q.order);
			if (!c) { return; }
			c.queued = q.action;
			if (q.action === 'recogi' && c.stage === 'pickup') { c.stage = 'route'; }
			if (q.action === 'entregado') { c.stage = 'delivered'; c.money = { after: true, bring: '', sentence: 'Se manda solo cuando vuelva la señal. Luego te digo cuánto llevas a la tienda.', fee: c.money.fee, feeText: c.money.feeText }; }
			if (q.action === 'problema') { c.stage = 'incident'; }
			if (q.action === 'hora') { c.eta = q.payload.texto; }
			if (q.action === 'suelto') { state.hoy.carreras = state.hoy.carreras.filter(function (x) { return x !== c; }); }
		});
	}

	function load() {
		return api('hoy').then(function (res) {
			if (blockedFrom(res)) { state.loading = false; render(); return; }
			if (res.status !== 200 || !res.data.ok) { throw new Error('hoy'); }
			state.blocked = null;
			var before = (state.hoy && !state.offline ? state.hoy.carreras : []).filter(function (c) { return !c.queued && !state.done[c.id]; });
			state.hoy = res.data;
			if (!res.offline) { newOffers(res.data.ofertas || []); } // 3.14.69.15: bocina si sale una carrera nueva
			var gone = before.filter(function (c) { return !findCarrera(c.id); });
			var passed = gone.filter(function (c) { return c.pase; });
			// 3.14.69.16 (Pedri 25-oct): si la cerró por WhatsApp no es «ya no está a tu nombre», es «entregado».
			var deliveredNow = ((res.data.dinero && res.data.dinero.rows) || []).map(function (r) { return String(r.number); });
			var closed = gone.filter(function (c) { return !c.pase && deliveredNow.indexOf(String(c.number)) !== -1; });
			var lost = gone.filter(function (c) { return !c.pase && deliveredNow.indexOf(String(c.number)) === -1; });
			var notes = passed.map(function (c) { return '✅ ' + c.pase.toName + ' aceptó #' + c.number + '.'; });
			closed.forEach(function (c) { notes.push('✅ #' + c.number + ' entregado.'); });
			if (lost.length) { notes.push(lost.map(function (c) { return '#' + c.number; }).join(', ') + (lost.length === 1 ? ' ya no es tuya' : ' ya no son tuyas') + '. Si tienes el paquete, llama a la tienda.'); }
			if (notes.length) { showToast(notes.join(' ')); }
			state.offline = res.offline;
			if (!res.offline) { store.set(PFX + 'hoy', JSON.stringify(res.data)); }
			applyQueue();
			state.loading = false;
			render();
		}).catch(function () {
			state.offline = true;
			if (!state.hoy) { try { state.hoy = JSON.parse(store.get(PFX + 'hoy') || 'null'); } catch (e) { state.hoy = null; } applyQueue(); }
			state.loading = false;
			render();
		});
	}

	var flushing = false;
	function flush() {
		if (flushing || !state.queue.length) { return Promise.resolve(); }
		flushing = true;
		var list = state.queue.slice();
		var step = function (i) {
			if (i >= list.length) { return Promise.resolve(); }
			var q = list[i];
			return api(q.path || 'carrera', Object.assign({ order: q.order, action: q.action, cid: q.cid }, q.payload)).then(function (res) {
				if (res.status >= 500) { throw new Error('servidor'); }
				if (blockedFrom(res)) { throw new Error('bloqueado'); }
				state.offline = false;
				return Q.del(q.cid).then(function () {
					state.queue = state.queue.filter(function (x) { return x.cid !== q.cid; });
					if (endsCarrera(q.action, res.data.result)) { state.done[q.order] = 1; }
					showToast(res.data.message || ('#' + q.number + ': listo.'));
					return step(i + 1);
				});
			});
		};
		return step(0).catch(function () { state.offline = true; }).then(function () { flushing = false; });
	}

	// ------------------------------------------------------------ Acciones
	function showToast(text) {
		state.toast = text;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { state.toast = ''; render(); }, 6000);
		render();
	}

	function claim(id) {
		var offer = findOffer(id);
		if (!offer || state.busy) { return; }
		state.busy = 'cojo-' + id; render();
		api('cojo', { order: offer.id, cid: newCid() }).then(function (res) {
			state.busy = '';
			if (blockedFrom(res)) { render(); return; }
			state.offline = false;
			var d = res.data;
			if (d.carrera) { putCarrera(d.carrera); showToast(d.message); go(d.carrera.eta ? '#carrera/' + d.carrera.id : '#hora/' + d.carrera.id); return; }
			state.hoy.ofertas = state.hoy.ofertas.filter(function (o) { return String(o.id) !== String(id); });
			showToast(d.message || 'Ya la cogió otro.');
		}).catch(function () {
			// Sin red no se pone en cola: otro mensajero puede cogerla antes.
			state.busy = ''; state.offline = true;
			go('#ayuda/oferta-' + id);
		});
	}

	/** Pasar / aceptar / no / cancelar un pase. Necesita señal: lo contesta otra persona, no se guarda en la cola. */
	function passCall(pathName, body, busyKey, after) {
		if (state.busy) { return; }
		state.busy = busyKey; render();
		api(pathName, Object.assign({ cid: newCid() }, body)).then(function (res) {
			state.busy = '';
			if (blockedFrom(res)) { render(); return; }
			state.offline = false;
			var d = res.data || {};
			if (d.result === 'bad_to') { state.form.err = d.message; render(); return; }
			if (d.carrera) { putCarrera(d.carrera); }
			state.form = {};
			showToast(d.message || 'Listo.');
			after(d);
			load();
		}).catch(function () { state.busy = ''; state.offline = true; showToast('Para pasar o aceptar una carrera necesitas señal. Prueba otra vez en un momento.'); });
	}

	/** La carrera sale de «Mis carreras» por algo que hizo el mensajero (no avisar «ya no está a tu nombre»). */
	function endsCarrera(action, result) {
		return ['entregado', 'suelto'].indexOf(action) !== -1 || ['not_now', 'not_found', 'test'].indexOf(result) !== -1;
	}

	function act(c, action, payload, pathName) {
		var where = pathName || 'carrera';
		if (state.busy) { return; }
		var item = { cid: newCid(), order: c.id, number: c.number, action: action, path: where, payload: payload || {}, at: Date.now() };
		state.busy = action + '-' + c.id; render();
		api(where, Object.assign({ order: c.id, action: action, cid: item.cid }, item.payload)).then(function (res) {
			state.busy = '';
			if (res.status >= 500) { throw new Error('servidor'); }
			if (blockedFrom(res)) { render(); return; }
			state.offline = false;
			var d = res.data;
			if (d.carrera) { putCarrera(d.carrera); } else if (d.stage) { state.hoy.carreras = state.hoy.carreras.filter(function (x) { return String(x.id) !== String(c.id); }); }
			if (d.result === 'needs_amount') { state.form.err = d.message; render(); return; }
			if (d.result === 'problem_empty' || d.result === 'eta_empty') { state.form.err = d.message; render(); return; }
			state.form = {};
			if (endsCarrera(action, d.result)) { state.done[c.id] = 1; }
			showToast(d.message);
			go(d.result === 'released' ? '#carreras' : '#carrera/' + c.id, true);
			load();
		}).catch(function () {
			state.busy = '';
			Q.put(item).then(function () {
				state.queue.push(item);
				state.offline = true;
				state.form = {};
				applyQueue();
				showToast('Sin conexión: quedó guardado. Se manda solo cuando vuelva la señal.');
				go(action === 'suelto' ? '#carreras' : '#carrera/' + c.id, true);
			});
		});
	}

	// ------------------------------------------------------------ Navegación
	function route() {
		var h = (location.hash || '').replace(/^#/, '');
		var p = h.split('/');
		return { view: p[0] || 'carreras', id: p[1] || '' };
	}
	function go(hash, replace) {
		if (replace && history.replaceState) { history.replaceState(null, '', hash); render(); } else if (location.hash === hash) { render(); } else { location.hash = hash; }
	}

	// ------------------------------------------------------------ Pantallas
	function nav(current) {
		var pending = state.hoy && state.hoy.dinero && state.hoy.dinero.pendingText;
		var item = function (hash, ic, label, key, dot) {
			return '<a href="' + hash + '"' + (current === key ? ' aria-current="page"' : '') + '>' + icon(ic) + (dot ? '<b aria-hidden="true">!</b>' : '') + '<span>' + label + '</span></a>';
		};
		return '<nav class="pl-nav" aria-label="Menú">' + item('#carreras', 'pedidos', 'Carreras', 'carreras') + item('#dinero', 'dinero', 'Mi dinero', 'dinero', !!pending) + item('#ayuda', 'pregunta', 'Ayuda', 'ayuda') + '</nav>';
	}
	// 3.14.69.16 «Ir» (Ernesto 27-oct: «que la app lo lleve a la ubicación del cliente»): en Android abre la app de mapas
	// que tenga (Google Maps, OsmAnd, Maps.me… funcionan sin datos si tiene el mapa bajado) en el punto exacto si el pedido
	// tiene coordenadas (_cvd_map_url de OpenStreetMap), o buscando la dirección; en otro teléfono, el mapa web.
	function goUrl(c) {
		var cu = c.customer || {}, m = /mlat=(-?\d+(?:\.\d+)?)&mlon=(-?\d+(?:\.\d+)?)/.exec(c.map || '');
		var where = [cu.address, cu.zone, 'La Habana', 'Cuba'].filter(Boolean).join(', ');
		if (!/Android/i.test(navigator.userAgent)) { return c.map || 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(where); }
		if (m) { return 'geo:' + m[1] + ',' + m[2] + '?q=' + m[1] + ',' + m[2] + '(' + encodeURIComponent('#' + c.number + ' ' + (cu.first || '')) + ')'; }
		return 'geo:0,0?q=' + encodeURIComponent(where);
	}
	// 3.14.69.16 (Pedri 25-oct: «lo tocas y no se nota»): el botón gira mientras actualiza.
	function refreshBtn() {
		return '<button type="button" class="pl-btn pl-btn--icon' + (state.busy === 'refresh' ? ' pl-btn--busy pl-spin' : '') + '" data-act="refresh" aria-label="Actualizar">' + icon('sincronizar', 22) + '</button>';
	}
	function offlineBar(text) {
		if (!state.offline) { return ''; }
		var q = state.queue.length ? ' ' + state.queue.length + (state.queue.length === 1 ? ' acción guardada.' : ' acciones guardadas.') : '';
		return '<div class="pl-offline" role="status">' + icon('senal') + '<span>' + esc((text || 'Sin conexión. Lo que ves es de la última vez.') + q) + '</span></div>';
	}
	function stageBadge(c) {
		if (c.queued) { return badge('muted', 'Guardado'); }
		return { pickup: badge('warning', 'Por recoger'), route: badge('info', 'En camino'), incident: badge('danger', 'Problema avisado'), delivered: badge('success', 'Entregado') }[c.stage] || '';
	}

	// ------------------------------------------------------------ Avisos push (3.14.69.1)
	// El teléfono se da de alta en Core (POST palla/push, con cookie): llega una señal y /palla/sw.js enseña el último aviso.
	function pushSupported() { return !P && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && location.protocol === 'https:'; }
	function pushAllowed() { return pushSupported() && state.hoy && state.hoy.push && state.hoy.push.allowed && state.hoy.push.key; }
	function pushState() { if (!pushAllowed()) { return 'none'; } if (Notification.permission === 'denied') { return 'denied'; } return Notification.permission === 'granted' && store.get(PFX + 'push') ? 'on' : 'off'; }
	function keyBytes(b64) { var pad = '='.repeat((4 - b64.length % 4) % 4), raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')), out = new Uint8Array(raw.length); for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); } return out; }
	function pushCall(method, endpoint) {
		return fetch(API + 'push', { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Palla-Token': state.token }, body: JSON.stringify({ endpoint: endpoint }), credentials: 'include', cache: 'no-store' }).then(function (r) { return r.json(); });
	}
	function pushOn() {
		if (!pushAllowed() || state.busy) { return; }
		state.busy = 'push'; render();
		Notification.requestPermission().then(function (perm) {
			if (perm !== 'granted') { throw new Error('permiso'); }
			return navigator.serviceWorker.ready;
		}).then(function (reg) {
			return reg.pushManager.getSubscription().then(function (sub) { return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(state.hoy.push.key) }); });
		}).then(function (sub) {
			return pushCall('POST', sub.endpoint).then(function (d) { if (!d || !d.subscribed) { throw new Error('core'); } store.set(PFX + 'push', sub.endpoint); });
		}).then(function () { state.busy = ''; showToast('🔔 Avisos activados. Te avisamos aunque tengas la app cerrada.'); })
			.catch(function (e) { state.busy = ''; showToast(e && e.message === 'permiso' ? 'Sin permiso no te podemos avisar. Si cambias de idea: Chrome → ⋮ → Configuración → Notificaciones.' : 'No se pudieron activar los avisos ahora. Prueba otra vez con señal.'); });
	}
	function pushOff() {
		var ep = store.get(PFX + 'push');
		store.set(PFX + 'push', null);
		navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) { if (sub) { ep = sub.endpoint; return sub.unsubscribe(); } })
			.then(function () { return ep ? pushCall('DELETE', ep) : null; }).catch(function () {}).then(function () { showToast('Avisos desactivados en este teléfono.'); });
	}
	// «Probar aquí»: aviso hecho por el propio teléfono (si no sale, Android tiene los avisos bloqueados o en silencio).
	function pushLocal() {
		navigator.serviceWorker.ready.then(function (reg) { return reg.showNotification('🔔 Prueba en este teléfono', { body: 'Si ves esto, el teléfono deja enseñar avisos de Pa\'llá.', icon: C.icon192 || undefined, tag: 'palla-local', renotify: true, vibrate: [300, 120, 300] }); })
			.then(function () { showToast('Mira arriba en el teléfono: debe salir «Prueba en este teléfono».'); }, function () { showToast('El teléfono no deja enseñar avisos. Ajustes → Apps → Chrome → Notificaciones.'); });
	}
	// «Probar desde la tienda»: Core manda un aviso de verdad por internet (cierra la app para verlo como llegará).
	function pushTest() {
		if (state.busy) { return; }
		state.busy = 'push-test'; render();
		fetch(API + 'push/prueba', { method: 'POST', headers: { 'Accept': 'application/json', 'X-Palla-Token': state.token }, credentials: 'include', cache: 'no-store' }).then(function (r) { return r.json(); })
			.then(function (d) { state.busy = ''; showToast(d && d.sent ? 'Enviado. Cierra la app y espera un minuto: debe sonar «Prueba de Pa\'llá».' : ((d && d.message) || 'No se pudo enviar ahora.')); })
			.catch(function () { state.busy = ''; showToast('Sin señal: prueba otra vez con internet.'); });
	}
	// ------------------------------------------------------------ Sonido de «carrera nueva» (3.14.69.15, Ernesto 27-oct)
	// Con la app abierta: si sale una carrera libre nueva, suena una bocina, vibra y sale arriba «🛵 Carrera nueva», y se
	// repite cada 3 s hasta que el mensajero toque la pantalla (máx. 5 min). El teléfono solo deja sonar después de un
	// primer toque («Activar sonido» o cualquier toque). «Esperando carreras» deja la pantalla encendida (Wake Lock) para
	// que no se apague. Sin Google ni VPN; con la app cerrada no suena (eso es ntfy / la app instalable).
	var snd = { ctx: null, seen: null, timer: 0, until: 0, lock: null };
	function audioCtx() {
		var A = window.AudioContext || window.webkitAudioContext;
		if (!A) { return null; }
		if (!snd.ctx) { try { snd.ctx = new A(); } catch (e) { return null; } }
		if (snd.ctx.state === 'suspended') { snd.ctx.resume().catch(function () {}); }
		return snd.ctx;
	}
	function soundReady() { return !!(snd.ctx && snd.ctx.state === 'running'); }
	function honk() { // bocina de moto: dos pitidos fuertes, distintos de WhatsApp
		var ctx = audioCtx();
		if (!ctx || ctx.state !== 'running') { return; }
		[0, 0.34].forEach(function (d) {
			var t = ctx.currentTime + d, g = ctx.createGain();
			g.gain.setValueAtTime(0.0001, t);
			g.gain.exponentialRampToValueAtTime(0.6, t + 0.02);
			g.gain.setValueAtTime(0.6, t + 0.22);
			g.gain.exponentialRampToValueAtTime(0.0001, t + 0.28);
			g.connect(ctx.destination);
			[660, 880].forEach(function (f) { var o = ctx.createOscillator(); o.type = 'square'; o.frequency.setValueAtTime(f, t); o.connect(g); o.start(t); o.stop(t + 0.3); });
		});
	}
	function alarmTick() {
		if (!state.alarm || Date.now() > snd.until) { alarmStop(); return; }
		honk();
		if (navigator.vibrate) { try { navigator.vibrate([400, 150, 400]); } catch (e) {} }
	}
	function alarmStart(fresh) {
		var o = fresh[0];
		state.alarm = { id: o.id, number: o.number, zone: o.zone || '', more: fresh.length - 1 };
		snd.until = Date.now() + 5 * 60000;
		clearInterval(snd.timer);
		alarmTick();
		snd.timer = setInterval(alarmTick, 3000);
	}
	function alarmStop() {
		clearInterval(snd.timer);
		snd.timer = 0;
		if (navigator.vibrate) { try { navigator.vibrate(0); } catch (e) {} }
	}
	/** Cada vez que llega la lista: ¿hay alguna carrera libre que no estaba? (la 1.ª vez solo se apunta lo que hay). */
	function newOffers(list) {
		var ids = list.map(function (o) { return String(o.id); });
		var seen = snd.seen;
		if (!seen) { try { seen = JSON.parse(store.get(PFX + 'vistas') || 'null'); } catch (e) { seen = null; } }
		snd.seen = ids;
		store.set(PFX + 'vistas', JSON.stringify(ids));
		if (state.alarm && ids.indexOf(String(state.alarm.id)) === -1) { state.alarm = null; alarmStop(); } // ya la cogió otro
		if (!seen) { return; }
		var fresh = list.filter(function (o) { return seen.indexOf(String(o.id)) === -1; });
		if (fresh.length) { alarmStart(fresh); }
	}
	function waiting() { return store.get(PFX + 'espera') === '1'; }
	function lockScreen() {
		if (!waiting() || snd.lock || !('wakeLock' in navigator) || document.visibilityState !== 'visible') { return; }
		navigator.wakeLock.request('screen').then(function (l) { snd.lock = l; l.addEventListener('release', function () { snd.lock = null; }); }).catch(function () {});
	}
	function waitOn() {
		audioCtx();
		store.set(PFX + 'espera', '1');
		store.set(PFX + 'espera-desde', String(Date.now()));
		lockScreen();
		showToast('wakeLock' in navigator ? '🛵 Esperando carreras: la pantalla se queda encendida y suena cuando salga una.' : 'Este teléfono no deja mantener la pantalla encendida desde la app: sube «Suspender después de» en Ajustes → Pantalla. El sonido sí está activado.');
		setTimeout(honk, 150);
	}
	function waitOff() {
		store.set(PFX + 'espera', null);
		if (snd.lock) { snd.lock.release().catch(function () {}); snd.lock = null; }
		showToast('Listo: la pantalla ya se puede apagar.');
	}
	function alarmBar() {
		var a = state.alarm;
		if (!a) { return ''; }
		return '<a class="pl-alarm" href="#carreras" data-act="alarm-ver" role="alert">🛵 <span><b>Carrera nueva #' + esc(a.number) + '</b>' + (a.zone ? ' · ' + esc(a.zone) : '') + (a.more > 0 ? ' (y ' + a.more + ' más)' : '') + ' — toca para verla</span></a>';
	}
	function waitBar() {
		if (!waiting()) { return ''; }
		return '<div class="pl-wait" role="status"><span>🛵 <b>Esperando carreras</b> · ' + (soundReady() ? 'pantalla encendida, suena al salir una' : 'toca la pantalla una vez para activar el sonido') + '</span><button type="button" class="pl-btn pl-btn--link" data-act="wait-off">Parar</button></div>';
	}
	// 3.14.69.16 (Ernesto 27-oct: «quitar botones innecesarios y textos explicativos»): una sola línea, sin explicación.
	function soundCard() {
		if (waiting()) { return ''; }
		return '<button type="button" class="pl-btn pl-btn--secondary pl-btn--block" data-act="' + (soundReady() ? 'wait-on' : 'snd-on') + '">' + (soundReady() ? '🛵 Esperando carreras' : '🔊 Activar sonido') + '</button>';
	}
	// Cualquier toque: deja sonar al teléfono y para la bocina (sin redibujar aquí, para no perder el toque).
	document.addEventListener('pointerdown', function () {
		audioCtx();
		if (snd.timer) { alarmStop(); if (route().view === 'carreras') { state.alarm = null; } }
	}, true);

	// 3.14.69.16: los avisos del navegador van por Google y en Cuba no son fiables: ya no se ofrecen en Carreras (sigue la
	// línea en Ayuda para quien ya los tiene). Los avisos de verdad llegarán con la app instalable.
	function pushCard() { return ''; }

	function screenCarreras() {
		var h = state.hoy, yo = h.yo || {};
		// 3.14.69.16 (Ernesto 27-oct): minimalista. Sin tarjetas de explicación, sin «Ver mi carrera» (toda la tarjeta se
		// toca), «Cobras 44 USD» en vez de frases, y «Por llevar» arriba si debe dinero a la tienda.
		var d = h.dinero || {};
		var html = '<header class="pl-top"><div><div class="pl-brand">Pa\'llá</div><div class="pl-sub">' + esc('Hola, ' + (yo.first || 'mensajero')) + '</div></div>' + refreshBtn() + '</header>' + offlineBar();
		html += '<main class="pl-main">' + soundCard();
		if (d.pendingText) { html += '<a class="pl-card pl-card--tap pl-card--brand pl-row" style="text-decoration:none;color:inherit;align-items:center" href="#dinero"><span>💵 Por llevar a la tienda</span><span class="pl-strong pl-num">' + esc(d.pendingText) + '</span></a>'; }
		(h.pases || []).forEach(function (x) {
			html += '<article class="pl-card pl-card--brand"><div class="pl-row" style="align-items:center"><span class="pl-strong">🔁 ' + esc(x.from) + ' te pasa #' + esc(x.number) + '</span>' + (x.picked ? badge('info', 'Recogida') : '') + '</div>'
				+ '<div class="pl-muted">' + esc([x.zone, x.when, x.feeText].filter(Boolean).join(' · ')) + '</div>'
				+ '<div class="pl-grid2"><button type="button" class="pl-btn pl-btn--primary" data-act="pase-si" data-id="' + x.id + '">La acepto</button><button type="button" class="pl-btn pl-btn--secondary" data-act="pase-no" data-id="' + x.id + '">No puedo</button></div></article>';
		});
		if (h.carreras.length) {
			html += '<h2 class="pl-h2">Mis carreras</h2>';
			h.carreras.forEach(function (c) {
				html += '<a class="pl-card pl-card--tap" style="text-decoration:none;color:inherit" href="#carrera/' + c.id + '"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' + esc(c.number) + ' · ' + esc(c.customer.zone) + '</span>' + stageBadge(c) + '</div>'
					+ (c.eta ? '<div class="pl-muted">🕒 ' + esc(c.eta) + '</div>' : '') + '</a>';
			});
		}
		html += '<div class="pl-row"><h1 class="pl-h1">Libres</h1><span class="pl-muted">' + h.ofertas.length + '</span></div>';
		if (!h.ofertas.length) { html += '<p class="pl-empty">No hay carreras libres.</p>'; }
		h.ofertas.forEach(function (o, i) {
			var busy = state.busy === 'cojo-' + o.id;
			var items = o.items.length > 1 ? o.items[0] + ' y ' + (o.items.length - 1) + ' más' : (o.items[0] || '');
			html += '<article class="pl-card"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' + esc(o.number) + ' · ' + esc(o.zone) + '</span><span class="pl-num" style="font-weight:800;white-space:nowrap">' + esc(o.feeText) + '</span></div>'
				+ '<div class="pl-muted">' + esc(o.when || 'lo antes posible') + ' · ' + esc(items) + '</div>'
				+ '<div class="pl-muted">Cobras <span class="pl-strong">' + esc(o.collect) + '</span>' + (o.change ? ' · vuelto ' + esc(o.change) : '') + (o.assembly ? ' · 🔧 armar' : '') + (o.supplier ? ' · bajo pedido' : '') + '</div>'
				+ '<button type="button" class="pl-btn pl-btn--block ' + (i === 0 ? 'pl-btn--primary' : 'pl-btn--secondary') + (busy ? ' pl-btn--busy' : '') + '" data-act="cojo" data-id="' + o.id + '">' + (busy ? 'Cogiéndola…' : 'La cojo') + '</button></article>';
		});
		return html + '</main>' + nav('carreras');
	}

	function wordFor(c) {
		// Ya pagado: «Entregado N» a secas. Con cifra: la del vale. Sin tasa: «cantidad» (la escribe él).
		var m = c.money || {};
		var amount = m.prepaid ? '' : (!m.after && m.chargeShort && /\d/.test(m.chargeShort) ? ' ' + m.chargeShort.split(' + ')[0].toLowerCase() : ' cantidad');
		return { recogi: 'Recogí ' + c.number, entregado: 'Entregado ' + c.number + amount, problema: 'Problema ' + c.number + ' no estaba', suelto: 'No puedo ' + c.number };
	}

	function screenCarrera(c) {
		var m = c.money || {}, cu = c.customer || {};
		var html = '<header class="pl-top pl-top--bar"><a class="pl-back" href="#carreras">‹ Carreras</a>' + stageBadge(c) + '</header>' + offlineBar('Sin conexión. Tu carrera sigue guardada.');
		html += '<main class="pl-main pl-main--pad-actions"><h1 class="pl-h1">#' + esc(c.number) + ' · ' + esc(cu.zone) + '</h1>';
		if (c.test) { html += '<div class="pl-test">PRUEBA · nada es real</div>'; }
		// 3.14.69.16 (Ernesto 27-oct): 4 pasos que se van marcando solos — hora · recogí · entregado · dinero en la tienda —
		// y la carrera se cierra cuando la tienda recibe el dinero (Mi dinero). Sin cuadros de explicación.
		var done = { hora: !!c.eta || c.stage !== 'pickup', recogi: c.stage !== 'pickup', entregado: c.stage === 'delivered' };
		var steps = [['hora', 'Hora'], ['recogi', 'Recogí'], ['entregado', 'Entregado'], ['dinero', 'Dinero']];
		var now = steps.filter(function (s) { return !done[s[0]]; })[0];
		html += '<ol class="pl-track">' + steps.map(function (s) { return '<li class="' + (done[s[0]] ? 'is-done' : (now && now[0] === s[0] ? 'is-now' : '')) + '">' + (done[s[0]] ? '✓ ' : '') + s[1] + '</li>'; }).join('') + '</ol>';
		if (c.queued) { html += '<div class="pl-note">' + icon('sincronizar', 20) + '<div>Guardado. Se manda al volver la señal.</div></div>'; }
		if (c.pase) { html += '<div class="pl-note">🔁<div>Esperando que ' + esc(c.pase.toName) + ' la acepte. <button type="button" class="pl-btn pl-btn--link" style="padding:0;min-height:0" data-act="pase-cancelar" data-id="' + c.id + '">Cancelar</button></div></div>'; }
		if (c.stage === 'incident' && !c.queued) { html += '<div class="pl-alert pl-alert--warning">' + icon('aviso', 20) + '<div><b>Problema avisado</b>Espera lo que diga la tienda.</div></div>'; }
		if (c.stage === 'delivered' && m.after) { html += '<div class="pl-alert pl-alert--success">' + icon('bien', 20) + '<div><b>Entregado</b>' + esc(m.sentence || '') + '</div></div>'; }
		// Cliente: dónde, cuándo y los dos botones que importan (Llamar · Ir).
		html += '<section class="pl-card"><div class="pl-strong">' + esc(cu.name) + '</div><div>' + esc(cu.address || cu.zone) + '</div><div class="pl-muted">' + esc([cu.address ? cu.zone : '', cu.reference].filter(Boolean).join(' · ')) + '</div>';
		if (c.eta) { html += '<div class="pl-row" style="align-items:center"><span>🕒 <span class="pl-strong">' + esc(c.eta) + '</span></span><a class="pl-btn pl-btn--link" href="#hora/' + c.id + '">Cambiar</a></div>'; }
		else if (c.when) { html += '<div class="pl-muted">Pidió ' + esc(c.when) + '</div>'; }
		if (cu.note) { html += '<div class="pl-muted">📝 ' + esc(cu.note) + '</div>'; }
		html += '<div class="pl-grid2">' + (cu.phone ? '<a class="pl-btn pl-btn--secondary" href="tel:+' + esc(cu.phone) + '">' + icon('tel', 20) + 'Llamar</a>' : '<span class="pl-btn pl-btn--secondary" aria-disabled="true">Sin teléfono</span>')
			+ (c.map || cu.address || cu.zone ? '<a class="pl-btn pl-btn--secondary" href="' + esc(goUrl(c)) + '" target="_blank" rel="noopener noreferrer">' + icon('mapa', 20) + 'Ir</a>' : '<span class="pl-btn pl-btn--secondary" aria-disabled="true">Sin mapa</span>') + '</div>';
		if (cu.alt) { html += '<div class="pl-muted">Otro: <a href="tel:+' + esc(cu.alt) + '">' + esc(cu.altLabel) + '</a></div>'; }
		html += '</section>';
		// Paquete: qué llevas (y de dónde, si aún no lo recogió).
		html += '<section class="pl-card"><ul class="pl-items">' + c.items.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
		if (c.stage === 'pickup') { html += '<div class="pl-muted">' + esc(c.pickup.place + ' · ' + c.pickup.hours) + '</div>'; }
		if (c.pickup.suppliers && c.pickup.suppliers.length && c.stage === 'pickup') { html += '<div class="pl-muted">Bajo pedido: pregunta en la tienda dónde se recoge.</div>'; }
		if (c.assembly) { html += '<div class="pl-muted">🔧 Armado: <span class="pl-strong">' + esc(c.assembly) + '</span> (tuyo)</div>'; }
		html += '</section>';
		if (!m.after) {
			var small = function (t) { return String(t).length > 11 ? ' pl-amount--sm' : ''; };
			html += '<section class="pl-card"><div class="pl-money"><div><span class="pl-muted">Cobras</span><span class="pl-amount' + small(m.chargeShort) + '">' + esc(m.chargeShort) + '</span></div><div><span class="pl-muted">A la tienda</span><span class="pl-amount' + small(m.bring) + '">' + esc(m.bring) + '</span></div></div>'
				+ '<div class="pl-muted">Mensajería <span class="pl-strong">' + esc(m.feeText) + '</span>' + (m.changeText ? ' · vuelto <span class="pl-strong">' + esc(m.changeText) + '</span>' + (m.payWith ? ' (paga con ' + esc(m.payWith) + ')' : '') : '') + '</div></section>';
		} else if (m.feeText) {
			html += '<section class="pl-card"><div class="pl-muted">Mensajería <span class="pl-strong">' + esc(m.feeText) + '</span></div></section>';
		}
		html += '</main>';
		// Abajo: UN botón grande según el paso; lo demás, en una línea de enlaces.
		var b = function (a) { return state.busy === a + '-' + c.id ? ' pl-btn--busy' : ''; };
		var links = [];
		var acts = '<div class="pl-actions">';
		if (c.stage === 'pickup' && !c.eta) { acts += '<a class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block" href="#hora/' + c.id + '">🕒 Poner la hora</a>'; }
		else if (c.stage === 'pickup') { acts += '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + b('recogi') + '" data-act="recogi" data-id="' + c.id + '">' + icon('bien') + 'Recogí</button>'; }
		else if (c.stage === 'route') { acts += '<a class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block" href="#entregado/' + c.id + '">' + icon('bien') + 'Entregado</a>'; }
		else if (c.stage === 'delivered') { acts += '<a class="pl-btn pl-btn--secondary pl-btn--lg pl-btn--block" href="#dinero">💵 Mi dinero</a>'; }
		if (['pickup', 'route', 'delivered'].indexOf(c.stage) !== -1) { links.push('<a href="#problema/' + c.id + '">Problema</a>'); }
		if (c.stage === 'pickup') { links.push('<a href="#suelto/' + c.id + '">No puedo</a>'); }
		if (['pickup', 'route'].indexOf(c.stage) !== -1 && !c.pase && !c.queued) { links.push('<a href="#pasar/' + c.id + '">Pasar</a>'); }
		if (links.length) { acts += '<div class="pl-links">' + links.join('') + '</div>'; }
		return html + acts + '</div>';
	}

	var KIND = { cash_usd: 'Efectivo USD', cash_cup: 'Efectivo CUP', transfer_cup: 'Transferencia CUP', clasica: 'Tarjeta Clásica', eur: 'Efectivo EUR' };
	function payOptions(m) {
		var o = [];
		if (m.prepaid) { return o; }
		// Primera opción: SIEMPRE cómo dice el pedido que paga (sin tasa, con el campo para escribir los CUP).
		o.push({ k: 'vale', label: KIND[m.kind] || m.label || 'Lo del vale', amount: m.unknown ? '' : m.productsText });
		if (m.kind !== 'cash_cup' && m.currency !== 'CUP') { o.push({ k: 'cup', label: 'Efectivo CUP' }); }
		if (!m.transfer) { o.push({ k: 'transfer', label: 'Transferencia', amount: m.unknown ? '' : m.productsText }); }
		o.push({ k: 'otra', label: 'Otra cantidad' });
		return o;
	}
	// Lo que se manda al servidor y lo que lleva a la tienda, según lo elegido.
	function payPlan(c) {
		var m = c.money, f = state.form, opts = payOptions(m), k = f.pay || (opts[0] && opts[0].k) || 'vale';
		if (m.prepaid) { return { payload: {}, bring: m.change || {}, ok: true }; }
		if (k === 'vale' && !m.unknown) { return { payload: {}, bring: addMoney(m.transfer ? {} : m.products, m.change), ok: true }; }
		if (k === 'vale') {
			var cupv = num(f.cup);
			return { payload: { otra: 1, cup: cupv, como: m.transfer ? 'transferencia' : 'efectivo' }, bring: addMoney(m.transfer ? {} : { CUP: cupv }, m.change), ok: cupv > 0 };
		}
		if (k === 'cup' || (k === 'transfer' && m.unknown)) {
			var cup = num(f.cup), tr = k === 'transfer';
			return { payload: { otra: 1, cup: cup, como: tr ? 'transferencia' : 'efectivo' }, bring: addMoney(tr ? {} : { CUP: cup }, m.change), ok: cup > 0 };
		}
		if (k === 'transfer') { return { payload: { otra: 1, usd: m.products.USD || 0, cup: m.products.CUP || 0, eur: m.products.EUR || 0, como: 'transferencia' }, bring: m.change || {}, ok: true }; }
		var by = { USD: num(f.usd), CUP: num(f.cupo), EUR: num(f.eur) }, trans = f.como === 'transferencia';
		return { payload: { otra: 1, usd: by.USD, cup: by.CUP, eur: by.EUR, como: trans ? 'transferencia' : 'efectivo' }, bring: addMoney(trans ? {} : by, m.change), ok: by.USD + by.CUP + by.EUR > 0 };
	}
	function bringNote(c) {
		var t = moneyText(payPlan(c).bring);
		return t ? 'A la tienda: <span style="font-weight:700">' + esc(t) + '</span> antes de las 4 p. m.' : 'No llevas dinero a la tienda.'; // 3.14.69.16: más corto
	}

	function sheetEntregado(c) {
		var m = c.money, f = state.form, opts = payOptions(m), k = f.pay || (opts[0] && opts[0].k);
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Cuánto cobraste?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<div class="pl-muted" style="font-size:16px;line-height:24px">Pedido #' + esc(c.number) + ' · ' + esc(c.customer.first || c.customer.name) + ' · ' + esc(c.customer.zone) + '</div>';
		if (m.prepaid) {
			html += '<div class="pl-alert pl-alert--success">' + icon('bien', 20) + '<div><b>Ya pagado</b>No cobras nada.</div></div>';
		} else {
			html += '<fieldset class="pl-fs"><legend>Cómo pagó</legend>';
			opts.forEach(function (o) {
				html += '<label class="pl-opt' + (o.k === k ? ' pl-opt--on' : '') + '"><span><input type="radio" name="pago" value="' + o.k + '" data-act="pay"' + (o.k === k ? ' checked' : '') + '> ' + esc(o.label) + '</span>' + (o.amount ? '<span class="pl-num">' + esc(o.amount) + '</span>' : '') + '</label>';
				if (o.k !== k) { return; }
				if (o.k === 'cup' || ((o.k === 'transfer' || o.k === 'vale') && m.unknown)) {
					html += '<label class="pl-field">CUP que cobraste (solo productos)<input data-f="cup" type="number" inputmode="decimal" min="0" step="1" value="' + esc(f.cup || '') + '" placeholder="0"></label>';
				}
				if (o.k === 'otra') {
					html += '<div class="pl-fields"><label class="pl-field">USD<input data-f="usd" type="number" inputmode="decimal" min="0" step="0.01" value="' + esc(f.usd || '') + '" placeholder="0"></label><label class="pl-field">CUP<input data-f="cupo" type="number" inputmode="decimal" min="0" step="1" value="' + esc(f.cupo || '') + '" placeholder="0"></label>'
						+ (m.currency === 'EUR' ? '<label class="pl-field">EUR<input data-f="eur" type="number" inputmode="decimal" min="0" step="0.01" value="' + esc(f.eur || '') + '" placeholder="0"></label>' : '') + '</div>'
						+ '<div class="pl-pills" role="radiogroup" aria-label="Cómo pagó"><label class="pl-pill"><input type="radio" name="como" value="efectivo" data-act="como"' + (f.como !== 'transferencia' ? ' checked' : '') + '> Efectivo</label><label class="pl-pill"><input type="radio" name="como" value="transferencia" data-act="como"' + (f.como === 'transferencia' ? ' checked' : '') + '> Transferencia</label></div>'
						+ '<div class="pl-muted">Sin tu mensajería.</div>';
				}
			});
			html += '</fieldset>';
		}
		html += '<div class="pl-note">' + icon('dinero') + '<div id="pl-bring">' + bringNote(c) + '</div></div>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		html += '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'entregado-' + c.id ? ' pl-btn--busy' : '') + '" data-act="entregado" data-id="' + c.id + '">' + icon('bien') + 'Confirmar entregado</button>';
		return html;
	}

	var MOTIVOS = [['no_estaba', 'El cliente no estaba'], ['parte', 'Solo quiso una parte'], ['devuelve', 'Lo devuelve'], ['vale_mal', 'El vale está mal'], ['otro', 'Otro problema']];
	function sheetProblema(c) {
		var f = state.form;
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Qué pasó?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div><div class="pl-muted" style="font-size:16px">#' + esc(c.number) + '</div><fieldset class="pl-fs"><legend>Motivo</legend>';
		MOTIVOS.forEach(function (o) { html += '<label class="pl-opt' + (f.motivo === o[0] ? ' pl-opt--on' : '') + '"><span><input type="radio" name="motivo" value="' + o[0] + '" data-act="motivo"' + (f.motivo === o[0] ? ' checked' : '') + '> ' + o[1] + '</span></label>'; });
		html += '</fieldset><label class="pl-field">Detalle' + (f.motivo === 'otro' ? '' : ' (opcional)') + '<textarea data-f="texto" maxlength="400" rows="2">' + esc(f.texto || '') + '</textarea></label>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		return html + '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'problema-' + c.id ? ' pl-btn--busy' : '') + '" data-act="problema" data-id="' + c.id + '">Avisar a la tienda</button>';
	}
	var HORAS = ['en 30 min', 'en 1 hora', 'en 2 horas', 'hoy por la tarde', 'mañana por la mañana'];
	/** «15:30» + hoy|mañana → «hoy a las 3:30 p. m.» */
	function horaTexto(hhmm, dia) {
		var p = String(hhmm || '').split(':'), h = parseInt(p[0], 10), m = p[1] || '00';
		if (!(h >= 0 && h <= 23)) { return ''; }
		return (dia === 'manana' ? 'mañana' : 'hoy') + ' a las ' + (h % 12 || 12) + ':' + m + (h < 12 ? ' a. m.' : ' p. m.');
	}
	function horaElegida() { var f = state.form; return f.hora === 'otra' ? horaTexto(f.hhmm, f.dia) : (f.hora || ''); }
	function sheetHora(c) {
		var f = state.form, sel = f.hora || '';
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿A qué hora lo entregas?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<div class="pl-muted" style="font-size:16px;line-height:24px">#' + esc(c.number) + ' · ' + esc(c.customer.zone) + (c.when ? ' · pidió ' + esc(c.when) : '') + '</div><fieldset class="pl-fs"><legend>Elige una</legend>';
		HORAS.concat(['otra']).forEach(function (h) {
			html += '<label class="pl-opt' + (sel === h ? ' pl-opt--on' : '') + '"><span><input type="radio" name="hora" value="' + esc(h) + '" data-act="hora-op"' + (sel === h ? ' checked' : '') + '> ' + (h === 'otra' ? 'Otra hora' : esc(h.charAt(0).toUpperCase() + h.slice(1))) + '</span></label>';
			if (h === 'otra' && sel === 'otra') {
				html += '<div class="pl-fields"><label class="pl-field">Día<select data-f="dia" style="min-height:48px;font-size:18px;border:1px solid var(--line-strong);border-radius:8px;padding:0 8px"><option value="hoy"' + (f.dia !== 'manana' ? ' selected' : '') + '>Hoy</option><option value="manana"' + (f.dia === 'manana' ? ' selected' : '') + '>Mañana</option></select></label><label class="pl-field">Hora<input data-f="hhmm" type="time" value="' + esc(f.hhmm || '') + '"></label></div>';
			}
		});
		html += '</fieldset>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		return html + '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'hora-' + c.id ? ' pl-btn--busy' : '') + '" data-act="hora" data-id="' + c.id + '">🕒 Guardar la hora</button>';
	}

	function sheetPasar(c) {
		var list = (state.hoy && state.hoy.companeros) || [], f = state.form;
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿A quién se la pasas?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<p style="margin:0">#' + esc(c.number) + ' sigue siendo tuya hasta que acepte.' + (c.stage === 'route' ? ' Dale el paquete en mano.' : '') + '</p>';
		if (!list.length) { return html + '<p class="pl-empty">Nadie más usa Pa\'llá todavía.</p>'; }
		html += '<fieldset class="pl-fs"><legend>Compañeros</legend>';
		list.forEach(function (m) { html += '<label class="pl-opt' + (String(f.to) === String(m.id) ? ' pl-opt--on' : '') + '"><span><input type="radio" name="to" value="' + m.id + '" data-act="pasar-a"' + (String(f.to) === String(m.id) ? ' checked' : '') + '> ' + esc(m.name) + '</span></label>'; });
		html += '</fieldset>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		return html + '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'pasar-' + c.id ? ' pl-btn--busy' : '') + '" data-act="pasar" data-id="' + c.id + '">🔁 Pedir que la acepte</button>';
	}

	function sheetSuelto(c) {
		return '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Sueltas la #' + esc(c.number) + '?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<p style="margin:0">Vuelve a quedar libre para otro.</p>'
			+ '<button type="button" class="pl-btn pl-btn--danger pl-btn--lg pl-btn--block' + (state.busy === 'suelto-' + c.id ? ' pl-btn--busy' : '') + '" data-act="suelto" data-id="' + c.id + '">Sí, la suelto</button><a class="pl-btn pl-btn--secondary pl-btn--block" href="#carrera/' + c.id + '">No, sigo con ella</a>';
	}

	function screenDinero() {
		// 3.14.69.16 (Pedri 25-oct: historial y acumulado en vez de su Excel): pestañas Hoy · Historial.
		var d = state.hoy.dinero || { rows: [] }, tab = state.dineroTab === 'hist' ? 'hist' : 'hoy';
		var html = '<header class="pl-top"><h1 class="pl-h1">Mi dinero</h1></header>' + offlineBar() + '<main class="pl-main">';
		html += '<div class="pl-tabs" role="tablist"><button type="button" role="tab" aria-selected="' + (tab === 'hoy') + '" data-act="dtab" data-tab="hoy">Hoy</button><button type="button" role="tab" aria-selected="' + (tab === 'hist') + '" data-act="dtab" data-tab="hist">Historial</button></div>';
		if (tab === 'hist') { return html + historyHtml() + '</main>' + nav('dinero'); }
		html += '<div class="pl-tiles"><div class="pl-tile"><span class="pl-muted">Ganaste</span><span class="pl-amount">' + esc(d.earnedText || '0') + '</span><span class="pl-muted">CUP</span></div>'
			+ '<div class="pl-tile pl-tile--brand"><span style="font-size:14px">A la tienda</span><span class="pl-amount' + ((d.pendingText || '').length > 9 ? ' pl-amount--sm' : '') + '">' + esc(d.pendingText || 'Nada') + '</span><span style="font-size:14px">' + esc(d.pendingText ? d.deadline : '') + '</span></div></div>';
		if (!d.rows.length) { html += '<p class="pl-empty">Hoy todavía no entregaste.</p>'; }
		else { html += rowsHtml(d.rows); }
		html += '<a class="pl-btn pl-btn--link" href="' + esc(C.storeMap || '#') + '" target="_blank" rel="noopener noreferrer">' + icon('tienda', 20) + 'Cómo llegar a la tienda</a>';
		return html + '</main>' + nav('dinero');
	}
	function rowsHtml(rows) {
		return '<div class="pl-list">' + rows.map(function (r) {
			return '<div class="pl-li"><div><span class="pl-strong">#' + esc(r.number) + ' · ' + esc(r.zone) + '</span><span class="pl-muted">' + esc(r.feeText) + '</span></div><div class="pl-end">' + (r.cash ? '<span class="pl-strong pl-num">' + esc(r.cash) + '</span>' : '') + (r.pending ? badge('warning', 'Por llevar') : badge('success', '✓')) + '</div></div>';
		}).join('') + '</div>';
	}
	function loadHistory() {
		if (state.histLoading) { return; }
		state.histLoading = true;
		api('historial?dias=31').then(function (res) {
			state.histLoading = false;
			if (res.status === 200 && res.data.ok) { state.hist = res.data; store.set(PFX + 'hist', JSON.stringify(res.data)); }
			else { throw new Error('hist'); }
			render();
		}).catch(function () {
			state.histLoading = false;
			try { state.hist = JSON.parse(store.get(PFX + 'hist') || 'null'); } catch (e) { state.hist = null; }
			state.hist = state.hist ? Object.assign({ old: true }, state.hist) : { error: true };
			render();
		});
	}
	function historyHtml() {
		var hs = state.hist;
		if (!hs) { loadHistory(); return '<p class="pl-empty">Cargando…</p>'; }
		if (hs.error) { return '<p class="pl-empty">Sin señal. <button type="button" class="pl-btn pl-btn--link" data-act="hist-retry">Probar otra vez</button></p>'; }
		var tile = function (label, t) { return '<div class="pl-tile"><span class="pl-muted">' + label + '</span><span class="pl-amount pl-amount--sm">' + esc(t.earnedText) + '</span><span class="pl-muted">' + t.count + (t.count === 1 ? ' carrera' : ' carreras') + '</span></div>'; };
		var html = (hs.old ? '<p class="pl-muted">Sin señal: es lo de la última vez.</p>' : '') + '<div class="pl-tiles">' + tile('Esta semana', hs.semana) + tile('Este mes', hs.mes) + '</div>';
		if (!hs.days.length) { return html + '<p class="pl-empty">Todavía no hay carreras entregadas.</p>'; }
		hs.days.forEach(function (day) {
			html += '<div class="pl-row pl-day"><span class="pl-strong">' + esc(day.label) + '</span><span class="pl-muted">' + day.count + ' · ' + esc(day.earnedText) + '</span></div>' + rowsHtml(day.rows);
		});
		return html;
	}

	function screenAyuda(id) {
		// 3.14.69.16 (Pedri 25-oct): se elige la carrera (no solo la última) y «sin señal» se explica en dos líneas.
		var mine = (state.hoy && state.hoy.carreras) || [];
		var offerId = /^oferta-/.test(id) ? id.slice(7) : '';
		var c = offerId ? null : (findCarrera(id) || mine[0] || null);
		var o = offerId ? findOffer(offerId) : null;
		var n = c ? c.number : (o ? o.number : '');
		var html = '<header class="pl-top"><h1 class="pl-h1">Ayuda</h1></header>' + offlineBar();
		html += '<main class="pl-main"><section class="pl-card"><div class="pl-strong">Sin señal</div><div>Lo que ya cogiste sigue aquí, y lo que toques se manda solo al volver la señal.</div><div class="pl-muted">Para ver carreras nuevas hace falta señal.</div></section>';
		if (state.queue.length) { html += '<div class="pl-note">' + icon('sincronizar', 20) + '<div>' + state.queue.length + (state.queue.length === 1 ? ' acción guardada' : ' acciones guardadas') + ', esperando la señal.</div></div>'; }
		html += '<h2 class="pl-h2">Por WhatsApp</h2>';
		if (mine.length > 1) { html += '<div class="pl-chips">' + mine.map(function (x) { return '<a class="pl-chip' + (c && String(x.id) === String(c.id) ? ' is-on' : '') + '" href="#ayuda/' + x.id + '">#' + esc(x.number) + '</a>'; }).join('') + '</div>'; }
		if (!c && !o) { html += '<p class="pl-empty">Cuando tengas una carrera, aquí salen sus palabras.</p>'; }
		else {
			var w = c ? wordFor(c) : null;
			var cmds = o ? [['Yo', 'En el grupo, debajo del anuncio de #' + n]] : [[w.entregado, 'Entregado y cobrado'], [w.problema, 'Algo salió mal'], [w.suelto, 'La sueltas']];
			// 3.14.69.8: solo lo que el bot en vivo entiende («Recogí» se toca en la app y va en la cola).
			html += '<div class="pl-list">' + cmds.map(function (x) {
				return '<div class="pl-li"><div><span class="pl-num" style="font-weight:700">' + esc(x[0]) + '</span><span class="pl-muted">' + esc(x[1]) + '</span></div><button type="button" class="pl-btn pl-btn--link" data-act="copiar" data-text="' + esc(x[0]) + '" aria-label="Copiar ' + esc(x[0]) + '">Copiar</button></div>';
			}).join('') + '</div>';
		}
		var ps = pushState();
		if (ps === 'on') { html += '<p class="pl-muted">🔔 Avisos activados · <button type="button" class="pl-btn pl-btn--link" style="padding:0;min-height:0" data-act="push-off">Quitar</button></p>'; }
		html += '<a class="pl-btn pl-btn--secondary pl-btn--block" href="https://wa.me/' + esc(C.whatsapp || '') + '" target="_blank" rel="noopener noreferrer">' + icon('mensaje') + 'WhatsApp de Casa Viva</a></main>';
		return html + nav('ayuda');
	}

	function screenBlocked() {
		var b = state.blocked || {};
		var title = { palla_token: 'Tu enlace ya no vale', palla_closed: 'Todavía no está abierta', palla_not_tester: 'Pa\'llá está en prueba', palla_not_messenger: 'Solo para mensajeros', palla_nolink: 'Abre tu enlace personal' }[b.code] || 'No puedes entrar ahora';
		return '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>' + esc(title) + '</h1><p>' + esc(b.message || '') + '</p><button type="button" class="pl-btn pl-btn--secondary" data-act="retry">Probar otra vez</button></main>';
	}

	function practiceBar() {
		var on = P.online;
		return '<div class="pl-practica" role="region" aria-label="Modo práctica"><span><b>PRÁCTICA</b> · nada es real</span>'
			+ '<button type="button" data-act="p-senal" aria-pressed="' + (on ? 'true' : 'false') + '">' + (on ? 'Con señal' : 'Sin señal') + '</button>'
			+ '<label class="pl-sr" for="pl-evento">Simular algo de fuera</label><select id="pl-evento" data-act="p-evento"><option value="">Pasa algo…</option><option value="otro">Otro mensajero coge una</option><option value="nueva">Sale una carrera nueva</option><option value="quita">La tienda me quita una</option><option value="cancela">La tienda cancela una mía</option><option value="recibido">La tienda recibe mi dinero</option><option value="acepta">Mi compañero acepta mi pase</option><option value="mepasan">Un compañero me pasa una</option></select>'
			+ '<button type="button" data-act="p-reset">Reiniciar</button></div>';
	}

	var lastView = '';
	function render() {
		var r = route(), html, sheet = '';
		if (state.blocked) { html = screenBlocked(); }
		else if (!state.hoy) {
			html = state.loading ? '<main class="pl-closed pl-loading" role="status"><div class="pl-logo" aria-hidden="true">P</div><div class="pl-bar" aria-hidden="true"></div><p class="pl-dots">Cargando Pa\'llá</p></main>' // 3.14.69.15: logo animado
				: '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><h1>Sin conexión</h1><p>La primera vez hace falta señal para bajar tus carreras.</p><button type="button" class="pl-btn pl-btn--secondary" data-act="retry">Probar otra vez</button></main>';
		} else {
			var c = r.id ? findCarrera(r.id) : null;
			if (['carrera', 'entregado', 'problema', 'suelto', 'hora', 'pasar'].indexOf(r.view) !== -1 && !c) { r.view = 'carreras'; }
			if (['carrera', 'entregado', 'problema', 'suelto', 'hora', 'pasar'].indexOf(r.view) !== -1) {
				html = screenCarrera(c);
				if (r.view === 'entregado' && c.stage !== 'delivered') { sheet = sheetEntregado(c); }
				if (r.view === 'problema') { sheet = sheetProblema(c); }
				if (r.view === 'suelto' && c.stage === 'pickup') { sheet = sheetSuelto(c); }
				if (r.view === 'hora' && ['pickup', 'route', 'incident'].indexOf(c.stage) !== -1) { sheet = sheetHora(c); }
				if (r.view === 'pasar' && ['pickup', 'route'].indexOf(c.stage) !== -1) { sheet = sheetPasar(c); }
			} else if (r.view === 'dinero') { html = screenDinero(); }
			else if (r.view === 'ayuda') { html = screenAyuda(r.id); }
			else { html = screenCarreras(); }
		}
		var view = r.view + '/' + r.id;
		var scroll = view === lastView ? window.scrollY : 0;
		var top = state.hoy && !state.blocked ? alarmBar() + waitBar() : ''; // 3.14.69.15
		app.innerHTML = '<div class="pl-shell">' + (P ? practiceBar() : '') + top + html + '</div>'
			+ (sheet ? '<div class="pl-scrim" data-act="scrim"><div class="pl-sheet" role="dialog" aria-modal="true" aria-labelledby="pl-sheet-t">' + sheet + '</div></div>' : '')
			+ (state.toast ? '<div class="pl-toast" role="status">' + esc(state.toast) + '</div>' : '');
		window.scrollTo(0, scroll);
		lastView = view;
	}

	// ------------------------------------------------------------ Eventos (un solo oyente para toda la app)
	app.addEventListener('click', function (e) {
		var el = e.target.closest('[data-act]');
		if (!el) { return; }
		var a = el.getAttribute('data-act'), r = route(), c = findCarrera(el.getAttribute('data-id') || r.id);
		if (a === 'scrim') { if (e.target === el) { go('#carrera/' + r.id); } return; }
		// 3.14.69.16: «Actualizar» ya no borra la pantalla: el botón gira y la lista se pone al día.
		if (a === 'refresh' && state.hoy && !state.blocked) { if (state.busy) { return; } state.busy = 'refresh'; render(); flush().then(load).then(function () { state.busy = ''; render(); }); return; }
		if (a === 'refresh' || a === 'retry') { state.blocked = null; state.loading = true; render(); start(); return; }
		if (a === 'dtab') { state.dineroTab = el.getAttribute('data-tab'); if (state.dineroTab === 'hist') { state.hist = null; } render(); return; }
		if (a === 'hist-retry') { state.hist = null; render(); return; }
		if (a === 'cojo') { claim(el.getAttribute('data-id')); return; }
		if (a === 'recogi' && c) { act(c, 'recogi'); return; }
		if (a === 'push-on') { store.set(PFX + 'push-no', null); pushOn(); return; }
		if (a === 'push-no') { store.set(PFX + 'push-no', '1'); render(); return; }
		if (a === 'push-off') { pushOff(); return; }
		if (a === 'snd-on') { audioCtx(); setTimeout(function () { honk(); render(); }, 150); showToast('🔊 Sonido activado: suena cuando salga una carrera libre (con la app abierta).'); return; }
		if (a === 'wait-on') { waitOn(); return; }
		if (a === 'wait-off') { waitOff(); return; }
		if (a === 'alarm-ver') { state.alarm = null; alarmStop(); if (location.hash === '#carreras') { e.preventDefault(); render(); } return; } // si no, el enlace lleva a #carreras
		if (a === 'push-local') { pushLocal(); return; }
		if (a === 'push-test') { pushTest(); return; }
		if (a === 'suelto' && c) { act(c, 'suelto'); return; }
		if (a === 'pasar' && c) {
			if (!state.form.to) { state.form.err = 'Elige a quién se la pasas.'; render(); return; }
			passCall('pasar', { order: c.id, to: state.form.to }, 'pasar-' + c.id, function () { go('#carrera/' + c.id, true); }); return;
		}
		if (a === 'pase-cancelar' && c) { passCall('pase', { order: c.id, decision: 'cancelar' }, 'pase-' + c.id, function () { render(); }); return; }
		if (a === 'pase-si' || a === 'pase-no') {
			var pid = el.getAttribute('data-id');
			passCall('pase', { order: pid, decision: a === 'pase-si' ? 'acepto' : 'no' }, 'pase-' + pid, function (d) {
				if (state.hoy) { state.hoy.pases = (state.hoy.pases || []).filter(function (x) { return String(x.id) !== String(pid); }); }
				if (d.result === 'pass_accepted') { go('#hora/' + pid); }
			}); return;
		}
		if (a === 'hora' && c) {
			var t = horaElegida();
			if (!t) { state.form.err = state.form.hora === 'otra' ? 'Pon la hora.' : 'Elige a qué hora lo entregas.'; render(); return; }
			act(c, 'hora', { texto: t }, 'hora'); return;
		}
		if (a === 'entregado' && c) {
			var plan = payPlan(c);
			if (!plan.ok) { state.form.err = 'Escribe cuánto cobraste de los productos.'; render(); return; }
			act(c, 'entregado', plan.payload); return;
		}
		if (a === 'problema' && c) {
			var f = state.form;
			if (!f.motivo) { f.err = 'Elige qué pasó.'; render(); return; }
			if (f.motivo === 'otro' && (f.texto || '').trim().length < 3) { f.err = 'Escribe en una frase qué pasó.'; render(); return; }
			act(c, 'problema', { motivo: f.motivo, texto: f.texto || '' }); return;
		}
		if (a === 'p-senal' && P) {
			P.setOnline(!P.online);
			if (P.online) { showToast('Volvió la señal: la app manda sola lo que quedó guardado.'); flush().then(load); } else { state.offline = true; showToast('Sin señal (práctica). Sigue trabajando: lo que toques queda guardado.'); }
			return;
		}
		if (a === 'p-reset' && P) {
			if (!window.confirm('¿Empezar la práctica de nuevo? Se borra lo que hiciste.')) { return; }
			P.reset();
			Q.all().then(function (l) { return Promise.all((l || []).map(function (x) { return Q.del(x.cid); })); }).then(function () { state.queue = []; state.hoy = null; state.done = {}; state.offline = false; go('#carreras', true); load(); });
			return;
		}
		if (a === 'copiar') {
			var t = el.getAttribute('data-text');
			var done = function () { showToast('Copiado: «' + t + '». Pégalo en WhatsApp.'); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () { showToast(t); }); } else { showToast(t); }
		}
	});
	app.addEventListener('change', function (e) {
		var el = e.target, a = el.getAttribute('data-act');
		if (a === 'pay') { state.form = { pay: el.value }; render(); }
		if (a === 'como') { state.form.como = el.value; updateBring(); }
		if (a === 'p-evento' && P && el.value) { showToast(P.event(el.value)); el.value = ''; load(); return; }
		if (a === 'pasar-a') { state.form = { to: el.value }; render(); return; }
		if (a === 'hora-op') { state.form = { hora: el.value, dia: state.form.dia, hhmm: state.form.hhmm }; render(); return; }
		if (el.getAttribute('data-f') === 'dia') { state.form.dia = el.value; return; }
		if (a === 'motivo') { state.form.motivo = el.value; state.form.err = ''; render(); }
	});
	app.addEventListener('input', function (e) {
		var k = e.target.getAttribute('data-f');
		if (!k) { return; }
		state.form[k] = e.target.value; state.form.err = '';
		updateBring();
	});
	function updateBring() {
		var r = route(), c = findCarrera(r.id), box = document.getElementById('pl-bring');
		if (c && box && r.view === 'entregado') { box.innerHTML = bringNote(c); }
	}
	window.addEventListener('hashchange', function () { var v = route().view; if (['entregado', 'problema', 'suelto', 'hora', 'pasar'].indexOf(v) === -1) { state.form = {}; } render(); });
	window.addEventListener('online', function () { flush().then(load); });
	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && state.token) { lockScreen(); flush().then(load); } });
	setInterval(function () {
		// 3.14.69.15: «Esperando carreras» se apaga solo a las 4 h (batería).
		if (waiting() && Date.now() - Number(store.get(PFX + 'espera-desde') || 0) > 4 * 3600000) { waitOff(); render(); }
		var v = route().view;
		if (document.visibilityState === 'visible' && state.token && !state.blocked && ['entregado', 'problema', 'suelto', 'hora', 'pasar'].indexOf(v) === -1) { flush().then(load); }
	}, 30000);

	// ------------------------------------------------------------ Arranque
	function getToken() {
		if (P) { return Promise.resolve('practica'); }
		var m = /(?:^#|&)k=([0-9a-f.]+)/i.exec(location.hash || '');
		if (m) {
			store.set(PFX + 'k', m[1]);
			if (history.replaceState) { history.replaceState(null, '', location.pathname + location.search + '#carreras'); }
			return Promise.resolve(m[1]);
		}
		var saved = store.get(PFX + 'k');
		if (saved) { return Promise.resolve(saved); }
		// Con la sesión de WordPress abierta (mensajero que ya entró en la web).
		return fetch(C.session || '/palla/sesion', { headers: { 'X-Palla': '1', 'Accept': 'application/json' }, credentials: 'include', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.token) { store.set(PFX + 'k', d.token); return d.token; } if (d && d.message) { state.blocked = { code: d.code, message: d.message }; } return ''; })
			.catch(function () { return ''; });
	}

	function start() {
		return getToken().then(function (token) {
			state.token = token;
			if (!token) {
				if (!state.blocked) { state.blocked = { code: 'palla_nolink', message: 'Pídele a la tienda tu enlace de Pa\'llá y ábrelo en este teléfono. Es solo tuyo: no lo compartas.' }; }
				state.loading = false; render(); return;
			}
			return Q.all().then(function (l) { state.queue = l || []; return flush(); }).then(load);
		});
	}

	if ('serviceWorker' in navigator && C.sw && location.protocol === 'https:') {
		navigator.serviceWorker.register(C.sw, { scope: C.scope || './' }).catch(function () {});
	}
	render();
	Q.open().then(start);
})();
