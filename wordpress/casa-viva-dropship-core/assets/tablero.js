/* Tablero único del móvil (Core 3.14.97). Solo lee: pide /tablero/datos y pinta tarjetas. Sin librerías. */
(function () {
  'use strict';
  var B = window.TABLERO || {};
  var root = document.getElementById('tb');
  var carga = document.getElementById('tb-carga');
  var STORE = 'tablero-ultimo';
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
    var r = el('div', 'tb-row');
    r.appendChild(el('span', 'tb-l', f.t));
    var right = el('span', 'tb-r');
    if (f.r) right.appendChild(el('b', null, f.r));
    if (f.tag) right.appendChild(tag(f.tag, f.tag_txt));
    r.appendChild(right);
    return r;
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

  function cambiarVista(ver) { state.ver = ver; state.hash = ''; window.scrollTo(0, 0); cargar(true); }

  function cargar(forzar) {
    if (state.cargando) return;
    state.cargando = true;
    var body = JSON.stringify({ ver: state.ver, hash: forzar ? '' : state.hash });
    fetch(B.datos, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-Tablero': '1' }, body: body })
      .then(function (res) { return res.json().then(function (j) { return { status: res.status, j: j }; }); })
      .then(function (x) {
        state.cargando = false;
        if (!x.j || !x.j.ok) {
          if (x.status === 403) { try { localStorage.removeItem(STORE); } catch (e) {} }
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
