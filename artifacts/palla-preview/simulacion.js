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
	var autoHora = true; // tras «La cojo» sale la hoja de la hora: el simulador elige «en 1 hora» (salvo donde se prueba eso)
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
	var newCid = function () { return 'sim' + Math.random().toString(36).slice(2); };

	async function run() {
		P.reset(); P.setOnline(true);
		await go('#carreras'); await refresh();

		// 1. Coger con señal → primero la hora (obligatoria)
		autoHora = false;
		await click('[data-act=cojo][data-id="9001"]', 900);
		var hashHora = location.hash;
		await go('#carrera/9001');
		var sinHora = $$('.pl-actions a, .pl-actions button').map(function (x) { return x.innerText; }).join('|');
		check('1. «La cojo» con señal: es mía y se abre «¿A qué hora lo entregas?»', hashHora === '#hora/9001' && order(9001).owner === 1, hashHora + ' owner=' + order(9001).owner);
		check('1b. Sin hora no sale «Recogí»: sale «Poner la hora»', /Poner la hora/.test(sinHora) && !/Recogí/.test(sinHora), sinHora);
		await go('#hora/9001'); await click('[data-act=hora]', 400);
		var pideHora = /Elige a qué hora/.test(text());
		await ponerHora('otra'); // «Otra hora» sin escribir la hora → la pide
		var pideHH = /Pon la hora/.test(text());
		await type('[data-f=hhmm]', '15:30'); await click('[data-act=hora]', 700);
		check('1c. La hora: pide elegir, «Otra hora» pide la hora, y guarda «hoy a las 3:30 p. m.»', pideHora && pideHH && order(9001).eta === 'hoy a las 3:30 p. m.' && /Lo entregas/.test(text()), [pideHora, pideHH, order(9001).eta].join(' / '));
		autoHora = true;
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
		check('6. «Entregado» bloqueado mientras no tocas «Recogí»', ent && ent.disabled, 'botón=' + (ent && ent.outerHTML.slice(0, 80)));
		// 7. Recogí y Recogí otra vez
		await click('[data-act=recogi]', 900);
		var r2 = await P.request('carrera', { order: 9001, action: 'recogi', cid: newCid() });
		check('7. «Recogí» dos veces: la 2.ª dice «ya constaba»', order(9001).stage === 'picked' && r2.data.result === 'already_picked', r2.data.result);
		// 8. Soltar después de recoger
		var botones = $$('.pl-actions a, .pl-actions button').map(function (x) { return x.innerText; }).join('|');
		var r3 = await P.request('carrera', { order: 9001, action: 'suelto', cid: newCid() });
		check('8. Con el paquete ya no se puede soltar: sin «No puedo» y Core dice too_late', !/No puedo/.test(botones) && ['not_now', 'too_late'].indexOf(r3.data.result) !== -1, botones + ' / ' + r3.data.result);
		// 9. Entregado con lo del vale (efectivo USD)
		await go('#entregado/9001');
		check('9a. Hoja «¿Cuánto cobraste?» dice que llevas 44 USD', /Llevas 44 USD/.test($('#pl-bring').innerText), $('#pl-bring').innerText);
		await click('[data-act=entregado]', 900);
		check('9b. Entregado: queda en «Mi dinero» con 44 USD por llevar', order(9001).stage === 'delivered' && /44 USD/.test(toast()), toast());
		await go('#dinero'); await wait(300);
		check('9c. «Mi dinero de hoy»: 2.300 CUP ganados y 44 USD para la tienda', /2\.300/.test(text()) && /Te faltan 44 USD/.test(text()), text().slice(0, 200));
		// 10. Otra moneda sin escribir la cantidad
		P.event('nueva'); await refresh(); var n2 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n2 + '"]', 900); await click('[data-act=recogi]', 900);
		await go('#entregado/' + n2); await setPay('cup'); await click('[data-act=entregado]', 500);
		check('10. Efectivo CUP sin escribir cuánto: no se manda y lo pide', order(n2).stage === 'picked' && /Escribe cuánto/.test(text()), text().slice(-200));
		await type('[data-f=cup]', '3000'); await click('[data-act=entregado]', 900);
		check('10b. Con la cifra escrita: entregado con 3.000 CUP', order(n2).stage === 'delivered' && order(n2).collected.CUP === 3000, JSON.stringify(order(n2).collected));
		// 11. Vuelto de la tienda (#56)
		await go('#carreras'); await click('[data-act=cojo][data-id="9002"]', 900);
		await go('#carrera/9002');
		check('11a. Con vuelto: la tarjeta dice que traes productos + 10 USD de vuelto', /10 USD \+ 12\.250 CUP/.test(text()) && /10 USD del vuelto/.test(text()), text().slice(0, 400));
		await click('[data-act=recogi]', 900); await go('#entregado/9002'); await click('[data-act=entregado]', 900);
		check('11b. Entregado con vuelto: el aviso suma el vuelto (#56)', /Lleva 10 USD \+ 12\.250 CUP/.test(toast()) && /10 USD del vuelto/.test(toast()), toast());
		// 12. Ya pagado (Zelle)
		await go('#carreras'); await click('[data-act=cojo][data-id="9003"]', 900); await click('[data-act=recogi]', 900);
		await go('#entregado/9003');
		check('12a. Ya pagado: la hoja dice que no cobras nada', /Ya estaba pagado/.test(text()) && /No llevas dinero/.test($('#pl-bring').innerText), $('#pl-bring').innerText);
		await click('[data-act=entregado]', 900);
		check('12b. Ya pagado: entregado sin dinero que llevar', order(9003).stage === 'delivered' && /no llevas dinero/i.test(toast()), toast());
		// 13. Transferencia CUP sin tasa (hay que escribir la cifra)
		await go('#carreras'); await click('[data-act=cojo][data-id="9004"]', 900); await click('[data-act=recogi]', 900);
		await go('#entregado/9004');
		var opciones = $$('input[name=pago]').map(function (x) { return x.value; }).join(',');
		var primera = ($('input[name=pago]:checked') || {}).value;
		var pide = !!$('[data-f=cup]');
		await type('[data-f=cup]', '9000'); await wait(100);
		check('13a. Transferencia sin tasa: la 1.ª opción ya es «Transferencia CUP» con el campo de CUP y no lleva dinero a la tienda', primera === 'vale' && pide &&/No llevas dinero/.test($('#pl-bring').innerText), opciones + ' / ' + $('#pl-bring').innerText);
		await click('[data-act=entregado]', 900);
		check('13b. Transferencia: entregado sin dinero para la tienda', order(9004).stage === 'delivered' && order(9004).transfer === true, JSON.stringify(order(9004)));
		// 14. Sin señal: Recogí + Entregado en cola, en orden, sin repetir
		P.event('nueva'); await refresh(); var n3 = world().orders[world().orders.length - 1].id;
		autoHora = false;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n3 + '"]', 900);
		autoHora = true;
		P.setOnline(false);
		await ponerHora('en 2 horas'); // la hora también se guarda sin señal
		var conHora = /Lo entregas/.test(text()) || /en 2 horas/.test(text());
		await go('#carrera/' + n3); await click('[data-act=recogi]', 900);
		var trasRecogi = text();
		await go('#entregado/' + n3); await click('[data-act=entregado]', 900);
		var enCola = 0;
		check('14a. Sin señal se puede seguir: hora, «Recogí» y «Entregado» guardados', conHora && /Guardado sin conexión/.test(trasRecogi) && order(n3).stage === 'assigned' && !order(n3).eta, 'hora=' + conHora + ' etapa=' + order(n3).stage);
		P.setOnline(true); await wait(3000);
		var logs = world().log.filter(function (l) { return l.indexOf('#' + n3 + ':') !== -1; });
		check('14b. Al volver la señal se mandan solas y en orden (hora → Recogí → Entregado), una vez cada una', order(n3).stage === 'delivered' && logs.length === 3 && /hora/.test(logs[0]) && /Recogí/.test(logs[1]) && /Entregado/.test(logs[2]), JSON.stringify(logs));
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
		var acciones = $$('.pl-actions a, .pl-actions button').map(function (x) { return x.innerText; }).join('|');
		check('16. Problema: pide motivo, pide frase si es «Otro», y luego ya no deja marcar Entregado', sinMotivo && sinTexto && order(n4).incident === 'no_estaba' && !/Entregado/.test(acciones), [sinMotivo, sinTexto, order(n4).incident, acciones].join(' / '));
		// 17. La tienda cancela una mía / me la quita (#65, #4223)
		P.event('nueva'); await refresh(); var n5 = world().orders[world().orders.length - 1].id;
		await go('#carreras'); await click('[data-act=cojo][data-id="' + n5 + '"]', 900); await go('#carreras');
		P.event('quita'); await refresh(); await wait(300);
		check('17. La tienda me quita una: la app me avisa que ya no es mía', /ya no está a tu nombre/.test(toast()) && !$('a[href="#carrera/' + n5 + '"]'), toast());
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
		check('19. La dependienta marca «Dinero recibido»: «Mi dinero» se pone en cero', /No debes dinero a la tienda/.test(text()), text().slice(0, 300));
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
		var veo = /te quiere pasar #/.test(text());
		autoHora = false; await click('[data-act=pase-si][data-id="' + n8 + '"]', 900); autoHora = true;
		check('23. Me pasan una: la veo arriba, «La acepto» → es mía y pide la hora', veo && order(n8).owner === 1 && location.hash === '#hora/' + n8, [veo, order(n8).owner, location.hash].join(' / '));
		await ponerHora();
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
