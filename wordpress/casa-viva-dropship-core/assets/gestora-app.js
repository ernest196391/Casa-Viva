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
  var alias = { dashboard: 'inicio', catalogo: 'tienda', clientes: 'clientes', 'mis-clientes': 'clientes', comisiones: 'ventas', mensajeria: 'ventas', pagos: 'cobrar', materiales: 'fotos' };
  var titles = { inicio: 'Inicio', aprende: 'Aprende', tienda: 'Mi tienda', precios: 'Mis precios', clientes: 'Clientes', herramientas: 'Herramientas', calculadora: 'Calculadora', buscar: 'Buscar precios', fotos: 'Fotos para publicar', ventas: 'Ventas', cobrar: 'Cobrar', invitar: 'Invitar gestores', perfil: 'Mi perfil' };
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

  // Buscar precios: foto → ofertas públicas en Cuba.
  var busca = app.querySelector('[data-cvga-busca]');
  if (busca) {
    var statusEl = busca.querySelector('[data-busca-status]'), out = app.querySelector('[data-busca-results]'), leftEl = busca.querySelector('[data-busca-left]');
    var fileInput = busca.querySelector('input[type=file]'), preview = busca.querySelector('.cvga-busca-preview');
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
    var matchLabel = { exact: 'Igual', close: 'Parecido', alternative: 'Otra opción', unconfirmed: 'Sin confirmar' };
    fileInput.addEventListener('change', function () { var f = fileInput.files && fileInput.files[0]; if (f) { preview.src = URL.createObjectURL(f); preview.hidden = false; } });
    busca.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!fileInput.files || !fileInput.files[0]) return;
      var button = busca.querySelector('button[type=submit]'); button.disabled = true;
      statusEl.hidden = false; statusEl.textContent = 'Buscando en Revolico, Facebook y tiendas… puede tardar hasta un minuto.'; out.innerHTML = '';
      fetch(busca.getAttribute('data-endpoint'), { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': busca.getAttribute('data-nonce') }, body: new FormData(busca) })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'No se pudo buscar.'); return d; }); })
        .then(function (d) {
          statusEl.hidden = true;
          if (leftEl && typeof d.remaining === 'number') leftEl.textContent = 'Te quedan ' + d.remaining + ' búsquedas hoy.';
          var html = '<section class="cvga-card"><h2>' + esc(d.product || 'Resultado') + '</h2><p class="cvga-note">' + esc(d.summary) + '</p></section>';
          if (d.results && d.results.length) {
            html += '<ul class="cvga-offers">' + d.results.map(function (r) {
              var wa = r.phone ? 'https://wa.me/' + (r.phone.replace(/\D/g, '').length === 8 ? '53' : '') + r.phone.replace(/\D/g, '') : '';
              return '<li class="cvga-card"><div class="cvga-row"><b>' + esc(r.price || 'Precio no visible') + '</b><span class="cvga-chip">' + esc(matchLabel[r.match] || '') + '</span></div><p>' + esc(r.title) + '</p><small class="cvga-note">' + esc([r.location, r.source].filter(Boolean).join(' · ')) + (r.note ? '<br>' + esc(r.note) : '') + '</small><div class="cvga-actions">' + (r.url ? '<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="' + esc(r.url) + '" target="_blank" rel="noopener nofollow">Ver anuncio</a>' : '') + (wa ? '<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="' + esc(wa) + '" target="_blank" rel="noopener">WhatsApp</a>' : '') + '</div></li>';
            }).join('') + '</ul>';
          } else { html += '<p class="cvga-empty">No encontré ofertas públicas de este producto. Prueba con otra foto o escribe qué es.</p>'; }
          if (d.questions && d.questions.length) html += '<section class="cvga-card"><h2>Pregúntale al vendedor</h2><ul class="cvga-questions">' + d.questions.map(function (q) { return '<li>' + esc(q) + '</li>'; }).join('') + '</ul></section>';
          html += '<p class="cvga-note">Precios de anuncios públicos: confírmalos con el vendedor antes de ofrecerlos.</p>';
          out.innerHTML = html;
        })
        .catch(function (err) { statusEl.hidden = false; statusEl.textContent = err.message; })
        .then(function () { button.disabled = false; });
    });
  }

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
      if (factor(rates.cup)) rows.push(['CUP', fmtCup(usd * factor(rates.cup) + ship), ship ? 'Ya incluye ' + fmtCup(ship) + ' de mensajería' : 'Efectivo o transferencia', true]);
      if (factor(rates.eur)) rows.push(['Euro', fmtMoney(usd * factor(rates.eur), 'EUR'), 'Efectivo en euros']);
      if (factor(rates.clasica)) rows.push(['Tarjeta clásica', fmtMoney(usd * factor(rates.clasica), 'USD'), 'Transferencia a tarjeta clásica']);
      if (factor(rates.zelle)) rows.push(['Zelle', fmtMoney(usd * factor(rates.zelle), 'USD'), '']);
      if (rates.paypalPct !== null && rates.paypalPct !== undefined && isFinite(Number(rates.paypalPct))) rows.push(['PayPal', fmtMoney(usd * (1 + Number(rates.paypalPct) / 100), 'USD'), '']);
      rows.push(['USDT', 'Consultar', 'Pregunta en el grupo General']);
      calcOut.innerHTML = rows.map(function (r) { return '<li' + (r[3] ? ' class="is-main"' : '') + '><span><b>' + escHtml(r[0]) + '</b>' + (r[2] ? '<small>' + escHtml(r[2]) + '</small>' : '') + '</span><strong>' + escHtml(r[1]) + '</strong></li>'; }).join('') + (ship ? '<li class="cvga-calc-empty">Con las demás formas de pago, la mensajería (' + escHtml(fmtCup(ship)) + ') se paga aparte, en CUP, al mensajero.</li>' : '');
      lastText = 'Precio: ' + fmtMoney(usd, 'USD') + (ship ? ' + mensajería ' + fmtCup(ship) : '') + '\n' + rows.filter(function (r) { return r[0] !== 'Efectivo USD' && r[1] !== 'Consultar'; }).map(function (r) { return '• ' + r[0] + ': ' + r[1] + (r[0] === 'CUP' && ship ? ' (con mensajería)' : ''); }).join('\n') + (ship ? '\nLa mensajería se paga en CUP al mensajero.' : '');
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
    var card = function (p) {
      var thumbs = p.i.length > 1 ? '<div class="cvgf-thumbs">' + p.i.map(function (im, k) { return '<button type="button" class="cvgf-thumb' + (k ? '' : ' is-on') + '" data-k="' + k + '" aria-label="Foto ' + (k + 1) + '"><img src="' + fesc(im[0]) + '" alt="" loading="lazy" width="48" height="48"></button>'; }).join('') + '</div>' : '';
      return '<article class="cvgf-item" data-id="' + p.id + '"><div class="cvgf-photo"><img src="' + fesc(p.i[0][0]) + '" alt="' + fesc(p.n) + '" loading="lazy" width="300" height="300"></div>' + thumbs +
        '<div class="cvgf-body"><b class="cvgf-name">' + fesc(p.n) + '</b><span class="cvgf-price">' + fesc(p.p) + (p.cup ? ' <small>' + fesc(p.cup) + '</small>' : '') + '</span><small class="cvgf-stock">' + fesc(p.s) + '</small>' +
        '<p class="cvgf-text">' + fesc(p.t) + '</p><div class="cvga-actions"><a class="cvga-btn cvga-btn-sm" href="' + fesc(p.i[0][1]) + '" data-cvgf-dl>Descargar foto' + (p.i.length > 1 ? ' 1' : '') + '</a><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvgf-copy>Copiar texto</button></div><small class="cvgf-done" role="status"></small></div></article>';
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
    fq.addEventListener('input', function () {
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
    fotos.addEventListener('click', function (e) {
      var item = e.target.closest('.cvgf-item'); if (!item) return;
      var p = byId[item.getAttribute('data-id')]; if (!p) return;
      var th = e.target.closest('.cvgf-thumb');
      if (th) {
        var k = Number(th.getAttribute('data-k'));
        item.querySelector('.cvgf-photo img').src = p.i[k][0];
        var dl = item.querySelector('[data-cvgf-dl]'); dl.href = p.i[k][1]; dl.textContent = 'Descargar foto ' + (k + 1);
        item.querySelectorAll('.cvgf-thumb').forEach(function (b) { b.classList.toggle('is-on', b === th); });
        return;
      }
      var done = item.querySelector('.cvgf-done');
      if (e.target.closest('[data-cvgf-dl]')) { done.textContent = 'Descargando… mírala en tu galería o en Descargas.'; return; }
      if (e.target.closest('[data-cvgf-copy]')) {
        var ok = function () { done.textContent = 'Texto copiado. Pégalo junto a la foto.'; };
        var fallback = function () {
          var ta = document.createElement('textarea'); ta.value = p.t; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
          var copied = false; try { copied = document.execCommand('copy'); } catch (ignore) {}
          document.body.removeChild(ta); if (copied) ok(); else window.prompt('Copia el texto:', p.t);
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(p.t).then(ok, fallback); else fallback();
      }
    });
    fotos.addEventListener('click', function (e) { var z = e.target.closest('[data-cvgf-zip]'); if (z) { var n = z.parentNode.querySelector('.cvga-note'); if (n) n.textContent = 'Preparando el ZIP… la primera vez puede tardar un minuto.'; } });
  }

  show(current(), false);
})();
