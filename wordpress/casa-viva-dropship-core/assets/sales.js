(function () {
  "use strict";
  if (!window.cvdSales) return;
  var list = document.getElementById("cvd-sales-list");
  var summary = document.getElementById("cvd-sales-summary");
  var filter = document.getElementById("cvd-sales-filter");
  var search = document.getElementById("cvd-sales-search");
  var message = document.getElementById("cvd-sales-message");
  // Pendientes / Cerrados (Nana, 12-oct): lo ya cerrado por caja va en su propia pestaña.
  // «Cerrado» = estado final de CVD_Sales::operation_status, sin más pasos posibles:
  // delivered (dinero recibido y completado, o recogido y pagado) y cancelled (cancelado, reembolsado o fallido).
  var views = document.getElementById("cvd-sales-views");
  var closedStatuses = ["delivered", "cancelled"];
  var view = "open"; // pestaña elegida; «Actualizar» y el refresco automático la respetan
  var lastData = null;
  var loadSeq = 0;
  function isClosed(status) { return closedStatuses.indexOf(status) !== -1; }
  function setView(next) {
    view = next === "closed" ? "closed" : "open";
    if (views) views.querySelectorAll("[data-sales-view]").forEach(function (tab) { tab.setAttribute("aria-pressed", tab.dataset.salesView === view ? "true" : "false"); });
  }
  var labels ={ new: "Nuevo", confirmed: "Confirmar", preparing: "Preparar", ready: "Listo para salir", picked_up: "Entregado al mensajero", delivered: "Registrar dinero recibido", incident: "Incidencia", cancelled: "Cancelar" };
  var moneyDialog = document.getElementById("cvd-money-dialog");
  // Tarjetas de la tienda (3.13.50): en transferencia o mixto se pregunta a qué tarjeta entró y el número
  // de la transferencia, para cuadrar luego con cada banco. Zelle y USDT ya dicen la cuenta: solo piden el número.
  var moneyMethod = document.getElementById("cvd-money-method");
  var moneyAccount = document.getElementById("cvd-money-account");
  var moneyReference = document.getElementById("cvd-money-reference");
  if (moneyAccount) moneyAccount.innerHTML = '<option value="">Elige la tarjeta</option>' + (cvdSales.accounts || []).map(function (a) { return '<option value="' + escapeText(a.id) + '">' + escapeText(a.name) + "</option>"; }).join("");
  function syncMoneyAccount() {
    if (!moneyAccount) return;
    var m = moneyMethod.value, card = m === "transfer" || m === "mixed", ref = card || m === "zelle" || m === "usdt" || m === "other";
    document.getElementById("cvd-money-account-row").hidden = !card;
    moneyAccount.required = m === "transfer"; // en mixto puede que no hubo transferencia
    document.getElementById("cvd-money-reference-row").hidden = !ref;
    if (!card) moneyAccount.value = "";
    if (!ref) moneyReference.value = "";
    // «Otra tarjeta…»: se escribe ahí mismo (nombre + últimos 4); solo administración puede guardarla en la lista.
    var typed = card && moneyAccount.value === "typed";
    document.getElementById("cvd-money-typed").hidden = !typed;
    document.getElementById("cvd-money-card-label").required = typed;
    document.getElementById("cvd-money-card-last4").required = typed;
    document.getElementById("cvd-money-save-row").hidden = !cvdSales.isAdmin;
    if (!typed) { document.getElementById("cvd-money-card-label").value = ""; document.getElementById("cvd-money-card-last4").value = ""; document.getElementById("cvd-money-save-card").checked = false; }
  }
  if (moneyMethod) moneyMethod.addEventListener("change", syncMoneyAccount);
  if (moneyAccount) moneyAccount.addEventListener("change", syncMoneyAccount);
  var scanner = document.getElementById("cvd-order-scanner");
  var stream = null;
  if(cvdSales.notificationsUrl)fetch(cvdSales.notificationsUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':cvdSales.nonce},body:JSON.stringify({id:0})}).catch(function(){});

  function escapeText(value) { var n = document.createElement("span"); n.textContent = String(value == null ? "" : value); return n.innerHTML; }
  async function api(url, options) {
    var response = await fetch(url, Object.assign({ credentials: "same-origin", headers: { "X-WP-Nonce": cvdSales.nonce, "Content-Type": "application/json" } }, options || {}));
    var data = await response.json();
    if (!response.ok) throw new Error(data.message || "No se pudo completar la operación.");
    return data;
  }
  function productLinks(products) { return products.map(function (p) { var text = escapeText(p.quantity + " × " + p.name); return p.url ? '<a target="_blank" rel="noopener" href="' + escapeText(p.url) + '">' + text + "</a>" : text; }).join(" · "); }
  function actionLabel(order, status) {
    if (status === "ready" && order.fulfillment === "Recogida en tienda") return "Listo para recoger";
    if (status === "delivered" && order.fulfillment === "Recogida en tienda") return "Entregar al cliente";
    return labels[status] || status;
  }
  function actionButtons(order) {
    return order.actions.filter(function (status) { return cvdSales.isAdmin || status !== "cancelled"; }).map(function (status) {
      var danger = status === "cancelled" || status === "incident" ? " is-warning" : status === "delivered" ? " is-success" : "";
      return '<button class="cvd-sale-action' + danger + '" data-order="' + order.id + '" data-status="' + status + '" data-pickup="' + (order.fulfillment === "Recogida en tienda" ? "1" : "0") + '" data-declared-usd="' + escapeText(order.declaredUsd || "") + '" data-declared-cup="' + escapeText(order.declaredCup || "") + '">' + escapeText(actionLabel(order, status)) + "</button>";
    }).join("");
  }
  // Mensajería con el nombre de quien lo lleva (Nana, 12-oct: en tienda se anota el mensajero y la gestora).
  function messengerRow(order) {
    var text = order.deliveryStatus || "No aplica";
    if (order.messengerName) text += " · " + order.messengerName + (order.directAssignment ? " (lo eligió la gestora)" : "");
    else if (order.preferredMessenger) text += " · preferido: " + order.preferredMessenger;
    return '<p><b>Mensajería</b><span>' + escapeText(text) + '</span></p>';
  }
  function commercialData(order) {
    var rows = [];
    if (Object.prototype.hasOwnProperty.call(order, "gestora")) {
      rows.push('<p><b>Gestora</b><span>' + escapeText(order.gestora || "Casa Viva · Venta directa") + '</span></p>');
    }
    if (Object.prototype.hasOwnProperty.call(order, "commission") && Object.prototype.hasOwnProperty.call(order, "commissionStatus")) {
      rows.push('<p><b>Comisión</b><span>' + escapeText(Number(order.commission || 0).toFixed(2) + " · " + order.commissionStatus) + '</span></p>');
    }
    return rows.join("");
  }
  function paint(data) {
    var cards = [["new", "Nuevos"], ["preparing", "Preparando"], ["ready", "Listos"], ["with_courier", "En camino"]];
    summary.innerHTML = cards.map(function (x) { return '<button type="button" data-summary-status="' + x[0] + '"><span>' + x[1] + '</span><strong>' + (data.summary[x[0]] || 0) + "</strong></button>"; }).join("");
    // Cuántos hay en cada pestaña (con la búsqueda aplicada) y solo las tarjetas de la pestaña elegida.
    var counts = { open: 0, closed: 0 };
    Object.keys(data.summary || {}).forEach(function (status) { counts[isClosed(status) ? "closed" : "open"] += Number(data.summary[status]) || 0; });
    if (views) views.querySelectorAll("[data-sales-view]").forEach(function (tab) { var n = tab.querySelector("b"); if (n) n.textContent = counts[tab.dataset.salesView] || 0; });
    var orders = data.orders.filter(function (order) { return isClosed(order.status) === (view === "closed"); });
    if (!orders.length) {
      var empty = filter.value || search.value.trim() ? "No hay pedidos con este filtro." : (view === "closed" ? "Todavía no hay pedidos cerrados." : "No hay pedidos pendientes.");
      var other = view === "closed" ? counts.open : counts.closed;
      if (search.value.trim() && other) empty += " Hay " + other + " en «" + (view === "closed" ? "Pendientes" : "Cerrados") + "».";
      list.innerHTML = "<p>" + escapeText(empty) + "</p>"; return;
    }
    list.innerHTML = orders.map(function (order) {
      var phone = order.phone ? '<a href="tel:' + escapeText(order.phone) + '">' + escapeText(order.phone) + "</a>" : "";
      var wa = order.phone ? '<a class="cvd-sale-wa" target="_blank" rel="noopener" href="https://wa.me/' + escapeText(order.phone.replace(/\D/g, "")) + '">WhatsApp</a>' : "";
      // Pedido de prueba (3.13.54): cartel grande arriba; el servidor no lo deja salir al reparto.
      var test = order.testOrder ? '<p class="cvd-sale-test" role="note" style="margin:0 0 10px;padding:10px 12px;border-radius:12px;background:#b3261e;color:#fff;font-weight:800;font-size:1.15em;letter-spacing:.02em;text-align:center">PRUEBA — no preparar<br><small style="font-weight:600;font-size:.75em">No sale al reparto ni a ningún mensajero</small></p>' : '';
      return '<article class="cvd-sale-card' + (order.testOrder ? ' is-test' : '') + '">' + test + '<div class="cvd-sale-top"><div><span class="cvd-sale-status is-' + order.status + '">' + escapeText(order.statusLabel) + '</span><h2>Pedido #' + escapeText(order.number) + '</h2><small>' + escapeText(order.date) + '</small></div><div class="cvd-sale-code"><canvas data-order-code="' + escapeText(order.orderCode) + '"></canvas><small>' + escapeText(order.orderCode) + '</small></div><strong>' + escapeText(order.total) + (order.shippingCup && !/CUP/i.test(order.total) ? ' + ' + escapeText(order.shippingCup) + ' CUP' : '') + '</strong></div><div class="cvd-sale-data"><p><b>Cliente</b><span>' + escapeText(order.customer) + " · " + phone + '</span></p><p><b>Entrega</b><span>' + escapeText(order.fulfillment + " · " + order.address) + '</span></p>' + (order.deliveryWhen ? '<p><b>Día</b><span' + (order.deliveryFuture ? ' style="color:#a8641c;font-weight:700"' : '') + '>📅 ' + escapeText(order.deliveryWhen) + '</span></p>' : '') + '<p><b>Productos</b><span>' + productLinks(order.products) + '</span></p>' + messengerRow(order) + commercialData(order) + '</div><div class="cvd-sale-actions">' + actionButtons(order) + (order.canReturn ? '<button type="button" class="cvd-sale-return is-warning" data-return-order="' + order.id + '">Devolución</button>' : '') + (order.trackingUrl ? '<button type="button" data-copy-tracking="' + escapeText(order.trackingUrl) + '">Copiar seguimiento</button>' : '') + wa + (cvdSales.isAdmin && order.adminUrl ? '<a href="' + escapeText(order.adminUrl) + '" target="_blank" rel="noopener">Ver pedido</a>' : '') + '</div></article>';
    }).join("");
    if (window.CVQRCode) document.querySelectorAll("[data-order-code]").forEach(function (canvas) { window.CVQRCode.toCanvas(canvas, canvas.dataset.orderCode, { width: 84, margin: 1 }); });
  }
  async function load() {
    var mine = ++loadSeq; // si llegan dos respuestas, solo pinta la última pedida (no vuelve un filtro viejo)
    message.textContent = "Actualizando…";
    var params = new URLSearchParams();
    if (filter.value) params.set("status", filter.value);
    if (search.value.trim()) params.set("search", search.value.trim());
    try { var data = await api(cvdSales.url + (params.toString() ? "?" + params : ""), { method: "GET" }); if (mine !== loadSeq) return; lastData = data; paint(data); message.textContent = ""; }
    catch (error) { if (mine !== loadSeq) return; message.textContent = error.message; message.className = "is-error"; }
  }
  list.addEventListener("click", async function (event) {
    var copy = event.target.closest('[data-copy-tracking]');
    if (copy) { try { await navigator.clipboard.writeText(copy.dataset.copyTracking); copy.textContent = 'Copiado'; } catch (error) { message.textContent = 'No se pudo copiar el enlace.'; } return; }
    // Devolución de un pedido cerrado (D30): vuelve el stock, se anula la comisión de la gestora y,
    // si se indica, la ganancia del mensajero. Queda escrito el dinero devuelto al cliente.
    var ret = event.target.closest("[data-return-order]");
    if (ret) {
      var reason = window.prompt("Devolución del pedido #" + ret.dataset.returnOrder + "\n\n¿Por qué se devuelve? (obligatorio)");
      if (!reason || !reason.trim()) return;
      var refundUsd = window.prompt("¿Cuántos USD se le devuelven al cliente? (0 si ninguno)", "0"); if (refundUsd === null) return;
      var refundCup = window.prompt("¿Cuántos CUP se le devuelven al cliente? (0 si ninguno)", "0"); if (refundCup === null) return;
      var voidMessenger = window.confirm("¿Anular también la ganancia del mensajero?\n\nAceptar = sí, se anula (por ejemplo, entrega mal hecha)\nCancelar = no, se le paga (hizo su trabajo)");
      if (!window.confirm("Confirmar devolución del #" + ret.dataset.returnOrder + ":\n• Se devuelve " + refundUsd + " USD + " + refundCup + " CUP\n• El producto vuelve al stock\n• Se anula la comisión de la gestora\n• Ganancia del mensajero: " + (voidMessenger ? "ANULADA" : "se mantiene"))) return;
      ret.disabled = true; message.textContent = "Registrando devolución…";
      try { await api(cvdSales.url + "/" + ret.dataset.returnOrder + "/return", { method: "POST", body: JSON.stringify({ reason: reason, refundUsd: refundUsd, refundCup: refundCup, voidMessenger: voidMessenger }) }); message.textContent = "Devolución registrada."; await load(); }
      catch (error) { message.textContent = error.message; message.className = "is-error"; ret.disabled = false; }
      return;
    }
    var button = event.target.closest(".cvd-sale-action"); if (!button) return;
    var status = button.dataset.status;
    if (status === "delivered") {
      var pickup = button.dataset.pickup === "1";
      document.getElementById("cvd-money-order").value = button.dataset.order;
      document.getElementById("cvd-money-pickup").value = pickup ? "1" : "0";
      document.getElementById("cvd-money-title").textContent = pickup ? "Confirmar recogida y cobro" : "Dinero recibido";
      var handover = document.getElementById("cvd-handover-confirmed");
      handover.closest("label").hidden = !pickup;
      handover.required = pickup;
      handover.checked = false;
      var card = button.closest(".cvd-sale-card");
      document.getElementById("cvd-money-usd").value = ((card && card.querySelector(".cvd-sale-top>strong").textContent.match(/[\d.,]+/)) || [""])[0].replace(",", ".");
      // Lo que el mensajero declaró por WhatsApp: se propone tal cual; si la tienda cuenta otra cosa, Core lo anota.
      var declaredUsd = button.dataset.declaredUsd, declaredCup = button.dataset.declaredCup;
      if (declaredUsd || declaredCup) {
        document.getElementById("cvd-money-usd").value = declaredUsd || "0";
        document.getElementById("cvd-money-cup").value = declaredCup || "0";
        document.getElementById("cvd-money-method").value = Number(declaredUsd) > 0 && Number(declaredCup) > 0 ? "mixed" : (Number(declaredUsd) > 0 ? "cash_usd" : "cash_cup");
        document.getElementById("cvd-money-title").textContent = "Dinero recibido · el mensajero declaró " + (declaredUsd || 0) + " USD + " + (declaredCup || 0) + " CUP";
      }
      syncMoneyAccount(); moneyDialog.showModal(); return;
    }
    if (status === "cancelled" && !window.confirm("¿Cancelar el pedido? Se repondrá el stock y se cancelará la comisión.")) return;
    button.disabled = true; message.textContent = "Guardando…";
    try { var saved = await api(cvdSales.url + "/" + button.dataset.order + "/status", { method: "POST", body: JSON.stringify({ status: status }) }); await load(); if (saved && saved.notice) { message.textContent = saved.notice; message.className = "is-error"; } }
    catch (error) { message.textContent = error.message; message.className = "is-error"; button.disabled = false; }
  });
  document.getElementById("cvd-money-form").addEventListener("submit", async function (event) {
    if (event.submitter && event.submitter.value === "cancel") return;
    event.preventDefault(); var submit = document.getElementById("cvd-money-submit"); submit.disabled = true;
    var pickup = document.getElementById("cvd-money-pickup").value === "1";
    try { await api(cvdSales.url + "/" + document.getElementById("cvd-money-order").value + "/status", { method: "POST", body: JSON.stringify({ status: "delivered", collectionMethod: document.getElementById("cvd-money-method").value, collectedUsd: document.getElementById("cvd-money-usd").value, collectedCup: document.getElementById("cvd-money-cup").value, collectionNote: document.getElementById("cvd-money-note").value, collectionAccount: moneyAccount ? moneyAccount.value : "", collectionReference: moneyReference ? moneyReference.value : "", collectionCardLabel: document.getElementById("cvd-money-card-label").value, collectionCardLast4: document.getElementById("cvd-money-card-last4").value, collectionSaveCard: document.getElementById("cvd-money-save-card").checked, moneyConfirmed: document.getElementById("cvd-money-confirmed").checked, handoverConfirmed: pickup ? document.getElementById("cvd-handover-confirmed").checked : false }) }); moneyDialog.close(); event.target.reset(); syncMoneyAccount(); document.getElementById("cvd-money-pickup").value = "0"; await load(); message.className = ""; message.textContent = "Pedido cerrado: ahora está en «Cerrados»."; }
    catch (error) { message.textContent = error.message; message.className = "is-error"; }
    finally { submit.disabled = false; }
  });

  function stopScanner() { if (stream) stream.getTracks().forEach(function (track) { track.stop(); }); stream = null; scanner.hidden = true; }
  document.getElementById("cvd-scan-order").addEventListener("click", async function () {
    if (stream) return stopScanner();
    if (!("BarcodeDetector" in window) || !navigator.mediaDevices) { message.textContent = "Este navegador no admite escaneo. Escribe el número del pedido."; return; }
    try { var detector = new BarcodeDetector({ formats: ["qr_code", "code_128"] }); stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false }); scanner.srcObject = stream; scanner.hidden = false; await scanner.play(); var detect = async function () { if (!stream) return; var codes = await detector.detect(scanner); if (codes.length) { var match = codes[0].rawValue.match(/(?:CV-PEDIDO-)?(\d+)/i); if (match) { search.value = match[1]; stopScanner(); load(); return; } } setTimeout(detect, 220); }; detect(); }
    catch (error) { stopScanner(); message.textContent = "No se pudo abrir la cámara."; }
  });
  // Un estado del filtro elige su pestaña: «Completados»/«Cancelados» → Cerrados; los demás → Pendientes.
  function syncViewToFilter() { if (filter.value) setView(isClosed(filter.value) ? "closed" : "open"); }
  filter.addEventListener("change", function () { syncViewToFilter(); load(); });
  summary.addEventListener("click", function (event) { var card=event.target.closest('[data-summary-status]'); if(!card)return; filter.value=card.dataset.summaryStatus; syncViewToFilter(); load(); document.getElementById('cvd-sales-list').scrollIntoView({behavior:'smooth',block:'start'}); });
  if (views) views.addEventListener("click", function (event) {
    var tab = event.target.closest("[data-sales-view]"); if (!tab || tab.dataset.salesView === view) return;
    setView(tab.dataset.salesView);
    // Un filtro de la otra pestaña (p. ej. «Completados» al volver a Pendientes) se quita.
    if (filter.value && isClosed(filter.value) !== (view === "closed")) { filter.value = ""; load(); return; }
    if (lastData) paint(lastData); else load(); // cambia al instante; el refresco automático trae lo nuevo
  });
  document.getElementById("cvd-sales-refresh").addEventListener("click", load);
  search.addEventListener("keydown", function (event) { if (event.key === "Enter") load(); });
  var query = new URLSearchParams(location.search);
  var requestedOrder = query.get("cvd_order") || query.get("order");
  if (requestedOrder) search.value = requestedOrder;
  load();
  window.setInterval(function(){ if(!document.hidden)load(); },8000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) load(); });
  window.addEventListener("pagehide", stopScanner);
})();