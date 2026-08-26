import fs from 'node:fs';

const php = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-shipping-rates.php', 'utf8');
const css = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/shipping-quote.css', 'utf8');
const js = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/shipping-quote.js', 'utf8');
const nav = fs.readFileSync('wordpress/casa-viva-dropship-core/assets/customer-navigation.js', 'utf8');

for (const repeated of ['Tarifas Casa Viva', 'Calculadora de mensajería', 'NEXO no calcula precios', 'Origen<input']) {
  if (php.includes(repeated)) throw new Error(`Copy redundante en Tarifas: ${repeated}`);
}
if (!php.includes('>Ver tarifa<')) throw new Error('Falta la acción principal Ver tarifa.');
if (php.includes('<header><h1>Tarifa de mensajería</h1></header>')) throw new Error('La página no debe repetir el título del tema.');
if (!css.includes('grid-template-columns: repeat(2, minmax(0, 1fr))')) throw new Error('Copiar y Compartir no tienen columnas iguales.');
if (!css.includes('.cvd-quote-actions button { width: 100%')) throw new Error('Las acciones de resultado no ocupan el mismo ancho.');
if (/body\.cvd-quote-page \.entry-(?:header|title)/.test(css)) throw new Error('Tarifas no debe ocultar el único título canónico del tema.');
if (/cvd-quote-page \.cv-whatsapp/.test(css)) throw new Error('Tarifas debe conservar el acceso a WhatsApp.');
if (!js.includes('document.body.classList.add("cvd-quote-page")')) throw new Error('Falta el scope visual de Tarifas.');
if (!nav.includes("details.cv-mobile-nav[open]") || !nav.includes("!menu.contains(event.target)")) throw new Error('El menú móvil no se cierra al tocar fuera.');

console.log('Tarifas móvil: copy, acciones y menú verificados.');
