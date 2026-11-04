/* Tablero único del móvil (Core 3.14.97). Solo lee: pide /tablero/datos y pinta tarjetas. Sin librerías. */
(function () {
  'use strict';
  var B = window.TABLERO || {};
  var root = document.getElementById('tb');
  var carga = document.getElementById('tb-carga');
  var STORE = 'tablero-ultimo';
  var KEY = 'tablero-k';
  var token = '';
  // La clave del enlace llega en #k= (nunca va al servidor ni a la caché): se guarda en el teléfono y se limpia la dirección.
  (function () {
    var m = /[#&]k=([0-9]+.[0-9]+.[a-f0-9]{32})/.exec(location.hash || '');
    if (m) { token = m[1]; try { localStorage.setItem(KEY, token); } catch (e) {} try { history.replaceState(null, '', location.pathname); } catch (e) {} }
    else { try { token = localStorage.getItem(KEY) || ''; } catch (e) { token = ''; } }
  })();
  var state = { ver: '', hash: '', data: null, cargando: false, timer: 0 };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }
  function safeUrl(u) { return /^https:\/\//.test(String(u || '')) ? String(u) : ''; }
  function guardar(d) { try { localStorage.setItem(STORE, JSON.stringify({ t: Date.now(), d: d })); } catch (e) { /* sin almacenamiento */ } }
  function leer() { try { var x = JSON.parse(localStorage.getItem(STORE) || 'null'); return x && x.d ? x : null; } catch (e) { return null; } }

  function tag(t, txt) {
    var labels = { rojo: 'urgente', amarillo: 'atención', verde: 'bien' };
    var s = el('span', 'tb-tag tb-' + t, txt || labels[t] || '');
    return s;
  }

  function fila(f) {
    var url = safeUrl(f.url);
    var r = el(url ? 'a' : 'div', url ? 'tb-row tb-link' : 'tb-row');
    if (url) { r.href = url; r.rel = 'noopener'; }
    r.appendChild(el('span', 'tb-l', f.t));
    var right = el('span', 'tb-r');
    if (f.r) right.appendChild(el('b', null, f.r));
    if (f.tag) right.appendChild(tag(f.tag, f.tag_txt));
    r.appendChild(right);
    if (!f.acciones || !f.acciones.length) return r;
    var wrap = el('div', 'tb-dec');
    wrap.appendChild(r);
    if (f.efecto) wrap.appendChild(el('p', 'tb-small', f.efecto));
    var bar = el('div', 'tb-acts');
    f.acciones.forEach(function (a, i) {
      var b = el('button', i === 0 ? 'tb-act tb-act-si' : 'tb-act', a.txt); b.type = 'button';
      b.addEventListener('click', function () { decidir(a, wrap); });
      bar.appendChild(b);
    });
    wrap.appendChild(bar);
    return wrap;
  }

  // Decisión de un toque: 10 s para deshacer y, después, la web dice el resultado real.
  function decidir(a, wrap) {
    var bar = wrap.querySelector('.tb-acts');
    var seg = 10;
    bar.textContent = '';
    var msg = el('span', 'tb-cuenta', a.aviso + ' en ' + seg + ' s');
    var undo = el('button', 'tb-act', 'Deshacer'); undo.type = 'button';
    bar.appendChild(msg); bar.appendChild(undo);
    var timer = setInterval(function () {
      seg -= 1;
      if (seg > 0) { msg.textContent = a.aviso + ' en ' + seg + ' s'; return; }
      clearInterval(timer);
      undo.remove();
      msg.textContent = 'Guardando…';
      enviar(a, msg);
    }, 1000);
    undo.addEventListener('click', function () { clearInterval(timer); state.hash = ''; cargar(true); });
  }

  function enviar(a, msg) {
    var headers = { 'Content-Type': 'application/json', 'X-Tablero': '1' };
    if (token) headers['X-Tablero-Token'] = token;
    fetch(B.accion, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: headers, body: JSON.stringify({ tipo: a.tipo, id: a.id, decision: a.decision }) })
      .then(function (res) { return res.json(); })
      .then(function (j) {
        msg.textContent = (j && j.message) || 'No se pudo. Inténtalo de nuevo.';
        msg.className = 'tb-cuenta ' + (j && j.ok ? 'tb-ok' : 'tb-mal');
        if (j && j.ok) setTimeout(function () { state.hash = ''; cargar(true); }, 2500);
      })
      .catch(function () { msg.textContent = 'Sin conexión: no se guardó. Inténtalo cuando vuelva.'; msg.className = 'tb-cuenta tb-mal'; });
  }

  function tarjeta(c) {
    if (c.boton) {
      var b = el('button', 'tb-go tb-btn', c.boton.txt + ' →');
      b.type = 'button';
      b.addEventListener('click', function () { cambiarVista(c.boton.ver); });
      return b;
    }
    var card = el('section', 'tb-card');
    var h = el('h3', null, c.titulo);
    if (c.nota) h.appendChild(el('span', null, c.nota));
    card.appendChild(h);
    if (c.nums && c.nums.length) {
      var g = el('div', 'tb-grid');
      c.nums.forEach(function (n) { var d = el('div', 'tb-n'); d.appendChild(el('b', null, n.n)); d.appendChild(el('small', null, n.label)); g.appendChild(d); });
      card.appendChild(g);
    }
    if (c.texto) card.appendChild(el('p', 'tb-small', c.texto));
    (c.detalle || []).forEach(function (f) { card.appendChild(fila(f)); });
    (c.filas || []).forEach(function (f) { card.appendChild(fila(f)); });
    if (c.mas && c.mas.filas && c.mas.filas.length) {
      var det = el('details');
      det.appendChild(el('summary', null, c.mas.txt));
      c.mas.filas.forEach(function (f) { det.appendChild(fila(f)); });
      card.appendChild(det);
    }
    if (c.aviso) card.appendChild(el('p', 'tb-stale', c.aviso));
    var url = c.enlace && safeUrl(c.enlace.url);
    if (url) { var a = el('a', 'tb-go', c.enlace.txt + ' →'); a.href = url; a.rel = 'noopener'; card.appendChild(a); }
    return card;
  }

  function pintar(d, desdeMemoria, minutos) {
    state.data = d;
    root.textContent = '';
    var head = el('header', 'tb-head');
    var logo = el('div', 'tb-logo tb-logo-s'); logo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19V5l14 14V5"/></svg>';
    head.appendChild(logo);
    var who = el('div', 'tb-who');
    who.appendChild(el('b', null, d.hola));
    who.appendChild(el('small', null, d.negocio + ' · ' + d.dia + ' · visto ' + (d.visto || '')));
    head.appendChild(who);
    var btn = el('button', 'tb-refresh', 'Actualizar'); btn.type = 'button';
    btn.addEventListener('click', function () { cargar(true); });
    head.appendChild(btn);
    root.appendChild(head);
    if (desdeMemoria) root.appendChild(el('p', 'tb-stale tb-center', 'Sin conexión: esto es lo último que viste' + (minutos ? ', hace ' + minutos + ' min.' : '.')));
    if (d.volver) {
      var v = el('button', 'tb-go tb-btn', '← ' + d.volver.txt); v.type = 'button';
      v.addEventListener('click', function () { cambiarVista(d.volver.ver); });
      root.appendChild(v);
    }
    var s = d.semaforo || { color: 'verde', titulo: '', razones: [] };
    var sem = el('section', 'tb-sem tb-sem-' + s.color);
    sem.appendChild(el('span', 'tb-dot'));
    var box = el('div');
    box.appendChild(el('h2', null, s.titulo));
    if (s.razones && s.razones.length) {
      var ul = el('ul');
      s.razones.forEach(function (t) { ul.appendChild(el('li', null, t)); });
      box.appendChild(ul);
    } else {
      box.appendChild(el('p', null, 'Todo en orden ahora mismo.'));
    }
    sem.appendChild(box);
    root.appendChild(sem);
    (d.tarjetas || []).forEach(function (c) { root.appendChild(tarjeta(c)); });
    root.hidden = false;
    if (carga) { carga.classList.add('tb-fuera'); setTimeout(function () { if (carga && carga.parentNode) carga.parentNode.removeChild(carga); carga = null; }, 400); }
  }

  function error(msg) {
    root.textContent = '';
    var m = el('main', 'tb-solo');
    m.appendChild(el('h1', null, 'No se pudo abrir'));
    m.appendChild(el('p', null, msg));
    var b = el('button', 'tb-go tb-btn', 'Intentar otra vez'); b.type = 'button';
    b.addEventListener('click', function () { cargar(true); });
    m.appendChild(b);
    root.appendChild(m);
    root.hidden = false;
    if (carga) { carga.classList.add('tb-fuera'); }
  }

  function personal() {
    root.textContent = '';
    var m = el('main', 'tb-solo');
    var logo = el('div', 'tb-logo'); logo.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19V5l14 14V5"/></svg>';
    m.appendChild(logo);
    m.appendChild(el('h1', null, 'Este tablero es personal'));
    m.appendChild(el('p', null, 'Abre el enlace completo que te dieron (desde el mensaje, sin copiar solo una parte). Si ya no funciona, pide uno nuevo.'));
    root.appendChild(m);
    root.hidden = false;
    if (carga) { carga.classList.add('tb-fuera'); }
  }

  function cambiarVista(ver) { state.ver = ver; state.hash = ''; window.scrollTo(0, 0); cargar(true); }

  function cargar(forzar) {
    if (state.cargando) return;
    state.cargando = true;
    var body = JSON.stringify({ ver: state.ver, hash: forzar ? '' : state.hash });
    fetch(B.datos, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: token ? { 'Content-Type': 'application/json', 'X-Tablero': '1', 'X-Tablero-Token': token } : { 'Content-Type': 'application/json', 'X-Tablero': '1' }, body: body })
      .then(function (res) { return res.json().then(function (j) { return { status: res.status, j: j }; }); })
      .then(function (x) {
        state.cargando = false;
        if (!x.j || !x.j.ok) {
          if (x.status === 403) { try { localStorage.removeItem(STORE); localStorage.removeItem(KEY); } catch (e) {} personal(); return; }
          error((x.j && x.j.message) || 'Inténtalo de nuevo en un momento.');
          return;
        }
        if (x.j.igual && state.data) { state.data.visto = x.j.visto; pintar(state.data); return; }
        state.hash = x.j.hash;
        if (!state.ver) guardar(x.j);
        pintar(x.j);
      })
      .catch(function () {
        state.cargando = false;
        var last = leer();
        if (last && !state.ver) { pintar(last.d, true, Math.round((Date.now() - last.t) / 60000)); }
        else if (!state.data) { error('No hay conexión. Cuando vuelva, toca «Intentar otra vez».'); }
      });
  }

  function programar() {
    clearInterval(state.timer);
    state.timer = setInterval(function () { if (document.visibilityState === 'visible') cargar(false); }, 120000);
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && state.data) cargar(false); });

  if ('serviceWorker' in navigator && B.sw) {
    navigator.serviceWorker.register(B.sw, { scope: B.scope }).catch(function () { /* sin modo sin conexión */ });
  }
  cargar(true);
  programar();
})();
