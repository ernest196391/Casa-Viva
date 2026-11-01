/* «Pedidos abiertos» en la oficina de la gestora (3.14.103, Ernesto 31-oct): pasar a mensajería (con la tarifa oficial del
   cotizador), pasar a recogida o cancelar con motivo. Lo del servidor está en includes/class-cvd-gestora-order-actions.php;
   son los mismos servicios que usa VivaBot por WhatsApp. Nunca se dice «listo» sin la respuesta de la web. */
(function () {
  'use strict';
  var box = document.querySelector('[data-cvga-cambios]');
  if (!box) return;
  var cfg = {};
  try { cfg = JSON.parse(box.querySelector('[data-cambios-config]').textContent) || {}; } catch (ignore) {}
  var zones = cfg.zones || {};

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]; }); }
  function cup(n) { return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' CUP'; } // 1.500 (es-ES no agrupa 4 cifras)
  function call(id, action, body) {
    return fetch(cfg.base + id + '/' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'No se pudo. Prueba otra vez.'); return d; });
    });
  }
  var REASONS = {
    test_order: 'Es un pedido de prueba.', not_open: 'Ese pedido ya no está abierto.', not_found: 'No encuentro ese pedido.',
    too_late: 'Ya salió de la tienda.', incident: 'Tiene una incidencia abierta: habla con la tienda.',
    busy: 'Alguien está cambiando ese pedido ahora mismo. Prueba en un minuto.', already_pickup: 'Ya es recogida en tienda.',
    reason_missing: 'Escribe por qué se cancela (una frase corta).'
  };
  function nothing(r) { return (r.message || REASONS[r.result] || 'No se pudo hacer ahora.') + ' No cambió nada.'; }

  function deliveryForm() {
    var muni = Object.keys(zones).map(function (m) { return '<option>' + esc(m) + '</option>'; }).join('');
    return '<form class="cvga-cambio-form" data-form="delivery" autocomplete="off">' +
      '<label class="cvga-field"><span>Dirección</span><input name="address" type="text" maxlength="200" required placeholder="Calle, número y entre calles"></label>' +
      '<label class="cvga-field"><span>Municipio</span><select name="municipality" required><option value="">Elige…</option>' + muni + '</select></label>' +
      '<label class="cvga-field"><span>Reparto</span><select name="zone" required disabled><option value="">Primero el municipio</option></select></label>' +
      '<p class="cvga-note" data-quote>Te digo el costo de la mensajería antes de cambiar nada.</p>' +
      '<div class="cvga-actions"><button class="cvga-btn cvga-btn-sm" type="submit">Ver costo</button></div></form>';
  }
  function pickupForm() {
    return '<form class="cvga-cambio-form" data-form="pickup"><p class="cvga-note">Se quita la mensajería y tu cliente lo recoge en la tienda (' + esc(cfg.pickup || '') +
      '). Si ya tenía mensajero, queda libre y le avisamos.</p><div class="cvga-actions"><button class="cvga-btn cvga-btn-sm" type="submit">Sí, pasar a recogida</button></div></form>';
  }
  function cancelForm() {
    return '<form class="cvga-cambio-form" data-form="cancel" autocomplete="off">' +
      '<label class="cvga-field"><span>¿Por qué se cancela?</span><textarea name="reason" rows="2" maxlength="200" required placeholder="Ej.: el cliente ya no lo quiere"></textarea></label>' +
      '<p class="cvga-note">Las existencias vuelven a la tienda y esa venta ya no suma comisión.</p>' +
      '<div class="cvga-actions"><button class="cvga-btn cvga-btn-sm" type="submit">Cancelar el pedido</button></div></form>';
  }

  function done(li, text) {
    li.querySelector('[data-cambio-panel]').hidden = true;
    var actions = li.querySelector('.cvga-actions');
    if (actions) actions.remove();
    li.querySelector('[data-cambio-res]').innerHTML = '<span class="cvga-ok">' + esc(text) + '</span>';
  }
  function fail(li, text) { li.querySelector('[data-cambio-res]').innerHTML = '<span class="cvga-warn">' + esc(text) + '</span>'; }

  box.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cambio-open]');
    if (!btn) return;
    var li = btn.closest('[data-order]'), panel = li.querySelector('[data-cambio-panel]');
    var kind = btn.getAttribute('data-cambio-open');
    var open = !panel.hidden && panel.getAttribute('data-kind') === kind;
    li.querySelectorAll('[data-cambio-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    li.querySelector('[data-cambio-res]').textContent = '';
    if (open) { panel.hidden = true; return; }
    panel.innerHTML = kind === 'delivery' ? deliveryForm() : kind === 'pickup' ? pickupForm() : cancelForm();
    panel.setAttribute('data-kind', kind);
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    var first = panel.querySelector('input,select,textarea,button');
    if (first) first.focus();
  });

  box.addEventListener('change', function (e) {
    if (e.target.name !== 'municipality') return;
    var form = e.target.form, sel = form.zone, list = zones[e.target.value] || [];
    sel.innerHTML = '<option value="">Elige…</option>' + list.map(function (z) { return '<option>' + esc(z) + '</option>'; }).join('');
    sel.disabled = !list.length;
    form.removeAttribute('data-fee');
    form.querySelector('[data-quote]').textContent = 'Te digo el costo de la mensajería antes de cambiar nada.';
    form.querySelector('button[type=submit]').textContent = 'Ver costo';
  });
  box.addEventListener('input', function (e) {
    var form = e.target.form;
    if (form && form.getAttribute('data-form') === 'delivery' && form.hasAttribute('data-fee') && e.target.name !== 'municipality') {
      form.removeAttribute('data-fee');
      form.querySelector('button[type=submit]').textContent = 'Ver costo';
    }
  });

  box.addEventListener('submit', function (e) {
    var form = e.target.closest('[data-form]');
    if (!form) return;
    e.preventDefault();
    var li = form.closest('[data-order]'), id = li.getAttribute('data-order'), kind = form.getAttribute('data-form');
    var btn = form.querySelector('button[type=submit]');
    if (btn.disabled) return;
    btn.disabled = true;
    var req;
    if (kind === 'delivery') {
      var body = { address: form.address.value.trim(), municipality: form.municipality.value, zone: form.zone.value };
      var fee = form.getAttribute('data-fee');
      if (fee) { body.fee = Number(fee); } else { body.dry = true; }
      req = call(id, 'to-delivery', body).then(function (r) {
        if (r.result === 'quote') {
          form.setAttribute('data-fee', r.fee);
          form.querySelector('[data-quote]').innerHTML = 'Mensajería a <b>' + esc(r.zone) + '</b>: <b>' + esc(cup(r.fee)) + '</b> (tarifa oficial). ¿Lo paso?';
          btn.textContent = 'Sí, pasar a mensajería';
          return;
        }
        if (r.result === 'changed') {
          var where = { published: ' Ya salió al grupo de mensajeros.', assigned: ' Lo lleva ' + (r.messenger || 'el mensajero elegido') + '.', scheduled: ' Sale al grupo a primera hora.', held: ' Lleva producto de proveedor: la tienda decide quién lo lleva.' }[r.dispatch] || ' La tienda lo saca al grupo.';
          done(li, '✅ Listo: ahora es a domicilio (' + r.zone + ') con ' + cup(r.fee) + ' de mensajería.' + where);
          return;
        }
        if (r.result === 'fee_mismatch') { form.removeAttribute('data-fee'); btn.textContent = 'Ver costo'; fail(li, 'La tarifa cambió: ahora es ' + cup(r.fee) + '. Míralo otra vez. No cambió nada.'); return; }
        if (r.result === 'zone_unknown') { fail(li, 'No encuentro ese reparto en la lista. Elige otro. No cambió nada.'); return; }
        if (r.result === 'missing') { fail(li, 'Falta la dirección o el municipio. No cambió nada.'); return; }
        if (r.result === 'already_delivery') { done(li, 'Ya era a domicilio. No cambió nada.'); return; }
        fail(li, nothing(r));
      });
    } else if (kind === 'pickup') {
      req = call(id, 'to-pickup').then(function (r) {
        if (r.result === 'changed') {
          done(li, '✅ Listo: ahora es recogida en tienda (' + (r.pickupAddress || cfg.pickup || '') + '). Se quitó la mensajería.' +
            (r.messenger ? ' ' + r.messenger + ' queda libre y le avisamos.' : '') + (r.wasOffered ? ' Lo retiramos del grupo de mensajeros.' : ''));
          return;
        }
        if (r.result === 'with_messenger') { fail(li, 'El mensajero ya recogió el paquete' + (r.messenger ? ' (' + r.messenger + ')' : '') + ': ya no se pasa a recogida. Habla con él y con la tienda. No cambió nada.'); return; }
        if (r.result === 'already_pickup') { done(li, 'Ya era recogida en tienda. No cambió nada.'); return; }
        fail(li, nothing(r));
      });
    } else {
      var reason = form.reason.value.trim();
      if (reason.length < 3) { btn.disabled = false; fail(li, REASONS.reason_missing); form.reason.focus(); return; }
      req = call(id, 'cancel', { reason: reason }).then(function (r) {
        if (r.result === 'cancelled') {
          done(li, '✅ Pedido cancelado. Las existencias vuelven a la tienda.' + (r.messenger ? ' Le avisamos a ' + r.messenger + ' que ya no lo lleve.' : '') + ' La tienda también se entera.');
          return;
        }
        fail(li, nothing(r));
      });
    }
    req.catch(function (err) { fail(li, err.message + ' No cambió nada.'); }).then(function () { btn.disabled = false; });
  });
})();
