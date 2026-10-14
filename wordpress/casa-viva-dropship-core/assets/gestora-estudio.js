/* «Mi estudio de fotos» (3.14.1; 3.14.3 modo Gemini gratis): elegir producto e idea, hacer la foto gratis en la
   app de Gemini (copiar instrucción, guardar foto base, abrir Gemini, subir la foto) o, con clave de Google con
   facturación, crearla aquí. Galería: descargar, compartir, poner en mi tienda. */
(function () {
  'use strict';
  var root = document.querySelector('[data-cves]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(root.querySelector('[data-cves-config]').textContent) || {}; } catch (ignore) {}
  var $ = function (sel) { return root.querySelector(sel); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
  var norm = function (v) { return String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  var isAndroid = /Android/i.test(navigator.userAgent || '');

  function api(path, body, method) {
    var opts = { method: method || 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
    if (body) opts.body = body;
    return fetch(cfg.base + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) { var e = new Error((d && d.message) || 'Algo falló. Prueba otra vez.'); e.data = (d && d.data) || {}; throw e; }
        return d;
      });
    }, function () { throw new Error('Sin conexión. Revisa tus datos o el wifi y prueba otra vez.'); });
  }
  function say(el, text, bad) { el.hidden = !text; el.textContent = text || ''; el.classList.toggle('is-bad', !!bad); }

  /* ---------- clave (opción avanzada) ---------- */
  var keyBox = $('[data-cves-key]'), keyForm = $('[data-cves-key-form]'), keyOn = keyBox.querySelector('.cves-key-on'), keyMsg = $('[data-cves-key-msg]');
  var makeForm = $('[data-cves-form]'), auto = $('[data-cves-auto]'), free = $('[data-cves-free]');
  function setKey(on, since) {
    cfg.hasKey = on; keyOn.hidden = !on; keyForm.hidden = on; auto.hidden = !on; keyBox.classList.toggle('is-on', on);
    showFree(!on);
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
  var state = { pid: 0, colors: [], color: '', preset: '', vertical: false, promptEdited: false, img: '' };
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
    state.pid = pid; state.color = ''; state.colors = []; state.img = p.i[0] ? p.i[0][0] : '';
    pick.hidden = false; search.hidden = true; hits.innerHTML = ''; q.value = '';
    pick.querySelector('img').src = state.img;
    $('[data-cves-pick-name]').textContent = p.n;
    var colorsNote = $('[data-cves-pick-colors]'); colorsNote.textContent = 'Mirando sus colores…';
    dirty();
    api('producto?id=' + pid, null, 'GET').then(function (d) {
      state.colors = d.colors || [];
      colorsNote.textContent = state.colors.length ? 'Colores: ' + state.colors.map(function (c) { return c.label; }).join(', ') : 'Un solo color (el de la foto)';
      renderColors();
    }).catch(function () { colorsNote.textContent = ''; });
  }
  $('[data-cves-pick-clear]').addEventListener('click', function () { state.pid = 0; state.colors = []; state.color = ''; state.img = ''; pick.hidden = true; search.hidden = false; renderColors(); dirty(); q.focus(); });

  var photo = makeForm.elements.photo, preview = $('[data-cves-preview]');
  function hasPhoto() { return !!(photo.files && photo.files[0]); }
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

  /* ---------- instrucción ---------- */
  var promptBox = $('[data-cves-prompt]'), promptText = $('[data-cves-prompt-text]'), text = makeForm.elements.text;
  function fields(fd) {
    fd.append('product_id', String(state.pid)); fd.append('preset', state.preset); fd.append('vertical', state.vertical ? '1' : '0');
    fd.append('color', state.color); fd.append('text', text.value);
    return fd;
  }
  function query(extra) {
    var p = new URLSearchParams({ product_id: String(state.pid), preset: state.preset, vertical: state.vertical ? '1' : '0', color: state.color, text: text.value, _wpnonce: cfg.nonce });
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return p.toString();
  }
  function dirty() {
    if (!state.promptEdited) { promptText.value = ''; if (promptBox.open) loadPrompt(); }
    freeDirty();
  }
  function loadPrompt() {
    var fd = fields(new FormData()); fd.append('has_photo', hasPhoto() ? '1' : '0');
    promptText.value = 'Armando la instrucción…';
    api('prompt', fd).then(function (d) { promptText.value = d.prompt; }).catch(function (err) { promptText.value = ''; say(msg, err.message, true); });
  }
  promptBox.addEventListener('toggle', function () { if (promptBox.open && !promptText.value) loadPrompt(); });
  promptText.addEventListener('input', function () { state.promptEdited = true; });
  text.addEventListener('change', dirty);
  text.addEventListener('input', function () { clearTimeout(text._t); text._t = setTimeout(freeDirty, 600); });

  /* ---------- modo Gemini gratis ---------- */
  var freeText = $('[data-cves-free-text]'), freeMsg = $('[data-cves-free-msg]'), copyBtn = $('[data-cves-copy]'), copyMsg = $('[data-cves-copy-msg]');
  var baseLink = $('[data-cves-base]'), baseImg = $('[data-cves-base-img]'), baseText = $('[data-cves-base-text]'), openLink = $('[data-cves-open]');
  var freeReq = null, freeSeq = 0, freeOk = false;
  // En Android se intenta la app de Gemini (com.google.android.apps.bard); si no está, se abre la web.
  if (isAndroid) openLink.href = 'intent://gemini.google.com/app#Intent;scheme=https;package=com.google.android.apps.bard;S.browser_fallback_url=' + encodeURIComponent('https://gemini.google.com/app') + ';end';
  function showFree(on) { free.hidden = !on; if (on) freeDirty(); }
  function freeDirty() {
    if (free.hidden) return;
    copyMsg.textContent = '';
    renderBase();
    if (!state.pid && !hasPhoto()) { freeOk = false; freeText.value = ''; freeReq = null; say(freeMsg, 'Primero elige arriba un producto de tu tienda (o una foto de tu móvil).'); return; }
    var seq = ++freeSeq, fd = fields(new FormData());
    fd.append('mode', 'app'); fd.append('has_photo', hasPhoto() ? '1' : '0');
    freeReq = api('prompt', fd).then(function (d) {
      if (seq !== freeSeq) return freeReq;
      freeOk = true; freeText.value = d.prompt; say(freeMsg, ''); return d.prompt;
    }).catch(function (err) {
      if (seq !== freeSeq) return freeReq;
      freeOk = false; freeText.value = ''; say(freeMsg, err.message, true); renderBase(); return '';
    });
  }
  function renderBase() {
    var step = $('[data-cves-base-step]');
    if (state.pid) {
      baseText.textContent = hasPhoto() ? 'Guarda la foto del producto. En Gemini adjunta esta y la de tu móvil.' : 'Guarda la foto del producto en tu móvil.';
      baseLink.hidden = false; baseLink.href = cfg.base + 'base?' + query();
      baseShare.hidden = !canShareFiles;
      prefetchBase();
      var c = state.colors.filter(function (x) { return x.label === state.color && x.img; })[0];
      baseImg.src = c ? c.img : state.img; baseImg.hidden = !baseImg.src;
      step.classList.toggle('is-off', false);
    } else {
      baseText.textContent = hasPhoto() ? 'Usa la foto de tu móvil: la adjuntas en Gemini.' : 'Elige un producto arriba para guardar su foto.';
      baseLink.hidden = true; baseImg.hidden = true; baseShare.hidden = true;
      step.classList.toggle('is-off', !hasPhoto());
    }
  }
  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
    return new Promise(function (ok, bad) {
      freeText.parentNode.open = true; freeText.focus(); freeText.select(); freeText.setSelectionRange(0, value.length);
      try { if (document.execCommand('copy')) { ok(); return; } } catch (ignore) {}
      bad(new Error('no'));
    });
  }
  copyBtn.addEventListener('click', function () {
    if (!state.pid && !hasPhoto()) { say(freeMsg, 'Primero elige arriba un producto de tu tienda (o una foto de tu móvil).', true); return; }
    var go = function (value) {
      if (!value) return;
      copyText(value).then(function () { copyMsg.textContent = 'Copiada ✓ Ahora pégala en Gemini.'; })
        .catch(function () { freeText.parentNode.open = true; freeText.select(); copyMsg.textContent = 'Mantén el dedo sobre la instrucción, toca «Seleccionar todo» y «Copiar».'; });
    };
    if (freeOk && freeText.value) { go(freeText.value); return; }
    copyMsg.textContent = 'Preparando…';
    (freeReq || Promise.resolve('')).then(function (v) { copyMsg.textContent = ''; go(v); });
  });
  baseLink.addEventListener('click', function (e) {
    if (!state.pid || (!freeOk && freeMsg.classList.contains('is-bad'))) e.preventDefault();
  });
  // «Mandarla a Gemini»: el menú Compartir del móvil (en Android sale Gemini; en iPhone también «Guardar imagen»).
  var baseShare = $('[data-cves-base-share]');
  var canShareFiles = false;
  try { canShareFiles = !!(navigator.canShare && window.File && navigator.canShare({ files: [new File(['x'], 'x.jpg', { type: 'image/jpeg' })] })); } catch (ignore) {}
  // La foto se baja antes (al elegir el producto o el color): así el menú Compartir abre al momento (el iPhone lo exige).
  var baseCache = { href: '', file: null, req: null };
  function getBase(href) {
    if (baseCache.href === href && (baseCache.file || baseCache.req)) return baseCache.file ? Promise.resolve(baseCache.file) : baseCache.req;
    baseCache = { href: href, file: null, req: null };
    baseCache.req = fetch(href, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('no'); return r.blob(); }).then(function (blob) {
      var f = new File([blob], 'casa-viva-producto.jpg', { type: 'image/jpeg' });
      if (baseCache.href === href) baseCache.file = f;
      return f;
    });
    baseCache.req.catch(function () { if (baseCache.href === href) baseCache.req = null; });
    return baseCache.req;
  }
  function prefetchBase() { clearTimeout(prefetchBase._t); prefetchBase._t = setTimeout(function () { if (canShareFiles && state.pid && !baseShare.hidden) getBase(baseLink.href).catch(function () {}); }, 700); }
  baseShare.addEventListener('click', function () {
    if (!state.pid || (!freeOk && freeMsg.classList.contains('is-bad'))) return;
    baseShare.disabled = true;
    var href = baseLink.href;
    (baseCache.href === href && baseCache.file ? Promise.resolve(baseCache.file) : getBase(href)).then(function (file) {
      return navigator.share({ files: [file] });
    }).catch(function (err) { if (err && err.name === 'AbortError') return; say(freeMsg, 'No pude compartirla. Toca «Guardar foto del producto» y adjúntala en Gemini.', true); })
      .then(function () { baseShare.disabled = false; });
  });
  $('[data-cves-free-show]').addEventListener('click', function () { showFree(true); free.scrollIntoView({ behavior: 'smooth', block: 'start' }); });

  /* ---------- subir la foto de Gemini ---------- */
  var up = $('[data-cves-up]'), upMsg = $('[data-cves-up-msg]'), upResult = $('[data-cves-up-result]');
  // Achica la foto en el móvil antes de subirla (máx. 1600 px, JPG): gasta menos datos.
  function shrink(file) {
    return new Promise(function (ok) {
      if (!window.URL || !document.createElement('canvas').toBlob) { ok(file); return; }
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var max = 1600, w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
        if (k === 1 && file.size < 1500000 && /jpe?g/i.test(file.type)) { URL.revokeObjectURL(url); ok(file); return; }
        var cv = document.createElement('canvas'); cv.width = Math.round(w * k); cv.height = Math.round(h * k);
        var cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); cx.drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob(function (b) { URL.revokeObjectURL(url); ok(b && b.size > 1000 ? new File([b], 'gemini.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); ok(file); };
      img.src = url;
    });
  }
  up.addEventListener('change', function () {
    var f = up.files && up.files[0]; if (!f) return;
    upResult.innerHTML = '';
    say(upMsg, 'Subiendo tu foto…');
    shrink(f).then(function (small) {
      var fd = fields(new FormData()); fd.append('photo', small, small.name || 'gemini.jpg');
      return api('subir', fd);
    }).then(function (d) {
      say(upMsg, state.pid ? 'Lista. Está en tu galería: ya puedes descargarla, compartirla o ponerla en tu tienda.' : 'Lista. Está en tu galería. Para ponerla en tu tienda, elige primero el producto arriba y súbela otra vez.');
      if (typeof d.left === 'number') left.textContent = 'Te quedan ' + d.left + ' fotos hoy.';
      cfg.gallery.unshift(d.photo); renderGallery();
      upResult.innerHTML = card(d.photo, true);
    }).catch(function (err) { say(upMsg, err.message, true); })
      .then(function () { up.value = ''; });
  });

  /* ---------- crear con la clave ---------- */
  var msg = $('[data-cves-msg]'), result = $('[data-cves-result]'), left = $('[data-cves-left]');
  makeForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!cfg.hasKey) { showFree(true); return; }
    if (!state.pid && !hasPhoto()) { say(msg, 'Elige un producto de tu tienda o sube una foto del móvil.', true); return; }
    var btn = $('[data-cves-go]'); btn.disabled = true; result.innerHTML = '';
    say(msg, 'Creando tu foto con Google… tarda entre 15 y 60 segundos. No cierres esta página.');
    var fd = fields(new FormData());
    if (hasPhoto()) fd.append('photo', photo.files[0]);
    if (state.promptEdited && promptText.value.trim()) fd.append('prompt', promptText.value);
    api('crear', fd).then(function (d) {
      say(msg, 'Lista. Está en tu galería.');
      if (typeof d.left === 'number') left.textContent = 'Te quedan ' + d.left + ' fotos hoy.';
      cfg.gallery.unshift(d.photo); renderGallery();
      result.innerHTML = card(d.photo, true);
    }).catch(function (err) {
      say(msg, err.message, true);
      if (err.data && err.data.free) showFree(true); // sin facturación: los 3 pasos gratis, con lo ya elegido
    }).then(function () { btn.disabled = false; });
  });

  /* ---------- galería ---------- */
  var gallery = $('[data-cves-gallery]'), empty = $('[data-cves-empty]'), count = $('[data-cves-count]');
  function card(p, big) {
    return '<article class="cves-item' + (big ? ' is-big' : '') + '" data-id="' + esc(p.id) + '"><a class="cves-img" href="' + esc(p.url) + '" target="_blank" rel="noopener"><img src="' + esc(p.url) + '" alt="' + esc(p.product || 'Foto') + '" loading="lazy" width="' + (p.w || 400) + '" height="' + (p.h || 400) + '"></a>' +
      '<div class="cves-item-body"><b>' + esc(p.product || 'Foto sin producto') + (p.color ? ' · ' + esc(p.color) : '') + '</b><small class="cvga-note">' + esc(p.date) + (p.text ? ' · «' + esc(p.text) + '»' : '') + '</small>' +
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
  function refreshBig() {
    [result, upResult].forEach(function (box) { if (box.firstChild) { var x = find(box.firstChild.getAttribute('data-id')); box.innerHTML = x ? card(x, true) : ''; } });
  }
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
      api('borrar', fdd).then(function () { cfg.gallery = cfg.gallery.filter(function (x) { return x.id !== p.id; }); renderGallery(); refreshBig(); }).catch(function (err) { done.textContent = err.message; });
      return;
    }
    if (act === 'store' || act === 'unstore') {
      b.disabled = true; done.textContent = act === 'store' ? 'Poniendo la foto en tu tienda…' : 'Quitando…';
      var fds = new FormData(); fds.append('id', p.id); if (act === 'unstore') fds.append('quitar', '1');
      api('tienda', fds).then(function (d) {
        cfg.gallery.forEach(function (x) { if (x.pid === p.pid) x.inStore = false; });
        p.inStore = !!d.in_store; if (d.link) p.storeLink = d.link;
        renderGallery(); refreshBig();
        root.querySelectorAll('.cves-item[data-id="' + p.id + '"] .cves-done').forEach(function (again) { again.textContent = d.message; });
      }).catch(function (err) { done.textContent = err.message; b.disabled = false; });
    }
  });
  renderGallery();
  if (!free.hidden) freeDirty();
})();
