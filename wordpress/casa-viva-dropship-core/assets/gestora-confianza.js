/* «¿Es de confianza este número?» (3.14.42, Ernesto 20-oct): mirar un número en la lista de sospechosos de estafa
   de la red NEXO, ver la lista (se actualiza sola) con un filtro, y reportar un número. Lo del servidor está en
   includes/class-cvd-gestora-confianza.php. Nunca decimos «es estafador»: «reportado por el equipo» o «sin confirmar». */
(function () {
  'use strict';
  var view = document.querySelector('[data-cvga-confianza]');
  if (!view) return;
  var cfg = {};
  try { cfg = JSON.parse(view.querySelector('[data-conf-config]').textContent) || {}; } catch (ignore) {}
  var formMirar = view.querySelector('[data-conf-mirar]'), res = view.querySelector('[data-conf-res]');
  var formRep = view.querySelector('[data-conf-reportar]'), repRes = view.querySelector('[data-conf-rep-res]');
  var listaEl = view.querySelector('[data-conf-lista]'), filtro = view.querySelector('[data-conf-filtro]');
  var numeros = null, cargando = false;

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
  function digits(v) { return String(v || '').replace(/\D/g, ''); }
  function bonito(t) { t = String(t); return t.length === 8 ? t.slice(0, 2) + ' ' + t.slice(2, 5) + ' ' + t.slice(5) : (t.length > 8 ? '+' + t : t); }
  function fecha(iso) { try { return new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }); } catch (e) { return ''; } }
  function call(method, path, body) {
    var opt = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(cfg.base + path, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'No se pudo. Prueba otra vez.'); return d; });
    });
  }

  function ficha(n) {
    if (n.estado === 'confirmado') {
      return '<div class="cvga-conf-box is-no"><b>⛔ Reportado por el equipo de Casa Viva.</b>' + (n.motivo ? '<br>' + esc(n.motivo) + '.' : '') +
        '<br>No mandes el pedido ni la mensajería sin hablar antes con la tienda.</div>';
    }
    return '<div class="cvga-conf-box is-duda"><b>⚠️ Reportado, sin confirmar por la tienda.</b><br>' + esc(n.origen || 'Reportado') + (n.desde ? ', ' + esc(fecha(n.desde)) : '') + '.' +
      '<br>Pide el pago por transferencia antes de enviar y avisa a la tienda.</div>';
  }

  if (!cfg.on) {
    res.innerHTML = '<p class="cvga-warn">La lista no está disponible ahora. Pregunta a la tienda.</p>';
    listaEl.innerHTML = '';
    return;
  }

  formMirar.addEventListener('submit', function (e) {
    e.preventDefault();
    var tel = formMirar.telefono.value.trim();
    if (digits(tel).length < 7) { res.innerHTML = '<p class="cvga-warn">Escribe el número completo, por ejemplo 5X XXX XXX.</p>'; return; }
    res.innerHTML = '<p class="cvga-note">Mirando…</p>';
    call('POST', '', { accion: 'mirar', telefono: tel }).then(function (d) {
      res.innerHTML = d.encontrado ? ficha(d)
        : '<div class="cvga-conf-box is-ok"><b>✅ No está en la lista.</b><br>Eso no garantiza nada: si algo te parece raro (dirección que no existe, prisa, pago en efectivo con billetes grandes), pregunta a la tienda.</div>';
    }).catch(function (err) { res.innerHTML = '<p class="cvga-warn">' + esc(err.message) + '</p>'; });
  });

  function pintarLista() {
    if (!numeros) return;
    var f = digits(filtro.value);
    var ver = numeros.filter(function (n) { return !f || String(n.telefono).indexOf(f) !== -1; });
    if (!ver.length) { listaEl.innerHTML = '<li class="cvga-empty">' + (f ? 'Ningún número reportado tiene esos dígitos.' : 'La lista está vacía.') + '</li>'; return; }
    listaEl.innerHTML = ver.map(function (n) {
      var conf = n.estado === 'confirmado';
      return '<li><b>' + esc(bonito(n.telefono)) + '</b><span class="cvga-chip' + (conf ? ' is-no' : ' is-warn') + '">' + (conf ? 'Reportado por el equipo' : 'Sin confirmar') + '</span>' +
        '<small>' + esc(conf && n.motivo ? n.motivo : (n.origen || '')) + (n.desde ? ' · ' + esc(fecha(n.desde)) : '') + '</small></li>';
    }).join('');
  }
  function cargarLista() {
    if (cargando) return;
    cargando = true;
    call('GET', '/lista').then(function (d) {
      numeros = (d.numeros || []).slice().sort(function (a, b) { return (a.estado === 'confirmado' ? 0 : 1) - (b.estado === 'confirmado' ? 0 : 1); });
      pintarLista();
    }).catch(function (err) { listaEl.innerHTML = '<li class="cvga-warn">' + esc(err.message) + '</li>'; })
      .then(function () { cargando = false; });
  }
  filtro.addEventListener('input', pintarLista);
  // La lista se pide al abrir la vista (no en cada carga del área).
  function alAbrir() { if ((window.location.hash || '').replace('#', '') === 'confianza' && !numeros) cargarLista(); }
  window.addEventListener('hashchange', alAbrir);
  alAbrir();

  formRep.addEventListener('submit', function (e) {
    e.preventDefault();
    var tel = formRep.telefono.value.trim(), motivo = formRep.motivo.value.trim();
    if (digits(tel).length < 7) { repRes.textContent = 'Escribe el número completo.'; return; }
    if (motivo.length < 5) { repRes.textContent = 'Cuenta en una frase qué pasó.'; return; }
    var btn = formRep.querySelector('button'); btn.disabled = true;
    repRes.textContent = 'Enviando…';
    call('POST', '', { accion: 'reportar', telefono: tel, motivo: motivo }).then(function () {
      repRes.textContent = '✅ Reportado. Gracias: la tienda lo revisa y ya sale en la lista como «sin confirmar».';
      formRep.reset(); numeros = null; cargarLista();
    }).catch(function (err) { repRes.textContent = err.message; })
      .then(function () { btn.disabled = false; });
  });
})();
