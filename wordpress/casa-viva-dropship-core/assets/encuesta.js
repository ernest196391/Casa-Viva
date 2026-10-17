/* Encuesta anónima Casa Viva (3.14.35). Una pregunta por pantalla, borrador en el teléfono y envío sin cookies. */
(function () {
	'use strict';
	var f = document.getElementById('enc');
	if (!f) { return; }
	var K = f.getAttribute('data-k'), KB = K + '_borrador';
	var LS = {
		get: function (k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
		set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
		del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }
	};
	var $ = function (id) { return document.getElementById(id); };
	var steps = [].slice.call(f.querySelectorAll('.step'));
	var intro = $('intro'), nav = $('nav'), back = $('back'), next = $('next'), bar = $('bar'), count = $('count'), msg = $('msg');
	var ya = $('ya'), done = $('done');
	var idx = 0, sending = false;

	function papel() {
		var p = f.querySelector('input[name=papel]:checked') || f.querySelector('input[type=hidden][name=papel]');
		return p ? p.value : '';
	}
	function val(q) {
		var el = f.querySelector('input[name="r[' + q + ']"]:checked');
		return el ? el.value : '';
	}
	function visible() {
		var p = papel();
		return steps.filter(function (s) {
			var b = s.getAttribute('data-block');
			if (b === 'papel') { return true; }
			if (!p) { return false; }
			if (b !== 'comun' && b !== 'final' && b !== p) { return false; }
			var c = s.getAttribute('data-if');
			if (c) {
				var parts = c.split(':');
				if (parts[1].split(',').indexOf(val(parts[0])) < 0) { return false; }
			}
			return true;
		});
	}
	function answered(s) {
		if (s.querySelector('input:checked')) { return true; }
		var t = s.querySelector('textarea');
		return !!(t && t.value.trim());
	}
	function show() {
		var v = visible();
		if (idx > v.length - 1) { idx = v.length - 1; }
		if (idx < 0) { idx = 0; }
		steps.forEach(function (s) { s.classList.remove('cur'); });
		var s = v[idx];
		s.classList.add('cur');
		intro.hidden = idx !== 0;
		var choosing = s.getAttribute('data-block') === 'papel';
		var total = choosing ? 0 : v.length;
		bar.style.width = total ? Math.round((idx + 1) * 100 / total) + '%' : '3%';
		count.textContent = total ? 'Pregunta ' + (idx + 1) + ' de ' + total : '';
		back.hidden = idx === 0;
		var last = !choosing && idx === v.length - 1;
		next.disabled = choosing && !papel();
		next.textContent = last ? 'Enviar' : (choosing || answered(s) ? 'Siguiente' : 'Saltar');
		next.classList.toggle('send', last);
		msg.hidden = true;
	}
	function save() {
		var data = [];
		[].forEach.call(f.elements, function (el) {
			if (!el.name || el.name === 'web') { return; }
			if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) { return; }
			if (el.type === 'hidden') { return; }
			if (el.value === '') { return; }
			data.push([el.name, el.value]);
		});
		LS.set(KB, { i: idx, d: data });
	}
	function restore() {
		var b = LS.get(KB);
		if (!b || !b.d) { return; }
		b.d.forEach(function (pair) {
			[].forEach.call(f.elements, function (el) {
				if (el.name !== pair[0]) { return; }
				if (el.type === 'radio' || el.type === 'checkbox') { if (el.value === pair[1]) { el.checked = true; } }
				else if (el.tagName === 'TEXTAREA' || el.type === 'text') { el.value = pair[1]; }
			});
		});
		idx = b.i || 0;
	}
	function syncOtra(scope) {
		[].forEach.call((scope || f).querySelectorAll('input[data-text]'), function (inp) {
			var box = inp.parentNode.nextElementSibling;
			if (box && box.classList.contains('otra')) { box.classList.toggle('on', inp.checked); }
		});
	}
	function go(d) {
		var v = visible();
		if (d > 0 && v[idx].getAttribute('data-block') !== 'papel' && idx === v.length - 1) { send(); return; }
		idx += d;
		show();
		save();
		window.scrollTo(0, 0);
	}
	function post(form, extra) {
		var fd = new FormData(form);
		fd.append('ajax', '1');
		Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
		// credentials:'omit' → no viajan las cookies de la web: aunque tengas la sesión abierta, no se sabe quién eres.
		return fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'omit' }).then(function (r) {
			return r.json().catch(function () { return { ok: false }; });
		});
	}
	function fail(el, text) { el.textContent = text; el.hidden = false; }
	function send() {
		if (sending) { return; }
		sending = true;
		next.disabled = true;
		next.textContent = 'Enviando…';
		post(f).then(function (res) {
			sending = false;
			if (res && res.ok) {
				LS.set(K, { papel: papel(), dia: new Date().toISOString().slice(0, 10) });
				LS.del(KB);
				f.hidden = true; intro.hidden = true; nav.hidden = true;
				bar.style.width = '100%'; count.textContent = '';
				done.hidden = false;
				window.scrollTo(0, 0);
			} else {
				next.disabled = false; next.textContent = 'Enviar otra vez';
				fail(msg, (res && res.msg) || 'No se pudo enviar. Tus respuestas siguen guardadas en este teléfono: toca «Enviar otra vez».');
			}
		}, function () {
			sending = false;
			next.disabled = false; next.textContent = 'Enviar otra vez';
			fail(msg, 'No hay conexión. Tus respuestas siguen guardadas en este teléfono: toca «Enviar otra vez» cuando tengas internet.');
		});
	}

	// ¿Ya la contestó en este teléfono? Entonces solo «¿quieres añadir algo?».
	var mark = LS.get(K);
	if (mark && mark.papel) {
		f.hidden = true; intro.hidden = true;
		ya.hidden = false;
		ya.querySelector('input[name=papel]').value = mark.papel;
		ya.addEventListener('submit', function (e) {
			e.preventDefault();
			var m = ya.querySelector('.msg'), btn = ya.querySelector('button');
			var t = ya.querySelector('textarea');
			if (!t.value.trim()) { fail(m, 'Escribe lo que quieras añadir.'); return; }
			btn.disabled = true; btn.textContent = 'Enviando…';
			post(ya).then(function (res) {
				btn.disabled = false; btn.textContent = 'Añadir';
				if (res && res.ok) { t.value = ''; m.hidden = true; ya.querySelector('p').textContent = 'Gracias, lo añadimos. ¿Algo más? Puedes escribirlo aquí.'; }
				else { fail(m, (res && res.msg) || 'No se pudo enviar. Prueba otra vez.'); }
			}, function () { btn.disabled = false; btn.textContent = 'Añadir'; fail(m, 'No hay conexión. Prueba otra vez cuando tengas internet.'); });
		});
		return;
	}

	restore();
	syncOtra();
	nav.hidden = false;
	show();

	f.addEventListener('change', function (e) {
		var el = e.target, s = el.closest('.step');
		if (!s) { return; }
		var type = s.getAttribute('data-type');
		if (type === 'multi' && el.type === 'checkbox' && el.checked) {
			var excl = (s.getAttribute('data-excl') || '').split(',');
			var boxes = [].slice.call(s.querySelectorAll('input[type=checkbox]'));
			if (excl.indexOf(el.value) >= 0) { boxes.forEach(function (b) { if (b !== el) { b.checked = false; } }); }
			else { boxes.forEach(function (b) { if (excl.indexOf(b.value) >= 0) { b.checked = false; } }); }
			var max = parseInt(s.getAttribute('data-max') || '0', 10);
			if (max && boxes.filter(function (b) { return b.checked; }).length > max) {
				el.checked = false;
				var h = s.querySelector('.hint');
				if (h) { h.textContent = 'Solo hasta ' + max + '. Quita una si quieres cambiarla.'; }
			}
		}
		syncOtra(s);
		save();
		show();
		var single = el.type === 'radio' && (type === 'single' || type === 'scale5' || type === 'scale10');
		if (single && !el.hasAttribute('data-text')) {
			setTimeout(function () { if (s.classList.contains('cur')) { go(1); } }, 260);
		} else if (el.hasAttribute('data-text') && el.checked) {
			var box = el.parentNode.nextElementSibling;
			if (box) { box.focus(); }
		}
	});
	f.addEventListener('input', function (e) { if (e.target.tagName === 'TEXTAREA' || e.target.type === 'text') { save(); var v = visible(); next.textContent = (idx === v.length - 1 && v[idx].getAttribute('data-block') !== 'papel') ? 'Enviar' : (answered(v[idx]) ? 'Siguiente' : 'Saltar'); } });
	f.addEventListener('submit', function (e) { e.preventDefault(); go(1); });
	f.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.type === 'text') { e.preventDefault(); } });
	back.addEventListener('click', function () { go(-1); });
	next.addEventListener('click', function () { go(1); });
})();
