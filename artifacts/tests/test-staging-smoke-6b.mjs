import fs from 'node:fs';

const requiredFiles = [
  'scripts/smoke-staging.sh',
  '.github/workflows/staging-smoke.yml',
  '.github/workflows/deploy-prototype.yml',
];
for (const file of requiredFiles) {
  if (!fs.existsSync(file)) throw new Error(`Missing 6B file: ${file}`);
}

const smoke = fs.readFileSync('scripts/smoke-staging.sh', 'utf8');
const workflow = fs.readFileSync('.github/workflows/staging-smoke.yml', 'utf8');
const prototype = fs.readFileSync('.github/workflows/deploy-prototype.yml', 'utf8');

for (const needle of [
  'https://',
  '/wp-json/',
  'casa-viva/v1',
  'order-center/0',
  '401',
  '403',
  'critical error',
  '/ruta-cv/',
  '/tarifas-mensajeria/',
  'Calculadora de mensajería',
]) {
  if (!smoke.toLowerCase().includes(needle.toLowerCase())) {
    throw new Error(`6B smoke runner missing contract marker: ${needle}`);
  }
}

for (const needle of [
  'workflow_dispatch',
  'staging_url',
  'expected_sha',
  'git merge-base --is-ancestor',
]) {
  if (!workflow.includes(needle)) {
    throw new Error(`6B smoke workflow missing contract marker: ${needle}`);
  }
}

for (const needle of [
  'HOSTINGER_SSH_PRIVATE_KEY',
  'build-release-candidate.sh',
  'sha256sum -c',
  '.cvd-deployed-sha',
  'if: failure()',
]) {
  if (!prototype.includes(needle)) {
    throw new Error(`6B prototype workflow missing contract marker: ${needle}`);
  }
}

const forbidden = /(password|passwd|hostinger_token|ftp_password|ssh_private_key)\s*[:=]\s*[^$\s{]/i;
if (forbidden.test(smoke) || forbidden.test(workflow) || forbidden.test(prototype)) {
  throw new Error('6B files must not contain embedded credentials');
}

console.log('6B smoke and prototype deployment contract OK');
