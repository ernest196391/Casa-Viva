(function () {
  "use strict";

  var center = document.querySelector(".cvd-order-center");
  if (!center || !window.cvdStructuredIncidents) return;

  var orderId = Number(center.dataset.orderId || 0);
  if (!orderId) return;

  var panel = document.createElement("section");
  panel.id = "cvd-structured-incidents";
  panel.className = "cvd-oc-card cvd-structured-incidents";
  panel.innerHTML = '<h2>Incidencia operativa</h2><p data-incident-status>Cargando…</p>';
  center.appendChild(panel);

  function esc(value) {
    var node = document.createElement("span");
    node.textContent = String(value == null ? "" : value);
    return node.innerHTML;
  }

  function request(method, body) {
    var key = "incident-" + orderId + "-" + Date.now() + "-" + Math.random().toString(16).slice(2);
    return fetch(cvdStructuredIncidents.url + orderId, {
      method: method,
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": cvdStructuredIncidents.nonce,
        "X-CVD-Idempotency-Key": key
      },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || "No se pudo actualizar la incidencia.");
        return data;
      });
    });
  }

  function render(data) {
    var active = data.active || {};
    if (active.active) {
      panel.innerHTML = '<h2>Incidencia operativa</h2>' +
        '<p><strong>' + esc(active.label || "Incidencia operativa") + '</strong></p>' +
        '<p>' + esc(active.note || "") + '</p>' +
        '<small>' + esc(active.at || "") + '</small>' +
        '<form data-incident-resolve>' +
        '<label>Cómo se resolvió<textarea name="note" rows="3" maxlength="500" required></textarea></label>' +
        '<button type="submit">Resolver incidencia</button>' +
        '<span data-incident-message role="status"></span>' +
        '</form>';
      return;
    }

    var reasons = data.allowedReasons || {};
    var options = Object.keys(reasons).map(function (key) {
      return '<option value="' + esc(key) + '">' + esc(reasons[key].label) + '</option>';
    }).join("");
    if (!options) {
      panel.innerHTML = '<h2>Incidencia operativa</h2><p>No hay incidencias operativas disponibles para la etapa actual.</p>';
      return;
    }
    panel.innerHTML = '<h2>Incidencia operativa</h2>' +
      '<form data-incident-open>' +
      '<label>Motivo<select name="reason" required><option value="">Selecciona</option>' + options + '</select></label>' +
      '<label>Qué ocurrió<textarea name="note" rows="3" maxlength="500" required></textarea></label>' +
      '<button type="submit">Registrar incidencia</button>' +
      '<span data-incident-message role="status"></span>' +
      '</form>';
  }

  function load() {
    request("GET").then(render).catch(function (error) {
      panel.innerHTML = '<h2>Incidencia operativa</h2><p class="is-error">' + esc(error.message) + '</p>';
    });
  }

  panel.addEventListener("submit", function (event) {
    event.preventDefault();
    var form = event.target;
    var message = form.querySelector("[data-incident-message]");
    var button = form.querySelector('button[type="submit"]');
    var isOpen = form.hasAttribute("data-incident-open");
    var payload = {
      action: isOpen ? "open" : "resolve",
      note: form.elements.note.value
    };
    if (isOpen) payload.reason = form.elements.reason.value;
    button.disabled = true;
    message.textContent = isOpen ? "Registrando…" : "Resolviendo…";
    request("POST", payload).then(function (response) {
      render(response.incident);
      window.setTimeout(function () { window.location.reload(); }, 250);
    }).catch(function (error) {
      message.textContent = error.message;
      button.disabled = false;
    });
  });

  load();
}());
