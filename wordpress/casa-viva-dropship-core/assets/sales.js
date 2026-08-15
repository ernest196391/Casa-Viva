(function () {
  "use strict";
  if (!window.cvdSales) return;
  var list = document.getElementById("cvd-sales-list");
  var summary = document.getElementById("cvd-sales-summary");
  var filter = document.getElementById("cvd-sales-filter");
  var search = document.getElementById("cvd-sales-search");
  var message = document.getElementById("cvd-sales-message");
  var labels = { new: "Nuevo", confirmed: "Confirmar", preparing: "Preparar", ready: "Listo para mensajería", picked_up: "Entregado al mensajero", delivered: "Registrar dinero recibido", incident: "Incidencia", cancelled: "Cancelar" };
  var moneyDialog = document.getElementById("cvd-money-dialog");
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
  function actionButtons(order) {
    return order.actions.filter(function (status) { return cvdSales.isAdmin || status !== "cancelled"; }).map(function (status) {
      var danger = status === "cancelled" || status === "incident" ? " is-warning" : status === "delivered" ? " is-success" : "";
      return '<button class="cvd-sale-action' + danger + '" data-order="' + order.id + '" data-status="' + status + '">' + escapeText(labels[status] || status) + "</button>";
    }).join("");
  }
  function paint(data) {
    var cards = [["new", "Nuevos"], ["preparing", "Preparando"], ["ready", "Listos"], ["with_courier", "En camino"]];
    summary.innerHTML = cards.map(function (x) { return '<button type="button" data-summary-status="' + x[0] + '"><span>' + x[1] + '</span><strong>' + (data.summary[x[0]] || 0) + "</strong></button>"; }).join("");
    if (!data.orders.length) { list.innerHTML = "<p>No hay pedidos con este filtro.</p>"; return; }
    list.innerHTML = data.orders.map(function (order) {
      var phone = order.phone ? '<a href="tel:' + escapeText(order.phone) + '">' + escapeText(order.phone) + "</a>" : "";
      var wa = order.phone ? '<a class="cvd-sale-wa" target="_blank" rel="noopener" href="https://wa.me/' + escapeText(order.phone.replace(/\D/g, "")) + '">WhatsApp</a>' : "";
      return '<article class="cvd-sale-card"><div class="cvd-sale-top"><div><span class="cvd-sale-status is-' + order.status + '">' + escapeText(order.statusLabel) + '</span><h2>Pedido #' + escapeText(order.number) + '</h2><small>' + escapeText(order.date) + '</small></div><div class="cvd-sale-code"><canvas data-order-code="' + escapeText(order.orderCode) + '"></canvas><small>' + escapeText(order.orderCode) + '</small></div><strong>' + escapeText(order.total) + (order.shippingCup ? ' + ' + escapeText(order.shippingCup) + ' CUP' : '') + '</strong></div><div class="cvd-sale-data"><p><b>Cliente</b><span>' + escapeText(order.customer) + " · " + phone + '</span></p><p><b>Entrega</b><span>' + escapeText(order.fulfillment + " · " + order.address) + '</span></p><p><b>Productos</b><span>' + productLinks(order.products) + '</span></p><p><b>Mensajería</b><span>' + escapeText(order.deliveryStatus || "No aplica") + '</span></p><p><b>Gestora</b><span>' + escapeText(order.gestora || "Casa Viva · Venta directa") + '</span></p><p><b>Comisión</b><span>' + escapeText(order.commission.toFixed(2) + " · " + order.commissionStatus) + '</span></p></div><div class="cvd-sale-actions">' + actionButtons(order) + (order.trackingUrl ? '<button type="button" data-copy-tracking="' + escapeText(order.trackingUrl) + '">Copiar seguimiento</button>' : '') + wa + (cvdSales.isAdmin ? '<a href="' + escapeText(order.adminUrl) + '" target="_blank" rel="noopener">Ver pedido</a>' : '') + '</div></article>';
    }).join("");
    if (window.CVQRCode) document.querySelectorAll("[data-order-code]").forEach(function (canvas) { window.CVQRCode.toCanvas(canvas, canvas.dataset.orderCode, { width: 84, margin: 1 }); });
  }
  async function load() {
    message.textContent = "Actualizando…";
    var params = new URLSearchParams();
    if (filter.value) params.set("status", filter.value);
    if (search.value.trim()) params.set("search", search.value.trim());
    try { var data = await api(cvdSales.url + (params.toString() ? "?" + params : ""), { method: "GET" }); paint(data); message.textContent = ""; }
    catch (error) { message.textContent = error.message; message.className = "is-error"; }
  }
  list.addEventListener("click", async function (event) {
    var copy = event.target.closest('[data-copy-tracking]');
    if (copy) { try { await navigator.clipboard.writeText(copy.dataset.copyTracking); copy.textContent = 'Copiado'; } catch (error) { message.textContent = 'No se pudo copiar el enlace.'; } return; }
    var button = event.target.closest(".cvd-sale-action"); if (!button) return;
    var status = button.dataset.status;
    if (status === "delivered") {
      document.getElementById("cvd-money-order").value = button.dataset.order;
      var card = button.closest(".cvd-sale-card");
      document.getElementById("cvd-money-usd").value = ((card && card.querySelector(".cvd-sale-top>strong").textContent.match(/[\d.,]+/)) || [""])[0].replace(",", ".");
      moneyDialog.showModal(); return;
    }
    if (status === "cancelled" && !window.confirm("¿Cancelar el pedido? Se repondrá el stock y se cancelará la comisión.")) return;
    button.disabled = true; message.textContent = "Guardando…";
    try { await api(cvdSales.url + "/" + button.dataset.order + "/status", { method: "POST", body: JSON.stringify({ status: status }) }); await load(); }
    catch (error) { message.textContent = error.message; message.className = "is-error"; button.disabled = false; }
  });
  document.getElementById("cvd-money-form").addEventListener("submit", async function (event) {
    if (event.submitter && event.submitter.value === "cancel") return;
    event.preventDefault(); var submit = document.getElementById("cvd-money-submit"); submit.disabled = true;
    try { await api(cvdSales.url + "/" + document.getElementById("cvd-money-order").value + "/status", { method: "POST", body: JSON.stringify({ status: "delivered", collectionMethod: document.getElementById("cvd-money-method").value, collectedUsd: document.getElementById("cvd-money-usd").value, collectedCup: document.getElementById("cvd-money-cup").value, collectionNote: document.getElementById("cvd-money-note").value, moneyConfirmed: document.getElementById("cvd-money-confirmed").checked }) }); moneyDialog.close(); event.target.reset(); await load(); }
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
  filter.addEventListener("change", load);
  summary.addEventListener("click", function (event) { var card=event.target.closest('[data-summary-status]'); if(!card)return; filter.value=card.dataset.summaryStatus; load(); document.getElementById('cvd-sales-list').scrollIntoView({behavior:'smooth',block:'start'}); });
  document.getElementById("cvd-sales-refresh").addEventListener("click", load);
  search.addEventListener("keydown", function (event) { if (event.key === "Enter") load(); });
  var requestedOrder = new URLSearchParams(location.search).get("order"); if (requestedOrder) search.value = requestedOrder;
  load();
  window.setInterval(function(){ if(!document.hidden)load(); },8000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) load(); });
  window.addEventListener("pagehide", stopScanner);
})();
