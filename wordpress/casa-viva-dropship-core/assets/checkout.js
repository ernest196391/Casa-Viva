(function ($) {
  'use strict';

  function escapeHtml(value) {
    return $('<div>').text(value).html();
  }

  function populateMunicipalities(keepCurrent) {
    var $state = $('#billing_state');
    var $city = $('#billing_city');
    if (!$state.length || !$city.length || typeof cvdCheckout === 'undefined') return;

    var state = $state.val() || cvdCheckout.defaultState;
    var current = keepCurrent ? $city.val() : '';
    var items = cvdCheckout.municipalities[state] || [];
    var html = '<option value="">Selecciona el municipio</option>';

    items.forEach(function (municipality) {
      var selected = municipality === current ? ' selected' : '';
      html += '<option value="' + escapeHtml(municipality) + '"' + selected + '>' + escapeHtml(municipality) + '</option>';
    });
    if ($city.hasClass('select2-hidden-accessible') && $city.selectWoo) $city.selectWoo('destroy');
    $city.html(html);
    if ($city.selectWoo) $city.selectWoo({ width: '100%', placeholder: 'Busca tu municipio' });
  }

  // Reparto con errata (3.13.53): misma regla que CVD_Shipping_Rates::match_zone() en el servidor.
  // Pasó: «Mitamar» (Playa) se guardó tal cual, sin tarifa, y el vale del mensajero salió sin mensajería.
  function plainZone(v) { return String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); }
  function looseZone(v) { return plainZone(v).replace(/[^a-z0-9]+/g, ' ').trim().replace(/^(reparto|rpto|repto|barrio) /, '').replace(/\s+/g, ' ').trim(); }
  // Letras distintas (dos letras cambiadas de sitio cuentan 1: «Kohly» ↔ «Kholy»), igual que en el servidor.
  function lev(a, b) {
    var d = [], i, j;
    for (i = 0; i <= a.length; i++) { d[i] = [i]; }
    for (j = 0; j <= b.length; j++) d[0][j] = j;
    for (i = 1; i <= a.length; i++) {
      for (j = 1; j <= b.length; j++) {
        d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
      }
    }
    return d[a.length][b.length];
  }
  function matchZone(city, typed) {
    var list = (cvdCheckout.localities || {})[city] || [];
    var key = plainZone(typed);
    if (!key || !list.length) return { zone: '', how: 'none' };
    for (var k = 0; k < list.length; k++) if (plainZone(list[k]) === key) return { zone: list[k], how: 'exact' };
    var t = looseZone(typed), m = looseZone(city);
    var without = m ? (' ' + t + ' ').split(' ' + m + ' ').join(' ').replace(/\s+/g, ' ').trim() : t;
    if (without) t = without;
    if (!t) return { zone: '', how: 'none' };
    var best = {}, bestD = Infinity;
    list.forEach(function (name) {
      var o = looseZone(name), d;
      if (!o) return;
      if (o === t || (o.length >= 4 && (' ' + t + ' ').indexOf(' ' + o + ' ') !== -1)) d = 0;
      else { d = lev(t, o); if (d > (o.length >= 6 ? 2 : o.length >= 4 ? 1 : 0)) return; }
      if (d < bestD) { bestD = d; best = {}; best[o] = name; } else if (d === bestD && !best[o]) best[o] = name;
    });
    var keys = Object.keys(best);
    if (!keys.length) return { zone: '', how: 'none' };
    if (keys.length > 1) {
      if (bestD !== 0) return { zone: '', how: 'none' };
      var max = Math.max.apply(null, keys.map(function (x) { return x.length; }));
      keys = keys.filter(function (x) { return x.length === max; });
      if (keys.length !== 1) return { zone: '', how: 'none' };
    }
    return { zone: best[keys[0]], how: 'fuzzy' };
  }
  function localityHint(text, tone) {
    var $field = $('#billing_cvd_locality_field');
    var $hint = $('#cvd-locality-hint');
    if (!text) { $hint.remove(); return; }
    if (!$hint.length) $hint = $('<p>', { id: 'cvd-locality-hint', class: 'cvd-locality-hint', 'aria-live': 'polite' }).appendTo($field);
    $hint.text(text).css({ margin: '6px 0 0', fontSize: '.9em', fontWeight: 600, color: tone === 'bad' ? '#b3261e' : '#2e7d32' });
  }
  // Al salir del campo: si no es exactamente uno de la lista, se corrige la errata o se avisa claro (no se bloquea la compra).
  function checkLocality() {
    var $locality = $('#billing_cvd_locality');
    if (!$locality.length || typeof cvdCheckout === 'undefined') return;
    var city = $('#billing_city').val(), typed = $.trim($locality.val() || '');
    var list = (cvdCheckout.localities || {})[city] || [];
    if (!typed || !list.length) { localityHint(''); return; }
    var found = matchZone(city, typed);
    if (found.how === 'exact') { localityHint(''); return; }
    if (found.how === 'fuzzy') {
      $locality.val(found.zone).attr('value', found.zone);
      localityHint('Lo cambiamos a «' + found.zone + '», de la lista de ' + city + '. Si no es tu reparto, elige otro de la lista.', 'ok');
      $(document.body).trigger('update_checkout');
      return;
    }
    localityHint('«' + typed + '» no está en la lista de ' + city + '. Toca tu reparto en la lista para ver el precio de la mensajería. Si de verdad no aparece, déjalo así: la tienda te confirma la mensajería.', 'bad');
  }

  function populateLocalities() {
    var $locality = $('#billing_cvd_locality');
    if (!$locality.length || typeof cvdCheckout === 'undefined') return;

    var city = $('#billing_city').val();
    var values = cvdCheckout.localities[city] || [];
    var query = plainZone($locality.val() || '');
    var filtered = values.filter(function (value) { return !query || plainZone(value).indexOf(query) !== -1; });
    // Nada coincide (errata): se enseña la lista entera del municipio para que elija de ahí.
    var all = query && !filtered.length && values.length;
    if (all) filtered = values;
    var $list = $('#cvd-locality-suggestions');
    if (!$list.length) {
      $list = $('<div>', { id: 'cvd-locality-suggestions', class: 'cvd-predictive-list', role: 'listbox' }).hide();
      $locality.attr({ autocomplete: 'off', role: 'combobox', 'aria-controls': 'cvd-locality-suggestions' }).after($list);
    }
    $locality.attr('placeholder', values.length ? 'Toca y elige tu reparto de la lista' : 'Escribe tu reparto');
    $list.empty();
    if (all) $list.append($('<div>', { class: 'cvd-predictive-status', text: 'No encontramos «' + $locality.val() + '». Repartos de ' + city + ':' }));
    filtered.forEach(function (value) {
      $list.append($('<button>', { type: 'button', class: 'cvd-predictive-option', role: 'option', text: value, 'data-value': value }));
    });
    if ($locality.is(':focus') && filtered.length) $list.show();
  }

  function updateShippingPreview() {
    var $field = $('#billing_cvd_locality_field');
    if (!$field.length) return;
    var $preview = $('#cvd-shipping-preview');
    if (!$preview.length) $preview = $('<div>', { id: 'cvd-shipping-preview', class: 'cvd-shipping-preview', 'aria-live': 'polite' }).insertAfter($field);
    var value = $('.cvd-shipping-cup td').text().replace(/\s+/g, ' ').trim();
    $preview.html('<span>Mensajería estimada</span><strong>' + escapeHtml(value || 'Selecciona un reparto') + '</strong>');
  }

  var addressTimer = null;
  var addressController = null;
  function prepareAddressAutocomplete() {
    var $address = $('#billing_address_1');
    if (!$address.length || $('#cvd-address-suggestions').length) return;
    var $list = $('<div>', { id: 'cvd-address-suggestions', class: 'cvd-predictive-list cvd-address-list', role: 'listbox' }).hide();
    var $status = $('<small>', { class: 'cvd-address-help', text: 'Escribe una calle o dirección. Puedes elegir una sugerencia o continuar manualmente.' });
    $address.attr({ autocomplete: 'off', role: 'combobox', 'aria-controls': 'cvd-address-suggestions' }).after($status).after($list);
  }

  function searchAddresses() {
    var query = ($('#billing_address_1').val() || '').trim();
    var $list = $('#cvd-address-suggestions');
    if (query.length < 3) { $list.hide().empty(); return; }
    if (addressController) addressController.abort();
    addressController = new AbortController();
    $list.html('<div class="cvd-predictive-status">Buscando direcciones…</div>').show();
    var url = cvdCheckout.ajaxUrl + '?action=cvd_address_search&nonce=' + encodeURIComponent(cvdCheckout.addressNonce) +
      '&query=' + encodeURIComponent(query) + '&municipality=' + encodeURIComponent($('#billing_city').val() || '') +
      '&zone=' + encodeURIComponent($('#billing_cvd_locality').val() || '');
    fetch(url, { credentials: 'same-origin', signal: addressController.signal }).then(function (response) { return response.json(); }).then(function (response) {
      var items = response && response.success && Array.isArray(response.data) ? response.data : [];
      $list.empty();
      if (!items.length) { $list.html('<div class="cvd-predictive-status">No aparece en el mapa. Puedes escribirla manualmente.</div>').show(); return; }
      items.forEach(function (item) {
        $list.append($('<button>', { type: 'button', class: 'cvd-predictive-option cvd-address-option', text: item.label, 'data-value': item.label, 'data-map': item.map }));
      });
      $list.show();
    }).catch(function (error) { if (error.name !== 'AbortError') $list.hide(); });
  }

  function toggleDeliveryFields() {
    var type = $('input[name="billing_cvd_fulfillment_type"]:checked').val() || 'delivery';
    var delivery = type === 'delivery';
    $('.cvd-delivery-field').toggle(delivery);
	$('.cvd-delivery-field input, .cvd-delivery-field select').attr('aria-hidden', delivery ? 'false' : 'true');
    $('.cvd-pickup-note').remove();
    if (!delivery) {
      $('.cvd-fulfillment').append(
        $('<div>', { class: 'cvd-pickup-note', text: 'Recogida: ' + cvdCheckout.pickupAddress })
      );
    }
  }

	function prepareLocationButton() {
	  var $field = $('#billing_cvd_map_url');
	  if (!$field.length || $('#cvd-use-location').length) return;
	  var $button = $('<button>', {
		type: 'button',
		id: 'cvd-use-location',
		class: 'cvd-location-button',
		text: 'Usar ubicación del lugar de entrega'
	  });
	  var $status = $('<span>', { class: 'cvd-location-status', 'aria-live': 'polite' });
	  var $view = $('<a>', { class: 'cvd-location-view', target: '_blank', rel: 'noopener', text: 'Ver ubicación guardada' }).hide();
	  $field.after($view).after($status).after($button);
	  if ($field.val()) {
		$field.attr('type', 'hidden');
		$view.attr('href', $field.val()).show();
	  }

	  $button.on('click', function () {
		if (!navigator.geolocation) {
		  $status.text('Tu navegador no permite obtener la ubicación. Puedes pegar un enlace de Maps.');
		  return;
		}
		$button.prop('disabled', true).text('Obteniendo ubicación…');
		navigator.geolocation.getCurrentPosition(function (position) {
		  var lat = position.coords.latitude.toFixed(6);
		  var lng = position.coords.longitude.toFixed(6);
		  if (Number(lat) < 19.5 || Number(lat) > 23.5 || Number(lng) < -85.3 || Number(lng) > -73.8) {
			$status.text('Esta ubicación parece estar fuera de Cuba. Debe compartirla la persona que recibirá el pedido.');
			$button.prop('disabled', false).text('Usar ubicación del lugar de entrega');
			return;
		  }
		  $field.val('https://www.google.com/maps?q=' + lat + ',' + lng).trigger('change');
		  $field.attr('type', 'hidden');
		  $('#billing_cvd_map_accuracy').val(Math.round(position.coords.accuracy || 0));
		  $view.attr('href', $field.val()).show();
		  var accuracy = Math.round(position.coords.accuracy || 0);
		  $status.text('Ubicación guardada' + (accuracy ? ' · precisión aproximada ' + accuracy + ' m.' : '.'));
		  $button.prop('disabled', false).text('Actualizar ubicación');
		}, function () {
		  $status.text('No pudimos obtenerla. Permite la ubicación o pega un enlace de Maps.');
		  $button.prop('disabled', false).text('Usar ubicación del lugar de entrega');
		}, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
	  });
	}

  $(document.body).on('change', '#billing_state', function () {
    populateMunicipalities(false);
    populateLocalities();
  });
  $(document.body).on('change', '#billing_city', function () {
    populateLocalities();
    checkLocality();
    $(document.body).trigger('update_checkout');
  });
  // Pequeña espera: si tocó una sugerencia (pointerdown), primero se pone esa y luego se revisa.
  $(document.body).on('blur', '#billing_cvd_locality', function () { setTimeout(checkLocality, 180); });
  var localityTimer = null;
  $(document.body).on('input change', '#billing_cvd_locality', function () {
    populateLocalities();
    clearTimeout(localityTimer);
    localityTimer = setTimeout(function () { $(document.body).trigger('update_checkout'); }, 350);
  });
  $(document.body).on('focus', '#billing_cvd_locality', populateLocalities);
  function chooseLocality(event) {
    if ($(this).hasClass('cvd-address-option')) return;
    event.preventDefault();
    event.stopPropagation();
    var value = this.getAttribute('data-value') || '';
    if (!value) return;
    clearTimeout(localityTimer);
    $('#billing_cvd_locality').val(value).attr('value', value).attr('aria-expanded', 'false');
    $('#cvd-locality-suggestions').hide().empty();
    localityHint('');
    $(document.body).trigger('update_checkout');
  }
  $(document.body).on('pointerdown', '.cvd-predictive-option[data-value]:not(.cvd-address-option)', chooseLocality);
  $(document.body).on('keydown', '.cvd-predictive-option[data-value]:not(.cvd-address-option)', function (event) {
    if (event.key === 'Enter' || event.key === ' ') chooseLocality.call(this, event);
  });
  $(document.body).on('input', '#billing_address_1', function () {
    clearTimeout(addressTimer); addressTimer = setTimeout(searchAddresses, 700);
  });
  function chooseAddress(event) {
    event.preventDefault();
    event.stopPropagation();
    $('#billing_address_1').val(this.getAttribute('data-value') || '').trigger('change');
    $('#billing_cvd_map_url').val(this.getAttribute('data-map') || '').trigger('change');
    $('#cvd-address-suggestions').hide();
  }
  $(document.body).on('pointerdown', '.cvd-address-option', chooseAddress);
  $(document.body).on('keydown', '.cvd-address-option', function (event) {
    if (event.key === 'Enter' || event.key === ' ') chooseAddress.call(this, event);
  });
  $(document).on('click', function (event) {
    if (!$(event.target).closest('#billing_cvd_locality_field').length) $('#cvd-locality-suggestions').hide();
    if (!$(event.target).closest('#billing_address_1_field').length) $('#cvd-address-suggestions').hide();
  });
  $(document.body).on('change', 'input[name="billing_cvd_fulfillment_type"]', function () {
    toggleDeliveryFields();
    $(document.body).trigger('update_checkout');
  });
  $(document.body).on('updated_checkout', function () {
    toggleDeliveryFields();
    populateLocalities();
	updateOrderButton();
	prepareDayChips();
	prepareLocationButton();
    prepareAddressAutocomplete();
    updateShippingPreview();
  });

	function updateOrderButton() {
	  var method = $('input[name="payment_method"]:checked').val();
	  var text = method === 'bacs' ? 'Crear pedido y ver transferencia' : 'Continuar por WhatsApp';
	  $('#place_order').text(text).attr('value', text);
	}

	$(document.body).on('change', 'input[name="payment_method"]', updateOrderButton);

	// Resumen antes de finalizar (opinión de Lennys, aprobada por Ernesto): el pedido se crea al pulsar
	// "Finalizar", así que primero se muestra todo para revisarlo y corregir sin perder el carrito.
	var reviewed = false;
	// Día de entrega (3.13.51): "hoy", "mañana sáb 12/10", "lun 14/10" o "Lo antes posible"; "hoy" es el día de la web (min del campo).
	var DAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
	function ymdDate(ymd) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || ''); return m ? new Date(+m[1], m[2] - 1, +m[3]) : null; }
	function ymdOf(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function dayText(ymd) {
	  var d = ymdDate(ymd); if (!d) return 'Lo antes posible';
	  var today = ymdDate($('#billing_cvd_delivery_date').attr('min')) || new Date(new Date().setHours(0, 0, 0, 0));
	  var diff = Math.round((d - today) / 864e5), short = DAYS[d.getDay()] + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
	  return diff === 0 ? 'Hoy' : diff === 1 ? 'Mañana ' + short : short;
	}
	function prepareDayChips() {
	  var $input = $('#billing_cvd_delivery_date'); if (!$input.length || $input.siblings('.cvd-day-chips').length) return;
	  var today = ymdDate($input.attr('min')) || new Date(new Date().setHours(0, 0, 0, 0));
	  var tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
	  var chips = $('<span class="cvd-day-chips" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"></span>');
	  [['Hoy', ymdOf(today)], ['Mañana', ymdOf(tomorrow)], ['Lo antes posible', '']].forEach(function (c) {
	    $('<button type="button" class="button" style="padding:4px 10px;font-size:.85em;line-height:1.4"></button>').text(c[0]).attr('data-day', c[1]).appendTo(chips);
	  });
	  $input.after(chips);
	}
	$(document.body).on('click', '.cvd-day-chips [data-day]', function () { $('#billing_cvd_delivery_date').val($(this).attr('data-day')).trigger('change'); });
	$(prepareDayChips);
	function fieldText(selector) { var el = $(selector); if (!el.length) return ''; return el.is('select') ? $.trim(el.find('option:selected').text()) : $.trim(el.val() || ''); }
	function reviewSummary() {
	  var rows = [];
	  $('.woocommerce-checkout-review-order-table .cart_item').each(function () { rows.push($.trim($(this).find('.product-name').text().replace(/\s+/g, ' '))); });
	  var total = $.trim($('.woocommerce-checkout-review-order-table .order-total .amount').first().text());
	  var pickup = ($('select[name="billing_cvd_fulfillment_type"]').val() || $('input[name="billing_cvd_fulfillment_type"]:checked').val() || $('input[name="billing_cvd_fulfillment_type"]').val()) === 'pickup';
	  var where = pickup ? 'Recogida en tienda' : [fieldText('#billing_address_1'), fieldText('#billing_cvd_locality'), fieldText('#billing_city')].filter(Boolean).join(', ');
	  var when = pickup ? '' : [dayText($('#billing_cvd_delivery_date').val()), $('#billing_cvd_delivery_window').val() ? fieldText('#billing_cvd_delivery_window') : ''].filter(Boolean).join(' · ');
	  var payWith = parseFloat($('#billing_cvd_change_amount').val() || '0');
	  var payCurrency = $('#billing_cvd_change_currency').val();
	  var totalNumber = parseFloat((total.match(/[\d.,]+/) || ['0'])[0].replace(/\.(?=\d{3}\b)/g, '').replace(',', '.'));
	  var change = payWith > 0 ? (payCurrency === 'USD' ? (payWith - totalNumber > 0 ? 'Paga con ' + payWith + ' USD → vuelto ' + (payWith - totalNumber).toFixed(2) + ' USD' : 'Paga exacto') : 'Paga con ' + payWith + ' ' + (payCurrency || '¿moneda?')) : 'Paga exacto (sin vuelto)';
	  if (pickup) change = ''; // Recogida: sin fila de vuelto ni pago en efectivo (mejora #23).
	  var line = function (label, value) { return value ? '<p style="margin:.35em 0"><b>' + label + ':</b> ' + $('<span>').text(value).html() + '</p>' : ''; };
	  // Mensajería (3.13.53): el precio de la lista o «por confirmar con la tienda» si el reparto no está en la lista.
	  var fee = $.trim($('.cvd-shipping-cup td strong').first().text());
	  var shipping = pickup ? '' : (/\d/.test(fee) ? fee : 'por confirmar con la tienda (tu reparto no está en la lista)');
	  return line('Productos', rows.join(' · ')) + line('Total productos', total) + line('Mensajería', shipping) + line('Entrega', where) + line('Cuándo', when) + line('Pago', change) + line('Recibe', [fieldText('#billing_first_name'), fieldText('#billing_last_name')].join(' ') + ' · ' + fieldText('#billing_phone'));
	}
	function showReview(onConfirm) {
	  $('#cvd-review').remove();
	  var box = $('<div id="cvd-review" role="dialog" aria-modal="true" style="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px">' +
	    '<div style="background:#fff;color:#222;max-width:440px;width:100%;border-radius:16px;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,.3);max-height:90vh;overflow:auto">' +
	    '<h3 style="margin:0 0 .6em">Revisa tu pedido antes de finalizar</h3>' + reviewSummary() +
	    '<p style="margin:.8em 0 1em;font-size:.9em;opacity:.8">Al pulsar <b>Todo bien, finalizar</b> el pedido queda hecho.</p>' +
	    '<div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="button" data-review="edit" style="flex:1">Corregir algo</button>' +
	    '<button type="button" class="button alt" data-review="ok" style="flex:1">Todo bien, finalizar</button></div></div></div>');
	  $('body').append(box);
	  box.on('click', '[data-review]', function () { var ok = $(this).data('review') === 'ok'; box.remove(); if (ok) onConfirm(); });
	}
	$('form.checkout').on('checkout_place_order', function () {
	  if (reviewed) { reviewed = false; return true; }
	  showReview(function () { reviewed = true; $('#place_order').trigger('click'); });
	  return false;
	});

  $(function () {
    if (!$('#billing_state').val()) $('#billing_state').val(cvdCheckout.defaultState);
    populateMunicipalities(true);
    populateLocalities();
    toggleDeliveryFields();
	updateOrderButton();
	prepareLocationButton();
    prepareAddressAutocomplete();
    updateShippingPreview();
  });
})(jQuery);
