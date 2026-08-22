import fs from 'node:fs';

const delivery = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-delivery.php', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');

for (const marker of ['money_confirmed', 'collection_method', 'collected_usd', 'collected_cup', '_cvd_collection_received_by', "'atomic_mutation'", "'delivered' === $next && $is_messenger"]) {
  if (!delivery.includes(marker)) throw new Error(`Falta cobro canónico al entregar: ${marker}`);
}
for (const marker of ['cvd-delivery-collection', 'Confirmar entrega y cobro real', 'Entregado · registrar cobro', 'No se completa ni se infiere desde el total']) {
  if (!portal.includes(marker)) throw new Error(`Falta revisión de cobro móvil: ${marker}`);
}
if (portal.includes("$actions = array( 'delivered' => 'Entregado'")) throw new Error('No debe quedar un atajo de entrega sin cobro estructurado.');

console.log('OK: entrega y cobro real usan la transición canónica.');
