(() => {
  const root = document.documentElement;

  // Portada: si el tema no pintó la imagen dentro del contenido, se coloca arriba del contenido principal.
  const template = document.getElementById("cvd-hero-template");
  if (template && !document.querySelector(".cvd-hero")) {
    const target = document.querySelector("main .entry-content, main, #content, .site-content");
    if (target) target.prepend(template.content.cloneNode(true));
  }

  // Barra fija de compra: aparece cuando el botón original sale de la pantalla.
  const bar = document.querySelector("[data-cvd-buybar]");
  const form = document.querySelector("form.cart");
  const submit = form && form.querySelector('button[type="submit"]');
  if (bar && submit && "IntersectionObserver" in window) {
    // El margen inferior descuenta la barra de navegación: un botón tapado por ella cuenta como oculto.
    const navHeight = () => (document.querySelector(".cvd-customer-nav")?.getBoundingClientRect().height || 0) + 8;
    const observer = new IntersectionObserver(([entry]) => {
      const show = !entry.isIntersecting;
      bar.hidden = !show;
      root.classList.toggle("cvd-buybar-on", show);
    }, { rootMargin: `0px 0px -${Math.round(navHeight())}px 0px`, threshold: 1 });
    observer.observe(submit);
    bar.querySelector("[data-cvd-buybar-go]").addEventListener("click", (event) => {
      const variable = event.currentTarget.dataset.variable === "1";
      if (variable && submit.classList.contains("disabled")) {
        form.scrollIntoView({ behavior: "smooth", block: "center" });
        const select = form.querySelector("select");
        if (select) select.focus({ preventScroll: true });
        return;
      }
      submit.click();
    });
  }

  // Ofertas: una vez por sesión, al bajar por la página o tras unos segundos, nunca encima de una compra.
  const offers = document.querySelector("[data-cvd-offers]");
  if (!offers) return;
  const KEY = "cvdOffersSeen";
  let seen = false;
  try { seen = sessionStorage.getItem(KEY) === "1"; } catch { /* sin almacenamiento */ }
  if (seen) return;
  let lastFocus = null;
  let timer = 0;
  const close = () => {
    offers.hidden = true;
    root.classList.remove("cvd-offers-open");
    document.removeEventListener("keydown", onKey);
    if (lastFocus) lastFocus.focus({ preventScroll: true });
  };
  const onKey = (event) => {
    if (event.key === "Escape") close();
    if (event.key !== "Tab") return;
    const items = [...offers.querySelectorAll("a[href],button")];
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  };
  const show = () => {
    window.clearTimeout(timer);
    window.removeEventListener("scroll", onScroll);
    // No interrumpe si la clienta ya está hablando con Curru o tiene un formulario activo.
    if (root.classList.contains("cvd-curru-open") || document.activeElement?.matches("input,select,textarea")) return;
    try { sessionStorage.setItem(KEY, "1"); } catch { /* sin almacenamiento */ }
    lastFocus = document.activeElement;
    offers.hidden = false;
    root.classList.add("cvd-offers-open");
    document.addEventListener("keydown", onKey);
    offers.querySelector(".cvd-offers__close").focus({ preventScroll: true });
  };
  const onScroll = () => {
    const depth = (window.scrollY + window.innerHeight) / document.documentElement.scrollHeight;
    if (depth > 0.55) show();
  };
  offers.querySelectorAll("[data-cvd-offers-close]").forEach((el) => el.addEventListener("click", close));
  window.addEventListener("scroll", onScroll, { passive: true });
  timer = window.setTimeout(show, 15000);
})();
