(function () {
  "use strict";
  var root = document.querySelector("[data-cvd-customer-order-detail]");
  if (!root || typeof cvdCustomerOrderLive === "undefined") return;

  var stage = root.querySelector("[data-cvd-customer-stage]");
  var liveStatus = root.querySelector("[data-cvd-live-status]");
  var locationLink = root.querySelector("[data-cvd-live-location]");
  var timeline = root.querySelector("[data-cvd-customer-timeline]");
  var timer = null;
  var fingerprint = "";

  function esc(value) {
    var node = document.createElement("div");
    node.textContent = value == null ? "" : String(value);
    return node.innerHTML;
  }

  function mapUrl(location) {
    if (!location || typeof location.latitude !== "number" || typeof location.longitude !== "number") return "";
    return "https://www.google.com/maps/search/?api=1&query=" + encodeURIComponent(location.latitude + "," + location.longitude);
  }

  function renderTimeline(events) {
    if (!timeline || !Array.isArray(events)) return;
    timeline.innerHTML = events.map(function (event, index) {
      return '<li class="' + (index === events.length - 1 ? "is-current" : "") + '"><i></i><div><strong>' + esc(event.label) + '</strong>' + (event.timestamp ? '<span>' + esc(event.timestamp) + '</span>' : '') + '</div></li>';
    }).join("");
  }

  function render(data) {
    if (stage && data.stageLabel) stage.textContent = data.stageLabel;
    if (liveStatus) liveStatus.textContent = data.deliveryStatusLabel || data.stageLabel || "Seguimiento actualizado";
    var url = mapUrl(data.location);
    if (locationLink) {
      if (url) {
        locationLink.href = url;
        locationLink.hidden = false;
      } else {
        locationLink.hidden = true;
        locationLink.removeAttribute("href");
      }
    }
    renderTimeline(data.timeline || []);
  }

  function load(force) {
    fetch(cvdCustomerOrderLive.url, {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": cvdCustomerOrderLive.nonce }
    }).then(function (response) {
      return response.json().then(function (json) {
        if (!response.ok) throw new Error(json.message || "No se pudo actualizar el seguimiento.");
        return json;
      });
    }).then(function (data) {
      var next = JSON.stringify([data.stage, data.deliveryStatus, data.location, data.timeline]);
      if (force || next !== fingerprint) {
        fingerprint = next;
        render(data);
      }
    }).catch(function () {
      if (liveStatus) liveStatus.textContent = "No pudimos actualizar ahora. Reintentaremos automáticamente.";
    });
  }

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) load(true);
  });
  load(true);
  timer = window.setInterval(function () {
    if (!document.hidden) load(false);
  }, Math.max(5000, Number(cvdCustomerOrderLive.interval) || 8000));
  window.addEventListener("pagehide", function () { window.clearInterval(timer); });
}());
