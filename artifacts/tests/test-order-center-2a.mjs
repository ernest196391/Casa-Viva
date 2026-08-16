import fs from "node:fs";
import assert from "node:assert/strict";

const root = new URL("../../", import.meta.url);
const center = fs.readFileSync(new URL("wordpress/casa-viva-dropship-core/includes/class-cvd-order-center.php", root), "utf8");
const service = fs.readFileSync(new URL("wordpress/casa-viva-dropship-core/includes/class-cvd-order-transition-service.php", root), "utf8");
const client = fs.readFileSync(new URL("wordpress/casa-viva-dropship-core/assets/order-center.js", root), "utf8");

for (const field of ["order", "customer", "items", "pricing", "operation", "delivery", "courier", "payment", "commission_summary", "gestora", "incident", "canonical_stage", "consistency", "timeline", "available_actions"]) {
  assert.match(center, new RegExp("['\"]" + field + "['\"]"), `falta ${field}`);
}
assert.match(center, /CVD_Canonical_Order_Reader::read/);
assert.match(center, /CVD_Order_Event_Timeline::for_wc_order/);
assert.match(center, /CVD_Order_Transition_Service::transition/);
assert.match(center, /available_targets/);
assert.match(service, /public static function available_targets/);
assert.doesNotMatch(client, /status\s*===|canonical_stage\s*===/);
assert.match(client, /document\.hidden/);
assert.match(client, /pagehide/);
assert.match(center, /'CONFLICT' === \$canonical\['consistency'\]/);
assert.match(center, /unset\( \$projection\['gestora'\]\['id'\] \)/);
console.log("FASE 2A: contrato estático del Centro Único verificado.");
