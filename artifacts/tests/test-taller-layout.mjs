// Pruebas de las funciones puras del Taller de fotos (Core 3.14.89): node artifacts/tests/test-taller-layout.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const require = createRequire(import.meta.url);
const { layout, wrapLines } = require('../../wordpress/casa-viva-dropship-core/assets/gestora-taller.js');

// Foto horizontal 4000x3000 en cuadrado 1080: llena (cover) = escala por el lado corto.
let L = layout(4000, 3000, 1080, 1080, 'cover', 1, 0, 0);
assert.equal(Math.round(L.dh), 1080); assert.equal(Math.round(L.dw), 1440);
assert.equal(Math.round(L.dx), -180); // centrada
// No deja huecos aunque se arrastre de más.
L = layout(4000, 3000, 1080, 1080, 'cover', 1, 9999, 9999);
assert.equal(Math.round(L.dx), 0); assert.equal(Math.round(L.dy), 0);
L = layout(4000, 3000, 1080, 1080, 'cover', 1, -9999, 0);
assert.equal(Math.round(L.dx + L.dw), 1080);
// Entera (fit): cabe completa con margen.
L = layout(4000, 3000, 1080, 1080, 'fit', 1, 0, 0);
assert.ok(L.dw <= 1080 && L.dh <= 1080); assert.ok(Math.abs(L.dx + L.dw / 2 - 540) < 1);
// Acercar 2x: la imagen crece al doble y sigue cubriendo.
L = layout(1000, 1000, 1080, 1080, 'cover', 2, 0, 0);
assert.equal(Math.round(L.dw), 2160);
// Entera con banda: el área es más baja.
L = layout(1000, 1000, 1080, 890, 'fit', 1, 0, 0);
assert.ok(L.dh <= 890);
// Vertical: foto horizontal llena el alto.
L = layout(4000, 3000, 1080, 1920, 'cover', 1, 0, 0);
assert.equal(Math.round(L.dh), 1920);

const m = (t) => t.length * 10;
assert.deepEqual(wrapLines('Filtro de aceite', 200, 1, m), ['Filtro de aceite']);
assert.deepEqual(wrapLines('Filtro de aceite Suzuki GN 125', 200, 2, m), ['Filtro de aceite', 'Suzuki GN 125']);
assert.equal(wrapLines('Filtro de aceite Suzuki GN 125 original', 100, 2, m), null);
assert.equal(wrapLines('Palabralarguisimaquenocabe', 100, 2, m), null);
assert.deepEqual(wrapLines('', 100, 2, m), []);
console.log('OK: layout y wrapLines');
