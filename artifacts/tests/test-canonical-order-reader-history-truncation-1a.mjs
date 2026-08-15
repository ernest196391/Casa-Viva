import assert from 'node:assert/strict';
import fs from 'node:fs';

const path = 'wordpress/casa-viva-dropship-core/includes/class-cvd-canonical-order-reader.php';
const source = fs.readFileSync(path, 'utf8');
const method = source.match(/private static function previous_stage[\s\S]*?\n\t}\n\n\tprivate static function validate_catalog/)?.[0] || '';

assert.ok(method, 'No se encontró el recuperador de etapa de incidencia.');
assert.doesNotMatch(
  method,
  /if \( \$to && \$current !== \$to[\s\S]*?return \$to;/,
  'Un historial truncado no puede usar un evento ajeno como etapa anterior de la incidencia.',
);
assert.match(
  method,
  /\$current !== \$to[\s\S]*?return '';/,
  'Si el último evento no abre la incidencia actual, la etapa previa debe quedar indeterminada.',
);

console.log('OK: el historial truncado no inventa la etapa anterior de una incidencia.');
