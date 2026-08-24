(() => {
  'use strict';

  const qs = (selector, root = document) => root.querySelector(selector);
  const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const text = (node) => (node?.textContent || '').replace(/\s+/g, ' ').trim();

  function syncMoneyScreen(center) {
    const payout = qs('#liquidaciones, .cvd-payout-panel', center);
    if (!payout) return;
    payout.dataset.cvdScreen = 'dinero';
    const apply = () => { payout.hidden = center.dataset.cvdView !== 'dinero'; };
    apply();
    new MutationObserver(apply).observe(center, { attributes: true, attributeFilter: ['data-cvd-view'] });
  }

  function consolidateTodayAlerts(center) {
    const today = qs('.cvd-messenger-today', center);
    const source = qs('.cvd-messenger-alerts', today);
    const attention = qs('.cvd-today-attention', today);
    if (!source || !attention) return;
    const alerts = qsa('p', source).map(text).filter(Boolean);
    const output = qs('strong', attention);
    if (output && alerts.length) output.textContent = alerts.join(' · ');
    source.hidden = true;
  }

  function embedDeliveryCards(center) {
    const route = qs('.cvd-messenger-route', center);
    if (!route) return;
    const cards = qsa('[data-delivery-id]', route);
    if (!cards.length) return;

    cards.forEach((card) => {
      const id = card.getAttribute('data-delivery-id');
      if (!id) return;
      const stop = qsa('[data-route-stop]', route).find((candidate) => candidate.getAttribute('data-route-stop') === id);
      const details = stop && qs('.cvd-route-details', stop);
      if (!details) return;
      card.classList.add('cvd-embedded-delivery');
      details.appendChild(card);
    });

    qsa('h2, h3, h4', route).forEach((heading) => {
      if (/^entrega activa$/i.test(text(heading))) heading.hidden = true;
    });
  }

  function observeLateWhatsapp(center) {
    const hide = () => {
      qsa('a[href*="wa.me"], a[href*="whatsapp.com"]', document).forEach((link) => {
        if (center.contains(link)) return;
        const style = getComputedStyle(link);
        if (style.position !== 'fixed') return;
        link.classList.add('cvd-hide-global-whatsapp');
        link.setAttribute('aria-hidden', 'true');
        link.tabIndex = -1;
      });
    };
    hide();
    new MutationObserver(hide).observe(document.body, { childList: true, subtree: true });
  }

  function init() {
    const center = qs('.cvd-messenger-center.cvd-premium-v2');
    if (!center) return;
    syncMoneyScreen(center);
    consolidateTodayAlerts(center);
    embedDeliveryCards(center);
    observeLateWhatsapp(center);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
