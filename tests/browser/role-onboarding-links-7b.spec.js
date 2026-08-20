/* eslint-disable @typescript-eslint/no-require-imports */
const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const source = fs.readFileSync(
  'wordpress/casa-viva-dropship-core/includes/class-cvd-registration.php',
  'utf8',
);

test('7B keeps application follow-up links aligned with the requested role', async () => {
  expect(source).toContain('portal_url_for_type');
  expect(source).toContain("'mensajero' === $type ? '/area-mensajeros/' : '/area-gestoras/'");
  expect(source).toContain("self::portal_url_for_type( $type )");

  const successBlock = source.match(/if \( \$success \) \{[\s\S]*?\n\t\t\}/)?.[0] || '';
  expect(successBlock).toContain('portal_url_for_type');
  expect(successBlock).not.toContain("home_url( '/area-gestoras/' )");

  const emailBlock = source.match(/private static function send_application_emails[\s\S]*?\n\t\}/)?.[0] || '';
  expect(emailBlock).toContain('portal_url_for_type');
  expect(emailBlock).not.toContain("home_url( '/area-gestoras/' )");
});
