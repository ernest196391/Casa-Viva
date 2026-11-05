/* global cvContent, cvProductBackground */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('cv-editor')) return;
  let product = null, photo = null, page = 1, pages = 1, token = crypto.randomUUID(), dirty = false;
  let markers = [], nextMarker = 0;
  // Changes whenever another product is opened; late answers for an older one must not touch the open form.
  let ficha = 0;
  let generationSession = 0;
  let scenePhoto = null, selectedOption = null;
  let tab = 'ficha', layoutValue = 'features', formatValue = '1920', reviewPick = '', copyEdited = false;
  // Brief fields no longer shown in the panel: kept as saved so a save never erases them.
  let briefExtra = {};
  const LAYOUTS = { features: 'Características', spec: 'Ficha técnica', offer: 'Oferta', use: 'Uso diario', review: 'Reseña' };
  const FONT = '"Helvetica Neue", Helvetica, Arial, sans-serif';
  function resetScenes() {
    generationSession++;
    scenePhoto = null; selectedOption = null;
    $('cv-variants').replaceChildren();
    $('cv-ai-status').textContent = '';
    $('cv-import-ai').value = '';
    markOptions(); optionPanel();
  }
  // Notice at the bottom of the screen: it clears itself after 10 s or when tapped, so it never covers the work.
  let messageTimer = 0;
  const message = (text) => { $('cv-message').textContent = text; clearTimeout(messageTimer); messageTimer = setTimeout(() => { $('cv-message').textContent = ''; }, 10000); };
  $('cv-message').addEventListener('click', () => { $('cv-message').textContent = ''; });
  const plain = (text) => { const d = new DOMParser().parseFromString(text || '', 'text/html'); return d.body.textContent || ''; };
  // Description and summary are edited as plain text: blank line = paragraph, «• » = list item, short line ending in «:» = heading.
  // Text with any other formatting (bold, links, tables…) stays as HTML so nothing is lost on save.
  const simpleTags = new Set(['P', 'H3', 'UL', 'LI', 'BR']);
  const rawHtml = {};
  function toText(html) {
    const body = new DOMParser().parseFromString(html || '', 'text/html').body;
    if ([...body.querySelectorAll('*')].some((el) => !simpleTags.has(el.tagName) || el.attributes.length) || body.querySelector('li ul, p ul')) return null;
    const line = (el) => [...el.childNodes].map((n) => n.nodeName === 'BR' ? '\n' : n.textContent.replace(/\s+/g, ' ')).join('').split('\n').map((s) => s.trim()).join('\n').trim();
    const blocks = [...body.childNodes].map((node) => {
      if (node.nodeType !== Node.ELEMENT_NODE) return node.textContent.trim();
      if (node.tagName === 'H3') return `${line(node).replace(/:$/, '')}:`;
      if (node.tagName === 'UL') return [...node.children].map((li) => `• ${line(li)}`).join('\n');
      return line(node);
    });
    return blocks.filter(Boolean).join('\n\n');
  }
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  function toHtml(text) {
    const html = [];
    for (const block of text.trim().split(/\n\s*\n/)) {
      let para = [], list = [];
      const flushPara = () => { if (para.length) html.push(`<p>${para.map(esc).join('<br>')}</p>`); para = []; };
      const flushList = () => { if (list.length) html.push(`<ul>${list.map((s) => `<li>${esc(s)}</li>`).join('')}</ul>`); list = []; };
      for (const l of block.split('\n').map((s) => s.trim()).filter(Boolean)) {
        const bullet = l.match(/^[•*-]\s+(.+)$/);
        if (bullet) { flushPara(); list.push(bullet[1]); }
        else if (l.endsWith(':') && l.length <= 60 && !para.length) { flushList(); html.push(`<h3>${esc(l.slice(0, -1))}</h3>`); }
        else { flushList(); para.push(l); }
      }
      flushPara(); flushList();
    }
    return html.join('');
  }
  const editableText = (id) => rawHtml[id] ? $(id).value : toHtml($(id).value);
  async function api(path, body) {
    const multipart = body instanceof FormData;
    // rest_url may be an index.php?rest_route= URL on sites without pretty permalinks.
    const [route, query = ''] = path.split('?');
    const url = new URL(cvContent.base + route, location.href);
    for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
    const response = await fetch(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cvContent.nonce, ...(!multipart && body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? (multipart ? body : JSON.stringify(body)) : undefined });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'No se pudo completar. Intenta otra vez.');
    return data;
  }
  async function busy(button, work) {
    if (button) button.disabled = true;
    try { await work(); } catch (error) { message(error.message); } finally { if (button) button.disabled = false; updateBar(); }
  }

  /* ---------- Hojas que suben desde abajo ---------- */
  let openSheetEl = null, sheetReturn = null;
  function openSheet(id, from) {
    closeSheet();
    const sheet = $(id); sheetReturn = from || document.activeElement;
    sheet.hidden = false; $('cv-scrim').hidden = false; openSheetEl = sheet; document.body.classList.add('cv-sheet-open');
    sheet.querySelector('h2')?.focus();
  }
  function closeSheet() {
    if (!openSheetEl) return;
    openSheetEl.hidden = true; $('cv-scrim').hidden = true; openSheetEl = null; document.body.classList.remove('cv-sheet-open');
    if (sheetReturn && document.contains(sheetReturn) && !sheetReturn.closest('[hidden]')) sheetReturn.focus();
    sheetReturn = null;
  }
  $('cv-scrim').addEventListener('click', closeSheet);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && openSheetEl) closeSheet(); });
  document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeSheet));

  /* ---------- Pantallas y pestañas ---------- */
  function showList() { closeSheet(); $('cv-editor').hidden = true; $('cv-list-screen').hidden = false; document.body.classList.remove('cv-has-bar'); window.scrollTo(0, 0); }
  function showEditor() { $('cv-list-screen').hidden = true; $('cv-editor').hidden = false; updateBar(); window.scrollTo(0, 0); }
  $('cv-back').addEventListener('click', () => { if (dirty && !confirm('Tienes cambios sin guardar. ¿Volver a la lista?')) return; showList(); });
  const TABS = ['ficha', 'anuncio', 'res'];
  function setTab(name) {
    tab = name;
    for (const t of TABS) {
      const b = $('cv-tab-' + t);
      b.setAttribute('aria-selected', String(t === name)); b.tabIndex = t === name ? 0 : -1;
      $('cv-panel-' + t).hidden = t !== name;
    }
    closeSheet(); updateBar(); window.scrollTo(0, 0);
    if (name === 'anuncio') { syncAnuncio(); preview(0); }
  }
  TABS.forEach((t, i) => {
    const b = $('cv-tab-' + t);
    b.addEventListener('click', () => { if (!b.disabled) setTab(t); });
    b.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
      const step = e.key === 'ArrowRight' ? 1 : -1;
      for (let k = 1; k <= TABS.length; k++) { const next = $('cv-tab-' + TABS[(i + step * k + TABS.length * k) % TABS.length]); if (!next.disabled) { next.focus(); next.click(); break; } }
    });
  });
  document.querySelectorAll('[data-goto]').forEach((b) => b.addEventListener('click', () => setTab(b.dataset.goto)));

  /* ---------- Barra de acción: un botón principal que dice lo que falta ---------- */
  function missing() {
    const m = [];
    if (!product?.image) m.push('foto');
    if (!plain(editableText('cv-description')).trim()) m.push('descripción');
    // 3.14.145 (Ernesto 5-nov): a draft goes live only when Lennys has set price and stock.
    const lennys = [];
    if (!product?.price) lennys.push('precio');
    if (!product?.id || (product.faltan || []).includes('existencias')) lennys.push('existencias');
    if (lennys.length) m.push(`${lennys.join(' y ')} (Lennys)`);
    return m;
  }
  function updateBar() {
    if (!product) return;
    const isNew = !product.id, published = product.status === 'publish';
    const save = $('cv-save'), pub = $('cv-publish'), fin = $('cv-render');
    save.textContent = isNew ? 'Guardar borrador' : published && tab === 'ficha' ? 'Guardar cambios' : 'Guardar';
    save.hidden = tab !== 'ficha' && !dirty;
    save.classList.toggle('cv-primary', published && tab === 'ficha');
    pub.hidden = tab !== 'ficha' || published;
    if (!pub.hidden) {
      const miss = missing();
      pub.disabled = miss.length > 0;
      pub.replaceChildren('Publicar');
      if (miss.length) { const s = document.createElement('small'); s.textContent = `Falta ${miss.join(', ')}`; pub.append(s); }
    }
    fin.hidden = tab !== 'anuncio';
    $('cv-actionbar').hidden = save.hidden && pub.hidden && fin.hidden;
    document.body.classList.toggle('cv-has-bar', !$('cv-editor').hidden && !$('cv-actionbar').hidden);
  }
  function refreshHeader() {
    const p = product || {};
    $('cv-heading').textContent = p.id ? p.name : 'Nueva mercancía';
    $('cv-status').textContent = !p.id ? 'Nuevo' : p.status === 'publish' ? 'Publicado' : 'Borrador';
    $('cv-status').classList.toggle('cv-pill-draft', p.status !== 'publish');
    const price = p.sale ? `${p.sale.price} (antes ${p.sale.regular})` : (p.price || 'Pendiente');
    $('cv-commercial').textContent = p.price || 'Pendiente'; $('cv-ad-price').textContent = price;
    $('cv-view').hidden = p.status !== 'publish'; if (p.url) $('cv-view').href = p.url;
    $('cv-tab-res').disabled = !p.id;
  }
  function showPhoto() {
    const img = $('cv-original'), has = Boolean(img.getAttribute('src'));
    img.hidden = !has; $('cv-photo-empty').hidden = has;
    $('cv-photo-label').textContent = has ? 'Cambiar foto' : 'Añadir foto';
    const thumb = $('cv-own-thumb'); thumb.hidden = !has; if (has) thumb.src = img.src; else thumb.removeAttribute('src');
  }

  /* ---------- Características: dato + para qué sirve, guardadas como «dato — beneficio» ---------- */
  const ROWS = [1, 2, 3];
  function facts() {
    return ROWS.map((i) => { const f = $('cv-fact-' + i).value.trim(), b = $('cv-benefit-' + i).value.trim(); return f ? (b ? `${f} — ${b}` : f) : ''; }).filter(Boolean).slice(0, 3);
  }
  const factsText = () => facts().join('\n');
  function showFactRows() {
    ROWS.forEach((i) => {
      const has = $('cv-fact-' + i).value || $('cv-benefit-' + i).value, before = i === 1 || $('cv-fact-' + (i - 1)).value.trim();
      $('cv-fact-row-' + i).hidden = !(has || before);
    });
  }
  function setFacts(text) {
    const lines = String(text || '').split('\n').map((s) => s.trim()).filter(Boolean);
    ROWS.forEach((i, k) => {
      const m = (lines[k] || '').match(/^(.*?)\s+[—–-]\s+(.+)$/);
      $('cv-fact-' + i).value = m ? m[1] : (lines[k] || ''); $('cv-benefit-' + i).value = m ? m[2] : '';
    });
    showFactRows();
  }

  function fill(p) {
    ficha++;
    closeSheet();
    resetScenes();
    const review = p.review || p.review_old;
    if (review) {
      const session = generationSession, changed = p.review_old ? (p.review_changed || []) : null;
      loadImage(review).then(img => { if (session === generationSession) showVariant(img, review, session, true, changed); }).catch(() => message('No se pudo abrir la opción guardada.'));
    }
    product = p; photo = null; dirty = false; markers = []; nextMarker = 0; copyEdited = false;
    $('cv-name').value = p.name || '';
    for (const [id, html] of [['cv-description', p.description], ['cv-short', p.short]]) {
      const text = toText(html); rawHtml[id] = text === null; $(id).value = text ?? html ?? '';
    }
    $('cv-text-note').hidden = !rawHtml['cv-description'] && !rawHtml['cv-short'];
    $('cv-tools').hidden = Boolean(rawHtml['cv-description']);
    $('cv-suggest-box').hidden = true; $('cv-suggestion').value = '';
    if (p.image) $('cv-original').src = p.image; else $('cv-original').removeAttribute('src');
    showPhoto();
    $('cv-photo').value = ''; $('cv-upload').hidden = true;
    const brief = p.brief || {};
    setFacts(brief.facts);
    for (const key of ['use', 'headline', 'offer']) $('cv-' + key).value = brief[key] || '';
    briefExtra = { buyer: brief.buyer || '', objection: brief.objection || '', evidence: brief.evidence || '' };
    reviewPick = '';
    showReviews(p.reviews || []); showAds(p.anuncios || []);
    $('cv-review-text').value = ''; $('cv-review-name').value = ''; setStars(0); $('cv-review-permission').checked = false;
    $('cv-canvas').hidden = true; $('cv-preview-note').hidden = true; $('cv-point-hint').hidden = true;
    $('cv-download').removeAttribute('href'); $('cv-copy').value = ''; $('cv-prompt').value = '';
    refreshHeader(); setTab('ficha');
  }

  /* ---------- Lista ---------- */
  let filter = 'all', listSeq = 0;
  function applyFilter() {
    for (const b of $('cv-items').querySelectorAll('.cv-product')) b.hidden = filter !== 'all' && b.dataset.status !== filter;
  }
  async function list(append = false) {
    const seq = ++listSeq;
    const data = await api(`products?search=${encodeURIComponent($('cv-search').elements.search.value)}&page=${page}`);
    if (seq !== listSeq) return;
    pages = data.pages; if (!append) $('cv-items').replaceChildren();
    for (const p of data.items) {
      const published = p.status === 'publish';
      const button = document.createElement('button'); button.type = 'button'; button.className = 'cv-product'; button.dataset.status = published ? 'publish' : 'draft';
      button.setAttribute('aria-label', `${p.name} · ${published ? 'Publicado' : 'Borrador / privado'}`);
      const thumb = document.createElement('span'); thumb.className = 'cv-thumb';
      if (p.image) { const img = document.createElement('img'); img.src = p.image; img.alt = ''; img.loading = 'lazy'; thumb.append(img); }
      const text = document.createElement('span'); text.className = 'cv-product-text';
      const name = document.createElement('b'); name.textContent = p.name;
      const meta = document.createElement('small'), dot = document.createElement('i'); dot.className = published ? 'cv-dot cv-dot-on' : 'cv-dot';
      meta.append(`${p.price || 'Sin precio'} · `, dot, published ? 'Publicado' : 'Borrador');
      text.append(name, meta); button.append(thumb, text);
      button.addEventListener('click', () => busy(button, async () => {
        if (dirty && !confirm('Tienes cambios sin guardar. ¿Abrir otro producto?')) return;
        const current = ++ficha, opened = await api(`products?id=${p.id}`);
        if (current !== ficha) return;
        fill(opened); showEditor(); message('Producto abierto.');
      })); $('cv-items').append(button);
    }
    if (!data.items.length && !append) { const empty = document.createElement('p'); empty.className = 'cv-empty'; empty.textContent = 'No encontramos productos con ese nombre.'; $('cv-items').append(empty); }
    $('cv-more').hidden = page >= pages;
    applyFilter();
  }
  let searchTimer = 0;
  $('cv-search').addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(searchTimer); page = 1; busy(null, () => list()); });
  $('cv-search').elements.search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; busy(null, () => list()); }, 400); });
  document.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.filter; document.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b))); applyFilter();
  }));
  $('cv-more').addEventListener('click', (e) => busy(e.currentTarget, async () => { page++; try { await list(true); } catch (error) { page--; throw error; } }));
  $('cv-new').addEventListener('click', () => {
    if (dirty && !confirm('Tienes cambios sin guardar. ¿Crear otro producto?')) return;
    token = crypto.randomUUID(); fill({}); showEditor(); $('cv-name').focus();
  });
  $('cv-editor').addEventListener('input', (e) => {
    if (e.target.closest('[data-nodirty]')) return;
    dirty = true;
    if (/^cv-(fact|benefit)-/.test(e.target.id)) { showFactRows(); showPoints(); preview(); }
    if (['cv-headline', 'cv-use', 'cv-offer'].includes(e.target.id)) preview();
    updateBar();
  });
  $('cv-copy').addEventListener('input', () => { copyEdited = true; });

  /* ---------- Cuenta ---------- */
  $('cv-account').addEventListener('click', (e) => { $('cv-password').hidden = true; openSheet('cv-account-sheet', e.currentTarget); });
  $('cv-password-open').addEventListener('click', () => { $('cv-password').hidden = false; $('cv-current-password').focus(); });
  $('cv-password').addEventListener('submit', (e) => { e.preventDefault(); busy(e.submitter, async () => {
    const result = await api('password', { current: $('cv-current-password').value, next: $('cv-next-password').value });
    dirty = false; location.assign(result.login);
  }); });
  $('cv-menu').addEventListener('click', (e) => openSheet('cv-menu-sheet', e.currentTarget));

  /* ---------- Ficha: mejorar con sugerencia en línea ---------- */
  function suggestion(target, text) {
    const box = $('cv-suggest-box'); box.dataset.target = target;
    $('cv-suggestion').value = text; $(target).closest('.cv-field').after(box); box.hidden = false;
  }
  $('cv-suggest').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const current = ficha, result = await api('suggest', { name: $('cv-name').value, facts: factsText() });
    if (current !== ficha) return;
    suggestion('cv-description', result.text); if (result.note) message(result.note);
  }));
  $('cv-title-suggest').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const current = ficha, result = await api('suggest', { name: $('cv-name').value, facts: factsText(), kind: 'titulo' });
    if (current !== ficha) return;
    suggestion('cv-name', result.text); if (result.note) message(result.note);
  }));
  $('cv-use-suggestion').addEventListener('click', () => {
    const target = $('cv-suggest-box').dataset.target, text = $('cv-suggestion').value.trim(); if (!text || !target) return;
    $(target).value = text; dirty = true; $('cv-suggest-box').hidden = true; updateBar();
    message(target === 'cv-name' ? 'Título puesto en la ficha. Guarda los cambios para publicarlo.' : 'Descripción puesta en la ficha. Guarda los cambios para publicarla.');
  });
  $('cv-drop-suggestion').addEventListener('click', () => { $('cv-suggest-box').hidden = true; });
  // «• Punto» and «Subtítulo» write the plain-text format for her.
  $('cv-tools').addEventListener('click', (e) => {
    const b = e.target.closest('[data-insert]'); if (!b) return;
    const ta = $('cv-description'), start = ta.selectionStart ?? ta.value.length, end = ta.selectionEnd ?? start;
    const before = ta.value.slice(0, start), after = ta.value.slice(end);
    let insert, from, to;
    if (b.dataset.insert === 'point') { insert = (before && !before.endsWith('\n') ? '\n' : '') + '• '; from = to = start + insert.length; }
    else { const gap = !before ? '' : before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n'; insert = `${gap}Subtítulo:\n`; from = start + gap.length; to = from + 'Subtítulo'.length; }
    ta.value = before + insert + after; ta.focus(); ta.setSelectionRange(from, to);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  async function save(action) {
    if (!$('cv-editor').reportValidity()) return;
    const current = ficha, p = await api('save', { id: product.id || 0, version: product.version || '', token, action,
      name: $('cv-name').value, description: editableText('cv-description'), short: editableText('cv-short'),
      brief: { facts: factsText(), ...briefExtra, use: $('cv-use').value, headline: $('cv-headline').value, offer: $('cv-offer').value } });
    if (current !== ficha) { message(`«${p.name}» quedó guardado. La ficha que tienes abierta no cambió.`); return; }
    // Keep unsaved layout work and selected local photo while refreshing server state.
    product = { ...p, anuncios: product.anuncios || [] }; dirty = false; refreshHeader(); updateBar();
    message(p.status === 'publish' ? 'Cambios publicados en Casa Viva.' : (p.falta ? `Borrador guardado. Falta: ${p.falta}.` : 'Borrador guardado. Ya tiene todo: puedes publicarlo.'));
  }
  $('cv-editor').addEventListener('submit', (e) => { e.preventDefault(); busy($('cv-save'), () => save('save')); });
  $('cv-publish').addEventListener('click', (e) => busy(e.currentTarget, () => save('publish')));

  /* ---------- Foto de la web ---------- */
  const imageCache = new Map();
  async function loadImage(url) { const img = new Image(); img.crossOrigin = 'anonymous'; await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = () => reject(new Error('No se pudo abrir la foto. Sube una desde tu móvil.')); img.src = url; }); return img; }
  function productImage() {
    const url = product?.image_full || product?.image; if (!url) return Promise.resolve(null);
    if (!imageCache.has(url)) imageCache.set(url, loadImage(url).catch((error) => { imageCache.delete(url); throw error; }));
    return imageCache.get(url);
  }
  $('cv-photo').addEventListener('change', (e) => busy($('cv-upload'), async () => {
    resetScenes();
    photo = null;
    const file = e.target.files[0]; if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) throw new Error('Elige JPG, PNG o WebP de hasta 8 MB.');
    const current = ficha, url = URL.createObjectURL(file);
    try {
      const img = await loadImage(url); if (current !== ficha) return;
      photo = img; markers = []; nextMarker = 0; $('cv-original').src = url; showPhoto(); $('cv-upload').hidden = false;
    } finally { URL.revokeObjectURL(url); }
    message('Foto preparada. Pulsa «Subir a la web» para ponerla en la tienda.');
    preview();
  }));
  $('cv-upload').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const file = $('cv-photo').files[0]; if (!file) throw new Error('Elige una foto.');
    let wasNew = false;
    // New merchandise: the draft is saved first, so the photo has somewhere to go.
    if (!product?.id) { wasNew = true; await save('save'); if (!product?.id) return; }
    const current = ficha, ready = await webImage(file);
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version); body.append('photo', ready, webName('foto', ready));
    const updated = await api('upload', body);
    if (current !== ficha) { message(`La foto quedó subida en «${updated.name}». La ficha que tienes abierta no cambió.`); return; }
    product = { ...updated, anuncios: product.anuncios || [] }; $('cv-upload').hidden = true; refreshHeader(); updateBar();
    message(updated.status === 'publish' ? 'Foto subida y protegida. La foto ya aparece en la tienda.'
      : `${wasNew ? 'Borrador guardado. ' : ''}Foto subida. ${updated.falta ? `Falta: ${updated.falta}.` : 'Ya puedes publicarlo.'}`);
  }));

  /* ---------- Dibujo del anuncio (1080 px) ---------- */
  function wrapped(ctx, text, x, y, width, size, maxLines = 3, color = '#173e35', weight = 600) {
    ctx.fillStyle = color; ctx.font = `${weight} ${size}px ${FONT}`; ctx.textAlign = 'left';
    const words = String(text).split(/\s+/); let line = '', lines = [];
    for (const word of words) { const next = line ? `${line} ${word}` : word; if (ctx.measureText(next).width > width && line) { lines.push(line); line = word; } else line = next; }
    if (line) lines.push(line);
    if (lines.length > maxLines || lines.some((s) => ctx.measureText(s).width > width)) throw new Error('Acorta el título o las características para que se lean completos.');
    lines.forEach((s, i) => ctx.fillText(s, x, y + i * size * 1.25)); return lines.length * size * 1.25;
  }
  function drawContained(ctx, img, x, y, w, h) { const scale = Math.min(w / img.width, h / img.height); const iw = img.width * scale, ih = img.height * scale; ctx.drawImage(img, x + (w - iw) / 2, y + (h - ih) / 2, iw, ih); }
  // Review card: only real reviews stored on the product (web reviews or comments saved with the customer's permission).
  // Shown with first name and surname initial: «María González» → «María G.».
  const shortName = (name) => { const parts = name.trim().split(/\s+/); return parts.length < 2 || /[.,]/.test(parts[1]) ? name.trim() : `${parts[0]} ${parts[1][0].toUpperCase()}.`; };
  const stars = (rating) => rating ? '★'.repeat(rating) + '☆'.repeat(5 - rating) : '';
  function drawReview(ctx, img, review, h) {
    const square = h === 1080, line = '#173e35';
    const box = square ? { x: 64, y: 290, w: 400, h: 560 } : { x: 64, y: 300, w: 952, h: 620 };
    ctx.fillStyle = '#fff'; ctx.fillRect(box.x, box.y, box.w, box.h); drawContained(ctx, img, box.x + 20, box.y + 20, box.w - 40, box.h - 40);
    const left = square ? 500 : 64, width = square ? 516 : 952, size = square ? 30 : 44;
    let y = square ? 330 : 990;
    ctx.fillStyle = line; ctx.font = `700 ${square ? 110 : 150}px ${FONT}`; ctx.textAlign = 'left'; ctx.fillText('“', left - 6, y + (square ? 60 : 80));
    y += square ? 90 : 120;
    try { y += wrapped(ctx, review.text, left, y, width, size, square ? 7 : 6, line, 400) + (square ? 20 : 30); }
    catch { throw new Error('La reseña es demasiado larga para la tarjeta. Elige otra más corta.'); }
    if (review.rating) { ctx.fillStyle = '#c99a1e'; ctx.font = `${square ? 34 : 48}px ${FONT}`; ctx.fillText(stars(review.rating), left, y); y += square ? 50 : 70; }
    wrapped(ctx, `— ${shortName(review.name)}`, left, y, width, square ? 26 : 34, 1);
    if (y > h - 190) throw new Error('La reseña es demasiado larga para la tarjeta. Elige otra más corta.');
  }
  // Offer card: only real data. Store price (and a store sale with its end date) plus the editor's current offer.
  const saleUntil = (date) => new Date(`${date}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'long' });
  function drawOffer(ctx, h, y) {
    if (!product?.price) throw new Error('La tarjeta de oferta necesita el precio del catálogo comercial.');
    const square = h === 1080, sale = product.sale, offer = $('cv-offer').value.trim(), big = square ? 64 : 96, small = square ? 26 : 34;
    const price = sale ? sale.price : product.price, priceY = y + big * 0.8;
    ctx.fillStyle = '#173e35'; ctx.font = `700 ${big}px ${FONT}`; ctx.textAlign = 'left'; ctx.fillText(price, 64, priceY);
    let next = priceY + 16;
    if (sale) {
      const x = 64 + ctx.measureText(price).width + 28;
      ctx.fillStyle = '#6b7a74'; ctx.font = `400 ${small + 6}px ${FONT}`; ctx.fillText(sale.regular, x, priceY);
      ctx.fillRect(x, priceY - (small + 6) * 0.35, ctx.measureText(sale.regular).width, 3);
      if (sale.until && sale.until >= new Date().toLocaleDateString('en-CA')) next += wrapped(ctx, `Oferta hasta el ${saleUntil(sale.until)}`, 64, next + small, 952, small, 1);
    }
    if (offer) {
      const lines = square ? 1 : 2, top = next + 12;
      ctx.fillStyle = '#e4eee8'; ctx.fillRect(64, top, 952, lines * small * 1.25 + 24);
      wrapped(ctx, offer, 84, top + 12 + small, 912, small, lines);
    }
  }
  // Callout diagram: product box, and where the explanations go (below in 9:16, at the right in 1:1).
  const featureBox = (h) => h === 1080 ? { x: 64, y: 290, w: 500, h: 560 } : { x: 64, y: 380, w: 952, h: 780 };
  function drawFeatures(ctx, img, rows, h) {
    const box = featureBox(h), square = h === 1080, line = '#173e35';
    ctx.fillStyle = '#fff'; ctx.fillRect(box.x, box.y, box.w, box.h); drawContained(ctx, img, box.x + 20, box.y + 20, box.w - 40, box.h - 40);
    // «Característica — para qué sirve»: the part after a spaced dash is the everyday benefit.
    const items = rows.map((s) => { const m = s.match(/^(.*?)\s+[—–-]\s+(.+)$/); return m ? { fact: m[1], benefit: m[2] } : { fact: s, benefit: '' }; });
    const left = square ? 600 : 64, width = square ? 416 : 952, factSize = square ? 28 : 34, benefitSize = square ? 24 : 28;
    let top = square ? box.y + 10 : box.y + box.h + 50;
    const anchors = items.map((item, i) => {
      const factY = top + factSize, factH = wrapped(ctx, item.fact, left + 60, factY, width - 60, factSize, 2);
      const benefitH = item.benefit ? wrapped(ctx, item.benefit, left + 60, factY + factH + 2, width - 60, benefitSize, 2, '#4b5b55', 400) : 0;
      ctx.beginPath(); ctx.arc(left + 22, top + 22, 22, 0, Math.PI * 2); ctx.fillStyle = line; ctx.fill();
      ctx.fillStyle = '#fff'; ctx.font = `bold 24px ${FONT}`; ctx.textAlign = 'center'; ctx.fillText(String(i + 1), left + 22, top + 31); ctx.textAlign = 'left';
      const anchor = top + 22; top = factY + factH + benefitH + 22;
      if (top > h - 190) throw new Error('Acorta las características para que se lean completas.');
      return anchor;
    });
    // Thin line from each tapped point to its explanation, routed along the margin so it does not cover the product.
    // Each line has its own lane so two lines never share a segment.
    ctx.strokeStyle = line; ctx.lineWidth = 3;
    items.forEach((item, i) => {
      const point = markers[i]; if (!point) return;
      const x = point.x * 1080, y = point.y * h, ay = anchors[i];
      ctx.beginPath(); ctx.moveTo(x, y);
      if (square) { const lane = 572 + i * 10; ctx.lineTo(lane, y); ctx.lineTo(lane, ay); ctx.lineTo(left, ay); }
      else { const lane = x < 540 ? 24 + i * 14 : 1056 - i * 14; ctx.lineTo(lane, y); ctx.lineTo(lane, ay); ctx.lineTo(x < 540 ? left : left + width, ay); }
      ctx.stroke();
    });
    items.forEach((item, i) => {
      const point = markers[i]; if (!point) return;
      const x = point.x * 1080, y = point.y * h;
      ctx.beginPath(); ctx.arc(x, y, 22, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill(); ctx.strokeStyle = line; ctx.lineWidth = 4; ctx.stroke();
      ctx.fillStyle = line; ctx.font = `bold 26px ${FONT}`; ctx.textAlign = 'center'; ctx.fillText(String(i + 1), x, y + 9); ctx.textAlign = 'left';
    });
  }
  async function drawNow() {
    const img = scenePhoto || photo || await productImage(); if (!img) throw new Error('Sube una foto real para empezar.');
    const layout = layoutValue, rows = facts(), h = Number(formatValue);
    if (['features', 'spec'].includes(layout) && !rows.length) throw new Error('Añade al menos una característica confirmada.');
    if (layout === 'use' && !$('cv-use').value.trim()) throw new Error('Describe el uso cotidiano confirmado.');
    const review = pickedReview();
    if (layout === 'review' && !review) throw new Error('Elige o añade una reseña real del cliente.');
    const c = $('cv-canvas'); c.height = h; const ctx = c.getContext('2d');
    ctx.fillStyle = '#f6f2e9'; ctx.fillRect(0, 0, 1080, h); ctx.fillStyle = '#173e35'; ctx.font = `600 26px ${FONT}`; ctx.fillText('CASA VIVA', 64, 70);
    const title = $('cv-headline').value.trim() || $('cv-name').value.trim();
    wrapped(ctx, title, 64, 150, 952, h === 1080 ? 44 : 58, 3);
    const imageTop = h === 1080 ? 300 : 400, imageH = h === 1080 ? 370 : 820;
    if (layout === 'features') drawFeatures(ctx, img, rows, h);
    else if (layout === 'review') drawReview(ctx, img, review, h);
    else { ctx.fillStyle = '#fff'; ctx.fillRect(64, imageTop, 952, imageH); drawContained(ctx, img, 88, imageTop + 24, 904, imageH - 48); }
    let y = imageTop + imageH + 70;
    if (layout === 'spec') rows.forEach((s, i) => { y += wrapped(ctx, `${i + 1}. ${s}`, 64, y, 952, h === 1080 ? 27 : 38, 2) + 16; });
    if (layout === 'offer') drawOffer(ctx, h, y);
    if (layout === 'use') wrapped(ctx, $('cv-use').value, 64, y, 952, 38, 3);
    ctx.fillStyle = '#173e35'; ctx.fillRect(64, h - 160, 952, 90); wrapped(ctx, 'VER EN CASA VIVA', 105, h - 103, 870, 32, 1, '#fff');
  }
  // Draws run one after another, so the canvas always ends with the latest choices.
  let drawing = Promise.resolve();
  function draw() { const run = drawing.then(drawNow, drawNow); drawing = run.catch(() => {}); return run; }
  const staleBlocks = () => Boolean(selectedOption?.stale && !selectedOption.compared);
  function showCanvas(error) {
    const c = $('cv-canvas'), note = $('cv-preview-note');
    c.hidden = !(scenePhoto || photo || product?.image);
    c.classList.toggle('cv-square', formatValue === '1080'); c.classList.toggle('cv-dim', Boolean(error));
    const text = staleBlocks() ? 'Compárala antes de usarla en el anuncio.' : (error || '');
    note.textContent = text; note.hidden = !text;
    updateHint();
  }
  function updateHint() {
    const n = facts().length, hint = $('cv-point-hint');
    hint.hidden = layoutValue !== 'features' || !n || $('cv-canvas').hidden;
    if (!hint.hidden) hint.textContent = `Toca la foto: número ${Math.min(nextMarker, n - 1) + 1}`;
  }
  let previewTimer = 0;
  function preview(delay = 250) { clearTimeout(previewTimer); previewTimer = setTimeout(runPreview, delay); }
  async function runPreview() {
    if ($('cv-editor').hidden || $('cv-panel-anuncio').hidden) return;
    const current = ficha;
    try { await draw(); if (current === ficha) showCanvas(''); } catch (error) { if (current === ficha) showCanvas(error.message); }
  }

  /* ---------- Plantilla, formato y puntos ---------- */
  function syncAnuncio() {
    for (const b of $('cv-layout').querySelectorAll('[role=radio]')) b.setAttribute('aria-checked', String(b.dataset.value === layoutValue));
    for (const b of $('cv-format').querySelectorAll('[role=radio]')) b.setAttribute('aria-checked', String(b.dataset.value === formatValue));
    document.querySelectorAll('[data-for]').forEach((el) => { el.hidden = el.dataset.for !== layoutValue; });
    showPoints();
  }
  function radioKeys(group, pick) {
    group.addEventListener('keydown', (e) => {
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
      const items = [...group.querySelectorAll('[role=radio]')], i = items.indexOf(document.activeElement); if (i < 0) return;
      e.preventDefault(); const next = items[(i + (['ArrowRight', 'ArrowDown'].includes(e.key) ? 1 : items.length - 1)) % items.length]; next.focus(); pick(next);
    });
  }
  const pickLayout = (b) => { layoutValue = b.dataset.value; syncAnuncio(); preview(0); };
  const pickFormat = (b) => { formatValue = b.dataset.value; syncAnuncio(); preview(0); };
  $('cv-layout').addEventListener('click', (e) => { const b = e.target.closest('[role=radio]'); if (b) pickLayout(b); });
  $('cv-format').addEventListener('click', (e) => { const b = e.target.closest('[role=radio]'); if (b) pickFormat(b); });
  radioKeys($('cv-layout'), pickLayout); radioKeys($('cv-format'), pickFormat);
  function showPoints() {
    const rows = facts(), box = $('cv-points');
    if (nextMarker >= rows.length) nextMarker = 0;
    if (!rows.length) { const p = document.createElement('p'); p.className = 'cv-empty'; p.textContent = 'Añade características en Ficha.'; box.replaceChildren(p); return; }
    box.replaceChildren(...rows.map((s, i) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'cv-point'; b.dataset.point = String(i); b.setAttribute('aria-pressed', String(i === nextMarker));
      const num = document.createElement('span'); num.className = 'cv-num'; num.textContent = String(i + 1);
      const label = document.createElement('span'); label.textContent = s.replace(/\s+[—–-]\s+.+$/, '');
      const state = document.createElement('em'); state.textContent = markers[i] ? 'Colocado' : 'Sin colocar';
      b.append(num, label, state); return b;
    }));
  }
  $('cv-points').addEventListener('click', (e) => {
    const b = e.target.closest('[data-point]'); if (!b) return;
    nextMarker = Number(b.dataset.point); showPoints(); updateHint();
    $('cv-canvas').scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  });
  $('cv-canvas').addEventListener('click', async (e) => {
    const n = facts().length;
    if (layoutValue !== 'features' || !n) return;
    const c = e.currentTarget, rect = c.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width, y = (e.clientY - rect.top) / rect.height;
    const h = c.height, box = featureBox(h);
    if (x < box.x / 1080 || x > (box.x + box.w) / 1080 || y < box.y / h || y > (box.y + box.h) / h) return;
    const placed = Math.min(nextMarker, n - 1); markers[placed] = { x, y };
    const free = [...Array(n).keys()].find((i) => !markers[i]);
    nextMarker = free ?? (placed + 1) % n; showPoints();
    const current = ficha;
    try { await draw(); if (current === ficha) showCanvas(''); } catch (error) { if (current === ficha) showCanvas(error.message); }
  });

  /* ---------- Reseñas reales ---------- */
  function showReviews(reviews) {
    $('cv-reviews-count').textContent = reviews.length ? String(reviews.length) : '';
    $('cv-reviews').replaceChildren(...reviews.map((r) => {
      const card = document.createElement('div'); card.className = 'cv-review';
      if (r.rating) { const s = document.createElement('span'); s.className = 'cv-stars-line'; s.textContent = stars(r.rating); s.setAttribute('aria-label', `${r.rating} de 5`); card.append(s); }
      const q = document.createElement('q'); q.textContent = r.text;
      const who = document.createElement('small'); who.textContent = `— ${shortName(r.name)}`;
      const tag = document.createElement('span'); tag.className = 'cv-tag'; tag.textContent = r.origin === 'whatsapp' ? 'WhatsApp' : r.origin === 'bot' ? 'Bot' : 'Web';
      card.append(q, who, tag); return card;
    }));
    const pick = $('cv-review-pick');
    if (!reviews.length) { reviewPick = ''; const p = document.createElement('p'); p.className = 'cv-empty'; p.textContent = 'Aún no hay reseñas reales'; pick.replaceChildren(p); return; }
    if (!reviews.some((r) => String(r.id) === reviewPick)) reviewPick = String(reviews[0].id);
    pick.replaceChildren(...reviews.map((r) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'cv-pick'; b.setAttribute('role', 'radio'); b.dataset.id = String(r.id);
      b.setAttribute('aria-checked', String(String(r.id) === reviewPick));
      b.textContent = `${stars(r.rating)} «${r.text.slice(0, 40)}${r.text.length > 40 ? '…' : ''}» — ${shortName(r.name)}`.trim();
      return b;
    }));
  }
  $('cv-review-pick').addEventListener('click', (e) => {
    const b = e.target.closest('[role=radio]'); if (!b) return;
    reviewPick = b.dataset.id; for (const x of $('cv-review-pick').querySelectorAll('[role=radio]')) x.setAttribute('aria-checked', String(x === b)); preview(0);
  });
  radioKeys($('cv-review-pick'), (b) => b.click());
  const pickedReview = () => (product?.reviews || []).find((r) => String(r.id) === reviewPick);
  function setStars(n) {
    $('cv-review-rating').value = String(n);
    for (const b of $('cv-review-stars').querySelectorAll('[data-stars]')) { const v = Number(b.dataset.stars); b.classList.toggle('cv-on', v <= n); b.setAttribute('aria-pressed', String(v === n)); }
    $('cv-review-stars-label').textContent = n ? `${n} de 5` : 'Sin estrellas';
  }
  $('cv-review-stars').addEventListener('click', (e) => { const b = e.target.closest('[data-stars]'); if (!b) return; const n = Number(b.dataset.stars); setStars(Number($('cv-review-rating').value) === n ? 0 : n); });
  for (const id of ['cv-review-open', 'cv-review-goto']) $(id).addEventListener('click', (e) => openSheet('cv-review-sheet', e.currentTarget));
  $('cv-review-save').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    if (!product?.id) throw new Error('Guarda primero el borrador.');
    const current = ficha, result = await api('resena', { id: product.id, text: $('cv-review-text').value, name: $('cv-review-name').value,
      rating: $('cv-review-rating').value, permission: $('cv-review-permission').checked ? '1' : '0' });
    if (current !== ficha) return;
    product.reviews = result.reviews; reviewPick = String(result.saved); showReviews(result.reviews);
    $('cv-review-text').value = ''; $('cv-review-name').value = ''; setStars(0); $('cv-review-permission').checked = false;
    closeSheet(); preview(0);
    message('Reseña guardada. Ya aparece en la tienda y puedes usarla en la tarjeta.');
  }));

  /* ---------- Fotos para el anuncio: tu foto, opciones con IA, guardada y del móvil ---------- */
  let imageURL = '';
  async function jpeg(img, limit, quality = 0.92) {
    const c = document.createElement('canvas'), scale = Math.min(1, limit / Math.max(img.width, img.height));
    c.width = Math.max(1, Math.round(img.width * scale)); c.height = Math.max(1, Math.round(img.height * scale));
    const ctx = c.getContext('2d'); ctx.fillStyle = 'white'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
    const blob = await new Promise(resolve => c.toBlob(resolve, 'image/jpeg', quality));
    if (!blob) throw new Error('No se pudo preparar la foto.');
    return blob;
  }
  // Photos for the web: WebP up to 1600 px; light JPEG where the browser cannot write WebP.
  async function webImage(source) {
    let img = source, url = '';
    if (source instanceof Blob) { url = URL.createObjectURL(source); }
    try {
      if (url) img = await loadImage(url);
      const width = img.naturalWidth || img.width, height = img.naturalHeight || img.height;
      const c = document.createElement('canvas'), scale = Math.min(1, 1600 / Math.max(width, height));
      c.width = Math.max(1, Math.round(width * scale)); c.height = Math.max(1, Math.round(height * scale));
      const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, c.width, c.height);
      let blob = await new Promise(resolve => c.toBlob(resolve, 'image/webp', 0.85));
      if (!blob || blob.type !== 'image/webp') {
        ctx.globalCompositeOperation = 'destination-over'; ctx.fillStyle = 'white'; ctx.fillRect(0, 0, c.width, c.height);
        blob = await new Promise(resolve => c.toBlob(resolve, 'image/jpeg', 0.88));
      }
      if (!blob) throw new Error('No se pudo preparar la foto.');
      return blob;
    } finally { if (url) URL.revokeObjectURL(url); }
  }
  const webName = (base, blob) => `${base}.${blob.type === 'image/webp' ? 'webp' : 'jpg'}`;
  async function publishVariant(source, session) {
    if (session !== generationSession) return;
    if (!product?.id) throw new Error('Guarda primero el borrador del producto.');
    const name = product.name;
    if (!confirm(`¿Usar esta imagen como principal de «${name}»? Si está publicado, aparecerá en la tienda.`)) return;
    const blob = await webImage(source);
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version);
    body.append('source', 'ai'); body.append('photo', blob, webName('casa-viva-ia', blob));
    const updated = await api('upload', body);
    if (session !== generationSession) return;
    product = { ...updated, anuncios: product.anuncios || [] }; refreshHeader();
    if (updated.image) { $('cv-original').src = updated.image; showPhoto(); }
    $('cv-ai-status').textContent = 'Imagen elegida guardada como principal.';
    message('Imagen elegida guardada como principal.');
  }
  function markOptions() {
    $('cv-photo-own').setAttribute('aria-checked', String(!selectedOption));
    for (const b of $('cv-variants').querySelectorAll('[role=radio]')) b.setAttribute('aria-checked', String(b.option === selectedOption));
  }
  function selectOption(option) {
    selectedOption = option;
    scenePhoto = option && (!option.stale || option.compared) ? option.img : null;
    markOptions(); optionPanel(); preview(0);
  }
  $('cv-photo-own').addEventListener('click', () => selectOption(null));
  // changed: null for a current option; for an option saved before the product changed, what changed ([] if unknown).
  function showVariant(img, finalURL, session, saved = false, changed = null, label = '') {
    const number = $('cv-variants').children.length + 1;
    const option = { img, url: finalURL, session, saved, stale: Boolean(saved && changed), changed, compared: false, number };
    img.alt = `Opción ${number}`;
    const b = document.createElement('button'); b.type = 'button'; b.className = 'cv-opt'; b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false'); b.option = option;
    const pic = document.createElement('span'); pic.className = 'cv-opt-pic';
    const thumb = document.createElement('img'); thumb.src = finalURL; thumb.alt = ''; pic.append(thumb);
    if (saved) { const flag = document.createElement('span'); flag.className = option.stale ? 'cv-flag cv-flag-gold' : 'cv-flag'; flag.textContent = option.stale ? 'Revisar' : 'Guardada'; pic.append(flag); }
    const name = document.createElement('span'); name.textContent = label || (saved ? 'Guardada' : `Opción ${number}`); b.setAttribute('aria-label', name.textContent);
    b.append(pic, name); b.addEventListener('click', () => selectOption(option));
    $('cv-variants').append(b);
    return option;
  }
  radioKeys(document.querySelector('.cv-options'), (b) => b.click());
  function optionPanel() {
    const panel = $('cv-option-panel'), option = selectedOption;
    panel.hidden = !option; panel.replaceChildren(); if (!option) return;
    const web = document.createElement('button'); web.type = 'button'; web.className = 'cv-btn cv-small'; web.id = 'cv-option-web'; web.textContent = 'Poner como foto de la web';
    web.onclick = () => busy(web, async () => publishVariant(option.img, option.session));
    if (option.stale) {
      const what = { foto: 'la foto original', texto: 'el texto de la ficha' }, reasons = option.changed.map((key) => what[key]).filter(Boolean);
      const note = document.createElement('p'); note.className = 'cv-warn';
      note.textContent = (reasons.length ? `Desde entonces cambió ${reasons.join(' y ')}.` : 'La ficha cambió desde que guardaste esta opción.') + ' Compárala con los datos actuales antes de usarla.';
      const check = document.createElement('label'), box = document.createElement('input'), text = document.createElement('span');
      check.className = 'cv-switch'; box.type = 'checkbox'; box.checked = option.compared; text.textContent = 'Ya la comparé con la ficha actual';
      check.append(box, text);
      web.disabled = !option.compared;
      // busy() re-enables the button when it finishes; keep it locked while the option is not compared.
      web.onclick = () => { if (option.stale && !option.compared) return; busy(web, async () => publishVariant(option.img, option.session)).then(() => { web.disabled = !option.compared; }); };
      box.addEventListener('change', () => { option.compared = box.checked; web.disabled = !box.checked; scenePhoto = box.checked ? option.img : null; preview(0); });
      panel.append(note, check);
    }
    const actions = document.createElement('div'); actions.className = 'cv-actions'; actions.append(web);
    if (!option.saved && product?.id) {
      const keep = document.createElement('button'); keep.type = 'button'; keep.className = 'cv-btn cv-small'; keep.textContent = 'Guardar para revisar';
      keep.onclick = () => busy(keep, async () => {
        if (option.session !== generationSession) return;
        const body = new FormData(); body.append('id', product.id); body.append('version', product.version);
        // High-quality JPEG keeps a 1080 px option within the private review limit.
        body.append('photo', await jpeg(option.img, 1920, 0.95), 'opcion.jpg');
        await api('review', body);
        if (option.session === generationSession) { option.saved = true; keep.remove(); $('cv-ai-status').textContent = 'Opción guardada en tu perfil para este producto.'; }
      }); actions.append(keep);
    }
    const download = document.createElement('a'); download.className = 'cv-link'; download.href = option.url; download.download = `casa-viva-opcion-${option.number}`; download.textContent = 'Descargar sin textos';
    actions.append(download); panel.append(actions);
  }
  // A photo from the phone for this ad only (also how a result made in ChatGPT or Higgsfield comes back).
  $('cv-import-ai').addEventListener('change', () => {
    const file = $('cv-import-ai').files[0];
    if (!file || !['image/png', 'image/webp', 'image/jpeg'].includes(file.type) || file.size > 8 * 1024 ** 2) {
      $('cv-import-ai').value = ''; message('Elige JPG, PNG o WebP de hasta 8 MB.'); return;
    }
    const session = generationSession, url = URL.createObjectURL(file);
    loadImage(url).then((img) => {
      if (session !== generationSession) return;
      selectOption(showVariant(img, url, session, false, null, 'Del móvil')); message('Foto añadida al anuncio.');
    }).catch(() => message('No se pudo abrir la imagen.'));
    $('cv-import-ai').value = '';
  });
  const providerSelect = $('cv-provider');
  for (const option of providerSelect.options) option.disabled = !cvContent.providers[option.value];
  const enabled = Array.from(providerSelect.options).filter(option => !option.disabled);
  if (enabled.length) providerSelect.value = enabled[0].value;
  $('cv-provider-wrap').hidden = enabled.length < 2;
  $('cv-generate').disabled = !enabled.length;
  $('cv-generate-label').textContent = enabled.length ? 'Fondo IA' : 'IA apagada';
  $('cv-generate').addEventListener('click', (e) => openSheet('cv-ai-sheet', e.currentTarget));
  $('cv-ai-create').addEventListener('click', () => { closeSheet(); busy($('cv-generate'), async () => {
    const session = generationSession, provider = providerSelect.value;
    const real = photo || (product?.image ? await loadImage(product.image_full || product.image) : null);
    if (!real) throw new Error('Elige la foto real del producto antes de generar.');
    if (session !== generationSession) return;
    if (provider !== 'cloudflare' && !confirm('¿Generar una imagen con la API de pago seleccionada? Se usará su saldo.')) return;
    const format = formatValue;
    const protectWhole = $('cv-protect-whole').checked;
    const body = {mode: 'background', provider, paid: provider === 'cloudflare' ? '0' : '1', layout: layoutValue, format};
    if (session !== generationSession) return;
    const status = $('cv-ai-status'); status.textContent = 'Generando una opción…';
    try {
      let result = await api('ia/generate', body);
      const deadline = Date.now() + 12 * 60 * 1000;
      while (result.job && session === generationSession && Date.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 4000));
        if (session !== generationSession) return;
        result = await api('ia/poll', {job: result.job});
      }
      if (session !== generationSession) return;
      if (!result.background) throw new Error('La imagen sigue pendiente. Consulta el historial del proveedor antes de repetir.');
      const bg = await loadImage(result.background);
      const composition = cvProductBackground(real, bg, format === '1920', protectWhole);
      const finalURL = composition.toDataURL('image/png');
      const img = await loadImage(finalURL);
      if (session !== generationSession) return;
      selectOption(showVariant(img, finalURL, session)); status.textContent = 'Revisa que coincidan el color, la forma y los detalles.';
    } catch (error) { if (session === generationSession) status.textContent = error.message; }
  }); });

  /* ---------- Terminar: imagen final, pie y enlace del anuncio ---------- */
  $('cv-render').addEventListener('click', (e) => { const button = e.currentTarget; busy(button, async () => {
    if (staleBlocks()) throw new Error('Compara la opción guardada con la ficha actual antes de usarla.');
    $('cv-download').removeAttribute('href');
    const current = ficha;
    try { await draw(); } catch (error) { showCanvas(error.message); throw error; }
    if (current !== ficha) return;
    showCanvas('');
    // Social downloads go out at maximum JPEG quality; the networks compress them again.
    const blob = await new Promise((resolve) => $('cv-canvas').toBlob(resolve, 'image/jpeg', 1)); if (!blob) throw new Error('No se pudo preparar la imagen.');
    if (imageURL) URL.revokeObjectURL(imageURL); imageURL = URL.createObjectURL(blob); $('cv-download').href = imageURL;
    if (!copyEdited) buildCopy();
    openSheet('cv-pie-sheet', button);
    message('Imagen lista para descargar.');
  }); });
  function buildCopy() {
    const rows = facts(), title = $('cv-name').value.trim(), usage = $('cv-use').value.trim();
    const link = product?.status === 'publish' ? product.url : '';
    const sale = product?.sale, offer = $('cv-offer').value.trim();
    const priceLine = sale ? `Precio de oferta: ${sale.price} (antes ${sale.regular})` : product?.price ? `Precio actual: ${product.price}` : '';
    const review = layoutValue === 'review' ? pickedReview() : null;
    $('cv-copy').value = [title, ...rows.map((s) => `• ${s}`), usage, priceLine, offer ? `Oferta: ${offer}` : '', review ? `«${review.text}» — ${shortName(review.name)}` : '', link ? `Míralo en Casa Viva: ${link}` : 'Consulta en Casa Viva.'].filter(Boolean).join('\n');
  }
  function buildPrompt() {
    const rows = facts(), title = $('cv-name').value.trim(), usage = $('cv-use').value.trim();
    $('cv-prompt').value = `Crea una imagen comercial para Casa Viva, formato ${formatValue === '1920' ? '9:16' : '1:1'}. Usa la foto real adjunta como referencia exacta. Conserva forma, color, etiquetas, marca, piezas y proporciones. No inventes vistas interiores ni accesorios incluidos. Estilo minimalista cálido, beige y verde oscuro, luz suave. Producto: ${title}. Datos confirmados: ${rows.join('; ') || plain($('cv-description').value)}. Uso confirmado: ${usage || 'no indicado'}. Deja espacio para añadir textos después. No dibujes precios, descuentos, estrellas, testimonios ni garantías. Si falta información, pregunta. Devuelve además tres títulos breves y una descripción usando solo estos datos. Revisa PROOF: promesa sustentada, evidencia real, oferta vigente, objeción y siguiente paso. No presentes el resultado como fotografía documental si modificas el producto.`;
    $('cv-prompt').value += `\nContexto aportado por la tienda (no investigación independiente): comprador/situación: ${briefExtra.buyer || 'pendiente'}; objeción: ${briefExtra.objection || 'pendiente'}; evidencia: ${briefExtra.evidence || 'pendiente'}. Investiga antes de generar si estas fuentes no bastan para sustentar el mensaje. No deduzcas ventas por la duración o alcance de un anuncio competidor.`;
  }
  async function copyText(text) {
    try { await Promise.race([navigator.clipboard.writeText(text), new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), 1500))]); return true; } catch { return false; }
  }
  // Ads measured by the code in their WhatsApp link: who wrote, and orders from them in the next 14 days.
  const adDate = (date) => new Date(`${date}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'short' });
  function showAds(ads) {
    $('cv-ads-count').textContent = ads.length ? ` ${ads.length}` : '';
    const total = $('cv-ads-total'); total.replaceChildren();
    if (!ads.length) {
      const empty = document.createElement('p'); empty.className = 'cv-empty'; empty.textContent = 'Aún no hay anuncios.';
      const go = document.createElement('button'); go.type = 'button'; go.className = 'cv-btn cv-primary cv-small'; go.textContent = 'Crear anuncio'; go.onclick = () => setTab('anuncio');
      total.append(empty, go); $('cv-ads').replaceChildren(); return;
    }
    const sum = (key) => ads.reduce((n, a) => n + Number(a[key] || 0), 0);
    for (const [n, label] of [[sum('mensajes'), 'escribieron'], [sum('pedidos'), 'pedidos']]) { const d = document.createElement('div'), b = document.createElement('b'), s = document.createElement('small'); b.textContent = String(n); s.textContent = label; d.append(b, s); total.append(d); }
    const best = [...ads].sort((a, b) => b.pedidos - a.pedidos || b.mensajes - a.mensajes)[0];
    $('cv-ads').replaceChildren(...ads.map((a) => {
      const li = document.createElement('li'), isBest = a === best && a.mensajes > 0; if (isBest) li.className = 'cv-best';
      const head = document.createElement('div'); head.className = 'cv-ad-head';
      const code = document.createElement('span'); code.className = 'cv-code'; code.textContent = a.code;
      const what = document.createElement('b'); what.textContent = `${LAYOUTS[a.layout] || 'Anuncio'} · ${a.format === '1080' ? '1:1' : '9:16'}`;
      head.append(code, ' ', what);
      if (isBest) { const tag = document.createElement('span'); tag.className = 'cv-best-tag'; tag.textContent = 'Mejor'; head.append(' ', tag); }
      const when = document.createElement('small'); when.textContent = adDate(a.created); head.append(' ', when);
      const nums = document.createElement('div'); nums.className = 'cv-ad-nums';
      const num = (value, label) => { const s = document.createElement('span'), b = document.createElement('b'); b.textContent = String(value); s.append(b, ` ${label} `); return s; };
      nums.append(num(a.mensajes, a.mensajes === 1 ? 'escribió' : 'escribieron'), num(a.pedidos, a.pedidos === 1 ? 'pedido' : 'pedidos'));
      if (a.total) nums.append(num(a.total, 'vendido'));
      const again = document.createElement('button'); again.type = 'button'; again.className = 'cv-btn cv-small'; again.textContent = 'Repetir';
      again.onclick = () => { if (LAYOUTS[a.layout]) layoutValue = a.layout; if (['1920', '1080'].includes(a.format)) formatValue = a.format; setTab('anuncio'); message('Misma plantilla y formato. Cambia la foto o la frase.'); };
      nums.append(again); li.append(head, nums); return li;
    }));
  }
  $('cv-ad-link').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    if (!product?.id) throw new Error('Guarda primero el borrador.');
    const current = ficha, result = await api('anuncio', { id: product.id, layout: layoutValue, format: formatValue });
    if (current !== ficha) return;
    product.anuncios = result.anuncios; showAds(result.anuncios);
    const line = `Escríbenos por WhatsApp: ${result.link}`;
    $('cv-copy').value = $('cv-copy').value.split('\n').filter((l) => !l.startsWith('Escríbenos por WhatsApp: ')).concat(line).join('\n').trim();
    const copied = await copyText($('cv-copy').value);
    if ($('cv-download').getAttribute('href')) $('cv-download').click();
    message(`Anuncio ${result.code} creado. ${copied ? 'Imagen descargada y pie copiado.' : 'Imagen descargada; copia el pie a mano.'} El enlace va solo en el pie.`);
  }));
  function downloadTexts(withPrompt) {
    if (!copyEdited && !$('cv-copy').value.trim()) buildCopy();
    if (withPrompt) buildPrompt();
    // Paragraph and line breaks of the description survive as new lines.
    const description = plain($('cv-description').value.replace(/<\/(p|li|h[1-6])>|<br\s*\/?>/gi, '$&\n')).replace(/\n{3,}/g, '\n\n').trim();
    const sections = [['TÍTULO', $('cv-name').value.trim()], ['DESCRIPCIÓN', description], ['TEXTO PARA REDES', $('cv-copy').value.trim()]];
    if (withPrompt) sections.push(['INSTRUCCIÓN PARA IA', $('cv-prompt').value.trim()]);
    const text = sections.filter(([, body]) => body).map(([head, body]) => `${head}\n${body}`).join('\n\n') + '\n';
    const url = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'})); const a = document.createElement('a'); a.href = url; a.download = 'casa-viva-textos.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  $('cv-copy-save').addEventListener('click', () => { downloadTexts(false); closeSheet(); });
  $('cv-copy-save-prompt').addEventListener('click', () => downloadTexts(true));
  $('cv-prompt-open').addEventListener('click', () => { buildPrompt(); openSheet('cv-prompt-sheet', $('cv-menu')); });
  $('cv-prompt-copy').addEventListener('click', async () => {
    if (await copyText($('cv-prompt').value)) message('Instrucción copiada. Adjunta la foto real y trae el resultado con «Del móvil».');
    else { $('cv-prompt').select(); message('Mantén pulsado el texto para copiarlo.'); }
  });
  list().catch((error) => message(error.message));
})();
