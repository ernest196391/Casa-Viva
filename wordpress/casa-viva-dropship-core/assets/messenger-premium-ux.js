(() => {
  'use strict';

  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const cleanText = (node) => (node?.textContent || '').replace(/\s+/g, ' ').trim();

  function setExpanded(button, expanded, openLabel = 'Ocultar detalle', closedLabel = 'Ver detalle') {
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    button.textContent = expanded ? openLabel : closedLabel;
  }

  function installScreens(center) {
    const selectors = {
      hoy: ['.cvd-messenger-today'],
      ruta: ['.cvd-messenger-route', '#entregas'],
      dinero: ['#ganancias', '.cvd-messenger-earnings', '#cierre', '.cvd-messenger-closeout'],
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

    const links = qsa('a', nav);
    const destination = new Map([
      ['#hoy', 'hoy'],
      ['#ruta', 'ruta'],
      ['#ganancias', 'dinero'],
      ['#perfil', 'mas'],
    ]);

    const activate = (screen, focus = false) => {
      center.dataset.cvdView = screen;
      allSections.forEach((section) => {
        section.hidden = section.dataset.cvdScreen !== screen;
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

    qsa('.cvd-messenger-launchpad a[href="#asistente"]', center).forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        activate('mas', false);
        const assistant = qs('#asistente', center);
        if (assistant) {
          assistant.hidden = false;
          assistant.classList.add('is-open');
          assistant.classList.remove('is-collapsed');
          assistant.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }, { capture: true });
    });

    let initial = location.hash === '#ruta' ? 'ruta' : location.hash === '#ganancias' ? 'dinero' : location.hash === '#perfil' || location.hash === '#asistente' ? 'mas' : 'hoy';
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

    const brief = document.createElement('div');
    brief.className = 'cvd-today-brief';
    brief.setAttribute('aria-label', 'Estado de jornada');
    brief.innerHTML = `<strong>${orders} entregas hoy</strong><span>${pending} por confirmar · ${delivered} entregadas</span>`;

    const head = qs('.cvd-messenger-section-head', today);
    if (head) head.insertAdjacentElement('afterend', brief);

    if (alerts.length) {
      const attention = document.createElement('div');
      attention.className = 'cvd-today-attention';
      attention.innerHTML = `<span>Requiere atención</span><strong>${alerts[0]}</strong>`;
      const statsGrid = qs('.cvd-messenger-today-stats', today);
      statsGrid?.insertAdjacentElement('afterend', attention);
    }

    const firstStop = qs('[data-route-stop]', center);
    if (firstStop) {
      const order = cleanText(qs('h3, h4, strong', firstStop));
      const address = cleanText(qs('.cvd-route-address', firstStop));
      const amount = cleanText(qs('.cvd-customer-collectible strong', firstStop));
      const navigate = qsa('a', firstStop).find((a) => /navegar|abrir mapa/i.test(cleanText(a)));
      const next = document.createElement('div');
      next.className = 'cvd-today-next-delivery';
      next.innerHTML = `<div><span>Siguiente entrega</span><strong>${order || 'Próxima parada'}</strong>${address ? `<small>${address}</small>` : ''}${amount ? `<b>${amount}</b>` : ''}</div>`;
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

      const details = document.createElement('div');
      details.className = 'cvd-contact-more';
      if (outcomes) details.appendChild(outcomes);
      if (result) details.appendChild(result);
      details.hidden = true;
      card.appendChild(details);

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cvd-contact-toggle';
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

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'cvd-secondary cvd-preparation-toggle';
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

      const quick = document.createElement('div');
      quick.className = 'cvd-route-quick';
      const amount = cleanText(qs('.cvd-customer-collectible strong', stop));
      if (amount) {
        const money = document.createElement('strong');
        money.className = 'cvd-route-quick-money';
        money.textContent = amount;
        quick.appendChild(money);
      }
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

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cvd-route-detail-toggle';
      toggle.setAttribute('aria-controls', `cvd-route-details-${index}`);
      setExpanded(toggle, false);

      const details = document.createElement('div');
      details.className = 'cvd-route-details';
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

      const content = document.createElement('div');
      content.className = 'cvd-qr-content';
      content.id = `cvd-qr-content-${index}`;
      const children = qsa(':scope > *', parent);
      children.forEach((child) => content.appendChild(child));
      content.hidden = true;
      parent.appendChild(content);

      const label = document.createElement('strong');
      label.className = 'cvd-qr-label';
      label.textContent = 'Recogida pendiente';
      parent.prepend(label);

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cvd-secondary cvd-qr-toggle';
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
      const style = getComputedStyle(link);
      if (style.position === 'fixed') {
        link.classList.add('cvd-hide-global-whatsapp');
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
