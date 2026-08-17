(function () {
  "use strict";

  if (!window.cvdInventory) return;

  var codeInput = document.getElementById("cvd-product-code");
  var findButton = document.getElementById("cvd-find-product");
  var cameraButton = document.getElementById("cvd-start-camera");
  var video = document.getElementById("cvd-scanner");
  var result = document.getElementById("cvd-product-result");
  var form = document.getElementById("cvd-movement-form");
  var status = document.getElementById("cvd-inventory-message");
  var movementStatus = document.getElementById("cvd-movement-message");
  var movementType = document.getElementById("cvd-movement-type");
  var quantityLabel = document.getElementById("cvd-quantity-label");
  var stream = null;
  var scanning = false;
  var reportFilter = document.getElementById("cvd-report-filter");
  var movementLabels = {
    entry: "Entrada",
    exit: "Salida",
    sale: "Venta",
    return: "Devolución",
    loss: "Pérdida",
    count: "Conteo"
  };

  var app = document.querySelector(".cvd-inventory-app");
  if (app && cvdInventory.homeUrl && cvdInventory.logoutUrl) {
    var sessionNav = document.createElement("nav");
    sessionNav.className = "cvd-inventory-session";
    sessionNav.setAttribute("aria-label", "Sesión de inventario");
    var homeLink = document.createElement("a");
    var logoutLink = document.createElement("a");
    homeLink.href = cvdInventory.homeUrl;
    homeLink.textContent = "Inicio";
    logoutLink.href = cvdInventory.logoutUrl;
    logoutLink.textContent = "Cerrar sesión";
    sessionNav.append(homeLink, logoutLink);
    app.insertBefore(sessionNav, app.firstChild);
  }

  function message(target, text, error) {
    target.textContent = text || "";
    target.classList.toggle("is-error", Boolean(error));
  }

  function withQuery(url, params) {
    var target = new URL(url, window.location.href);
    Object.keys(params).forEach(function (key) {
      var value = params[key];
      if (value === "" || value == null) {
        target.searchParams.delete(key);
      } else {
        target.searchParams.set(key, value);
      }
    });
    return target.toString();
  }

  async function api(url, options) {
    var response = await fetch(url, Object.assign({
      credentials: "same-origin",
      headers: { "X-WP-Nonce": cvdInventory.nonce, "Content-Type": "application/json" }
    }, options || {}));
    var data = await response.json();
    if (!response.ok) throw new Error(data.message || "No se pudo completar la operación.");
    return data;
  }

  function paintProduct(product) {
    document.getElementById("cvd-product-id").value = product.id;
    document.getElementById("cvd-product-image").src = product.image;
    document.getElementById("cvd-product-image").alt = product.name;
    document.getElementById("cvd-product-code-label").textContent = product.code + (product.sku ? " · SKU " + product.sku : "");
    document.getElementById("cvd-product-name").textContent = product.name;
    document.getElementById("cvd-product-description").textContent = product.description || "Sin descripción breve.";
    document.getElementById("cvd-product-price").textContent = product.price;
    document.getElementById("cvd-product-stock").textContent = product.stock;
    document.getElementById("cvd-product-link").href = product.publicUrl;
    document.getElementById("cvd-qr-name").textContent = product.name;
    document.getElementById("cvd-qr-code").textContent = product.code;
    if (window.CVQRCode) {
      window.CVQRCode.toCanvas(document.getElementById("cvd-product-qr"), product.code, { width: 150, margin: 1, errorCorrectionLevel: "M" });
    }
    result.hidden = false;
    form.hidden = false;
  }

  function escapeText(value) {
    var node = document.createElement("span");
    node.textContent = String(value == null ? "" : value);
    return node.innerHTML;
  }

  async function loadReport() {
    var list = document.getElementById("cvd-movement-list");
    if (!list || !cvdInventory.reportUrl) return;
    list.innerHTML = "<p>Cargando actividad…</p>";
    try {
      var reportUrl = withQuery(cvdInventory.reportUrl, {
        type: reportFilter && reportFilter.value ? reportFilter.value : ""
      });
      var report = await api(reportUrl, { method: "GET" });
      document.getElementById("cvd-summary-entries").textContent = report.summary.entries;
      document.getElementById("cvd-summary-exits").textContent = report.summary.exits;
      document.getElementById("cvd-summary-movements").textContent = report.summary.movements;
      document.getElementById("cvd-summary-low").textContent = report.summary.lowStock;
      if (!report.movements.length) {
        list.innerHTML = "<p>No hay movimientos para este filtro.</p>";
        return;
      }
      list.innerHTML = report.movements.map(function (row) {
        var sign = row.delta > 0 ? "+" : "";
        var productName = row.productUrl ? '<a target="_blank" rel="noopener" href="' + escapeText(row.productUrl) + '">' + escapeText(row.product) + '</a>' : escapeText(row.product);
        var orderLink = row.orderUrl ? ' · <a href="' + escapeText(row.orderUrl) + '">Pedido #' + escapeText(row.reference) + '</a>' : '';
        return '<article class="cvd-movement-row"><div><span class="cvd-movement-type is-' +
          escapeText(row.type) + '">' + escapeText(movementLabels[row.type] || row.type) +
          '</span><strong>' + productName + '</strong><small>' +
          escapeText(row.date + " · " + row.source + " · " + row.actor) + orderLink +
          '</small></div><div class="cvd-movement-stock"><b>' + sign + escapeText(row.delta) +
          '</b><small>' + escapeText(row.before + " → " + row.after) + "</small></div></article>";
      }).join("");
    } catch (error) {
      list.innerHTML = '<p class="is-error">' + escapeText(error.message) + "</p>";
    }
  }

  async function findProduct(rawCode) {
    var code = String(rawCode || codeInput.value).trim();
    if (!code) return message(status, "Escribe o escanea un código.", true);
    message(status, "Buscando…", false);
    try {
      var product = await api(withQuery(cvdInventory.productUrl, { code: code }), { method: "GET" });
      codeInput.value = product.code;
      paintProduct(product);
      message(status, "Producto encontrado.", false);
    } catch (error) {
      result.hidden = true;
      form.hidden = true;
      message(status, error.message, true);
    }
  }

  function stopCamera() {
    scanning = false;
    if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
    stream = null;
    video.hidden = true;
    cameraButton.textContent = "Escanear con la cámara";
  }

  async function startCamera() {
    if (stream) return stopCamera();
    if (!("BarcodeDetector" in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return message(status, "Este navegador no admite el escáner automático. Escribe el código manualmente.", true);
    }
    try {
      var detector = new BarcodeDetector({ formats: ["qr_code", "ean_13", "ean_8", "code_128", "upc_a", "upc_e"] });
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false });
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      scanning = true;
      cameraButton.textContent = "Cerrar cámara";
      message(status, "Apunta al código sin mover el teléfono.", false);
      var detect = async function () {
        if (!scanning) return;
        try {
          var codes = await detector.detect(video);
          if (codes.length) {
            codeInput.value = codes[0].rawValue;
            stopCamera();
            return findProduct(codes[0].rawValue);
          }
        } catch (error) {}
        window.setTimeout(detect, 220);
      };
      detect();
    } catch (error) {
      stopCamera();
      message(status, "No fue posible abrir la cámara. Revisa el permiso del navegador.", true);
    }
  }

  findButton.addEventListener("click", function () { findProduct(); });
  codeInput.addEventListener("keydown", function (event) { if (event.key === "Enter") { event.preventDefault(); findProduct(); } });
  cameraButton.addEventListener("click", startCamera);
  document.getElementById("cvd-print-qr").addEventListener("click", function () { window.print(); });
  movementType.addEventListener("change", function () {
    quantityLabel.textContent = movementType.value === "count" ? "Existencia física contada" : "Cantidad";
  });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    var payload = {
      uuid: window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now() + "-" + Math.random().toString(16).slice(2),
      productId: Number(document.getElementById("cvd-product-id").value),
      type: movementType.value,
      quantity: Number(document.getElementById("cvd-movement-quantity").value),
      reason: document.getElementById("cvd-movement-reason").value,
      referenceType: "manual"
    };
    message(movementStatus, "Guardando…", false);
    form.querySelector("button[type=submit]").disabled = true;
    try {
      var response = await api(cvdInventory.movementUrl, { method: "POST", body: JSON.stringify(payload) });
      paintProduct(response.product);
      document.getElementById("cvd-movement-quantity").value = "";
      document.getElementById("cvd-movement-reason").value = "";
      message(movementStatus, response.duplicate ? "Este movimiento ya estaba registrado." : response.message, false);
      loadReport();
    } catch (error) {
      message(movementStatus, error.message, true);
    } finally {
      form.querySelector("button[type=submit]").disabled = false;
    }
  });

  if (reportFilter) reportFilter.addEventListener("change", loadReport);
  loadReport();

  window.addEventListener("pagehide", stopCamera);
})();