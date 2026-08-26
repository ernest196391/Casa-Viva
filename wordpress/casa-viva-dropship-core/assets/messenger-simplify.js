(() => {
  'use strict';

  const qs = (s, root = document) => root.querySelector(s);
  const qsa = (s, root = document) => [...root.querySelectorAll(s)];
  const text = (el) => (el?.textContent || '').trim();

  function scrollToHash(hash) {
    const target = qs(hash);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function syncMobileViewport(center) {
    const query = window.matchMedia('(max-width: 640px)');
    const apply = () => {
      if (query.matches) {
        center.style.setProperty('width', '100vw', 'important');
        center.style.setProperty('max-width', '100vw', 'important');
        center.style.setProperty('margin-left', 'calc(50% - 50vw)', 'important');
        center.style.setProperty('margin-right', 'calc(50% - 50vw)', 'important');
      } else {
        center.style.removeProperty('width');
        center.style.removeProperty('max-width');
        center.style.removeProperty('margin-left');
        center.style.removeProperty('margin-right');
      }
    };
    apply();
    if (query.addEventListener) query.addEventListener('change', apply);
  }

  function simplifyMessenger() {
    const center = qs('.cvd-messenger-center');
    if (!center) return;
    center.classList.add('cvd-p03');
    syncMobileViewport(center);

    const head = qs('.cvd-messenger-head', center);
    if (head) {
      const kicker = qs('.cvd-kicker', head);
      const h1 = qs('h1', head);
      const p = qs('p:last-of-type', head);
      if (kicker) kicker.textContent = 'Casa Viva';
      if (h1) h1.textContent = 'Hoy';
      if (p) p.textContent = 'Tu jornada, sin ruido.';
    }

    const launchpad = qs('.cvd-messenger-launchpad', center);
    if (launchpad) {
      const upload = qs('.cvd-upload-voucher', launchpad);
      const assistant = qsa('a', launchpad).find((a) => /asistente/i.test(text(a)));
      if (upload) upload.innerHTML = '<span aria-hidden="true">＋</span> Añadir vale';
      if (assistant) assistant.textContent = 'Asistente';
    }

    const nav = qs('.cvd-messenger-nav', center);
    if (nav) {
      nav.innerHTML = [
        ['#hoy', 'Hoy'],
        ['#ruta', 'Ruta'],
        ['#ganancias', 'Dinero'],
        ['#perfil', 'Más'],
      ].map(([href, label]) => `<a href="${href}">${label}</a>`).join('');
    }

    const today = qs('.cvd-messenger-today', center);
    if (today) {
      const stats = qsa('.cvd-messenger-today-stats article', today);
      const values = {};
      stats.forEach((card) => {
        const label = text(qs('span', card));
        const value = text(qs('strong', card));
        values[label] = value;
        if (['Pedidos', 'Pendientes de contacto', 'Entregados'].includes(label)) card.classList.add('is-key');
        else card.classList.add('is-secondary');
      });

      const orders = Number(values['Pedidos'] || 0);
      const pending = Number(values['Pendientes de contacto'] || 0);
      const prepared = Number(values['Preparados'] || 0);
      const delivered = Number(values['Entregados'] || 0);
      let task = 'Jornada al día';
      let href = '#ruta';
      let action = 'Ver ruta';
      if (!orders) {
        task = 'Añade tu primer vale';
        href = qs('.cvd-upload-voucher', center)?.getAttribute('href') || '#';
        action = 'Añadir vale';
      } else if (pending > 0) {
        task = `Faltan ${pending} cliente${pending === 1 ? '' : 's'} por confirmar`;
        href = '#contactos';
        action = 'Contactar';
      } else if (prepared < orders) {
        task = 'Revisa la preparación de salida';
        href = '#preparar';
        action = 'Preparar';
      } else if (delivered < orders) {
        task = 'Continúa con la próxima entrega';
        href = '#ruta';
        action = 'Ir a ruta';
      }
      const taskBox = document.createElement('div');
      taskBox.className = 'cvd-next-task';
      taskBox.innerHTML = `<div><span>Siguiente tarea</span><strong>${task}</strong></div><a class="cvd-primary" href="${href}">${action}</a>`;
      const sectionHead = qs('.cvd-messenger-section-head', today);
      if (sectionHead) sectionHead.insertAdjacentElement('afterend', taskBox);
    }

    const assistant = qs('.cvd-operational-assistant', center);
    if (assistant) {
      assistant.classList.add('is-collapsed');
      const kicker = qs('.cvd-kicker', assistant);
      const h2 = qs('h2', assistant);
      const intro = qs('.cvd-messenger-section-head p:last-child', assistant);
      if (kicker) kicker.textContent = 'Asistente';
      if (h2) h2.textContent = '¿Qué necesitas?';
      if (intro) intro.remove();
      const chipLabels = ['Clientes por llamar', 'Vuelto', 'Preparación', 'Qué falta'];
      qsa('.cvd-assistant-chips button', assistant).forEach((button, i) => {
        if (chipLabels[i]) button.textContent = chipLabels[i];
      });
      const label = qs('form label', assistant);
      if (label) label.textContent = 'Pregúntame algo';
      const input = qs('form input', assistant);
      if (input) input.placeholder = 'Ej.: ¿Qué pedido es por la mañana?';
      const submit = qs('form button', assistant);
      if (submit) submit.textContent = 'Preguntar';
    }

    qsa('.cvd-messenger-launchpad a[href="#asistente"]', center).forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const panel = qs('#asistente', center);
        if (!panel) return;
        panel.classList.toggle('is-open', true);
        panel.classList.remove('is-collapsed');
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    const contacts = qs('.cvd-messenger-contacts', center);
    if (contacts) {
      const p = qs('.cvd-messenger-section-head p:last-child', contacts);
      const h2 = qs('h2', contacts);
      if (p) p.remove();
      if (h2) h2.textContent = 'Clientes por confirmar';
    }

    const preparation = qs('.cvd-messenger-preparation', center);
    if (preparation) {
      const h2 = qs('h2', preparation);
      if (h2) h2.textContent = 'Preparar salida';
      const sync = qs('.cvd-preparation-sync', preparation);
      if (sync) sync.remove();
      const share = qsa('a', preparation).find((a) => /Compartir resumen/i.test(text(a)));
      if (share) share.textContent = 'Enviar resumen por WhatsApp';
    }

    const route = qs('.cvd-messenger-route', center);
    if (route) {
      const intro = qs('.cvd-messenger-section-head p:last-child', route);
      const h2 = qs('h2', route);
      if (intro) intro.remove();
      if (h2) h2.textContent = 'Ruta de hoy';
      const stops = qsa('[data-route-stop]', route);
      stops.forEach((stop, index) => {
        stop.classList.toggle('is-current', index === 0);
        const position = qs('.cvd-route-position', stop);
        if (position) position.textContent = `Parada ${index + 1} de ${stops.length}`;
        const collectible = qs('.cvd-customer-collectible small', stop);
        if (collectible) collectible.hidden = true;
      });

      const deliveryCards = qsa('[data-delivery-id]', route);
      const currentDelivery = deliveryCards.find((card) => qsa('a', card).some((link) => text(link) === 'Navegar')) || deliveryCards[0];
      deliveryCards.forEach((card) => card.classList.toggle('is-current', card === currentDelivery));

      const disclaimer = qs('.cvd-route-disclaimer', route);
      if (disclaimer) disclaimer.textContent = 'Puedes cambiar el orden con Subir y Bajar.';
    }

    const closeout = qs('.cvd-messenger-closeout', center);
    if (closeout) {
      const p = qs('.cvd-messenger-section-head p:last-child', closeout);
      if (p) p.remove();
    }

    const enableAlerts = qs('#cvd-enable-notifications', center);
    if (enableAlerts) enableAlerts.closest('.cvd-messenger-alert-control')?.classList.add('cvd-more-only');

    if (location.hash === '#asistente' && assistant) {
      assistant.classList.add('is-open');
      assistant.classList.remove('is-collapsed');
    }

    center.addEventListener('click', (event) => {
      const link = event.target.closest('a[href^="#"]');
      if (!link) return;
      const hash = link.getAttribute('href');
      if (!hash || hash === '#') return;
      if (hash === '#asistente' && assistant) {
        assistant.classList.add('is-open');
        assistant.classList.remove('is-collapsed');
      }
      setTimeout(() => scrollToHash(hash), 0);
    });
  }

  function simplifyVoucher() {
    const app = qs('[data-cvd-voucher]');
    if (!app) return;
    document.body.classList.add('cvd-p03-voucher');
    app.classList.add('cvd-p03');

    const header = qs('header', app);
    if (header) {
      const kicker = qs('.cvd-kicker', header);
      const h1 = qs('h1', header);
      const p = qs('p:last-child', header);
      if (kicker) kicker.remove();
      if (h1) h1.textContent = 'Añadir vale';
      if (p) p.textContent = 'Pega el mensaje de WhatsApp.';
    }

    const parse = qs('[data-voucher-parse]', app);
    if (parse) parse.textContent = 'Analizar';
    const textarea = qs('#cvd-voucher-text', app);
    if (textarea) textarea.rows = 9;

    const review = qs('[data-voucher-review]', app);
    if (!review) return;

    function tuneReview() {
      if (review.hidden) return;
      const kicker = qs('.cvd-kicker', review);
      const h2 = qs('h2', review);
      const confidence = qs('[data-voucher-confidence]', review);
      const note = qs('.cvd-voucher-note', review);
      const confirm = qs('[data-voucher-confirm]', review);
      if (kicker) kicker.textContent = 'Encontré esto';
      if (h2) h2.textContent = 'Revisa lo importante';
      if (confidence) confidence.setAttribute('aria-label', `Confianza: ${text(confidence)}`);
      if (note) note.hidden = true;
      if (confirm && !confirm.disabled) confirm.textContent = 'Confirmar';

      const form = qs('[data-voucher-form]', review);
      const grid = qs('.cvd-voucher-grid', form || review);
      if (grid && !qs('[data-cvd-show-all]', review)) {
        const alertText = text(qs('[data-voucher-alerts]', review)).toLowerCase();
        qsa('[data-key]', grid).forEach((input) => {
          const label = input.closest('label') || input.parentElement;
          if (!label) return;
          const key = String(input.dataset.key || '').toLowerCase();
          const value = String(input.value || '').trim();
          const labelText = text(label).toLowerCase();
          const flagged = !value || (key && alertText.includes(key)) || [...labelText.split(/\s+/)].some((word) => word.length > 4 && alertText.includes(word));
          label.classList.toggle('cvd-field-confirmed', !flagged);
        });
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'cvd-secondary cvd-voucher-show-all';
        toggle.dataset.cvdShowAll = '1';
        toggle.textContent = 'Ver todos los datos';
        toggle.addEventListener('click', () => {
          review.classList.toggle('show-all-fields');
          toggle.textContent = review.classList.contains('show-all-fields') ? 'Ocultar datos correctos' : 'Ver todos los datos';
        });
        grid.insertAdjacentElement('afterend', toggle);
      }
    }

    const observer = new MutationObserver(tuneReview);
    observer.observe(review, { attributes: true, subtree: true, childList: true });
    tuneReview();

    const status = qs('.cvd-voucher-status', app);
    if (status && parse) {
      const syncAnalyzing = () => app.classList.toggle('is-analyzing', parse.disabled);
      const slowTimer = new MutationObserver(syncAnalyzing);
      slowTimer.observe(parse, { attributes: true, attributeFilter: ['disabled'] });
      syncAnalyzing();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    simplifyMessenger();
    simplifyVoucher();
  });

  document.addEventListener('click', (event) => {
    qsa('details.cv-mobile-nav[open]').forEach((menu) => {
      if (!menu.contains(event.target) || event.target.closest('.cv-mobile-nav__panel a')) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') qsa('details.cv-mobile-nav[open]').forEach((menu) => menu.removeAttribute('open'));
  });
})();
