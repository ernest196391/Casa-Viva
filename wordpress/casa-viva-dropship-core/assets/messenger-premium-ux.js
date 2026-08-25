(() => {
  'use strict';

  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const cleanText = (node) => (node?.textContent || '').replace(/\s+/g, ' ').trim();
  const make = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };

  function setExpanded(button, expanded, openLabel = 'Ocultar detalle', closedLabel = 'Ver detalle') {
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    button.textContent = expanded ? openLabel : closedLabel;
  }

  function installScreens(center) {
    const selectors = {
      hoy: ['.cvd-messenger-today'],
      ruta: ['.cvd-messenger-route', '#entregas'],
      dinero: ['#ganancias', '.cvd-messenger-earnings', '#cierre', '.cvd-messenger-closeout', '#liquidaciones'],
      mas: ['.cvd-messenger-contacts', '.cvd-messenger-preparation', '#asistente', '#ofertas', '#perfil'],
    };

    const allSections = new Set();
    Object.entries(selectors).forEach(([screen, entries]) => {
      entries.forEach((selector) => {
        qsa(selector, center).forEach((section) => {
          section.dataset.cvdScreen = screen;
          allSections.add(section);
        });
      });
    });

    const nav = qs('.cvd-messenger-nav', center);
    if (!nav || !allSections.size) return;

    const assistant = qs('#asistente', center);
    const links = qsa('a', nav);
    const destination = new Map([
      ['#hoy', 'hoy'],
      ['#ruta', 'ruta'],
      ['#ganancias', 'dinero'],
      ['#liquidaciones', 'dinero'],
      ['#perfil', 'mas'],
      ['#contactos', 'mas'],
      ['#preparar', 'mas'],
      ['#asistente', 'mas'],
    ]);

    const activate = (screen, focus = false) => {
      center.dataset.cvdView = screen;
      if (screen !== 'mas' && assistant) assistant.classList.remove('is-open');
      allSections.forEach((section) => {
        const isAssistant = section.id === 'asistente';
        const belongsToScreen = section.dataset.cvdScreen === screen;
        section.hidden = !belongsToScreen || (isAssistant && !section.classList.contains('is-open'));
      });
      links.forEach((link) => {
        const active = destination.get(link.getAttribute('href')) === screen;
        link.classList.toggle('is-active', active);
        if (active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
      if (focus) {
        const first = qsa(`[data-cvd-screen="${screen}"]`, center).find((section) => !section.hidden);
        first?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      try { sessionStorage.setItem('cvdMessengerView', screen); } catch (_) {}
    };

    links.forEach((link) => {
      const screen = destination.get(link.getAttribute('href'));
      if (!screen) return;
      link.addEventListener('click', (event) => {
        event.preventDefault();
        activate(screen, true);
      });
    });

    qsa('a[href^="#"]', center).forEach((link) => {
      if (nav.contains(link) || link.getAttribute('href') === '#asistente') return;
      const screen = destination.get(link.getAttribute('href'));
      if (!screen) return;
      link.addEventListener('click', (event) => {
        event.preventDefault();
        activate(screen, true);
      });
    });

    qsa('.cvd-messenger-launchpad a[href="#asistente"]', center).forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        if (assistant) {
          assistant.classList.add('is-open');
          assistant.classList.remove('is-collapsed');
        }
        activate('mas', false);
        if (assistant) {
          assistant.hidden = false;
          assistant.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }, { capture: true });
    });

    let initial = location.hash === '#ruta' ? 'ruta' : location.hash === '#ganancias' || location.hash === '#liquidaciones' ? 'dinero' : location.hash === '#perfil' || location.hash === '#contactos' || location.hash === '#preparar' || location.hash === '#asistente' ? 'mas' : 'hoy';
    if (location.hash === '#asistente' && assistant) assistant.classList.add('is-open');
    try {
      const stored = sessionStorage.getItem('cvdMessengerView');
      if (['hoy', 'ruta', 'dinero', 'mas'].includes(stored) && !location.hash) initial = stored;
    } catch (_) {}
    activate(initial, false);
  }

  function buildTodaySnapshot(center) {
    const today = qs('.cvd-messenger-today', center);
    if (!today || qs('.cvd-today-brief', today)) return;

    const stats = qsa('.cvd-messenger-today-stats article', today);
    const getValue = (needle) => {
      const card = stats.find((entry) => cleanText(qs('span', entry)).toLowerCase().includes(needle));
      return card ? cleanText(qs('strong', card)) : '0';
    };
    const orders = getValue('pedido');
    const pending = getValue('pendiente');
    const delivered = getValue('entregado');
    const alerts = qsa('.cvd-messenger-alerts p', today).map(cleanText).filter(Boolean);

    const brief = make('div', 'cvd-today-brief');
    brief.setAttribute('aria-label', 'Estado de jornada');
    brief.append(make('strong', '', `${orders} entregas hoy`), make('span', '', `${pending} por confirmar · ${delivered} entregadas`));

    const head = qs('.cvd-messenger-section-head', today);
    if (head) head.insertAdjacentElement('afterend', brief);

    if (alerts.length) {
      const attention = make('div', 'cvd-today-attention');
      attention.append(make('span', '', 'Requiere atención'), make('strong', '', alerts[0]));
      const statsGrid = qs('.cvd-messenger-today-stats', today);
      statsGrid?.insertAdjacentElement('afterend', attention);
    }

    const firstStop = qs('[data-route-stop]', center);
    if (firstStop) {
      const order = cleanText(qs('h3, h4, strong', firstStop));
      const address = cleanText(qs('.cvd-route-address', firstStop));
      const amount = cleanText(qs('.cvd-customer-collectible strong', firstStop));
      const navigate = qsa('a', firstStop).find((a) => /navegar|abrir mapa/i.test(cleanText(a)));
      const next = make('div', 'cvd-today-next-delivery');
      const info = make('div');
      info.append(make('span', '', 'Siguiente entrega'), make('strong', '', order || 'Próxima parada'));
      if (address) info.append(make('small', '', address));
      if (amount) info.append(make('b', '', amount));
      next.append(info);
      if (navigate) {
        const quick = navigate.cloneNode(true);
        quick.className = 'cvd-primary cvd-today-navigate';
        quick.textContent = 'Navegar';
        next.appendChild(quick);
      }
      today.appendChild(next);
    }
  }

  function compactContacts(center) {
    qsa('.cvd-contact-list article', center).forEach((card, index) => {
      if (card.dataset.cvdCompactReady) return;
      card.dataset.cvdCompactReady = '1';
      const outcomes = qs('.cvd-contact-outcomes', card);
      const result = qs('.cvd-contact-result', card);
      if (!outcomes && !result) return;

      const details = make('div', 'cvd-contact-more');
      if (outcomes) details.appendChild(outcomes);
      if (result) details.appendChild(result);
      details.hidden = true;
      card.appendChild(details);

      const toggle = make('button', 'cvd-contact-toggle');
      toggle.type = 'button';
      toggle.setAttribute('aria-controls', `cvd-contact-more-${index}`);
      details.id = `cvd-contact-more-${index}`;
      setExpanded(toggle, false, 'Ocultar opciones', 'Más opciones');
      toggle.addEventListener('click', () => {
        details.hidden = !details.hidden;
        setExpanded(toggle, !details.hidden, 'Ocultar opciones', 'Más opciones');
      });
      card.appendChild(toggle);
    });
  }

  function compactPreparation(center) {
    const preparation = qs('.cvd-messenger-preparation', center);
    if (!preparation || preparation.dataset.cvdCompactReady) return;
    preparation.dataset.cvdCompactReady = '1';

    const manifest = qs('.cvd-preparation-manifest', preparation);
    if (!manifest) return;
    const candidates = qsa('h3, h4, strong', manifest).filter((node) => /^#?\d{3,}$/.test(cleanText(node).replace(/^pedido\s*/i, '')));
    const blocks = [];
    candidates.forEach((node) => {
      const block = node.closest('article, section, div');
      if (block && block !== manifest && !blocks.includes(block)) blocks.push(block);
    });
    if (!blocks.length) return;
    blocks.forEach((block) => { block.classList.add('cvd-prep-order-detail'); block.hidden = true; });

    const toggle = make('button', 'cvd-secondary cvd-preparation-toggle');
    toggle.type = 'button';
    setExpanded(toggle, false, 'Ocultar pedidos', `Ver pedidos (${blocks.length})`);
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') !== 'true';
      blocks.forEach((block) => { block.hidden = !expanded; });
      setExpanded(toggle, expanded, 'Ocultar pedidos', `Ver pedidos (${blocks.length})`);
    });
    manifest.appendChild(toggle);
  }

  function compactRoute(center) {
    qsa('[data-route-stop]', center).forEach((stop, index) => {
      if (stop.dataset.cvdCompactReady) return;
      stop.dataset.cvdCompactReady = '1';
      stop.classList.add('cvd-route-compact-card');

      const quick = make('div', 'cvd-route-quick');
      const amount = cleanText(qs('.cvd-customer-collectible strong', stop));
      if (amount) quick.appendChild(make('strong', 'cvd-route-quick-money', amount));
      const navigate = qsa('a', stop).find((a) => /navegar|abrir mapa/i.test(cleanText(a)));
      if (navigate) {
        const clone = navigate.cloneNode(true);
        clone.className = 'cvd-primary cvd-route-quick-action';
        clone.textContent = 'Navegar';
        quick.appendChild(clone);
      }

      const header = qs('header', stop);
      if (header) header.insertAdjacentElement('afterend', quick);
      else stop.prepend(quick);

      const toggle = make('button', 'cvd-route-detail-toggle');
      toggle.type = 'button';
      toggle.setAttribute('aria-controls', `cvd-route-details-${index}`);
      setExpanded(toggle, false);

      const details = make('div', 'cvd-route-details');
      details.id = `cvd-route-details-${index}`;
      details.hidden = true;

      const movable = qsa(':scope > *', stop).filter((child) => child !== header && child !== quick && !child.classList.contains('cvd-route-order'));
      movable.forEach((child) => details.appendChild(child));
      stop.appendChild(details);
      stop.appendChild(toggle);

      toggle.addEventListener('click', () => {
        details.hidden = !details.hidden;
        stop.classList.toggle('is-expanded', !details.hidden);
        setExpanded(toggle, !details.hidden);
      });
    });
  }

  function collapseQr(center) {
    qsa('img, canvas, svg', center).forEach((visual, index) => {
      const parent = visual.closest('section, article, div');
      if (!parent || parent.dataset.cvdQrReady) return;
      const blockText = cleanText(parent).toLowerCase();
      if (!blockText.includes('qr de recogida') && !blockText.includes('ampliar qr')) return;
      parent.dataset.cvdQrReady = '1';
      parent.classList.add('cvd-qr-collapsible');

      const content = make('div', 'cvd-qr-content');
      content.id = `cvd-qr-content-${index}`;
      const children = qsa(':scope > *', parent);
      children.forEach((child) => content.appendChild(child));
      content.hidden = true;
      parent.appendChild(content);

      const label = make('strong', 'cvd-qr-label', 'Recogida pendiente');
      parent.prepend(label);

      const toggle = make('button', 'cvd-secondary cvd-qr-toggle');
      toggle.type = 'button';
      toggle.setAttribute('aria-controls', content.id);
      setExpanded(toggle, false, 'Ocultar QR', 'Mostrar QR');
      toggle.addEventListener('click', () => {
        content.hidden = !content.hidden;
        setExpanded(toggle, !content.hidden, 'Ocultar QR', 'Mostrar QR');
      });
      parent.appendChild(toggle);
    });
  }

  function hideFloatingWhatsapp(center) {
    qsa('a[href*="wa.me"], a[href*="whatsapp.com"]', document).forEach((link) => {
      if (center.contains(link)) return;
      let floating = link;
      while (floating && floating !== document.body && getComputedStyle(floating).position !== 'fixed') {
        floating = floating.parentElement;
      }
      if (floating && floating !== document.body) {
        floating.classList.add('cvd-hide-global-whatsapp');
        link.setAttribute('aria-hidden', 'true');
        link.tabIndex = -1;
      }
    });
  }

  function normalizeProductQuantities(center) {
    qsa('.cvd-preparation-manifest li, .cvd-route-items li', center).forEach((item) => {
      const value = cleanText(item);
      const normalized = value.replace(/^(\d+)\s*[×x]\s*/i, '$1× ');
      if (normalized !== value) item.textContent = normalized;
    });
  }

  function init() {
    const center = qs('.cvd-messenger-center.cvd-p03');
    if (!center) return;
    center.classList.add('cvd-premium-v2');
    buildTodaySnapshot(center);
    compactContacts(center);
    compactPreparation(center);
    compactRoute(center);
    collapseQr(center);
    normalizeProductQuantities(center);
    hideFloatingWhatsapp(center);
    installScreens(center);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
