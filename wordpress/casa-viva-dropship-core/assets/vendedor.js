/* «Mis artículos» (3.14.87): la tienda propia de un vendedor. Crear, editar, pausar y borrar SUS artículos desde el móvil,
   con fotos (cámara o galería), y apuntar sus ventas a mano. No mueve dinero. */
(function () {
  'use strict';
  var root = document.querySelector('[data-cvsl]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(document.querySelector('[data-cvsl-config]').textContent) || {}; } catch (ignore) {}
  var $ = function (sel) { return root.querySelector(sel); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
  var money = function (n) { return (Number(n) || 0).toFixed(2).replace('.', ',') + ' USD'; };
  var state = { items: cfg.items || [], sales: cfg.sales || [], summary: cfg.summary || {}, refs: cfg.refs || [], store: cfg.store || {} };
  var toastEl = $('[data-cvsl-toast]'), toastTimer = 0;

  function toast(text, bad) {
    toastEl.hidden = false; toastEl.textContent = text; toastEl.classList.toggle('is-bad', !!bad);
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4200);
  }
  function api(path, body, method) {
    var opts = { method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if (body instanceof FormData) opts.body = body;
    else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    return fetch(cfg.base + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) throw new Error((d && d.message) || ('Algo falló (código ' + r.status + '). Prueba otra vez.'));
        return d;
      });
    }, function () { throw new Error('Sin conexión. Revisa tus datos o el wifi y prueba otra vez.'); });
  }

  /* ---------- tienda: enlace y datos ---------- */
  function renderStore() {
    var s = state.store, link = s.url, total = 0;
    state.items.forEach(function (i) { total += i.consultas || 0; });
    $('[data-cvsl-store]').innerHTML =
      '<h2>' + esc(s.name) + '</h2><p class="cvga-note">Esta es tu tienda. Manda el enlace a tus clientes: cada artículo tiene su botón «Pedir por WhatsApp».</p>' +
      '<div class="cvga-link-box"><input id="cvsl-link" readonly value="' + esc(link) + '" aria-label="Enlace de tu tienda"><button class="cvga-btn cvga-btn-sm" type="button" data-cvsl-copy>Copiar</button></div>' +
      '<div class="cvga-actions"><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="https://wa.me/?text=' + encodeURIComponent('Mira mis piezas en mi tienda de Casa Viva: ' + link) + '" target="_blank" rel="noopener">Mandar por WhatsApp</a><a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="' + esc(link) + '" target="_blank" rel="noopener">Ver mi tienda</a></div>' +
      '<p class="cvga-note">' + total + ' personas han tocado «Pedir por WhatsApp».</p>' +
      '<details class="cvsl-more"><summary>Cambiar el nombre y el WhatsApp de mi tienda</summary>' +
      '<form data-cvsl-settings><label class="cvga-field"><span>Nombre de mi tienda</span><input name="name" maxlength="40" required value="' + esc(s.name) + '"></label>' +
      '<label class="cvga-field"><span>WhatsApp para mis clientes</span><input name="whatsapp" inputmode="tel" placeholder="5XXXXXXX" value="' + esc(s.whatsapp || '') + '"></label>' +
      '<p class="cvga-note">Si lo dejas vacío, usamos el WhatsApp de tu cuenta.</p><div class="cvga-actions"><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="submit">Guardar</button></div></form></details>';
  }

  /* ---------- lista ---------- */
  function renderList() {
    var box = $('[data-cvsl-list]');
    if (!state.items.length) { box.innerHTML = '<section class="cvga-card"><p class="cvga-empty">Aún no tienes artículos. Toca «+ Nuevo artículo»: foto, nombre y precio, y listo.</p></section>'; return; }
    box.innerHTML = '<div class="cvsl-grid">' + state.items.map(function (i) {
      var photo = i.photos[0] ? '<img src="' + esc(i.photos[0].url) + '" alt="" loading="lazy">' : '<span class="cvsl-noimg">Sin foto</span>';
      var tags = [];
      if (i.paused) tags.push('<span class="cvga-chip">Pausado</span>');
      if (i.adminHidden) tags.push('<span class="cvga-chip is-bad">Oculto por Casa Viva</span>');
      if (i.stock === 0) tags.push('<span class="cvga-chip">Agotado</span>');
      if (i.offer) tags.push('<span class="cvga-chip is-good">Gestoras ganan ' + money(i.gestoraUsd) + '</span>');
      return '<article class="cvga-card cvsl-card" data-id="' + i.id + '">' + photo + '<h2>' + esc(i.title) + '</h2><p class="cvsl-price">' + (i.price >= 0.5 ? money(i.price) : '⚠️ Ponle precio') + '</p>' +
        '<p class="cvga-note">' + (i.stock == null ? 'Sin contar existencias' : 'Quedan ' + i.stock) + ' · ' + i.consultas + ' consultas</p><div class="cvsl-tags">' + tags.join('') + '</div>' +
        '<div class="cvga-actions"><button class="cvga-btn cvga-btn-sm" type="button" data-cvsl-edit="' + i.id + '">Editar</button>' +
        '<button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvsl-pause="' + i.id + '">' + (i.paused ? 'Activar' : 'Pausar') + '</button>' +
        '<button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvsl-del="' + i.id + '">Borrar</button></div></article>';
    }).join('') + '</div>';
  }

  /* ---------- formulario ---------- */
  var sheet = $('[data-cvsl-sheet]'), formBox = $('[data-cvsl-form]');
  var photos = [];

  function openForm(item) {
    var i = item || { id: 0, title: '', description: '', compat: '', price: '', stock: null, category: '', photos: [], paused: false, offer: false, gestoraUsd: '', delivery: 'yo', pay: ['coordinar'], wantGeneral: false };
    photos = (i.photos || []).slice();
    var cats = (cfg.cats || []).map(function (c) { return '<option' + (c === i.category ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
    var del = Object.keys(cfg.delivery || {}).map(function (k) { return '<option value="' + k + '"' + (k === i.delivery ? ' selected' : '') + '>' + esc(cfg.delivery[k]) + '</option>'; }).join('');
    var pay = Object.keys(cfg.pay || {}).map(function (k) { return '<label class="cvsl-check"><input type="checkbox" name="pay" value="' + k + '"' + ((i.pay || []).indexOf(k) > -1 ? ' checked' : '') + '> ' + esc(cfg.pay[k]) + '</label>'; }).join('');
    formBox.innerHTML =
      '<form data-cvsl-item novalidate><h2>' + (i.id ? 'Editar artículo' : 'Nuevo artículo') + '</h2><input type="hidden" name="id" value="' + i.id + '">' +
      '<div class="cvsl-photos" data-cvsl-photos></div>' +
      '<div class="cvga-actions"><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvsl-cam>📷 Tomar foto</button><button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvsl-gal>🖼️ De la galería</button></div>' +
      '<input type="file" accept="image/*" capture="environment" hidden data-cvsl-file-cam><input type="file" accept="image/*" multiple hidden data-cvsl-file-gal>' +
      '<p class="cvga-note">Hasta 5 fotos. La primera es la principal. Con buena luz y fondo liso se vende más.</p>' +
      '<label class="cvga-field"><span>Nombre del artículo</span><input name="title" maxlength="90" required value="' + esc(i.title) + '" placeholder="Ej.: Pastillas de freno Suzuki GN125"></label>' +
      '<label class="cvga-field"><span>Sirve para (marca y modelo de moto)</span><input name="compat" maxlength="120" value="' + esc(i.compat) + '" placeholder="Ej.: Suzuki GN125, Honda CG150"></label>' +
      '<label class="cvga-field"><span>Descripción</span><textarea name="description" rows="4" maxlength="1500" placeholder="Para qué moto sirve, estado, medidas…">' + esc(i.description) + '</textarea></label>' +
      '<div class="cvsl-two"><label class="cvga-field"><span>Precio (USD)</span><input name="price" inputmode="decimal" required value="' + (Number(i.price) >= 0.5 ? esc(i.price) : '') + '" placeholder="0,00"></label>' +
      '<label class="cvga-field"><span>Cuántos tienes</span><input name="stock" inputmode="numeric" value="' + (i.stock == null ? '' : esc(i.stock)) + '" placeholder="vacío = no cuento"></label></div>' +
      '<label class="cvga-field"><span>Categoría</span><select name="category"><option value="">Elige una</option>' + cats + '</select></label>' +
      '<fieldset class="cvsl-set"><legend>¿Dónde se ve?</legend><label class="cvsl-check"><input type="radio" checked disabled> Solo en mi tienda</label>' +
      '<label class="cvsl-check' + (cfg.generalOpen ? '' : ' is-locked') + '"><input type="checkbox" name="wantGeneral"' + (i.wantGeneral ? ' checked' : '') + (cfg.generalOpen ? '' : ' disabled') + '> También en Casa Viva en general</label>' +
      (cfg.generalOpen ? '' : '<p class="cvga-note">Esto se abre cuando hables con Ernesto de la comisión de Casa Viva.</p>') + '</fieldset>' +
      '<fieldset class="cvsl-set"><legend>Gestoras</legend><label class="cvsl-check"><input type="checkbox" name="offer"' + (i.offer ? ' checked' : '') + '> Ofrecerlo a las gestoras</label>' +
      '<label class="cvga-field" data-cvsl-gest' + (i.offer ? '' : ' hidden') + '><span>Cuánto gana cada gestora por unidad (USD)</span><input name="gestoraUsd" inputmode="decimal" value="' + esc(i.gestoraUsd || '') + '" placeholder="Ej.: 2"></label>' +
      '<p class="cvga-note">Tú pagas esa ganancia a la gestora; aquí solo se apunta.</p></fieldset>' +
      '<fieldset class="cvsl-set"><legend>Entrega</legend><select name="delivery">' + del + '</select></fieldset>' +
      '<fieldset class="cvsl-set"><legend>Cómo se cobra</legend>' + pay + '</fieldset>' +
      '<label class="cvsl-check"><input type="checkbox" name="paused"' + (i.paused && Number(i.price) >= 0.5 ? ' checked' : '') + '> Guardarlo pausado (que nadie lo vea todavía)</label>' +
      '<p class="cvsl-error" data-cvsl-error role="alert" hidden></p>' +
      '<div class="cvga-actions"><button class="cvga-btn" type="submit" data-cvsl-save>Publicar</button><button class="cvga-btn cvga-btn-soft" type="button" data-cvsl-cancel>Cancelar</button></div></form>';
    sheet.hidden = false; document.body.classList.add('cvsl-open');
    renderPhotos();
    formBox.scrollTop = 0;
  }
  function closeForm() { sheet.hidden = true; document.body.classList.remove('cvsl-open'); formBox.innerHTML = ''; }

  function renderPhotos() {
    var box = formBox.querySelector('[data-cvsl-photos]');
    if (!box) return;
    box.innerHTML = photos.map(function (p, n) { return '<div class="cvsl-ph"><img src="' + esc(p.url) + '" alt=""><button type="button" data-cvsl-ph-del="' + n + '" aria-label="Quitar foto">✕</button>' + (n === 0 ? '<span>Principal</span>' : '<button type="button" class="cvsl-first" data-cvsl-ph-first="' + n + '">Hacer principal</button>') + '</div>'; }).join('');
  }

  // Reduce la foto en el teléfono (máx. 1400 px, JPEG) para gastar pocos datos.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!window.createImageBitmap && !window.URL) return resolve(file);
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var max = 1400, w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
        var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
        if (c.toBlob) c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.82); else resolve(file);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }
  function addFiles(list) {
    var files = Array.prototype.slice.call(list || []);
    if (!files.length) return;
    var room = 5 - photos.length;
    if (room <= 0) { toast('Ya tienes 5 fotos. Quita una para poner otra.', true); return; }
    files = files.slice(0, room);
    var save = formBox.querySelector('[data-cvsl-save]'); if (save) save.disabled = true;
    toast('Subiendo ' + (files.length === 1 ? 'la foto' : files.length + ' fotos') + '…');
    files.reduce(function (chain, f) {
      return chain.then(function () {
        return shrink(f).then(function (blob) {
          var fd = new FormData(); fd.append('photo', blob, 'foto.jpg');
          return api('/vendedor/foto', fd).then(function (d) { photos.push(d.photo); renderPhotos(); });
        }).catch(function (e) { toast(e.message, true); });
      });
    }, Promise.resolve()).then(function () { if (save) save.disabled = false; if (toastEl.textContent.indexOf('Subiendo') === 0) toast('Listo.'); });
  }

  function submitItem(form) {
    var err = formBox.querySelector('[data-cvsl-error]'), btn = form.querySelector('[data-cvsl-save]');
    var fd = new FormData(form);
    var body = {
      id: Number(fd.get('id')) || 0, title: fd.get('title'), description: fd.get('description'), price: fd.get('price'), stock: fd.get('stock'),
      category: fd.get('category'), compat: fd.get('compat'), photos: photos.map(function (p) { return p.id; }), offer: !!fd.get('offer'), gestoraUsd: fd.get('gestoraUsd'),
      delivery: fd.get('delivery'), pay: fd.getAll('pay'), paused: !!fd.get('paused'), wantGeneral: !!fd.get('wantGeneral')
    };
    err.hidden = true; btn.disabled = true; btn.textContent = 'Guardando…';
    api('/vendedor/articulos', body).then(function (d) {
      var at = state.items.findIndex(function (x) { return x.id === d.item.id; });
      if (at > -1) state.items[at] = d.item; else state.items.unshift(d.item);
      renderList(); renderStore(); closeForm(); toast(body.paused ? 'Guardado, pausado.' : 'Listo, ya se ve en tu tienda.');
    }).catch(function (e) { err.hidden = false; err.textContent = e.message; btn.disabled = false; btn.textContent = 'Publicar'; });
  }

  /* ---------- ventas ---------- */
  function renderSales() {
    var pane = $('[data-cvsl-pane="sales"]'), s = state.summary || {};
    var itemOpts = state.items.filter(function (i) { return !i.paused; }).map(function (i) { return '<option value="' + i.id + '" data-price="' + esc(i.price) + '">' + esc(i.title) + '</option>'; }).join('');
    var refs = (state.refs || []).map(function (r) { return '<option value="' + esc(r.code) + '">' + esc(r.name) + '</option>'; }).join('');
    var rows = state.sales.map(function (x) {
      return '<li class="' + (x.canceled ? 'is-off' : '') + '"><div><b>' + esc(x.title) + '</b> × ' + x.qty + ' · ' + money(x.total) + '<br><small>' + esc(x.date) + (x.buyer ? ' · ' + esc(x.buyer) : '') + (x.gestora ? ' · trajo: ' + esc(x.gestora) + ' (le debes ' + money(x.gestoraUsd) + ')' : '') + (x.canceled ? ' · ANULADA' : '') + '</small></div>' +
        (x.canceled ? '' : '<button class="cvga-btn cvga-btn-soft cvga-btn-sm" type="button" data-cvsl-cancel-sale="' + x.id + '">Anular</button>') + '</li>';
    }).join('');
    pane.innerHTML =
      '<section class="cvga-card"><h2>Este mes</h2><p><b>' + (s.count || 0) + '</b> ventas · <b>' + money(s.totalUsd) + '</b></p><p class="cvga-note">A las gestoras les debes <b>' + money(s.gestoraUsd) + '</b>. Comisión de Casa Viva: pendiente de hablar con Ernesto. Esto es solo tu libreta: aquí no se cobra ni se paga nada.</p></section>' +
      (itemOpts ? '<section class="cvga-card"><h2>Apuntar una venta</h2><form data-cvsl-sale novalidate>' +
        '<label class="cvga-field"><span>Artículo</span><select name="item">' + itemOpts + '</select></label>' +
        '<div class="cvsl-two"><label class="cvga-field"><span>Cuántos</span><input name="qty" inputmode="numeric" value="1"></label><label class="cvga-field"><span>Precio de cada uno (USD)</span><input name="unit" inputmode="decimal"></label></div>' +
        '<label class="cvga-field"><span>Cliente (opcional)</span><input name="buyer" maxlength="80" placeholder="Nombre o teléfono"></label>' +
        '<label class="cvga-field"><span>Si lo trajo una gestora: su código</span><input name="gestoraCode" list="cvsl-refs" autocapitalize="characters" placeholder="CV…"><datalist id="cvsl-refs">' + refs + '</datalist></label>' +
        '<p class="cvsl-error" data-cvsl-sale-error role="alert" hidden></p><div class="cvga-actions"><button class="cvga-btn" type="submit">Apuntar venta</button></div></form></section>' : '<section class="cvga-card"><p class="cvga-empty">Publica un artículo para poder apuntar ventas.</p></section>') +
      '<section class="cvga-card"><h2>Mis ventas</h2>' + (rows ? '<ul class="cvsl-sales">' + rows + '</ul>' : '<p class="cvga-empty">Aún no has apuntado ventas.</p>') + '</section>';
    var sel = pane.querySelector('select[name=item]'), unit = pane.querySelector('input[name=unit]');
    if (sel && unit) { var fill = function () { var o = sel.options[sel.selectedIndex]; unit.value = o ? o.getAttribute('data-price') : ''; }; sel.addEventListener('change', fill); fill(); }
  }

  /* ---------- eventos ---------- */
  root.addEventListener('click', function (e) {
    var t = e.target.closest('button, a'); if (!t) return;
    if (t.hasAttribute('data-cvsl-copy')) { var inp = $('#cvsl-link'); inp.select(); try { navigator.clipboard.writeText(inp.value); } catch (ignore) { document.execCommand('copy'); } toast('Enlace copiado.'); return; }
    var tab = t.getAttribute('data-cvsl-tab');
    if (tab) { root.querySelectorAll('[data-cvsl-tab]').forEach(function (b) { b.classList.toggle('is-on', b === t); }); root.querySelectorAll('[data-cvsl-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-cvsl-pane') !== tab; }); if (tab === 'sales') api('/vendedor/ventas').then(function (d) { state.sales = d.sales; state.summary = d.summary; state.refs = d.refs; renderSales(); }).catch(function (er) { toast(er.message, true); }); return; }
    if (t.hasAttribute('data-cvsl-new')) { openForm(null); return; }
    if (t.hasAttribute('data-cvsl-cancel')) { closeForm(); return; }
    if (t.hasAttribute('data-cvsl-cam')) { formBox.querySelector('[data-cvsl-file-cam]').click(); return; }
    if (t.hasAttribute('data-cvsl-gal')) { formBox.querySelector('[data-cvsl-file-gal]').click(); return; }
    var pd = t.getAttribute('data-cvsl-ph-del'); if (pd !== null) { photos.splice(Number(pd), 1); renderPhotos(); return; }
    var pf = t.getAttribute('data-cvsl-ph-first'); if (pf !== null) { photos.unshift(photos.splice(Number(pf), 1)[0]); renderPhotos(); return; }
    var ed = t.getAttribute('data-cvsl-edit'); if (ed) { openForm(state.items.find(function (x) { return x.id === Number(ed); })); return; }
    var ps = t.getAttribute('data-cvsl-pause');
    if (ps) { api('/vendedor/articulos/' + ps + '/pausa', {}).then(function (d) { var at = state.items.findIndex(function (x) { return x.id === d.item.id; }); state.items[at] = d.item; renderList(); toast(d.item.paused ? 'Pausado: nadie lo ve.' : 'Activado: ya se ve.'); }).catch(function (er) { toast(er.message, true); }); return; }
    var dl = t.getAttribute('data-cvsl-del');
    if (dl) { var it = state.items.find(function (x) { return x.id === Number(dl); }); if (!window.confirm('¿Borrar «' + (it ? it.title : 'este artículo') + '»? Ya no se verá en tu tienda.')) return; api('/vendedor/articulos/' + dl + '/borrar', {}).then(function () { state.items = state.items.filter(function (x) { return x.id !== Number(dl); }); renderList(); renderStore(); toast('Borrado.'); }).catch(function (er) { toast(er.message, true); }); return; }
    var cs = t.getAttribute('data-cvsl-cancel-sale');
    if (cs) { if (!window.confirm('¿Anular esta venta? El artículo vuelve a tu cuenta de existencias.')) return; api('/vendedor/ventas/' + cs + '/anular', {}).then(function () { return api('/vendedor/ventas'); }).then(function (d) { state.sales = d.sales; state.summary = d.summary; return api('/vendedor/articulos'); }).then(function (d) { state.items = d.items; renderList(); renderSales(); toast('Venta anulada.'); }).catch(function (er) { toast(er.message, true); }); }
  });
  root.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-cvsl-file-cam], [data-cvsl-file-gal]')) { addFiles(t.files); t.value = ''; return; }
    if (t.name === 'offer') { var g = formBox.querySelector('[data-cvsl-gest]'); if (g) g.hidden = !t.checked; }
  });
  root.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('[data-cvsl-item]')) { e.preventDefault(); submitItem(f); return; }
    if (f.matches('[data-cvsl-settings]')) {
      e.preventDefault(); var fd = new FormData(f);
      api('/vendedor/tienda', { name: fd.get('name'), whatsapp: fd.get('whatsapp') }).then(function (d) { state.store.name = d.name; state.store.whatsapp = d.whatsapp; renderStore(); toast('Guardado.'); }).catch(function (er) { toast(er.message, true); });
      return;
    }
    if (f.matches('[data-cvsl-sale]')) {
      e.preventDefault(); var s = new FormData(f), err = $('[data-cvsl-sale-error]'); err.hidden = true;
      api('/vendedor/ventas', { item: s.get('item'), qty: s.get('qty'), unit: s.get('unit'), buyer: s.get('buyer'), gestoraCode: s.get('gestoraCode') }).then(function (d) {
        state.summary = d.summary; var at = state.items.findIndex(function (x) { return x.id === d.item.id; }); if (at > -1) state.items[at] = d.item;
        state.sales.unshift(d.sale); renderList(); renderSales(); toast('Venta apuntada.');
      }).catch(function (er) { err.hidden = false; err.textContent = er.message; });
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) closeForm(); });

  renderStore(); renderList(); renderSales();
})();
