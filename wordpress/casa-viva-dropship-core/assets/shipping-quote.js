(() => {
  "use strict";
  const root = document.querySelector("[data-cvd-quote]");
  if (!root || !window.cvdShippingQuote) return;
  document.body.classList.add("cvd-quote-page");
  const municipality = root.querySelector("[data-quote-municipality]");
  const zone = root.querySelector("[data-quote-zone]");
  const submit = root.querySelector("[data-quote-submit]");
  const status = root.querySelector("[role=status]");
  const result = root.querySelector("[data-quote-result]");
  const copy = root.querySelector("[data-quote-copy]");
  const share = root.querySelector("[data-quote-share]");
  let shareText = "";

  municipality.addEventListener("change", () => {
    zone.replaceChildren(Object.assign(document.createElement("option"), { value: "", textContent: "Selecciona zona o reparto" }));
    for (const name of window.cvdShippingQuote.localities[municipality.value] || []) zone.append(Object.assign(document.createElement("option"), { value: name, textContent: name }));
    zone.disabled = !municipality.value;
    result.hidden = true;
    status.textContent = "";
  });

  submit.addEventListener("click", async () => {
    if (!municipality.value || !zone.value) {
      status.textContent = "Selecciona municipio y zona.";
      (!municipality.value ? municipality : zone).focus();
      return;
    }
    result.hidden = true;
    status.textContent = "Consultando…";
    submit.disabled = true;
    try {
      const response = await fetch(`${window.cvdShippingQuote.endpoint}?municipality=${encodeURIComponent(municipality.value)}&zone=${encodeURIComponent(zone.value)}`, { credentials: "same-origin" });
      const quote = await response.json();
      if (!response.ok) throw new Error("No se pudo consultar. Reintenta.");
      const official = quote.official && quote.feeCup > 0;
      root.querySelector("[data-quote-kind]").textContent = official ? "Tarifa oficial" : "Requiere confirmación";
      root.querySelector("[data-quote-price]").textContent = official ? `${quote.feeCup} CUP` : "Por confirmar";
      root.querySelector("[data-quote-route]").textContent = quote.destination || "Destino sin definir";
      shareText = official ? `Mensajería a ${quote.destination}: ${quote.feeCup} CUP` : `Mensajería a ${quote.destination}: requiere confirmación`;
      result.hidden = false;
      status.textContent = official ? "" : "Esta ruta todavía no tiene una tarifa definida.";
    } catch (error) {
      status.textContent = error instanceof Error ? error.message : "No se pudo consultar. Reintenta.";
    } finally {
      submit.disabled = false;
    }
  });

  async function copyQuote(button) {
    if (!shareText) return;
    try {
      await navigator.clipboard.writeText(shareText);
      const previous = button.textContent;
      button.textContent = "Copiado";
      window.setTimeout(() => { button.textContent = previous; }, 1400);
    } catch {
      status.textContent = "No se pudo copiar.";
    }
  }

  copy.addEventListener("click", () => copyQuote(copy));
  share.addEventListener("click", async () => {
    if (!shareText) return;
    try {
      if (navigator.share) await navigator.share({ title: "Tarifa de mensajería", text: shareText });
      else await copyQuote(share);
    } catch (error) {
      if (error?.name !== "AbortError") status.textContent = "No se pudo compartir.";
    }
  });
})();
