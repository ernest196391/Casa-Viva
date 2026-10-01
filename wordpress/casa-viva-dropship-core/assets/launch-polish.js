(() => {
  const config = window.cvdLaunchPolish || {};
  const fallback = String(config.categoryFallback || "");
  if (!fallback) return;

  const categorySelector = 'a[href*="/categoria-producto/"] img';

  function categoryLabel(img) {
    const anchor = img.closest('a[href*="/categoria-producto/"]');
    const fromAlt = (img.getAttribute('alt') || '').trim();
    const fromAnchor = anchor ? (anchor.textContent || '').replace(/\s+/g, ' ').trim() : '';
    return fromAlt || fromAnchor || 'Categoría de Casa Viva';
  }

  function repair(img) {
    if (!(img instanceof HTMLImageElement) || img.dataset.cvdCategoryRepair === '1') return;
    img.dataset.cvdCategoryRepair = '1';
    const anchor = img.closest('a[href*="/categoria-producto/"]');
    if (anchor) anchor.classList.add('cvd-category-card-fallback');
    img.classList.add('cvd-category-image-fallback');
    img.alt = categoryLabel(img);
    img.srcset = '';
    img.sizes = '';
    img.src = fallback;
  }

  function bind(img) {
    if (!(img instanceof HTMLImageElement) || img.dataset.cvdCategoryBound === '1') return;
    img.dataset.cvdCategoryBound = '1';
    img.addEventListener('error', () => repair(img), { once: true });
    if (img.complete && img.naturalWidth === 0) repair(img);
  }

  function scan(root) {
    if (root instanceof HTMLImageElement && root.matches(categorySelector)) bind(root);
    if (root.querySelectorAll) root.querySelectorAll(categorySelector).forEach(bind);
  }

  scan(document);

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) scan(node);
      });
    }
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();