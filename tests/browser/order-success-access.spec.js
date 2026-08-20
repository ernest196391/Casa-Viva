/* eslint-disable @typescript-eslint/no-require-imports */
const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const source = fs.readFileSync(
  'wordpress/casa-viva-dropship-core/includes/class-cvd-whatsapp-gateway.php',
  'utf8',
);

test('7A keeps post-checkout access private and discoverable', async () => {
  expect(source).toContain('customer_order_url');
  expect(source).toContain("(int) $order->get_customer_id() !== get_current_user_id()");
  expect(source).toContain("wc_get_endpoint_url( 'view-order'");
  expect(source).toContain('Ver seguimiento del pedido');
  expect(source).toContain('Abrir WhatsApp y confirmar');
  expect(source).toContain('Seguir comprando');
  expect(source).not.toMatch(/Ver seguimiento del pedido[\s\S]{0,500}get_order_key\s*\(/);
});
