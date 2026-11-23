// Offline classifier probe for the recovery receiver. Requires Node --expose-internals.
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { runInNewContext } from 'node:vm';

const { parse } = createRequire(import.meta.url)('internal/deps/acorn/acorn/dist/acorn');
const cases = {
  saludo: 'Hola',
  ventiladores: 'Hola, ¿qué ventiladores recargables tienen disponibles?',
};
const supported = new Set(['Literal', 'ArrowFunctionExpression', 'FunctionExpression', 'ArrayExpression', 'ObjectExpression']);

export function probe(source, now = Date.now()) {
  const ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
  const definitions = new Map();
  for (const statement of ast.body) {
    const node = statement.declaration ?? statement;
    if (node.type === 'FunctionDeclaration' && node.id) {
      definitions.set(node.id.name, source.slice(node.start, node.end));
    }
    if (node.type !== 'VariableDeclaration') continue;
    for (const item of node.declarations) {
      if (item.id.type === 'Identifier' && supported.has(item.init?.type)) {
        definitions.set(item.id.name, 'const ' + source.slice(item.start, item.end) + ';');
      }
    }
  }

  const classification = {};
  for (const [label, text] of Object.entries(cases)) {
    classification[label] = classify(definitions, text, now);
  }
  return {
    sha256: createHash('sha256').update(source).digest('hex'),
    classification,
    catalog: 'not_tested',
    reply: 'not_tested',
  };
}

function classify(definitions, text, now) {
  if (!definitions.has('classify')) return 'unsupported';
  const selected = new Set(['classify']);
  const message = {
    key: { remoteJid: '10000000001@s.whatsapp.net', fromMe: false, id: 'CV-SYNTHETIC' },
    message: { conversation: text },
    messageTimestamp: Math.floor(now / 1000),
  };
  while (true) {
    const code = [...definitions].filter(([name]) => selected.has(name)).map(([, declaration]) => declaration).join('\n');
    try {
      return runInNewContext(
        code + '\n{ const result = classify(' + JSON.stringify(message) +
          ', undefined, "10000000002@s.whatsapp.net"); ' +
          'result?.then ? (result.then(undefined, () => {}), "unsupported") : result == null || result === false ? "none" : "detected"; }',
        Object.create(null),
        { timeout: 250, contextCodeGeneration: { strings: false, wasm: false } },
      );
    } catch (error) {
      const name = /^([\w$]+) is not defined$/.exec(error.message)?.[1];
      if (!definitions.has(name) || selected.has(name) || name === 'respond') return 'unsupported';
      selected.add(name);
    }
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href && process.argv[2]) {
  const source = readFileSync(process.argv[2], 'utf8');
  console.log(JSON.stringify(probe(source)));
}
