(function () {
  'use strict';
  var app = document.querySelector('[data-cvga]');
  if (!app) return;

  // Secciones del portal clásico → vista de la app.
  var legacy = {
    'mis-clientes': 'clientes', precios: 'precios', invitar: 'invitar', ventas: 'ventas', mensajeria: 'ventas',
    pagos: 'cobrar', materiales: 'materiales', perfil: 'perfil'
  };
  Object.keys(legacy).forEach(function (id) { var el = document.getElementById(id); if (el) el.setAttribute('data-view', legacy[id]); });
  var perProduct = document.querySelector('.cvd-products-pricing'); if (perProduct) perProduct.setAttribute('data-view', 'precios');

  // Enlaces viejos (redirecciones tras guardar) que apuntan a otra palabra.
  var alias = { dashboard: 'inicio', catalogo: 'tienda', clientes: 'clientes', 'mis-clientes': 'clientes', comisiones: 'ventas', mensajeria: 'ventas', pagos: 'cobrar' };
  var titles = { inicio: 'Inicio', tienda: 'Mi tienda', precios: 'Mis precios', clientes: 'Clientes', herramientas: 'Herramientas', materiales: 'Fotos y textos listos', ventas: 'Ventas', cobrar: 'Cobrar', invitar: 'Invitar gestoras', perfil: 'Mi perfil' };
  var views = Array.prototype.slice.call(document.querySelectorAll('[data-view]'));
  var titleEl = app.querySelector('[data-cvga-title]');

  function current() {
    var h = (window.location.hash || '').replace('#', '');
    h = alias[h] || h;
    return titles[h] ? h : 'inicio';
  }
  function show(view, scroll) {
    views.forEach(function (el) { el.hidden = el.getAttribute('data-view') !== view; });
    app.querySelectorAll('[data-cvga-go]').forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-cvga-go') === view); if (a.classList.contains('is-active')) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    if (titleEl) titleEl.textContent = titles[view];
    document.title = titles[view] + ' · Casa Viva';
    // Formularios que se envían a esta misma página: al volver, abrir la misma vista.
    views.forEach(function (el) { el.querySelectorAll('form').forEach(function (f) { var a = f.getAttribute('action'); if (!a || a.charAt(0) === '#') f.setAttribute('action', '#' + el.getAttribute('data-view')); }); });
    app.classList.remove('is-open');
    if (scroll) window.scrollTo(0, 0);
  }
  window.addEventListener('hashchange', function () { show(current(), true); });
  app.addEventListener('click', function (e) {
    var go = e.target.closest('[data-cvga-go]');
    if (go) { e.preventDefault(); var v = go.getAttribute('data-cvga-go'); if (window.location.hash === '#' + v) show(v, true); else window.location.hash = v; return; }
    if (e.target.closest('[data-cvga-open]')) { app.classList.add('is-open'); return; }
    if (e.target.closest('[data-cvga-close]')) { app.classList.remove('is-open'); return; }
    if (e.target.closest('[data-cvga-collapse]')) { app.classList.toggle('is-mini'); try { localStorage.setItem('cvga-mini', app.classList.contains('is-mini') ? '1' : '0'); } catch (ignore) {} return; }
    if (e.target.closest('[data-cvga-logout]') && !window.confirm('¿Salir de tu cuenta? Para volver a entrar escribe "mi acceso" al WhatsApp de Casa Viva.')) { e.preventDefault(); return; }
    if (e.target.closest('[data-cvga-curru]')) { var launcher = document.querySelector('.cvd-assistant-launcher'); if (launcher) launcher.click(); return; }
    var save = e.target.closest('[data-cvga-save-qr]');
    if (save) { var canvas = app.querySelector('#cvga-tienda canvas'); if (canvas) { var link = document.createElement('a'); link.download = 'mi-tienda-casa-viva.png'; link.href = canvas.toDataURL('image/png'); link.click(); } }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') app.classList.remove('is-open'); });
  try { if (localStorage.getItem('cvga-mini') === '1') app.classList.add('is-mini'); } catch (ignore) {}

  // El buscador de precios estaba dentro del formulario: Enter lo enviaba vacío ("0 precios guardados").
  var priceSearch = document.getElementById('cvd-price-search');
  if (priceSearch) priceSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });

  show(current(), false);
})();
