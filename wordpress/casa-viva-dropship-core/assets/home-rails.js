/* 3.13.83 · Portada que vende: cuenta (sin datos personales) visitas, toques en productos, «Añadir» y «Ver todo» por fila. */
(() => {
  const cfg = window.cvdHome || {};
  if (!cfg.ajax) return;
  const mirror = /[?&](ref|cv_ref)=/.test(window.location.search) ? "1" : "";

  const send = (event, list) => {
    try {
      const body = new FormData();
      body.append("action", "cvd_home_event");
      body.append("e", event);
      body.append("l", list || "");
      body.append("m", mirror);
      if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajax, body)) return;
      fetch(cfg.ajax, { method: "POST", body, keepalive: true, credentials: "same-origin" }).catch(() => {});
    } catch (error) { /* el contador nunca estorba la compra */ }
  };

  // Una visita por sesión y día (la página puede venir de la caché: el recuento lo hace el navegador).
  let seen = false;
  try {
    const key = "cvdHomeView-" + new Date().toISOString().slice(0, 10);
    seen = sessionStorage.getItem(key) === "1";
    if (!seen) sessionStorage.setItem(key, "1");
  } catch (error) { /* sin almacenamiento */ }
  if (!seen) send("view", "");

  document.addEventListener("click", (event) => {
    const el = event.target && event.target.closest ? event.target.closest("[data-cvd-list]") : null;
    if (!el) return;
    const kind = el.dataset.cvdKind || (el.classList.contains("ajax_add_to_cart") ? "add" : "click");
    send(kind, el.dataset.cvdList);
  }, true);

  // «Añadir» confirma en el mismo botón; el panel del carrito (con «Pagar ahora») lo abre la marca.
  if (window.jQuery) {
    window.jQuery(document.body).on("added_to_cart", (event, fragments, hash, button) => {
      const el = button && button[0];
      if (el && el.classList.contains("cvd-card__add")) el.textContent = "✓ Añadido";
    });
  }
})();
