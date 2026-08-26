(() => {
  "use strict";
  const root = document.querySelector("[data-cvd-voucher]");
  if (!root || !window.cvdVoucherIntake) return;
  const ui = { text: root.querySelector("#cvd-voucher-text"), parse: root.querySelector("[data-voucher-parse]"), status: root.querySelector("[role=status]"), review: root.querySelector("[data-voucher-review]"), form: root.querySelector("[data-voucher-form]"), alerts: root.querySelector("[data-voucher-alerts]"), confidence: root.querySelector("[data-voucher-confidence]"), confirm: root.querySelector("[data-voucher-confirm]"), result: root.querySelector("[data-voucher-payload]") };
  let draft = null, confirmationKey = "";
  const PARSE_TIMEOUT_MS = 35000;
  const ENRICH_TIMEOUT_MS = 8000;
  async function responseBody(response) {
    const text = await response.text();
    try { return text ? JSON.parse(text) : {}; } catch { return {}; }
  }
  async function fetchWithTimeout(url, options = {}, timeout = ENRICH_TIMEOUT_MS) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeout);
    try { return await fetch(url, { ...options, signal: controller.signal }); }
    finally { window.clearTimeout(timer); }
  }
  const scalar = [["orderCode", "ID pedido"], ["store", "Origen / tienda"], ["sourceUrl", "Enlace original"], ["manager", "Gestor/a"], ["managerCode", "Código"], ["customer", "Cliente"], ["address", "Dirección"], ["betweenStreets", "Entrecalles"], ["reference", "Referencia"], ["municipality", "Municipio"], ["zone", "Zona/reparto"], ["scheduledDate", "Fecha (AAAA-MM-DD)"], ["scheduledTime", "Horario"]];
  function field(label, value, name, tag = "input") { const wrap = document.createElement("label"); wrap.className = "cvd-voucher-field"; wrap.append(Object.assign(document.createElement("span"), { textContent: label })); const input = document.createElement(tag); input.value = value ?? ""; input.dataset.key = name; wrap.append(input); return wrap; }
  function choices(row, product, index) {
    const search = field("Buscar en catálogo", product.name, `search.${index}`), select = document.createElement("select"); select.dataset.productId = String(index); select.setAttribute("aria-label", `Producto Casa Viva para ${product.name}`); row.append(search, select, field("Cantidad", product.quantity, `product.${index}.quantity`));
    const load = async () => { select.replaceChildren(Object.assign(document.createElement("option"), { value: "", textContent: "Selecciona el producto canónico" })); try { const response = await fetchWithTimeout(`${window.cvdVoucherIntake.productsEndpoint}?q=${encodeURIComponent(search.querySelector("input").value)}`, { credentials: "same-origin", headers: { "X-WP-Nonce": window.cvdVoucherIntake.nonce } }); for (const item of await response.json()) { const option = document.createElement("option"); option.value = String(item.id); option.textContent = `${item.name} · ${item.price} ${item.currency}`; select.append(option); } } catch { select.firstElementChild.textContent = "Catálogo no disponible · reintenta"; } };
    search.querySelector("input").addEventListener("change", load); void load();
  }
  function interpretedMoney() { const section = document.createElement("div"); section.className = "cvd-voucher-interpreted-money"; section.append(Object.assign(document.createElement("h3"), { textContent: "Importes y vuelto interpretados" })); const values = [...(draft.productTotals || []).map((item) => `Productos: ${item.amount} ${item.currency}`), ...(draft.changeRequired || []).map((item) => `Vuelto: ${item.amount} ${item.currency}`)]; if (!values.length) values.push("No se interpretaron importes de producto ni vuelto."); values.forEach((value) => section.append(Object.assign(document.createElement("p"), { textContent: value }))); return section; }
  function normalizeSplitPayment() { const delivery = draft.deliveryCharge; if (!delivery) return; const total = Number(delivery.amount || 0), deduction = Number(delivery.commissionAdjustment || 0), payer = String(delivery.payer || "").toLocaleLowerCase("es"); if (deduction > 0 && total >= deduction && /client/.test(payer)) { draft.deliveryVoucherTotal = total; delivery.amount = total - deduction; draft.warnings = [...(draft.warnings || []), `Pagador dividido propuesto: cliente ${delivery.amount} CUP y gestora ${deduction} CUP. Revisa antes de confirmar.`]; } }
  async function render() {
    ui.form.replaceChildren(); const grid = document.createElement("div"); grid.className = "cvd-voucher-grid"; scalar.forEach(([key, label]) => grid.append(field(label, draft[key], key))); grid.append(field("Teléfonos (uno por línea)", (draft.phones || []).join("\n"), "phones", "textarea"), field("Notas (una por línea)", (draft.notes || []).join("\n"), "notes", "textarea")); ui.form.append(grid);
    ui.form.append(Object.assign(document.createElement("h3"), { textContent: "Productos: vincula cada línea al catálogo Casa Viva" })); for (const [index, product] of (draft.products || []).entries()) { const row = document.createElement("div"); row.className = "cvd-voucher-product cvd-voucher-product-map"; ui.form.append(row); choices(row, product, index); }
    ui.form.append(interpretedMoney()); const delivery = draft.deliveryCharge || {}; draft.deliveryCustomerAmount = Number(delivery.amount || 0); draft.deliveryManagerAmount = Number(delivery.commissionAdjustment || 0); const payment = document.createElement("div"); payment.className = "cvd-voucher-payment"; payment.append(Object.assign(document.createElement("h3"), { textContent: "Mensajería y pagador" }), field("Mensajería indicada en el vale (CUP)", delivery.amount || "", "deliveryVoucherAmount"), field("Cobrar al cliente (CUP)", draft.deliveryCustomerAmount || "", "deliveryCustomerAmount"), field("Descontar a gestora (CUP)", draft.deliveryManagerAmount || "", "deliveryManagerAmount")); const quote = document.createElement("p"); quote.className = "cvd-voucher-quote"; quote.textContent = "Consultando tarifa oficial…"; payment.append(quote); ui.form.append(payment);
    ui.review.hidden = false;
    try { const response = await fetchWithTimeout(`${window.cvdVoucherIntake.quoteEndpoint}?municipality=${encodeURIComponent(draft.municipality || "")}&zone=${encodeURIComponent(draft.zone || "")}`, { credentials: "same-origin" }); const official = await response.json(); draft.officialShippingFeeCup = Number(official.feeCup || 0); if (official.official) { const voucher = Number(delivery.amount || 0); quote.textContent = voucher === draft.officialShippingFeeCup ? `✅ Coincide con tarifa oficial: ${official.feeCup} CUP` : `⚠️ Tarifa oficial: ${official.feeCup} CUP · El vale indica ${voucher || "sin importe"} CUP`; quote.classList.toggle("is-warning", voucher !== draft.officialShippingFeeCup); } else { quote.textContent = "❓ Ruta sin tarifa oficial definida. Requiere cotización."; quote.classList.add("is-warning"); } } catch { quote.textContent = "No se pudo consultar la tarifa. Reintenta antes de confirmar."; quote.classList.add("is-warning"); }
    ui.alerts.replaceChildren(); for (const [kind, items] of [["Faltan datos", draft.missing || []], ["Advertencias", draft.warnings || []]]) if (items.length) { const box = document.createElement("div"); box.className = "cvd-voucher-alert"; box.append(Object.assign(document.createElement("strong"), { textContent: kind })); const list = document.createElement("ul"); items.forEach((item) => list.append(Object.assign(document.createElement("li"), { textContent: item }))); box.append(list); ui.alerts.append(box); } ui.confidence.textContent = `${Math.round(Number(draft.confidence || 0) * 100)}% confianza`;
  }
  function collect() { ui.form.querySelectorAll("[data-key]").forEach((input) => { const key = input.dataset.key; if (key === "phones" || key === "notes") draft[key] = input.value.split(/\n|,/).map((value) => value.trim()).filter(Boolean); else if (key.startsWith("product.")) { const [, index, property] = key.split("."); draft.products[Number(index)][property] = Number(input.value); } else if (["deliveryVoucherAmount", "deliveryCustomerAmount", "deliveryManagerAmount"].includes(key)) draft[key] = Number(input.value || 0); else if (!key.startsWith("search.")) draft[key] = input.value || null; }); return [...ui.form.querySelectorAll("[data-product-id]")].map((select, index) => ({ productId: Number(select.value), quantity: Number(draft.products[index]?.quantity || 1) })); }
  ui.parse.addEventListener("click", async () => {
    const voucherText = ui.text.value.trim();
    if (voucherText.length < 20) {
      ui.status.textContent = "Pega el vale completo antes de analizar.";
      ui.text.focus();
      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), PARSE_TIMEOUT_MS);
    const slowTimer = window.setTimeout(() => { if (ui.parse.disabled) ui.status.textContent = "Sigue analizando. Esto puede tardar unos segundos…"; }, 12000);
    ui.status.textContent = "Analizando…";
    ui.parse.disabled = true;
    ui.review.hidden = true;
    ui.result.hidden = true;
    try {
      const response = await fetch(window.cvdVoucherIntake.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": window.cvdVoucherIntake.nonce },
        body: JSON.stringify({ text: voucherText }),
        signal: controller.signal
      });
      const body = await responseBody(response);
      if (!response.ok) throw new Error(body?.message || "No se pudo analizar. Reintenta.");
      if (!body?.draft) throw new Error("La respuesta no se pudo leer. Reintenta.");
      draft = body.draft;
      draft.municipality = draft.municipality || "";
      normalizeSplitPayment();
      confirmationKey = crypto.randomUUID();
      await render();
      const total = ui.form.querySelector('[data-key="deliveryVoucherAmount"]');
      if (total && draft.deliveryVoucherTotal) total.value = String(draft.deliveryVoucherTotal);
      ui.status.textContent = "Listo. Revisa los datos.";
    } catch (error) {
      if (error?.name === "AbortError") ui.status.textContent = "El análisis tardó demasiado. Reintenta; no se creó ningún pedido.";
      else if (error instanceof TypeError) ui.status.textContent = "No hubo conexión para analizar. Reintenta; no se creó ningún pedido.";
      else ui.status.textContent = error instanceof Error ? error.message : "No se pudo analizar. Reintenta; no se creó ningún pedido.";
    } finally {
      window.clearTimeout(timer);
      window.clearTimeout(slowTimer);
      ui.parse.disabled = false;
    }
  });
  ui.confirm.addEventListener("click", async () => { if (!window.cvdVoucherIntake.canConfirm) return; ui.confirm.disabled = true; try { const lines = collect(); if (lines.some((line) => !line.productId)) throw new Error("Vincula todos los productos con el catálogo Casa Viva."); const split = Number(draft.deliveryCustomerAmount || 0) + Number(draft.deliveryManagerAmount || 0); if (split > 0 && split !== Number(draft.officialShippingFeeCup || 0)) throw new Error("El reparto de mensajería debe sumar la tarifa oficial antes de confirmar."); const response = await fetch(window.cvdVoucherIntake.ordersEndpoint, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-WP-Nonce": window.cvdVoucherIntake.nonce, "Idempotency-Key": confirmationKey }, body: JSON.stringify({ draft, lines }) }); const body = await response.json(); if (!response.ok) throw new Error(body?.message || "No se pudo crear el pedido."); ui.result.querySelector("pre").textContent = `Pedido #${body.orderNumber}\nEstado: ${body.status}\nMensajería: ${body.shippingFeeCup || "por confirmar"} CUP\nTarifa: ${body.shippingStatus}`; ui.result.hidden = false; ui.status.textContent = `Pedido Casa Viva #${body.orderNumber} creado y auditado.`; ui.result.scrollIntoView({ behavior: "smooth" }); } catch (error) { ui.status.textContent = error instanceof Error ? error.message : "No se pudo confirmar el pedido."; } finally { ui.confirm.disabled = false; } });
})();
