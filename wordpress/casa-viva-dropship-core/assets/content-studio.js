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
  let scenePhoto = null;
  function resetScenes() {
    generationSession++;
    scenePhoto = null;
    $('cv-variants').replaceChildren();
    $('cv-ai-status').textContent = '';
    $('cv-publish-layout').hidden = true;
    $('cv-import-ai').value = '';
    $('cv-import-preview').hidden = true;
    $('cv-import-preview').removeAttribute('src');
  }
  const message = (text) => { $('cv-message').textContent = text; };
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
    button.disabled = true;
    try { await work(); } catch (error) { message(error.message); } finally { button.disabled = false; }
  }
  function fill(p) {
    ficha++;
    resetScenes();
    const review = p.review || p.review_old;
    if (review) {
      const session = generationSession, changed = p.review_old ? (p.review_changed || []) : null;
      loadImage(review).then(img => { if (session === generationSession) showVariant(img, review, session, true, changed); }).catch(() => message('No se pudo abrir la opción guardada.'));
    }
    product = p; photo = null; dirty = false; markers = []; nextMarker = 0;
    $('cv-editor').hidden = false;
    $('cv-name').value = p.name || '';
    for (const [id, html] of [['cv-description', p.description], ['cv-short', p.short]]) {
      const text = toText(html); rawHtml[id] = text === null; $(id).value = text ?? html ?? '';
    }
    $('cv-text-note').hidden = !rawHtml['cv-description'] && !rawHtml['cv-short'];
    $('cv-commercial').textContent = p.id ? `Precio actual: ${p.price || 'pendiente'} · ${p.status === 'publish' ? 'Publicado' : 'Sin publicar'}` : 'Nueva mercancía · borrador';
    $('cv-original').hidden = !p.image;
    if (p.image) $('cv-original').src = p.image; else $('cv-original').removeAttribute('src');
    $('cv-view').hidden = p.status !== 'publish'; if (p.url) $('cv-view').href = p.url;
    $('cv-photo').value = ''; $('cv-facts').value = ''; $('cv-headline').value = ''; $('cv-use').value = '';
    for (const key of ['facts', 'buyer', 'objection', 'evidence', 'use', 'headline']) $('cv-' + key).value = p.brief?.[key] || '';
    $('cv-canvas').hidden = true; $('cv-download').hidden = true; $('cv-copy').value = ''; $('cv-prompt').value = ''; $('cv-suggestion').value = '';
  }
  async function list(append = false) {
    const data = await api(`products?search=${encodeURIComponent($('cv-search').elements.search.value)}&page=${page}`);
    pages = data.pages; if (!append) $('cv-items').replaceChildren();
    for (const p of data.items) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'cv-product';
      if (p.image) { const img = document.createElement('img'); img.src = p.image; img.alt = ''; img.loading = 'lazy'; button.append(img); }
      const title = document.createElement('span'); title.textContent = `${p.name} · ${p.status === 'publish' ? 'Publicado' : 'Borrador / privado'}`; button.append(title);
      button.addEventListener('click', () => busy(button, async () => {
        if (dirty && !confirm('Tienes cambios sin guardar. ¿Abrir otro producto?')) return;
        const current = ++ficha, opened = await api(`products?id=${p.id}`);
        if (current !== ficha) return;
        fill(opened); message('Producto abierto.'); $('cv-editor').scrollIntoView({ behavior: 'smooth' });
      })); $('cv-items').append(button);
    }
    if (!data.items.length && !append) $('cv-items').textContent = 'No encontramos productos con ese nombre.';
    $('cv-more').hidden = page >= pages;
  }
  $('cv-search').addEventListener('submit', (e) => { e.preventDefault(); page = 1; busy(e.submitter, () => list()); });
  $('cv-more').addEventListener('click', (e) => busy(e.target, async () => { page++; try { await list(true); } catch (error) { page--; throw error; } }));
  $('cv-new').addEventListener('click', () => {
    if (dirty && !confirm('Tienes cambios sin guardar. ¿Crear otro producto?')) return;
    token = crypto.randomUUID(); fill({}); $('cv-name').focus();
  });
  $('cv-editor').addEventListener('input', () => { dirty = true; });
  $('cv-password').addEventListener('submit', (e) => { e.preventDefault(); busy(e.submitter, async () => {
    const result = await api('password', { current: $('cv-current-password').value, next: $('cv-next-password').value });
    dirty = false; location.assign(result.login);
  }); });
  $('cv-suggest').addEventListener('click', (e) => busy(e.target, async () => {
    const current = ficha, result = await api('suggest', { name: $('cv-name').value, facts: $('cv-facts').value });
    if (current !== ficha) return;
    $('cv-suggestion').value = result.text; message(result.note);
  }));
  $('cv-title-suggest').addEventListener('click', (e) => busy(e.target, async () => {
    const current = ficha, result = await api('suggest', { name: $('cv-name').value, facts: $('cv-facts').value, kind: 'titulo' });
    if (current !== ficha) return;
    $('cv-name').value = result.text; dirty = true; message(result.note);
  }));
  $('cv-use-suggestion').addEventListener('click', () => {
    const text = $('cv-suggestion').value.trim(); if (!text) return;
    $('cv-description').value = text; dirty = true; message('Descripción puesta en la ficha. Guarda los cambios para publicarla.');
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  async function save(action) {
    if (!$('cv-editor').reportValidity()) return;
    const current = ficha, p = await api('save', { id: product.id || 0, version: product.version || '', token, action,
      name: $('cv-name').value, description: editableText('cv-description'), short: editableText('cv-short'),
      brief: Object.fromEntries(['facts', 'buyer', 'objection', 'evidence', 'use', 'headline'].map((key) => [key, $('cv-' + key).value])) });
    if (current !== ficha) { message(`«${p.name}» quedó guardado. La ficha que tienes abierta no cambió.`); return; }
    // Keep unsaved layout work and selected local photo while refreshing server state.
    product = p; dirty = false; $('cv-commercial').textContent = `Precio actual: ${p.price || 'pendiente'} · ${p.status === 'publish' ? 'Publicado' : 'Borrador'}`;
    $('cv-view').hidden = p.status !== 'publish'; $('cv-view').href = p.url;
    message(p.status === 'publish' ? 'Cambios publicados en Casa Viva.' : 'Borrador guardado. Puedes subir la foto.');
  }
  $('cv-editor').addEventListener('submit', (e) => { e.preventDefault(); busy($('cv-save'), () => save('save')); });
  $('cv-publish').addEventListener('click', (e) => busy(e.target, () => save('publish')));
  async function loadImage(url) { const img = new Image(); img.crossOrigin = 'anonymous'; await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = () => reject(new Error('No se pudo abrir la foto. Sube una desde tu móvil.')); img.src = url; }); return img; }
  $('cv-photo').addEventListener('change', (e) => busy($('cv-upload'), async () => {
    resetScenes();
    photo = null;
    const file = e.target.files[0]; if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) throw new Error('Elige JPG, PNG o WebP de hasta 8 MB.');
    const current = ficha, url = URL.createObjectURL(file);
    try {
      const img = await loadImage(url); if (current !== ficha) return;
      photo = img; markers = []; nextMarker = 0; $('cv-original').src = url; $('cv-original').hidden = false;
    } finally { URL.revokeObjectURL(url); }
    message('Foto preparada. Pulsa «Subir como foto principal» para enviarla a la web.');
  }));
  $('cv-upload').addEventListener('click', (e) => busy(e.target, async () => {
    if (!product?.id) throw new Error('Guarda primero el borrador.');
    const file = $('cv-photo').files[0]; if (!file) throw new Error('Elige una foto.');
    const current = ficha, ready = await webImage(file);
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version); body.append('photo', ready, webName('foto', ready));
    const updated = await api('upload', body);
    if (current !== ficha) { message(`La foto quedó subida en «${updated.name}». La ficha que tienes abierta no cambió.`); return; }
    product = updated; message('Foto subida y protegida. Si el producto ya estaba publicado, la foto ya aparece en la tienda.');
  }));
  function wrapped(ctx, text, x, y, width, size, maxLines = 3, color = '#173e35', weight = 600) {
    ctx.fillStyle = color; ctx.font = `${weight} ${size}px Arial, sans-serif`; ctx.textAlign = 'left';
    const words = String(text).split(/\s+/); let line = '', lines = [];
    for (const word of words) { const next = line ? `${line} ${word}` : word; if (ctx.measureText(next).width > width && line) { lines.push(line); line = word; } else line = next; }
    if (line) lines.push(line);
    if (lines.length > maxLines || lines.some((s) => ctx.measureText(s).width > width)) throw new Error('Acorta el título o las características para que se lean completos.');
    lines.forEach((s, i) => ctx.fillText(s, x, y + i * size * 1.25)); return lines.length * size * 1.25;
  }
  function drawContained(ctx, img, x, y, w, h) { const scale = Math.min(w / img.width, h / img.height); const iw = img.width * scale, ih = img.height * scale; ctx.drawImage(img, x + (w - iw) / 2, y + (h - ih) / 2, iw, ih); }
  function facts() { return $('cv-facts').value.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 3); }
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
      ctx.fillStyle = '#fff'; ctx.font = 'bold 24px Arial'; ctx.textAlign = 'center'; ctx.fillText(String(i + 1), left + 22, top + 31); ctx.textAlign = 'left';
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
      ctx.fillStyle = line; ctx.font = 'bold 26px Arial'; ctx.textAlign = 'center'; ctx.fillText(String(i + 1), x, y + 9); ctx.textAlign = 'left';
    });
  }
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
    product = updated; $('cv-ai-status').textContent = 'Imagen elegida guardada como principal.';
    message('Imagen elegida guardada como principal.');
  }
  // changed: null for a current option; for an option saved before the product changed, what changed ([] if unknown).
  function showVariant(img, finalURL, session, saved = false, changed = null) {
    const group = $('cv-variants'), card = document.createElement('article'), number = group.children.length + 1;
    img.alt = `Opción ${number}`; img.style.maxWidth = '100%'; img.style.height = 'auto'; card.append(img);
    const use = document.createElement('button'); use.type = 'button'; use.textContent = `Usar opción ${number} en la plantilla`;
    use.onclick = () => { scenePhoto = img; $('cv-publish-layout').hidden = true; $('cv-render').click(); }; card.append(use);
    const publish = document.createElement('button'); publish.type = 'button'; publish.textContent = `Elegir opción ${number} como foto principal`;
    publish.onclick = () => busy(publish, async () => publishVariant(img, session)); card.append(publish);
    const download = document.createElement('a'); download.href = finalURL; download.download = `casa-viva-opcion-${number}`; download.textContent = 'Descargar esta opción'; card.append(download);
    if (saved && changed) {
      const what = { foto: 'la foto original', texto: 'el texto de la ficha' }, reasons = changed.map((key) => what[key]).filter(Boolean);
      const label = document.createElement('p');
      label.textContent = (reasons.length ? `Guardada para revisar. Desde entonces cambió ${reasons.join(' y ')}.` : 'Guardada para revisar. La ficha cambió desde que guardaste esta opción.') + ' Compárala con los datos actuales antes de usarla.';
      card.prepend(label);
      const check = document.createElement('label'), box = document.createElement('input');
      box.type = 'checkbox'; check.append(box, ' Ya la comparé con la ficha actual');
      use.disabled = true; publish.disabled = true;
      box.addEventListener('change', () => { use.disabled = !box.checked; publish.disabled = !box.checked; });
      download.before(check);
    } else if (saved) {
      const label = document.createElement('p'); label.textContent = 'Guardada para revisar'; card.prepend(label);
    } else if (product?.id) {
      const keep = document.createElement('button'); keep.type = 'button'; keep.textContent = 'Guardar para revisar después';
      keep.onclick = () => busy(keep, async () => {
        if (session !== generationSession) return;
        const body = new FormData(); body.append('id', product.id); body.append('version', product.version);
        // High-quality JPEG keeps a 1080 px option within the private review limit.
        body.append('photo', await jpeg(img, 1920, 0.95), 'opcion.jpg');
        await api('review', body);
        if (session === generationSession) $('cv-ai-status').textContent = 'Opción guardada en tu perfil para este producto.';
      }); card.append(keep);
    }
    group.append(card);
  }
  const providerSelect = $('cv-provider');
  $('cv-import-ai').addEventListener('change', () => {
    const file = $('cv-import-ai').files[0], img = $('cv-import-preview');
    img.hidden = true;
    if (!file || !['image/png', 'image/webp', 'image/jpeg'].includes(file.type) || file.size > 8 * 1024 ** 2) {
      $('cv-import-ai').value = ''; message('Elige JPG, PNG o WebP de hasta 8 MB.'); return;
    }
    const url = URL.createObjectURL(file); img.onload = () => { img.hidden = false; URL.revokeObjectURL(url); };
    img.onerror = () => { URL.revokeObjectURL(url); message('No se pudo abrir la imagen.'); };
    img.src = url;
  });
  $('cv-import-button').addEventListener('click', e => busy(e.target, async () => {
    const img = $('cv-import-preview'), session = generationSession;
    if (img.hidden || !img.complete || !$('cv-import-ai').files[0]) throw new Error('Elige y revisa una imagen primero.');
    await publishVariant(img, session);
  }));
  for (const option of providerSelect.options) option.disabled = !cvContent.providers[option.value];
  const firstProvider = Array.from(providerSelect.options).find(option => !option.disabled);
  if (firstProvider) providerSelect.value = firstProvider.value;
  $('cv-generate').disabled = !firstProvider;
  $('cv-ai-setup').hidden = Boolean(firstProvider);
  $('cv-generate').addEventListener('click', e => busy(e.target, async () => {
    const session = generationSession, provider = providerSelect.value;
    const real = photo || (product?.image ? await loadImage(product.image_full || product.image) : null);
    if (!real) throw new Error('Elige la foto real del producto antes de generar.');
    if (session !== generationSession) return;
    if (provider !== 'cloudflare' && !confirm('¿Generar una imagen con la API de pago seleccionada? Se usará su saldo.')) return;
    const format = $('cv-format').value;
    const protectWhole = $('cv-protect-whole').checked;
    const body = {mode: 'background', provider, paid: provider === 'cloudflare' ? '0' : '1', layout: $('cv-layout').value, format};
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
      showVariant(img, finalURL, session); status.textContent = 'Revisa que coincidan el color, la forma y los detalles. Puedes generar otra opción para comparar.';
    } catch (error) { if (session === generationSession) status.textContent = error.message; }
  }));
  $('cv-publish-layout').addEventListener('click', e => busy(e.target, async () => {
    const session = generationSession;
    const blob = await (await fetch($('cv-download').href)).blob();
    await publishVariant(blob, session);
  }));
  $('cv-render').addEventListener('click', (e) => busy(e.target, async () => {
    $('cv-download').hidden = true;
    $('cv-publish-layout').hidden = true;
    const img = scenePhoto || photo || (product?.image ? await loadImage(product.image_full || product.image) : null); if (!img) throw new Error('Sube una foto real para empezar.');
    const layout = $('cv-layout').value, rows = facts(), h = Number($('cv-format').value);
    if (['features', 'spec'].includes(layout) && !rows.length) throw new Error('Añade al menos una característica confirmada.');
    if (layout === 'use' && !$('cv-use').value.trim()) throw new Error('Describe el uso cotidiano confirmado.');
    const c = $('cv-canvas'); c.height = h; const ctx = c.getContext('2d');
    ctx.fillStyle = '#f6f2e9'; ctx.fillRect(0, 0, 1080, h); ctx.fillStyle = '#173e35'; ctx.font = '600 26px Arial'; ctx.fillText('CASA VIVA', 64, 70);
    const title = $('cv-headline').value.trim() || $('cv-name').value.trim();
    wrapped(ctx, title, 64, 150, 952, h === 1080 ? 44 : 58, 3);
    const imageTop = h === 1080 ? 300 : 400, imageH = h === 1080 ? 370 : 820;
    if (layout === 'features') drawFeatures(ctx, img, rows, h);
    else { ctx.fillStyle = '#fff'; ctx.fillRect(64, imageTop, 952, imageH); drawContained(ctx, img, 88, imageTop + 24, 904, imageH - 48); }
    let y = imageTop + imageH + 70;
    if (layout === 'spec') rows.forEach((s, i) => { y += wrapped(ctx, `${i + 1}. ${s}`, 64, y, 952, h === 1080 ? 27 : 38, 2) + 16; });
    if (layout === 'offer') { wrapped(ctx, `Precio actual: ${product?.price || 'Consulta en la tienda'}`, 64, y, 952, 38, 2); }
    if (layout === 'use') wrapped(ctx, $('cv-use').value, 64, y, 952, 38, 3);
    ctx.fillStyle = '#173e35'; ctx.fillRect(64, h - 160, 952, 90); wrapped(ctx, 'VER EN CASA VIVA', 105, h - 103, 870, 32, 1, '#fff');
    // Social downloads go out at maximum JPEG quality; the networks compress them again.
    const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 1)); if (!blob) throw new Error('No se pudo preparar la imagen.');
    if (imageURL) URL.revokeObjectURL(imageURL); imageURL = URL.createObjectURL(blob); $('cv-download').href = imageURL;
    $('cv-publish-layout').hidden = !scenePhoto;
    c.hidden = false; $('cv-download').hidden = false; message(layout === 'features' ? `Imagen preparada. Toca la foto para colocar el número ${nextMarker + 1} en el detalle correspondiente; después descarga.` : 'Imagen lista para descargar.');
  }));
  $('cv-canvas').addEventListener('click', (e) => {
    if ($('cv-layout').value !== 'features' || !facts().length) return;
    const c = e.currentTarget, rect = c.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width, y = (e.clientY - rect.top) / rect.height;
    const h = c.height, box = featureBox(h);
    if (x < box.x / 1080 || x > (box.x + box.w) / 1080 || y < box.y / h || y > (box.y + box.h) / h) return;
    markers[nextMarker] = {x, y}; nextMarker = (nextMarker + 1) % facts().length; $('cv-render').click();
  });
  $('cv-copy-build').addEventListener('click', () => {
    const rows = facts(), title = $('cv-name').value.trim(), usage = $('cv-use').value.trim();
    const link = product?.status === 'publish' ? product.url : '';
    $('cv-copy').value = [title, ...rows.map((s) => `• ${s}`), usage, product?.price ? `Precio actual: ${product.price}` : '', link ? `Míralo en Casa Viva: ${link}` : 'Consulta en Casa Viva.'].filter(Boolean).join('\n');
    $('cv-prompt').value = `Crea una imagen comercial para Casa Viva, formato ${$('cv-format').value === '1920' ? '9:16' : '1:1'}. Usa la foto real adjunta como referencia exacta. Conserva forma, color, etiquetas, marca, piezas y proporciones. No inventes vistas interiores ni accesorios incluidos. Estilo minimalista cálido, beige y verde oscuro, luz suave. Producto: ${title}. Datos confirmados: ${rows.join('; ') || plain($('cv-description').value)}. Uso confirmado: ${usage || 'no indicado'}. Deja espacio para añadir textos después. No dibujes precios, descuentos, estrellas, testimonios ni garantías. Si falta información, pregunta. Devuelve además tres títulos breves y una descripción usando solo estos datos. Revisa PROOF: promesa sustentada, evidencia real, oferta vigente, objeción y siguiente paso. No presentes el resultado como fotografía documental si modificas el producto.`;
    $('cv-prompt').value += `\nContexto aportado por la tienda (no investigación independiente): comprador/situación: ${$('cv-buyer').value || 'pendiente'}; objeción: ${$('cv-objection').value || 'pendiente'}; evidencia: ${$('cv-evidence').value || 'pendiente'}. Investiga antes de generar si estas fuentes no bastan para sustentar el mensaje. No deduzcas ventas por la duración o alcance de un anuncio competidor.`;
    message('Textos e instrucción preparados con tus datos. Puedes editarlos.');
  });
  $('cv-copy-save').addEventListener('click', () => {
    // Paragraph and line breaks of the description survive as new lines.
    const description = plain($('cv-description').value.replace(/<\/(p|li|h[1-6])>|<br\s*\/?>/gi, '$&\n')).replace(/\n{3,}/g, '\n\n').trim();
    const sections = [['TÍTULO', $('cv-name').value.trim()], ['DESCRIPCIÓN', description], ['TEXTO PARA REDES', $('cv-copy').value.trim()]];
    if ($('cv-copy-prompt').checked) sections.push(['INSTRUCCIÓN PARA IA', $('cv-prompt').value.trim()]);
    const text = sections.filter(([, body]) => body).map(([head, body]) => `${head}\n${body}`).join('\n\n') + '\n';
    const url = URL.createObjectURL(new Blob([text], {type: 'text/plain;charset=utf-8'})); const a = document.createElement('a'); a.href = url; a.download = 'casa-viva-textos.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  list().catch((error) => message(error.message));
})();
