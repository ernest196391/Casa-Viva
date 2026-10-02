(() => {
  "use strict";
  const launcher = document.querySelector(".cvd-assistant-launcher");
  const panel = document.querySelector(".cvd-contextual-assistant");
  if (!launcher || !panel) return;
  const config = window.cvdContextualAssistant || {
    context: panel.dataset.context || "visitante",
    name: "Curru",
    askUrl: "/wp-json/casa-viva/v1/curru/ask",
    addUrl: "/?wc-ajax=add_to_cart",
    cartUrl: "/carrito/",
    whatsapp: ""
  };
  const backdrop = document.querySelector("[data-cvd-curru-backdrop]");
  const nudge = document.querySelector("[data-cvd-curru-nudge]");
  const messages = panel.querySelector("[data-cvd-curru-messages]");
  const quick = panel.querySelector("[data-cvd-curru-quick]");
  const form = panel.querySelector("[data-cvd-curru-form]");
  const input = panel.querySelector("#cvd-contextual-question");
  const mic = panel.querySelector("[data-cvd-curru-mic]");
  const voiceStatus = panel.querySelector("[data-cvd-curru-voice]");
  let busy = false;
  // Últimos turnos para que la IA entienda el hilo; solo texto de esta conversación.
  const history = [];

  // Si la foto falla, queda la inicial.
  document.querySelectorAll(".cvd-curru-photo").forEach((img) => img.addEventListener("error", () => img.closest(".has-photo")?.classList.remove("has-photo")));

  function open() {
    const messengerAssistant = document.querySelector("#asistente[data-cvd-assistant]");
    if (messengerAssistant) {
      document.querySelector('.cvd-messenger-launchpad a[href="#asistente"]')?.click();
      return;
    }
    if (nudge) nudge.hidden = true;
    panel.hidden = false;
    if (backdrop) backdrop.hidden = false;
    document.documentElement.classList.add("cvd-curru-open");
    launcher.setAttribute("aria-expanded", "true");
    window.setTimeout(() => input.focus(), 0);
  }
  function close() {
    stopVoice();
    panel.hidden = true;
    if (backdrop) backdrop.hidden = true;
    document.documentElement.classList.remove("cvd-curru-open");
    launcher.setAttribute("aria-expanded", "false");
    launcher.focus();
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }
  function scrollDown() { messages.scrollTop = messages.scrollHeight; }
  function bubble(text, who) {
    const node = el("p", "cvd-curru-bubble" + (who === "user" ? " is-user" : ""), text);
    messages.append(node);
    scrollDown();
    return node;
  }

  async function addToCart(product, button) {
    button.disabled = true;
    button.textContent = "Añadiendo…";
    try {
      const body = new URLSearchParams({ product_id: String(product.id), quantity: "1" });
      const response = await fetch(config.addUrl, { method: "POST", body, credentials: "same-origin" });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.error) throw new Error();
      if (window.jQuery && data.fragments) window.jQuery(document.body).trigger("added_to_cart", [data.fragments, data.cart_hash, window.jQuery(button)]);
      button.textContent = "En el carrito ✓";
      const go = el("a", "cvd-curru-link", "Ver carrito");
      go.href = config.cartUrl;
      button.after(go);
    } catch {
      button.disabled = false;
      button.textContent = "Añadir";
      bubble("No pude añadirlo. Ábrelo y añádelo desde su página.", "assistant");
    }
  }

  function productCard(product) {
    const card = el("article", "cvd-curru-product");
    const link = el("a");
    link.href = product.url;
    const img = el("img");
    img.src = product.image;
    img.alt = "";
    img.loading = "lazy";
    link.append(img, el("b", "", product.name));
    const price = el("span", "cvd-curru-price");
    if (product.regular) price.append(el("s", "", product.regular), " ");
    price.append(el("strong", "", product.price));
    card.append(link, price);
    if (product.quickAdd) {
      const add = el("button", "cvd-curru-add", "Añadir");
      add.type = "button";
      add.addEventListener("click", () => addToCart(product, add));
      card.append(add);
    } else {
      const view = el("a", "cvd-curru-add is-secondary", product.inStock ? "Ver opciones" : "Agotado");
      view.href = product.url;
      card.append(view);
    }
    return card;
  }

  function render(data) {
    const wrap = el("div", "cvd-curru-reply");
    wrap.append(el("p", "cvd-curru-bubble", data.answer || "No tengo respuesta para eso."));
    if (Array.isArray(data.products) && data.products.length) {
      const grid = el("div", "cvd-curru-products");
      data.products.forEach((product) => grid.append(productCard(product)));
      wrap.append(grid);
    }
    if (Array.isArray(data.links) && data.links.length) {
      const links = el("div", "cvd-curru-links");
      data.links.forEach((item) => {
        const a = el("a", "cvd-curru-link", item.label);
        a.href = item.url;
        if (/^https:\/\/wa\.me\//.test(item.url)) { a.target = "_blank"; a.rel = "noopener"; }
        links.append(a);
      });
      wrap.append(links);
    }
    messages.append(wrap);
    scrollDown();
  }

  async function ask(question) {
    const text = question.trim();
    if (!text || busy) return;
    busy = true;
    if (quick) quick.hidden = true;
    bubble(text, "user");
    input.value = "";
    const typing = bubble(`${config.name} está buscando…`, "assistant");
    typing.classList.add("is-typing");
    try {
      const response = await fetch(config.askUrl, {
        method: "POST",
        headers: Object.assign({ "Content-Type": "application/json" }, config.nonce ? { "X-WP-Nonce": config.nonce } : {}),
        body: JSON.stringify({ question: text, history: history.slice(-6) }),
        credentials: "same-origin"
      });
      const data = await response.json().catch(() => ({}));
      typing.remove();
      if (!response.ok) throw new Error(data.message || "");
      render(data);
      history.push({ role: "user", text }, { role: "assistant", text: String(data.answer || "").slice(0, 400) });
    } catch (error) {
      typing.remove();
      render({ answer: (error && error.message) || "No pude responder ahora. Inténtalo otra vez o escríbenos por WhatsApp.", links: config.whatsapp ? [{ label: "Escribir por WhatsApp", url: config.whatsapp }] : [] });
    } finally {
      busy = false;
    }
  }

  // Dictado por voz (Web Speech). Solo rellena el campo; enviar sigue siendo decisión del cliente.
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  let recognition = null;
  function cleanSpeech(value) {
    const words = String(value || "").replace(/\s+/g, " ").trim().split(" ").filter(Boolean);
    for (let changed = true; changed;) {
      changed = false;
      for (let size = Math.min(8, Math.floor(words.length / 2)); size >= 1 && !changed; size--) {
        for (let i = 0; i + size * 2 <= words.length; i++) {
          const a = words.slice(i, i + size).join(" ").toLocaleLowerCase("es");
          const b = words.slice(i + size, i + size * 2).join(" ").toLocaleLowerCase("es");
          if (a === b) { words.splice(i + size, size); changed = true; break; }
        }
      }
    }
    return words.join(" ");
  }
  function stopVoice() {
    if (!recognition) return;
    recognition.onend = null;
    try { recognition.stop(); } catch { /* ya detenido */ }
    recognition = null;
    mic.setAttribute("aria-pressed", "false");
    voiceStatus.textContent = input.value ? "Dictado listo. Revisa el texto y toca enviar." : "";
  }
  if (Recognition && mic) {
    mic.hidden = false;
    mic.addEventListener("click", () => {
      if (recognition) { stopVoice(); return; }
      const base = input.value;
      recognition = new Recognition();
      recognition.lang = "es-ES";
      recognition.interimResults = true;
      recognition.continuous = false;
      recognition.onresult = (event) => {
        let heard = "";
        for (let i = 0; i < event.results.length; i++) heard += " " + (event.results[i][0]?.transcript || "");
        input.value = cleanSpeech(base + " " + heard);
      };
      recognition.onerror = (event) => {
        if (event.error === "not-allowed" || event.error === "service-not-allowed") voiceStatus.textContent = "Activa el permiso del micrófono o escribe tu mensaje.";
      };
      recognition.onend = () => stopVoice();
      try {
        recognition.start();
        mic.setAttribute("aria-pressed", "true");
        voiceStatus.textContent = "Escuchando…";
      } catch {
        recognition = null;
        voiceStatus.textContent = "El micrófono no está disponible.";
      }
    });
  }

  launcher.addEventListener("click", open);
  document.querySelectorAll("[data-cvd-curru-open]").forEach((button) => button.addEventListener("click", open));
  backdrop?.addEventListener("click", close);
  panel.querySelector("[data-cvd-assistant-close]").addEventListener("click", close);
  panel.querySelectorAll("[data-question]").forEach((button) => button.addEventListener("click", () => ask(button.dataset.question)));
  form.addEventListener("submit", (event) => { event.preventDefault(); stopVoice(); ask(input.value); });
  panel.addEventListener("keydown", (event) => {
    if (event.key === "Escape") { close(); return; }
    if (event.key !== "Tab") return;
    const items = [...panel.querySelectorAll("button:not(:disabled):not([hidden]),a[href],input:not(:disabled)")].filter((item) => item.getClientRects().length);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  // Saludo breve una vez por sesión, solo para clientes y visitantes.
  if (nudge && ["visitante", "cliente"].includes(config.context)) {
    let seen = false;
    try { seen = sessionStorage.getItem("cvdCurruNudge") === "1"; } catch { /* almacenamiento no disponible */ }
    if (!seen) {
      const hide = () => { nudge.hidden = true; };
      window.setTimeout(() => { if (panel.hidden) nudge.hidden = false; try { sessionStorage.setItem("cvdCurruNudge", "1"); } catch { /* sin almacenamiento */ } }, 1200);
      window.setTimeout(hide, 9000);
      window.addEventListener("scroll", hide, { passive: true, once: true });
    }
  }
})();
