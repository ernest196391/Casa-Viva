/* global cvContent */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('cv-editor')) return;
  let product = null, photo = null, page = 1, pages = 1, token = crypto.randomUUID(), dirty = false;
  let markers = [], nextMarker = 0;
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
    resetScenes();
    product = p; photo = null; dirty = false; markers = []; nextMarker = 0;
    $('cv-editor').hidden = false;
    $('cv-name').value = p.name || ''; $('cv-description').value = p.description || ''; $('cv-short').value = p.short || '';
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
        fill(await api(`products?id=${p.id}`)); message('Producto abierto.'); $('cv-editor').scrollIntoView({ behavior: 'smooth' });
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
    const result = await api('suggest', { name: $('cv-name').value, facts: $('cv-facts').value });
    $('cv-suggestion').value = result.text; message(result.note);
  }));
  $('cv-title-suggest').addEventListener('click', (e) => busy(e.target, async () => {
    const result = await api('suggest', { name: $('cv-name').value, facts: $('cv-facts').value, kind: 'titulo' });
    $('cv-name').value = result.text; dirty = true; message(result.note);
  }));
  $('cv-use-suggestion').addEventListener('click', () => {
    const text = $('cv-suggestion').value.trim(); if (!text) return;
    $('cv-description').value = text; dirty = true; message('Descripción puesta en la ficha. Guarda los cambios para publicarla.');
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  async function save(action) {
    if (!$('cv-editor').reportValidity()) return;
    const p = await api('save', { id: product.id || 0, version: product.version || '', token, action,
      name: $('cv-name').value, description: $('cv-description').value, short: $('cv-short').value,
      brief: Object.fromEntries(['facts', 'buyer', 'objection', 'evidence', 'use', 'headline'].map((key) => [key, $('cv-' + key).value])) });
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
    const url = URL.createObjectURL(file);
    try { photo = await loadImage(url); markers = []; nextMarker = 0; $('cv-original').src = url; $('cv-original').hidden = false; } finally { URL.revokeObjectURL(url); }
    message('Foto preparada. Pulsa «Subir como foto principal» para enviarla a la web.');
  }));
  $('cv-upload').addEventListener('click', (e) => busy(e.target, async () => {
    if (!product?.id) throw new Error('Guarda primero el borrador.');
    const file = $('cv-photo').files[0]; if (!file) throw new Error('Elige una foto.');
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version); body.append('photo', file);
    product = await api('upload', body); message('Foto subida y protegida. Si el producto ya estaba publicado, la foto ya aparece en la tienda.');
  }));
  function wrapped(ctx, text, x, y, width, size, maxLines = 3, color = '#173e35') {
    ctx.fillStyle = color; ctx.font = `600 ${size}px Arial, sans-serif`; ctx.textAlign = 'left';
    const words = String(text).split(/\s+/); let line = '', lines = [];
    for (const word of words) { const next = line ? `${line} ${word}` : word; if (ctx.measureText(next).width > width && line) { lines.push(line); line = word; } else line = next; }
    if (line) lines.push(line);
    if (lines.length > maxLines || lines.some((s) => ctx.measureText(s).width > width)) throw new Error('Acorta el título o las características para que se lean completos.');
    lines.forEach((s, i) => ctx.fillText(s, x, y + i * size * 1.25)); return lines.length * size * 1.25;
  }
  function drawContained(ctx, img, x, y, w, h) { const scale = Math.min(w / img.width, h / img.height); const iw = img.width * scale, ih = img.height * scale; ctx.drawImage(img, x + (w - iw) / 2, y + (h - ih) / 2, iw, ih); }
  function facts() { return $('cv-facts').value.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 3); }
  let imageURL = '';
  async function jpeg(img, limit) {
    const c = document.createElement('canvas'), scale = Math.min(1, limit / Math.max(img.width, img.height));
    c.width = Math.max(1, Math.round(img.width * scale)); c.height = Math.max(1, Math.round(img.height * scale));
    const ctx = c.getContext('2d'); ctx.fillStyle = 'white'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
    const blob = await new Promise(resolve => c.toBlob(resolve, 'image/jpeg', 0.92));
    if (!blob) throw new Error('No se pudo preparar la foto.');
    return blob;
  }
  async function publishVariant(blob, session) {
    if (session !== generationSession) return;
    if (!product?.id) throw new Error('Guarda primero el borrador del producto.');
    const name = product.name;
    if (!confirm(`¿Usar esta imagen como principal de «${name}»? Si está publicado, aparecerá en la tienda.`)) return;
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version);
    body.append('source', 'ai'); body.append('photo', blob, 'casa-viva-ia.jpg');
    const updated = await api('upload', body);
    if (session !== generationSession) return;
    product = updated; $('cv-ai-status').textContent = 'Imagen elegida guardada como principal.';
    message('Imagen elegida guardada como principal.');
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
    await publishVariant(await jpeg(img, 1920), session);
  }));
  for (const option of providerSelect.options) option.disabled = !cvContent.providers[option.value];
  const firstProvider = Array.from(providerSelect.options).find(option => !option.disabled);
  if (firstProvider) providerSelect.value = firstProvider.value;
  $('cv-generate').disabled = !firstProvider;
  $('cv-ai-setup').hidden = Boolean(firstProvider);
  $('cv-generate').addEventListener('click', e => busy(e.target, async () => {
    const session = generationSession, provider = providerSelect.value;
    const real = photo || (product?.image ? await loadImage(product.image) : null);
    if (!real) throw new Error('Elige la foto real del producto antes de generar.');
    if (session !== generationSession) return;
    if (provider !== 'cloudflare' && !confirm('¿Generar una imagen con la API de pago seleccionada? Se usará su saldo.')) return;
    const body = new FormData();
    body.append('provider', provider); body.append('paid', provider === 'cloudflare' ? '0' : '1');
    body.append('layout', $('cv-layout').value); body.append('format', $('cv-format').value);
    body.append('details', [$('cv-name').value, $('cv-facts').value, $('cv-use').value].filter(Boolean).join('. '));
    body.append('photo', await jpeg(real, provider === 'cloudflare' ? 511 : 1536), 'referencia.jpg');
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
      if (!result.image) throw new Error('La imagen sigue pendiente. Consulta el historial del proveedor antes de repetir.');
      const img = await loadImage(result.image);
      if (session !== generationSession) return;
      const group = $('cv-variants'), card = document.createElement('article'), number = group.children.length + 1;
      img.alt = `Opción ${number}`; img.style.maxWidth = '100%'; img.style.height = 'auto'; card.append(img);
      const use = document.createElement('button'); use.type = 'button'; use.textContent = `Usar opción ${number} en la plantilla`;
      use.onclick = () => { scenePhoto = img; $('cv-publish-layout').hidden = true; $('cv-render').click(); }; card.append(use);
      const publish = document.createElement('button'); publish.type = 'button'; publish.textContent = `Elegir opción ${number} como foto principal`;
      publish.onclick = () => busy(publish, async () => publishVariant(await jpeg(img, 1920), session)); card.append(publish);
      const download = document.createElement('a'); download.href = result.image; download.download = `casa-viva-opcion-${number}`; download.textContent = 'Descargar esta opción'; card.append(download);
      group.append(card); status.textContent = 'Revisa que coincidan el color, la forma y los detalles. Puedes generar otra opción para comparar.';
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
    const img = scenePhoto || photo || (product?.image ? await loadImage(product.image) : null); if (!img) throw new Error('Sube una foto real para empezar.');
    const layout = $('cv-layout').value, rows = facts(), h = Number($('cv-format').value);
    if (['features', 'spec'].includes(layout) && !rows.length) throw new Error('Añade al menos una característica confirmada.');
    if (layout === 'use' && !$('cv-use').value.trim()) throw new Error('Describe el uso cotidiano confirmado.');
    const c = $('cv-canvas'); c.height = h; const ctx = c.getContext('2d');
    ctx.fillStyle = '#f6f2e9'; ctx.fillRect(0, 0, 1080, h); ctx.fillStyle = '#173e35'; ctx.font = '600 26px Arial'; ctx.fillText('CASA VIVA', 64, 70);
    const title = $('cv-headline').value.trim() || $('cv-name').value.trim();
    wrapped(ctx, title, 64, 150, 952, h === 1080 ? 44 : 58, 3);
    const imageTop = h === 1080 ? 300 : 400, imageH = h === 1080 ? 370 : 820;
    ctx.fillStyle = '#fff'; ctx.fillRect(64, imageTop, 952, imageH); drawContained(ctx, img, 88, imageTop + 24, 904, imageH - 48);
    if (layout === 'features') rows.forEach((s, i) => {
      const point = markers[i]; if (!point) return;
      const x = point.x * 1080, y = point.y * h;
      ctx.beginPath(); ctx.arc(x, y, 24, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill(); ctx.strokeStyle = '#173e35'; ctx.lineWidth = 3; ctx.stroke();
      ctx.fillStyle = '#173e35'; ctx.font = 'bold 28px Arial'; ctx.textAlign = 'center'; ctx.fillText(String(i + 1), x, y + 10); ctx.textAlign = 'left';
    });
    let y = imageTop + imageH + 70;
    if (['features', 'spec'].includes(layout)) rows.forEach((s, i) => { y += wrapped(ctx, `${i + 1}. ${s}`, 64, y, 952, h === 1080 ? 27 : 38, 2) + 16; });
    if (layout === 'offer') { wrapped(ctx, `Precio actual: ${product?.price || 'Consulta en la tienda'}`, 64, y, 952, 38, 2); }
    if (layout === 'use') wrapped(ctx, $('cv-use').value, 64, y, 952, 38, 3);
    ctx.fillStyle = '#173e35'; ctx.fillRect(64, h - 160, 952, 90); wrapped(ctx, 'VER EN CASA VIVA', 105, h - 103, 870, 32, 1, '#fff');
    const blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.92)); if (!blob) throw new Error('No se pudo preparar la imagen.');
    if (imageURL) URL.revokeObjectURL(imageURL); imageURL = URL.createObjectURL(blob); $('cv-download').href = imageURL;
    $('cv-publish-layout').hidden = !scenePhoto;
    c.hidden = false; $('cv-download').hidden = false; message(layout === 'features' ? `Imagen preparada. Toca la foto para colocar el número ${nextMarker + 1} en el detalle correspondiente; después descarga.` : 'Imagen lista para descargar.');
  }));
  $('cv-canvas').addEventListener('click', (e) => {
    if ($('cv-layout').value !== 'features' || !facts().length) return;
    const c = e.currentTarget, rect = c.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width, y = (e.clientY - rect.top) / rect.height;
    const h = c.height, top = h === 1080 ? 300 : 400, height = h === 1080 ? 370 : 820;
    if (x < 64 / 1080 || x > 1016 / 1080 || y < top / h || y > (top + height) / h) return;
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
  $('cv-copy-save').addEventListener('click', () => { const url = URL.createObjectURL(new Blob([$('cv-copy').value, '\n\n', $('cv-prompt').value], {type: 'text/plain;charset=utf-8'})); const a = document.createElement('a'); a.href = url; a.download = 'casa-viva-textos.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); });
  list().catch((error) => message(error.message));
})();
