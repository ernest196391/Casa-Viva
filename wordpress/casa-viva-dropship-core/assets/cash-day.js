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
      dialog.innerHTML = '<form method="dialog"><h2>¿Lo recibiste hoy?</h2><p></p>' +
        '<p>El dinero cuenta en el cuadre del día en que entró a la tienda.</p>' +
        '<label>Si fue otro día, ¿cuál? <input type="date" name="day"></label>' +
        '<menu><button value="yes" type="submit">Sí, entró hoy</button><button value="no" type="submit">No, entró ese día</button><button value="cancel" type="submit" formnovalidate>Cancelar</button></menu></form>';
      dialog.querySelector("p").textContent = why;
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
