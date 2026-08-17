(function () {
  "use strict";
  if (!window.cvdInventory) return;

  var movementType = document.getElementById("cvd-movement-type");
  if (movementType) {
    ["sale", "return"].forEach(function (value) {
      var option = movementType.querySelector('option[value="' + value + '"]');
      if (option) option.remove();
    });
  }

  var summary = document.querySelector(".cvd-report-summary");
  var report = document.querySelector(".cvd-inventory-report");
  if (!summary || !report || !cvdInventory.reportUrl) return;

  var integrityCard = document.createElement("article");
  integrityCard.innerHTML = '<span>Discrepancias</span><strong id="cvd-summary-discrepancies">—</strong>';
  summary.appendChild(integrityCard);

  var notice = document.createElement("div");
  notice.id = "cvd-inventory-integrity";
  notice.setAttribute("role", "status");
  notice.hidden = true;
  report.insertBefore(notice, report.querySelector(".cvd-movement-list"));

  function escapeText(value) {
    var node = document.createElement("span");
    node.textContent = String(value == null ? "" : value);
    return node.innerHTML;
  }

  async function refreshIntegrity() {
    try {
      var response = await fetch(cvdInventory.reportUrl + "?limit=10", {
        credentials: "same-origin",
        headers: { "X-WP-Nonce": cvdInventory.nonce, "Content-Type": "application/json" }
      });
      var data = await response.json();
      if (!response.ok || !data.integrity) return;
      var integrity = data.integrity;
      document.getElementById("cvd-summary-discrepancies").textContent = integrity.discrepancyCount || 0;
      if (!integrity.discrepancyCount) {
        notice.hidden = false;
        notice.className = "cvd-integrity-ok";
        notice.innerHTML = "<strong>Inventario conciliado</strong><p>El stock oficial coincide con el último saldo auditado.</p>";
        return;
      }
      notice.hidden = false;
      notice.className = "cvd-integrity-warning";
      var rows = (integrity.discrepancies || []).slice(0, 8).map(function (item) {
        var code = item.code ? " · " + escapeText(item.code) : "";
        return '<li><strong>' + escapeText(item.product) + code + '</strong><span>Auditado: ' + escapeText(item.expectedStock) + ' · WooCommerce: ' + escapeText(item.currentStock) + '</span></li>';
      }).join("");
      notice.innerHTML = '<strong>Reconciliación requerida</strong><p>Hay ' + escapeText(integrity.discrepancyCount) + ' producto(s) cuyo stock oficial no coincide con el último saldo auditado. Realiza un conteo físico antes de nuevas entradas o salidas.</p><ul>' + rows + '</ul>';
    } catch (error) {}
  }

  var movementMessage = document.getElementById("cvd-movement-message");
  if (movementMessage && window.MutationObserver) {
    new MutationObserver(function () {
      if (movementMessage.textContent) window.setTimeout(refreshIntegrity, 250);
    }).observe(movementMessage, { childList: true, subtree: true, characterData: true });
  }

  refreshIntegrity();
})();