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

  function populateLocalities() {
    var $locality = $('#billing_cvd_locality');
    if (!$locality.length || typeof cvdCheckout === 'undefined') return;

    var city = $('#billing_city').val();
    var values = cvdCheckout.localities[city] || [];
    var query = ($locality.val() || '').toLocaleLowerCase();
    var filtered = values.filter(function (value) { return !query || value.toLocaleLowerCase().indexOf(query) !== -1; });
    var $list = $('#cvd-locality-suggestions');
    if (!$list.length) {
      $list = $('<div>', { id: 'cvd-locality-suggestions', class: 'cvd-predictive-list', role: 'listbox' }).hide();
      $locality.attr({ autocomplete: 'off', role: 'combobox', 'aria-controls': 'cvd-locality-suggestions' }).after($list);
    }
    $list.empty();
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
    $(document.body).trigger('update_checkout');
  });
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
