/* Simulador de Pa'llá: recorre los casos de error del reparto en el MODO PRÁCTICA (nada real) tocando la app como un
 * mensajero. Abrir: practica.html?practica=1&sim=1 → resultado en pantalla y en window.SIM (ok/falla por caso). */
(function () {
	'use strict';
	if (!/[?&]sim=1\b/.test(location.search)) { return; }
	var P = window.PALLA_PRACTICA;
	var out = [];
	var wait = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
	var $ = function (s) { return document.querySelector(s); };
	var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
	var text = function () { return (document.getElementById('app') || {}).innerText || ''; };
	var toast = function () { var t = $('.pl-toast'); return t ? t.innerText : ''; };
	var world = function () { return JSON.parse(localStorage.getItem('palla.p.mundo')); };
	var order = function (id) { return world().orders.filter(function (o) { return o.id === id; })[0]; };
	var go = async function (h) { location.hash = h; await wait(150); };
	var autoHora = false; // 3.14.86: ya no hay paso de hora
	var ponerHora = async function (valor) {
		var r = $('input[name=hora][value="' + (valor || 'en 1 hora') + '"]'); if (!r) { return false; }
		r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		$('[data-act=hora]').click(); await wait(700); return true;
	};
	var click = async function (sel, ms) {
		var el = typeof sel === 'string' ? $(sel) : sel; if (!el) { throw new Error('no encuentro ' + sel); }
		el.click(); await wait(ms || 600);
		if (autoHora && el.getAttribute('data-act') === 'cojo' && /^#hora\//.test(location.hash)) { await ponerHora(); }
	};
	var refresh = async function () { var b = $('[data-act=refresh]'); window.dispatchEvent(new Event('online')); await wait(900); };
	var check = function (caso, cond, detalle) { out.push({ caso: caso, ok: !!cond, detalle: cond ? '' : detalle || '' }); };
	var setPay = async function (v) { var r = $('input[name=pago][value=' + v + ']'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); await wait(150); };
	var type = async function (sel, v) { var i = $(sel); i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); await wait(100); };
	// 3.14.86: «Entregado» de un toque; se manda al acabar la cuenta atrás (en el simulador, 300 ms).
	var entregar = async function (id) { await go('#carrera/' + id); await click('[data-act=entregar]', 150); await wait(1100); };
	var newCid = function () { return 'sim' + Math.random().toString(36).slice(2); };

	async function run() {
		P.reset(); P.setOnline(true); window.PALLA_UNDO_MS = 300;
		await go('#carreras'); await refresh();

		// 1. Coger con señal → se abre la carrera (3.14.86: sin paso de hora)
		await click('[data-act=cojo][data-id="9001"]', 900);
		var hash1 = location.hash;
		var acciones1 = $$('.pl-actions a, .pl-actions button, .pl-fm-sheet > button, .pl-fm-sheet > div > button, .pl-fm-sheet > a').map(function (x) { return x.innerText; }).join('|');
		check('1. «La cojo» con señal: es mía y se abre la carrera', hash1 === '#carrera/9001' && order(9001).owner === 1, hash1 + ' owner=' + order(9001).owner);
		check('1b. Sin hora: el botón grande ya es «Recogí»', /Recogí/.test(acciones1) && !/hora/i.test(acciones1), acciones1);
		// 2. Doble toque en «La cojo»
		await go('#carreras');
		var b = $('[data-act=cojo][data-id="9002"]'); b.click(); b.click(); await wait(900);
		var cogidas = world().log.filter(function (l) { return /Cogiste #9002/.test(l); }).length;
		check('2. Doble toque en «La cojo» no la coge dos veces', cogidas === 1, 'veces=' + cogidas);
		// 3. Otro la coge mientras la miro
		await go('#carreras'); P.event('nueva'); await refresh();
		var nueva = world().orders[world().orders.length - 1].id;
		P.event('otro', nueva);
		await click('[data-act=cojo][data-id="' + nueva + '"]', 900);
		check('3. Llego tarde: «ya la cogió otro» y desaparece de la lista', /ya la cogió otro/.test(toast()) && !$('[data-act=cojo][data-id="' + nueva + '"]'), toast());
		// 4. «La cojo» sin señal
		P.setOnline(false);
		await click('[data-act=cojo][data-id="9003"]', 900);
		check('4. «La cojo» sin señal: NO se guarda; pantalla de ayuda con «Yo»', /#ayuda\/oferta-9003/.test(location.hash) && order(9003).owner === 0 && /\bYo\b/.test(text()), location.hash + ' dueño=' + order(9003).owner + ' yo=' + /\bYo\b/.test(text()) + ' ' + text().slice(0, 160));
		P.setOnline(true); await refresh();
		// 5. «No puedo» antes de recoger (#54)
		await go('#suelto/9002'); await click('[data-act=suelto]', 900);
		check('5. «No puedo» antes de recoger: vuelve a estar libre', order(9002).owner === 0 && order(9002).stage === 'offered' && location.hash === '#carreras', 'owner=' + order(9002).owner + ' ' + location.hash);
		// 6. Entregado antes de Recogí
		await go('#carrera/9001');
		var ent = $$('.pl-actions button').filter(function (x) { return /Entregado/.test(x.innerText); })[0];
		// 3.14.69.16: ya no hay botón «Entregado» apagado; antes de «Recogí» simplemente no se puede ir a «Entregado».
		check('6. «Entregado» no se puede mientras no tocas «Recogí»', !$('[data-act=entregar]') && !!$('[data-act=recogi]'), 'entregar=' + !!$('[data-act=entregar]'));
		// 7. Recogí y Recogí otra vez
		await click('[data-act=recogi]', 900);
		var r2 = await P.request('carrera', { order: 9001, action: 'recogi', cid: newCid() });
		check('7. «Recogí» dos veces: la 2.ª dice «ya constaba»', order(9001).stage === 'picked' && r2.data.result === 'already_picked', r2.data.result);
		// 8. Soltar después de recoger
		var botones = $$('.pl-actions a, .pl-actions button, .pl-fm-sheet > button, .pl-fm-sheet > div > button, .pl-fm-sheet > a').map(function (x) { return x.innerText; }).join('|');
		var r3 = await P.request('carrera', { order: 9001, action: 'suelto', cid: newCid() });
		check('8. Con el paquete ya no se puede soltar: sin «No puedo» y Core dice too_late', !/No puedo/.test(botones) && ['not_now', 'too_late'].indexOf(r3.data.result) !== -1, botones + ' / ' + r3.data.result);
		// 8b. 3.14.71.2: «Llegué» en camino: avisa a la gestora, se marca y el botón desaparece.
		await go('#carrera/9001'); await click('[data-act=llegue]', 900);
		check('8b. «Llegué» en camino: queda «Llegaste» y ya no sale el botón', !!order(9001).arrived && /Llegaste/.test(text()) && !$('[data-act=llegue]'), text().slice(0, 200));
		// 9. Entregado de un toque con lo del vale (efectivo USD); antes, «Deshacer» lo para
		await go('#carrera/9001');
		var boton9 = ($('[data-act=entregar]') || {}).innerText || '';
		window.PALLA_UNDO_MS = 2000; await click('[data-act=entregar]', 200);
		var hayDeshacer = !!$('[data-act=deshacer]'); await click('[data-act=deshacer]', 300); await wait(2200);
		check('9a. El botón dice «Entregado · cobré 44 USD» y «Deshacer» lo para (sigue en camino)', /cobré 44 USD/.test(boton9) && hayDeshacer && order(9001).stage === 'picked', boton9 + ' / deshacer=' + hayDeshacer + ' / ' + order(9001).stage);
		window.PALLA_UNDO_MS = 300; await entregar(9001);
		check('9b. Entregado: queda en «Mi dinero» con 44 USD por llevar', order(9001).stage === 'delivered' && /44 USD/.test(toast()), toast());
		await go('#dinero'); await wait(300);
		check('9c. «Mi dinero de hoy»: 2.300 CUP ganados y 44 USD para la tienda', /2\.300/.test(text()) && /A la tienda\s*44 USD/.test(text()), text().slice(0, 200));
		// 10. (3.14.86) Ya no hay hoja de cobro: el mensajero cobra lo que dice el vale.
		// 11. Vuelto de la tienda (#56)
		await go('#carreras'); await click('[data-act=cojo][data-id="9002"]', 900);
		await go('#carrera/9002');
		check('11a. Con vuelto: la tarjeta dice que traes productos + 10 USD de vuelto', /10 USD \+ 12\.250 CUP/.test(text()) && /vuelto 10 USD/.test(text()), text().slice(0, 400));
		await click('[data-act=recogi]', 900); await entregar(9002);
		check('11b. Entregado con vuelto: el aviso suma el vuelto (#56)', /Lleva 10 USD \+ 12\.250 CUP/.test(toast()) && /10 USD del vuelto/.test(toast()), toast());
		// 12. Ya pagado (Zelle)
		await go('#carreras'); await click('[data-act=cojo][data-id="9003"]', 900); await click('[data-act=recogi]', 900);
		await go('#carrera/9003');
		check('12a. Ya pagado: la tarjeta dice que no cobras nada y el botón es «Entregado» a secas', /Nada/.test(text()) && !/cobré/.test(($('[data-act=entregar]') || {}).innerText || 'x'), text().slice(0, 300));
		await entregar(9003);
		check('12b. Ya pagado: entregado sin dinero que llevar', order(9003).stage === 'delivered' && /no llevas dinero/i.test(toast()), toast());
		// 13. Vale sin cifra (transferencia sin tasa): avisa en la tarjeta y no deja marcar Entregado (lo resuelve la gestora antes)
		await go('#carreras'); await click('[data-act=cojo][data-id="9004"]', 900); await click('[data-act=recogi]', 900);
		await go('#carrera/9004'); var aviso13 = /no dice cuánto cobrar/.test(text());
		await click('[data-act=entregar]', 300); await wait(700);
		check('13. Vale sin cifra: avisa y no se marca Entregado', aviso13 && order(9004).stage === 'picked' && /no dice cuánto/.test(toast()), aviso13 + ' / ' + order(9004).stage + ' / ' + toast());
		// 14. Sin señal: Recogí + Entregado en cola, en orden, sin repetir
		P.event('nueva'); await refresh(); var n3 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n3 + '"]', 900);
		await go('#carrera/' + n3); var sinCifra = /no dice cuánto cobrar/.test(text());
		P.setOnline(false);
		await go('#carrera/' + n3); await click('[data-act=recogi]', 900);
		var trasRecogi = text();
		if (!sinCifra) { await entregar(n3); }
		check('14a. Sin señal se puede seguir: «Recogí» y «Entregado» guardados', /Guardado/.test(trasRecogi) && order(n3).stage === 'assigned', 'etapa=' + order(n3).stage);
		P.setOnline(true); await wait(3000);
		var logs = world().log.filter(function (l) { return l.indexOf('#' + n3 + ':') !== -1; });
		check('14b. Al volver la señal se mandan solas y en orden (Recogí → Entregado), una vez cada una', sinCifra ? (logs.length === 1 && /Recogí/.test(logs[0])) : (order(n3).stage === 'delivered' && logs.length === 2 && /Recogí/.test(logs[0]) && /Entregado/.test(logs[1])), JSON.stringify(logs) + ' sinCifra=' + sinCifra);
		// 15. Reenvío de la misma acción (respuesta perdida): no se repite
		var cid = newCid();
		var a1 = await P.request('carrera', { order: n3, action: 'problema', motivo: 'parte', cid: cid });
		var a2 = await P.request('carrera', { order: n3, action: 'problema', motivo: 'parte', cid: cid });
		check('15. Mismo envío dos veces (se perdió la respuesta): la 2.ª vez no repite nada', a2.data.replay === true && a1.data.result === a2.data.result && world().log.filter(function (l) { return l.indexOf('#' + n3 + ': Problema') !== -1; }).length === 1, JSON.stringify([a1.data.result, a2.data]));
		// 16. Problema mal rellenado y luego bien (#4: un problema no es «entregado»)
		P.event('nueva'); await refresh(); var n4 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n4 + '"]', 900); await click('[data-act=recogi]', 900);
		await go('#problema/' + n4); await click('[data-act=problema]', 400);
		var sinMotivo = /Elige qué pasó/.test(text());
		var r = $('input[name=motivo][value=otro]'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		await click('[data-act=problema]', 400);
		var sinTexto = /Escribe en una frase/.test(text());
		r = $('input[name=motivo][value=no_estaba]'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		await click('[data-act=problema]', 900);
		var acciones = $$('.pl-actions a, .pl-actions button, .pl-fm-sheet > button, .pl-fm-sheet > div > button, .pl-fm-sheet > a').map(function (x) { return x.innerText; }).join('|');
		check('16. Problema: pide motivo, pide frase si es «Otro», y luego ya no deja marcar Entregado', sinMotivo && sinTexto && order(n4).incident === 'no_estaba' && !/Entregado/.test(acciones), [sinMotivo, sinTexto, order(n4).incident, acciones].join(' / '));
		// 17. La tienda cancela una mía / me la quita (#65, #4223)
		P.event('nueva'); await refresh(); var n5 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n5 + '"]', 900); await go('#carreras');
		P.event('quita'); await refresh(); await wait(300);
		check('17. La tienda me quita una: la app me avisa que ya no es mía', /ya no es tuya/.test(toast()) && !$('a[href="#carrera/' + n5 + '"]'), toast());
		// 18. Recogí sin señal y la tienda la cancela antes de que vuelva
		P.event('nueva'); await refresh(); var n6 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n6 + '"]', 900);
		P.setOnline(false); await go('#carrera/' + n6); await click('[data-act=recogi]', 900);
		P.event('cancela', n6);
		P.setOnline(true); await wait(2500);
		var cola2 = await new Promise(function (r) { var q = indexedDB.open('palla-practica', 1); q.onsuccess = function () { var g = q.result.transaction('cola').objectStore('cola').getAll(); g.onsuccess = function () { r(g.result); }; }; });
		check('18. Acción guardada sin señal de una carrera que cancelaron: al volver avisa y no se queda atascada', cola2.length === 0 && order(n6).stage === 'cancelled' && /ya no se puede|ya no está/.test(toast() + text()), 'cola=' + cola2.length + ' etapa=' + order(n6).stage + ' / ' + toast());
		// 19. Dinero recibido en la tienda
		P.event('recibido'); await go('#dinero'); await refresh(); await wait(300);
		check('19. La dependienta marca «Dinero recibido»: «Mi dinero» se pone en cero', /A la tienda\s*Nada/.test(text()), text().slice(0, 300));
		// 19b. 3.14.69.16 (Pedri): historial con días anteriores y lo ganado en la semana y el mes.
		await click('[data-act=dtab][data-tab=hist]', 900);
		check('19b. Mi dinero → Historial: semana, mes y días anteriores', /Esta semana/.test(text()) && /Este mes/.test(text()) && /Ayer/.test(text()), text().slice(0, 300));
		await click('[data-act=dtab][data-tab=hoy]', 300);
		// 20. Ayuda sin conexión: las palabras de WhatsApp de una carrera mía
		await go('#ayuda/' + n4);
		// 3.14.69.8: solo las 3 que el bot en vivo entiende (sin «Recogí N»: eso se toca en la app y va en la cola).
		check('20. Ayuda: las 3 palabras para WhatsApp con el número de la carrera (sin «Recogí N»)', text().indexOf('Entregado ' + n4) !== -1 && text().indexOf('Problema ' + n4) !== -1 && text().indexOf('No puedo ' + n4) !== -1 && text().indexOf('Recogí ' + n4) === -1, text().slice(0, 400));

		// 21. Pasar a otro mensajero (#54, #65): pide elegir, queda esperando, se puede cancelar
		P.event('nueva'); await refresh(); var n7 = world().orders[world().orders.length - 1].id;
		// 3.14.69.15: carrera nueva con la app abierta → aviso arriba (y bocina si el sonido está activado); al tocarlo se quita.
		var aviso = $('.pl-alarm') ? $('.pl-alarm').innerText : '';
		await go('#carrera/' + n4); await click('.pl-alarm', 400);
		check('20b. Carrera nueva: sale arriba «🛵 Carrera nueva #N» y al tocarlo se quita y lleva a Carreras', /Carrera nueva #\d+/.test(aviso) && !$('.pl-alarm') && location.hash === '#carreras', aviso + ' / ' + location.hash);
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n7 + '"]', 900);
		await go('#pasar/' + n7); await click('[data-act=pasar]', 400);
		var pideA = /Elige a quién/.test(text());
		var ra = $('input[name=to][value="2"]'); ra.checked = true; ra.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		await click('[data-act=pasar]', 900);
		var esperando = /Esperando que Pedri/.test(text()) && !/Pasar a otro mensajero/.test(text()) && order(n7).owner === 1;
		await click('[data-act=pase-cancelar]', 900);
		check('21. Pasar: pide a quién, queda «esperando» (sigue siendo mía) y se puede cancelar', pideA && esperando && !order(n7).pase && /cancelaste el pase/.test(toast()), [pideA, esperando, JSON.stringify(order(n7).pase), toast()].join(' / '));
		// 22. El compañero acepta: la app me avisa y ya no es mía
		await go('#pasar/' + n7); ra = $('input[name=to][value="2"]'); ra.checked = true; ra.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		await click('[data-act=pasar]', 900); await go('#carreras');
		P.event('acepta'); await refresh(); await wait(300);
		check('22. Mi compañero acepta el pase: «Pedri aceptó #N, ya no es tuya»', order(n7).owner === 2 && /aceptó #' + n7 + '/.test(toast()) || new RegExp('aceptó #' + n7).test(toast()), toast());
		// 23. Me pasan una y la acepto → primero la hora
		P.event('mepasan'); await refresh(); var n8 = world().orders[world().orders.length - 1].id;
		var veo = /te pasa #/.test(text());
		autoHora = false; await click('[data-act=pase-si][data-id="' + n8 + '"]', 900); autoHora = true;
		check('23. Me pasan una: la veo arriba, «La acepto» → es mía y se abre la carrera', veo && order(n8).owner === 1 && location.hash === '#carrera/' + n8, [veo, order(n8).owner, location.hash].join(' / '));
		// 24. Me pasan otra y digo que no
		P.event('mepasan'); await refresh(); var n9 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=pase-no][data-id="' + n9 + '"]', 900);
		check('24. Me pasan otra y digo «No puedo»: sigue siendo del compañero y desaparece', order(n9).owner === 2 && !order(n9).pase && !$('[data-act=pase-si][data-id="' + n9 + '"]'), JSON.stringify(order(n9)));
		// 25. Pasar sin señal: no se guarda (lo decide otra persona)
		P.setOnline(false);
		await go('#pasar/' + n8); ra = $('input[name=to][value="3"]'); ra.checked = true; ra.dispatchEvent(new Event('change', { bubbles: true })); await wait(150);
		await click('[data-act=pasar]', 900);
		check('25. Pasar sin señal: avisa que hace falta señal y no cambia nada', /necesitas señal/.test(toast()) && !order(n8).pase, toast());
		P.setOnline(true); await refresh();

		var ok = out.filter(function (x) { return x.ok; }).length;
		window.SIM = { ok: ok, total: out.length, casos: out };
		var box = document.createElement('pre');
		box.id = 'sim';
		box.style.cssText = 'position:fixed;inset:auto 0 0 0;max-height:45vh;overflow:auto;background:#121212;color:#fff;font:12px/1.4 monospace;padding:8px;margin:0;z-index:99';
		box.textContent = 'SIMULACIÓN: ' + ok + '/' + out.length + ' bien\n' + out.map(function (x) { return (x.ok ? '✔ ' : '✘ ') + x.caso + (x.ok ? '' : '  → ' + x.detalle); }).join('\n');
		document.body.appendChild(box);
	}
	window.addEventListener('load', function () { setTimeout(function () { run().catch(function (e) { window.SIM = { error: String(e && e.stack || e), casos: out }; }); }, 800); });
})();
