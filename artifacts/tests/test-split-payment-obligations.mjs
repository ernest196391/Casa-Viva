import fs from 'node:fs';

const obligations = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-payment-obligations.php', 'utf8');
const delivery = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-delivery.php', 'utf8');
const portal = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-portal.php', 'utf8');
const payouts = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-payouts.php', 'utf8');
const plugin = fs.readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-plugin.php', 'utf8');

for (const marker of ["'_cvd_payment_obligations'", "'concept'", "'amount'", "'currency'", "'payer'", "'method'", "'commission_deduction'", 'settle_customer_allocations', 'post_commission_deductions']) {
  if (!obligations.includes(marker)) throw new Error(`Falta contrato de obligación: ${marker}`);
}
for (const marker of ['cvd_owner_financial_ledger', 'order_obligation_entry', 'owner_status_currency']) {
  if (!plugin.includes(marker)) throw new Error(`Falta libro canónico: ${marker}`);
}
for (const marker of ['collection_allocations', 'CVD_Payment_Obligations::settle_customer_allocations', 'CVD_Payment_Obligations::post_commission_deductions']) {
  if (!delivery.includes(marker)) throw new Error(`Falta integración de entrega: ${marker}`);
}
if (!portal.includes('collection_allocations[')) throw new Error('El mensajero no recibe las obligaciones asignadas.');
for (const marker of ["status='reserved'", "status='settled'", "status='open'", '$gross - $debits']) {
  if (!payouts.includes(marker)) throw new Error(`Falta conciliación de liquidación: ${marker}`);
}
if (!obligations.includes('abs( $delivery_cup - $shipping )')) throw new Error('No se valida el total de mensajería.');

console.log('OK: pagador dividido usa obligaciones canónicas y débito por moneda sin conversión.');
