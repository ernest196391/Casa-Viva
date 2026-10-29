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

  // 6) 3.14.74 (Lennys 28-oct: cortinas con alfombra en estampados parecidos): al elegir un diseño sale junto al botón
  //    «Elegiste: Verde» con SU foto (sin foto propia, la del producto) y la foto grande pasa a ese diseño.
  //    Tocar el recuadro sube a la foto grande. Solo presentación: no toca precio, stock ni pedido.
  if ($) {
    var gallery = document.querySelector("div.product .woocommerce-product-gallery") || document.querySelector("div.product .images");
    var mainImg = function () { return gallery && (gallery.querySelector(".woocommerce-product-gallery__image img") || gallery.querySelector("img")); };
    var file = function (url) { return String(url || "").split("?")[0].split("/").pop().replace(/(\.(webp|jpe?g|png|gif|avif))+$/i, "").replace(/-\d+x\d+$/, "").replace(/-scaled$/, ""); };
    var box = document.createElement("div");
    box.className = "cvd-chosen";
    box.hidden = true;
    box.setAttribute("role", "status");
    box.setAttribute("aria-live", "polite");
    box.innerHTML = '<img class="cvd-chosen__img" alt="" width="64" height="64" decoding="async"><span class="cvd-chosen__text"><small>Elegiste</small><b></b><em>Mira la foto antes de comprar</em></span>';
    var wrap = form.querySelector(".single_variation_wrap");
    if (wrap) wrap.insertBefore(box, wrap.firstChild); else form.appendChild(box);
    var chosenName = function () {
      var parts = [];
      form.querySelectorAll("table.variations select").forEach(function (one) {
        var option = one.options[one.selectedIndex];
        if (one.value && option) parts.push(option.textContent.trim());
      });
      return parts.join(" · ");
    };
    var saved = null;
    var restore = function () {
      var img = mainImg();
      if (!saved || !img) return;
      Object.keys(saved).forEach(function (attr) { if (saved[attr] === null) img.removeAttribute(attr); else img.setAttribute(attr, saved[attr]); });
      saved = null;
    };
    // WooCommerce ya cambia la foto si el tema usa su galería; esto es por si no lo hizo (galería propia, foto perezosa).
    var ensureMain = function (image) {
      var img = mainImg();
      if (!img || !image || !image.src || gallery.querySelector(".flex-control-nav")) return; // con miniaturas, Woo pasa a la del diseño
      var want = file(image.full_src || image.src), have = [img.currentSrc, img.getAttribute("src"), img.getAttribute("data-src"), img.getAttribute("data-large_image")].map(file);
      if (have.indexOf(want) > -1 || have.indexOf(file(image.src)) > -1) return;
      if (!saved) {
        saved = {};
        ["src", "srcset", "sizes", "data-src", "data-srcset", "data-large_image", "alt"].forEach(function (attr) { saved[attr] = img.getAttribute(attr); });
      }
      img.setAttribute("src", image.src);
      if (image.srcset) img.setAttribute("srcset", image.srcset); else img.removeAttribute("srcset");
      if (image.sizes) img.setAttribute("sizes", image.sizes);
      img.removeAttribute("data-src");
      img.removeAttribute("data-srcset");
      if (image.full_src) img.setAttribute("data-large_image", image.full_src);
      if (image.alt) img.setAttribute("alt", image.alt);
    };
    $(form).on("found_variation", function (event, variation) {
      var name = chosenName();
      var image = variation && variation.image ? variation.image : null;
      var thumb = image && (image.gallery_thumbnail_src || image.thumb_src || image.src);
      if (!name) { box.hidden = true; return; }
      box.querySelector("b").textContent = name;
      var pic = box.querySelector("img");
      if (thumb) { pic.src = thumb; pic.alt = name; pic.hidden = false; } else { pic.hidden = true; }
      box.hidden = false;
      window.setTimeout(function () { ensureMain(image); }, 150);
    });
    $(form).on("reset_data hide_variation", function () { box.hidden = true; restore(); });
    box.addEventListener("click", function () {
      if (gallery) gallery.scrollIntoView({ behavior: "smooth", block: "start" });
    });
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
