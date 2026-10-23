(function () {
  "use strict";

  if (!window.cvdMirrorStore || !window.cvdMirrorStore.referralCode) return;

  var code = String(window.cvdMirrorStore.referralCode);
  var origin = new URL(window.cvdMirrorStore.origin, window.location.href).origin;
  var excluded = ["/wp-admin/", "/wp-login.php", "/wp-json/", "/area-gestoras/", "/mi-cuenta/"];

  function withRef(href) {
    var url;
    try {
      url = new URL(href, window.location.href);
    } catch (error) {
      return null;
    }
    if (url.origin !== origin || !/^https?:$/.test(url.protocol)) return null;
    if (excluded.some(function (path) { return url.pathname.indexOf(path) === 0; })) return null;
    if (url.searchParams.has("wc-ajax") || url.searchParams.has("action")) return null;
    // 3.14.56: «Ver la tienda de Casa Viva» (?ref=salir) no se cambia por el código de la gestora.
    if (/^(salir|casaviva)$/i.test(url.searchParams.get("ref") || url.searchParams.get("cv_ref") || "")) return null;
    url.searchParams.set("ref", code);
    return url.toString();
  }

  function tagLinks(root) {
    (root || document).querySelectorAll("a[href]").forEach(function (anchor) {
      var next = withRef(anchor.href);
      if (next && next !== anchor.href) anchor.href = next;
    });
  }

  tagLinks();

  // 3.13.79 (Thaly, 13-oct): la barra de abajo («Inicio», «Comprar», «Carrito») y otros enlaces se pintan
  // después de este script, y el buscador de la cabecera no llevaba el ?ref=. Al tocarlos se perdía la tienda
  // de la gestora y salía el precio de Casa Viva (30 en vez de 35). Ahora se añade al hacer clic y al buscar.
  document.addEventListener("click", function (event) {
    var anchor = event.target && event.target.closest ? event.target.closest("a[href]") : null;
    if (!anchor) return;
    var next = withRef(anchor.href);
    if (next && next !== anchor.href) anchor.href = next;
  }, true);

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!form || String(form.method || "get").toLowerCase() !== "get") return;
    if (!withRef(form.action || window.location.href)) return;
    if (form.querySelector('[name="ref"]')) return;
    var input = document.createElement("input");
    input.type = "hidden";
    input.name = "ref";
    input.value = code;
    form.appendChild(input);
  }, true);
})();
