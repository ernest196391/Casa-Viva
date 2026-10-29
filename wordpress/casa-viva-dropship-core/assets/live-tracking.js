(function () {
  'use strict';
  if (!window.cvdLiveTracking) return;
  var linkForm=document.querySelector('[data-tracking-link-form]');
  if(linkForm)linkForm.addEventListener('submit',function(event){event.preventDefault();var input=linkForm.querySelector('input'),error=linkForm.querySelector('[data-tracking-link-error]');try{var url=new URL(input.value.trim());if(url.origin!==location.origin||url.pathname.replace(/\/+$/,'')!=='/seguimiento'||!url.searchParams.get('pedido')||!url.searchParams.get('clave'))throw new Error('invalid');location.assign(url.href);}catch(ignore){error.textContent='Ese enlace no es válido. Copia el enlace completo recibido con el pedido.';}});

  // 3.13.89: «Tus pedidos en este teléfono». Los guarda la página de pedido recibido, solo en este navegador.
  var savedBox = document.querySelector('[data-my-orders]');
  if (savedBox) {
    var saved = [];
    try { saved = JSON.parse(window.localStorage.getItem('cvd_mis_pedidos') || '[]'); } catch (ignore) { saved = []; }
    saved = Array.isArray(saved) ? saved.filter(function (o) { return o && o.n && typeof o.u === 'string' && o.u.indexOf(location.origin + '/seguimiento/') === 0; }) : [];
    if (saved.length) {
      var savedList = savedBox.querySelector('ul');
      saved.slice(0, 10).forEach(function (o) {
        var li = document.createElement('li'), a = document.createElement('a');
        a.href = o.u; a.className = 'cvd-secondary'; a.textContent = 'Pedido #' + String(o.n).replace(/\D/g, '');
        li.appendChild(a);
        if (o.t) { var when = document.createElement('small'); when.textContent = ' ' + new Date(o.t).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }); li.appendChild(when); }
        savedList.appendChild(li);
      });
      savedBox.hidden = false;
    }
  }

  var watchId = null;
  var activeOrder = 0;
  var lastSentAt = 0;
  var wakeLock = null;

  function setLiveStatus(card, text, error) {
    var status = card && card.querySelector('[data-live-status]');
    if (status) { status.textContent = text; status.classList.toggle('is-error', !!error); }
  }

  function sendLocation(card, position) {
    var now = Date.now();
    if (now - lastSentAt < 10000) return;
    lastSentAt = now;
    var body = {
      latitude: position.coords.latitude,
      longitude: position.coords.longitude,
      accuracy: position.coords.accuracy || 0
    };
    fetch(window.cvdLiveTracking.baseUrl + activeOrder + '/location', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.cvdLiveTracking.restNonce },
      body: JSON.stringify(body)
    }).then(function (response) {
      if (response.status === 409) { stopSharing(card, 'La carrera ya no está en camino.'); return null; }
      if (response.status === 429) return null;
      if (!response.ok) throw new Error('location');
      return response.json();
    }).then(function (result) {
      if (result) setLiveStatus(card, result.saved === false ? 'Activa · sin movimiento' : 'Activa · ubicación enviada');
    }).catch(function () { setLiveStatus(card, 'Sin conexión · reintentando', true); });
  }

  function stopSharing(card, message) {
    if (watchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(watchId);
    watchId = null; activeOrder = 0;
    if (wakeLock && wakeLock.release) wakeLock.release().catch(function () {});
    wakeLock = null;
    var button = card && card.querySelector('[data-live-toggle]');
    if (button) { button.textContent = 'Compartir ubicación'; button.classList.remove('is-sharing'); }
    if (card) setLiveStatus(card, message || 'Desactivada');
  }

  function startSharing(card) {
    if (!navigator.geolocation) { setLiveStatus(card, 'Este teléfono no permite ubicación.', true); return; }
    activeOrder = Number(card.getAttribute('data-live-order'));
    var button = card.querySelector('[data-live-toggle]');
    button.textContent = 'Detener ubicación'; button.classList.add('is-sharing');
    setLiveStatus(card, 'Solicitando permiso…');
    if (navigator.wakeLock && navigator.wakeLock.request) navigator.wakeLock.request('screen').then(function (lock) { wakeLock = lock; }).catch(function () {});
    watchId = navigator.geolocation.watchPosition(
      function (position) { setLiveStatus(card, 'Activa · conectando'); sendLocation(card, position); },
      function (error) { setLiveStatus(card, error.code === 1 ? 'Permiso de ubicación denegado.' : 'No se pudo obtener la ubicación.', true); if (error.code === 1) stopSharing(card, 'Permiso de ubicación denegado.'); },
      { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
    );
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-live-toggle]');
    if (!button) return;
    var card = button.closest('[data-live-order]');
    if (watchId !== null && activeOrder === Number(card.getAttribute('data-live-order'))) stopSharing(card);
    else { if (watchId !== null) stopSharing(document.querySelector('[data-live-order="' + activeOrder + '"]')); startSharing(card); }
  });

  var tracking = document.querySelector('[data-customer-tracking]');
  if (!tracking) return;
  var orderId = Number(tracking.getAttribute('data-customer-tracking'));
  var orderKey = tracking.getAttribute('data-tracking-key');
  var titles = { unassigned:'Estamos preparando tu pedido', offered:'Estamos preparando tu pedido', assigned:'Mensajero asignado', accepted:'Mensajero asignado', to_store:'El mensajero va a recoger tu pedido', picked_up:'Pedido entregado al mensajero', handed_over:'Tu pedido va en camino', delivered:'Pedido entregado', cash_returned:'Pedido entregado', closed:'Pedido completado', incident:'Estamos revisando tu entrega', failed:'No se pudo completar la entrega', returned:'Pedido devuelto a Casa Viva', cancelled:'Pedido cancelado' };

  function updateCustomer(payload) {
    var title = tracking.querySelector('[data-tracking-title]');
    if (title && (payload.title || titles[payload.status])) title.textContent = payload.title || titles[payload.status];
    // Los pasos se vuelven a pintar en cada consulta (antes solo cambiaba el título).
    var stepsList = tracking.querySelector('[data-tracking-steps]');
    if (stepsList && Array.isArray(payload.steps)) {
      stepsList.textContent = '';
      payload.steps.forEach(function (step) {
        var li = document.createElement('li');
        if (step.done) li.className = 'is-complete';
        var strong = document.createElement('strong');
        strong.textContent = step.label;
        li.appendChild(document.createElement('span'));
        li.appendChild(strong);
        stepsList.appendChild(li);
      });
    }
    var courier = tracking.querySelector('[data-tracking-courier]');
    if (courier && typeof payload.courierText === 'string') {
      courier.textContent = payload.courierText;
      courier.hidden = !payload.courierText;
    }
    var locationBox = tracking.querySelector('[data-courier-location]');
    if (locationBox) {
      locationBox.hidden = !payload.location;
      if (payload.location) {
        var time = locationBox.querySelector('[data-location-time]');
        var link = locationBox.querySelector('[data-location-link]');
        if (time) time.textContent = 'Actualizada ' + new Date(payload.location.recordedAt).toLocaleTimeString('es', { hour:'2-digit', minute:'2-digit' });
        if (link) link.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(payload.location.latitude + ',' + payload.location.longitude);
      }
    }
    drawRuta(payload.ruta);
    if (payload.customerConfirmed) {
      var form = tracking.querySelector('[data-delivery-rating]');
      if (form) form.outerHTML = '<div class="cvd-confirmed-delivery"><strong>Entrega confirmada</strong><span>Gracias por evaluar el servicio.</span></div>';
    }
  }

  // 3.14.72.2 (Ernesto 28-oct, «como Mandao»): el mapa del recorrido de Pa'llá. assets/palla/mapa.js (y Leaflet) solo se
  // cargan si hay una carrera en curso; entregado o sin mensajero, el mapa se esconde.
  var mapaCarga = null;
  function drawRuta(r) {
    var box = tracking.querySelector('[data-ruta]');
    if (!box) return;
    if (!r || !r.active || !r.pos) { box.hidden = true; return; } // sin ubicación del mensajero (sin la app), no hay mapa
    box.hidden = false;
    if (!mapaCarga) {
      mapaCarga = window.PallaMapa ? Promise.resolve() : new Promise(function (ok, fail) {
        var s = document.createElement('script');
        s.src = window.cvdLiveTracking.mapaUrl;
        s.onload = ok;
        s.onerror = function () { mapaCarga = null; fail(new Error('mapa')); };
        document.head.appendChild(s);
      });
    }
    mapaCarga.then(function () { return window.PallaMapa.ruta(box.querySelector('[data-ruta-map]'), r); })
      .then(function (txt) { var t = box.querySelector('[data-ruta-text]'); if (t) t.textContent = txt; })
      .catch(function () { var t = box.querySelector('[data-ruta-text]'); if (t) t.textContent = 'El mapa no cargó ahora (¿señal?). Lo intento de nuevo.'; });
  }

  // 3.14.74.3 (fase 3 del mapa): el cliente marca su casa. Se abre el mapa centrado donde está el teléfono (si lo deja),
  // toca su casa y la guarda (POST /delivery/{id}/casa con la clave del seguimiento).
  function loadMapa() {
    if (!mapaCarga) {
      mapaCarga = window.PallaMapa ? Promise.resolve() : new Promise(function (ok, fail) {
        var s = document.createElement('script');
        s.src = window.cvdLiveTracking.mapaUrl;
        s.onload = ok;
        s.onerror = function () { mapaCarga = null; fail(new Error('mapa')); };
        document.head.appendChild(s);
      });
    }
    return mapaCarga;
  }
  var casa = tracking && tracking.querySelector('[data-casa]'), casaPicker = null;
  if (casa) {
    var casaMsg = casa.querySelector('[data-casa-msg]');
    casa.querySelector('[data-casa-abrir]').addEventListener('click', function (event) {
      event.currentTarget.hidden = true;
      var mapEl = casa.querySelector('[data-casa-map]');
      mapEl.hidden = false; mapEl.style.height = '300px';
      casa.querySelector('[data-casa-help]').hidden = false;
      casa.querySelector('[data-casa-guardar]').hidden = false;
      var start = (casa.getAttribute('data-start') || '23.115,-82.397').split(',').map(Number);
      loadMapa().then(function () { return window.PallaMapa.marcar(mapEl, start); }).then(function (picker) {
        casaPicker = picker;
        if (casa.hasAttribute('data-auto')) { picker.put(start); return; } // 3.14.78: la casa sacada de la dirección
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(function (p) {
            if (p.coords.accuracy <= 300 && !picker.get()) picker.put([p.coords.latitude, p.coords.longitude]);
          }, function () {}, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
        }
      }).catch(function () { if (casaMsg) casaMsg.textContent = 'El mapa no cargó ahora (¿señal?). Prueba otra vez.'; });
    });
    casa.querySelector('[data-casa-guardar]').addEventListener('click', function (event) {
      var ll = casaPicker && casaPicker.get();
      if (!ll) { if (casaMsg) casaMsg.textContent = 'Toca tu casa en el mapa primero.'; return; }
      var btn = event.currentTarget;
      btn.disabled = true;
      if (casaMsg) casaMsg.textContent = 'Guardando…';
      fetch(window.cvdLiveTracking.baseUrl + orderId + '/casa', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: orderKey, lat: ll[0], lon: ll[1] }) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (casaMsg) casaMsg.textContent = (d && d.message) || 'Listo.'; if (d && d.ok) { btn.hidden = true; } else { btn.disabled = false; } })
        .catch(function () { btn.disabled = false; if (casaMsg) casaMsg.textContent = 'Sin señal: prueba otra vez.'; });
    });
  }

  function pollTracking() {
    fetch(window.cvdLiveTracking.baseUrl + orderId + '/tracking?key=' + encodeURIComponent(orderKey), { credentials:'omit' })
      .then(function (response) { if (!response.ok) throw new Error('tracking'); return response.json(); })
      .then(updateCustomer).catch(function () {});
  }

  tracking.addEventListener('submit', function (event) {
    var form = event.target.closest('[data-delivery-rating]');
    if (!form) return;
    event.preventDefault();
    var status = form.querySelector('[data-rating-status]');
    var body = { key:orderKey, rating:Number(form.elements.rating.value), comment:form.elements.comment.value };
    if (status) status.textContent = 'Guardando…';
    fetch(window.cvdLiveTracking.baseUrl + orderId + '/tracking', { method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) })
      .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'No se pudo confirmar.'); return data; }); })
      .then(function (data) { updateCustomer(data.tracking); })
      .catch(function (error) { if (status) status.textContent = error.message; });
  });

  pollTracking();
  window.setInterval(pollTracking, 15000);
})();
