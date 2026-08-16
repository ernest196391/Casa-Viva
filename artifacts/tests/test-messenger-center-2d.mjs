import fs from 'node:fs';

const source = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/portal.js', 'utf8');
const required = [
  'enhanceMessengerCenter',
  'cvd-messenger-center',
  'cvd-messenger-primary',
  'https://wa.me/',
  'tel:+',
  "map.textContent = 'Navegar'",
  "title.textContent = 'Entrega activa'",
];
for (const marker of required) {
  if (!source.includes(marker)) throw new Error(`Falta contrato 2D: ${marker}`);
}
console.log('OK: contrato del Centro Operativo del Mensajero.');
