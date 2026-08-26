(() => {
  "use strict";
  const launcher = document.querySelector(".cvd-assistant-launcher");
  const panel = document.querySelector(".cvd-contextual-assistant");
  if (!launcher || !panel || !window.cvdContextualAssistant) return;
  const answer = panel.querySelector(".cvd-contextual-assistant__answer p");
  const form = panel.querySelector("form");
  const input = panel.querySelector("input");
  const context = window.cvdContextualAssistant.context;

  function open() {
    const messengerAssistant = document.querySelector("#asistente[data-cvd-assistant]");
    if (messengerAssistant) {
      document.querySelector('.cvd-messenger-launchpad a[href="#asistente"]')?.click();
      return;
    }
    panel.hidden = false;
    launcher.setAttribute("aria-expanded", "true");
    window.setTimeout(() => input.focus(), 0);
  }
  function close() { panel.hidden = true; launcher.setAttribute("aria-expanded", "false"); launcher.focus(); }
  function link(label, url) { return `<a href="${url}">${label}</a>`; }
  function reply(question) {
    const value = question.toLocaleLowerCase("es");
    const urls = window.cvdContextualAssistant;
    let message;
    if (/pedido|estado|seguimiento|dónde está/.test(value)) message = context === "mensajero" ? `Abre ${link("tu Ruta", urls.routeUrl)} para ver únicamente tus entregas.` : `Consulta ${link("Mis pedidos", urls.ordersUrl)} para ver estado y progreso.`;
    else if (/tarifa|mensajería|envío|zona|reparto/.test(value)) message = `Consulta la ${link("tarifa oficial", urls.ratesUrl)} seleccionando municipio y reparto.`;
    else if (/pago|pagar|transferencia|efectivo|moneda/.test(value)) message = "En la confirmación del pedido aparecen los importes y monedas aceptados. Casa Viva nunca convierte monedas de forma implícita.";
    else if (/gestora|comisión|vale/.test(value) && context === "gestora") message = `Desde ${link("Gestoras", urls.managersUrl)} puedes revisar tus pedidos y enviar un vale. Las asignaciones de mensajería siguen el mecanismo oficial.`;
    else if (/ruta|entrega|cliente|llamar|vuelto/.test(value) && context === "mensajero") message = `Abre ${link("Ruta", urls.routeUrl)}. Allí tienes contactos, preparación, vuelto, cobro y el asistente de tu jornada.`;
    else if (/devolver|cambio|garantía|problema|ayuda|contacto/.test(value)) message = "Para una incidencia o postventa, usa el botón de WhatsApp y envía el número de pedido junto con una descripción breve. No compartas datos bancarios.";
    else message = "Puedo ayudarte con pedidos, pagos, tarifas, entregas y uso de tu cuenta. Escribe una de esas dudas o usa WhatsApp para atención humana.";
    answer.innerHTML = message;
  }

  launcher.addEventListener("click", open);
  panel.querySelector("[data-cvd-assistant-close]").addEventListener("click", close);
  panel.querySelectorAll("[data-question]").forEach((button) => button.addEventListener("click", () => reply(button.dataset.question)));
  form.addEventListener("submit", (event) => { event.preventDefault(); const question = input.value.trim(); if (question) reply(question); });
  document.addEventListener("keydown", (event) => { if (event.key === "Escape" && !panel.hidden) close(); });
})();
