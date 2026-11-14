import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-cadena-mensajeros.php', 'utf8').replace(/\r/g, '');
const tick = source.split('function tick_order(')[1]?.split('// ------------------------------------------------------------------ Avisos')[0] ?? '';
const business = source.split('function business_seconds(')[1]?.split('public static function tick_order(')[0] ?? '';
const hours = source.split('function in_confirmation_hours(')[1]?.split('*/')[0] ?? '';

test('courier timeout cannot release an assigned order to the group', () => {
  assert.match(tick, /outside_confirmation_hours/);
  assert.doesNotMatch(tick, /system_release\s*\(/);
  assert.doesNotMatch(tick, /publish_offer\s*\(/);
  assert.doesNotMatch(tick, /group_min\(\)/);
  assert.doesNotMatch(tick, /INVITED_META, \$backup/);
  assert.doesNotMatch(tick, /queue_invite\(/);
});
test('courier reminder uses Cuba business hours, not wall clock elapsed time', () => {
  assert.match(tick, /self::business_seconds\( \$assigned_at, \$now \)/);
  assert.match(tick, /self::in_confirmation_hours\( \$now \)/);
  assert.match(business, /America\/Havana/);
  assert.match(business, /setTime\( 9, 0, 0 \)/);
  assert.match(business, /setTime\( 17, 0, 0 \)/);
  assert.match(hours, /America\/Havana/);
  assert.match(tick, /self::remind_min\(\) \* 60/);
});
test('reminder is sent once per assignment', () => {
  assert.match(tick, /REMINDED_META, true \) \) < \$assigned_at/);
});
test('reminder threshold is at least 20 minutes', () => {
  assert.match(source, /function remind_min\(\): int \{ return max\( 20,/);
});
test('courier confirmation route is registered', () => {
  const bridge = readFileSync('wordpress/casa-viva-dropship-core/includes/class-cvd-bot-bridge.php', 'utf8');
  assert.match(bridge, /\/bot\/dispatch\/\(\?P<id>\\d\+\)\/confirm/);
  assert.match(bridge, /function confirm_received/);
  const confirm = bridge.split('function confirm_received(')[1].split('public static function eta(')[0];
  assert.match(confirm, /assigned_messenger\(/);
  assert.match(confirm, /'already_accepted'/);
  assert.match(confirm, /accept_assigned\(/);
});
