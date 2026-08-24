(function () {
  'use strict';

  if (!window.fetch) return;

  var nativeFetch = window.fetch.bind(window);

  function isMessengerFeed(input) {
    try {
      var raw = typeof input === 'string' ? input : (input && input.url) || '';
      var url = new URL(raw, window.location.origin);
      return url.origin === window.location.origin && url.pathname.indexOf('/wp-json/casa-viva/v1/messenger/feed') !== -1;
    } catch (ignore) {
      return false;
    }
  }

  function visibleDeliverySnapshot() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-delivery-id]')).map(function (card) {
      return {
        id: Number(card.getAttribute('data-delivery-id')),
        status: String(card.getAttribute('data-delivery-status') || ''),
        operationStatus: String(card.getAttribute('data-operation-status') || '')
      };
    }).filter(function (item) { return item.id > 0; });
  }

  window.fetch = function (input, init) {
    return nativeFetch(input, init).then(function (response) {
      if (!isMessengerFeed(input) || !response || !response.ok) return response;

      return response.clone().json().then(function (data) {
        if (!data || !Array.isArray(data.deliveries)) return response;

        var visible = visibleDeliverySnapshot();
        var visibleIds = visible.map(function (item) { return item.id; });
        var aligned = data.deliveries.filter(function (delivery) {
          return visibleIds.indexOf(Number(delivery && delivery.id)) !== -1;
        });

        visible.forEach(function (snapshot) {
          var exists = aligned.some(function (delivery) { return Number(delivery && delivery.id) === snapshot.id; });
          if (!exists) aligned.push(snapshot);
        });

        var normalized = Object.assign({}, data, { deliveries: aligned });
        var headers = new Headers(response.headers);
        headers.set('Content-Type', 'application/json; charset=UTF-8');
        return new Response(JSON.stringify(normalized), {
          status: response.status,
          statusText: response.statusText,
          headers: headers
        });
      }).catch(function () {
        return response;
      });
    });
  };
}());
