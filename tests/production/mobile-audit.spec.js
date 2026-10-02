/* eslint-disable @typescript-eslint/no-require-imports */
// Auditoría móvil de solo lectura contra un sitio desplegado.
// Solo navega con GET (salvo una consulta a Curru): no añade al carrito, no envía formularios ni inicia sesión.
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const baseURL = (process.env.PRODUCTION_URL || 'https://casavivadecuba.com').replace(/\/$/, '');
const outDir = process.env.PRODUCTION_EVIDENCE_DIR || 'prod-evidence';
const viewport = { width: 390, height: 844 };
const report = { base_url: baseURL, viewport, generated_at: new Date().toISOString(), pages: [] };

const FATAL = /There has been a critical error|Fatal error:|Parse error:|Warning: .* on line \d+/i;

function slug(url) {
  const p = new URL(url).pathname.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-');
  return p || 'inicio';
}

async function settle(page) {
  // Recorre la página para disparar lazy-loading antes de medir imágenes.
  await page.evaluate(async () => {
    const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 120));
    }
    window.scrollTo(0, 0);
  });
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
}

async function inspect(page) {
  return page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const overflow = [];
    document.querySelectorAll('body *').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.right > vw + 1 && getComputedStyle(el).position !== 'fixed') {
        const cls = typeof el.className === 'string' ? el.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
        overflow.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${cls ? '.' + cls : ''} right=${Math.round(r.right)}`);
      }
    });
    const sel = (el) => {
      const parts = [];
      for (let n = el; n && n !== document.body && parts.length < 4; n = n.parentElement) {
        const cls = typeof n.className === 'string' ? n.className.trim().split(/\s+/).filter(Boolean).slice(0, 2).join('.') : '';
        parts.unshift(`${n.tagName.toLowerCase()}${n.id ? '#' + n.id : ''}${cls ? '.' + cls : ''}`);
      }
      return parts.join(' > ');
    };
    // Raíces del desbordamiento: elementos que salen del viewport cuyo padre no sale.
    const overflowRoots = [];
    document.querySelectorAll('body *').forEach((el) => {
      const r = el.getBoundingClientRect();
      const parent = el.parentElement;
      if (!parent || r.width === 0 || r.right <= vw + 1 || getComputedStyle(el).position === 'fixed') return;
      const pr = parent.getBoundingClientRect();
      if (pr.right > vw + 1) return;
      const ps = getComputedStyle(parent);
      if (ps.overflowX !== 'visible' && ps.overflowX !== 'clip') return;
      overflowRoots.push({
        element: sel(el),
        right: Math.round(r.right),
        width: Math.round(r.width),
        parent_display: ps.display,
        parent_overflow_x: ps.overflowX,
        parent_flex_wrap: ps.flexWrap,
        parent_html: parent.outerHTML.replace(/\s+/g, ' ').slice(0, 400),
      });
    });
    const widest = [...document.querySelectorAll('body *')]
      .map((el) => ({ el, r: el.getBoundingClientRect() }))
      .filter(({ el, r }) => {
        if (r.width === 0 || r.right <= vw + 1) return false;
        // Ignora lo que ya recorta un ancestro con scroll/hidden: no ensancha la página.
        // También ignora lo fijo: sigue al viewport de layout ya ensanchado.
        if (getComputedStyle(el).position === 'fixed') return false;
        for (let n = el.parentElement; n && n !== document.documentElement; n = n.parentElement) {
          const ns = getComputedStyle(n);
          if (ns.overflowX !== 'visible' || ns.position === 'fixed') return false;
        }
        return true;
      })
      .sort((a, b) => b.r.right - a.r.right)
      .slice(0, 6)
      .map(({ el, r }) => {
        const cs = getComputedStyle(el);
        return { html: el.outerHTML.replace(/\s+/g, ' ').slice(0, 300), element: sel(el), right: Math.round(r.right), position: cs.position, transform: cs.transform === 'none' ? '' : cs.transform, parent_overflow_x: el.parentElement ? getComputedStyle(el.parentElement).overflowX : '' };
      });
    const imgs = [...document.images];
    const broken = imgs
      .filter((i) => i.complete && i.naturalWidth === 0 && (i.currentSrc || i.src))
      .map((i) => i.currentSrc || i.src);
    const repaired = imgs.filter((i) => i.dataset.cvdCategoryRepair === '1').map((i) => i.alt);
    const placeholders = imgs.filter((i) => /woocommerce-placeholder/.test(i.currentSrc || i.src)).length;
    const noAlt = imgs.filter((i) => !i.hasAttribute('alt')).length;
    const smallTargets = [...document.querySelectorAll('a[href], button, input[type=submit]')]
      .filter((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && (r.height < 24 || r.width < 24) && getComputedStyle(el).visibility !== 'hidden';
      }).length;
    return {
      title: document.title,
      h1: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim().slice(0, 120)),
      meta_description: [...document.querySelectorAll('meta[name="description"]')].map((m) => m.content),
      canonical: [...document.querySelectorAll('link[rel="canonical"]')].map((l) => l.href),
      scroll_width: document.documentElement.scrollWidth,
      client_width: vw,
      overflow_elements: overflow.slice(0, 8),
      overflow_roots: overflowRoots.slice(0, 10),
      overflow_widest: widest,
      body_overflow_x: getComputedStyle(document.body).overflowX + '/' + getComputedStyle(document.documentElement).overflowX,
      images_total: imgs.length,
      images_broken: [...new Set(broken)].slice(0, 20),
      images_repaired: repaired,
      woo_placeholders: placeholders,
      images_without_alt: noAlt,
      small_tap_targets: smallTargets,
      has_customer_nav: !!document.querySelector('.cvd-customer-nav'),
      has_quote: !!document.querySelector('[data-cvd-quote]'),
      product_links: [...new Set([...document.querySelectorAll('a[href*="/producto/"]')].map((a) => a.href.split('#')[0]))].slice(0, 3),
      category_links: [...new Set([...document.querySelectorAll('a[href*="/categoria-producto/"]')].map((a) => a.href.split('#')[0]))].slice(0, 8),
      launch_assets: !!document.querySelector('link[href*="launch-polish.css"], script[src*="launch-polish.js"]'),
    };
  });
}

// Localiza por bisección (ocultando nodos solo en el DOM local) qué elemento ensancha la página.
async function overflowCulprit(page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    const wide = () => root.scrollWidth > root.clientWidth + 1;
    if (!wide()) return [];
    const path = [];
    let node = document.body;
    for (let depth = 0; depth < 25 && node; depth++) {
      let next = null;
      for (const child of node.children) {
        const prev = child.style.getPropertyValue('display');
        const prio = child.style.getPropertyPriority('display');
        child.style.setProperty('display', 'none', 'important');
        const fixed = !wide();
        child.style.setProperty('display', prev, prio);
        if (fixed) { next = child; break; }
      }
      if (!next) break;
      const cs = getComputedStyle(next);
      const r = next.getBoundingClientRect();
      const cls = typeof next.className === 'string' ? next.className.trim().split(/\s+/).slice(0, 3).join('.') : '';
      path.push({
        element: `${next.tagName.toLowerCase()}${next.id ? '#' + next.id : ''}${cls ? '.' + cls : ''}`,
        left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width),
        position: cs.position, display: cs.display, overflow_x: cs.overflowX,
        transform: cs.transform === 'none' ? '' : cs.transform,
        before_width: getComputedStyle(next, '::before').width, after_width: getComputedStyle(next, '::after').width,
      });
      node = next;
    }
    // Si ningún hijo aislado lo explica, prueba a ocultar cada clase de descendiente a la vez.
    if (node && node !== document.body && path.length) {
      const classes = new Set();
      node.querySelectorAll('*').forEach((el) => {
        if (typeof el.className === 'string') el.className.trim().split(/\s+/).filter(Boolean).forEach((c) => classes.add(c));
      });
      const fixers = [];
      for (const c of classes) {
        const els = [...node.getElementsByClassName(c)];
        const prev = els.map((el) => el.style.getPropertyValue('display'));
        els.forEach((el) => el.style.setProperty('display', 'none', 'important'));
        if (!wide()) {
          const sample = els[0];
          const cs = getComputedStyle(sample);
          fixers.push({ class: c, count: els.length, position: cs.position, html: sample.outerHTML.replace(/\s+/g, ' ').slice(0, 250) });
        }
        els.forEach((el, i) => el.style.setProperty('display', prev[i]));
      }
      path.push({ fixing_descendant_classes: fixers.slice(0, 12) });
    }
    return path;
  });
}

async function audit(page, url) {
  const consoleErrors = [];
  const failed = [];
  page.removeAllListeners('console');
  page.removeAllListeners('requestfailed');
  page.removeAllListeners('response');
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200)); });
  page.on('requestfailed', (r) => failed.push(`${r.failure()?.errorText || 'failed'} ${r.url()}`.slice(0, 200)));
  page.on('response', (r) => { if (r.status() >= 400 && r.request().resourceType() !== 'document') failed.push(`${r.status()} ${r.url()}`.slice(0, 200)); });
  const started = Date.now();
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
  const status = response ? response.status() : 0;
  const html = response ? await response.text().catch(() => '') : '';
  await settle(page);
  const data = await inspect(page);
  data.overflow_culprit = await overflowCulprit(page);
  const name = slug(page.url());
  await page.screenshot({ path: path.join(outDir, `${name}.png`) });
  await page.screenshot({ path: path.join(outDir, `${name}-full.png`), fullPage: true }).catch(() => {});
  const entry = {
    url, final_url: page.url(), status, load_ms: Date.now() - started,
    fatal_marker: FATAL.test(html), console_errors: consoleErrors.slice(0, 10),
    failed_requests: [...new Set(failed)].slice(0, 15), ...data,
  };
  report.pages.push(entry);
  return entry;
}

test.describe.configure({ mode: 'serial' });
test.use({ viewport, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 CasaVivaAudit/1.0' });

test.beforeAll(() => fs.mkdirSync(outDir, { recursive: true }));
test.afterAll(() => fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2)));

test('auditoría móvil de producción', async ({ page }) => {
  test.setTimeout(10 * 60 * 1000);

  const home = await audit(page, `${baseURL}/`);
  const shop = await audit(page, `${baseURL}/tienda/`);
  const tariffs = await audit(page, `${baseURL}/tarifas-mensajeria/`);
  for (const p of ['/mi-cuenta/', '/area-gestoras/', '/area-mensajeros/']) await audit(page, baseURL + p);
  const categories = [...new Set([...home.category_links, ...shop.category_links])].slice(0, 8);
  for (const c of categories) await audit(page, c);
  for (const p of shop.product_links.slice(0, 2)) await audit(page, p);

  // Contrato desplegado de CV-LAUNCH-POLISH-001.
  expect.soft(home.title).toBe('Casa Viva | Tienda online para el hogar en La Habana');
  expect.soft(home.h1).toHaveLength(1);
  expect.soft(home.meta_description).toHaveLength(1);
  expect.soft(home.canonical).toHaveLength(1);
  expect.soft(home.launch_assets).toBe(true);
  expect.soft(shop.launch_assets).toBe(false);
  expect.soft(tariffs.has_quote).toBe(true);

  for (const p of report.pages) {
    expect.soft(p.status, `${p.url} HTTP`).toBeLessThan(400);
    expect.soft(p.fatal_marker, `${p.url} error fatal`).toBe(false);
    expect.soft(p.scroll_width, `${p.url} scroll horizontal en móvil`).toBeLessThanOrEqual(p.client_width + 1);
  }
});

// Curru: abre el asistente y hace una pregunta de catálogo. Solo consulta (POST de lectura); no añade al carrito.
test('Curru responde con productos', async ({ page }) => {
  test.setTimeout(2 * 60 * 1000);
  await page.goto(`${baseURL}/`, { waitUntil: 'domcontentloaded' });
  const curru = { launcher: false, opened: false, answer: '', products: 0, photo: false };
  const launcher = page.locator('.cvd-assistant-launcher');
  curru.launcher = await launcher.isVisible().catch(() => false);
  curru.photo = await page.locator('.cvd-assistant-launcher.has-photo').count() > 0;
  if (curru.launcher) {
    await launcher.click();
    const panel = page.locator('#cvd-contextual-assistant');
    curru.opened = await panel.isVisible().catch(() => false);
    if (curru.opened) {
      await page.fill('#cvd-contextual-question', 'sartén');
      await page.press('#cvd-contextual-question', 'Enter');
      await page.waitForSelector('.cvd-curru-reply', { timeout: 30000 }).catch(() => {});
      curru.answer = (await page.locator('.cvd-curru-reply .cvd-curru-bubble').first().textContent().catch(() => '')) || '';
      curru.products = await page.locator('.cvd-curru-reply .cvd-curru-product').count();
      await page.waitForTimeout(800);
    }
  }
  await page.screenshot({ path: path.join(outDir, 'curru.png') });
  report.curru = curru;
  expect.soft(curru.launcher, 'Curru visible').toBe(true);
  expect.soft(curru.opened, 'Curru abre').toBe(true);
  expect.soft(curru.products, 'Curru muestra productos').toBeGreaterThan(0);
});
