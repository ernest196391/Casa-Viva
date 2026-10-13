/* 3.13.84 — Ficha de producto en el teléfono (auditoría de cliente, 13-oct). Solo presentación: no toca precios, stock ni pedidos. */
(function ($) {
  "use strict";
  var form = document.querySelector("form.variations_form");
  if (!form) return;
  var select = form.querySelector("table.variations select");

  // 1) Colores y tallas con nombre legible: «azul-claro» → «Azul claro» (solo el texto; el valor no cambia).
  function prettify() {
    form.querySelectorAll("table.variations select option").forEach(function (option) {
      var text = option.textContent.trim();
      if (!option.value || text !== option.value || !/^[a-záéíóúñü][a-z0-9áéíóúñü]*(-[a-z0-9áéíóúñü]+)*$/.test(text)) return;
      var nice = text.replace(/-/g, " ");
      option.textContent = nice.charAt(0).toUpperCase() + nice.slice(1);
    });
  }
  prettify();
  if ($) $(form).on("woocommerce_update_variation_values", prettify);

  // 2) Saber si ya eligió color/talla.
  function chosen() {
    var id = form.querySelector("input[name=variation_id]");
    return !!(id && Number(id.value) > 0);
  }
  function hint() {
    form.scrollIntoView({ behavior: "smooth", block: "center" });
    form.classList.add("cvd-pick-hint");
    window.setTimeout(function () { form.classList.remove("cvd-pick-hint"); }, 1800);
    if (select) window.setTimeout(function () { select.focus({ preventScroll: true }); }, 450);
  }

  // 3) «Comprar ahora» sin color elegido recargaba la página con un error rojo: ahora lleva a elegir.
  form.addEventListener("click", function (event) {
    if (!event.target.closest(".cvm-buy-now") || chosen()) return;
    event.preventDefault();
    hint();
  }, true);

  // 4) Barra fija de compra: dice «Elegir color» hasta que se elige; tocarla sin elegir lleva al selector.
  var bar = document.querySelector("[data-cvd-buybar]");
  var go = bar && bar.querySelector("[data-cvd-buybar-go]");
  if (bar && go) {
    var what = select ? select.name.replace(/^attribute_(pa_)?/, "").replace(/[-_]+/g, " ").toLowerCase() : "";
    var word = /^(color|talla|tamaño|tamano|medida|modelo)$/.test(what) ? what.replace("tamano", "tamaño") : "opción";
    var ready = document.querySelector(".cvm-buy-now") ? "Comprar ahora" : "Añadir al carrito";
    var label = function () { go.textContent = chosen() ? ready : "Elegir " + word; };
    // En captura sobre la barra: llega antes que el botón de «Comprar ahora» del plugin de marca.
    bar.addEventListener("click", function (event) {
      if (!event.target.closest("[data-cvd-buybar-go]") || chosen()) return;
      event.preventDefault();
      event.stopPropagation();
      hint();
    }, true);
    if ($) $(form).on("found_variation reset_data hide_variation show_variation", function () { window.setTimeout(label, 0); });
    label();
    window.addEventListener("load", label);
  }

  // 5) Si solo queda una opción (las agotadas ya no salen en la lista), se elige sola.
  if ($) {
    $(function () {
      window.setTimeout(function () {
        form.querySelectorAll("table.variations select").forEach(function (one) {
          if (one.value) return;
          var real = Array.prototype.filter.call(one.options, function (option) { return option.value && !option.disabled; });
          if (real.length === 1) { one.value = real[0].value; $(one).trigger("change"); }
        });
      }, 0);
    });
  }
})(window.jQuery);
