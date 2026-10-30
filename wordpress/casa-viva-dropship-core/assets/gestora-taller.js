/* «Taller de fotos» (Core 3.14.91): la foto se arregla en el teléfono (canvas), el texto lo pide al servidor.
   Las funciones puras (layout, wrapLines) se prueban solas: artifacts/tests/test-taller-layout.mjs */
(function () {
'use strict';

/** Dónde dibujar la foto dentro de un área aw×ah (a partir de ay). mode: 'cover' llena, 'fit' entera. ox/oy = desplazamiento en px. */
function layout(iw, ih, aw, ah, mode, zoom, ox, oy) {
  var base = mode === 'fit' ? Math.min(aw / iw, ah / ih) * 0.94 : Math.max(aw / iw, ah / ih);
  var s = base * Math.max(1, zoom || 1);
  var dw = iw * s, dh = ih * s;
  function clamp(off, d, a) {
    var slack = Math.abs(d - a) / 2;
    return Math.max(-slack, Math.min(slack, off || 0));
  }
  var cx = clamp(ox, dw, aw), cy = clamp(oy, dh, ah);
  return { dw: dw, dh: dh, dx: (aw - dw) / 2 + cx, dy: (ah - dh) / 2 + cy, ox: cx, oy: cy };
}

/** Parte un texto en hasta maxLines líneas que quepan en maxW según measure(texto). Devuelve null si no cabe. */
function wrapLines(text, maxW, maxLines, measure) {
  var words = String(text || '').trim().split(/\s+/).filter(Boolean);
  if (!words.length) return [];
  var lines = [], cur = '';
  for (var i = 0; i < words.length; i++) {
    var test = cur ? cur + ' ' + words[i] : words[i];
    if (measure(test) <= maxW || !cur) { cur = test; } else { lines.push(cur); cur = words[i]; }
  }
  lines.push(cur);
  if (lines.length > maxLines) return null;
  for (var j = 0; j < lines.length; j++) { if (measure(lines[j]) > maxW) return null; }
  return lines;
}

if (typeof module !== 'undefined' && module.exports) module.exports = { layout: layout, wrapLines: wrapLines };
if (typeof document === 'undefined') return;

{
  var root = document.querySelector('[data-cvtl]');
  if (!root) return;
  var cfg = {};
  try { cfg = JSON.parse(root.querySelector('[data-cvtl-config]').textContent) || {}; } catch (ignore) {}

  var $ = function (sel) { return root.querySelector(sel); };
  var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); };
  var norm = function (v) { return String(v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  function say(el, text, bad) { el.hidden = !text; el.textContent = text || ''; el.classList.toggle('is-bad', !!bad); }

  var items = [];
  try { items = (JSON.parse(document.querySelector('[data-cvgf-data]').textContent) || {}).items || []; } catch (ignore) {}
  items.forEach(function (p) { p.q = norm(p.n); });

  var state = { pid: 0, name: '', price: '', img: null, size: 'sq', mode: 'cover', bg: 'white', band: 'name', bandcolor: 'dark', zoom: 1, ox: 0, oy: 0, kind: 'facebook', objectUrl: '' };

  /* ---------------- producto ---------------- */
  var q = $('[data-cvtl-q]'), hits = $('[data-cvtl-hits]'), pick = $('[data-cvtl-pick]'), search = $('[data-cvtl-search]');
  var thumbs = $('[data-cvtl-thumbs]'), bandText = $('[data-cvtl-band-text]'), priceText = $('[data-cvtl-price-text]'), msg = $('[data-cvtl-msg]');

  function findItems(text) {
    var words = norm(text).split(/\s+/).filter(function (w) { return w.length > 1; });
    if (!words.length) return [];
    return items.filter(function (p) { return words.every(function (w) { return p.q.indexOf(w.length > 4 ? w.slice(0, -1) : w) > -1; }); }).slice(0, 8);
  }
  function renderHits() {
    var text = q.value.trim();
    if (!text) { hits.innerHTML = ''; return; }
    var found = findItems(text);
    hits.innerHTML = found.map(function (p) {
      return '<button type="button" class="cvtl-hit" data-cvtl-pid="' + p.id + '"><img src="' + esc(p.i[0] && p.i[0][0]) + '" alt="" width="40" height="40" loading="lazy"><span>' + esc(p.n) + '<small>' + esc(p.p) + '</small></span></button>';
    }).join('') + '<button type="button" class="cvtl-hit cvtl-own" data-cvtl-own><span>Usar este nombre: «' + esc(text) + '»<small>Una pieza que no está en tu tienda</small></span></button>';
  }
  var timer;
  q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(renderHits, 150); });
  hits.addEventListener('click', function (e) {
    var own = e.target.closest('[data-cvtl-own]');
    if (own) { setProduct(0, q.value.trim(), ''); return; }
    var b = e.target.closest('[data-cvtl-pid]');
    if (b) setProduct(Number(b.getAttribute('data-cvtl-pid')));
  });
  $('[data-cvtl-pick-clear]').addEventListener('click', function () {
    state.pid = 0; state.name = ''; state.price = ''; pick.hidden = true; search.hidden = false; thumbs.hidden = true; thumbs.innerHTML = '';
    bandText.value = ''; priceText.value = ''; q.value = ''; hits.innerHTML = ''; q.focus(); draw();
  });

  function setProduct(pid, ownName, ownPrice) {
    var p = pid ? items.filter(function (x) { return x.id === pid; })[0] : null;
    if (pid && !p) return;
    state.pid = p ? p.id : 0;
    state.name = p ? p.n : ownName;
    state.price = p ? p.p : (ownPrice || '');
    pick.hidden = false; search.hidden = true; hits.innerHTML = '';
    pick.querySelector('img').src = p && p.i[0] ? p.i[0][0] : 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
    pick.querySelector('[data-cvtl-pick-name]').textContent = state.name;
    pick.querySelector('[data-cvtl-pick-price]').textContent = state.price ? 'Tu precio: ' + state.price : 'Fuera de tu tienda';
    bandText.value = state.name; priceText.value = state.price;
    if (p && p.i.length) {
      thumbs.hidden = false;
      thumbs.innerHTML = '<span class="cvga-note">Foto de la tienda (o sube la tuya):</span>' + p.i.slice(0, 6).map(function (im, n) { return '<button type="button" class="cvtl-thumb" data-cvtl-thumb="' + n + '" aria-label="Usar la foto ' + (n + 1) + '"><img src="' + esc(im[0]) + '" alt="" width="56" height="56" loading="lazy"></button>'; }).join('');
      if (!state.img) loadUrl(p.i[0][0]);
    } else { thumbs.hidden = true; }
    draw();
  }
  thumbs.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cvtl-thumb]'); if (!b) return;
    var p = items.filter(function (x) { return x.id === state.pid; })[0];
    if (p) loadUrl(p.i[Number(b.getAttribute('data-cvtl-thumb'))][0]);
  });

  /* ---------------- foto ---------------- */
  var stage = $('[data-cvtl-stage]'), canvas = $('[data-cvtl-canvas]'), ctx = canvas.getContext('2d');

  function useImage(img) {
    state.img = img; state.zoom = 1; state.ox = 0; state.oy = 0;
    $('[data-cvtl-zoom]').value = 100;
    stage.hidden = false; say(msg, '');
    draw();
  }
  function loadUrl(url) {
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function () { useImage(img); };
    img.onerror = function () { say(msg, 'No pude abrir esa foto. Sube una de tu móvil.', true); };
    img.src = url;
  }
  $('[data-cvtl-photo]').addEventListener('change', function (e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    if (state.objectUrl) { try { URL.revokeObjectURL(state.objectUrl); } catch (ignore) {} }
    state.objectUrl = URL.createObjectURL(f);
    var img = new Image();
    img.onload = function () { useImage(img); };
    img.onerror = function () { say(msg, 'Esa foto no se pudo abrir. Prueba con otra (JPG, PNG o WebP).', true); };
    img.src = state.objectUrl;
  });

  var BG = { white: '#ffffff', cream: '#f6efe3', gray: '#e9ebec' };
  var BAND = { dark: ['#16201f', '#ffffff', '#c39443'], light: ['#ffffff', '#16201f', '#0f3d3a'], gold: ['#c39443', '#1d1406', '#16201f'] };

  function dims() { return state.size === 'v' ? [1080, 1920] : [1080, 1080]; }

  function draw() {
    var d = dims(), W = d[0], H = d[1];
    if (canvas.width !== W) canvas.width = W;
    if (canvas.height !== H) canvas.height = H;
    ctx.fillStyle = BG[state.bg] || '#fff'; ctx.fillRect(0, 0, W, H);
    var bandH = state.band === 'none' ? 0 : (state.size === 'v' ? 250 : 190);
    if (state.img) {
      var areaH = state.mode === 'fit' ? H - bandH : H;
      var L = layout(state.img.naturalWidth, state.img.naturalHeight, W, areaH, state.mode, state.zoom, state.ox, state.oy);
      state.ox = L.ox; state.oy = L.oy;
      ctx.save(); ctx.beginPath(); ctx.rect(0, 0, W, areaH); ctx.clip();
      ctx.drawImage(state.img, L.dx, L.dy, L.dw, L.dh);
      ctx.restore();
    }
    if (bandH) drawBand(W, H, bandH);
    $$('[data-cvtl-bg-opt]').forEach(function (el) { el.hidden = state.mode !== 'fit'; });
    $$('[data-cvtl-band-opt]').forEach(function (el) { el.hidden = state.band === 'none'; });
    $$('[data-cvtl-price-opt]').forEach(function (el) { el.hidden = state.band !== 'price'; });
  }

  function drawBand(W, H, bandH) {
    var c = BAND[state.bandcolor] || BAND.dark, pad = 54, y0 = H - bandH;
    ctx.fillStyle = c[0]; ctx.fillRect(0, y0, W, bandH);
    ctx.fillStyle = c[2]; ctx.fillRect(0, y0, W, 8); // filo dorado/verde arriba de la banda
    var name = (bandText.value || state.name || '').trim();
    var price = state.band === 'price' ? (priceText.value || '').trim() : '';
    var font = function (px) { return '700 ' + px + 'px "Helvetica Neue", Arial, sans-serif'; };
    var priceW = 0, gap = 36;
    if (price) {
      ctx.font = font(60); priceW = ctx.measureText(price).width + 56;
    }
    var availW = W - pad * 2 - (price ? priceW + gap : 0);
    var lines = null, px = 66;
    for (; px >= 34 && !lines; px -= 4) {
      ctx.font = font(px);
      lines = wrapLines(name, availW, px >= 50 ? 1 : 2, function (t) { return ctx.measureText(t).width; });
    }
    if (!lines) { px = 34; ctx.font = font(px); lines = wrapLines(name, availW, 2, function (t) { return ctx.measureText(t).width; }) || [name.slice(0, 28) + '…']; }
    px += 4;
    ctx.font = font(px); ctx.fillStyle = c[1]; ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
    var lh = px * 1.15, cy = y0 + 8 + (bandH - 8) / 2, top = cy - (lines.length - 1) * lh / 2;
    lines.forEach(function (ln, i) { ctx.fillText(ln, pad, top + i * lh); });
    if (price) {
      var bx = W - pad - priceW, bh = 96, by = cy - bh / 2;
      ctx.fillStyle = c[2];
      roundRect(bx, by, priceW, bh, 22); ctx.fill();
      ctx.fillStyle = state.bandcolor === 'dark' ? '#1d1406' : '#ffffff';
      ctx.font = font(60); ctx.textAlign = 'center';
      ctx.fillText(price, bx + priceW / 2, cy + 2);
    }
  }
  function roundRect(x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  /* ---------------- opciones ---------------- */
  $$('[data-cvtl-seg]').forEach(function (seg) {
    var key = seg.getAttribute('data-cvtl-seg');
    if (key === 'kind') return;
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-v]'); if (!b) return;
      state[key] = b.getAttribute('data-v');
      Array.prototype.forEach.call(seg.querySelectorAll('button'), function (o) { o.setAttribute('aria-pressed', String(o === b)); });
      state.ox = 0; state.oy = 0;
      draw();
    });
  });
  $('[data-cvtl-zoom]').addEventListener('input', function (e) { state.zoom = Number(e.target.value) / 100; draw(); });
  bandText.addEventListener('input', draw);
  priceText.addEventListener('input', draw);

  // Arrastrar la foto con el dedo.
  var drag = null;
  canvas.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, ox: state.ox, oy: state.oy }; try { canvas.setPointerCapture(e.pointerId); } catch (ignore) {} });
  canvas.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var k = canvas.width / canvas.getBoundingClientRect().width;
    state.ox = drag.ox + (e.clientX - drag.x) * k; state.oy = drag.oy + (e.clientY - drag.y) * k;
    draw();
  });
  ['pointerup', 'pointercancel'].forEach(function (n) { canvas.addEventListener(n, function () { drag = null; }); });

  /* ---------------- guardar ---------------- */
  var shareBtn = $('[data-cvtl-share]');
  function fileName() { return (norm(bandText.value || state.name || 'foto').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'foto') + (state.size === 'v' ? '-estado' : '-1080') + '.jpg'; }
  function toFile(cb) {
    try {
      canvas.toBlob(function (blob) {
        if (!blob) { say(msg, 'No pude preparar la foto. Prueba con una foto de tu móvil.', true); return; }
        cb(blob);
      }, 'image/jpeg', 0.92);
    } catch (err) { say(msg, 'No pude guardar esta foto (la de la tienda no se deja). Sube una de tu móvil.', true); }
  }
  $('[data-cvtl-save]').addEventListener('click', function () {
    toFile(function (blob) {
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = fileName();
      document.body.appendChild(a); a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
      say(msg, 'Lista: busca «' + a.download + '» en tus descargas o tu galería.');
    });
  });
  if (navigator.canShare && window.File) shareBtn.hidden = false;
  shareBtn.addEventListener('click', function () {
    toFile(function (blob) {
      var file = new File([blob], fileName(), { type: 'image/jpeg' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: state.name || 'Foto' }).catch(function () {});
      } else { say(msg, 'Tu móvil no deja compartir desde aquí. Usa «Guardar foto».', true); }
    });
  });

  /* ---------------- texto ---------------- */
  var kindsBox = $('[data-cvtl-kinds]'), extra = $('[data-cvtl-extra]'), tmsg = $('[data-cvtl-text-msg]'), result = $('[data-cvtl-result]'), out = $('[data-cvtl-text]'), leftEl = $('[data-cvtl-left]');
  kindsBox.innerHTML = Object.keys(cfg.kinds || {}).map(function (k, n) { return '<button type="button" data-v="' + esc(k) + '" aria-pressed="' + (n === 0) + '">' + esc(cfg.kinds[k]) + '</button>'; }).join('');
  kindsBox.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-v]'); if (!b) return;
    state.kind = b.getAttribute('data-v');
    Array.prototype.forEach.call(kindsBox.querySelectorAll('button'), function (o) { o.setAttribute('aria-pressed', String(o === b)); });
  });
  function showLeft() { leftEl.textContent = cfg.ai ? 'Te quedan ' + cfg.left + ' textos con IA hoy.' : 'Texto sencillo con los datos del producto.'; }
  showLeft();

  $('[data-cvtl-write]').addEventListener('click', function () {
    var btn = this;
    var name = state.name || q.value.trim();
    if (!name) { say(tmsg, 'Primero elige el producto (arriba) o escribe cómo se llama.', true); q.focus(); return; }
    btn.disabled = true; say(tmsg, 'Escribiendo…');
    var fd = new FormData();
    fd.append('product_id', String(state.pid || 0)); fd.append('name', name); fd.append('kind', state.kind); fd.append('extra', extra.value);
    fetch(cfg.base + 'texto', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'No pude escribir el texto. Prueba otra vez.'); return d; }); }, function () { throw new Error('Sin conexión. Revisa tus datos o el wifi y prueba otra vez.'); })
      .then(function (d) {
        out.value = d.text || ''; result.hidden = false; cfg.left = d.left; cfg.ai = !!d.has_ai; showLeft();
        say(tmsg, d.note || (d.ai ? 'Listo, escrito con IA a partir de los datos del producto.' : ''));
      })
      .catch(function (err) { say(tmsg, err.message, true); })
      .then(function () { btn.disabled = false; });
  });
  $('[data-cvtl-copy]').addEventListener('click', function () {
    var note = $('[data-cvtl-copy-msg]');
    function ok() { note.textContent = 'Copiado ✓'; setTimeout(function () { note.textContent = ''; }, 2500); }
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(out.value).then(ok, fallback); } else { fallback(); }
    function fallback() { out.focus(); out.select(); try { document.execCommand('copy'); ok(); } catch (ignore) { note.textContent = 'Mantén el dedo sobre el texto y toca Copiar.'; } }
  });
}
})();
