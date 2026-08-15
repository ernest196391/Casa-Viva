(function () {
  'use strict';
  if (!window.cvdLiveTracking) return;
  var linkForm=document.querySelector('[data-tracking-link-form]');
  if(linkForm)linkForm.addEventListener('submit',function(event){event.preventDefault();var input=linkForm.querySelector('input'),error=linkForm.querySelector('[data-tracking-link-error]');try{var url=new URL(input.value.trim());if(url.origin!==location.origin||url.pathname.replace(/\/+$/,'')!=='/seguimiento'||!url.searchParams.get('pedido')||!url.searchParams.get('clave'))throw new Error('invalid');location.assign(url.href);}catch(ignore){error.textContent='Ese enlace no es válido. Copia el enlace completo recibido con el pedido.';}});

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
    if (title && titles[payload.status]) title.textContent = titles[payload.status];
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
    if (payload.customerConfirmed) {
      var form = tracking.querySelector('[data-delivery-rating]');
      if (form) form.outerHTML = '<div class="cvd-confirmed-delivery"><strong>Entrega confirmada</strong><span>Gracias por evaluar el servicio.</span></div>';
    }
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
