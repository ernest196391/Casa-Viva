(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-target]');
    if (!button) return;
    var input = document.getElementById(button.getAttribute('data-copy-target'));
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(function () {
      button.textContent = 'Copiado';
      setTimeout(function () { button.textContent = 'Copiar'; }, 1800);
    });
  });
  var search = document.getElementById('cvd-price-search');
  if (search) search.addEventListener('input', function () {
    var query = search.value.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    document.querySelectorAll('.cvd-price-list > article').forEach(function (item) {
      item.hidden = query && item.getAttribute('data-product-name').indexOf(query) === -1;
    });
  });

  function savePrice(input) {
    if (!input || !window.cvdPortal) return;
    var card = input.closest('article');
    var status = card && card.querySelector('.cvd-save-status');
    var body = new URLSearchParams({ action: 'cvd_save_gestora_price', nonce: window.cvdPortal.nonce, product_id: input.getAttribute('data-product-id'), price: input.value });
    if (status) status.textContent = 'Guardando…';
    fetch(window.cvdPortal.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result.success) throw new Error(result.data && result.data.message || 'No se pudo guardar.');
        if (status) status.textContent = result.data.message;
        input.classList.remove('is-dirty');
      }).catch(function (error) { if (status) status.textContent = error.message; });
  }
  document.addEventListener('input', function (event) { if (event.target.matches('.cvd-price-input')) event.target.classList.add('is-dirty'); });
  document.addEventListener('click', function (event) { var button = event.target.closest('.cvd-save-one'); if (button) savePrice(button.closest('label').querySelector('.cvd-price-input')); });
  document.addEventListener('change', function (event) { if (event.target.matches('.cvd-price-input')) savePrice(event.target); });

  var navLinks = document.querySelectorAll('.cvd-app-nav a');
  function markActiveNav() { var hash = window.location.hash || '#dashboard'; navLinks.forEach(function (link) { link.classList.toggle('is-active', link.getAttribute('href') === hash); }); }
  window.addEventListener('hashchange', markActiveNav); markActiveNav();

  if (window.CVQRCode) document.querySelectorAll('[data-pickup-qr]').forEach(function (canvas) { window.CVQRCode.toCanvas(canvas, canvas.getAttribute('data-pickup-qr'), { width: 360, margin: 2, errorCorrectionLevel: 'M' }); });
  function closeExpandedQr() { var expanded = document.querySelector('.cvd-pickup-qr.is-expanded'); if (expanded) expanded.classList.remove('is-expanded'); document.body.classList.remove('cvd-qr-open'); }
  document.addEventListener('click', function (event) { var button = event.target.closest('[data-qr-expand]'); if (button) { var qr = button.closest('.cvd-pickup-qr'); var opening = !qr.classList.contains('is-expanded'); closeExpandedQr(); if (opening) { qr.classList.add('is-expanded'); document.body.classList.add('cvd-qr-open'); } return; } if (event.target.matches('.cvd-pickup-qr.is-expanded')) closeExpandedQr(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeExpandedQr(); });

  document.addEventListener('click', function (event) {
    var action = event.target.closest('[data-confirm-delivery]'); if (!action) return;
    var labels = { to_store: 'Confirma que vas a recoger el pedido.', handed_over: 'Confirma que vas en camino al cliente.', delivered: 'Confirma que entregaste el pedido al cliente.', failed: 'Confirma que la entrega no pudo completarse.', returned: 'Confirma que devolviste el pedido a Casa Viva.' };
    if (!window.confirm(labels[action.getAttribute('data-confirm-delivery')] || '¿Confirmas este cambio?')) event.preventDefault();
  });
  document.addEventListener('click', function (event) { if (event.target.closest('[data-cvd-refresh-preparation]')) window.location.reload(); });
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-contact-outcome]');
    if (!button || !window.cvdPortal || !window.cvdPortal.messengerContactUrl) return;
    var card = button.closest('article'), status = card && card.querySelector('.cvd-contact-result');
    var id = Number(button.getAttribute('data-contact-order')), outcome = button.getAttribute('data-contact-outcome');
    if (!id || !outcome || button.disabled) return;
    var key = 'contact-' + id + '-' + outcome + '-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    Array.prototype.forEach.call(card.querySelectorAll('[data-contact-outcome]'), function (item) { item.disabled = true; });
    if (status) status.textContent = 'Registrando…';
    fetch(window.cvdPortal.messengerContactUrl + id + '/contact', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':window.cvdPortal.restNonce,'X-CVD-Idempotency-Key':key}, body:JSON.stringify({outcome:outcome,channel:'unspecified'}) })
      .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || 'No se pudo registrar.'); return data; }); })
      .then(function () { if (status) status.textContent = 'Resultado registrado y auditado.'; })
      .catch(function (error) { if (status) status.textContent = error.message; Array.prototype.forEach.call(card.querySelectorAll('[data-contact-outcome]'), function (item) { item.disabled = false; }); });
  });

  var notifyButton = document.getElementById('cvd-enable-notifications');
  var knownOffers = Array.prototype.map.call(document.querySelectorAll('[data-offer-id]'), function (card) { return Number(card.getAttribute('data-offer-id')); });
  var alertAudioContext = null, visibleOfferId = 0;
  function notificationLabel() { if (!notifyButton) return; notifyButton.textContent = !('Notification' in window) ? 'Avisos no compatibles' : Notification.permission === 'granted' ? 'Verificando avisos…' : Notification.permission === 'denied' ? 'Avisos bloqueados' : 'Activar avisos'; notifyButton.disabled = !('Notification' in window) || Notification.permission === 'denied'; if (Notification.permission === 'granted' && window.cvdPushIsSubscribed) window.cvdPushIsSubscribed().then(function (active) { notifyButton.textContent = active ? 'Avisos activados' : 'Activar avisos'; }).catch(function () { notifyButton.textContent = 'Activar avisos'; }); }
  function soundAlert() { try { var Context = window.AudioContext || window.webkitAudioContext; if (!Context) return; alertAudioContext = alertAudioContext || new Context(); if (alertAudioContext.state === 'suspended') alertAudioContext.resume(); var oscillator = alertAudioContext.createOscillator(), gain = alertAudioContext.createGain(); oscillator.frequency.value = 880; gain.gain.value = 0.08; oscillator.connect(gain); gain.connect(alertAudioContext.destination); oscillator.start(); gain.gain.exponentialRampToValueAtTime(0.001, alertAudioContext.currentTime + 0.9); oscillator.stop(alertAudioContext.currentTime + 0.92); } catch (ignore) {} }
  function showOfferAlert(offer) { soundAlert(); if ('Notification' in window && Notification.permission === 'granted') { try { var notice = new Notification('Nueva carrera', { body: offer.zone + ' · ' + Number(offer.earningCup || 0).toLocaleString('es') + ' CUP', tag: 'cvd-offer-' + offer.id }); notice.onclick = function () { window.focus(); window.location.href = window.location.pathname + '#ofertas'; window.location.reload(); }; } catch (ignore) {} } var bar = document.querySelector('.cvd-notification-bar'); if (bar && !bar.querySelector('.cvd-new-offer')) { var link = document.createElement('a'); link.className = 'cvd-primary cvd-new-offer'; link.href = window.location.pathname + '#ofertas'; link.textContent = 'Nueva carrera · Ver oferta'; link.addEventListener('click', function () { window.location.reload(); }); bar.appendChild(link); } var modal = document.getElementById('cvd-offer-modal'); if (modal) { visibleOfferId = Number(offer.id); modal.querySelector('[data-offer-zone]').textContent = offer.zone || 'Zona por confirmar'; modal.querySelector('[data-offer-earning]').textContent = Number(offer.earningCup || 0).toLocaleString('es') + ' CUP'; modal.querySelector('[data-offer-items]').textContent = offer.items || 'Pedido preparado'; modal.querySelector('[data-offer-accept]').href = offer.acceptUrl; modal.querySelector('[data-offer-decline]').href = offer.declineUrl; modal.hidden = false; document.body.classList.add('cvd-modal-open'); } }
  function pollOffers() { if (!window.cvdPortal || !window.cvdPortal.isMessenger || !window.cvdPortal.messengerFeedUrl || document.hidden) return; var feedUrl = new URL(window.cvdPortal.messengerFeedUrl, window.location.origin); feedUrl.searchParams.set('_cvd_poll', Date.now()); fetch(feedUrl.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': window.cvdPortal.restNonce, 'Cache-Control': 'no-cache' } }).then(function (response) { if (!response.ok) throw new Error('feed'); return response.json(); }).then(function (data) { var offers = Array.isArray(data.offers) ? data.offers : [], deliveries = Array.isArray(data.deliveries) ? data.deliveries : [], visibleDeliveries = Array.prototype.slice.call(document.querySelectorAll('[data-delivery-id]')); var deliveryChanged = visibleDeliveries.some(function (card) { var current = deliveries.find(function (delivery) { return Number(delivery.id) === Number(card.getAttribute('data-delivery-id')); }); return !current || current.status !== card.getAttribute('data-delivery-status') || String(current.operationStatus || '') !== String(card.getAttribute('data-operation-status') || ''); }) || deliveries.some(function (delivery) { return !document.querySelector('[data-delivery-id="' + Number(delivery.id) + '"]'); }); if (deliveryChanged) { window.location.reload(); return; } if (visibleOfferId && !offers.some(function (offer) { return Number(offer.id) === visibleOfferId; })) { var openModal = document.getElementById('cvd-offer-modal'); if (openModal) openModal.hidden = true; document.body.classList.remove('cvd-modal-open'); visibleOfferId = 0; } var fresh = offers.filter(function (offer) { return knownOffers.indexOf(Number(offer.id)) === -1 || sessionStorage.getItem('cvd-offer-alerted-' + offer.id) !== 'yes'; }); knownOffers = offers.map(function (offer) { return Number(offer.id); }); if (fresh.length) { sessionStorage.setItem('cvd-offer-alerted-' + fresh[0].id, 'yes'); showOfferAlert(fresh[0]); } }).catch(function () {}); }
  if (notifyButton) notifyButton.addEventListener('click', function () { try { var Context = window.AudioContext || window.webkitAudioContext; alertAudioContext = alertAudioContext || (Context ? new Context() : null); if (alertAudioContext) alertAudioContext.resume(); } catch (ignore) {} notifyButton.disabled = true; notifyButton.textContent = 'Activando…'; var action = window.cvdPushToggle ? window.cvdPushToggle() : Promise.reject(new Error('Avisos no disponibles.')); action.then(function () { notifyButton.disabled = false; notificationLabel(); pollOffers(); }).catch(function (error) { notifyButton.disabled = false; notifyButton.textContent = error.message; }); });
  notificationLabel(); if (window.cvdPortal && window.cvdPortal.isMessenger) { pollOffers(); window.setInterval(pollOffers, 8000); document.addEventListener('visibilitychange', function () { if (!document.hidden) pollOffers(); }); }

  var inbox = document.querySelector('[data-notification-inbox]');
  function escapeHtml(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]; }); }
  function renderInbox(items) { if (!inbox) return; if (!items.length) { inbox.innerHTML = '<p class="cvd-empty-state">No tienes avisos pendientes.</p>'; return; } inbox.innerHTML = items.map(function (item) { var actionUrl = String(item.action_url || ''); if (!actionUrl.startsWith('/') && !actionUrl.startsWith(window.location.origin + '/')) actionUrl = '#avisos'; return '<a class="cvd-inbox-item' + (item.read_at ? '' : ' is-unread') + '" href="' + escapeHtml(actionUrl) + '" data-notification-id="' + Number(item.id) + '"><span></span><div><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.message) + '</small></div><time>' + escapeHtml(item.created_at) + '</time></a>'; }).join(''); }
  function loadInbox() { if (!inbox || !window.cvdPwa) return; fetch(window.cvdPwa.notificationsUrl, {credentials:'same-origin',headers:{'X-WP-Nonce':window.cvdPwa.restNonce}}).then(function(r){return r.json();}).then(function(data){renderInbox(data.notifications||[]);}).catch(function(){}); }
  document.addEventListener('click', function(event) { var item=event.target.closest('[data-notification-id]'); if(!item||!window.cvdPwa)return; fetch(window.cvdPwa.notificationsUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':window.cvdPwa.restNonce},body:JSON.stringify({id:Number(item.getAttribute('data-notification-id'))})}).catch(function(){}); });
  loadInbox();

  function initializeMessengerRoute() {
    var route = document.querySelector('[data-route-list]');
    if (!route) return;
    var list = route.querySelector('.cvd-route-list');
    if (!list) return;
    var storageKey = route.getAttribute('data-route-key') || 'cvd-route-session';
    function stops() { return Array.prototype.slice.call(list.querySelectorAll('[data-route-stop]')); }
    function updatePositions() {
      var current = stops(), total = current.length;
      current.forEach(function (stop, index) {
        var label = stop.querySelector('.cvd-route-position'); if (label) label.textContent = 'Parada ' + (index + 1) + ' de ' + total;
        var up = stop.querySelector('[data-route-up]'), down = stop.querySelector('[data-route-down]');
        if (up) up.disabled = index === 0; if (down) down.disabled = index === total - 1;
      });
      try { sessionStorage.setItem(storageKey, JSON.stringify(current.map(function (stop) { return stop.getAttribute('data-route-stop'); }))); } catch (ignore) {}
    }
    try {
      var saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
      if (Array.isArray(saved)) saved.forEach(function (id) { var stop = list.querySelector('[data-route-stop="' + String(id).replace(/[^0-9]/g, '') + '"]'); if (stop) list.appendChild(stop); });
    } catch (ignore) {}
    route.addEventListener('click', function (event) {
      var button = event.target.closest('[data-route-up],[data-route-down],[data-route-reset]'); if (!button) return;
      if (button.matches('[data-route-reset]')) { try { sessionStorage.removeItem(storageKey); } catch (ignore) {} window.location.reload(); return; }
      var stop = button.closest('[data-route-stop]'); if (!stop) return;
      if (button.matches('[data-route-up]') && stop.previousElementSibling) list.insertBefore(stop, stop.previousElementSibling);
      if (button.matches('[data-route-down]') && stop.nextElementSibling) list.insertBefore(stop.nextElementSibling, stop);
      updatePositions(); stop.focus({ preventScroll: true });
    });
    stops().forEach(function (stop) { stop.setAttribute('tabindex', '-1'); }); updatePositions();
  }
  initializeMessengerRoute();

  function enhanceMessengerCenter() {
    if (!window.cvdPortal || !window.cvdPortal.isMessenger) return;
    var shell = document.querySelector('.cvd-dashboard.cvd-app-shell');
    var deliveriesPanel = document.getElementById('entregas');
    if (!shell || !deliveriesPanel) return;
    shell.classList.add('cvd-messenger-center');
    var cards = Array.prototype.slice.call(deliveriesPanel.querySelectorAll('[data-delivery-id]'));
    cards.forEach(function (card) {
      var status = card.getAttribute('data-delivery-status') || '';
      card.classList.add('cvd-messenger-job');
      var footer = card.querySelector('footer');
      if (footer) {
        var primary = footer.querySelector('a[data-confirm-delivery]:not([data-confirm-delivery="incident"])');
        if (primary) primary.classList.add('cvd-primary', 'cvd-messenger-primary');
      }
      if (['accepted','to_store','picked_up','handed_over'].indexOf(status) !== -1) {
        if (card.querySelector('.cvd-messenger-tools')) return;
        var small = card.querySelector('header small');
        var text = small ? small.textContent : '';
        var phoneMatch = text.match(/(?:\+?\d[\d\s().-]{6,}\d)/);
        var phone = phoneMatch ? phoneMatch[0].replace(/\D+/g, '') : '';
        var tools = document.createElement('div'); tools.className = 'cvd-messenger-tools';
        if (phone) {
          var wa = document.createElement('a'); wa.href = 'https://wa.me/' + phone; wa.target = '_blank'; wa.rel = 'noopener'; wa.textContent = 'WhatsApp'; wa.className = 'cvd-secondary'; tools.appendChild(wa);
          var call = document.createElement('a'); call.href = 'tel:+' + phone; call.textContent = 'Llamar'; call.className = 'cvd-secondary'; tools.appendChild(call);
        }
        var map = card.querySelector('.cvd-map-action');
        if (map) { map.textContent = 'Navegar'; map.classList.add('cvd-secondary'); tools.appendChild(map); }
        if (tools.children.length) { var header = card.querySelector('header'); if (header) header.insertAdjacentElement('afterend', tools); }
      }
    });
    if (cards.length) {
      deliveriesPanel.classList.add('cvd-messenger-focus');
      var first = cards[0]; first.classList.add('is-current');
      var title = deliveriesPanel.querySelector('h2'); if (title) title.textContent = 'Entrega activa';
      var nav = document.querySelector('.cvd-messenger-nav a[href="#entregas"]'); if (nav) nav.textContent = 'Entrega activa';
    }
    var style = document.createElement('style');
    style.textContent = '.cvd-messenger-center #entregas{order:-3}.cvd-messenger-center #ofertas{order:-2}.cvd-messenger-job{border:1px solid #e7e2d9;border-radius:18px;padding:18px;background:#fff}.cvd-messenger-job.is-current{box-shadow:0 10px 28px rgba(0,0,0,.08)}.cvd-messenger-tools{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.cvd-messenger-tools a{text-align:center;min-height:44px;display:flex;align-items:center;justify-content:center}.cvd-messenger-job footer{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.cvd-messenger-primary{flex:1 1 220px;min-height:48px;text-align:center;display:flex!important;align-items:center;justify-content:center;font-weight:700}@media(max-width:480px){.cvd-messenger-tools{grid-template-columns:1fr 1fr}.cvd-messenger-tools a:last-child:nth-child(3){grid-column:1/-1}.cvd-messenger-job{padding:14px}.cvd-messenger-primary{position:sticky;bottom:10px;z-index:4}}';
    document.head.appendChild(style);
  }
  enhanceMessengerCenter();
})();
