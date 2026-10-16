/* 3.14.23 · Cobrar mi comisión: cuánto retira (pedidos enteros, del más viejo), cómo cobra y cuánto le pagan.
   Las mismas cuentas que CVD_Payout_Math (el servidor vuelve a calcular todo; esto solo lo enseña antes). */
(function () {
  'use strict';
  var form = document.querySelector('[data-cvp-form]');
  if (!form) return;
  var data; try { data = JSON.parse(form.getAttribute('data-cvp')); } catch (e) { return; }
  var amount = form.querySelector('[data-cvp-amount]');
  var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-cvp-order]'));
  var payEl = form.querySelector('[data-cvp-pay]'), dedEl = form.querySelector('[data-cvp-deduct]'), errEl = form.querySelector('[data-cvp-error]');
  var splitEl = form.querySelector('[data-cvp-split]'), transfer = form.querySelector('[data-cvp-transfer]'), submit = form.querySelector('[data-cvp-submit]');
  var byId = {}; data.lines.forEach(function (l) { byId[l.id] = l; });

  function r2(x) { return Math.round(x * 100) / 100; }
  function num(x) {
    x = r2(x);
    var s = (Math.round(x) === x ? x.toFixed(0) : x.toFixed(2).replace(/0$/, '')).split('.');
    return s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (s[1] ? ',' + s[1] : '');
  }
  function usd(x) { x = r2(x); var p = (Math.round(x) === x ? x.toFixed(0) : x.toFixed(2)).split('.'); return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (p[1] ? ',' + p[1] : '') + ' USD'; }
  function cup(x) { return num(x) + ' CUP'; }
  function method() { var c = form.querySelector('[data-cvp-method]:checked'); return c ? c.value : 'transfer'; }
  function chosen() { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return byId[b.value]; }); }

  function pick(want) {
    var total = data.lines.reduce(function (s, l) { return s + l.usd; }, 0), sum = 0;
    boxes.forEach(function (b) {
      var l = byId[b.value];
      if (isNaN(want) || want >= total - 0.004) { b.checked = true; return; }
      b.checked = sum + l.usd <= want + 0.004; if (b.checked) sum += l.usd;
    });
  }

  function render() {
    var m = method(), lines = chosen(), usdSum = 0, cupSum = 0, noRate = false;
    lines.forEach(function (l) { usdSum += l.usd; cupSum += l.cup; if (!(l.rate > 0)) noRate = true; });
    usdSum = r2(usdSum); cupSum = r2(cupSum);
    var all = r2(data.lines.reduce(function (s, l) { return s + l.usd; }, 0)), left = r2(all - usdSum);
    splitEl.textContent = !lines.length ? 'Marca al menos un pedido.' : (left > 0 ? 'Retiras ' + usd(usdSum) + ' (' + lines.length + (lines.length === 1 ? ' pedido' : ' pedidos') + '). Quedan ' + usd(left) + ' guardados.' : 'Retiras todo. Van pedidos enteros; lo que no retires queda guardado.');
    if (transfer) transfer.hidden = m !== 'transfer';
    var err = '', pay = 0, ded = 0, cur = m === 'cash_usd' ? 'USD' : 'CUP';
    var dc = r2(data.debtCup + data.debtUsd * data.today), du = r2(data.debtUsd + (data.debtCup > 0 && data.today > 0 ? Math.ceil(Math.round(data.debtCup / data.today * 100 * 1e6) / 1e6) / 100 : 0));
    if (!lines.length) err = 'Elige al menos un pedido.';
    else if (m !== 'cash_usd' && noRate) err = 'Falta la tasa de un pedido. Cóbralo en USD o avisa a Lennys.';
    else if (((m === 'cash_usd' && data.debtCup > 0) || (m !== 'cash_usd' && data.debtUsd > 0)) && !(data.today > 0)) err = 'No hay tasa de hoy para convertir la deuda. Avisa a Lennys.';
    else {
      if (m === 'cash_usd') { ded = du; pay = r2(usdSum - du); } else { ded = dc; pay = m === 'cash_cup' ? Math.round(cupSum - dc) : r2(cupSum - dc); }
      if (pay <= 0) err = 'Lo que retiras no alcanza para la deuda con la tienda (' + (cur === 'USD' ? usd(ded) : cup(ded)) + '). Retira más o espera a tener más ventas.';
    }
    payEl.textContent = err ? '—' : (cur === 'USD' ? usd(pay) : cup(pay));
    dedEl.textContent = !err && ded > 0 ? 'Ya descontada la deuda: ' + (cur === 'USD' ? usd(ded) : cup(ded)) : '';
    errEl.textContent = err;
    submit.disabled = !!err;
  }

  if (amount) amount.addEventListener('input', function () { pick(parseFloat(String(amount.value).replace(',', '.'))); render(); });
  form.addEventListener('click', function (e) { if (e.target.closest('[data-cvp-all]')) { amount.value = r2(data.lines.reduce(function (s, l) { return s + l.usd; }, 0)); pick(NaN); render(); } });
  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-cvp-order]')) { amount.value = r2(chosen().reduce(function (s, l) { return s + l.usd; }, 0)); }
    render();
  });
  // Formatea la tarjeta en grupos de 4 mientras escribe.
  var card = form.querySelector('input[name="cvd_card"]');
  if (card) card.addEventListener('input', function () { var d = card.value.replace(/\D/g, '').slice(0, 16); card.value = d.replace(/(\d{4})(?=\d)/g, '$1 '); });
  // Un solo envío (internet lento): el servidor además no crea otra solicitud con el mismo token.
  form.addEventListener('submit', function () { setTimeout(function () { submit.disabled = true; submit.textContent = 'Enviando…'; }, 0); });
  render();
}());
