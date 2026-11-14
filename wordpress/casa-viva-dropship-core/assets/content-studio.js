/* global cvContent */
/* Panel «Mi catálogo y fotos» v2 (diseño aprobado 9-nov): Ficha · Fotos (HERO, LIFESTYLE, BENEFICIO) · Anuncio · Resultados
 * y la bandeja «Dudas del catálogo». WooCommerce manda: aquí solo se edita contenido y fotos; precio y existencias los pone Lennys. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  if (!$('cv-editor')) return;
  const MOTOR = cvContent.motor || { recorte: false, ia: false, gasto: { hoy: 0, tope: 0.5, ia: 0.15, lleno: false }, max: 3, motivos: ['Color', 'Tamaño o forma', 'Piezas o etiqueta', 'Cortado', 'Fondo'], auto: true };
  let product = null, photo = null, page = 1, pages = 1, token = crypto.randomUUID(), dirty = false;
  // Cambia al abrir otro producto: una respuesta tardía de otro producto nunca toca la ficha abierta.
  let ficha = 0, generationSession = 0;
  let tab = 'ficha', layoutValue = 'ben', formatValue = '1920', reviewPick = '', copyEdited = false;
  // Datos del brief que ya no se ven en el panel: se guardan tal cual para que un guardado nunca los borre.
  let briefExtra = {};
  // Fotos: pieza elegida, «repetir», fotos de partida y la opción que se compara. Anuncio: puntos por foto.
  let fotoState = { pieza: '', more: false, bases: [], local: null, bad: false, option: '' };
  let points = {}, activePoint = 0, job = null;
  const LAYOUTS = { ben: 'Beneficio', offer: 'Oferta', review: 'Reseña', features: 'Características', spec: 'Ficha técnica', use: 'Uso diario' };
  const PIEZA = { hero: { n: 'HERO', d: 'Fondo blanco · web' }, life: { n: 'LIFESTYLE', d: 'Una persona usándolo' }, ben: { n: 'BENEFICIO', d: 'Responde la duda' } };
  const ESTADO = { aprobada: 'Aprobada', revision: 'Revisión', rechazada: 'Rechazada' };
  const PRIO = { nuevo: 'Nuevo', top: 'Más vendido', anuncios: 'Va a anuncios' };
  const FONT = '"Helvetica Neue", Helvetica, Arial, sans-serif';
  const usd = (n) => `${Number(n || 0).toFixed(2).replace('.', ',')} USD`;
  const SVG = {
    check: '<path d="M5 12l4.5 4.5L19 7"/>', x: '<path d="M6 6l12 12M18 6L6 18"/>', cam: '<path d="M4 8h3l1.6-2.5h6.8L17 8h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/>',
    mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0014 0M12 18v3"/>', back: '<path d="M4 12a8 8 0 108-8 8 8 0 00-6 2.7M4 4v4h4"/>',
  };
  function icon(name, cls = 'cv-ic cv-sm') {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('class', cls); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.innerHTML = SVG[name]; return s;
  }
  // Pequeño constructor de nodos: h('button', { class: 'cv-btn', onclick }, 'Texto').
  function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v; else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v); else el.setAttribute(k, v === true ? '' : String(v));
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid);
    return el;
  }
  const btn = (text, cls, onclick, attrs = {}) => h('button', { type: 'button', class: `cv-btn ${cls || ''}`.trim(), onclick, ...attrs }, text);
  // Aviso de abajo: se quita solo a los 10 s o al tocarlo, para no tapar el trabajo.
  let messageTimer = 0;
  const message = (text) => { $('cv-message').textContent = text; clearTimeout(messageTimer); messageTimer = setTimeout(() => { $('cv-message').textContent = ''; }, 10000); };
  $('cv-message').addEventListener('click', () => { $('cv-message').textContent = ''; });
  const plain = (text) => { const d = new DOMParser().parseFromString(text || '', 'text/html'); return d.body.textContent || ''; };

  /* ---------- Descripción en texto normal: párrafos, «• » puntos, «Subtítulo:» y *negrita* (como en WhatsApp) ---------- */
  const simpleTags = new Set(['P', 'H3', 'UL', 'LI', 'BR', 'STRONG', 'B']);
  const rawHtml = {};
  function toText(html) {
    const body = new DOMParser().parseFromString(html || '', 'text/html').body;
    const els = [...body.querySelectorAll('*')];
    if (els.some((el) => !simpleTags.has(el.tagName) || el.attributes.length) || body.querySelector('li ul, p ul')) return null;
    if (els.some((el) => ['STRONG', 'B'].includes(el.tagName) && (el.children.length || el.parentElement === body || /\*/.test(el.textContent)))) return null;
    const line = (el) => [...el.childNodes].map((n) => n.nodeName === 'BR' ? '\n' : ['STRONG', 'B'].includes(n.nodeName) ? `*${n.textContent.trim()}*` : n.textContent.replace(/\s+/g, ' ')).join('').split('\n').map((s) => s.trim()).join('\n').trim();
    const blocks = [...body.childNodes].map((node) => {
      if (node.nodeType !== Node.ELEMENT_NODE) return node.textContent.trim();
      if (node.tagName === 'H3') return `${line(node).replace(/:$/, '')}:`;
      if (node.tagName === 'UL') return [...node.children].map((li) => `• ${line(li)}`).join('\n');
      return line(node);
    });
    return blocks.filter(Boolean).join('\n\n');
  }
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const bold = (s) => esc(s).replace(/(^|[\s(¿¡«"])\*([^\s*](?:[^*\n]*[^\s*])?)\*(?=$|[\s.,;:!?)»"])/g, '$1<strong>$2</strong>');
  function toHtml(text) {
    const html = [];
    for (const block of text.trim().split(/\n\s*\n/)) {
      let para = [], items = [];
      const flushPara = () => { if (para.length) html.push(`<p>${para.map(bold).join('<br>')}</p>`); para = []; };
      const flushList = () => { if (items.length) html.push(`<ul>${items.map((s) => `<li>${bold(s)}</li>`).join('')}</ul>`); items = []; };
      for (const l of block.split('\n').map((s) => s.trim()).filter(Boolean)) {
        const bullet = l.match(/^[•-]\s+(.+)$/);
        if (bullet) { flushPara(); items.push(bullet[1]); }
        else if (l.endsWith(':') && l.length <= 60 && !para.length && !/\*/.test(l)) { flushList(); html.push(`<h3>${esc(l.slice(0, -1))}</h3>`); }
        else { flushList(); para.push(l); }
      }
      flushPara(); flushList();
    }
    return html.join('');
  }
  const editableText = (id) => rawHtml[id] ? $(id).value : toHtml($(id).value);

  /* ---------- Servidor ---------- */
  async function api(path, body) {
    const multipart = body instanceof FormData;
    // rest_url puede ser index.php?rest_route= en sitios sin enlaces permanentes.
    const [route, query = ''] = path.split('?');
    const url = new URL(cvContent.base + route, location.href);
    for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
    const response = await fetch(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cvContent.nonce, ...(!multipart && body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? (multipart ? body : JSON.stringify(body)) : undefined });
    let data = {};
    try { data = await response.json(); } catch { data = {}; }
    if (!response.ok) {
      const error = new Error(data.message || (response.status >= 500 ? 'El servidor no respondió. Espera un minuto y repite.' : 'No se pudo completar. Intenta otra vez.'));
      error.status = response.status; error.data = data.data || {}; throw error;
    }
    return data;
  }
  async function busy(button, work) {
    if (button) button.disabled = true;
    try { await work(); } catch (error) { message(error.message); } finally { if (button && document.contains(button)) button.disabled = false; updateBar(); }
  }
  const form = (fields) => { const f = new FormData(); for (const [k, v] of Object.entries(fields)) if (v !== undefined && v !== null) { if (Array.isArray(v)) v.forEach((x) => f.append(`${k}[]`, x)); else f.append(k, v); } return f; };

  /* ---------- Hojas que suben desde abajo ---------- */
  let openSheetEl = null, sheetReturn = null;
  function openSheet(id, from) {
    closeSheet();
    const el = $(id); sheetReturn = from || document.activeElement;
    el.hidden = false; $('cv-scrim').hidden = false; openSheetEl = el; document.body.classList.add('cv-sheet-open');
    el.querySelector('h2')?.focus();
  }
  function closeSheet() {
    if (!openSheetEl) return;
    openSheetEl.hidden = true; $('cv-scrim').hidden = true; openSheetEl = null; document.body.classList.remove('cv-sheet-open');
    if (sheetReturn && document.contains(sheetReturn) && !sheetReturn.closest('[hidden]')) sheetReturn.focus();
    sheetReturn = null;
  }
  // Hoja genérica: título + contenido armado en el momento.
  function sheet(title, ...kids) { $('cv-gen-heading').textContent = title; $('cv-gen-body').replaceChildren(...kids.flat().filter(Boolean)); openSheet('cv-gen-sheet'); return $('cv-gen-body'); }
  $('cv-scrim').addEventListener('click', closeSheet);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && openSheetEl) closeSheet(); });
  document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeSheet));
  const chips = (names, picked) => h('div', { class: 'cv-chips', role: 'group', 'aria-label': '¿Qué falló?' }, names.map((n) => h('button', { type: 'button', 'aria-pressed': 'false', text: n,
    onclick: (e) => { const on = e.currentTarget.getAttribute('aria-pressed') !== 'true'; e.currentTarget.setAttribute('aria-pressed', String(on)); if (on) picked.add(n); else picked.delete(n); } })));

  /* ---------- Pantallas y pestañas ---------- */
  function showScreen(name) {
    closeSheet();
    $('cv-list-screen').hidden = name !== 'list'; $('cv-editor').hidden = name !== 'editor'; $('cv-dudas-screen').hidden = name !== 'dudas';
    document.body.classList.toggle('cv-has-bar', name !== 'list'); updateBar(); window.scrollTo(0, 0);
  }
  const showList = () => { showScreen('list'); list().catch((error) => message(error.message)); };
  const showEditor = () => showScreen('editor');
  const run = (next) => { Promise.resolve().then(next).catch((error) => message(error.message)); };
  // «Cambios sin guardar»: guardar y salir, salir sin guardar o seguir aquí.
  function guard(next) {
    if (!dirty) return run(next);
    const name = $('cv-name').value.trim() || 'Nueva mercancía';
    sheet('Cambios sin guardar', h('p', { text: name }), h('div', { class: 'cv-acts' },
      btn('Guardar y salir', 'cv-primary', (e) => busy(e.currentTarget, async () => { await save('save'); if (dirty) return; closeSheet(); await next(); })),
      btn('Salir sin guardar', '', () => { dirty = false; closeSheet(); run(next); }),
      h('button', { type: 'button', class: 'cv-link cv-center', onclick: closeSheet, text: 'Seguir aquí' })));
  }
  $('cv-back').addEventListener('click', () => guard(showList));
  const TABS = ['ficha', 'fotos', 'anuncio', 'res'];
  function setTab(name) {
    tab = name;
    for (const t of TABS) {
      const b = $('cv-tab-' + t);
      b.setAttribute('aria-selected', String(t === name)); b.tabIndex = t === name ? 0 : -1;
      $('cv-panel-' + t).hidden = t !== name;
    }
    closeSheet(); window.scrollTo(0, 0);
    if (name === 'fotos') renderFotos();
    if (name === 'anuncio') { syncAnuncio(); preview(0); }
    updateBar();
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

  /* ---------- Barra de acción: un botón principal que dice lo que falta ---------- */
  function missing() {
    const m = [];
    if (!product?.image) m.push('foto');
    if (!plain(editableText('cv-description')).trim()) m.push('descripción');
    // 3.14.145: un borrador sale a la web solo cuando Lennys puso precio y existencias.
    const lennys = [];
    if (!product?.price) lennys.push('precio');
    if (!product?.id || (product.faltan || []).includes('existencias')) lennys.push('existencias');
    if (lennys.length) m.push(`${lennys.join(' y ')} (Lennys)`);
    return m;
  }
  function updateBar() {
    if (!product || $('cv-editor').hidden) return;
    const isNew = !product.id, published = product.status === 'publish';
    const save = $('cv-save'), pub = $('cv-publish'), fin = $('cv-render'), fotobar = $('cv-fotobar');
    save.textContent = isNew ? 'Guardar borrador' : published && tab === 'ficha' ? 'Guardar cambios' : 'Guardar';
    fotobar.hidden = tab !== 'fotos' || !fotobar.childElementCount;
    save.hidden = tab === 'fotos' || (tab !== 'ficha' && !dirty);
    save.classList.toggle('cv-primary', published && tab === 'ficha');
    pub.hidden = tab !== 'ficha' || published;
    if (!pub.hidden) {
      const miss = missing();
      pub.disabled = miss.length > 0;
      pub.replaceChildren('Publicar');
      if (miss.length) pub.append(h('small', { text: `Falta ${miss.join(', ')}` }));
    }
    fin.hidden = tab !== 'anuncio';
    $('cv-actionbar').hidden = save.hidden && pub.hidden && fin.hidden && fotobar.hidden;
    document.body.classList.toggle('cv-has-bar', !$('cv-actionbar').hidden);
  }
  function setFotobar(...buttons) { $('cv-fotobar').replaceChildren(...buttons.flat().filter(Boolean)); updateBar(); }
  const heroOnWeb = (p = product) => Boolean(p?.hero_en_web);
  function refreshHeader() {
    const p = product || {};
    $('cv-heading').textContent = p.id ? p.name : 'Nueva mercancía';
    $('cv-status').textContent = !p.id ? 'Nuevo' : p.status === 'publish' ? 'Publicado' : 'Borrador';
    $('cv-status').classList.toggle('cv-pill-draft', p.status !== 'publish');
    const price = p.sale ? `${p.sale.price} (antes ${p.sale.regular})` : (p.price || 'Pendiente');
    $('cv-commercial').textContent = p.price || 'Pendiente'; $('cv-ad-price').textContent = price;
    $('cv-view').hidden = p.status !== 'publish'; if (p.url) $('cv-view').href = p.url;
    $('cv-tab-res').disabled = !p.id; $('cv-tab-fotos').disabled = !p.id;
    $('cv-fotos-badge').hidden = !p.id || !(p.falta_hero || p.faltan_piezas || p.hero_revisar || Object.values(p.piezas || {}).some((x) => x.sin_revisar));
    const prios = p.prios || [];
    $('cv-prio-toggle').hidden = !p.id || (prios.length > 0 && !prios.includes('anuncios'));
    $('cv-prio-label').textContent = prios.includes('anuncios') ? 'Quitar «Va a anuncios»' : 'Va a anuncios: hacer las 3 piezas';
    $('cv-restore-menu').hidden = !p.restaurable;
    // Lo que falta para publicar: lo tuyo y lo de Lennys (precio y existencias).
    const own = [], len = [];
    if (p.id && p.status !== 'publish') {
      for (const k of p.faltan || []) { if (k === 'foto') own.push('foto real'); if (k === 'descripcion') own.push('descripción (medidas y color)'); if (k === 'precio') len.push('precio'); if (k === 'existencias') len.push('existencias'); }
    }
    const miss = $('cv-miss'); miss.hidden = !own.length && !len.length; miss.replaceChildren();
    if (own.length) miss.append(h('b', { text: 'Tú: ' }), own.join(', '), len.length ? h('br') : '');
    if (len.length) miss.append(h('b', { text: 'Lennys: ' }), len.join(' y '));
  }
  function showPhoto() {
    const img = $('cv-original'), has = Boolean(img.getAttribute('src')), hero = has && !photo && heroOnWeb();
    img.hidden = !has; $('cv-photo-empty').hidden = has;
    $('cv-photo-label').textContent = has ? 'Cambiar foto' : 'Añadir foto';
    img.closest('.cv-photo').classList.toggle('cv-white', hero);
    const tag = $('cv-hero-tag'); tag.replaceChildren();
    tag.className = `cv-hero-tag cv-chiptag${hero ? '' : ' cv-gold'}`;
    if (hero) tag.append(icon('check'), 'HERO en la web'); else tag.append('Falta HERO');
    tag.hidden = !product?.id || Boolean(photo) || (!hero && !product.falta_hero);
  }
  // Tras una acción de fotos el servidor devuelve el producto: se toman solo los datos de fotos y la versión,
  // para no pisar lo que se está escribiendo en la Ficha.
  function mergePhoto(p) {
    if (!p || !product || p.id !== product.id) return;
    const keep = ['piezas', 'opciones', 'fotos', 'prios', 'prioridad', 'prio', 'falta_hero', 'faltan_piezas', 'falta_fotos', 'hero_en_web', 'hero_revisar', 'restaurable', 'image', 'image_full', 'image_id', 'gallery', 'version', 'faltan', 'falta', 'protected', 'status'];
    for (const k of keep) if (k in p) product[k] = p[k];
    const brief = p.brief || {};
    product.brief = { ...(product.brief || {}) };
    for (const k of ['duda', 'resultado', 'prueba', 'condicion', 'fuente', 'matriz_ok']) { if (k in brief) product.brief[k] = brief[k]; else delete product.brief[k]; }
    if (!photo) { if (product.image) $('cv-original').src = product.image; else $('cv-original').removeAttribute('src'); }
    showPhoto(); refreshHeader(); sources();
  }

  /* ---------- Características: dato + para qué sirve, guardadas como «dato — beneficio» ---------- */
  const ROWS = [1, 2, 3];
  const factRows = () => ROWS.map((i) => ({ fact: $('cv-fact-' + i).value.trim(), benefit: $('cv-benefit-' + i).value.trim() })).filter((r) => r.fact);
  const facts = () => factRows().map((r) => r.benefit ? `${r.fact} — ${r.benefit}` : r.fact).slice(0, 3);
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
      loadImage(review).then((img) => { if (session === generationSession) showVariant(img, review, session, true, changed); }).catch(() => message('No se pudo abrir la opción guardada.'));
    }
    product = p; photo = null; dirty = false; copyEdited = false; points = {}; activePoint = 0; adSource = '';
    fotoState = { pieza: '', more: false, bases: [], local: null, bad: false, option: '' };
    $('cv-name').value = p.name || '';
    for (const [id, html] of [['cv-description', p.description], ['cv-short', p.short]]) {
      const text = toText(html); rawHtml[id] = text === null; $(id).value = text ?? html ?? '';
    }
    $('cv-text-note').hidden = !rawHtml['cv-description'] && !rawHtml['cv-short'];
    $('cv-tools').hidden = Boolean(rawHtml['cv-description']); $('cv-text-help').hidden = Boolean(rawHtml['cv-description']);
    $('cv-suggest-box').hidden = true; $('cv-suggestion').value = '';
    if (p.image) $('cv-original').src = p.image; else $('cv-original').removeAttribute('src');
    $('cv-photo').value = ''; $('cv-upload').hidden = true;
    const brief = p.brief || {};
    setFacts(brief.facts);
    $('cv-offer').value = brief.offer || '';
    briefExtra = { buyer: brief.buyer || '', objection: brief.objection || '', evidence: brief.evidence || '', use: brief.use || '', headline: brief.headline || '' };
    reviewPick = '';
    showReviews(p.reviews || []); showAds(p.anuncios || []);
    $('cv-review-text').value = ''; $('cv-review-name').value = ''; setStars(0); $('cv-review-permission').checked = false;
    $('cv-canvas').hidden = true; $('cv-preview-note').hidden = true; $('cv-point-hint').hidden = true; $('cv-pts').hidden = true;
    $('cv-download').removeAttribute('href'); $('cv-copy').value = ''; $('cv-prompt').value = '';
    refreshHeader(); showPhoto(); sources();
    // Con fotos reales y sin HERO, se abre en Fotos; si no, en la Ficha.
    setTab(p.id && p.falta_hero && (p.fotos || []).length ? 'fotos' : 'ficha');
  }

  /* ---------- Lista ---------- */
  let filter = 'all', listSeq = 0;
  function statusLine(p) {
    if (job && job.id === p.id) return h('span', { class: 'cv-need' }, h('span', { class: 'cv-spin' }), `Creando ${PIEZA[job.pieza].n}…`);
    if (p.hero_revisar || Object.values(p.piezas || {}).some((x) => x.sin_revisar)) return h('span', { class: 'cv-need', text: 'Lista para revisar' });
    if (p.falta_fotos) return h('span', { class: 'cv-need', text: p.falta_fotos });
    return h('span', { class: 'cv-okc', text: 'Fotos listas' });
  }
  async function list(append = false) {
    const seq = ++listSeq;
    const data = await api(`products?search=${encodeURIComponent($('cv-search').elements.search.value)}&page=${page}&filtro=${filter}`);
    if (seq !== listSeq) return;
    pages = data.pages; if (!append) $('cv-items').replaceChildren();
    $('cv-n-nohero').textContent = data.counts?.nohero || ''; $('cv-n-piezas').textContent = data.counts?.piezas || '';
    if (data.gasto) MOTOR.gasto = data.gasto;
    for (const p of data.items) {
      const published = p.status === 'publish';
      const thumb = h('span', { class: `cv-thumb${p.hero_en_web ? ' cv-white' : ''}` });
      if (p.image) thumb.append(h('img', { src: p.image, alt: '', loading: 'lazy' }));
      if (p.falta_hero) thumb.append(h('span', { class: 'cv-flagcam', title: 'Falta HERO' }, icon('cam')));
      const meta = h('span', { class: 'cv-meta' }, h('span', { text: p.price || 'Sin precio' }),
        h('span', {}, h('i', { class: published ? 'cv-dot cv-dot-on' : 'cv-dot' }), ` ${published ? 'Publicado' : 'Borrador'}`),
        ...(p.prios || []).map((k) => h('span', { class: 'cv-prio', text: PRIO[k] || k })));
      const button = h('button', { type: 'button', class: 'cv-product', 'aria-label': `${p.name} · ${published ? 'Publicado' : 'Borrador / privado'}` },
        thumb, h('span', { class: 'cv-product-text' }, h('b', { text: p.name }), meta, statusLine(p)));
      button.addEventListener('click', () => guard(() => busy(button, async () => {
        const current = ++ficha, opened = await api(`products?id=${p.id}`);
        if (current !== ficha) return;
        fill(opened); showEditor(); message('Producto abierto.');
      })));
      $('cv-items').append(button);
    }
    if (!data.items.length && !append) $('cv-items').append(h('p', { class: 'cv-empty', text: filter === 'all' ? 'No encontramos productos con ese nombre.' : 'Nada en este filtro.' }));
    $('cv-more').hidden = page >= pages;
    if (cvContent.dudas) dudasCount().catch(() => {});
  }
  let searchTimer = 0;
  $('cv-search').addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(searchTimer); page = 1; busy(null, () => list()); });
  $('cv-search').elements.search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; busy(null, () => list()); }, 400); });
  document.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.filter; page = 1; document.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b))); busy(null, () => list());
  }));
  $('cv-more').addEventListener('click', (e) => busy(e.currentTarget, async () => { page++; try { await list(true); } catch (error) { page--; throw error; } }));
  $('cv-new').addEventListener('click', () => guard(() => { token = crypto.randomUUID(); fill({}); showEditor(); $('cv-name').focus(); }));
  $('cv-editor').addEventListener('input', (e) => {
    if (e.target.closest('[data-nodirty]')) return;
    dirty = true;
    if (/^cv-(fact|benefit)-/.test(e.target.id)) showFactRows();
    if (e.target.id === 'cv-offer' || /^cv-(fact|benefit)-/.test(e.target.id)) { showPoints(); preview(); }
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
  // Negrita, Punto y Subtítulo escriben el formato de texto normal por ella.
  $('cv-tools').addEventListener('click', (e) => {
    const b = e.target.closest('[data-insert]'); if (!b) return;
    const ta = $('cv-description'), start = ta.selectionStart ?? ta.value.length, end = ta.selectionEnd ?? start;
    const before = ta.value.slice(0, start), picked = ta.value.slice(start, end), after = ta.value.slice(end);
    let insert, from, to;
    if (b.dataset.insert === 'bold') { const word = picked.trim() || 'negrita'; insert = `*${word}*`; from = start + 1; to = from + word.length; }
    else if (b.dataset.insert === 'point') { insert = (before && !before.endsWith('\n') ? '\n' : '') + '• ' + picked; from = to = start + insert.length; }
    else { const gap = !before ? '' : before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n'; insert = `${gap}Subtítulo:\n`; from = start + gap.length; to = from + 'Subtítulo'.length; }
    ta.value = before + insert + after; ta.focus(); ta.setSelectionRange(from, to);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  async function save(action) {
    if (!$('cv-editor').reportValidity()) return;
    const current = ficha, p = await api('save', { id: product.id || 0, version: product.version || '', token, action,
      name: $('cv-name').value, description: editableText('cv-description'), short: editableText('cv-short'),
      brief: { facts: factsText(), ...briefExtra, offer: $('cv-offer').value } });
    if (current !== ficha) { message(`«${p.name}» quedó guardado. La ficha que tienes abierta no cambió.`); return; }
    // Se conserva el trabajo del anuncio y la foto local mientras se refresca lo del servidor.
    product = { ...p, anuncios: product.anuncios || [] }; dirty = false; refreshHeader(); showPhoto(); updateBar();
    $('cv-name').value = p.name; // el servidor limpia el título («Mesa de oficina.» → «Mesa de oficina»)
    message(p.status === 'publish' ? 'Cambios publicados en Casa Viva.' : (p.falta ? `Borrador guardado. Falta: ${p.falta}.` : 'Borrador guardado. Ya tiene todo: puedes publicarlo.'));
  }
  $('cv-editor').addEventListener('submit', (e) => { e.preventDefault(); busy($('cv-save'), () => save('save')); });
  $('cv-publish').addEventListener('click', (e) => busy(e.currentTarget, () => save('publish')));

  /* ---------- Foto real de la Ficha ---------- */
  const imageCache = new Map();
  async function loadImage(url) { const img = new Image(); img.crossOrigin = 'anonymous'; await new Promise((resolve, reject) => { img.onload = resolve; img.onerror = () => reject(new Error('No se pudo abrir la foto. Sube una desde tu móvil.')); img.src = url; }); return img; }
  function cachedImage(url) {
    if (!url) return Promise.resolve(null);
    if (!imageCache.has(url)) imageCache.set(url, loadImage(url).catch((error) => { imageCache.delete(url); throw error; }));
    return imageCache.get(url);
  }
  $('cv-photo').addEventListener('change', (e) => busy($('cv-upload'), async () => {
    photo = null;
    const file = e.target.files[0]; if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) throw new Error('Elige JPG, PNG o WebP de hasta 8 MB.');
    const current = ficha, url = URL.createObjectURL(file);
    try {
      const img = await loadImage(url); if (current !== ficha) return;
      photo = img; $('cv-original').src = url; showPhoto(); $('cv-upload').hidden = false;
    } finally { URL.revokeObjectURL(url); }
    sources(); message('Foto preparada. Pulsa «Subir a la web» para ponerla en la tienda.');
    preview();
  }));
  $('cv-upload').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    const file = $('cv-photo').files[0]; if (!file) throw new Error('Elige una foto.');
    let wasNew = false;
    // Mercancía nueva: primero se guarda el borrador, para que la foto tenga dónde ir.
    if (!product?.id) { wasNew = true; await save('save'); if (!product?.id) return; }
    const current = ficha, ready = await webImage(file);
    const body = new FormData(); body.append('id', product.id); body.append('version', product.version); body.append('photo', ready, webName('foto', ready));
    const updated = await api('upload', body);
    if (current !== ficha) { message(`La foto quedó subida en «${updated.name}». La ficha que tienes abierta no cambió.`); return; }
    product = { ...updated, anuncios: product.anuncios || [] }; photo = null; $('cv-upload').hidden = true; refreshHeader(); showPhoto(); sources(); updateBar();
    message(updated.status === 'publish' ? 'Foto subida y protegida. La foto ya aparece en la tienda.'
      : `${wasNew ? 'Borrador guardado. ' : ''}Foto subida. ${updated.falta ? `Falta: ${updated.falta}.` : 'Ya puedes publicarlo.'}`);
  }));
  // Fotos para la web: WebP hasta 1600 px; JPEG ligero donde el navegador no escribe WebP.
  async function webImage(source) {
    let img = source, url = '';
    if (source instanceof Blob) url = URL.createObjectURL(source);
    try {
      if (url) img = await loadImage(url);
      const width = img.naturalWidth || img.width, height = img.naturalHeight || img.height;
      const c = document.createElement('canvas'), scale = Math.min(1, 1600 / Math.max(width, height));
      c.width = Math.max(1, Math.round(width * scale)); c.height = Math.max(1, Math.round(height * scale));
      const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, c.width, c.height);
      let blob = await new Promise((resolve) => c.toBlob(resolve, 'image/webp', 0.85));
      if (!blob || blob.type !== 'image/webp') {
        ctx.globalCompositeOperation = 'destination-over'; ctx.fillStyle = 'white'; ctx.fillRect(0, 0, c.width, c.height);
        blob = await new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.88));
      }
      if (!blob) throw new Error('No se pudo preparar la foto.');
      return blob;
    } finally { if (url) URL.revokeObjectURL(url); }
  }
  const webName = (base, blob) => `${base}.${blob.type === 'image/webp' ? 'webp' : 'jpg'}`;

  /* ---------- Reseñas reales ---------- */
  // Solo reseñas reales guardadas en el producto, con nombre e inicial: «María González» → «María G.».
  const shortName = (name) => { const parts = name.trim().split(/\s+/); return parts.length < 2 || /[.,]/.test(parts[1]) ? name.trim() : `${parts[0]} ${parts[1][0].toUpperCase()}.`; };
  const stars = (rating) => rating ? '★'.repeat(rating) + '☆'.repeat(5 - rating) : '';
  function showReviews(reviews) {
    $('cv-reviews-count').textContent = reviews.length ? String(reviews.length) : '';
    $('cv-reviews').replaceChildren(...reviews.map((r) => h('div', { class: 'cv-review' },
      r.rating ? h('span', { class: 'cv-stars-line', 'aria-label': `${r.rating} de 5`, text: stars(r.rating) }) : null,
      h('q', { text: r.text }), h('small', { text: `— ${shortName(r.name)}` }),
      h('span', { class: 'cv-tag', text: r.origin === 'whatsapp' ? 'WhatsApp' : r.origin === 'bot' ? 'Bot' : 'Web' }))));
    const pick = $('cv-review-pick');
    if (!reviews.length) { reviewPick = ''; pick.replaceChildren(h('p', { class: 'cv-empty', text: 'Aún no hay reseñas reales' })); return; }
    if (!reviews.some((r) => String(r.id) === reviewPick)) reviewPick = String(reviews[0].id);
    pick.replaceChildren(...reviews.map((r) => h('button', { type: 'button', class: 'cv-pick', role: 'radio', 'data-id': r.id, 'aria-checked': String(String(r.id) === reviewPick),
      text: `${stars(r.rating)} «${r.text.slice(0, 40)}${r.text.length > 40 ? '…' : ''}» — ${shortName(r.name)}`.trim() })));
  }
  $('cv-review-pick').addEventListener('click', (e) => {
    const b = e.target.closest('[role=radio]'); if (!b) return;
    reviewPick = b.dataset.id; for (const x of $('cv-review-pick').querySelectorAll('[role=radio]')) x.setAttribute('aria-checked', String(x === b)); preview(0);
  });
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
  /* ---------- Fotos: HERO · LIFESTYLE · BENEFICIO ---------- */
  const GUIDE = [['Entera', '<rect x="8" y="6" width="32" height="36" rx="3" stroke-dasharray="3 3"/><rect x="16" y="12" width="16" height="24" rx="2"/>'],
    ['Fuera de la caja', '<path d="M6 30l10-6 10 6-10 6z"/><path d="M6 30v8l10 6 10-6v-8"/><rect x="30" y="8" width="12" height="26" rx="2"/>'],
    ['Con luz', '<circle cx="24" cy="18" r="7"/><path d="M24 4v4M24 28v4M10 18h4M34 18h4M14 8l3 3M31 25l3 3M34 8l-3 3M17 25l-3 3"/><path d="M10 42h28"/>']];
  function guide() { return h('div', { class: 'cv-guide' }, GUIDE.map(([t, s]) => { const d = h('div'); d.innerHTML = `<svg viewBox="0 0 48 48" aria-hidden="true">${s}</svg>`; d.append(t); return d; })); }
  const prios = () => product?.prios || [];
  const locked = (k) => k !== 'hero' && !prios().length;
  const piece = (k) => product?.piezas?.[k] || { estado: 'falta' };
  const optionsOf = (k) => (product?.opciones || []).filter((o) => o.pieza === k);
  const unreviewed = (k) => optionsOf(k).filter((o) => !o.estado);
  const realPhotos = () => (product?.fotos || []).filter((f) => !f.hero);
  const usedText = (escalon, coste) => escalon === 'ia' ? `IA de pago · ${usd(coste)}` : escalon === 'recorte' ? 'Recorte gratis · 0 USD' : escalon === 'anuncio' ? 'Hecho en Anuncio' : escalon ? `${escalon} · ${usd(coste)}` : '';
  function chipFor(k) {
    if (locked(k)) return h('span', { class: 'cv-st off', text: 'Solo top' });
    if (job && job.id === product.id && job.pieza === k) return h('span', { class: 'cv-st run', text: 'Creando' });
    const pz = piece(k);
    if (ESTADO[pz.estado]) return h('span', { class: `cv-st ${pz.estado}`, text: ESTADO[pz.estado] });
    if (k === 'hero' && product.hero_en_web) return h('span', { class: 'cv-st run', text: 'En la web' });
    if (unreviewed(k).length) return h('span', { class: 'cv-st revision', text: 'Por revisar' });
    return h('span', { class: 'cv-st', text: 'Falta' });
  }
  function renderPieces() {
    const cur = fotoState.pieza || 'hero';
    $('cv-pieces').replaceChildren(...['hero', 'life', 'ben'].map((k) => {
      const url = k === 'hero' ? (product.hero_en_web ? product.image : piece(k).url) : piece(k).url;
      return h('button', { type: 'button', class: `cv-pz${locked(k) ? ' cv-lock' : ''}`, 'aria-pressed': String(k === cur), 'data-pieza': k, onclick: () => pickPiece(k) },
        h('span', { class: `cv-pzi${k === 'hero' ? ' cv-white' : ''}` }, url ? h('img', { src: url, alt: '' }) : null),
        h('b', { text: PIEZA[k].n }), h('small', { text: PIEZA[k].d }), chipFor(k));
    }));
    const ps = prios();
    $('cv-pieces-note').textContent = ps.length ? `${ps.map((k) => PRIO[k] || k).join(' · ')}: lleva las 3 piezas.` : 'Este producto lleva solo HERO. Las 3 piezas: más vendidos, nuevos y anuncios.';
  }
  function pickPiece(k) {
    if (locked(k)) {
      return sheet('Solo HERO', h('p', { text: 'LIFESTYLE y BENEFICIO son para los más vendidos, los nuevos y los que van a anuncios.' }),
        h('div', { class: 'cv-acts' }, btn('Va a anuncios: hacer las 3', 'cv-primary', (e) => busy(e.currentTarget, async () => { await setPrio(true); closeSheet(); fotoState.pieza = k; renderFotos(); }))));
    }
    fotoState.pieza = k; fotoState.more = false; fotoState.option = ''; renderFotos();
  }
  async function setPrio(on) {
    const current = ficha, p = await api('prioridad', { id: product.id, anuncios: on ? '1' : '0' });
    if (current !== ficha) return;
    mergePhoto(p); message(on ? 'Ahora lleva HERO, LIFESTYLE y BENEFICIO.' : 'Ya no va a anuncios: lleva solo HERO.');
  }
  $('cv-prio-toggle').addEventListener('click', (e) => busy(e.currentTarget, async () => { await setPrio(!prios().includes('anuncios')); closeSheet(); if (tab === 'fotos') renderFotos(); }));
  async function restaurar() {
    const current = ficha, p = await api('restaurar', { id: product.id });
    if (current !== ficha) return;
    mergePhoto(p); message('Volvió la foto anterior a la web.'); if (tab === 'fotos') renderFotos();
  }
  $('cv-restore-menu').addEventListener('click', (e) => busy(e.currentTarget, async () => { closeSheet(); await restaurar(); }));
  // Tope del panel: «Hoy X de 0,50 USD». El recorte gratis no gasta ni se bloquea.
  function budget() {
    const g = MOTOR.gasto || {}, pct = Math.min(100, (g.hoy || 0) / (g.tope || 0.5) * 100);
    return h('p', { class: 'cv-budget' }, h('span', { class: 'cv-meter' }, h('i', { style: `width:${pct}%` })),
      g.lleno ? `Límite de hoy alcanzado · ${usd(g.hoy)} de ${usd(g.tope)}` : `Hoy ${usd(g.hoy)} de ${usd(g.tope)}`);
  }
  // Escalones, de lo gratis a lo caro, con su coste a la vista.
  function ladder(done, runK, only) {
    const STEPS = { recorte: ['Recorte gratis', 'VPS · ~7 s', '0 USD'], ia: ['IA de pago', 'gpt-image-1.5 · 20–60 s', `~${usd(MOTOR.gasto?.ia || 0.15)}`], codex: ['Corrector ChatGPT', 'Solo ocasional, a mano', '—'] };
    return h('ol', { class: 'cv-ladder' }, (only || ['recorte', 'ia', 'codex']).map((k, i) => {
      const s = (done || []).find((x) => x.escalon === k);
      const off = k === 'codex' || (k === 'recorte' && !MOTOR.recorte) || (k === 'ia' && !MOTOR.ia);
      const cls = s ? (s.ok ? 'ok' : 'ko') : runK === k ? 'run' : off ? 'off' : '';
      const note = s ? (s.ok ? `Hecho en ${s.segundos || 0} s` : s.motivo || 'No sirvió') : off && k !== 'codex' ? 'Aún no disponible' : STEPS[k][1];
      return h('li', { class: cls }, h('span', { class: 'cv-n2' }, s ? icon(s.ok ? 'check' : 'x') : String(i + 1)), h('b', { text: STEPS[k][0] }), h('small', { text: note }),
        h('em', { text: s ? (s.coste ? usd(s.coste) : '0 USD') : STEPS[k][2] }));
    }));
  }
  // Fotos de partida: hasta 2, de las reales del producto o una hecha con el móvil.
  function bases() {
    const ids = realPhotos().map((f) => String(f.id));
    fotoState.bases = fotoState.bases.filter((b) => b === 'local' ? fotoState.local : ids.includes(b));
    if (!fotoState.bases.length) { if (fotoState.local) fotoState.bases = ['local']; else if (ids[0]) fotoState.bases = [ids[0]]; }
    return fotoState.bases;
  }
  const baseUrl = (b) => b === 'local' ? fotoState.local?.url : (realPhotos().find((f) => String(f.id) === b) || {}).url;
  function toggleBase(b) {
    const B = bases();
    if (B[0] === b) { if (B.length === 2) fotoState.bases = [B[1], B[0]]; }
    else if (B.includes(b)) fotoState.bases = [B[0]];
    else fotoState.bases = fotoState.bad ? [b] : [B[0], b].filter(Boolean).slice(0, 2);
    fotoState.bad = false; renderFotos();
  }
  $('cv-camera').addEventListener('change', (e) => {
    const file = e.target.files[0]; e.target.value = ''; if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) { message('Elige JPG, PNG o WebP de hasta 8 MB.'); return; }
    if (fotoState.local) URL.revokeObjectURL(fotoState.local.url);
    fotoState.local = { file, url: URL.createObjectURL(file) }; fotoState.bases = ['local']; fotoState.bad = false;
    message('Foto añadida como foto de partida.'); renderFotos();
  });
  function renderFotos() {
    if (!product?.id || $('cv-panel-fotos').hidden) return;
    renderPieces();
    const body = $('cv-foto-body'), k = fotoState.pieza || 'hero', pz = piece(k);
    if (job && job.id === product.id && job.pieza === k) return showWork(body);
    const chosen = fotoState.option && optionsOf(k).find((o) => o.id === fotoState.option);
    if (chosen) return showCompare(body, chosen);
    const pend = unreviewed(k);
    if (!fotoState.more && pend.length && k !== 'ben') return showCompare(body, pend[pend.length - 1]);
    if (!fotoState.more && k !== 'ben' && (ESTADO[pz.estado] || (k === 'hero' && product.hero_en_web))) return showDone(body, k);
    if (k !== 'hero' && !product.brief?.matriz_ok) return showMatrix(body);
    if (k === 'ben') return showBen(body);
    return showPick(body, k);
  }
  function showPick(body, k) {
    const B = bases(), has = B.length > 0, bad = fotoState.bad || !has;
    const mini = (b, url, label) => { const n = B.indexOf(b) + 1; return h('button', { type: 'button', class: 'cv-mini2', 'aria-pressed': String(n > 0), 'aria-label': label, onclick: () => toggleBase(b) }, h('img', { src: url, alt: '' }), n ? h('span', { class: 'cv-bn', text: String(n) }) : null); };
    const kids = [];
    const err = fotoState.error && fotoState.error.pieza === k ? fotoState.error : null;
    if (err) kids.push(h('div', { class: 'cv-warnbox' }, h('p', { text: err.message }), err.escalones.length ? ladder(err.escalones, '', k === 'hero' ? null : ['ia']) : null));
    kids.push(h('p', { class: 'cv-sec', text: 'Foto de partida' }));
    if (has) kids.push(h('div', { class: 'cv-src' }, h('img', { src: baseUrl(B[0]), alt: 'Foto real del producto' }), h('span', { class: 'cv-chiptag', text: 'Foto real' })));
    kids.push(h('div', { class: 'cv-strip', role: 'group', 'aria-label': 'Fotos de partida, hasta 2' },
      realPhotos().map((f, i) => mini(String(f.id), f.url, `Foto real ${i + 1}`)), fotoState.local ? mini('local', fotoState.local.url, 'Foto del móvil') : null,
      h('label', { class: 'cv-mini2 cv-cam', for: 'cv-camera', 'aria-label': 'Hacer foto' }, icon('cam', 'cv-ic'))));
    if (bad) {
      const pedir = cvContent.dudas && has ? btn('Pedir otra foto a la tienda', 'cv-small', (e) => busy(e.currentTarget, async () => {
        const current = ficha, r = await api('pieza', { id: product.id, pedir_foto: '1', motivo: 'La foto real no sirve para el HERO' });
        if (current !== ficha) return; mergePhoto(r); message('Pedido en Dudas del catálogo.');
      })) : null;
      kids.push(h('div', { class: 'cv-warnbox' }, h('p', { text: has ? 'Esta foto no sirve: haz otra.' : 'Falta una foto del producto.' }), guide(), pedir ? h('div', { class: 'cv-actions' }, pedir) : null));
    } else {
      kids.push(h('p', { class: 'cv-ok' }, icon('check'), 'Sirve', h('button', { type: 'button', class: 'cv-link', style: 'margin-left:auto', text: 'No sirve', onclick: () => { fotoState.bad = true; renderFotos(); } })));
      if (k === 'hero') kids.push(h('p', { class: 'cv-sec', text: 'HERO: fondo blanco puro, sin texto ni logo' }), ladder(null, '', null), budget());
      else kids.push(h('p', { class: 'cv-sec', text: 'Escena con persona' }), h('p', { class: 'cv-note2', text: `Resultado: «${product.brief?.resultado || ''}»` }), ladder(null, '', ['ia']), budget());
    }
    body.replaceChildren(...kids);
    const g = MOTOR.gasto || {}, off = (text) => btn(text, 'cv-primary', null, { disabled: true });
    let main;
    if (bad) main = h('label', { class: 'cv-btn cv-primary', for: 'cv-camera' }, icon('cam'), has ? 'Hacer otra foto' : 'Hacer foto');
    else if (job) main = btn(h('span', { class: 'cv-spin' }), 'cv-primary', null, { disabled: true }), main.append(' Otra foto en marcha');
    else if (k === 'hero' && !MOTOR.recorte && !MOTOR.ia) main = off('Motor de imágenes apagado');
    else if ((k !== 'hero' || !MOTOR.recorte) && g.lleno) main = off('Límite de hoy alcanzado');
    else if (k !== 'hero' && !MOTOR.ia) main = off('IA de pago sin configurar');
    else main = btn(`Crear ${PIEZA[k].n}`, 'cv-primary', () => crear(k, false));
    setFotobar(main);
  }
  // Crear es una sola llamada (hasta 150 s por escalón): se ve el tiempo y se puede seguir en la Ficha.
  async function crear(k, forzar) {
    if (job) { message('Otra foto en marcha. Espera a que termine.'); return; }
    const hechas = optionsOf(k).length, max = MOTOR.max || 3;
    if (!forzar && hechas >= max) {
      sheet(`Ya hay ${hechas} opciones`, h('p', { text: 'Elige una de las que hay o haz una más.' }), h('div', { class: 'cv-acts' },
        btn('Elegir de las que hay', 'cv-primary', () => { closeSheet(); fotoState.more = false; fotoState.option = optionsOf(k).at(-1).id; renderFotos(); }),
        btn('Hacer una más', '', () => { closeSheet(); crear(k, true); })));
      return;
    }
    const B = bases().slice(0, 2), fields = { id: product.id, pieza: k, escalon: k === 'hero' ? 'auto' : 'ia', forzar: forzar ? '1' : null };
    const id = product.id, name = $('cv-name').value.trim() || product.name, before = B[0] ? baseUrl(B[0]) : '';
    fotoState.error = null; fotoState.more = false; fotoState.option = '';
    job = { id, pieza: k, name, start: Date.now(), phase: 0, antes: before, n: hechas + 1 };
    renderFotos();
    let out = null, error = null;
    try {
      // Dos fotos de partida (o una del móvil) van como archivos; una sola real va por su id.
      if (B.length > 1 || B[0] === 'local') {
        for (const [i, b] of B.entries()) {
          fields[`base${i + 1}`] = b === 'local' ? fotoState.local.file
            : new File([await (await fetch((realPhotos().find((f) => String(f.id) === b) || {}).full || baseUrl(b), { credentials: 'same-origin' })).blob()], `base${i + 1}.jpg`, { type: 'image/jpeg' });
        }
      } else if (B[0]) fields.base_id = B[0];
      job.phase = 1;
      out = await api('crear', form(fields));
      job.phase = 2;
      if (out.opcion?.url) await cachedImage(out.opcion.url).catch(() => null);
    } catch (e) { error = e; }
    job = null; clearInterval(workTimer);
    if (out?.gasto) MOTOR.gasto = out.gasto; else if (error?.data?.gasto) MOTOR.gasto = error.data.gasto;
    if (product?.id === id) {
      if (out) { mergePhoto(out.producto); fotoState.option = out.opcion?.id || ''; fotoState.pieza = k; }
      else fotoState.error = { pieza: k, message: error.message, escalones: error.data?.escalones || [] };
      if (tab === 'fotos') renderFotos();
    }
    message(out ? (out.publicada ? `HERO de ${name} ya está en la web. Revísalo.` : `${PIEZA[k].n} de ${name} lista para revisar.`) : error.message);
    if (!$('cv-list-screen').hidden) list().catch(() => {});
  }
  let workTimer = 0;
  function showWork(body) {
    const max = MOTOR.max || 3;
    body.replaceChildren(h('p', { class: 'cv-sec', text: `Creando ${PIEZA[job.pieza].n} · opción ${Math.min(job.n, max)} de ${max}` }),
      job.antes ? h('div', { class: 'cv-src cv-dimmed' }, h('img', { src: job.antes, alt: '' }), h('span', { class: 'cv-chiptag', text: 'Foto real' })) : null,
      h('div', { class: 'cv-work', role: 'status', 'aria-live': 'off' }, h('div', { class: 'cv-work-row' }, h('b', { id: 'cv-work-step' }), h('span', { id: 'cv-work-sec', text: '0 s' })),
        h('div', { class: 'cv-track' }, h('i', { id: 'cv-work-bar' })), h('div', { class: 'cv-phases', id: 'cv-work-phases' }, ['En cola', 'Creando', 'Revisando'].map((t) => h('span', { text: t }))),
        h('div', { id: 'cv-work-ladder' })),
      h('p', { class: 'cv-note2', text: 'Suele tardar de 10 a 60 s. Puedes seguir en la Ficha: te aviso al terminar.' }));
    setFotobar(btn('Seguir en la Ficha', '', () => setTab('ficha')));
    clearInterval(workTimer);
    const step = () => {
      const el = $('cv-work-sec'); if (!job || !el) { clearInterval(workTimer); return; }
      const s = Math.floor((Date.now() - job.start) / 1000), recorte = job.pieza === 'hero' && MOTOR.recorte;
      // El servidor prueba primero el recorte gratis (~7 s); si no sirve, pasa a la IA de pago.
      const k = recorte && (s < 20 || !MOTOR.ia) ? 'recorte' : 'ia';
      el.textContent = `${s} s`;
      $('cv-work-step').textContent = k === 'recorte' ? 'Recorte gratis · 0 USD' : `IA de pago · ~${usd(MOTOR.gasto?.ia || 0.15)}`;
      $('cv-work-bar').style.width = `${Math.min(95, k === 'recorte' ? 10 + s * 6 : 30 + (s - (recorte ? 20 : 0)) * 1.5)}%`;
      [...$('cv-work-phases').children].forEach((x, i) => { x.className = i < job.phase ? 'done' : i === job.phase ? 'on' : ''; });
      $('cv-work-ladder').replaceChildren(ladder(null, k, job.pieza === 'hero' ? null : ['ia']));
    };
    step(); workTimer = setInterval(step, 1000);
  }
  // Comparar es obligatorio: los 3 estados se encienden al mover el deslizador.
  function showCompare(body, o) {
    const k = o.pieza, pz = piece(k), onWeb = k === 'hero' && product.hero_en_web && pz.opcion === o.id;
    const range = h('input', { type: 'range', min: '0', max: '100', value: '40', 'aria-label': 'Comparar antes y después' });
    const after = h('div', { class: 'cv-after', style: 'clip-path:inset(0 0 0 40%)' }, h('img', { src: o.url, alt: 'Después: foto nueva' }));
    const knob = h('span', { class: 'cv-knob', style: 'left:40%' }), before = o.antes || realPhotos()[0]?.url || '';
    const kids = [h('p', { class: 'cv-sec', text: `Compara con la real · ${PIEZA[k].n}` }),
      h('div', { class: 'cv-cmp' }, before ? h('img', { src: before, alt: 'Antes: foto real' }) : null, after, h('span', { class: 'cv-l', text: 'Antes' }), h('span', { class: 'cv-r', text: 'Después' }), knob, range),
      h('p', { class: 'cv-used' }, icon('check'), usedText(o.escalon, o.coste), o.fallo ? h('small', { text: `(${o.fallo})` }) : null)];
    if (o.cortado) kids.push(h('p', { class: 'cv-warn', text: 'Toca el borde: revisa que el producto salga entero.' }));
    const all = optionsOf(k);
    if (all.length > 1) {
      kids.push(h('div', { class: 'cv-strip', role: 'group', 'aria-label': 'Opciones' }, all.map((x, i) => h('button', { type: 'button', class: 'cv-mini2', 'aria-pressed': String(x.id === o.id),
        'aria-label': `Opción ${i + 1}${x.estado ? ` · ${ESTADO[x.estado]}` : ''}`, onclick: () => { fotoState.option = x.id; renderFotos(); } }, h('img', { src: x.url, alt: '' }), h('span', { class: 'cv-bn', text: String(i + 1) })))));
    }
    if (onWeb) kids.push(h('div', { class: 'cv-web' }, icon('check'), h('span', { text: 'Ya está en la web' }), product.restaurable ? h('button', { type: 'button', class: 'cv-link', text: 'Restaurar la anterior', onclick: (e) => busy(e.currentTarget, restaurar) }) : null));
    let compared = !(o.cambio || []).length, slid = false;
    const states = ['rechazada', 'revision', 'aprobada'].map((s) => btn(ESTADO[s], s === 'rechazada' ? 'cv-bad' : s === 'aprobada' ? 'cv-primary' : '', () => decide(o, s), { disabled: true, 'data-estado': s }));
    const enable = () => states.forEach((b) => { b.disabled = !(slid && compared); });
    if (!compared) {
      // La ficha cambió desde que se hizo esta opción: se dice qué cambió y se pide compararla.
      const what = { foto: 'la foto original', texto: 'el texto de la ficha' }, reasons = o.cambio.map((x) => what[x]).filter(Boolean);
      const box = h('input', { type: 'checkbox', 'data-nodirty': true }); box.addEventListener('change', () => { compared = box.checked; enable(); });
      kids.push(h('p', { class: 'cv-warn', text: `${reasons.length ? `Desde entonces cambió ${reasons.join(' y ')}.` : 'La ficha cambió desde que se hizo esta opción.'} Compárala con los datos actuales antes de decidir.` }),
        h('label', { class: 'cv-switch' }, box, h('span', { text: 'Ya la comparé con la ficha actual' })));
    }
    const hint = h('p', { class: 'cv-note2', text: 'Desliza para comparar antes de decidir.' });
    kids.push(hint);
    if (o.estado) kids.push(h('p', { class: 'cv-note2', text: `Esta opción está ${ESTADO[o.estado].toLowerCase()}.` }));
    range.addEventListener('input', () => { after.style.clipPath = `inset(0 0 0 ${range.value}%)`; knob.style.left = `${range.value}%`; if (!slid) { slid = true; hint.hidden = true; enable(); } });
    body.replaceChildren(...kids);
    setFotobar(h('div', { class: 'cv-states' }, states));
  }
  async function sendPieza(o, estado, extra = {}) {
    const id = product.id, r = await api('pieza', { id, pieza: o.pieza, estado, opcion: o.id, ...extra });
    if (product?.id !== id) return null;
    mergePhoto(r); fotoState.option = ''; return r;
  }
  function decide(o, s) {
    if (s === 'rechazada') {
      const picked = new Set(), done = (r) => message(r.restaurada ? 'Rechazada. Volvió la foto anterior a la web.' : 'Rechazada. Apuntado en Preferencias.');
      sheet('¿Qué falló?', chips(MOTOR.motivos || [], picked), h('p', { class: 'cv-note2', text: 'Se apunta en Preferencias para que no se repita.' }), h('div', { class: 'cv-acts' },
        btn('Repetir', 'cv-primary', (e) => busy(e.currentTarget, async () => { const r = await sendPieza(o, 'rechazada', { motivos: [...picked] }); closeSheet(); if (!r) return; done(r); fotoState.more = true; await crear(o.pieza, false); })),
        btn('Dejarla rechazada', '', (e) => busy(e.currentTarget, async () => { const r = await sendPieza(o, 'rechazada', { motivos: [...picked] }); closeSheet(); if (!r) return; done(r); fotoState.more = false; renderFotos(); }))));
      return;
    }
    if (s === 'revision') { busy(null, async () => { if (await sendPieza(o, 'revision')) { message('En revisión: queda en Dudas del catálogo.'); renderFotos(); } }); return; }
    const onWeb = o.pieza === 'hero' && product.hero_en_web && piece('hero').opcion === o.id;
    if (o.pieza !== 'hero' || onWeb) {
      busy(null, async () => { if (await sendPieza(o, 'aprobada', onWeb ? { web: '1' } : {})) { message(onWeb ? 'HERO aprobado. Ya está en la web.' : `${PIEZA[o.pieza].n} aprobada.`); renderFotos(); } });
      return;
    }
    askWeb(o);
  }
  // A la web solo sube el HERO aprobado: WebP 1600 px en blanco puro, nunca una tarjeta.
  function askWeb(o) {
    sheet('¿Ponerla en la web?', h('div', { class: 'cv-webp' }, h('div', { class: 'cv-pvb' }, h('img', { src: o.url, alt: '' })), h('ul', {}, h('li', { text: 'Fondo blanco puro' }), h('li', { text: 'Sin texto ni logo' }), h('li', { text: 'WebP 1600 px' }))),
      h('div', { class: 'cv-acts' },
        btn('Poner en la web', 'cv-primary', (e) => busy(e.currentTarget, async () => { const r = await sendPieza(o, 'aprobada', { web: '1' }); closeSheet(); if (r) { message('HERO en la web.'); renderFotos(); } })),
        btn('Ahora no', '', (e) => busy(e.currentTarget, async () => { const r = await sendPieza(o, 'aprobada', { web: '0' }); closeSheet(); if (r) { message('HERO aprobado. Aún no está en la web.'); renderFotos(); } }))));
  }
  function showDone(body, k) {
    const pz = piece(k), onWeb = k === 'hero' && product.hero_en_web, url = onWeb ? product.image : pz.url;
    const chip = ESTADO[pz.estado] ? h('span', { class: `cv-st ${pz.estado}`, text: ESTADO[pz.estado] }) : onWeb ? h('span', { class: 'cv-st run', text: 'En la web' }) : null;
    const kids = [h('div', { class: 'cv-done1' }, h('div', { class: 'cv-pvb' }, url ? h('img', { src: url, alt: `${PIEZA[k].n} actual` }) : null),
      h('div', {}, chip, usedText(pz.escalon, pz.coste) ? h('p', { class: 'cv-used', text: usedText(pz.escalon, pz.coste) }) : null,
        k === 'hero' ? h('p', { class: 'cv-used', text: onWeb ? 'Ya está en la web' : 'Aún no está en la web' }) : null))];
    if (onWeb && product.restaurable) kids.push(h('div', { class: 'cv-web' }, icon('back'), h('span', { text: '¿No te gusta? Vuelve la de antes.' }), h('button', { type: 'button', class: 'cv-link', text: 'Restaurar la anterior', onclick: (e) => busy(e.currentTarget, restaurar) })));
    const approved = k === 'hero' && !onWeb && pz.estado === 'aprobada' && optionsOf('hero').find((o) => o.id === pz.opcion);
    if (approved) kids.push(btn('Poner en la web', 'cv-wide', () => askWeb(approved)));
    kids.push(h('button', { type: 'button', class: 'cv-link', text: `Repetir ${PIEZA[k].n}`, onclick: () => { fotoState.more = true; fotoState.option = ''; renderFotos(); } }));
    body.replaceChildren(...kids);
    const next = ['hero', 'life', 'ben'].find((x) => x !== k && (x === 'hero' || prios().length) && piece(x).estado !== 'aprobada' && !(x === 'hero' && product.hero_en_web));
    setFotobar(next ? btn(`Siguiente: ${PIEZA[next].n}`, 'cv-primary', () => pickPiece(next)) : btn('Hacer anuncios', 'cv-primary', () => setTab('anuncio')));
  }
  // Antes de LIFESTYLE y BENEFICIO: duda → resultado → prueba (+ condición y fuente).
  const MX = [['duda', 'Duda'], ['resultado', 'Resultado'], ['prueba', 'Prueba'], ['condicion', 'Condición']];
  function matrixForm(onDone) {
    const b = product.brief || {}, inputs = {};
    const card = h('div', { class: 'cv-mxc' }, MX.map(([k, l]) => {
      inputs[k] = h('input', { id: `cv-mx-${k}`, value: b[k] || '', maxlength: '160', 'data-nodirty': true, onkeydown: (e) => { if (e.key === 'Enter') e.preventDefault(); } });
      return h('div', { class: 'cv-mx' }, h('label', { for: `cv-mx-${k}`, text: l }), inputs[k]);
    }), h('small', { class: 'cv-mxsrc', text: b.fuente || 'Ficha · características' }));
    const ok = () => inputs.duda.value.trim() && inputs.resultado.value.trim() && inputs.prueba.value.trim();
    const confirm = btn('Confirmar', 'cv-primary', (e) => busy(e.currentTarget, async () => {
      const current = ficha, p = await api('matriz', { id: product.id, version: product.version, duda: inputs.duda.value, resultado: inputs.resultado.value, prueba: inputs.prueba.value,
        condicion: inputs.condicion.value, fuente: b.fuente || 'Ficha · características', confirmada: '1' });
      if (current !== ficha) return;
      mergePhoto(p); message('Duda → resultado → prueba confirmada.'); onDone();
    }), { disabled: !ok() });
    Object.values(inputs).forEach((i) => i.addEventListener('input', () => { confirm.disabled = !ok(); }));
    return { card, confirm };
  }
  function showMatrix(body) {
    const { card, confirm } = matrixForm(renderFotos);
    body.replaceChildren(h('p', { class: 'cv-sec', text: 'Antes: duda → resultado → prueba' }), card, h('p', { class: 'cv-note2', text: 'Solo datos confirmados. El titular del BENEFICIO es el resultado.' }));
    setFotobar(confirm);
  }
  const matrixView = () => { const b = product?.brief || {}; return h('div', { class: 'cv-mxc' }, MX.map(([k, l]) => h('div', { class: 'cv-mx' }, h('span', { text: l }), h('b', { text: b[k] || '—' }))), h('small', { class: 'cv-mxsrc', text: b.fuente || 'Ficha · características' })); };
  function editMatrix(after) { const { card, confirm } = matrixForm(() => { closeSheet(); after(); }); sheet('Duda → resultado → prueba', card, h('div', { class: 'cv-acts' }, confirm)); }
  function showBen(body) {
    body.replaceChildren(h('p', { class: 'cv-sec', text: 'BENEFICIO' }), matrixView(), h('p', { class: 'cv-note2', text: 'Se arma en Anuncio: foto + resultado + prueba con su condición. Sin precio ni logo.' }),
      h('button', { type: 'button', class: 'cv-link', text: 'Editar', onclick: () => editMatrix(renderFotos) }));
    setFotobar(btn('Hacer BENEFICIO', 'cv-primary', () => { layoutValue = 'ben'; setTab('anuncio'); }));
  }
  /*@@ANUNCIO@@*/
})();
