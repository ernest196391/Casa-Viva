import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-cadena-mensajeros.php', 'utf8');
const tick = source.split('function tick_order(')[1]?.split('// ------------------------------------------------------------------ Avisos')[0] ?? '';

test('courier timeout cannot release an assigned order to the group', () => {
  assert.match(tick, /outside_confirmation_hours/);
  assert.doesNotMatch(tick, /system_release\s*\(/);
  assert.doesNotMatch(tick, /publish_offer\s*\(/);
});
test('courier reminder uses Cuba business hours, not wall clock elapsed time', () => {
  assert.match(tick, /America\/Havana/);
  assert.match(tick, /setTime\( 9, 0, 0 \)/);
  assert.match(tick, /setTime\( 17, 0, 0 \)/);
  assert.match(tick, /\$age \+= \$to - \$from/);
  assert.match(tick, /self::remind_min\(\) \* 60/);
});
test('courier confirmation route is registered', () => {
  const bridge = readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-bot-bridge.php', 'utf8');
  assert.match(bridge, /\/bot\/dispatch\/\(\?P<id>\\d\+\)\/confirm/);
  assert.match(bridge, /function confirm_received/);
});
