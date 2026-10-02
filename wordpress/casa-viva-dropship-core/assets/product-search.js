(() => {
  "use strict";
  const config = window.cvdProductSearch;
  if (!config || !config.url || !window.fetch) return;
  const SELECTOR = '[data-cvd-product-search] input[name="s"],form.woocommerce-product-search input[name="s"],form[role="search"] input[name="s"],form.search-form input[name="s"]';
  const cache = new Map();
  let uid = 0;

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }
  function allResultsUrl(query) {
    const url = new URL(config.home || "/", window.location.origin);
    url.searchParams.set("s", query);
    url.searchParams.set("post_type", "product");
    return url.toString();
  }

  function enhance(input) {
    if (input.dataset.cvdSuggest) return;
    input.dataset.cvdSuggest = "1";
    const id = `cvd-search-list-${++uid}`;
    const list = el("ul", "cvd-search-suggest");
    list.id = id;
    list.setAttribute("role", "listbox");
    list.setAttribute("aria-label", "Sugerencias");
    list.hidden = true;
    document.body.append(list);
    const status = el("span", "cvd-search-status");
    status.setAttribute("role", "status");
    input.after(status);
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-controls", id);
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("autocomplete", "off");
    let items = [];
    let active = -1;
    let timer = 0;
    let controller = null;

    function place() {
      const r = input.getBoundingClientRect();
      const width = Math.max(r.width, Math.min(360, window.innerWidth - 24));
      const left = Math.min(Math.max(12, r.left), window.innerWidth - width - 12);
      list.style.top = `${r.bottom + window.scrollY + 6}px`;
      list.style.left = `${left + window.scrollX}px`;
      list.style.width = `${width}px`;
    }
    function close() {
      list.hidden = true;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
      active = -1;
    }
    function setActive(index) {
      items.forEach((item, i) => item.setAttribute("aria-selected", i === index ? "true" : "false"));
      active = index;
      if (index >= 0) {
        input.setAttribute("aria-activedescendant", items[index].id);
        items[index].scrollIntoView({ block: "nearest" });
      } else input.removeAttribute("aria-activedescendant");
    }
    function option(href, children, className) {
      const li = el("li", className);
      li.id = `${id}-${list.children.length}`;
      li.setAttribute("role", "option");
      li.setAttribute("aria-selected", "false");
      li.dataset.href = href;
      li.append(...children);
      li.addEventListener("mousedown", (event) => event.preventDefault());
      li.addEventListener("click", () => { window.location.href = href; });
      list.append(li);
      return li;
    }
    function render(query, products) {
      list.replaceChildren();
      if (!products.length) {
        const empty = el("li", "cvd-search-empty", `Sin resultados para «${query}». Prueba otra palabra.`);
        empty.setAttribute("role", "presentation");
        list.append(empty);
        status.textContent = "Sin sugerencias";
      } else {
        products.forEach((product) => {
          const img = el("img");
          img.src = product.image;
          img.alt = "";
          img.width = 44;
          img.height = 44;
          img.loading = "lazy";
          const text = el("span", "cvd-search-text");
          text.append(el("b", "", product.name));
          const price = el("small", product.inStock ? "" : "is-out", product.inStock ? product.price : `${product.price} · Agotado`);
          text.append(price);
          option(product.url, [img, text], "cvd-search-item");
        });
        option(allResultsUrl(query), [el("span", "", `Ver todos los resultados de «${query}»`)], "cvd-search-all");
        status.textContent = `${products.length} sugerencias`;
      }
      items = [...list.querySelectorAll('[role="option"]')];
      active = -1;
      place();
      list.hidden = false;
      input.setAttribute("aria-expanded", "true");
    }
    async function lookup() {
      const query = input.value.trim();
      if (query.length < 2) { close(); return; }
      if (cache.has(query)) { render(query, cache.get(query)); return; }
      controller?.abort();
      controller = new AbortController();
      try {
        const url = new URL(config.url, window.location.origin);
        url.searchParams.set("q", query);
        url.searchParams.set("limit", "6");
        const response = await fetch(url, { signal: controller.signal, credentials: "same-origin" });
        if (!response.ok) throw new Error();
        const data = await response.json();
        const products = Array.isArray(data.products) ? data.products : [];
        cache.set(query, products);
        if (input.value.trim() === query) render(query, products);
      } catch (error) {
        if (error && error.name === "AbortError") return;
        close();
      }
    }

    input.addEventListener("input", () => { window.clearTimeout(timer); timer = window.setTimeout(lookup, 220); });
    input.addEventListener("focus", () => { if (input.value.trim().length >= 2) lookup(); });
    input.addEventListener("blur", () => window.setTimeout(close, 120));
    input.addEventListener("keydown", (event) => {
      if (list.hidden && event.key === "ArrowDown") { lookup(); return; }
      if (list.hidden) return;
      if (event.key === "ArrowDown") { event.preventDefault(); setActive(items.length ? (active + 1) % items.length : -1); }
      else if (event.key === "ArrowUp") { event.preventDefault(); setActive(items.length ? (active <= 0 ? items.length - 1 : active - 1) : -1); }
      else if (event.key === "Escape") { close(); }
      else if (event.key === "Enter" && active >= 0) { event.preventDefault(); window.location.href = items[active].dataset.href; }
    });
    window.addEventListener("resize", () => { if (!list.hidden) place(); }, { passive: true });
  }

  document.querySelectorAll(SELECTOR).forEach(enhance);
})();
