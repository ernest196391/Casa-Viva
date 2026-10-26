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
			var gone = before.filter(function (c) { return !findCarrera(c.id); });
			var passed = gone.filter(function (c) { return c.pase; });
			var lost = gone.filter(function (c) { return !c.pase; });
			var notes = passed.map(function (c) { return '✅ ' + c.pase.toName + ' aceptó #' + c.number + ': ya no es tuya.'; });
			if (lost.length) { notes.push(lost.map(function (c) { return '#' + c.number; }).join(', ') + (lost.length === 1 ? ' ya no está' : ' ya no están') + ' a tu nombre: la tienda la canceló o se la pasó a otro mensajero. Si tenías el paquete, llama a la tienda.'); }
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
	function pushCard() {
		if (pushState() !== 'off' || store.get(PFX + 'push-no')) { return ''; }
		return '<article class="pl-card pl-card--brand"><b>🔔 Activa los avisos</b><span>Te avisamos al teléfono cuando salga una carrera libre, para que la cojas antes. Aunque tengas la app cerrada.</span>'
			+ '<div class="pl-grid2"><button type="button" class="pl-btn pl-btn--primary' + (state.busy === 'push' ? ' pl-btn--busy' : '') + '" data-act="push-on">Activar avisos</button><button type="button" class="pl-btn pl-btn--secondary" data-act="push-no">Ahora no</button></div></article>';
	}

	function screenCarreras() {
		var h = state.hoy, yo = h.yo || {};
		var html = '<header class="pl-top"><div><div class="pl-brand">Pa\'llá</div><div class="pl-sub">' + esc('Hola, ' + (yo.first || 'mensajero') + (yo.vehicle ? ' · ' + yo.vehicle : '')) + '</div></div>' + (yo.available ? badge('success', 'Disponible') : badge('muted', 'No disponible')) + '</header>' + offlineBar();
		html += '<main class="pl-main">' + pushCard();
		(h.pases || []).forEach(function (x) {
			html += '<article class="pl-card pl-card--brand"><div class="pl-row" style="align-items:center"><span class="pl-strong">🔁 ' + esc(x.from) + ' te quiere pasar #' + esc(x.number) + '</span>' + (x.picked ? badge('info', 'Ya recogida') : '') + '</div>'
				+ '<div class="pl-muted">' + esc(x.zone) + (x.when ? ' · ' + esc(x.when) : '') + (x.feeText ? ' · ' + esc(x.feeText) : '') + '</div>'
				+ '<div class="pl-muted">' + esc((x.items || []).join(' · ')) + '</div>'
				+ '<div class="pl-grid2"><button type="button" class="pl-btn pl-btn--primary" data-act="pase-si" data-id="' + x.id + '">' + icon('bien', 18) + 'La acepto</button><button type="button" class="pl-btn pl-btn--secondary" data-act="pase-no" data-id="' + x.id + '">No puedo</button></div></article>';
		});
		if (h.carreras.length) {
			html += '<h2 class="pl-h2">Mis carreras</h2>';
			h.carreras.forEach(function (c) {
				var where = c.stage === 'pickup' ? 'Recoges en Casa Viva · ' + c.pickup.zone : 'Entregas en ' + c.customer.zone;
				html += '<a class="pl-card pl-card--tap" style="text-decoration:none;color:inherit" href="#carrera/' + c.id + '"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' + esc(c.number) + ' · ' + esc(c.customer.zone) + '</span>' + stageBadge(c) + '</div><div class="pl-muted">' + esc(where) + '</div><span class="pl-btn pl-btn--secondary pl-btn--block">Ver mi carrera</span></a>';
			});
		}
		html += '<div class="pl-row"><h1 class="pl-h1">Carreras disponibles</h1><span class="pl-muted">' + h.ofertas.length + (h.ofertas.length === 1 ? ' libre' : ' libres') + '</span></div>';
		if (!h.ofertas.length) {
			html += '<div class="pl-card"><b>No hay carreras libres ahora</b><span class="pl-muted">Cuando la tienda tenga una lista, sale aquí. La lista se pone al día sola.</span><button class="pl-btn pl-btn--secondary pl-btn--block" data-act="refresh" type="button">' + icon('sincronizar', 20) + 'Actualizar</button></div>';
		}
		h.ofertas.forEach(function (o, i) {
			var busy = state.busy === 'cojo-' + o.id;
			var items = o.items.length > 1 ? o.items[0] + ' y ' + (o.items.length - 1) + ' más' : (o.items[0] || '');
			html += '<article class="pl-card"><div class="pl-row" style="align-items:center"><span class="pl-strong">#' + esc(o.number) + ' · ' + esc(o.when || 'lo antes posible') + '</span><span class="pl-num" style="font-weight:800;white-space:nowrap">' + esc(o.feeText) + '</span></div>'
				+ '<div class="pl-route"><div><span class="pl-dot"></span><span>' + esc(o.from) + '</span></div><div><span class="pl-sq"></span><span>' + esc(o.zone) + '</span></div></div>'
				+ '<div class="pl-meta"><span>' + esc(items) + '</span></div>'
				+ '<div class="pl-muted">Cobras al cliente: <span class="pl-strong">' + esc(o.collect) + '</span>' + (o.change ? ' · la tienda te da ' + esc(o.change) + ' de vuelto' : '') + '</div>'
				+ (o.assembly ? '<div class="pl-muted">🔧 ' + esc(o.assembly) + '</div>' : '')
				+ (o.supplier ? '<div class="pl-muted">Bajo pedido: la tienda te dice dónde se recoge.</div>' : '')
				+ '<button type="button" class="pl-btn pl-btn--block ' + (i === 0 ? 'pl-btn--primary' : 'pl-btn--secondary') + (busy ? ' pl-btn--busy' : '') + '" data-act="cojo" data-id="' + o.id + '">' + (i === 0 ? icon('bien', 20) : '') + (busy ? 'Cogiéndola…' : 'La cojo') + '</button></article>';
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
		if (c.test) { html += '<div class="pl-test">PRUEBA · los botones no cambian nada</div>'; }
		var picked = c.stage !== 'pickup';
		html += '<div class="pl-steps"><div class="pl-step ' + (picked ? 'pl-step--done' : 'pl-step--now') + '">' + (picked ? '✓' : '1 ·') + ' Recoger</div><div class="pl-step ' + (c.stage === 'delivered' ? 'pl-step--done' : (picked ? 'pl-step--now' : '')) + '">' + (c.stage === 'delivered' ? '✓' : '2 ·') + ' Entregar</div></div>';
		if (c.queued) { html += '<div class="pl-alert pl-alert--info">' + icon('sincronizar', 20) + '<div><b>Guardado sin conexión</b>Se manda solo cuando vuelva la señal. No tienes que repetir nada.</div></div>'; }
		if (c.pase) { html += '<div class="pl-alert pl-alert--info">' + icon('sincronizar', 20) + '<div><b>Esperando que ' + esc(c.pase.toName) + ' la acepte</b>Sigue siendo tuya hasta que diga que sí.<br><button type="button" class="pl-btn pl-btn--link" style="padding:0" data-act="pase-cancelar" data-id="' + c.id + '">Cancelar el pase</button></div></div>'; }
		if (c.stage === 'incident' && !c.queued) { html += '<div class="pl-alert pl-alert--warning">' + icon('aviso', 20) + '<div><b>Hay un problema avisado</b>La tienda te dice qué hacer. No lo marques entregado hasta entonces.</div></div>'; }
		if (c.stage === 'delivered' && m.after) { html += '<div class="pl-alert pl-alert--success">' + icon('bien', 20) + '<div><b>Entregado</b>' + esc(m.sentence || '') + ' La mensajería es tuya.</div></div>'; }
		html += '<section class="pl-card"><div class="pl-label">' + (c.stage === 'pickup' ? 'Recoges en' : 'Llevas') + '</div>';
		if (c.stage === 'pickup') { html += '<div class="pl-strong">' + esc(c.pickup.place) + '</div><div class="pl-muted">' + esc(c.pickup.zone + ' · ' + c.pickup.hours) + '</div>'; }
		html += '<ul class="pl-items">' + c.items.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>';
		if (c.pickup.suppliers && c.pickup.suppliers.length && c.stage === 'pickup') { html += '<div class="pl-muted">Bajo pedido (' + esc(c.pickup.suppliers.join(', ')) + '): pregunta en la tienda si lo recoges allí o en el proveedor antes de salir.</div>'; }
		if (c.assembly) { html += '<div class="pl-muted">🔧 Hay que armarlo: cobras <span class="pl-strong">' + esc(c.assembly) + '</span> de armado aparte (es tuyo).</div>'; }
		html += '</section>';
		html += '<section class="pl-card"><div class="pl-label">Entregas a</div><div class="pl-strong">' + esc(cu.name + (cu.address ? ' · ' + cu.address : '')) + '</div><div class="pl-muted">' + esc([cu.zone, cu.reference].filter(Boolean).join(' · ')) + '</div>';
		if (c.when) { html += '<div class="pl-muted">Pidió: <span class="pl-strong">' + esc(c.when) + '</span></div>'; }
		html += c.eta ? '<div class="pl-row" style="align-items:center"><span>🕒 Lo entregas: <span class="pl-strong">' + esc(c.eta) + '</span></span><a class="pl-btn pl-btn--link" href="#hora/' + c.id + '">Cambiar</a></div>' : '<div class="pl-alert pl-alert--warning">' + icon('aviso', 20) + '<div><b>Di a qué hora lo entregas</b>Se lo decimos a quien lo espera. Sin la hora no puedes marcar «Recogí».</div></div>';
		if (cu.note) { html += '<div class="pl-muted">Nota: ' + esc(cu.note) + '</div>'; }
		html += '<div class="pl-grid2">' + (cu.phone ? '<a class="pl-btn pl-btn--secondary" href="tel:+' + esc(cu.phone) + '" aria-label="Llamar al cliente">' + icon('tel', 20) + 'Llamar</a>' : '<span class="pl-btn pl-btn--secondary" aria-disabled="true">Sin teléfono</span>')
			+ (c.map ? '<a class="pl-btn pl-btn--secondary" href="' + esc(c.map) + '" target="_blank" rel="noopener noreferrer" aria-label="Abrir en el mapa">' + icon('mapa', 20) + 'Mapa</a>' : '<span class="pl-btn pl-btn--secondary" aria-disabled="true">Sin mapa</span>') + '</div>';
		if (cu.alt) { html += '<div class="pl-muted">Otro teléfono: <a href="tel:+' + esc(cu.alt) + '">' + esc(cu.altLabel) + '</a></div>'; }
		html += '</section>';
		if (!m.after) {
			var small = function (t) { return String(t).length > 11 ? ' pl-amount--sm' : ''; };
			html += '<section class="pl-card"><div class="pl-money"><div><span class="pl-muted">Cobras al cliente</span><span class="pl-amount' + small(m.chargeShort) + '">' + esc(m.chargeShort) + '</span></div><div><span class="pl-muted">Llevas a la tienda</span><span class="pl-amount' + small(m.bring) + '">' + esc(m.bring) + '</span></div>'
				+ '<div class="pl-muted">Tu mensajería: <span class="pl-strong">' + esc(m.feeText) + '</span></div><div class="pl-muted">' + esc(m.deadline) + '</div></div>'
				+ '<div class="pl-muted">' + esc(m.charge) + '</div>'
				+ (m.changeText ? '<div class="pl-muted">Vuelto: la tienda te da <span class="pl-strong">' + esc(m.changeText) + '</span> para el cliente' + (m.payWith ? ' (paga con ' + esc(m.payWith) + ')' : '') + '.</div>' : '')
				+ (m.bringDetail ? '<div class="pl-muted">' + esc(m.bringDetail) + '</div>' : '') + '</section>';
		} else if (m.feeText) {
			html += '<section class="pl-card"><div class="pl-muted">Tu mensajería: <span class="pl-strong">' + esc(m.feeText) + '</span></div></section>';
		}
		html += '</main>';
		// Botones de abajo según el paso (los mismos que la tarjeta de carrera).
		var w = wordFor(c), b = function (a) { return state.busy === a + '-' + c.id ? ' pl-btn--busy' : ''; };
		var acts = '<div class="pl-actions">';
		if (c.stage === 'pickup' && !c.eta) {
			acts += '<a class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block" href="#hora/' + c.id + '">🕒 Poner la hora</a>'
				+ '<div class="pl-grid2"><a class="pl-btn pl-btn--secondary" href="#problema/' + c.id + '">' + icon('aviso', 18) + 'Problema</a><a class="pl-btn pl-btn--secondary" href="#suelto/' + c.id + '">No puedo</a></div>';
		} else if (c.stage === 'pickup') {
			acts += '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + b('recogi') + '" data-act="recogi" data-id="' + c.id + '">' + icon('bien') + 'Recogí</button>'
				+ '<div class="pl-grid3"><button type="button" class="pl-btn pl-btn--secondary" disabled>Entregado</button><a class="pl-btn pl-btn--secondary" href="#problema/' + c.id + '">' + icon('aviso', 18) + 'Problema</a><a class="pl-btn pl-btn--secondary" href="#suelto/' + c.id + '">No puedo</a></div>'
				+ '<div class="pl-hint">¿Sin conexión? Escribe <span class="pl-strong">' + esc(w.recogi) + '</span> por WhatsApp</div>';
		} else if (c.stage === 'route') {
			acts += '<a class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block" href="#entregado/' + c.id + '">' + icon('bien') + 'Entregado</a>'
				+ '<a class="pl-btn pl-btn--secondary pl-btn--block" href="#problema/' + c.id + '">' + icon('aviso', 18) + 'Problema</a>'
				+ '<div class="pl-hint">Ya tienes el paquete: si no puedes entregarlo, toca «Problema». ¿Sin conexión? Escribe <span class="pl-strong">' + esc(w.entregado) + '</span></div>';
		} else if (c.stage === 'delivered') {
			acts += '<a class="pl-btn pl-btn--secondary pl-btn--block" href="#problema/' + c.id + '">' + icon('aviso', 18) + 'Avisar un problema</a><a class="pl-btn pl-btn--link" href="#dinero">Ver mi dinero de hoy</a>';
		} else {
			acts += '<div class="pl-hint">La tienda te llama o te escribe. Si tienes dudas, llama a la tienda.</div>';
		}
		if (['pickup', 'route'].indexOf(c.stage) !== -1 && !c.pase && !c.queued) { acts += '<a class="pl-btn pl-btn--link" href="#pasar/' + c.id + '">🔁 Pasar a otro mensajero</a>'; }
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
		return t ? 'Llevas <span style="font-weight:700">' + esc(t) + '</span> a la tienda antes de las 4:00 p. m. La mensajería es tuya.' : 'No llevas dinero de productos a la tienda. La mensajería es tuya.';
	}

	function sheetEntregado(c) {
		var m = c.money, f = state.form, opts = payOptions(m), k = f.pay || (opts[0] && opts[0].k);
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Cuánto cobraste?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<div class="pl-muted" style="font-size:16px;line-height:24px">Pedido #' + esc(c.number) + ' · ' + esc(c.customer.first || c.customer.name) + ' · ' + esc(c.customer.zone) + '</div>';
		if (m.prepaid) {
			html += '<div class="pl-alert pl-alert--success">' + icon('bien', 20) + '<div><b>Ya estaba pagado</b>No cobras productos' + (m.label ? ' (' + esc(m.label) + ')' : '') + '.</div></div>';
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
						+ '<div class="pl-muted">Solo lo de los productos, sin tu mensajería.</div>';
				}
			});
			html += '</fieldset>';
		}
		html += '<div class="pl-note">' + icon('dinero') + '<div id="pl-bring">' + bringNote(c) + '</div></div>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		html += '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'entregado-' + c.id ? ' pl-btn--busy' : '') + '" data-act="entregado" data-id="' + c.id + '">' + icon('bien') + 'Confirmar entregado</button>'
			+ '<div class="pl-hint">¿Sin conexión? Escribe <span class="pl-strong">' + esc(wordFor(c).entregado) + '</span></div>';
		return html;
	}

	var MOTIVOS = [['no_estaba', 'El cliente no estaba'], ['parte', 'Solo quiso una parte'], ['devuelve', 'Lo devuelve'], ['vale_mal', 'El vale está mal'], ['otro', 'Otro problema']];
	function sheetProblema(c) {
		var f = state.form;
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Qué pasó?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div><div class="pl-muted" style="font-size:16px">Pedido #' + esc(c.number) + ' · la tienda lo ve al momento.</div><fieldset class="pl-fs"><legend>Motivo</legend>';
		MOTIVOS.forEach(function (o) { html += '<label class="pl-opt' + (f.motivo === o[0] ? ' pl-opt--on' : '') + '"><span><input type="radio" name="motivo" value="' + o[0] + '" data-act="motivo"' + (f.motivo === o[0] ? ' checked' : '') + '> ' + o[1] + '</span></label>'; });
		html += '</fieldset><label class="pl-field">Cuéntalo en una frase ' + (f.motivo === 'otro' ? '(hace falta)' : '(si quieres)') + '<textarea data-f="texto" maxlength="400" rows="2">' + esc(f.texto || '') + '</textarea></label>';
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
		return html + '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'hora-' + c.id ? ' pl-btn--busy' : '') + '" data-act="hora" data-id="' + c.id + '">🕒 Guardar la hora</button>'
			+ '<div class="pl-hint">La gestora o el cliente reciben la hora al momento.</div>';
	}

	function sheetPasar(c) {
		var list = (state.hoy && state.hoy.companeros) || [], f = state.form;
		var html = '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿A quién se la pasas?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<p style="margin:0">#' + esc(c.number) + ' sigue siendo tuya hasta que tu compañero la acepte en su Pa\'llá.' + (c.stage === 'route' ? ' Ya tienes el paquete: dáselo en mano.' : '') + '</p>';
		if (!list.length) { return html + '<div class="pl-alert pl-alert--info">' + icon('pregunta', 20) + '<div><b>Todavía no hay compañeros con Pa\'llá</b>Pásala como siempre o toca «No puedo».</div></div>'; }
		html += '<fieldset class="pl-fs"><legend>Compañeros</legend>';
		list.forEach(function (m) { html += '<label class="pl-opt' + (String(f.to) === String(m.id) ? ' pl-opt--on' : '') + '"><span><input type="radio" name="to" value="' + m.id + '" data-act="pasar-a"' + (String(f.to) === String(m.id) ? ' checked' : '') + '> ' + esc(m.name) + '</span></label>'; });
		html += '</fieldset>';
		if (f.err) { html += '<div class="pl-err" role="alert">' + esc(f.err) + '</div>'; }
		return html + '<button type="button" class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block' + (state.busy === 'pasar-' + c.id ? ' pl-btn--busy' : '') + '" data-act="pasar" data-id="' + c.id + '">🔁 Pedir que la acepte</button>';
	}

	function sheetSuelto(c) {
		return '<div class="pl-sheet-head"><h1 class="pl-h1" id="pl-sheet-t">¿Sueltas la #' + esc(c.number) + '?</h1><a class="pl-x" href="#carrera/' + c.id + '" aria-label="Cerrar">' + icon('cerrar') + '</a></div>'
			+ '<p style="margin:0">La carrera vuelve a estar libre para otro mensajero. Solo se puede antes de recoger el paquete.</p>'
			+ '<button type="button" class="pl-btn pl-btn--danger pl-btn--lg pl-btn--block' + (state.busy === 'suelto-' + c.id ? ' pl-btn--busy' : '') + '" data-act="suelto" data-id="' + c.id + '">Sí, la suelto</button><a class="pl-btn pl-btn--secondary pl-btn--block" href="#carrera/' + c.id + '">No, sigo con ella</a>';
	}

	function screenDinero() {
		var d = state.hoy.dinero || { rows: [] };
		var html = '<header class="pl-top"><div><h1 class="pl-h1">Mi dinero de hoy</h1><span class="pl-sub">' + esc(d.label || '') + '</span></div></header>' + offlineBar() + '<main class="pl-main">';
		html += '<div class="pl-tiles"><div class="pl-tile"><span class="pl-muted">Ganaste</span><span class="pl-amount">' + esc(d.earnedText || '0') + '</span><span class="pl-muted">CUP de mensajería</span></div>'
			+ '<div class="pl-tile pl-tile--brand"><span style="font-size:14px">Para la tienda</span><span class="pl-amount' + ((d.pendingText || '').length > 9 ? ' pl-amount--sm' : '') + '">' + esc(d.pendingText || 'Nada') + '</span><span style="font-size:14px">' + esc(d.pendingText ? d.deadline : 'no debes nada') + '</span></div></div>';
		if (d.pendingText) { html += '<div class="pl-alert pl-alert--warning">' + icon('aviso', 20) + '<div><b>Te faltan ' + esc(d.pendingText) + ' por llevar</b>Pásalos por la tienda hoy. La dependienta marca «Dinero recibido» y aquí se pone en cero.</div></div>'; }
		else if (d.delivered) { html += '<div class="pl-alert pl-alert--success">' + icon('bien', 20) + '<div><b>No debes dinero a la tienda</b>Todo lo de hoy ya está entregado.</div></div>'; }
		else { html += '<div class="pl-alert pl-alert--info">' + icon('pedidos', 20) + '<div><b>Todavía no entregaste carreras hoy</b>Cuando entregues, aquí ves tu mensajería y lo que llevas a la tienda.</div></div>'; }
		if (d.rows.length) {
			html += '<div class="pl-list">' + d.rows.map(function (r) {
				return '<div class="pl-li"><div><span class="pl-strong">#' + esc(r.number) + ' · ' + esc(r.zone) + '</span><span class="pl-muted">Mensajería ' + esc(r.feeText) + '</span></div><div class="pl-end"><span class="pl-strong pl-num">' + esc(r.cash) + '</span>' + (r.pending ? badge('warning', 'Por llevar') : badge('success', 'Entregado')) + '</div></div>';
			}).join('') + '</div>';
		}
		html += '<a class="pl-btn pl-btn--secondary pl-btn--block" href="' + esc(C.storeMap || '#') + '" target="_blank" rel="noopener noreferrer">' + icon('tienda', 20) + 'Cómo llegar a la tienda</a>';
		return html + '</main>' + nav('dinero');
	}

	function screenAyuda(id) {
		var offerId = /^oferta-/.test(id) ? id.slice(7) : '';
		var c = offerId ? null : (findCarrera(id) || (state.hoy && state.hoy.carreras[0]) || null);
		var o = offerId ? findOffer(offerId) : null;
		var n = c ? c.number : (o ? o.number : 'NÚMERO');
		var back = c ? '<a class="pl-back" href="#carrera/' + c.id + '">‹ Mi carrera</a><span class="pl-strong">#' + esc(n) + '</span>' : '<a class="pl-back" href="#carreras">‹ Carreras</a>' + (o ? '<span class="pl-strong">#' + esc(n) + '</span>' : '');
		var w = c ? wordFor(c) : { recogi: 'Recogí ' + n, entregado: 'Entregado ' + n + ' 44 usd', problema: 'Problema ' + n + ' no estaba', suelto: 'No puedo ' + n };
		var cmds = [];
		if (o) { cmds.push(['Yo', 'En el grupo de Mensajerías, debajo del anuncio de #' + n]); }
		cmds.push([w.recogi, 'Ya tienes el paquete'], [w.entregado, 'Lo entregaste y cobraste'], [w.problema, 'Algo salió mal'], [w.suelto, 'La suelta para otro']);
		var html = '<header class="pl-top pl-top--bar">' + back + '</header>' + (state.offline ? '<div class="pl-offline" role="status">' + icon('senal') + '<span>' + (o ? 'Sin conexión. No se pudo coger la carrera.' : 'Sin conexión. Tu carrera sigue guardada.') + '</span></div>' : '');
		html += '<main class="pl-main"><h1 class="pl-h1">Escríbelo por WhatsApp</h1><p style="margin:0;color:var(--ink-muted)">Hace lo mismo que los botones. Manda una sola línea al WhatsApp de Casa Viva.</p>';
		if (o) { html += '<div class="pl-alert pl-alert--warning">' + icon('aviso', 20) + '<div><b>«La cojo» necesita conexión</b>Otro mensajero puede cogerla antes. Escribe <b style="display:inline">Yo</b> en el grupo o prueba otra vez cuando tengas señal.</div></div>'; }
		html += '<div class="pl-list">' + cmds.map(function (x, i) {
			return '<div class="pl-li" style="min-height:64px"><div><span class="pl-num" style="font-weight:700">' + esc(x[0]) + '</span><span class="pl-muted">' + esc(x[1]) + '</span></div><button type="button" class="pl-btn pl-btn--link" data-act="copiar" data-text="' + esc(x[0]) + '" aria-label="Copiar ' + esc(x[0]) + '">Copiar</button></div>';
		}).join('') + '</div>';
		var ps = pushState();
		if (ps === 'on') { html += '<div class="pl-note pl-note--info">🔔<div><b>Avisos activados</b> en este teléfono. <button type="button" class="pl-btn pl-btn--link" style="padding:0;min-height:0" data-act="push-off">Desactivar</button></div></div>'; }
		else if (ps === 'off') { html += '<div class="pl-note">🔔<div><b>Avisos apagados.</b> <button type="button" class="pl-btn pl-btn--link" style="padding:0;min-height:0" data-act="push-on">Activar avisos</button></div></div>'; }
		else if (ps === 'denied') { html += '<div class="pl-note">🔕<div><b>Avisos bloqueados en Chrome.</b> Para activarlos: Chrome → ⋮ → Configuración → Notificaciones → casaviva.company → Permitir.</div></div>'; }
		html += '<div class="pl-note pl-note--info">' + icon('sincronizar') + '<div>Cuando vuelva la señal, la app se pone al día sola. No tienes que repetir nada.' + (state.queue.length ? ' Tienes <b style="display:inline">' + state.queue.length + '</b> ' + (state.queue.length === 1 ? 'acción guardada' : 'acciones guardadas') + ' esperando la señal.' : '') + '</div></div>';
		html += '</main><div class="pl-actions"><a class="pl-btn pl-btn--primary pl-btn--lg pl-btn--block" href="https://wa.me/' + esc(C.whatsapp || '') + '" target="_blank" rel="noopener noreferrer">' + icon('mensaje') + 'Abrir WhatsApp</a></div>';
		return html;
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
			html = state.loading ? '<main class="pl-closed"><div class="pl-logo" aria-hidden="true">P</div><p>Cargando Pa\'llá…</p></main>'
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
		app.innerHTML = '<div class="pl-shell">' + (P ? practiceBar() : '') + html + '</div>'
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
		if (a === 'refresh' || a === 'retry') { state.blocked = null; state.loading = true; render(); start(); return; }
		if (a === 'cojo') { claim(el.getAttribute('data-id')); return; }
		if (a === 'recogi' && c) { act(c, 'recogi'); return; }
		if (a === 'push-on') { store.set(PFX + 'push-no', null); pushOn(); return; }
		if (a === 'push-no') { store.set(PFX + 'push-no', '1'); render(); return; }
		if (a === 'push-off') { pushOff(); return; }
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
	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && state.token) { flush().then(load); } });
	setInterval(function () {
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
