(function () {
  "use strict";
  // «Cerrar mi turno» (Pilar 2, 29-oct-2026). La dependienta cuenta sus billetes en Ventas; el sistema dice «cuadra» o
  // «faltan/sobran» con la lista de cobros; el cierre queda firmado y guardado (no se cambia, solo se corrige con motivo).
  if (!window.cvdCierre) return;
  var C = window.cvdCierre;
  var tools = document.querySelector(".cvd-sales-tools");
  if (!tools) return;
  var S = { data: null, correcting: null, busy: false };
  var dlg = null;

  function esc(v) { var n = document.createElement("span"); n.textContent = String(v == null ? "" : v); return n.innerHTML; }
  function group(n) { return String(Math.abs(Math.round(n))).replace(/\B(?=(\d{3})+(?!\d))/g, "."); }
  function usd(c) { var neg = c < 0, a = Math.abs(c), whole = Math.floor(a / 100), cents = a % 100; return (neg ? "-" : "") + group(whole) + (cents ? "," + (cents < 10 ? "0" : "") + cents : ""); }
  function mn(p) { return (p < 0 ? "-" : "") + group(p); }
  function toCents(s) { var n = parseFloat(String(s == null ? "" : s).replace(",", ".")); return isFinite(n) && n > 0 ? Math.round(n * 100) : 0; }
  function toPesos(s) { var n = parseFloat(String(s == null ? "" : s).replace(",", ".")); return isFinite(n) && n > 0 ? Math.round(n) : 0; }
  function q(sel) { return dlg.querySelector(sel); }
  function qa(sel) { return Array.prototype.slice.call(dlg.querySelectorAll(sel)); }
  async function api(url, options) {
    var response = await fetch(url, Object.assign({ credentials: "same-origin", headers: { "X-WP-Nonce": C.nonce, "Content-Type": "application/json" } }, options || {}));
    var data = await response.json();
    if (!response.ok) throw new Error(data.message || "No se pudo completar la operación.");
    return data;
  }
  var METHODS = { cash_usd: "Efectivo USD", cash_cup: "Efectivo MN", transfer: "Transferencia", zelle: "Zelle", usdt: "USDT", mixed: "Mixto", other: "Otro", allocated: "Repartido" };
  var WHY = ["Vuelto", "Pago a gestora", "Pago a mensajero", "Proveedor", "Otro"];

  // ---------- botón en Ventas ----------
  var button = document.createElement("button");
  button.type = "button"; button.id = "cvd-cierre-open"; button.className = "cvd-cierre-open"; button.textContent = "Cerrar mi turno";
  tools.appendChild(button);
  button.addEventListener("click", open);

  function ensureDialog() {
    if (dlg) return;
    dlg = document.createElement("dialog"); dlg.id = "cvd-cierre-dialog";
    dlg.addEventListener("input", function (e) { if (e.target.closest("#cc-form")) recalc(); });
    dlg.addEventListener("click", onClick);
    document.querySelector(".cvd-sales-app").appendChild(dlg);
  }

  async function open() {
    ensureDialog();
    dlg.innerHTML = '<div class="cc-wrap"><p class="cc-loading">Cargando tu turno…</p></div>';
    if (!dlg.open) dlg.showModal();
    try {
      S.data = await api(C.url);
      S.correcting = null;
      render();
    } catch (e) {
      dlg.innerHTML = '<div class="cc-wrap"><p class="cc-error">' + esc(e.message) + '</p><button type="button" data-cc="close" class="cc-btn">Cerrar</button></div>';
    }
  }

  // ---------- pantalla ----------
  function rowsHtml(rows, empty) {
    if (!rows.length) return '<p class="cc-muted">' + esc(empty) + "</p>";
    return '<ul class="cc-rows">' + rows.map(function (r) {
      var parts = [];
      if (r.usd) parts.push("USD " + usd(r.usd));
      if (r.mn) parts.push(mn(r.mn) + " MN");
      if (r.trf) parts.push(mn(r.trf) + " MN transf.");
      if (r.otros) parts.push("USD " + usd(r.otros) + " (" + esc(METHODS[r.method] || "otro") + ")");
      return "<li><b>#" + esc(r.number) + "</b><span>" + esc(r.time) + " · " + esc(METHODS[r.method] || r.method) + (r.account ? " · " + esc(r.account) : "") + (r.mixed ? " · mixto" : "") + "</span><em>" + parts.join(" + ") + "</em></li>";
    }).join("") + "</ul>";
  }

  function billsGrid(kind, denoms, values) {
    return '<div class="cc-bills" data-kind="' + kind + '">' + denoms.map(function (d) {
      var v = values && values[d] ? values[d] : "";
      return '<label><span>' + group(d) + '</span><input type="number" inputmode="numeric" min="0" step="1" data-bill-' + kind + '="' + d + '" value="' + esc(v) + '" placeholder="0"></label>';
    }).join("") + "</div>";
  }

  function outRow(o) {
    o = o || {};
    return '<div class="cc-out"><select>' + WHY.map(function (w) { return "<option" + (o.why === w ? " selected" : "") + ">" + w + "</option>"; }).join("") + '</select>' +
      '<input class="cc-out-usd" type="text" inputmode="decimal" placeholder="USD" value="' + esc(o.usd ? usd(o.usd) : "") + '">' +
      '<input class="cc-out-mn" type="text" inputmode="numeric" placeholder="MN" value="' + esc(o.mn ? o.mn : "") + '">' +
      '<button type="button" data-cc="del-out" aria-label="Quitar">✕</button></div>';
  }

  function render() {
    var d = S.data;
    var closing = d.closing;
    var head = '<header class="cc-head"><div><h2>Cerrar mi turno</h2><p>' + esc(d.user.name) + " · " + esc(d.dayLabel) + '</p></div><button type="button" data-cc="close" aria-label="Cerrar">✕</button></header>';
    if (!d.user.canSign) {
      dlg.innerHTML = '<div class="cc-wrap">' + head + '<p class="cc-note">Este cierre lo firma la dependienta que recibió el dinero (Nana o Zaymi). Aquí solo puedes mirarlo.</p>' + historyHolder() + "</div>";
      loadHistory();
      return;
    }
    if (closing && !S.correcting) { dlg.innerHTML = '<div class="cc-wrap">' + head + summaryHtml(closing) + newAfterHtml(d) + historyHolder() + "</div>"; loadHistory(); return; }
    var prev = S.correcting ? S.correcting.detail || {} : {};
    var prevFund = S.correcting ? S.correcting.fund : (d.suggestedFund || { usd: 0, mn: 0 });
    var prevKeep = S.correcting ? S.correcting.keep : { usd: 0, mn: 0 };
    var prevOuts = (prev.outs || []).map(function (o) { return o; });
    var fundHint = !S.correcting && d.suggestedFund ? '<p class="cc-muted">Lo dejó ' + esc(d.suggestedFund.from) + " en su cierre del " + esc(d.suggestedFund.day.split("-").reverse().join("/")) + ". Si hoy empezaste con otra cantidad, cámbiala y explica por qué abajo en la nota.</p>" : '<p class="cc-muted">Escribe con cuánto dinero empezaste hoy en la caja.</p>';
    var others = (d.othersCajon || []).length ? '<p class="cc-muted">Cobros que apuntó otra persona hoy (no cuentan en tu caja): ' + d.othersCajon.map(function (o) { return esc(o.name) + " " + o.n + " pedido" + (o.n === 1 ? "" : "s"); }).join(", ") + ".</p>" : "";
    var html = '<div class="cc-wrap">' + head +
      (S.correcting ? '<p class="cc-note cc-warn">Estás corrigiendo tu cierre. El anterior no se borra: queda guardado y este lo reemplaza. Escribe el motivo en la nota.</p>' : "") +
      '<form id="cc-form" autocomplete="off">' +
      '<section><h3><i>1</i> Con cuánto empecé</h3>' + fundHint +
      '<div class="cc-pair"><label>USD<input id="cc-fund-usd" type="text" inputmode="decimal" value="' + esc(prevFund.usd ? usd(prevFund.usd) : "") + '" placeholder="0"></label><label>MN<input id="cc-fund-mn" type="text" inputmode="numeric" value="' + esc(prevFund.mn ? prevFund.mn : "") + '" placeholder="0"></label></div></section>' +
      '<section><h3><i>2</i> Lo que cobré</h3><p class="cc-muted">Sale de lo que apuntaste en «Registrar dinero recibido». Si falta alguno, apúntalo en el pedido y vuelve a abrir esto.</p>' +
      rowsHtml(d.cash, "Todavía no has apuntado ningún cobro hoy.") +
      '<div class="cc-total"><span>Total cobrado</span><b>USD ' + usd(d.cashTotals.usd) + " · " + mn(d.cashTotals.mn) + " MN</b></div>" + others + "</section>" +
      '<section><h3><i>3</i> Salidas de la caja</h3><p class="cc-muted">Lo que sacaste del dinero: vueltos, pagos a gestoras, mensajeros o proveedores.</p><div id="cc-outs">' + prevOuts.map(outRow).join("") + '</div><button type="button" class="cc-add" data-cc="add-out">+ Añadir una salida</button></section>' +
      '<section class="cc-expected"><h3><i>4</i> Debería haber</h3><div id="cc-expected"></div></section>' +
      '<section><h3><i>5</i> Cuenta tus billetes</h3><h4>Dólares (USD)</h4>' + billsGrid("usd", C.usdBills, prev.bills && prev.bills.usd) + '<div class="cc-total"><span>Contado USD</span><b id="cc-count-usd">0</b></div>' +
      '<h4>Pesos (MN)</h4>' + billsGrid("mn", C.mnBills, prev.bills && prev.bills.mn) + '<div class="cc-total"><span>Contado MN</span><b id="cc-count-mn">0</b></div></section>' +
      '<section id="cc-result" class="cc-result" aria-live="polite"></section>' +
      '<section><h3><i>6</i> Lo que dejo y lo que entrego</h3><p class="cc-muted">Del dinero contado, ¿cuánto se queda en la caja como fondo para mañana?</p>' +
      '<div class="cc-pair"><label>Fondo USD<input id="cc-keep-usd" type="text" inputmode="decimal" value="' + esc(prevKeep.usd ? usd(prevKeep.usd) : "") + '" placeholder="0"></label><label>Fondo MN<input id="cc-keep-mn" type="text" inputmode="numeric" value="' + esc(prevKeep.mn ? prevKeep.mn : "") + '" placeholder="0"></label></div>' +
      '<div class="cc-total"><span>Entrego a Lennys</span><b id="cc-deliver">—</b></div></section>' +
      transfersHtml(d) +
      '<section><h3><i>8</i> Nota</h3><textarea id="cc-note" rows="3" maxlength="500" placeholder="Solo si algo no cuadra o cambió el fondo. Ej.: falta el vuelto del #5604">' + esc(S.correcting ? "" : "") + "</textarea></section>" +
      '<p id="cc-error" class="cc-error" role="alert" hidden></p>' +
      '<div class="cc-actions"><button type="button" class="cc-btn cc-ghost" data-cc="close">Volver</button><button type="submit" class="cc-btn cc-primary" id="cc-sign">Cerrar y firmar</button></div>' +
      "</form>" + historyHolder() + "</div>";
    dlg.innerHTML = html;
    dlg.querySelector("#cc-form").addEventListener("submit", sign);
    recalc();
    loadHistory();
  }

  function transfersHtml(d) {
    var cards = d.byCard || [];
    var inner = cards.length ? '<ul class="cc-rows">' + cards.map(function (c) {
      return "<li><b>" + esc(c.name) + "</b><span>" + c.n + " pedido" + (c.n === 1 ? "" : "s") + " (#" + esc(c.orders.join(", #")) + ")</span><em>" + (c.cup ? mn(c.cup) + " MN" : "") + (c.cup && c.usd ? " + " : "") + (c.usd ? "USD " + usd(c.usd) : "") + "</em></li>";
    }).join("") + '</ul><p class="cc-muted">Esto no va en la caja de billetes. Mira en tu banco que cada transferencia entró a su tarjeta.</p>' : '<p class="cc-muted">Hoy no hay transferencias.</p>';
    return '<section><h3><i>7</i> Transferencias (aparte)</h3>' + inner + "</section>";
  }

  function summaryHtml(c) {
    function line(label, v) { return "<li><span>" + label + "</span><b>USD " + usd(v.usd) + " · " + mn(v.mn) + " MN</b></li>"; }
    var ok = c.diff.usd === 0 && c.diff.mn === 0;
    var diffTxt = ok ? "Cuadró ✔" : ((c.diff.usd ? (c.diff.usd < 0 ? "Faltaron USD " : "Sobraron USD ") + usd(Math.abs(c.diff.usd)) : "") + (c.diff.usd && c.diff.mn ? " y " : "") + (c.diff.mn ? (c.diff.mn < 0 ? "faltaron " : "sobraron ") + mn(Math.abs(c.diff.mn)) + " MN" : ""));
    return '<div class="cc-signed ' + (ok ? "cc-ok" : "cc-bad") + '"><h3>Tu cierre está firmado</h3><p>' + esc(c.signedAtLocal) + " · " + esc(c.name) + (c.test ? " · PRUEBA" : "") + "</p><div class=\"cc-big\">" + esc(diffTxt) + "</div><ul class=\"cc-list\">" +
      line("Empezaste con", c.fund) + line("Cobraste", c.received) + line("Salidas", c.out) + line("Debía haber", c.expected) + line("Contaste", c.counted) + line("Dejaste de fondo", c.keep) +
      line("Entregas a Lennys", { usd: c.counted.usd - c.keep.usd, mn: c.counted.mn - c.keep.mn }) + "</ul>" + (c.note ? '<p class="cc-muted">Nota: ' + esc(c.note) + "</p>" : "") +
      '<div class="cc-actions"><button type="button" class="cc-btn cc-ghost" data-cc="close">Listo</button><button type="button" class="cc-btn cc-ghost" data-cc="correct">Corregir mi cierre</button></div></div>';
  }

  function newAfterHtml(d) {
    if (!d.newAfterClosing || !d.newAfterClosing.length) return "";
    return '<div class="cc-note cc-warn"><b>Ojo:</b> después de firmar apuntaste ' + d.newAfterClosing.length + " cobro(s) más. No están en tu cierre firmado; si cuentan para hoy, usa «Corregir mi cierre».</div>" + rowsHtml(d.newAfterClosing, "");
  }

  function historyHolder() { return '<section class="cc-history"><h3>Cierres anteriores</h3><div id="cc-history"><p class="cc-muted">Cargando…</p></div></section>'; }
  async function loadHistory() {
    var box = q("#cc-history");
    if (!box) return;
    try {
      var data = await api(C.historyUrl + "?days=14");
      var list = (data.closings || []).filter(function (c) { return !c.test; });
      box.innerHTML = list.length ? '<ul class="cc-rows">' + list.slice(0, 10).map(function (c) {
        var ok = c.diff.usd === 0 && c.diff.mn === 0;
        return "<li><b>" + esc(c.day.split("-").reverse().join("/")) + "</b><span>" + esc(c.name) + "</span><em class=\"" + (ok ? "cc-ok-t" : "cc-bad-t") + "\">" + (ok ? "cuadró" : "USD " + usd(c.diff.usd) + " · " + mn(c.diff.mn) + " MN") + "</em></li>";
      }).join("") + "</ul>" : '<p class="cc-muted">Todavía no hay cierres firmados.</p>';
    } catch (e) { box.innerHTML = '<p class="cc-muted">No se pudo cargar.</p>'; }
  }

  // ---------- cuentas en vivo ----------
  function read() {
    var bills = { usd: {}, mn: {} };
    qa("[data-bill-usd]").forEach(function (i) { var n = parseInt(i.value, 10) || 0; if (n > 0) bills.usd[i.getAttribute("data-bill-usd")] = n; });
    qa("[data-bill-mn]").forEach(function (i) { var n = parseInt(i.value, 10) || 0; if (n > 0) bills.mn[i.getAttribute("data-bill-mn")] = n; });
    var outs = qa(".cc-out").map(function (r) { return { why: r.querySelector("select").value, usd: toCents(r.querySelector(".cc-out-usd").value), mn: toPesos(r.querySelector(".cc-out-mn").value) }; }).filter(function (o) { return o.usd || o.mn; });
    return {
      fund: { usd: toCents(q("#cc-fund-usd").value), mn: toPesos(q("#cc-fund-mn").value) }, keep: { usd: toCents(q("#cc-keep-usd").value), mn: toPesos(q("#cc-keep-mn").value) },
      bills: bills, outs: outs, note: q("#cc-note").value.trim(),
    };
  }
  function countOf(bills, kind) { var t = 0; Object.keys(bills[kind]).forEach(function (k) { t += parseInt(k, 10) * bills[kind][k]; }); return t; }

  function calc() {
    var r = read(), d = S.data;
    var counted = { usd: countOf(r.bills, "usd") * 100, mn: countOf(r.bills, "mn") };
    var out = { usd: 0, mn: 0 };
    r.outs.forEach(function (o) { out.usd += o.usd; out.mn += o.mn; });
    var res = {};
    ["usd", "mn"].forEach(function (k) {
      var expected = r.fund[k] + d.cashTotals[k] - out[k];
      res[k] = { fund: r.fund[k], received: d.cashTotals[k], out: out[k], expected: expected, counted: counted[k], diff: counted[k] - expected };
    });
    return { r: r, counted: counted, res: res, ok: res.usd.diff === 0 && res.mn.diff === 0 };
  }

  function recalc() {
    if (!q("#cc-form")) return;
    var c = calc(), res = c.res;
    q("#cc-expected").innerHTML = '<ul class="cc-list">' +
      "<li><span>Empecé con</span><b>USD " + usd(res.usd.fund) + " · " + mn(res.mn.fund) + " MN</b></li>" +
      "<li><span>+ Cobré</span><b>USD " + usd(res.usd.received) + " · " + mn(res.mn.received) + " MN</b></li>" +
      "<li><span>− Salidas</span><b>USD " + usd(res.usd.out) + " · " + mn(res.mn.out) + " MN</b></li></ul>" +
      '<div class="cc-total cc-big2"><span>Debería haber</span><b>USD ' + usd(res.usd.expected) + " · " + mn(res.mn.expected) + " MN</b></div>";
    q("#cc-count-usd").textContent = "USD " + usd(c.counted.usd);
    q("#cc-count-mn").textContent = mn(c.counted.mn) + " MN";
    var any = c.counted.usd > 0 || c.counted.mn > 0;
    var box = q("#cc-result");
    if (!any) { box.className = "cc-result"; box.innerHTML = '<p class="cc-muted">Cuenta tus billetes arriba y aquí verás si cuadra.</p>'; }
    else if (c.ok) { box.className = "cc-result cc-ok"; box.innerHTML = '<div class="cc-big">CUADRA ✔</div><p>Lo que contaste es lo que debía haber.</p>'; }
    else {
      var parts = [];
      if (res.usd.diff) parts.push((res.usd.diff < 0 ? "FALTAN USD " : "SOBRAN USD ") + usd(Math.abs(res.usd.diff)));
      if (res.mn.diff) parts.push((res.mn.diff < 0 ? "FALTAN " : "SOBRAN ") + mn(Math.abs(res.mn.diff)) + " MN");
      box.className = "cc-result cc-bad";
      box.innerHTML = '<div class="cc-big">' + esc(parts.join(" · ")) + "</div><p>Antes de irte revisa:</p><ul class=\"cc-checks\"><li>¿Falta apuntar algún cobro en «Registrar dinero recibido»?</li><li>¿Diste un vuelto o hiciste una salida sin anotarla en el punto 3?</li><li>¿Empezaste con otro fondo?</li></ul>" + rowsHtml(S.data.cash, "") + "<p>Si no encuentras la causa, escribe qué crees que pasó en la nota y firma igual.</p>";
    }
    var k = c.r.keep;
    q("#cc-deliver").textContent = any ? "USD " + usd(c.counted.usd - k.usd) + " · " + mn(c.counted.mn - k.mn) + " MN" : "—";
  }

  // ---------- acciones ----------
  function onClick(e) {
    var t = e.target.closest("[data-cc]");
    if (!t) return;
    var a = t.getAttribute("data-cc");
    if (a === "close") { dlg.close(); }
    else if (a === "add-out") { q("#cc-outs").insertAdjacentHTML("beforeend", outRow()); }
    else if (a === "del-out") { t.closest(".cc-out").remove(); recalc(); }
    else if (a === "correct") { S.correcting = S.data.closing; render(); }
  }

  async function sign(e) {
    e.preventDefault();
    if (S.busy) return;
    var c = calc(), err = q("#cc-error");
    err.hidden = true;
    if (!c.counted.usd && !c.counted.mn) { err.textContent = "Cuenta tus billetes antes de firmar."; err.hidden = false; return; }
    if (!c.ok && !c.r.note) { err.textContent = "No cuadra: escribe en la nota qué crees que pasó y vuelve a firmar."; err.hidden = false; q("#cc-note").focus(); return; }
    if (!window.confirm("¿Firmar el cierre de " + S.data.user.name + "? Después no se puede cambiar (solo corregir con un motivo).")) return;
    S.busy = true; q("#cc-sign").disabled = true; q("#cc-sign").textContent = "Guardando…";
    try {
      var body = {
        day: S.data.day, corrects: S.correcting ? S.correcting.id : 0, note: c.r.note, bills: c.r.bills,
        fund: { usd: c.r.fund.usd / 100, mn: c.r.fund.mn }, keep: { usd: c.r.keep.usd / 100, mn: c.r.keep.mn },
        outs: c.r.outs.map(function (o) { return { why: o.why, usd: o.usd / 100, mn: o.mn }; }),
      };
      var done = await api(C.url, { method: "POST", body: JSON.stringify(body) });
      S.data.closing = done.closing; S.correcting = null;
      render();
      var wrap = dlg.querySelector(".cc-wrap"); if (wrap) wrap.scrollTop = 0; dlg.scrollTop = 0;
    } catch (ex) {
      err.textContent = ex.message; err.hidden = false;
      q("#cc-sign").disabled = false; q("#cc-sign").textContent = "Cerrar y firmar";
    } finally { S.busy = false; }
  }
})();
