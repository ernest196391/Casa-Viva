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
    var body = new URLSearchParams({
      action: 'cvd_save_gestora_price',
      nonce: window.cvdPortal.nonce,
      product_id: input.getAttribute('data-product-id'),
      price: input.value
    });
    if (status) status.textContent = 'Guardando…';
    fetch(window.cvdPortal.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result.success) throw new Error(result.data && result.data.message || 'No se pudo guardar.');
        if (status) status.textContent = result.data.message;
        input.classList.remove('is-dirty');
      })
      .catch(function (error) { if (status) status.textContent = error.message; });
  }

  document.addEventListener('input', function (event) {
    if (event.target.matches('.cvd-price-input')) event.target.classList.add('is-dirty');
  });
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.cvd-save-one');
    if (button) savePrice(button.closest('label').querySelector('.cvd-price-input'));
  });
  document.addEventListener('change', function (event) {
    if (event.target.matches('.cvd-price-input')) savePrice(event.target);
  });

  var navLinks = document.querySelectorAll('.cvd-app-nav a');
  function markActiveNav() {
    var hash = window.location.hash || '#dashboard';
    navLinks.forEach(function (link) { link.classList.toggle('is-active', link.getAttribute('href') === hash); });
  }
  window.addEventListener('hashchange', markActiveNav);
  markActiveNav();

  if (window.CVQRCode) document.querySelectorAll('[data-pickup-qr]').forEach(function (canvas) {
    window.CVQRCode.toCanvas(canvas, canvas.getAttribute('data-pickup-qr'), { width: 360, margin: 2, errorCorrectionLevel: 'M' });
  });

  function closeExpandedQr() {
    var expanded = document.querySelector('.cvd-pickup-qr.is-expanded');
    if (expanded) expanded.classList.remove('is-expanded');
    document.body.classList.remove('cvd-qr-open');
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-qr-expand]');
    if (button) {
      var qr = button.closest('.cvd-pickup-qr');
      var opening = !qr.classList.contains('is-expanded');
      closeExpandedQr();
      if (opening) { qr.classList.add('is-expanded'); document.body.classList.add('cvd-qr-open'); }
      return;
    }
    if (event.target.matches('.cvd-pickup-qr.is-expanded')) closeExpandedQr();
  });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeExpandedQr(); });

  document.addEventListener('click', function (event) {
    var action = event.target.closest('[data-confirm-delivery]');
    if (!action) return;
    var labels = { to_store: 'Confirma que vas a recoger el pedido.', handed_over: 'Confirma que vas en camino al cliente.', delivered: 'Confirma que entregaste el pedido al cliente.', failed: 'Confirma que la entrega no pudo completarse.', returned: 'Confirma que devolviste el pedido a Casa Viva.' };
    if (!window.confirm(labels[action.getAttribute('data-confirm-delivery')] || '¿Confirmas este cambio?')) event.preventDefault();
  });

  var notifyButton = document.getElementById('cvd-enable-notifications');
  var knownOffers = Array.prototype.map.call(document.querySelectorAll('[data-offer-id]'), function (card) { return Number(card.getAttribute('data-offer-id')); });
  var alertAudioContext = null;
  var visibleOfferId = 0;
  function notificationLabel() {
    if (!notifyButton) return;
    notifyButton.textContent = !('Notification' in window) ? 'Avisos no compatibles' : Notification.permission === 'granted' ? 'Verificando avisos…' : Notification.permission === 'denied' ? 'Avisos bloqueados' : 'Activar avisos';
    notifyButton.disabled = !('Notification' in window) || Notification.permission === 'denied';
    if (Notification.permission === 'granted' && window.cvdPushIsSubscribed) {
      window.cvdPushIsSubscribed().then(function (active) {
        notifyButton.textContent = active ? 'Avisos activados' : 'Activar avisos';
      }).catch(function () { notifyButton.textContent = 'Activar avisos'; });
    }
  }
  function soundAlert() {
    try {
      var Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) return;
      alertAudioContext = alertAudioContext || new Context();
      if (alertAudioContext.state === 'suspended') alertAudioContext.resume();
      var oscillator = alertAudioContext.createOscillator();
      var gain = alertAudioContext.createGain();
      oscillator.frequency.value = 880; gain.gain.value = 0.08;
      oscillator.connect(gain); gain.connect(alertAudioContext.destination); oscillator.start();
      gain.gain.exponentialRampToValueAtTime(0.001, alertAudioContext.currentTime + 0.9); oscillator.stop(alertAudioContext.currentTime + 0.92);
    } catch (ignore) {}
  }
  function showOfferAlert(offer) {
    soundAlert();
    if ('Notification' in window && Notification.permission === 'granted') {
      try {
        var notice = new Notification('Nueva carrera', { body: offer.zone + ' · ' + Number(offer.earningCup || 0).toLocaleString('es') + ' CUP', tag: 'cvd-offer-' + offer.id });
        notice.onclick = function () { window.focus(); window.location.href = window.location.pathname + '#ofertas'; window.location.reload(); };
      } catch (ignore) {}
    }
    var bar = document.querySelector('.cvd-notification-bar');
    if (bar && !bar.querySelector('.cvd-new-offer')) {
      var link = document.createElement('a'); link.className = 'cvd-primary cvd-new-offer'; link.href = window.location.pathname + '#ofertas'; link.textContent = 'Nueva carrera · Ver oferta';
      link.addEventListener('click', function () { window.location.reload(); }); bar.appendChild(link);
    }
    var modal = document.getElementById('cvd-offer-modal');
    if (modal) {
      visibleOfferId = Number(offer.id);
      modal.querySelector('[data-offer-zone]').textContent = offer.zone || 'Zona por confirmar';
      modal.querySelector('[data-offer-earning]').textContent = Number(offer.earningCup || 0).toLocaleString('es') + ' CUP';
      modal.querySelector('[data-offer-items]').textContent = offer.items || 'Pedido preparado';
      modal.querySelector('[data-offer-accept]').href = offer.acceptUrl;
      modal.querySelector('[data-offer-decline]').href = offer.declineUrl;
      modal.hidden = false;
      document.body.classList.add('cvd-modal-open');
    }
  }
  function pollOffers() {
    if (!window.cvdPortal || !window.cvdPortal.isMessenger || !window.cvdPortal.messengerFeedUrl || document.hidden) return;
    var feedUrl = new URL(window.cvdPortal.messengerFeedUrl, window.location.origin);
    feedUrl.searchParams.set('_cvd_poll', Date.now());
    fetch(feedUrl.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': window.cvdPortal.restNonce, 'Cache-Control': 'no-cache' } })
      .then(function (response) { if (!response.ok) throw new Error('feed'); return response.json(); })
      .then(function (data) {
        var offers = Array.isArray(data.offers) ? data.offers : [];
		var deliveries = Array.isArray(data.deliveries) ? data.deliveries : [];
		var visibleDeliveries = Array.prototype.slice.call(document.querySelectorAll('[data-delivery-id]'));
		var deliveryChanged = visibleDeliveries.some(function (card) {
			var current = deliveries.find(function (delivery) { return Number(delivery.id) === Number(card.getAttribute('data-delivery-id')); });
			return !current || current.status !== card.getAttribute('data-delivery-status');
		}) || deliveries.some(function (delivery) { return !document.querySelector('[data-delivery-id="' + Number(delivery.id) + '"]'); });
		if (deliveryChanged) { window.location.reload(); return; }
		if (visibleOfferId && !offers.some(function(offer){return Number(offer.id)===visibleOfferId;})) {
			var openModal=document.getElementById('cvd-offer-modal'); if(openModal)openModal.hidden=true; document.body.classList.remove('cvd-modal-open'); visibleOfferId=0;
		}
        var fresh = offers.filter(function (offer) { return knownOffers.indexOf(Number(offer.id)) === -1 || sessionStorage.getItem('cvd-offer-alerted-' + offer.id) !== 'yes'; });
        knownOffers = offers.map(function (offer) { return Number(offer.id); });
        if (fresh.length) {
          sessionStorage.setItem('cvd-offer-alerted-' + fresh[0].id, 'yes');
          showOfferAlert(fresh[0]);
        }
      }).catch(function () {});
  }
  if (notifyButton) notifyButton.addEventListener('click', function () {
    try { var Context = window.AudioContext || window.webkitAudioContext; alertAudioContext = alertAudioContext || (Context ? new Context() : null); if (alertAudioContext) alertAudioContext.resume(); } catch (ignore) {}
    notifyButton.disabled = true; notifyButton.textContent = 'Activando…';
    var action = window.cvdPushToggle ? window.cvdPushToggle() : Promise.reject(new Error('Avisos no disponibles.'));
    action.then(function () { notifyButton.disabled = false; notificationLabel(); pollOffers(); }).catch(function (error) { notifyButton.disabled = false; notifyButton.textContent = error.message; });
  });
  notificationLabel();
  if (window.cvdPortal && window.cvdPortal.isMessenger) {
    pollOffers();
    window.setInterval(pollOffers, 8000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pollOffers(); });
  }

  var inbox = document.querySelector('[data-notification-inbox]');
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
    });
  }
  function renderInbox(items) {
    if (!inbox) return;
    if (!items.length) { inbox.innerHTML = '<p class="cvd-empty-state">No tienes avisos pendientes.</p>'; return; }
    inbox.innerHTML = items.map(function (item) {
      var actionUrl = String(item.action_url || '');
      if (!actionUrl.startsWith('/') && !actionUrl.startsWith(window.location.origin + '/')) actionUrl = '#avisos';
      return '<a class="cvd-inbox-item' + (item.read_at ? '' : ' is-unread') + '" href="' + escapeHtml(actionUrl) + '" data-notification-id="' + Number(item.id) + '"><span></span><div><strong>' + escapeHtml(item.title) + '</strong><small>' + escapeHtml(item.message) + '</small></div><time>' + escapeHtml(item.created_at) + '</time></a>';
    }).join('');
  }
  function loadInbox() {
    if (!inbox || !window.cvdPwa) return;
    fetch(window.cvdPwa.notificationsUrl, {credentials:'same-origin',headers:{'X-WP-Nonce':window.cvdPwa.restNonce}}).then(function(r){return r.json();}).then(function(data){renderInbox(data.notifications||[]);}).catch(function(){});
  }
  document.addEventListener('click', function(event) {
    var item=event.target.closest('[data-notification-id]'); if(!item||!window.cvdPwa)return;
    fetch(window.cvdPwa.notificationsUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':window.cvdPwa.restNonce},body:JSON.stringify({id:Number(item.getAttribute('data-notification-id'))})}).catch(function(){});
  });
  loadInbox();
})();
