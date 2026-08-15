(function () {
  "use strict";

  if (!window.cvdMirrorStore || !window.cvdMirrorStore.referralCode) return;

  var code = String(window.cvdMirrorStore.referralCode);
  var origin = new URL(window.cvdMirrorStore.origin, window.location.href).origin;
  var excluded = ["/wp-admin/", "/wp-login.php", "/wp-json/", "/area-gestoras/", "/mi-cuenta/"];

  document.querySelectorAll("a[href]").forEach(function (anchor) {
    var url;
    try {
      url = new URL(anchor.href, window.location.href);
    } catch (error) {
      return;
    }
    if (url.origin !== origin || !/^https?:$/.test(url.protocol)) return;
    if (excluded.some(function (path) { return url.pathname.indexOf(path) === 0; })) return;
    if (url.searchParams.has("wc-ajax") || url.searchParams.has("action")) return;
    url.searchParams.set("ref", code);
    anchor.href = url.toString();
  });
})();
