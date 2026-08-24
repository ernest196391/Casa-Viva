(() => {
  'use strict';

  const clean = (node) => (node?.textContent || '').replace(/\s+/g, ' ').trim();

  function init() {
    const center = document.querySelector('.cvd-messenger-center.cvd-p03');
    const assistant = center?.querySelector('#asistente');
    const answer = assistant?.querySelector('.cvd-assistant-answer');
    const form = assistant?.querySelector('form');
    const input = form?.querySelector('input');
    if (!center || !assistant || !answer || !form || !input) return;

    let lastQuestion = '';
    form.addEventListener('submit', () => { lastQuestion = String(input.value || '').trim(); });

    const isOverviewQuestion = () => /(?:qu[eé]\s+hay\s+de\s+nuevo|resumen|c[oó]mo\s+va|estado\s+de\s+(?:hoy|la\s+jornada)|ahora\s+mismo)/i.test(lastQuestion);

    const readStat = (needle) => {
      const cards = Array.from(center.querySelectorAll('.cvd-messenger-today-stats article'));
      const card = cards.find((entry) => clean(entry.querySelector('span')).toLowerCase().includes(needle));
      return card ? clean(card.querySelector('strong')) : '0';
    };

    const renderOverview = () => {
      if (!isOverviewQuestion()) return;
      if (!/falta\s+informaci[oó]n/i.test(clean(answer))) return;

      const orders = readStat('pedido');
      const pending = readStat('pendiente');
      const delivered = readStat('entregado');
      const alerts = Array.from(center.querySelectorAll('.cvd-messenger-alerts p, .cvd-preparation-alerts p'))
        .map(clean)
        .filter(Boolean);
      const mapAlert = alerts.find((item) => /mapa|ubicaci[oó]n/i.test(item));
      const incident = alerts.find((item) => /incidencia/i.test(item));

      const lines = [
        `<strong>Ahora mismo</strong>`,
        `<span>${orders} entregas · ${pending} pendientes de confirmar · ${delivered} entregadas.</span>`,
      ];
      if (mapAlert) lines.push(`<span>${mapAlert}</span>`);
      if (incident) lines.push(`<span>${incident}</span>`);

      const pendingNumber = Number(String(pending).replace(/\D/g, '')) || 0;
      const nextAction = pendingNumber > 0
        ? `Lo primero: contactar ${pendingNumber === 1 ? 'al cliente pendiente' : `a los ${pendingNumber} clientes pendientes`}.`
        : incident
          ? 'Lo primero: revisar la incidencia antes de continuar.'
          : 'La jornada no muestra bloqueos prioritarios en este momento.';
      lines.push(`<b>${nextAction}</b>`);
      answer.innerHTML = `<div class="cvd-assistant-overview">${lines.join('')}</div>`;
    };

    new MutationObserver(renderOverview).observe(answer, { childList: true, subtree: true, characterData: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
