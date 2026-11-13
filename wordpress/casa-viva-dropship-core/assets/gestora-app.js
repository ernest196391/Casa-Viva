(function () {
  'use strict';
  var app = document.querySelector('[data-cvga]');
  if (!app) return;

  // Secciones del portal clásico → vista de la app.
  var legacy = {
    'mis-clientes': 'clientes', precios: 'precios', invitar: 'invitar', ventas: 'ventas', mensajeria: 'ventas',
    pagos: 'cobrar', materiales: 'fotos', perfil: 'perfil', 'cvd-clave': 'perfil'
  };
  Object.keys(legacy).forEach(function (id) { var el = document.getElementById(id); if (el) el.setAttribute('data-view', legacy[id]); });
  var perProduct = document.querySelector('.cvd-products-pricing'); if (perProduct) perProduct.setAttribute('data-view', 'precios');

  // Enlaces viejos (redirecciones tras guardar) que apuntan a otra palabra.
  var alias = { dashboard: 'inicio', catalogo: 'tienda', clientes: 'clientes', 'mis-clientes': 'clientes', comisiones: 'ventas', mensajeria: 'ventas', pagos: 'cobrar', materiales: 'fotos', busca: 'buscar', 'nexo-busca': 'buscar' };
  var titles = { inicio: 'Inicio', aprende: 'Aprende', tienda: 'Mi tienda', precios: 'Mis precios', clientes: 'Clientes', herramientas: 'Herramientas', calculadora: 'Calculadora', buscar: 'NEXO Busca', confianza: '¿Es de confianza?', fotos: 'Fotos para publicar', estudio: 'Mi estudio de fotos', taller: 'Taller de fotos',contabilidad: 'Mi contabilidad', ventas: 'Ventas', cobrar: 'Cobrar', invitar: 'Invitar gestores', equipo: 'Mi equipo', piedra: 'Mi piedra', proveedor: 'Mis ventas de proveedor', vendedor: 'Mis artículos', articulos: 'Artículos de vendedores', perfil: 'Mi perfil' };
  var views = Array.prototype.slice.call(document.querySelectorAll('[data-view]'));
  var titleEl = app.querySelector('[data-cvga-title]');

  function current() {
    var h = (window.location.hash || '').replace('#', '');
    h = alias[h] || h;
    return titles[h] ? h : 'inicio';
  }
  function show(view, scroll) {
    views.forEach(function (el) { el.hidden = el.getAttribute('data-view') !== view; });
    app.querySelectorAll('[data-cvga-go]').forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-cvga-go') === view); if (a.classList.contains('is-active')) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    if (titleEl) titleEl.textContent = titles[view];
    document.title = titles[view] + ' · Casa Viva';
    // Formularios que se envían a esta misma página: al volver, abrir la misma vista.
    views.forEach(function (el) { el.querySelectorAll('form').forEach(function (f) { var a = f.getAttribute('action'); if (!a || a.charAt(0) === '#') f.setAttribute('action', '#' + el.getAttribute('data-view')); }); });
    app.classList.remove('is-open');
    if (scroll) window.scrollTo(0, 0);
  }
  window.addEventListener('hashchange', function () { show(current(), true); });
  app.addEventListener('click', function (e) {
    var go = e.target.closest('[data-cvga-go]');
    if (go) { e.preventDefault(); var v = go.getAttribute('data-cvga-go'); if (window.location.hash === '#' + v) show(v, true); else window.location.hash = v; return; }
    if (e.target.closest('[data-cvga-open]')) { app.classList.add('is-open'); return; }
    if (e.target.closest('[data-cvga-close]')) { app.classList.remove('is-open'); return; }
    if (e.target.closest('[data-cvga-collapse]')) { app.classList.toggle('is-mini'); try { localStorage.setItem('cvga-mini', app.classList.contains('is-mini') ? '1' : '0'); } catch (ignore) {} return; }
    if (e.target.closest('[data-cvga-logout]') && !window.confirm('¿Salir de tu cuenta? Para volver a entrar escribe "mi acceso" al WhatsApp de Casa Viva.')) { e.preventDefault(); return; }
    if (e.target.closest('[data-cvga-curru]')) { var launcher = document.querySelector('.cvd-assistant-launcher'); if (launcher) launcher.click(); return; }
    var save = e.target.closest('[data-cvga-save-qr]');
    if (save) {
      // 3.13.76: la imagen que se guarda es grande (~1000 px) y con más blanco alrededor (6 módulos): en el estado de
      // WhatsApp el fondo es negro y comprime la foto.
      var canvas = app.querySelector('#cvga-tienda canvas'), text = canvas && canvas.getAttribute('data-qr');
      var download = function (url) { var link = document.createElement('a'); link.download = 'mi-tienda-casa-viva.png'; link.href = url; document.body.appendChild(link); link.click(); link.remove(); };
      if (text && window.CVQRCode && window.cvdQrOptions) { window.CVQRCode.toDataURL(text, window.cvdQrOptions(text, 1024, 6), function (err, url) { if (!err && url) download(url); else if (canvas) download(canvas.toDataURL('image/png')); }); }
      else if (canvas) { download(canvas.toDataURL('image/png')); }
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') app.classList.remove('is-open'); });
  try { if (localStorage.getItem('cvga-mini') === '1') app.classList.add('is-mini'); } catch (ignore) {}

  // El buscador de precios estaba dentro del formulario: Enter lo enviaba vacío ("0 precios guardados").
  var priceSearch = document.getElementById('cvd-price-search');
  if (priceSearch) priceSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

  // NEXO Busca (3.14.28): todo en assets/gestora-busca.js.

  // Calculadora (3.13.51): precio en USD (+ mensajería en CUP) → cada forma de pago con la tasa del día.
  var calc = app.querySelector('[data-cvga-calc]');
  if (calc) {
    var rates = {};
    try { rates = JSON.parse(calc.getAttribute('data-rates') || '{}') || {}; } catch (ignore) {}
    var calcOut = app.querySelector('[data-calc-out]'), copyBtn = app.querySelector('[data-calc-copy]'), copiedEl = app.querySelector('[data-calc-copied]');
    var lastText = '';
    function escHtml(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
    // "25", "25,5", "25.50", "1.250,50" o "1,250.50": el último separador es el decimal.
    function usdValue(v) {
      var s = String(v || '').replace(/[^\d.,]/g, '');
      var dot = s.lastIndexOf('.'), comma = s.lastIndexOf(',');
      if (dot > -1 && comma > -1) s = dot > comma ? s.replace(/,/g, '') : s.replace(/\./g, '').replace(',', '.');
      else if (comma > -1) s = s.replace(/,(?=.*,)/g, '').replace(',', '.');
      var n = parseFloat(s);
      return isFinite(n) && n > 0 ? n : 0;
    }
    function cupValue(v) { var n = parseInt(String(v || '').replace(/\D/g, ''), 10); return isFinite(n) && n > 0 ? n : 0; }
    function fmtCup(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
    function fmtMoney(n, cur) { var s = (Math.round(n * 100) / 100).toFixed(2).replace('.', ',').replace(/,00$/, ''); return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ' + cur; }
    function factor(v) { var n = Number(v); return isFinite(n) && n > 0 ? n : 0; }
    function render() {
      var usd = usdValue(calc.elements.usd.value), ship = cupValue(calc.elements.ship.value);
      if (!usd) { calcOut.innerHTML = '<li class="cvga-calc-empty">Escribe un precio para ver cuánto es.</li>'; copyBtn.hidden = true; lastText = ''; return; }
      var rows = [['Efectivo USD', fmtMoney(usd, 'USD'), '']];
      // 3.14.43 (Ernesto 20-oct): efectivo CUP = tasa del día; transferencia CUP = tasa × 1,20 (rates.cupTransfer).
      var cupT = factor(rates.cupTransfer);
      if (factor(rates.cup)) rows.push(['CUP', fmtCup(usd * factor(rates.cup) + ship), (cupT ? 'Efectivo' : 'Efectivo o transferencia') + (ship ? ' · ya incluye ' + fmtCup(ship) + ' de mensajería' : ''), true]);
      if (factor(rates.cup) && cupT) rows.push(['CUP transferencia', fmtCup(usd * cupT + ship), 'Transferencia en CUP' + (ship ? ' · ya incluye ' + fmtCup(ship) + ' de mensajería' : '')]);
      if (factor(rates.eur)) rows.push(['Euro', fmtMoney(usd * factor(rates.eur), 'EUR'), 'Efectivo en euros']);
      if (factor(rates.clasica)) rows.push(['Tarjeta clásica', fmtMoney(usd * factor(rates.clasica), 'USD'), 'Transferencia a tarjeta clásica']);
      if (factor(rates.zelle)) rows.push(['Zelle', fmtMoney(usd * factor(rates.zelle), 'USD'), '']);
      if (rates.paypalPct !== null && rates.paypalPct !== undefined && isFinite(Number(rates.paypalPct))) rows.push(['PayPal', fmtMoney(usd * (1 + Number(rates.paypalPct) / 100), 'USD'), '']);
      rows.push(['USDT', 'Consultar', 'Pregunta en el grupo General']);
      calcOut.innerHTML = rows.map(function (r) { return '<li' + (r[3] ? ' class="is-main"' : '') + '><span><b>' + escHtml(r[0]) + '</b>' + (r[2] ? '<small>' + escHtml(r[2]) + '</small>' : '') + '</span><strong>' + escHtml(r[1]) + '</strong></li>'; }).join('') + (ship ? '<li class="cvga-calc-empty">Con las demás formas de pago, la mensajería (' + escHtml(fmtCup(ship)) + ') se paga aparte, en CUP, al mensajero.</li>' : '');
      lastText = 'Precio: ' + fmtMoney(usd, 'USD') + (ship ? ' + mensajería ' + fmtCup(ship) : '') + '\n' + rows.filter(function (r) { return r[0] !== 'Efectivo USD' && r[1] !== 'Consultar'; }).map(function (r) { return '• ' + r[0] + ': ' + r[1] + (r[0].indexOf('CUP') === 0 && ship ? ' (con mensajería)' : ''); }).join('\n') + (ship ? '\nLa mensajería se paga en CUP al mensajero.' : '');
      copyBtn.hidden = false; copiedEl.textContent = '';
    }
    calc.addEventListener('input', render);
    calc.addEventListener('submit', function (e) { e.preventDefault(); render(); if (document.activeElement) document.activeElement.blur(); });
    copyBtn.addEventListener('click', function () {
      if (!lastText) return;
      var done = function () { copiedEl.textContent = 'Copiado. Pégalo en el chat del cliente.'; };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(lastText).then(done, function () { window.prompt('Copia el texto:', lastText); }); }
      else { window.prompt('Copia el texto:', lastText); }
    });
    if (!factor(rates.cup)) calc.elements.usd.placeholder = 'Ej.: 25 (aún sin tasa del día)';
    render();
  }

  // Fotos para publicar (3.13.57): las tarjetas se pintan al abrir cada categoría o al buscar.
  var fotos = app.querySelector('[data-cvgf]');
  if (fotos) {
    var fdata = { items: [], cats: [] };
    try { fdata = JSON.parse(fotos.querySelector('[data-cvgf-data]').textContent) || fdata; } catch (ignore) {}
    // Material promocional viejo: solo se ve si hay algo publicado.
    var mat = document.getElementById('materiales');
    if (mat && !mat.querySelector('.cvd-resource-card')) { mat.hidden = true; if (views.indexOf(mat) > -1) views.splice(views.indexOf(mat), 1); }
    var fq = fotos.querySelector('[data-cvgf-q]'), fres = fotos.querySelector('[data-cvgf-results]'), fcats = fotos.querySelector('[data-cvgf-cats]'), fcount = fotos.querySelector('[data-cvgf-count]');
    var fesc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
    var fnorm = function (v) { return String(v || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    // 3.14.74 (Lennys 28-oct): cada foto de un diseño trae su nombre (im[2]) y, si es de uno solo, sus atributos (im[3]).
    // Al tocar la miniatura sale «Elegiste: Verde» sobre la foto; el texto copiado lleva el diseño y el enlace lo abre ya elegido.
    var fdesign = function (p, k, picked) { var d = p.i[k] && p.i[k][2]; return d ? (picked ? 'Elegiste: ' : 'Diseño: ') + '<b>' + fesc(d) + '</b>' : ''; };
    var card = function (p) {
      var thumbs = p.i.length > 1 ? '<div class="cvgf-thumbs">' + p.i.map(function (im, k) { return '<button type="button" class="cvgf-thumb' + (k ? '' : ' is-on') + '" data-k="' + k + '" aria-label="' + fesc(im[2] ? 'Diseño ' + im[2] : 'Foto ' + (k + 1)) + '"' + (im[2] ? ' title="' + fesc(im[2]) + '"' : '') + '><img src="' + fesc(im[0]) + '" alt="" loading="lazy" width="48" height="48"></button>'; }).join('') + '</div>' : '';
      var first = fdesign(p, 0, false);
      return '<article class="cvgf-item" data-id="' + p.id + '"><div class="cvgf-photo"><img src="' + fesc(p.i[0][0]) + '" alt="' + fesc(p.n + (p.i[0][2] ? ' — ' + p.i[0][2] : '')) + '" loading="lazy" width="300" height="300"><span class="cvgf-design" data-cvgf-design' + (first ? '' : ' hidden') + '>' + first + '</span></div>' + thumbs +
        '<div class="cvgf-body"><b class="cvgf-name">' + fesc(p.n) + '</b><span class="cvgf-price">' + fesc(p.p) + (p.cup ? ' <small>' + fesc(p.cup) + '</small>' : '') + '</span><small class="cvgf-stock">' + fesc(p.s) + '</small>' +
        '<p class="cvgf-text">' + fesc(ftext(p, 0)) + '</p><div class="cvga-actions"><a class="cvga-btn cvga-btn-sm" href="' + fesc(p.i[0][1]) + '" data-cvgf-dl>Descargar foto' + (p.i.length > 1 ? ' 1' : '') + '</a><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvgf-copy>Copiar texto</button></div><small class="cvgf-done" role="status"></small></div></article>';
    };
    // Texto para copiar de la foto k: si es de un diseño, «*Nombre* — Verde» y el enlace abre la ficha con ese diseño.
    var ftext = function (p, k) {
      var im = p.i[k] || [], t = p.t;
      if (!im[2]) return t;
      var nl = t.indexOf('\n'), head = nl > -1 ? t.slice(0, nl) : t, rest = nl > -1 ? t.slice(nl) : '';
      t = head + ' — ' + im[2] + rest;
      if (im[3]) t = t.replace(/(https?:\/\/\S+)\s*$/, function (all, link) { return link + (link.indexOf('?') > -1 ? '&' : '?') + im[3]; });
      return t;
    };
    var byId = {};
    fdata.items.forEach(function (p) { byId[p.id] = p; p.q = fnorm(p.n + ' ' + p.t.split('\n')[1]); });
    fcats.addEventListener('toggle', function (e) {
      var d = e.target; if (!d.matches || !d.matches('details[data-cat]') || !d.open) return;
      var grid = d.querySelector('[data-cvgf-grid]'); if (grid.childElementCount) return;
      var cat = Number(d.getAttribute('data-cat'));
      grid.innerHTML = fdata.items.filter(function (p) { return p.c.indexOf(cat) > -1; }).map(card).join('');
    }, true);
    var ftimer;
    // 3.14.66 (zhu, 24-oct): la búsqueda se recuerda en este teléfono (al volver de WhatsApp la página puede recargarse).
    var fkey = 'cvgfQ', fstore = null;
    try { fstore = window.sessionStorage; } catch (ignore) {}
    fq.addEventListener('input', function () {
      try { if (fstore) fstore.setItem(fkey, fq.value); } catch (ignore) {}
      clearTimeout(ftimer);
      ftimer = setTimeout(function () {
        var words = fnorm(fq.value).split(/\s+/).filter(function (w) { return w.length > 1; });
        if (!words.length) { fres.hidden = true; fres.innerHTML = ''; fcats.hidden = false; fcount.textContent = fdata.items.length + ' productos en existencia'; return; }
        var hits = fdata.items.filter(function (p) { return words.every(function (w) { return p.q.indexOf(w.length > 4 ? w.slice(0, -1) : w) > -1; }); });
        fcats.hidden = true; fres.hidden = false;
        fres.innerHTML = hits.length ? '<div class="cvgf-grid">' + hits.map(card).join('') + '</div>' : '<p class="cvga-empty">No encontré «' + fesc(fq.value) + '». Prueba con otra palabra o pregúntale a Curru.</p>';
        fcount.textContent = hits.length + (hits.length === 1 ? ' producto' : ' productos');
      }, 150);
    });
    var fsaved = ''; try { fsaved = fstore ? fstore.getItem(fkey) || '' : ''; } catch (ignore) {}
    if (fsaved && !fq.value) { fq.value = fsaved; fq.dispatchEvent(new Event('input')); }
    fotos.addEventListener('click', function (e) {
      var item = e.target.closest('.cvgf-item'); if (!item) return;
      var p = byId[item.getAttribute('data-id')]; if (!p) return;
      var th = e.target.closest('.cvgf-thumb');
      if (th) {
        var k = Number(th.getAttribute('data-k'));
        var big = item.querySelector('.cvgf-photo img'); big.src = p.i[k][0]; big.alt = p.n + (p.i[k][2] ? ' — ' + p.i[k][2] : '');
        var dl = item.querySelector('[data-cvgf-dl]'); dl.href = p.i[k][1]; dl.textContent = 'Descargar foto ' + (k + 1);
        item.querySelectorAll('.cvgf-thumb').forEach(function (b) { b.classList.toggle('is-on', b === th); });
        var tag = item.querySelector('[data-cvgf-design]'), shown = fdesign(p, k, true);
        if (tag) { tag.innerHTML = shown; tag.hidden = !shown; }
        item.setAttribute('data-k', String(k));
        var tx = item.querySelector('.cvgf-text'); if (tx) tx.textContent = ftext(p, k);
        item.querySelector('.cvgf-done').textContent = '';
        return;
      }
      var done = item.querySelector('.cvgf-done');
      if (e.target.closest('[data-cvgf-dl]')) { done.textContent = 'Descargando… mírala en tu galería o en Descargas.'; return; }
      if (e.target.closest('[data-cvgf-copy]')) {
        var k = Number(item.getAttribute('data-k') || 0), d = p.i[k] && p.i[k][2], txt = ftext(p, k);
        var ok = function () { done.textContent = d ? 'Texto copiado con el diseño «' + d + '». Pégalo junto a la foto.' : 'Texto copiado. Pégalo junto a la foto.'; };
        var fallback = function () {
          var ta = document.createElement('textarea'); ta.value = txt; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
          var copied = false; try { copied = document.execCommand('copy'); } catch (ignore) {}
          document.body.removeChild(ta); if (copied) ok(); else window.prompt('Copia el texto:', txt);
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(txt).then(ok, fallback); else fallback();
      }
    });
    fotos.addEventListener('click', function (e) { var z = e.target.closest('[data-cvgf-zip]'); if (z) { var n = z.parentNode.querySelector('.cvga-note'); if (n) n.textContent = 'Preparando el ZIP… la primera vez puede tardar un minuto.'; } });
  }

  // 3.14.116 (Ernesto 3-nov, caso #6062): «Cambiar», «Cancelar» y «Recuperar» en sus pedidos de «Tus ventas».
  // Core decide qué se puede (GET /gestora/pedidos); aquí solo se pintan los botones y los formularios.
  // Solo quitar o bajar cantidades, contacto, calle (mismo reparto), día y nota. Lo demás lo hace la tienda.
  (function () {
    var cfg = window.cvgaPedidos, table = document.querySelector('#ventas table');
    if (!cfg || !table || !window.fetch) return;
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
    var money = function (n, cur) { return (Math.round(Number(n) * 100) / 100).toFixed(2).replace('.', ',').replace(/,00$/, '') + ' ' + (cur || 'USD'); };
    var reqId = function () { try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID(); } catch (ignore) {} return 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); };
    function api(path, body) {
      var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' } };
      if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
      return fetch(cfg.base + path, opts).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d && d.message ? d.message : 'No se pudo. Revisa tu conexión y vuelve a probar.'); e.data = d; throw e; } return d; });
      });
    }
    var dlg = document.createElement('dialog');
    dlg.className = 'cvga-ped-dialog';
    app.appendChild(dlg);
    var state = null;
    function close() { if (dlg.open) dlg.close(); state = null; }
    function done(text, bad) {
      dlg.innerHTML = '<div class="cvga-ped-form"><h2>' + (bad ? 'No se puede desde aquí' : 'Listo') + '</h2><p class="' + (bad ? 'cvga-ped-err' : 'cvga-ped-ok') + '" role="status">' + esc(text) + '</p><div class="cvga-ped-buttons"><button type="button" class="cvga-btn" data-ped-reload>Entendido</button></div></div>';
      if (!dlg.open) dlg.showModal();
    }
    function fail(form, err) { var m = form.querySelector('[data-ped-msg]'); if (m) m.textContent = err.message; var b = form.querySelector('[type=submit]'); if (b) b.disabled = false; }

    function paintRows(orders) {
      var byNumber = {};
      orders.forEach(function (o) { byNumber[o.number] = o; });
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        var cell = tr.cells[0]; if (!cell || cell.querySelector('.cvga-ped-actions')) return;
        var m = (cell.textContent || '').match(/#\s*([\w-]+)/); var o = m && byNumber[m[1]]; if (!o) return;
        var html = '';
        if (o.can.cambiar === '') html += '<button type="button" class="cvga-ped-btn" data-ped-change="' + o.id + '">Cambiar</button>';
        if (o.can.cancelar === '') html += '<button type="button" class="cvga-ped-btn is-warn" data-ped-cancel="' + o.id + '" data-number="' + esc(o.number) + '">Cancelar</button>';
        if (o.can.recuperar === '') html += '<button type="button" class="cvga-ped-btn" data-ped-recover="' + o.id + '" data-number="' + esc(o.number) + '">Recuperar</button>';
        if (!html && o.status !== 'cancelled') html = '<small class="cvga-ped-why">' + esc(o.can.cambiar || o.can.cancelar) + '</small>';
        var box = document.createElement('div'); box.className = 'cvga-ped-actions'; box.innerHTML = html; cell.appendChild(box);
      });
    }
    api('pedidos').then(function (d) { paintRows(d.orders || []); }).catch(function () {});

    function openChange(id) {
      api('pedido/' + id).then(function (d) {
        if (d.can.cambiar !== '') { done(d.can.cambiar, true); return; }
        state = { id: d.id, number: d.number, lines: d.lines, currency: d.currency, total: d.total, request: reqId() };
        var windows = [['', 'A cualquier hora'], ['morning', 'Por la mañana'], ['afternoon', 'Por la tarde']];
        var opts = function (max, cur) { var s = ''; for (var i = max; i >= 0; i--) s += '<option value="' + i + '"' + (i === cur ? ' selected' : '') + '>' + (i === 0 ? 'Quitar' : i) + '</option>'; return s; };
        dlg.innerHTML = '<form class="cvga-ped-form" data-ped-form="change" novalidate><h2>Cambiar el pedido #' + esc(d.number) + '</h2>'
          + '<p class="cvga-note">Puedes quitar productos o bajar cantidades, y cambiar los datos del cliente. Para añadir algo o cambiar de municipio, escribe a la tienda.</p>'
          + '<h3>Productos</h3>' + d.lines.map(function (l) { return '<label class="cvga-ped-line"><span>' + esc(l.name) + '<small>' + esc(money(l.unit, d.currency)) + ' c/u</small></span><select data-ped-line="' + l.itemId + '" data-unit="' + l.unit + '" aria-label="Cantidad de ' + esc(l.name) + '">' + opts(l.qty, l.qty) + '</select></label>'; }).join('')
          + '<p class="cvga-ped-total">Total: <b data-ped-total>' + esc(money(d.total, d.currency)) + '</b></p>'
          + '<h3>Cliente</h3><div class="cvga-ped-two"><label class="cvga-field"><span>Nombre</span><input name="firstName" value="' + esc(d.firstName) + '" maxlength="60" required></label><label class="cvga-field"><span>Apellidos</span><input name="lastName" value="' + esc(d.lastName) + '" maxlength="60"></label></div>'
          + '<label class="cvga-field"><span>Teléfono</span><input name="phone" value="' + esc(d.phone) + '" inputmode="tel" maxlength="30"></label>'
          + (d.pickup ? '<p class="cvga-note">Recogida en tienda.</p>'
            : '<label class="cvga-field"><span>Dirección (calle y número)</span><input name="address" value="' + esc(d.address) + '" maxlength="200"></label>'
              + '<p class="cvga-note">Solo en el mismo reparto' + (d.locality ? ' (<b>' + esc(d.locality) + '</b>)' : '') + '. Si es en otro municipio o reparto, escribe a la tienda: cambia la mensajería.</p>'
              + '<div class="cvga-ped-two"><label class="cvga-field"><span>Día de entrega</span><input type="date" name="deliveryDate" value="' + esc(d.date) + '" min="' + esc(d.minDate) + '" max="' + esc(d.maxDate) + '"></label><label class="cvga-field"><span>Franja</span><select name="deliveryWindow">' + windows.map(function (w) { return '<option value="' + w[0] + '"' + (w[0] === (d.window || '') ? ' selected' : '') + '>' + w[1] + '</option>'; }).join('') + '</select></label></div>'
              + '<p class="cvga-note">Día vacío = lo antes posible. Ahora: ' + esc(d.whenLabel) + '</p>')
          + '<label class="cvga-field"><span>Nota del pedido</span><textarea name="note" rows="2" maxlength="500">' + esc(d.note) + '</textarea></label>'
          + (d.hasMessenger ? '<p class="cvga-ped-warn">Este pedido ya tiene mensajero: el bot le avisa del cambio.</p>' : '')
          + '<p class="cvga-ped-err" data-ped-msg role="alert"></p>'
          + '<div class="cvga-ped-buttons"><button type="button" class="cvga-btn cvga-btn-soft" data-ped-close>Volver</button><button type="submit" class="cvga-btn">Guardar cambios</button></div></form>';
        dlg.showModal();
      }).catch(function (err) { done(err.message, true); });
    }
    function newTotal(form) { var t = 0; form.querySelectorAll('[data-ped-line]').forEach(function (s) { t += Number(s.getAttribute('data-unit')) * Number(s.value); }); return t; }
    function openReason(kind, id, number) {
      state = { id: id, number: number, request: reqId(), kind: kind };
      var cancel = kind === 'cancel';
      dlg.innerHTML = '<form class="cvga-ped-form" data-ped-form="' + kind + '" novalidate><h2>' + (cancel ? '¿Cancelar el pedido #' : '¿Recuperar el pedido #') + esc(number) + '?</h2>'
        + '<p class="cvga-note">' + (cancel ? 'Los productos vuelven a la tienda y la comisión de este pedido se anula. Si tiene mensajero, el bot le avisa.' : 'El pedido vuelve a estar activo y los productos se apartan otra vez.') + '</p>'
        + '<label class="cvga-field"><span>' + (cancel ? '¿Por qué se cancela?' : '¿Por qué lo recuperas?') + '</span><textarea name="reason" rows="2" maxlength="200" required placeholder="' + (cancel ? 'Ej.: el cliente ya no lo quiere' : 'Ej.: lo cancelé por error') + '"></textarea></label>'
        + '<p class="cvga-ped-err" data-ped-msg role="alert"></p>'
        + '<div class="cvga-ped-buttons"><button type="button" class="cvga-btn cvga-btn-soft" data-ped-close>Volver</button><button type="submit" class="cvga-btn' + (cancel ? ' is-warn' : '') + '">' + (cancel ? 'Sí, cancelar' : 'Sí, recuperarlo') + '</button></div></form>';
      dlg.showModal();
    }

    app.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ped-change]'); if (b) { e.preventDefault(); openChange(b.getAttribute('data-ped-change')); return; }
      b = e.target.closest('[data-ped-cancel]'); if (b) { e.preventDefault(); openReason('cancel', b.getAttribute('data-ped-cancel'), b.getAttribute('data-number')); return; }
      b = e.target.closest('[data-ped-recover]'); if (b) { e.preventDefault(); openReason('recover', b.getAttribute('data-ped-recover'), b.getAttribute('data-number')); return; }
      if (e.target.closest('[data-ped-close]')) { close(); return; }
      if (e.target.closest('[data-ped-reload]')) { close(); window.location.hash = 'ventas'; window.location.reload(); }
    });
    dlg.addEventListener('change', function (e) {
      if (!e.target.matches('[data-ped-line]') || !state) return;
      var form = e.target.form, t = form.querySelector('[data-ped-total]'); if (t) t.textContent = money(newTotal(form), state.currency);
    });
    dlg.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!state) return;
      var form = e.target, kind = form.getAttribute('data-ped-form'), msg = form.querySelector('[data-ped-msg]'), btn = form.querySelector('[type=submit]');
      msg.textContent = '';
      if (kind === 'change') {
        var lines = Array.prototype.map.call(form.querySelectorAll('[data-ped-line]'), function (s) { return { itemId: Number(s.getAttribute('data-ped-line')), qty: Number(s.value) }; });
        if (lines.every(function (l) { return l.qty === 0; })) { msg.textContent = 'El pedido no puede quedar sin productos. Si ya no lo quieren, cancélalo.'; return; }
        if (!form.elements.firstName.value.trim()) { msg.textContent = 'Escribe el nombre del cliente.'; return; }
        var body = { requestId: state.request, lines: lines, firstName: form.elements.firstName.value, lastName: form.elements.lastName.value, phone: form.elements.phone.value, note: form.elements.note.value };
        if (form.elements.address) { body.address = form.elements.address.value; body.deliveryDate = form.elements.deliveryDate.value; body.deliveryWindow = form.elements.deliveryWindow.value; }
        var total = newTotal(form);
        if (Math.abs(total - state.total) > 0.009 && !window.confirm('El pedido #' + state.number + ' queda en ' + money(total, state.currency) + ' (antes ' + money(state.total, state.currency) + '). ¿Guardar?')) return;
        btn.disabled = true;
        api('pedido/' + state.id + '/cambiar', body).then(function (r) { done(r.message); }).catch(function (err) { fail(form, err); });
        return;
      }
      var reason = form.elements.reason.value.trim();
      if (reason.length < 3) { msg.textContent = 'Escribe el motivo (una frase corta).'; return; }
      btn.disabled = true;
      api('pedido/' + state.id + '/' + (kind === 'cancel' ? 'cancelar' : 'recuperar'), { requestId: state.request, reason: reason }).then(function (r) { done(r.message); }).catch(function (err) { fail(form, err); });
    });
  })();

  show(current(), false);
})();
