import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { probeGroupClassification } from './receiver-group-cases.mjs';

const { parse } = createRequire(import.meta.url)('internal/deps/acorn/acorn/dist/acorn');

function declaration(source, name, variable = false) {
  const ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
  const matches = [];
  for (const statement of ast.body) {
    const node = statement.declaration ?? statement;
    if (!variable && node.type === 'FunctionDeclaration' && node.id?.name === name) {
      matches.push(source.slice(node.start, node.end));
    }
    if (variable && node.type === 'VariableDeclaration' && node.kind === 'const') {
      for (const item of node.declarations) {
        if (item.id.type === 'Identifier' && item.id.name === name &&
            item.init?.type === 'ArrowFunctionExpression') {
          matches.push('const ' + source.slice(item.start, item.end) + ';');
        }
      }
    }
  }
  if (matches.length !== 1) throw new Error('Unexpected declaration: ' + name);
  return matches[0];
}

const input = JSON.parse(readFileSync(0, 'utf8'));
const sources = ['group', 'actions'];
for (const key of sources) {
  const source = input[key];
  if (typeof source !== 'string' || !source || Buffer.byteLength(source) > 262144 ||
      createHash('sha256').update(source).digest('hex') !== input.sha256?.[key]) {
    throw new Error('Invalid source or hash: ' + key);
  }
}

const subject = 'Grupo sintético';
const code = [
  'const name = ' + JSON.stringify(subject) + ';',
  declaration(input.group, 'pn', true),
  declaration(input.actions, 'actionCommand'),
  declaration(input.group, 'groupCommand'),
  'groupCommand;',
].join('\n');

// Execute only in an OS sandbox with a hard timeout and no network.
// VM extraction prevents production imports or top-level initializers.
const groupCommand = runInNewContext(code, Object.create(null), {
  timeout: 250,
  contextCodeGeneration: { strings: false, wasm: false },
});
const passed = await probeGroupClassification(groupCommand, subject);
console.log(JSON.stringify({ passed }));
