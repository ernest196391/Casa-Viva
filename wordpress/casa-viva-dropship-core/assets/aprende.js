/* 3.13.77 · «Aprende»: ▶ monta el vídeo, la nota de voz o las guías al tocar (preload="none": los datos cuestan),
   «Marcar como visto» se guarda en la cuenta (POST /casa-viva/v1/aprende) y, al terminar un vídeo o la nota de voz,
   se marca solo. Cada sección [data-cva] es independiente (gestora en la app, mensajero en su área). */
(function () {
  'use strict';
  var roots = document.querySelectorAll('[data-cva]');
  if (!roots.length) return;
  Array.prototype.forEach.call(roots, init);

  function init(root) {
    var endpoint = root.getAttribute('data-endpoint');
    var nonce = root.getAttribute('data-nonce');
    var role = root.getAttribute('data-cva-role') || 'gestora';
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-cva-card]'));
    var countEl = root.querySelector('[data-cva-count]');
    var pctEl = root.querySelector('[data-cva-pct]');
    var barEl = root.querySelector('[data-cva-bar]');
    var barWrap = barEl ? barEl.parentNode : null;
    var statusEl = root.querySelector('[data-cva-status]');
    var statusTimer = null;

    function say(message) {
      if (!statusEl) return;
      statusEl.textContent = message;
      statusEl.hidden = !message;
      clearTimeout(statusTimer);
      if (message) statusTimer = setTimeout(function () { statusEl.hidden = true; }, 6000);
    }

    // Repinta todo a partir de data-done: número/✓, «Te toca» en la primera sin ver, botón, barra y menús.
    function paint() {
      var done = 0;
      var nextFound = false;
      cards.forEach(function (card) {
        var isDone = card.getAttribute('data-done') === '1';
        var isNext = !isDone && !nextFound;
        if (isDone) done++;
        if (isNext) nextFound = true;
        card.classList.toggle('is-done', isDone);
        card.classList.toggle('is-next', isNext);
        var badge = card.querySelector('[data-cva-next]');
        if (badge) badge.hidden = !isNext;
        var num = card.querySelector('.cva-num');
        if (num) num.textContent = isDone ? '✓' : card.getAttribute('data-num');
        var toggle = card.querySelector('[data-cva-toggle]');
        if (toggle) {
          toggle.textContent = isDone ? '✓ Ya lo vi' : 'Marcar como visto';
          toggle.setAttribute('aria-pressed', isDone ? 'true' : 'false');
        }
      });
      var total = cards.length;
      var pct = total ? Math.round(done / total * 100) : 0;
      if (countEl) countEl.textContent = done + ' de ' + total + ' vistos';
      if (pctEl) pctEl.textContent = pct + ' %';
      if (barEl) barEl.style.width = pct + '%';
      if (barWrap) barWrap.setAttribute('aria-valuenow', String(done));
      root.setAttribute('data-cva-done', done >= total ? '1' : '0');
      // Lo que falta, en el menú de la app de gestoras y en «Para hoy».
      Array.prototype.forEach.call(document.querySelectorAll('[data-cva-badge="' + role + '"]'), function (el) {
        el.textContent = done + '/' + total;
        el.hidden = done >= total;
      });
      Array.prototype.forEach.call(document.querySelectorAll('[data-cva-nudge="' + role + '"]'), function (el) {
        el.hidden = done >= total;
        var left = el.querySelector('[data-cva-left]');
        if (left) left.textContent = String(total - done);
      });
    }

    function save(card, seen) {
      var before = card.getAttribute('data-done');
      card.setAttribute('data-done', seen ? '1' : '0');
      paint();
      if (!endpoint || !window.fetch) return;
      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce || '' },
        body: JSON.stringify({ card: card.getAttribute('data-cva-card'), role: role, seen: !!seen })
      })
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (d) {
            if (!r.ok) throw new Error((d && d.message) || 'No se pudo guardar.');
            return d;
          });
        })
        .then(function (d) {
          if (d && d.cards) {
            cards.forEach(function (c) {
              var info = d.cards[c.getAttribute('data-cva-card')];
              if (info) c.setAttribute('data-done', info.done ? '1' : '0');
            });
            paint();
          }
        })
        .catch(function () {
          card.setAttribute('data-done', before);
          paint();
          say('No se pudo guardar. Revisa tu conexión y vuelve a tocar el botón.');
        });
    }

    function mediaOf(panel) {
      return panel ? panel.querySelector('video, audio') : null;
    }

    // Crea el reproductor solo la primera vez que se toca ▶.
    function build(panel) {
      if (panel.getAttribute('data-built') === '1') return mediaOf(panel);
      var m = {};
      try { m = JSON.parse(panel.getAttribute('data-cva-media') || '{}') || {}; } catch (ignore) {}
      var el = null;
      if (m.type === 'video' && m.src) {
        el = document.createElement('video');
        el.controls = true;
        el.setAttribute('controls', '');
        el.setAttribute('playsinline', '');
        el.setAttribute('preload', 'none');
        el.src = m.src;
        panel.appendChild(el);
      } else if (m.type === 'audio' && m.sources) {
        el = document.createElement('audio');
        el.controls = true;
        el.setAttribute('controls', '');
        el.setAttribute('preload', 'none');
        // ogg primero (Android, más ligero); el iPhone salta al mp3.
        m.sources.forEach(function (s) {
          var source = document.createElement('source');
          source.src = s.src;
          source.type = s.type;
          el.appendChild(source);
        });
        panel.appendChild(el);
      } else if (m.type === 'images' && m.imgs) {
        var wrap = document.createElement('div');
        wrap.className = 'cva-imgs';
        m.imgs.forEach(function (im) {
          var fig = document.createElement('figure');
          var img = document.createElement('img');
          img.src = im.src;
          img.alt = im.alt || '';
          img.loading = 'lazy';
          img.decoding = 'async';
          var link = document.createElement('a');
          link.className = 'cva-save';
          link.href = im.src;
          link.setAttribute('download', im.name || '');
          link.textContent = 'Guardar imagen';
          fig.appendChild(img);
          fig.appendChild(link);
          wrap.appendChild(fig);
        });
        panel.appendChild(wrap);
      }
      panel.setAttribute('data-built', '1');
      if (el) {
        el.addEventListener('ended', function () {
          var card = panel.closest('[data-cva-card]');
          if (card && card.getAttribute('data-done') !== '1') save(card, true);
        });
        el.addEventListener('error', function () { say('No se pudo cargar. Prueba con wifi o más tarde.'); }, true);
      }
      return el;
    }

    function close(button) {
      var panel = document.getElementById(button.getAttribute('aria-controls'));
      var media = mediaOf(panel);
      if (media && !media.paused) media.pause();
      if (panel) panel.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      if (button.getAttribute('data-label')) button.setAttribute('aria-label', button.getAttribute('data-label'));
    }

    function open(button) {
      // Uno a la vez: cierra (y pausa) lo que estuviera abierto.
      Array.prototype.forEach.call(root.querySelectorAll('[data-cva-play][aria-expanded="true"]'), function (b) { if (b !== button) close(b); });
      var panel = document.getElementById(button.getAttribute('aria-controls'));
      if (!panel) return;
      if (!button.getAttribute('data-label')) button.setAttribute('data-label', button.getAttribute('aria-label') || '');
      panel.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      button.setAttribute('aria-label', 'Cerrar');
      var media = build(panel);
      if (media && media.play) {
        var playing = media.play();
        if (playing && playing.catch) playing.catch(function () {});
      }
    }

    // Al cambiar de pestaña (app de gestoras o pantallas del mensajero) no sigue sonando nada escondido.
    function closeAll() {
      Array.prototype.forEach.call(root.querySelectorAll('[data-cva-play][aria-expanded="true"]'), close);
    }
    window.addEventListener('hashchange', closeAll);
    document.addEventListener('click', function (e) {
      var link = e.target.closest && e.target.closest('a[href^="#"], [data-cvga-go]');
      if (link && !root.contains(link)) closeAll();
    });

    root.addEventListener('click', function (e) {
      var play = e.target.closest('[data-cva-play]');
      if (play && root.contains(play)) {
        if (play.getAttribute('aria-expanded') === 'true') close(play); else open(play);
        return;
      }
      var toggle = e.target.closest('[data-cva-toggle]');
      if (toggle && root.contains(toggle)) {
        var card = toggle.closest('[data-cva-card]');
        if (card) save(card, card.getAttribute('data-done') !== '1');
        return;
      }
      var chip = e.target.closest('[data-cva-topic]');
      if (chip && root.contains(chip)) {
        var key = chip.getAttribute('data-cva-topic');
        var wasOpen = chip.getAttribute('aria-expanded') === 'true';
        Array.prototype.forEach.call(root.querySelectorAll('[data-cva-topic]'), function (c) {
          c.setAttribute('aria-expanded', !wasOpen && c === chip ? 'true' : 'false');
        });
        Array.prototype.forEach.call(root.querySelectorAll('[data-cva-answer]'), function (a) {
          a.hidden = wasOpen || a.getAttribute('data-cva-answer') !== key;
        });
      }
    });

    paint();
  }
})();
