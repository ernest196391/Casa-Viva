/* «Mi estudio de fotos» (3.14.1): conectar la clave de Google, elegir producto, ideas, crear y galería. */
(function () {
  'use strict';
  var root = document.querySelector('[data-cves]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(root.querySelector('[data-cves-config]').textContent) || {}; } catch (ignore) {}
  var $ = function (sel) { return root.querySelector(sel); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
  var norm = function (v) { return String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };

  function api(path, body, method) {
    var opts = { method: method || 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if (body) opts.body = body;
    return fetch(cfg.base + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) throw new Error((d && d.message) || 'Algo falló. Prueba otra vez.');
        return d;
      });
    }, function () { throw new Error('Sin conexión. Revisa tus datos o el wifi y prueba otra vez.'); });
  }
  function say(el, text, bad) { el.hidden = !text; el.textContent = text || ''; el.classList.toggle('is-bad', !!bad); }

  /* ---------- clave ---------- */
  var keyBox = $('[data-cves-key]'), keyForm = $('[data-cves-key-form]'), keyOn = keyBox.querySelector('.cves-key-on'), keyMsg = $('[data-cves-key-msg]');
  var makeForm = $('[data-cves-form]'), galleryWrap = $('[data-cves-gallery-wrap]');
  function setKey(on, since) {
    cfg.hasKey = on; keyOn.hidden = !on; keyForm.hidden = on; makeForm.hidden = !on; keyBox.classList.toggle('is-on', on);
    if (on) galleryWrap.hidden = false;
    if (since) $('[data-cves-since]').textContent = 'Conectada · ' + since;
  }
  keyForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var input = keyForm.elements.key, btn = keyForm.querySelector('button[type=submit]');
    if (!input.value.trim()) return;
    btn.disabled = true; say(keyMsg, 'Comprobando tu clave con Google…');
    var fd = new FormData(); fd.append('key', input.value.trim());
    api('clave', fd).then(function (d) { input.value = ''; setKey(true, d.since); say(keyMsg, d.message); })
      .catch(function (err) { say(keyMsg, err.message, true); })
      .then(function () { btn.disabled = false; });
  });
  root.querySelector('[data-cves-key-remove]').addEventListener('click', function () {
    if (!window.confirm('¿Quitar tu clave de Google de Casa Viva? Tus fotos se quedan en tu galería.')) return;
    api('clave/quitar', new FormData()).then(function (d) { setKey(false); say(keyMsg, d.message); }).catch(function (err) { say(keyMsg, err.message, true); });
  });

  /* ---------- producto ---------- */
  var items = [];
  try { items = (JSON.parse(document.querySelector('[data-cvgf-data]').textContent) || {}).items || []; } catch (ignore) {}
  items.forEach(function (p) { p.q = norm(p.n); });
  var state = { pid: 0, colors: [], color: '', preset: '', vertical: false, promptEdited: false };
  var q = $('[data-cves-q]'), hits = $('[data-cves-hits]'), pick = $('[data-cves-pick]'), search = $('[data-cves-search]'), colorsBox = $('[data-cves-colors]');
  var timer;
  q.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var words = norm(q.value).split(/\s+/).filter(function (w) { return w.length > 1; });
      if (!words.length) { hits.innerHTML = ''; return; }
      var found = items.filter(function (p) { return words.every(function (w) { return p.q.indexOf(w.length > 4 ? w.slice(0, -1) : w) > -1; }); }).slice(0, 8);
      hits.innerHTML = found.length ? found.map(function (p) { return '<button type="button" class="cves-hit" data-pid="' + p.id + '"><img src="' + esc(p.i[0] && p.i[0][0]) + '" alt="" width="40" height="40" loading="lazy"><span>' + esc(p.n) + '<small>' + esc(p.p) + '</small></span></button>'; }).join('') : '<p class="cvga-empty">No encontré «' + esc(q.value) + '» en tu tienda.</p>';
    }, 150);
  });
  hits.addEventListener('click', function (e) {
    var b = e.target.closest('[data-pid]'); if (!b) return;
    choose(Number(b.getAttribute('data-pid')));
  });
  function choose(pid) {
    var p = items.filter(function (x) { return x.id === pid; })[0]; if (!p) return;
    state.pid = pid; state.color = ''; state.colors = []; dirty();
    pick.hidden = false; search.hidden = true; hits.innerHTML = ''; q.value = '';
    pick.querySelector('img').src = p.i[0] ? p.i[0][0] : '';
    $('[data-cves-pick-name]').textContent = p.n;
    var colorsNote = $('[data-cves-pick-colors]'); colorsNote.textContent = 'Mirando sus colores…';
    api('producto?id=' + pid, null, 'GET').then(function (d) {
      state.colors = d.colors || [];
      colorsNote.textContent = state.colors.length ? 'Colores: ' + state.colors.map(function (c) { return c.label; }).join(', ') : 'Un solo color (el de la foto)';
      renderColors();
    }).catch(function () { colorsNote.textContent = ''; });
  }
  $('[data-cves-pick-clear]').addEventListener('click', function () { state.pid = 0; state.colors = []; state.color = ''; pick.hidden = true; search.hidden = false; renderColors(); dirty(); q.focus(); });

  var photo = makeForm.elements.photo, preview = $('[data-cves-preview]');
  photo.addEventListener('change', function () { var f = photo.files && photo.files[0]; if (f) { preview.src = URL.createObjectURL(f); preview.hidden = false; } else { preview.hidden = true; } dirty(); });

  /* ---------- ideas y colores ---------- */
  root.querySelectorAll('[data-cves-idea]').forEach(function (b) {
    b.addEventListener('click', function () {
      var idea = b.getAttribute('data-cves-idea');
      if (idea === 'estado') { state.vertical = !state.vertical; b.setAttribute('aria-pressed', String(state.vertical)); dirty(); return; }
      state.preset = state.preset === idea ? '' : idea;
      root.querySelectorAll('[data-cves-idea]').forEach(function (o) { if (o.getAttribute('data-cves-idea') !== 'estado') o.setAttribute('aria-pressed', String(o.getAttribute('data-cves-idea') === state.preset)); });
      if (state.preset !== 'color') state.color = '';
      renderColors(); dirty();
    });
  });
  function renderColors() {
    if (state.preset !== 'color') { colorsBox.hidden = true; colorsBox.innerHTML = ''; return; }
    colorsBox.hidden = false;
    if (!state.pid) { colorsBox.innerHTML = '<p class="cvga-note">Primero elige el producto: solo puedo usar colores que tenemos de verdad.</p>'; return; }
    if (!state.colors.length) { colorsBox.innerHTML = '<p class="cvga-note">Este producto solo lo tenemos en el color de su foto.</p>'; return; }
    colorsBox.innerHTML = '<span class="cvga-note">Colores que tenemos:</span>' + state.colors.map(function (c) { return '<button type="button" class="cves-color" data-color="' + esc(c.label) + '" aria-pressed="' + (state.color === c.label) + '">' + (c.img ? '<img src="' + esc(c.img) + '" alt="" width="22" height="22">' : '') + esc(c.label) + '</button>'; }).join('');
  }
  colorsBox.addEventListener('click', function (e) { var b = e.target.closest('[data-color]'); if (!b) return; state.color = b.getAttribute('data-color'); renderColors(); dirty(); });

  /* ---------- instrucción plegada ---------- */
  var promptBox = $('[data-cves-prompt]'), promptText = $('[data-cves-prompt-text]'), text = makeForm.elements.text;
  function fields(fd) {
    fd.append('product_id', String(state.pid)); fd.append('preset', state.preset); fd.append('vertical', state.vertical ? '1' : '0');
    fd.append('color', state.color); fd.append('text', text.value);
    return fd;
  }
  function dirty() { if (!state.promptEdited) { promptText.value = ''; if (promptBox.open) loadPrompt(); } }
  function loadPrompt() {
    var fd = fields(new FormData()); fd.append('has_photo', photo.files && photo.files[0] ? '1' : '0');
    promptText.value = 'Armando la instrucción…';
    api('prompt', fd).then(function (d) { promptText.value = d.prompt; }).catch(function (err) { promptText.value = ''; say(msg, err.message, true); });
  }
  promptBox.addEventListener('toggle', function () { if (promptBox.open && !promptText.value) loadPrompt(); });
  promptText.addEventListener('input', function () { state.promptEdited = true; });
  text.addEventListener('change', dirty);

  /* ---------- crear ---------- */
  var msg = $('[data-cves-msg]'), result = $('[data-cves-result]'), left = $('[data-cves-left]');
  makeForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!state.pid && !(photo.files && photo.files[0])) { say(msg, 'Elige un producto de tu tienda o sube una foto del móvil.', true); return; }
    var btn = $('[data-cves-go]'); btn.disabled = true; result.innerHTML = '';
    say(msg, 'Creando tu foto con Google… tarda entre 15 y 60 segundos. No cierres esta página.');
    var fd = fields(new FormData());
    if (photo.files && photo.files[0]) fd.append('photo', photo.files[0]);
    if (state.promptEdited && promptText.value.trim()) fd.append('prompt', promptText.value);
    api('crear', fd).then(function (d) {
      say(msg, 'Lista. Está en tu galería.');
      if (typeof d.left === 'number') left.textContent = 'Te quedan ' + d.left + ' fotos hoy.';
      cfg.gallery.unshift(d.photo); renderGallery();
      result.innerHTML = card(d.photo, true);
    }).catch(function (err) { say(msg, err.message, true); })
      .then(function () { btn.disabled = false; });
  });

  /* ---------- galería ---------- */
  var gallery = $('[data-cves-gallery]'), empty = $('[data-cves-empty]'), count = $('[data-cves-count]');
  function card(p, big) {
    return '<article class="cves-item' + (big ? ' is-big' : '') + '" data-id="' + esc(p.id) + '"><a class="cves-img" href="' + esc(p.url) + '" target="_blank" rel="noopener"><img src="' + esc(p.url) + '" alt="' + esc(p.product || 'Foto') + '" loading="lazy" width="' + (p.w || 400) + '" height="' + (p.h || 400) + '"></a>' +
      '<div class="cves-item-body"><b>' + esc(p.product || 'Foto del móvil') + (p.color ? ' · ' + esc(p.color) : '') + '</b><small class="cvga-note">' + esc(p.date) + (p.text ? ' · «' + esc(p.text) + '»' : '') + '</small>' +
      (p.inStore ? '<span class="cvga-chip is-good">En tu tienda</span>' : '') +
      '<div class="cvga-actions"><a class="cvga-btn cvga-btn-sm" href="' + esc(p.url) + '" download="' + esc(p.name) + '">Descargar</a><button type="button" class="cvga-btn cvga-btn-soft cvga-btn-sm" data-act="share">Compartir</button>' +
      (p.pid ? (p.inStore ? '<button type="button" class="cvga-btn cvga-btn-soft cvga-btn-sm" data-act="unstore">Quitar de mi tienda</button>' + (p.storeLink ? '<a class="cvga-btn cvga-btn-soft cvga-btn-sm" href="' + esc(p.storeLink) + '" target="_blank" rel="noopener">Verla</a>' : '') : '<button type="button" class="cvga-btn cvga-btn-soft cvga-btn-sm" data-act="store">Poner en mi tienda</button>') : '') +
      '<button type="button" class="cves-del" data-act="del" aria-label="Borrar foto">Borrar</button></div><small class="cves-done" role="status"></small></div></article>';
  }
  function renderGallery() {
    gallery.innerHTML = cfg.gallery.map(function (p) { return card(p, false); }).join('');
    empty.hidden = cfg.gallery.length > 0;
    count.textContent = cfg.gallery.length + ' de ' + cfg.maxPhotos;
  }
  function find(id) { return cfg.gallery.filter(function (p) { return p.id === id; })[0]; }
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b) return;
    var el = b.closest('.cves-item'), p = el && find(el.getAttribute('data-id')); if (!p) return;
    var done = el.querySelector('.cves-done'), act = b.getAttribute('data-act');
    if (act === 'share') {
      var shareText = (p.product ? p.product + (p.storeLink ? ' — pídelo aquí: ' + p.storeLink : '') : '');
      if (navigator.canShare && window.File) {
        fetch(p.url).then(function (r) { return r.blob(); }).then(function (blob) {
          var file = new File([blob], p.name, { type: 'image/jpeg' });
          if (navigator.canShare({ files: [file] })) return navigator.share({ files: [file], text: shareText });
          throw new Error('no');
        }).catch(function (err) { if (err && err.name === 'AbortError') return; window.open('https://wa.me/?text=' + encodeURIComponent((shareText ? shareText + ' ' : '') + p.url), '_blank'); });
      } else { window.open('https://wa.me/?text=' + encodeURIComponent((shareText ? shareText + ' ' : '') + p.url), '_blank'); }
      return;
    }
    if (act === 'del') {
      if (!window.confirm('¿Borrar esta foto de tu galería?' + (p.inStore ? ' También se quita de tu tienda.' : ''))) return;
      var fdd = new FormData(); fdd.append('id', p.id);
      api('borrar', fdd).then(function () { cfg.gallery = cfg.gallery.filter(function (x) { return x.id !== p.id; }); renderGallery(); if (result.querySelector('[data-id="' + p.id + '"]')) result.innerHTML = ''; }).catch(function (err) { done.textContent = err.message; });
      return;
    }
    if (act === 'store' || act === 'unstore') {
      b.disabled = true; done.textContent = act === 'store' ? 'Poniendo la foto en tu tienda…' : 'Quitando…';
      var fds = new FormData(); fds.append('id', p.id); if (act === 'unstore') fds.append('quitar', '1');
      api('tienda', fds).then(function (d) {
        cfg.gallery.forEach(function (x) { if (x.pid === p.pid) x.inStore = false; });
        p.inStore = !!d.in_store; if (d.link) p.storeLink = d.link;
        renderGallery();
        if (result.firstChild) result.innerHTML = card(find(result.firstChild.getAttribute('data-id')) || p, true);
        var again = root.querySelector('.cves-item[data-id="' + p.id + '"] .cves-done'); if (again) again.textContent = d.message;
      }).catch(function (err) { done.textContent = err.message; b.disabled = false; });
    }
  });
  renderGallery();
})();
