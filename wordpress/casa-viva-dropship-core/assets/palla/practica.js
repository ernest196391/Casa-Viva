/* Pa'llá 3.14.64.1 · MODO PRÁCTICA (/palla/?practica=1). Nada es real: ni pedidos, ni dinero, ni mensajes.
 * Un «servidor» de mentira con las MISMAS reglas que Core (CVD_Tarjeta_Carrera::stage/allowed/submit,
 * CVD_Bot_Bridge::claim_by_messenger, CVD_Palla_Api) para entrenar a mensajeros y probar la app sin tocar pedidos.
 * La barra de arriba simula lo que pasa fuera: perder la señal, otro mensajero que coge, la tienda que cancela o quita
 * la carrera, la dependienta que marca «Dinero recibido». Se guarda en este teléfono (localStorage palla.p.*). */
(function () {
	'use strict';
	var KEY = 'palla.p.mundo';
	var PROBLEMS = { no_estaba: 'El cliente no estaba', parte: 'Solo quiso una parte', devuelve: 'Lo devuelve', vale_mal: 'El vale está mal', otro: 'Otro problema' };
	var STORE = 'Casa Viva · Conill A esq. 45 #864';

	function money(a, cur) {
		var dec = cur === 'CUP' || Math.abs(a - Math.round(a)) < 0.005 ? 0 : 2;
		var p = (+a).toFixed(dec).split('.');
		return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (dec ? ',' + p[1] : '') + ' ' + cur;
	}
	function moneyText(by) { var o = []; ['USD', 'CUP', 'EUR'].forEach(function (c) { if (by && by[c] > 0) { o.push(money(by[c], c)); } }); return o.join(' + '); }
	function add(a, b) { var o = {}; [a, b].forEach(function (x) { Object.keys(x || {}).forEach(function (k) { o[k] = Math.round(((o[k] || 0) + (+x[k] || 0)) * 100) / 100; }); }); return o; }
	function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0; }
	function hour() { return new Date().getHours(); }
	function deadline(h) { return h >= 16 ? 'mañana antes de las 4:00 p. m. (ya pasaron las 4:00 p. m.)' : 'antes de las 4:00 p. m.'; }

	// Pagos como CVD_Cuban_Checkout (kind, currency, amount, prepaid). amount null = CUP sin tasa (el mensajero escribe la cifra).
	var PAY = {
		cash_usd: { label: 'Efectivo USD', currency: 'USD' },
		cash_cup: { label: 'Efectivo CUP', currency: 'CUP' },
		transfer_cup: { label: 'Transferencia CUP', currency: 'CUP', transfer: true },
		zelle: { label: 'Zelle', currency: 'USD', prepaid: true }
	};
	function fresh() {
		var o = function (id, when, zone, items, fee, kind, amount, change, cust) {
			return { id: id, when: when, zone: zone, items: items, fee: fee, kind: kind, amount: amount, change: change || {}, cust: cust, owner: 0, stage: 'offered', at: '', collected: null, transfer: false, received: false, incident: '' };
		};
		return {
			v: 1, online: true, me: 1, log: [], cids: {},
			orders: [
				o(9001, 'hoy · por la tarde', 'Siboney, Playa', ['4× Cojín decorativo — 11 USD c/u = 44 USD'], 2300, 'cash_usd', 44, {}, { name: 'Dasiel (práctica)', address: 'Calle 15 e/ 180 y 182', ref: 'frente a la escuela', phone: '5350000001' }),
				o(9002, 'hoy · lo antes posible', 'El Vedado, Plaza', ['1× Juego de sábanas — 25 USD', '2× Funda de almohada — 5 USD c/u = 10 USD'], 1500, 'cash_cup', 12250, { USD: 10 }, { name: 'Mairelis (práctica)', address: 'Calle 23 #1054 e/ 12 y 14', ref: 'edificio azul, 3er piso', phone: '5350000002' }),
				o(9003, 'mañana · por la mañana', 'Lawton, 10 de Octubre', ['1× Base de nevera — 20 USD'], 2000, 'zelle', 20, {}, { name: 'Yordanka (práctica)', address: 'Calle D #312 e/ 6 y 7', ref: '', phone: '5350000003' }),
				o(9004, 'hoy · por la tarde', 'Alamar, Habana del Este', ['1× Cortina blackout — 18 USD'], 3000, 'transfer_cup', null, {}, { name: 'Osmany (práctica)', address: 'Zona 9, edificio 42, apto 6', ref: 'cerca del policlínico', phone: '5350000004' })
			]
		};
	}
	var W;
	function load() { try { W = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { W = null; } if (!W || W.v !== 1) { W = fresh(); save(); } }
	function save() { try { localStorage.setItem(KEY, JSON.stringify(W)); } catch (e) {} }
	function find(id) { for (var i = 0; i < W.orders.length; i++) { if (String(W.orders[i].id) === String(id)) { return W.orders[i]; } } return null; }

	// ----- Reglas de Core -----
	function stage(o) { // CVD_Tarjeta_Carrera::stage para el mensajero de la práctica
		if (o.stage === 'cancelled') { return 'cancelled'; }
		if (o.owner !== W.me) { return 'other'; }
		if (o.incident) { return 'incident'; }
		return { assigned: 'pickup', picked: 'route', delivered: 'delivered' }[o.stage] || 'other';
	}
	function allowed(st, action) { return ({ recogi: ['pickup'], entregado: ['pickup', 'route'], problema: ['pickup', 'route', 'delivered'], suelto: ['pickup'] }[action] || []).indexOf(st) !== -1; }
	function defaults(o) { // CVD_Messenger_Delivered_Link::default_amounts
		var p = PAY[o.kind];
		if (p.prepaid) { return { usd: 0, cup: 0, eur: 0, method: 'transfer', prepaid: true }; }
		var a = { usd: 0, cup: 0, eur: 0, method: p.transfer ? 'transfer' : '', prepaid: false };
		if (p.currency === 'CUP') { a.cup = o.amount || 0; } else { a.usd = o.amount || 0; }
		return a;
	}
	function productsOf(o) { var a = defaults(o); var by = {}; if (a.usd) { by.USD = a.usd; } if (a.cup) { by.CUP = a.cup; } return by; }
	function moneyBefore(o) {
		var p = PAY[o.kind], a = defaults(o), products = productsOf(o), unknown = !a.prepaid && !moneyText(products);
		var transfer = !a.prepaid && a.method === 'transfer';
		var bring = add(a.prepaid || transfer ? {} : products, o.change);
		var pt = moneyText(products), bt = moneyText(bring);
		var charge = a.prepaid ? 'Nada de productos: ya pagó por ' + p.label + '.' : unknown ? p.label + ' · 18 USD en CUP a la tasa del día — pregunta la tasa en la tienda.' : pt + (transfer ? ' por transferencia a la tarjeta de Casa Viva (pide la captura)' : ' en efectivo');
		if (unknown) { bt = 'Lo que cobres' + (moneyText(o.change) ? ' + ' + moneyText(o.change) + ' del vuelto' : ''); }
		return {
			after: false, prepaid: a.prepaid, unknown: unknown, transfer: transfer, kind: o.kind, label: p.label, currency: p.currency,
			products: products, productsText: pt, change: o.change, changeText: moneyText(o.change), payWith: o.change.USD ? '50 USD' : '',
			charge: charge, chargeShort: a.prepaid ? 'Nada' : unknown ? 'Pregunta' : pt, bring: bt || 'Nada',
			bringDetail: moneyText(o.change) && !unknown ? (!transfer && !a.prepaid ? pt + ' de productos + ' : '') + moneyText(o.change) + ' del vuelto que te dio la tienda' : '',
			deadline: bt ? 'Antes de las 4:00 p. m.' : 'La mensajería es tuya.', fee: o.fee, feeText: money(o.fee, 'CUP')
		};
	}
	function bringAfter(o) { return add(o.transfer || defaults(o).prepaid ? {} : o.collected || {}, o.change); }
	function sentence(o) {
		var b = moneyText(bringAfter(o));
		if (!b) { return defaults(o).prepaid ? 'Los productos ya estaban pagados: no llevas dinero de productos a la tienda.' : 'No llevas dinero de productos a la tienda.'; }
		var detail = moneyText(o.change) ? ' (' + (moneyText(o.transfer ? {} : o.collected) ? moneyText(o.collected) + ' de productos + ' : '') + moneyText(o.change) + ' del vuelto que te dio la tienda)' : '';
		return 'Lleva ' + b + ' a la tienda' + detail + ' ' + deadline(o.atHour || hour()) + '.';
	}
	function card(o) {
		var st = stage(o);
		return {
			id: o.id, number: String(o.id), stage: st, test: false, when: o.when, eta: o.eta || '', pase: o.pase && o.pase.from === W.me ? { to: o.pase.to, toName: compaName(o.pase.to) } : null,
			customer: { name: o.cust.name, first: o.cust.name.split(' ')[0], address: o.cust.address, zone: o.zone, reference: o.cust.ref, note: '', phone: o.cust.phone, phoneLabel: '+53 ' + o.cust.phone.slice(2, 6) + ' ' + o.cust.phone.slice(6), alt: '', altLabel: '' },
			map: 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(o.cust.address + ', ' + o.zone + ', La Habana'), items: o.items, pickup: { place: STORE, zone: 'Nuevo Vedado', hours: 'de 10:00 a. m. a 4:00 p. m.', suppliers: [] }, assembly: '',
			money: st === 'delivered' ? { after: true, prepaid: defaults(o).prepaid, bring: moneyText(bringAfter(o)) || 'Nada', sentence: sentence(o), deadline: deadline(o.atHour || hour()), fee: o.fee, feeText: money(o.fee, 'CUP') } : moneyBefore(o)
		};
	}
	function offer(o) {
		var a = defaults(o);
		return { id: o.id, number: String(o.id), when: o.when, zone: o.zone, from: 'Casa Viva · Nuevo Vedado', items: o.items, fee: o.fee, feeText: money(o.fee, 'CUP'), collect: a.prepaid ? 'Nada, ya pagó' : (moneyText(productsOf(o)) || 'Lo que diga la tienda'), change: moneyText(o.change), assembly: '', supplier: false };
	}
	function hoy() {
		var rows = [], earned = 0, pending = {};
		W.orders.forEach(function (o) {
			if (o.owner !== W.me || o.stage !== 'delivered') { return; }
			var b = bringAfter(o), owes = !!moneyText(b) && !o.received;
			earned += o.fee; if (owes) { pending = add(pending, b); }
			rows.push({ id: o.id, number: String(o.id), zone: o.zone.split(',')[0], feeText: money(o.fee, 'CUP'), cash: moneyText(b) || 'Nada que llevar', pending: owes });
		});
		var d = new Date(), days = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'], months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
		return {
			ok: true, now: d.toISOString(), practica: true,
			yo: { id: W.me, name: 'Mensajero de práctica', first: (window.PALLA_PRACTICA_NOMBRE || 'tú'), vehicle: 'Moto', available: true },
			ofertas: W.orders.filter(function (o) { return o.stage === 'offered' && !o.owner; }).map(offer),
			pases: pasesParaMi(), companeros: COMPAS,
			carreras: W.orders.filter(function (o) { return ['pickup', 'route', 'incident'].indexOf(stage(o)) !== -1; }).map(card),
			dinero: { label: days[d.getDay()] + ' ' + d.getDate() + ' ' + months[d.getMonth()] + ' · ' + rows.length + (rows.length === 1 ? ' carrera entregada' : ' carreras entregadas'), delivered: rows.length, earned: earned, earnedText: String(earned).replace(/\B(?=(\d{3})+(?!\d))/g, '.'), pending: pending, pendingText: moneyText(pending), deadline: deadline(hour()), rows: rows }
		};
	}
	var MSG = {
		won: function (n) { return '✅ #' + n + ' es tuya. Ve a recogerla a la tienda.'; },
		yours: function (n) { return '#' + n + ' ya era tuya.'; },
		taken: function (n) { return '#' + n + ' ya la cogió otro.'; },
		picked: function (n) { return '✅ #' + n + ': anotado, ya tienes el paquete. Ahora llévalo al cliente.'; },
		already_picked: function (n) { return '#' + n + ': ya constaba que tienes el paquete.'; },
		delivered: function (n) { return '✅ ¡#' + n + ' entregado! La mensajería es tuya.'; },
		already: function (n) { return '#' + n + ' ya constaba como entregado.'; },
		needs_amount: function (n) { return '#' + n + ': escribe cuánto cobraste de los productos (USD o CUP).'; },
		problem: function (n) { return '⚠️ #' + n + ': avisado. La tienda ya lo sabe y te llama o te escribe. No lo marques entregado hasta que te digan.'; },
		problem_already: function (n) { return '#' + n + ': ya había un problema avisado. La tienda lo está viendo.'; },
		problem_empty: function () { return 'Elige qué pasó (y si es «Otro problema», escríbelo en una frase).'; },
		released: function (n) { return 'Soltaste #' + n + '. Vuelve a estar libre para otro mensajero. ¡Gracias por avisar!'; },
		too_late: function (n) { return '#' + n + ': ya tienes el paquete, no se puede soltar. Si no puedes entregarlo, toca «Problema».'; },
		not_now: function (n) { return '#' + n + ': eso ya no se puede en este paso del pedido.'; },
		not_found: function () { return 'No encuentro ese pedido.'; }
	};
	function note(t) { W.log.push(new Date().toTimeString().slice(0, 5) + ' ' + t); W.log = W.log.slice(-40); }

	function claim(b) {
		var o = find(b.order), n = String(b.order);
		if (!o) { return { result: 'not_found', message: MSG.not_found() }; }
		if (o.owner === W.me && o.stage !== 'offered') { return { result: 'yours', number: n, message: MSG.yours(n), carrera: card(o) }; }
		if (o.owner || o.stage !== 'offered') { return { result: 'taken', number: n, message: MSG.taken(n) }; }
		o.owner = W.me; o.stage = 'assigned'; note('Cogiste #' + n + ' (en el grupo saldría «✅ #' + n + ' es tuya» y el vale por privado).');
		return { result: 'won', number: n, message: MSG.won(n), carrera: card(o) };
	}
	function action(b) {
		var o = find(b.order), n = String(b.order), act = b.action;
		if (!o) { return { result: 'not_found', message: MSG.not_found() }; }
		var st = stage(o), code;
		if (act === 'entregado' && st === 'delivered') { code = 'already'; }
		else if (act === 'recogi' && st === 'route') { code = 'already_picked'; }
		else if (act === 'problema' && st === 'incident') { code = 'problem_already'; }
		else if (!allowed(st, act)) { code = 'not_now'; }
		else if (act === 'recogi') { o.stage = 'picked'; code = 'picked'; note('#' + n + ': Recogí (la dependienta lo vería en Pedidos).'); }
		else if (act === 'entregado') {
			var a = defaults(o), transfer = a.method === 'transfer';
			if (b.otra && !a.prepaid) { a.usd = num(b.usd); a.cup = num(b.cup); a.eur = num(b.eur); transfer = b.como === 'transferencia'; }
			if (!a.prepaid && a.usd + a.cup + a.eur <= 0) { code = 'needs_amount'; }
			else {
				o.collected = {}; if (a.usd) { o.collected.USD = a.usd; } if (a.cup) { o.collected.CUP = a.cup; } if (a.eur) { o.collected.EUR = a.eur; }
				o.transfer = transfer; o.stage = 'delivered'; o.atHour = hour(); code = 'delivered';
				note('#' + n + ': Entregado (' + (moneyText(o.collected) || 'ya pagado') + (transfer ? ', transferencia' : '') + ').');
			}
		}
		else if (act === 'problema') {
			if (!PROBLEMS[b.motivo] || (b.motivo === 'otro' && String(b.texto || '').trim().length < 3)) { code = 'problem_empty'; }
			else { o.incident = b.motivo; code = 'problem'; note('#' + n + ': Problema «' + PROBLEMS[b.motivo] + '» (la tienda recibiría el aviso).'); }
		}
		else if (act === 'suelto') { o.owner = 0; o.stage = 'offered'; code = 'released'; note('Soltaste #' + n + ' (el bot la vuelve a anunciar en el grupo).'); }
		var out = { result: code, action: act, number: n, message: MSG[code](n) };
		if (code === 'delivered' || code === 'already') { out.message += ' ' + sentence(o); }
		var s2 = stage(o); out.stage = s2;
		if (['pickup', 'route', 'incident', 'delivered'].indexOf(s2) !== -1) { out.carrera = card(o); }
		return out;
	}

	function hora(b) { // CVD_Palla_Api::eta
		var o = find(b.order), n = String(b.order), t = String(b.texto || '').trim().slice(0, 80);
		if (!o) { return { result: 'not_found', message: MSG.not_found() }; }
		if (['pickup', 'route', 'incident'].indexOf(stage(o)) === -1) { return { result: 'not_now', number: n, message: MSG.not_now(n) }; }
		if (t.length < 2) { return { result: 'eta_empty', number: n, message: 'Elige a qué hora lo entregas.' }; }
		o.eta = t; note('#' + n + ': hora «' + t + '» (la gestora o el cliente la recibirían).');
		return { result: 'eta', number: n, message: '🕒 #' + n + ': anotado, lo entregas ' + t + '. Se lo decimos a quien lo espera.', carrera: card(o) };
	}

	var COMPAS = [{ id: 2, name: 'Pedri (práctica)' }, { id: 3, name: 'Ana (práctica)' }];
	function compaName(id) { var c = COMPAS.filter(function (x) { return x.id === id; })[0]; return c ? c.name : 'tu compañero'; }
	function pasar(b) { // CVD_Palla_Api::pass_request
		var o = find(b.order), n = String(b.order), to = parseInt(b.to, 10);
		if (!o) { return { result: 'not_found', message: MSG.not_found() }; }
		if (['pickup', 'route'].indexOf(stage(o)) === -1) { return { result: 'not_now', number: n, message: '#' + n + ': ahora no se puede pasar (si hay un problema avisado, la tienda decide).' }; }
		if (!COMPAS.some(function (x) { return x.id === to; })) { return { result: 'bad_to', number: n, message: 'Elige a un compañero que use Pa\'llá.' }; }
		o.pase = { from: W.me, to: to }; note('#' + n + ': le pides a ' + compaName(to) + ' que la acepte (el bot le escribiría con su enlace).');
		return { result: 'pass_sent', number: n, message: '🔁 #' + n + ': le pedimos a ' + compaName(to).split(' ')[0] + ' que la acepte. Sigue siendo tuya hasta que diga que sí.', carrera: card(o) };
	}
	function pase(b) { // CVD_Palla_Api::pass_answer
		var o = find(b.order), n = String(b.order), d = b.decision;
		if (!o || !o.pase) { return { result: 'gone', number: n, message: '#' + n + ': ese pase ya no está (la soltó, la entregó o lo canceló).' }; }
		if (d === 'cancelar' && o.pase.from === W.me) { o.pase = null; note('#' + n + ': cancelaste el pase.'); return { result: 'pass_cancelled', number: n, message: '#' + n + ': cancelaste el pase. Sigue siendo tuya.', carrera: card(o) }; }
		if (o.pase.to !== W.me) { return { result: 'gone', number: n, message: '#' + n + ': ese pase ya no está.' }; }
		if (d === 'no') { o.pase = null; note('#' + n + ': no aceptaste el pase.'); return { result: 'pass_declined', number: n, message: 'Listo, no aceptaste #' + n + '. Se lo decimos a tu compañero.' }; }
		if (o.incident) { o.pase = null; return { result: 'not_now', number: n, message: '#' + n + ': ya no se puede pasar.' }; }
		o.owner = W.me; o.eta = ''; o.pase = null; note('#' + n + ': aceptaste el pase (el bot te mandaría el vale y avisaría a la gestora).');
		return { result: 'pass_accepted', number: n, message: '✅ #' + n + ' ahora es tuya. Pon a qué hora la entregas.', carrera: card(o) };
	}
	function pasesParaMi() {
		return W.orders.filter(function (o) { return o.pase && o.pase.to === W.me && o.owner === o.pase.from; }).map(function (o) {
			return { id: o.id, number: String(o.id), from: compaName(o.owner), zone: o.zone, picked: o.stage === 'picked', items: o.items, feeText: money(o.fee, 'CUP'), when: o.when };
		});
	}

	// ----- Lo que pasa fuera de la app (la barra de práctica) -----
	var EVENTS = {
		otro: function (id) { var o = id ? find(id) : W.orders.filter(function (x) { return x.stage === 'offered' && !x.owner; })[0]; if (!o) { return 'No hay carreras libres para que las coja otro.'; } o.owner = 2; o.stage = 'assigned'; note('Otro mensajero cogió #' + o.id + '.'); return 'Otro mensajero cogió #' + o.id + ' (si la tenías en pantalla, verás «ya la cogió otro»).'; },
		cancela: function (id) { var o = id ? find(id) : W.orders.filter(function (x) { return x.owner === W.me && ['assigned', 'picked'].indexOf(x.stage) !== -1; })[0]; if (!o) { return 'No tienes carreras en curso para cancelar.'; } o.stage = 'cancelled'; note('La tienda canceló #' + o.id + '.'); return 'La tienda canceló #' + o.id + '.'; },
		quita: function () { var o = W.orders.filter(function (x) { return x.owner === W.me && x.stage === 'assigned'; })[0]; if (!o) { return 'No tienes carreras por recoger que la tienda te pueda quitar.'; } o.owner = 3; note('La tienda pasó #' + o.id + ' a otro mensajero.'); return 'La tienda pasó #' + o.id + ' a otro mensajero.'; },
		recibido: function () { var c = 0; W.orders.forEach(function (o) { if (o.owner === W.me && o.stage === 'delivered' && !o.received) { o.received = true; c++; } }); note('La dependienta marcó «Dinero recibido» (' + c + ').'); return c ? 'La dependienta marcó «Dinero recibido» en ' + c + (c === 1 ? ' carrera.' : ' carreras.') : 'No había dinero por recibir.'; },
		acepta: function () { var o = W.orders.filter(function (x) { return x.pase && x.pase.from === W.me; })[0]; if (!o) { return 'No tienes ningún pase esperando.'; } o.owner = o.pase.to; var who = compaName(o.pase.to); o.pase = null; o.eta = ''; note(who + ' aceptó #' + o.id + '.'); return who + ' aceptó #' + o.id + ': ya no es tuya.'; },
		mepasan: function () { var id = 9000 + W.orders.length + 1; W.orders.push({ id: id, when: 'hoy · por la tarde', zone: 'Cerro', items: ['2× Toalla de baño — 9 USD c/u = 18 USD'], fee: 1800, kind: 'cash_usd', amount: 18, change: {}, cust: { name: 'Yanelis (práctica)', address: 'Calzada del Cerro #1820', ref: '', phone: '5350000010' }, owner: 2, stage: 'assigned', at: '', collected: null, transfer: false, received: false, incident: '', pase: { from: 2, to: W.me } }); note('Pedri te quiere pasar #' + id + '.'); return 'Pedri (práctica) te quiere pasar #' + id + '. Mira arriba en «Carreras».'; },
		nueva: function () { var id = 9000 + W.orders.length + 1; W.orders.push({ id: id, when: 'hoy · lo antes posible', zone: 'Centro Habana', items: ['1× Lámpara de mesa — 15 USD'], fee: 1200, kind: 'cash_usd', amount: 15, change: {}, cust: { name: 'Lisandra (práctica)', address: 'San Lázaro #410', ref: '', phone: '5350000009' }, owner: 0, stage: 'offered', at: '', collected: null, transfer: false, received: false, incident: '' }); note('Salió una carrera nueva #' + id + '.'); return 'Salió una carrera nueva: #' + id + '.'; }
	};

	load();
	window.PALLA_PRACTICA = {
		get online() { return W.online; },
		setOnline: function (v) { W.online = !!v; save(); if (v) { window.dispatchEvent(new Event('online')); } },
		event: function (k, id) { load(); var t = EVENTS[k] ? EVENTS[k](id) : ''; save(); return t; },
		reset: function () { W = fresh(); save(); try { Object.keys(localStorage).forEach(function (k) { if (k.indexOf('palla.p.') === 0) { localStorage.removeItem(k); } }); } catch (e) {} save(); },
		log: function () { return W.log.slice(); },
		/** Lo mismo que fetch() a la API de Pa'llá → { status, offline, data }. Sin «señal», falla como sin red. */
		request: function (path, body) {
			return new Promise(function (resolve, reject) {
				setTimeout(function () {
					load();
					if (!W.online) { reject(new TypeError('Sin señal (práctica)')); return; }
					var key = body && body.cid ? String(body.cid) : '';
					if (key && W.cids[key]) { resolve({ status: 200, offline: false, data: Object.assign({ replay: true }, W.cids[key]) }); return; }
					var data = path === 'hoy' ? hoy() : path === 'cojo' ? claim(body || {}) : path === 'carrera' ? action(body || {}) : path === 'hora' ? hora(body || {}) : path === 'pasar' ? pasar(body || {}) : path === 'pase' ? pase(body || {}) : { code: 'rest_no_route' };
					if (key) { W.cids[key] = data; }
					save();
					resolve({ status: data.code ? 404 : 200, offline: false, data: data });
				}, 250);
			});
		}
	};
})();
