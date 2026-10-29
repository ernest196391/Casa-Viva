/* 3.14.76 (Ernesto 28-oct): «¿Lo recibiste hoy?» antes de contar el dinero de una entrega de otro día
   (CVD_Cash_Day). La usan Ventas y el centro del pedido: cvdCashDay.ask(data) → Promise con
   { cashReceivedToday: "yes"|"no", cashReceivedDay: "AAAA-MM-DD" } o null si se cancela. */
(function () {
  "use strict";
  function label(day) {
    var p = String(day || "").split("-").map(Number);
    if (p.length !== 3 || !p[0]) return "";
    return new Date(Date.UTC(p[0], p[1] - 1, p[2])).toLocaleDateString("es", { weekday: "long", day: "numeric", month: "long", timeZone: "UTC" });
  }
  function yesterday(today) {
    var p = String(today || "").split("-").map(Number);
    return p.length === 3 && p[0] ? new Date(Date.UTC(p[0], p[1] - 1, p[2] - 1)).toISOString().slice(0, 10) : "";
  }
  function ask(data) {
    data = data || {};
    return new Promise(function (resolve) {
      var dialog = document.createElement("dialog");
      dialog.className = "cvd-cash-day";
      var reasons = data.reasons || [];
      var why = reasons.indexOf("tarde") >= 0 && data.delivered_day ? "Este pedido se entregó el " + label(data.delivered_day) + "." : "";
      if (reasons.indexOf("rafaga") >= 0) why += (why ? " " : "") + "Ya marcaste varios cobros de otros días seguidos.";
      var max = yesterday(data.today), def = data.delivered_day && data.delivered_day <= max ? data.delivered_day : max;
      // 3.14.76.1: con el estilo de Casa Viva y botones grandes uno debajo de otro (el móvil de la tienda)
      dialog.innerHTML = '<form method="dialog"><h2>¿Lo recibiste hoy?</h2><p class="cvd-cash-day-why"></p>' +
        '<p>El dinero cuenta en el cuadre del día en que entró a la tienda.</p>' +
        '<button class="cvd-cash-day-yes" value="yes" type="submit">Sí, entró hoy</button>' +
        '<div class="cvd-cash-day-other"><label>Si entró otro día, ¿qué día? <input type="date" name="day"></label>' +
        '<button class="cvd-cash-day-no" value="no" type="submit">No, entró ese día</button></div>' +
        '<button class="cvd-cash-day-cancel" value="cancel" type="submit" formnovalidate>Cancelar</button></form>';
      if (!document.getElementById("cvd-cash-day-css")) {
        var css = document.createElement("style"); css.id = "cvd-cash-day-css";
        css.textContent = ".cvd-cash-day,.cvd-cash-day *{box-sizing:border-box}.cvd-cash-day{border:0;border-radius:24px;box-shadow:0 24px 80px rgba(30,20,20,.3);max-width:440px;width:calc(100% - 28px);padding:24px;color:#2B2420;background:#F6F1E6;font:500 17px/1.4 Figtree,system-ui,sans-serif}" +
          ".cvd-cash-day::backdrop{background:rgba(30,20,20,.55)}.cvd-cash-day h2{margin:0 0 8px;font-size:26px;color:#0D3B45}.cvd-cash-day p{margin:0 0 12px}.cvd-cash-day-why{font-weight:600}.cvd-cash-day-why:empty{display:none}" +
          ".cvd-cash-day button{display:block;width:100%;min-height:52px;border-radius:14px;font:600 18px Figtree,system-ui,sans-serif;cursor:pointer}" +
          ".cvd-cash-day-yes{border:0;background:#0D3B45;color:#fff;margin:4px 0 16px}.cvd-cash-day-other{border-top:1px solid rgba(43,36,32,.15);padding-top:14px}" +
          ".cvd-cash-day label{display:block;margin-bottom:10px}.cvd-cash-day input{display:block;width:100%;box-sizing:border-box;margin-top:6px;min-height:48px;padding:8px 12px;border:1px solid rgba(43,36,32,.3);border-radius:12px;font:500 18px Figtree,system-ui,sans-serif;background:#fff}" +
          ".cvd-cash-day-no{border:2px solid #A35A1F;background:#fff;color:#A35A1F}.cvd-cash-day-cancel{border:0;background:none;color:#2B2420;text-decoration:underline;margin-top:8px;min-height:44px!important}";
        document.head.appendChild(css);
      }
      dialog.querySelector(".cvd-cash-day-why").textContent = why;
      var input = dialog.querySelector("input");
      input.value = def; if (max) input.max = max;
      document.body.appendChild(dialog);
      dialog.addEventListener("close", function () {
        var v = dialog.returnValue; dialog.remove();
        if (v === "yes") resolve({ cashReceivedToday: "yes", cashReceivedDay: data.today || "" });
        else if (v === "no") resolve({ cashReceivedToday: "no", cashReceivedDay: input.value || "" });
        else resolve(null);
      });
      if (dialog.showModal) dialog.showModal();
      else { dialog.remove(); resolve(window.confirm("¿Lo recibiste hoy?\n" + why + "\n\nAceptar = sí, hoy · Cancelar = no, otro día") ? { cashReceivedToday: "yes", cashReceivedDay: data.today || "" } : { cashReceivedToday: "no", cashReceivedDay: def }); }
    });
  }
  window.cvdCashDay = { ask: ask, code: "cvd_cash_day_question" };
}());
