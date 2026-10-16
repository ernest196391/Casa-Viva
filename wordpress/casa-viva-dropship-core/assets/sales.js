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
  // 3.14.7 (Nana): cada producto con su precio, uno por renglón («2× Toalla — 15 USD c/u = 30 USD»).
  function productLinks(products) { return products.map(function (p) { var text = escapeText(p.text || (p.quantity + " × " + p.name)); return p.url ? '<a target="_blank" rel="noopener" href="' + escapeText(p.url) + '">' + text + "</a>" : text; }).join("<br>"); }
  function actionLabel(order, status) {
    if (status === "ready" && order.fulfillment === "Recogida en tienda") return "Listo para recoger";
    if (status === "delivered" && order.fulfillment === "Recogida en tienda") return "Entregar al cliente";
    return labels[status] || status;
  }
  function actionButtons(order) {
    // 3.13.97: «Cancelar» va aparte (con motivo), ver extraButtons.
    return order.actions.filter(function (status) { return status !== "cancelled"; }).map(function (status) {
      var danger = status === "cancelled" || status === "incident" ? " is-warning" : status === "delivered" ? " is-success" : "";
      return '<button class="cvd-sale-action' + danger + '" data-order="' + order.id + '" data-status="' + status + '" data-pickup="' + (order.fulfillment === "Recogida en tienda" ? "1" : "0") + '" data-declared-usd="' + escapeText(order.declaredUsd || "") + '" data-declared-cup="' + escapeText(order.declaredCup || "") + '">' + escapeText(actionLabel(order, status)) + "</button>";
    }).join("");
  }
  // 3.13.97 (Ernesto, 14-oct): «Cambiar pedido» (antes de que el mensajero lo recoja) y «Cancelar pedido» (con motivo).
  // Core decide quién y cuándo (canEdit / canCancel); aquí solo se pintan.
  function extraButtons(order) {
    return (order.canEdit ? '<button type="button" class="cvd-sale-extra" data-edit-order="' + order.id + '">Cambiar pedido</button>' : "")
      + (order.canCancel ? '<button type="button" class="cvd-sale-extra is-warning" data-cancel-order="' + order.id + '" data-number="' + escapeText(order.number) + '">Cancelar pedido</button>' : "");
  }
  // Mensajería: en qué punto va la entrega. Quién lo lleva sale arriba, en «Mensajero» (peopleRows).
  function messengerRow(order) {
    if (order.isPickup) return "";
    return '<p><b>Mensajería</b><span>' + escapeText(order.deliveryStatus || "No aplica") + '</span></p>';
  }
  // Gestora y mensajero de cada pedido, con su WhatsApp (3.13.78, Nana 12–13 oct: «en tienda debemos hacer esas anotaciones»).
  function waLink(phone) {
    var d = String(phone || "").replace(/\D/g, "");
    if (!d) return "";
    if (d.length === 8) d = "53" + d;
    var label = d.length === 10 && d.indexOf("53") === 0 ? "+53 " + d.slice(2, 6) + " " + d.slice(6) : "+" + d;
    return ' · <a target="_blank" rel="noopener" href="https://wa.me/' + d + '">' + escapeText(label) + "</a>";
  }
  function peopleRows(order) {
    var people = order.people || {}, g = people.gestora, m = people.messenger, messenger;
    var gestora = g ? escapeText(g.name) + waLink(g.phone) : "Venta directa (sin gestor/a)";
    if (people.pickup) messenger = "Recogida en tienda";
    else if (m && m.state === "assigned") messenger = escapeText(m.name) + waLink(m.phone) + (m.byGestora ? " (lo eligió quien vendió)" : "");
    else if (m) messenger = "Sin mensajero todavía · quien vendió pidió a " + escapeText(m.name) + waLink(m.phone);
    else messenger = "Sin mensajero todavía";
    return '<p class="cvd-sale-people"><b>Gestor/a</b><span><strong>' + gestora + '</strong></span></p><p class="cvd-sale-people"><b>Mensajero</b><span><strong>' + messenger + "</strong></span></p>";
  }
  // Cómo paga el cliente (3.13.67, Lennys): la misma cifra que lee el mensajero en su vale.
  // Zelle/PayPal: el cliente paga todo antes; la tienda verifica la captura antes de coordinar la entrega.
  function paymentRow(order) {
    var p = order.payment;
    if (!p || !p.text) return "";
    // 3.13.74: en la recogida en tienda, lo que se cobra al entregarlo en la tienda.
    var extra = order.isPickup
      ? (p.prepaid ? " · verifica la captura antes de entregarlo (sin devolución)" : " · se cobra en la tienda al recogerlo")
      : (p.prepaid ? " · verifica la captura antes de coordinar la entrega (sin devolución); el mensajero solo cobra su mensajería" : (p.kind === "transfer_cup" ? " · a la tarjeta del mensajero: él trae el dinero" : ""));
    return '<p><b>Pago</b><span' + (p.prepaid ? ' style="color:#a8641c;font-weight:700"' : "") + ">💳 " + escapeText(p.text + extra) + "</span></p>";
  }
  // Vuelto (3.14.18, Zaymi): con cuánto paga el cliente y cuánto hay que devolverle; en la recogida y en el domicilio.
  function changeRow(order) {
    if (!order.change) return "";
    return '<p><b>Vuelto</b><span style="color:#a8641c;font-weight:700">💵 ' + escapeText(order.change) + "</span></p>";
  }
  // Armado por el mensajero (3.13.70, Ernesto): se cobra aparte, como la mensajería; no está en el total.
  function assemblyRow(order) {
    var a = order.assembly;
    if (!a || !a.text) return "";
    return '<p><b>Armado</b><span style="color:#a35a1f;font-weight:700">🔧 ' + escapeText(a.text) + "</span></p>";
  }
  function commercialData(order) {
    var rows = []; // la gestora ya sale arriba (peopleRows); aquí solo la comisión, que solo ve administración
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
      return '<article class="cvd-sale-card' + (order.testOrder ? ' is-test' : '') + '">' + test + '<div class="cvd-sale-top"><div><span class="cvd-sale-status is-' + order.status + '">' + escapeText(order.statusLabel) + '</span><h2>Pedido #' + escapeText(order.number) + '</h2><small>' + escapeText(order.date) + '</small></div><div class="cvd-sale-code"><canvas data-order-code="' + escapeText(order.orderCode) + '"></canvas><small>' + escapeText(order.orderCode) + '</small></div><strong>' + escapeText(order.total) + (order.shippingCup && !/CUP/i.test(order.total) ? ' + ' + escapeText(order.shippingCup) + ' CUP' : '') + '</strong></div><div class="cvd-sale-data"><p><b>Cliente</b><span>' + escapeText(order.customer) + " · " + phone + '</span></p>' + peopleRows(order) + '<p><b>Entrega</b><span>' + escapeText(order.fulfillment + " · " + order.address) + '</span></p>' + paymentRow(order) + changeRow(order) + assemblyRow(order) + (order.deliveryWhen ? '<p><b>Día</b><span' + (order.deliveryFuture ? ' style="color:#a8641c;font-weight:700"' : '') + '>📅 ' + escapeText(order.deliveryWhen) + '</span></p>' : '') + '<p><b>Productos</b><span>' + productLinks(order.products) + '</span></p>' + messengerRow(order) + commercialData(order) + (order.cancelReason && order.status === "cancelled" ? '<p><b>Motivo</b><span>' + escapeText(order.cancelReason) + '</span></p>' : '') + '</div><div class="cvd-sale-actions">' + actionButtons(order) + extraButtons(order) + (order.canReturn ? '<button type="button" class="cvd-sale-return is-warning" data-return-order="' + order.id + '">Devolución</button>' : '') + (order.trackingUrl ? '<button type="button" data-copy-tracking="' + escapeText(order.trackingUrl) + '">Copiar seguimiento</button>' : '') + wa + (cvdSales.isAdmin && order.adminUrl ? '<a href="' + escapeText(order.adminUrl) + '" target="_blank" rel="noopener">Ver pedido</a>' : '') + '</div></article>';
    }).join("");
    if (window.CVQRCode) document.querySelectorAll("[data-order-code]").forEach(function (canvas) { window.CVQRCode.toCanvas(canvas, canvas.dataset.orderCode, { width: 84, margin: 1 }); });
  }
  // 3.14.18 (33/33/33): lo que lleva cada dependienta por extras en tienda (últimos 35 días). Administración ve a todas.
  async function loadExtras() {
    var box = document.getElementById("cvd-sales-extras");
    if (!box) { box = document.createElement("div"); box.id = "cvd-sales-extras"; box.style.cssText = "margin:.5em 0 1em;padding:.75em 1em;border-radius:12px;background:#fbf3e6;border:1px solid #ecd9b8;font-size:.95em"; if (list && list.parentNode) list.parentNode.insertBefore(box, list); }
    var r;
    try { r = await api(cvdSales.url + "/extras", { method: "GET" }); } catch (e) { box.hidden = true; return; }
    var rows = r.rows || [];
    if (!rows.length) { box.hidden = true; return; }
    var usd = function (v) { return v === null || v === undefined ? "—" : Number(v).toFixed(2).replace(".", ","); };
    var head = r.admin ? "<b>Extras en tienda (33/33/33)</b> · últimos 35 días" : "<b>Tus extras en tienda</b> · llevas <b>" + usd(r.staffTotal) + " USD</b> (33 % de la ganancia) · se pagan un día fijo a la semana";
    box.hidden = false;
    box.innerHTML = '<details><summary>' + head + "</summary>" + rows.map(function (x) { return '<p style="margin:.4em 0">#' + escapeText(x.number) + " · " + escapeText(x.date) + " · " + escapeText(x.qty + "× " + x.name) + " · gestora " + escapeText(x.gestoraName || "—") + (x.profit === null ? ' · <b style="color:#a35a1f">sin costo apuntado: avisa a Lennys</b>' : (r.admin ? " · ganancia " + usd(x.profit) + " → gestora " + usd(x.gestora) + " · " + escapeText(x.staffName || "sin dependienta") + " " + usd(x.staff) + " · tienda " + usd(x.store) : " · tu parte <b>" + usd(x.staff) + " USD</b>")) + (x.closed ? "" : " · <i>pedido aún abierto</i>") + "</p>"; }).join("") + "</details>";
  }
  async function load() {
    loadExtras();
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
      if (!window.confirm("Confirmar devolución del #" + ret.dataset.returnOrder + ":\n• Se devuelve " + refundUsd + " USD + " + refundCup + " CUP\n• El producto vuelve al stock\n• Se anula la comisión de quien vendió\n• Ganancia del mensajero: " + (voidMessenger ? "ANULADA" : "se mantiene"))) return;
      ret.disabled = true; message.textContent = "Registrando devolución…";
      try { await api(cvdSales.url + "/" + ret.dataset.returnOrder + "/return", { method: "POST", body: JSON.stringify({ reason: reason, refundUsd: refundUsd, refundCup: refundCup, voidMessenger: voidMessenger }) }); message.textContent = "Devolución registrada."; await load(); }
      catch (error) { message.textContent = error.message; message.className = "is-error"; ret.disabled = false; }
      return;
    }
    var cancelBtn = event.target.closest("[data-cancel-order]");
    if (cancelBtn) {
      var why = window.prompt("Cancelar el pedido #" + cancelBtn.dataset.number + "\n\n¿Por qué se cancela? (obligatorio, una frase corta)");
      if (why === null) return;
      if (why.trim().length < 3) { message.className = "is-error"; message.textContent = "Para cancelar hay que escribir el motivo."; return; }
      if (!window.confirm("¿Seguro que cancelas el pedido #" + cancelBtn.dataset.number + "?\n\n• Las existencias vuelven a la tienda\n• La comisión de quien vendió queda anulada\n• No se puede deshacer\n\nMotivo: " + why.trim())) return;
      cancelBtn.disabled = true; message.className = ""; message.textContent = "Cancelando…";
      try { var done = await api(cvdSales.url + "/" + cancelBtn.dataset.cancelOrder + "/cancel", { method: "POST", body: JSON.stringify({ reason: why.trim() }) }); await load(); message.className = done.messenger ? "is-error" : ""; message.textContent = done.message || "Pedido cancelado."; }
      catch (error) { message.textContent = error.message; message.className = "is-error"; cancelBtn.disabled = false; }
      return;
    }
    var editBtn = event.target.closest("[data-edit-order]");
    if (editBtn) { openEdit(editBtn.dataset.editOrder); return; }
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
      // 3.13.67: la forma de pago del pedido (Efectivo CUP, Zelle…) y su cifra; los euros van a la observación.
      var sale = lastData && (lastData.orders || []).filter(function (o) { return String(o.id) === String(button.dataset.order); })[0];
      var pay = sale && sale.payment, declaredEur = sale ? Number(sale.declaredEur || 0) : 0, moneyNote = document.getElementById("cvd-money-note");
      if (/^Cobrado en euros:/.test(moneyNote.value)) moneyNote.value = ""; // lo puso otro pedido
      if (pay && pay.amount && !(Number(declaredUsd) > 0 || Number(declaredCup) > 0)) {
        document.getElementById("cvd-money-usd").value = pay.currency === "USD" ? pay.amount : "0";
        document.getElementById("cvd-money-cup").value = pay.currency === "CUP" ? pay.amount : "0";
        document.getElementById("cvd-money-method").value = { cash_usd: "cash_usd", cash_cup: "cash_cup", transfer_cup: "cash_cup", clasica: "transfer", zelle: "zelle" }[pay.kind] || "other";
        if (pay.currency === "EUR") moneyNote.value = "Cobrado en euros: " + (declaredEur || pay.amount) + " EUR";
        declaredUsd = ""; declaredCup = "";
      }
      if (declaredUsd || declaredCup) {
        document.getElementById("cvd-money-usd").value = declaredUsd || "0";
        document.getElementById("cvd-money-cup").value = declaredCup || "0";
        document.getElementById("cvd-money-method").value = Number(declaredUsd) > 0 && Number(declaredCup) > 0 ? "mixed" : (Number(declaredUsd) > 0 ? "cash_usd" : "cash_cup");
        document.getElementById("cvd-money-title").textContent = "Dinero recibido · el mensajero declaró " + (declaredUsd || 0) + " USD + " + (declaredCup || 0) + " CUP";
        if (pay) document.getElementById("cvd-money-method").value = { cash_usd: "cash_usd", cash_cup: "cash_cup", transfer_cup: "cash_cup", clasica: "transfer", zelle: "zelle" }[pay.kind] || "other";
      }
      if (pay && pay.text) document.getElementById("cvd-money-title").textContent += " · Pago: " + pay.text;
      syncMoneyAccount(); moneyDialog.showModal(); return;
    }
    if (status === "cancelled" && !window.confirm("¿Cancelar el pedido? Se repondrá el stock y se cancelará la comisión.")) return;
    button.disabled = true; message.textContent = "Guardando…";
    try { var saved = await api(cvdSales.url + "/" + button.dataset.order + "/status", { method: "POST", body: JSON.stringify({ status: status }) }); await load(); if (saved && saved.notice) { message.textContent = saved.notice; message.className = "is-error"; } }
    catch (error) { message.textContent = error.message; message.className = "is-error"; button.disabled = false; }
  });
  // ---------- Cambiar pedido (3.13.97) ----------
  // Nombre, teléfono, dirección, día y franja, nota y productos (cantidad, quitar, añadir). Core valida y deja nota.
  var editDialog = document.createElement("dialog");
  editDialog.id = "cvd-edit-dialog";
  list.parentNode.appendChild(editDialog);
  var editState = null;
  function editField(label, name, value, extra) { return '<label>' + escapeText(label) + '<input name="' + name + '" value="' + escapeText(value || "") + '"' + (extra || "") + "></label>"; }
  function paintAdded() {
    var box = editDialog.querySelector("[data-added]");
    box.innerHTML = editState.add.map(function (a, i) { return '<p class="cvd-edit-line"><span>+ ' + escapeText(a.name) + '</span><input type="number" min="1" max="999" inputmode="numeric" data-add-qty="' + i + '" value="' + a.qty + '" aria-label="Cantidad"><button type="button" data-add-rm="' + i + '" aria-label="Quitar">✕</button></p>'
      // 3.14.18 (33/33/33): en pedidos de gestora, marcar si la clienta lo eligió aquí en la tienda.
      + (editState.gestora ? '<label class="cvd-edit-extra" style="display:flex;gap:.5em;align-items:flex-start;margin:-.25em 0 .75em;font-size:.92em"><input type="checkbox" data-add-extra="' + i + '"' + (a.extra ? " checked" : "") + '><span><b>Extra en tienda</b> — la clienta lo eligió aquí. La ganancia se reparte: 33 % ' + escapeText(editState.gestoraName || "la gestora") + ', 33 % tú y 33 % la tienda. Va al precio de la tienda.</span></label>' : ""); }).join("");
  }
  async function openEdit(id) {
    message.className = ""; message.textContent = "Abriendo el pedido…";
    var d;
    try { d = await api(cvdSales.url + "/" + id + "/edit", { method: "GET" }); }
    catch (error) { message.className = "is-error"; message.textContent = error.message; return; }
    message.textContent = "";
    if (!d.editable) { message.className = "is-error"; message.textContent = d.why || "Este pedido ya no se puede cambiar."; return; }
    editState = { id: d.id, add: [], gestora: !!d.gestoraOrder, gestoraName: d.gestoraName || "" };
    var windows = [["", "A cualquier hora"], ["morning", "Por la mañana"], ["afternoon", "Por la tarde"]];
    editDialog.innerHTML = '<form method="dialog" id="cvd-edit-form"><h2>Cambiar pedido #' + escapeText(d.number) + '</h2>'
      + (d.messenger ? '<p class="cvd-edit-warn">Ya lo tiene asignado <b>' + escapeText(d.messenger.name) + '</b>. Si cambias algo, avísale.</p>' : '')
      + '<div class="cvd-edit-two">' + editField("Nombre", "firstName", d.firstName, ' required maxlength="60"') + editField("Apellidos", "lastName", d.lastName, ' maxlength="60"') + '</div>'
      + editField("Teléfono", "phone", d.phone, ' inputmode="tel" required maxlength="30"')
      + (d.pickup ? '<p class="cvd-edit-hint">Recogida en tienda.</p>'
        : editField("Dirección", "address", d.address, ' maxlength="200"') + (d.locality ? '<p class="cvd-edit-hint">Reparto: ' + escapeText(d.locality) + '. Si cambia de municipio o de reparto, cancélalo y que se haga de nuevo (cambia la mensajería).</p>' : '')
          + '<div class="cvd-edit-two"><label>Día de entrega<input type="date" name="deliveryDate" value="' + escapeText(d.date) + '" min="' + escapeText(d.minDate) + '" max="' + escapeText(d.maxDate) + '"></label><label>Franja<select name="deliveryWindow">' + windows.map(function (w) { return '<option value="' + w[0] + '"' + (w[0] === (d.window || "") ? " selected" : "") + ">" + w[1] + "</option>"; }).join("") + '</select></label></div><p class="cvd-edit-hint">Día vacío = lo antes posible. Ahora: ' + escapeText(d.whenLabel) + '</p>')
      + '<label>Nota del pedido<textarea name="note" rows="2" maxlength="500">' + escapeText(d.note) + '</textarea></label>'
      + '<h3>Productos</h3>' + d.lines.map(function (l) { return '<p class="cvd-edit-line"><span>' + escapeText(l.name) + (l.extra ? " · <small>extra en tienda</small>" : "") + '</span><input type="number" min="0" max="999" inputmode="numeric" data-line="' + l.itemId + '" value="' + l.qty + '" aria-label="Cantidad"></p>'; }).join("")
      + '<p class="cvd-edit-hint">Pon 0 para quitar un producto. Las existencias se ajustan solas.</p>'
      + '<label>Añadir otro producto<input type="search" data-product-q placeholder="Nombre o código" autocomplete="off"></label><div data-found></div><div data-added></div>'
      + '<p data-edit-msg role="status" class="is-error"></p>'
      + '<div class="cvd-edit-buttons"><button value="cancel" formnovalidate>Volver</button><button id="cvd-edit-submit" value="default">Guardar cambios</button></div></form>';
    editDialog.showModal();
  }
  var searchTimer = null;
  editDialog.addEventListener("input", function (event) {
    var q = event.target.closest("[data-product-q]");
    var addQty = event.target.closest("[data-add-qty]");
    if (addQty) { editState.add[Number(addQty.dataset.addQty)].qty = Math.max(1, parseInt(addQty.value, 10) || 1); return; }
    var addExtra = event.target.closest("[data-add-extra]");
    if (addExtra) { editState.add[Number(addExtra.dataset.addExtra)].extra = addExtra.checked; return; }
    if (!q) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async function () {
      var box = editDialog.querySelector("[data-found]");
      if (q.value.trim().length < 2) { box.innerHTML = ""; return; }
      try {
        var r = await api(cvdSales.url + "/products?q=" + encodeURIComponent(q.value.trim()) + "&order=" + encodeURIComponent(editState.id), { method: "GET" });
        box.innerHTML = (r.products || []).length ? r.products.map(function (p) { return '<button type="button" class="cvd-edit-found" data-pick="' + p.wooId + '" data-name="' + escapeText(p.name) + '">' + escapeText(p.name) + " · " + escapeText(p.price) + " USD" + (p.gestoraPrice ? " (precio de la gestora)" : "") + (p.stock === null ? "" : " · quedan " + p.stock) + "</button>"; }).join("") : '<p class="cvd-edit-hint">No encontré ese producto.</p>';
      } catch (error) { box.innerHTML = '<p class="cvd-edit-hint">' + escapeText(error.message) + "</p>"; }
    }, 300);
  });
  editDialog.addEventListener("click", function (event) {
    var pick = event.target.closest("[data-pick]");
    if (pick) { editState.add.push({ wooId: Number(pick.dataset.pick), name: pick.dataset.name, qty: 1 }); editDialog.querySelector("[data-found]").innerHTML = ""; editDialog.querySelector("[data-product-q]").value = ""; paintAdded(); return; }
    var rm = event.target.closest("[data-add-rm]");
    if (rm) { editState.add.splice(Number(rm.dataset.addRm), 1); paintAdded(); }
  });
  editDialog.addEventListener("submit", async function (event) {
    if (event.submitter && event.submitter.value === "cancel") return;
    event.preventDefault();
    var f = event.target, msg = editDialog.querySelector("[data-edit-msg]"), submit = document.getElementById("cvd-edit-submit");
    var lines = Array.prototype.map.call(f.querySelectorAll("[data-line]"), function (i) { return { itemId: Number(i.dataset.line), qty: Math.max(0, parseInt(i.value, 10) || 0) }; });
    if (lines.every(function (l) { return l.qty === 0; }) && !editState.add.length) { msg.textContent = "El pedido no puede quedar sin productos. Si ya no lo quieren, cancélalo."; return; }
    var body = { firstName: f.elements.firstName.value, lastName: f.elements.lastName.value, phone: f.elements.phone.value, note: f.elements.note.value, lines: lines, add: editState.add.map(function (a) { return { wooId: a.wooId, qty: a.qty, extra: !!a.extra }; }) };
    if (f.elements.address) body.address = f.elements.address.value;
    if (f.elements.deliveryDate) { body.deliveryDate = f.elements.deliveryDate.value; body.deliveryWindow = f.elements.deliveryWindow.value; }
    submit.disabled = true; msg.textContent = "";
    try {
      var r = await api(cvdSales.url + "/" + editState.id + "/edit", { method: "POST", body: JSON.stringify(body) });
      editDialog.close(); await load();
      message.className = r.messenger ? "is-error" : ""; message.textContent = r.message || "Pedido cambiado.";
    } catch (error) { msg.textContent = error.message; }
    finally { submit.disabled = false; }
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