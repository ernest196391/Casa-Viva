/* NEXO Busca (3.14.28, Ernesto 16-oct): foto o texto → ofertas en Cuba → «Usar esta»:
   1) Define tu precio (cantidad; sumar USD, sumar % o precio final; mensajería CUP) → «Cliente paga» y «Tú ganas»
      en USD y CUP con la tasa del día (lógica de calculateOffer de la primera versión, lib/product-search/validation.ts);
   2) Preguntar al proveedor (wa.me con texto listo, o «Abrir anuncio»);
   3) Hacer foto bonita (lleva la foto al estudio del área y vuelve con ella);
   4) Mandar a mi cliente (texto sin decir dónde se compra; copiar, compartir, con la foto bonita si la hay);
   5) Vender por Casa Viva (bajo pedido): solicitud que Casa Viva comprueba con el proveedor ANTES de confirmar. */
(function () {
  'use strict';
  var form = document.querySelector('[data-cvga-busca]');
  if (!form) return;
  var cfg = {};
  try { cfg = JSON.parse(form.querySelector('[data-busca-config]').textContent) || {}; } catch (ignore) {}
  var rates = cfg.rates || {};
  var view = form.closest('[data-view]') || document;
  var statusEl = form.querySelector('[data-busca-status]'), leftEl = form.querySelector('[data-busca-left]');
  var out = view.querySelector('[data-busca-results]'), use = view.querySelector('[data-busca-use]');
  var mine = view.querySelector('[data-busca-mine]'), mineList = view.querySelector('[data-busca-mine-list]');
  var fileInput = form.querySelector('input[type=file]'), preview = form.querySelector('.cvga-busca-preview'), dropText = form.querySelector('.cvnb-drop-text');
  var st = { data: null, sel: -1, file: null, pretty: null, qty: 1, mode: 'fixed', value: '3', ship: '', cost: '', audience: 'client', sent: {} };

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
  // "12", "12,5", "1.250,50" o "1,250.50": el último separador es el decimal.
  function num(v) {
    var s = String(v == null ? '' : v).replace(/[^\d.,]/g, '');
    var dot = s.lastIndexOf('.'), comma = s.lastIndexOf(',');
    if (dot > -1 && comma > -1) s = dot > comma ? s.replace(/,/g, '') : s.replace(/\./g, '').replace(',', '.');
    else if (comma > -1) s = s.replace(/,(?=.*,)/g, '').replace(',', '.');
    else if ((s.match(/\./g) || []).length > 1 || /^\d{1,3}\.\d{3}$/.test(s)) s = s.replace(/\./g, ''); // 9.000 CUP
    var n = parseFloat(s);
    return isFinite(n) && n > 0 ? n : 0;
  }
  function factor(v) { var n = Number(v); return isFinite(n) && n > 0 ? n : 0; }
  var cupRate = factor(rates.cup);
  function r2(n) { return Math.round(n * 100) / 100; }
  function fmtUsd(n) { return String(r2(n).toFixed(2)).replace('.', ',').replace(/,00$/, '') + ' USD'; }
  function fmtCup(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; }
  function cup(n) { return cupRate ? fmtCup(n * cupRate) : ''; }
  // Precio del anuncio en USD (CUP con la tasa del día; MLC como USD). null si no se sabe.
  function offerUsd(o) {
    var amount = Number(o.amount) > 0 ? Number(o.amount) : 0, cur = o.currency || '';
    if (!amount && o.price) {
      amount = num(o.price);
      var l = String(o.price).toUpperCase();
      cur = /CUP|MN|PESOS/.test(l) ? 'CUP' : /MLC/.test(l) ? 'MLC' : /EUR|€/.test(l) ? 'EUR' : /USD|\$|DÓLAR|DOLAR/.test(l) ? 'USD' : '';
    }
    if (!amount) return null;
    if (cur === 'CUP') return cupRate ? r2(amount / cupRate) : null;
    if (cur === 'EUR') return factor(rates.eur) ? r2(amount / factor(rates.eur)) : amount;
    if (cur === 'USD' || cur === 'MLC') return amount;
    return amount < 400 ? amount : (cupRate ? r2(amount / cupRate) : null); // sin moneda: un número grande es CUP
  }
  // calculateOffer (primera versión de NEXO Busca), con la ganancia por unidad y el precio final por unidad.
  function calc(i) {
    var q = Math.max(1, i.quantity), cost = Math.max(0, i.cost) * q, v = Math.max(0, i.value), product;
    if (i.mode === 'final') product = Math.max(cost, v * q);
    else if (i.mode === 'percent') product = cost * (1 + v / 100);
    else product = cost + v * q;
    product = r2(product);
    return { cost: r2(cost), productTotal: product, unit: r2(product / q), earning: r2(product - cost), delivery: Math.max(0, i.delivery) };
  }
  function current() { return st.data && st.data.results[st.sel]; }
  function productName() { var o = current(); return (st.data && st.data.product) || (o && o.title) || 'el producto'; }
  function offerNow() {
    if (!current() || !num(st.cost)) return null;
    return calc({ cost: num(st.cost), quantity: st.qty, mode: st.mode, value: num(st.value), delivery: Math.round(num(st.ship)) });
  }
  function waPhone(p) {
    var d = String(p || '').replace(/\D/g, '');
    if (d.length === 8 && d.charAt(0) === '5') return '53' + d;
    if (d.length === 10 && d.indexOf('53') === 0) return d;
    return d.length >= 8 ? d : '';
  }
  function copy(text, done) {
    var fallback = function () {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
      var ok = false; try { ok = document.execCommand('copy'); } catch (ignore) {}
      document.body.removeChild(ta); if (ok) done(); else window.prompt('Copia el texto:', text);
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fallback); else fallback();
  }
  function post(path, fd) {
    return fetch(cfg.base + path, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'No se pudo. Prueba otra vez.'); return d; }); },
        function () { throw new Error('Sin conexión. Revisa tus datos o el wifi y prueba otra vez.'); });
  }

  /* ---------- 0. buscar ---------- */
  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    st.file = f || null;
    if (f) { preview.src = URL.createObjectURL(f); preview.hidden = false; dropText.querySelector('b').textContent = 'Cambiar foto'; }
  });
  var matchLabel = { exact: 'Igual', close: 'Muy parecido', alternative: 'Otra opción', unconfirmed: 'Sin confirmar' };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ctx = form.elements.context.value.trim();
    if (!st.file && ctx.length < 3) { statusEl.hidden = false; statusEl.textContent = 'Sube una foto o escribe qué buscas.'; return; }
    var button = form.querySelector('button[type=submit]'); button.disabled = true;
    statusEl.hidden = false; statusEl.textContent = 'Buscando en Revolico, Facebook y tiendas… puede tardar hasta un minuto. No cierres esta página.';
    out.innerHTML = ''; use.hidden = true; use.innerHTML = ''; st.sel = -1; st.pretty = null; st.sent = {};
    var fd = new FormData();
    if (st.file) fd.append('image', st.file, st.file.name || 'foto.jpg');
    fd.append('context', ctx); fd.append('zone', form.elements.zone.value);
    post('', fd).then(function (d) {
      statusEl.hidden = true; st.data = d; d.results = d.results || [];
      if (leftEl && typeof d.remaining === 'number') leftEl.textContent = 'Te quedan ' + d.remaining + ' búsquedas hoy.';
      var html = '<section class="cvga-card cvnb-sum"><span class="cvnb-kick">Lo que encontré</span><h2>' + esc(d.product || 'Resultado') + '</h2><p class="cvga-note">' + esc(d.summary) + '</p></section>';
      if (d.results.length) {
        html += '<ul class="cvga-offers cvnb-offers">' + d.results.map(function (r, i) {
          return '<li class="cvga-card cvnb-offer" data-i="' + i + '"><div class="cvga-row"><b class="cvnb-price">' + esc(r.price || 'Precio no visible') + '</b><span class="cvga-chip">' + esc(matchLabel[r.match] || '') + '</span></div><p>' + esc(r.title) + '</p><small class="cvga-note">' + esc([r.location, r.source].filter(Boolean).join(' · ')) + (r.note ? '<br>' + esc(r.note) : '') + '</small>' +
            '<div class="cvga-actions"><button type="button" class="cvga-btn cvga-btn-sm cvnb-use-btn" data-use="' + i + '">Usar esta</button>' + (r.url ? '<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="' + esc(r.url) + '" target="_blank" rel="noopener nofollow">Ver anuncio</a>' : '') + '</div></li>';
        }).join('') + '</ul>';
      } else { html += '<p class="cvga-empty">No encontré ofertas públicas de este producto. Prueba con otra foto o escribe qué es.</p>'; }
      if (d.questions && d.questions.length) html += '<details class="cvga-card cvnb-q"><summary>Qué preguntarle al vendedor</summary><ul class="cvga-questions">' + d.questions.map(function (q) { return '<li>' + esc(q) + '</li>'; }).join('') + '</ul></details>';
      html += '<p class="cvga-note">Precios de anuncios públicos: confírmalos con el vendedor antes de ofrecerlos.</p>';
      out.innerHTML = html;
      out.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function (err) { statusEl.hidden = false; statusEl.textContent = err.message; })
      .then(function () { button.disabled = false; });
  });

  /* ---------- «Usar esta» ---------- */
  out.addEventListener('click', function (e) {
    var b = e.target.closest('[data-use]'); if (!b) return;
    st.sel = Number(b.getAttribute('data-use'));
    var o = current(), usd = offerUsd(o);
    st.cost = usd ? String(usd).replace('.', ',') : ''; st.qty = 1; st.mode = 'fixed'; st.value = '3'; st.ship = '';
    out.querySelectorAll('.cvnb-offer').forEach(function (li) { li.classList.toggle('is-on', Number(li.getAttribute('data-i')) === st.sel); });
    if (st.data.id) { var fd = new FormData(); fd.append('id', st.data.id); fd.append('index', st.sel); post('/elegir', fd).catch(function () {}); }
    renderUse();
    use.hidden = false;
    use.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  function renderUse() {
    var o = current(); if (!o) return;
    var phone = waPhone(o.phone), cvOk = !!st.data.id;
    var modes = [['fixed', 'Sumar USD'], ['percent', 'Sumar %'], ['final', 'Precio final']];
    use.innerHTML =
      '<section class="cvga-card cvnb-chosen"><span class="cvnb-kick">Elegiste</span><div class="cvga-row"><b class="cvnb-price">' + esc(o.price || 'Precio no visible') + '</b><button type="button" class="cvnb-link" data-act="change">Cambiar</button></div><p>' + esc(o.title) + '</p><small class="cvga-note">' + esc([o.location, o.source].filter(Boolean).join(' · ')) + '</small></section>' +

      '<section class="cvga-card cvnb-step" data-step="precio"><h2><span class="cvnb-n">1</span>Define tu precio</h2>' +
      '<div class="cvnb-grid">' +
      '<label class="cvga-field"><span>Precio del proveedor (USD)</span><input data-f="cost" type="text" inputmode="decimal" value="' + esc(st.cost) + '" placeholder="Ej.: 9"></label>' +
      '<label class="cvga-field"><span>Cantidad</span><input data-f="qty" type="number" inputmode="numeric" min="1" max="99" value="' + st.qty + '"></label></div>' +
      (o.currency === 'CUP' || /CUP/i.test(o.price || '') ? '<p class="cvga-note">El anuncio está en CUP: lo pasé a USD con la tasa de hoy' + (cupRate ? ' (1 USD = ' + esc(fmtCup(cupRate)) + ')' : '') + '.</p>' : '') +
      '<div class="cvnb-seg" role="group" aria-label="Cómo pones tu ganancia">' + modes.map(function (m) { return '<button type="button" data-mode="' + m[0] + '" aria-pressed="' + (st.mode === m[0]) + '">' + m[1] + '</button>'; }).join('') + '</div>' +
      '<div class="cvnb-grid"><label class="cvga-field"><span data-value-label></span><input data-f="value" type="text" inputmode="decimal" value="' + esc(st.value) + '"></label>' +
      '<label class="cvga-field"><span>Mensajería (CUP) <small>(opcional)</small></span><input data-f="ship" type="text" inputmode="numeric" value="' + esc(st.ship) + '" placeholder="Ej.: 500"></label></div>' +
      '<div class="cvnb-total" data-total aria-live="polite"></div></section>' +

      '<p class="cvnb-split">¿Cómo lo vendes? <b>Por tu cuenta</b> (pasos 2, 3 y 4) o <b>por Casa Viva</b> (paso 5). Tú eliges.</p>' +

      '<section class="cvga-card cvnb-step"><h2><span class="cvnb-n">2</span>Pregúntale al proveedor</h2><p class="cvga-note">Antes de ofrecerlo, confirma que lo tiene y si hace entrega.</p><div class="cvga-actions">' +
      (phone ? '<a class="cvga-btn" data-wa href="#" target="_blank" rel="noopener">Escribir por WhatsApp</a>' : '') +
      (o.url ? '<a class="cvga-btn' + (phone ? ' cvga-btn-soft' : '') + '" href="' + esc(o.url) + '" target="_blank" rel="noopener nofollow">Abrir anuncio</a>' : '') +
      (!phone && !o.url ? '<p class="cvga-empty">Este anuncio no trae teléfono ni enlace.</p>' : '') + '</div>' +
      (phone ? '<small class="cvga-note" data-wa-text></small>' : '') + '</section>' +

      '<section class="cvga-card cvnb-step"><h2><span class="cvnb-n">3</span>Haz una foto bonita</h2>' +
      (cfg.estudio && st.file ? '<p class="cvga-note">Llevo tu foto a «Mi estudio de fotos». Cuando la hagas, vuelves aquí con ella para mandársela al cliente.</p><div class="cvga-actions"><button type="button" class="cvga-btn cvga-btn-soft" data-act="pretty">Hacer foto bonita</button></div>' :
        '<p class="cvga-note">' + (cfg.estudio ? 'Para esto hace falta buscar con una foto.' : 'El estudio de fotos todavía no está abierto en tu cuenta.') + '</p>') +
      '<div data-pretty></div></section>' +

      '<section class="cvga-card cvnb-step"><h2><span class="cvnb-n">4</span>Mándaselo a tu cliente</h2>' +
      '<div class="cvnb-seg" role="group" aria-label="Para quién es el texto"><button type="button" data-aud="client" aria-pressed="' + (st.audience === 'client') + '">Para el cliente</button><button type="button" data-aud="me" aria-pressed="' + (st.audience === 'me') + '">Para mí</button></div>' +
      '<textarea class="cvnb-msg" data-msg rows="8"></textarea>' +
      '<div class="cvga-actions"><button type="button" class="cvga-btn" data-act="share">Compartir</button><button type="button" class="cvga-btn cvga-btn-soft" data-act="copy">Copiar</button><a class="cvga-btn cvga-btn-soft" data-save hidden download>Guardar foto</a></div>' +
      '<small class="cvga-note" data-msg-done role="status"></small></section>' +

      '<section class="cvga-card cvnb-step cvnb-cv"><h2><span class="cvnb-n">5</span>O véndelo por Casa Viva <small>(bajo pedido)</small></h2>' +
      '<p class="cvga-note">Casa Viva lo comprueba con el proveedor y te avisa por WhatsApp. <b>No le confirmes nada a tu cliente hasta que te avisemos.</b> La comisión te la dice Casa Viva al confirmar el precio.</p>' +
      (cvOk ? '<form data-cv-form><label class="cvga-field"><span>Cliente <small>(nombre o teléfono, opcional)</small></span><input name="client" type="text" maxlength="120" placeholder="Ej.: Marta 5xxxxxxx"></label>' +
        '<label class="cvga-field"><span>Nota <small>(opcional)</small></span><input name="note" type="text" maxlength="300" placeholder="Ej.: la quiere en azul, entrega en Playa"></label>' +
        '<p class="cvnb-cv-sum" data-cv-sum></p><div class="cvga-actions"><button class="cvga-btn" type="submit">Mandar solicitud a Casa Viva</button></div><p class="cvga-busca-status" data-cv-status role="status" hidden></p></form>'
        : '<p class="cvga-empty">Esta búsqueda no se guardó. Busca otra vez para poder pedirlo.</p>') + '</section>';
    renderNumbers();
  }

  function renderNumbers() {
    var o = current(); if (!o) return;
    var lab = use.querySelector('[data-value-label]');
    if (lab) lab.textContent = st.mode === 'percent' ? 'Tu ganancia (%)' : st.mode === 'final' ? 'Precio final por unidad (USD)' : 'Tu ganancia por unidad (USD)';
    var r = offerNow(), box = use.querySelector('[data-total]');
    if (!r) box.innerHTML = '<p class="cvga-empty">Escribe el precio del proveedor en USD para ver cuánto cobrar.</p>';
    else {
      var each = st.qty > 1 ? ' <small>(' + esc(fmtUsd(r.unit)) + ' cada uno)</small>' : '';
      box.innerHTML = '<div class="cvnb-big"><span>Cliente paga</span><b>' + esc(fmtUsd(r.productTotal)) + '</b>' + each + (cupRate ? '<small>≈ ' + esc(fmtCup(r.productTotal * cupRate + r.delivery)) + (r.delivery ? ' con la mensajería' : '') + '</small>' : '') + '</div>' +
        '<div class="cvnb-big is-gain"><span>Tú ganas</span><b>' + esc(fmtUsd(r.earning)) + '</b>' + (cupRate ? '<small>≈ ' + esc(cup(r.earning)) + '</small>' : '') + '</div>' +
        (r.delivery ? '<p class="cvga-note cvnb-wide">La mensajería (' + esc(fmtCup(r.delivery)) + ') es del mensajero: no es ganancia.</p>' : '') +
        (num(st.value) === 0 && st.mode !== 'final' ? '<p class="cvga-warn cvnb-wide">Así no ganas nada: suma algo a tu precio.</p>' : '') +
        (cupRate ? '' : '<p class="cvga-note cvnb-wide">Aún no hay tasa del día para pasarlo a CUP.</p>');
    }
    var wa = use.querySelector('[data-wa]');
    if (wa) {
      var t = 'Hola, vi su anuncio de ' + productName() + (o.price ? ' a ' + o.price : '') + '. ¿Lo tiene disponible? ¿Hace entrega en ' + (form.elements.zone.value || cfg.zone || 'La Habana') + '?';
      wa.href = 'https://wa.me/' + waPhone(o.phone) + '?text=' + encodeURIComponent(t);
      var wt = use.querySelector('[data-wa-text]'); if (wt) wt.textContent = '«' + t + '»';
    }
    var msg = use.querySelector('[data-msg]');
    if (msg && !msg._edited) msg.value = message(r);
    var sum = use.querySelector('[data-cv-sum]');
    if (sum) sum.innerHTML = r ? 'Pides: <b>' + esc(productName()) + '</b>' + (st.qty > 1 ? ' × ' + st.qty : '') + ' · tu precio <b>' + esc(fmtUsd(r.unit)) + '</b>' + (st.qty > 1 ? ' cada uno' : '') + ' · proveedor ' + esc(fmtUsd(num(st.cost))) : 'Primero pon el precio del proveedor en el paso 1.';
  }

  function message(r) {
    var o = current(), name = productName();
    if (st.audience === 'me') {
      return name + '\nPrecio del anuncio: ' + (o.price || 'no visible') + '\nZona: ' + (o.location || 'no indicada') + '\nFuente: ' + (o.source || 'web') + (o.phone ? '\nContacto: ' + o.phone : '') + (o.url ? '\n' + o.url : '') +
        '\n\nConfirma disponibilidad, precio y modelo antes de ofrecer.' + (r ? '\nLe cobro: ' + fmtUsd(r.productTotal) + (cupRate ? ' (' + cup(r.productTotal) + ')' : '') + ' · Gano: ' + fmtUsd(r.earning) + '.' : '');
    }
    var price = r ? fmtUsd(r.unit) + (cupRate ? ' (' + cup(r.unit) + ')' : '') + (st.qty > 1 ? ' cada uno' : '') : 'por confirmar';
    return 'Hola 👋 Te encontré lo que buscabas:\n\n*' + name + '*' + (st.qty > 1 ? ' × ' + st.qty : '') + '\nPrecio: *' + price + '*' +
      (r && r.delivery ? '\nMensajería: ' + fmtCup(r.delivery) + ' (se le paga al mensajero)' : '') +
      '\n\nSi te interesa, te lo reservo. Antes de cobrarte te confirmo que está disponible.';
  }

  use.addEventListener('input', function (e) {
    var f = e.target.getAttribute && e.target.getAttribute('data-f');
    if (f === 'cost') st.cost = e.target.value;
    else if (f === 'qty') st.qty = Math.max(1, Math.min(99, parseInt(e.target.value, 10) || 1));
    else if (f === 'value') st.value = e.target.value;
    else if (f === 'ship') st.ship = e.target.value;
    else if (e.target.hasAttribute && e.target.hasAttribute('data-msg')) { e.target._edited = true; return; }
    else return;
    renderNumbers();
  });

  use.addEventListener('click', function (e) {
    var m = e.target.closest('[data-mode]');
    if (m) {
      st.mode = m.getAttribute('data-mode');
      var r = offerNow();
      st.value = st.mode === 'percent' ? '30' : st.mode === 'final' ? (r ? String(Math.ceil(r.unit)) : '') : '3';
      use.querySelectorAll('[data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === m)); });
      var vi = use.querySelector('[data-f=value]'); vi.value = st.value; vi.focus();
      renderNumbers(); return;
    }
    var a = e.target.closest('[data-aud]');
    if (a) {
      st.audience = a.getAttribute('data-aud');
      use.querySelectorAll('[data-aud]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === a)); });
      var msg = use.querySelector('[data-msg]'); msg._edited = false; renderNumbers(); return;
    }
    var act = e.target.closest('[data-act]'); if (!act) return;
    var what = act.getAttribute('data-act'), done = use.querySelector('[data-msg-done]'), text = use.querySelector('[data-msg]').value;
    if (what === 'change') { out.scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    if (what === 'copy') { copy(text, function () { done.textContent = 'Copiado. Pégalo en el chat de tu cliente.'; }); return; }
    if (what === 'share') { share(text, done); return; }
    if (what === 'pretty') { toStudio(); }
  });

  function share(text, done) {
    var plain = function () {
      if (navigator.share) { navigator.share({ text: text }).catch(function (err) { if (err && err.name !== 'AbortError') copy(text, function () { done.textContent = 'Copiado. Pégalo en el chat de tu cliente.'; }); }); }
      else window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    };
    if (!st.pretty || !navigator.canShare || !window.File) { plain(); return; }
    done.textContent = 'Preparando la foto…';
    fetch(st.pretty.url, { credentials: 'same-origin' }).then(function (r) { return r.blob(); }).then(function (blob) {
      var file = new File([blob], st.pretty.name || 'foto.jpg', { type: blob.type || 'image/jpeg' });
      if (!navigator.canShare({ files: [file] })) throw new Error('no');
      done.textContent = '';
      return navigator.share({ files: [file], text: text });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { done.textContent = ''; return; }
      done.textContent = 'Tu móvil no deja compartir la foto con el texto: toca «Guardar foto» y mándala aparte.';
      plain();
    });
  }

  /* ---------- 3. foto bonita: ir al estudio y volver ---------- */
  var studio = document.querySelector('[data-cves]'), back = null;
  function toStudio() {
    var sf = studio && studio.querySelector('[data-cves-form]');
    if (!sf || !st.file) return;
    try {
      var dt = new DataTransfer(); dt.items.add(st.file);
      sf.elements.photo.files = dt.files;
      sf.elements.photo.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (ignore) {}
    if (sf.elements.text && !sf.elements.text.value) { sf.elements.text.value = productName(); sf.elements.text.dispatchEvent(new Event('change', { bubbles: true })); }
    if (!back) {
      back = document.createElement('div'); back.className = 'cvnb-back';
      back.innerHTML = '<span data-back-text>Vienes de NEXO Busca: tu foto ya está puesta. Elige una idea y crea la foto.</span><button type="button" class="cvga-btn cvga-btn-sm">← Volver a NEXO Busca</button>';
      back.querySelector('button').addEventListener('click', goBack);
      var stView = studio.closest('[data-view]') || studio;
      stView.insertBefore(back, stView.firstChild);
    }
    back.hidden = false;
    window.location.hash = 'estudio';
  }
  function goBack() {
    if (back) back.hidden = true;
    window.location.hash = 'buscar';
    setTimeout(function () { var p = use.querySelector('[data-pretty]'); if (p) p.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 120);
  }
  document.addEventListener('cvd:estudio-foto', function (e) {
    if (!back || back.hidden || !e.detail || !e.detail.url) return;
    st.pretty = e.detail;
    back.querySelector('[data-back-text]').textContent = '¡Lista! Vuelve a NEXO Busca para mandársela a tu cliente.';
    var box = use.querySelector('[data-pretty]');
    if (box) box.innerHTML = '<img class="cvnb-pretty" src="' + esc(st.pretty.url) + '" alt="Tu foto bonita">';
    var save = use.querySelector('[data-save]');
    if (save) { save.href = st.pretty.url; save.setAttribute('download', st.pretty.name || 'foto.jpg'); save.hidden = false; }
    var sb = use.querySelector('[data-act=share]'); if (sb) sb.textContent = 'Compartir con la foto';
  });

  /* ---------- 5. vender por Casa Viva ---------- */
  use.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-cv-form]'); if (!f) return;
    e.preventDefault();
    var r = offerNow(), status = f.querySelector('[data-cv-status]');
    if (!r) { status.hidden = false; status.textContent = 'Primero pon el precio del proveedor en el paso 1.'; return; }
    var key = st.data.id + ':' + st.sel;
    if (st.sent[key] && !window.confirm('Ya mandaste esta solicitud. ¿Mandarla otra vez?')) return;
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
    status.hidden = false; status.textContent = 'Mandando…';
    var fd = new FormData();
    fd.append('id', st.data.id); fd.append('index', st.sel); fd.append('product', productName());
    fd.append('price_usd', String(r.unit)); fd.append('supplier_usd', num(st.cost) ? String(num(st.cost)) : ''); fd.append('quantity', String(st.qty));
    fd.append('client', f.elements.client.value); fd.append('note', f.elements.note.value);
    post('/pedido', fd).then(function (d) {
      st.sent[key] = true;
      status.textContent = 'Enviada (solicitud #' + d.pedido.id + '). Casa Viva lo comprueba con el proveedor y te avisa por WhatsApp. Todavía no le confirmes nada a tu cliente.';
      renderMine(d.pedidos);
    }).catch(function (err) { status.textContent = err.message; })
      .then(function () { btn.disabled = false; });
  });

  /* ---------- Mis solicitudes ---------- */
  var stLabel = { pendiente: ['Pendiente', 'Casa Viva lo está comprobando con el proveedor'], confirmada: ['Confirmada', ''], no_hay: ['No hay', 'Ahora mismo no lo podemos conseguir'] };
  function renderMine(list) {
    list = list || [];
    mine.hidden = !list.length;
    mineList.innerHTML = list.map(function (p) {
      var s = stLabel[p.status] || [p.status, ''];
      var detail = p.status === 'confirmada' ? 'Precio: ' + fmtUsd(p.final != null ? p.final : p.price) + (p.commission != null ? ' · tu comisión ' + fmtUsd(p.commission) : '') : s[1];
      return '<li class="cvnb-item">' + (p.photo ? '<img src="' + esc(p.photo) + '" alt="" width="52" height="52" loading="lazy">' : '<span class="cvnb-noimg"></span>') +
        '<div><b>' + esc(p.product) + (p.quantity > 1 ? ' × ' + p.quantity : '') + '</b><small class="cvga-note">#' + p.id + ' · ' + esc(p.date) + (p.client ? ' · ' + esc(p.client) : '') + ' · tu precio ' + esc(fmtUsd(p.price)) + '</small>' +
        '<small>' + esc(detail) + (p.adminNote ? ' · ' + esc(p.adminNote) : '') + '</small></div><span class="cvga-chip cvnb-st-' + esc(p.status) + '">' + esc(s[0]) + '</span></li>';
    }).join('');
  }
  renderMine(cfg.pedidos);
})();
