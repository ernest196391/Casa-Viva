import fs from 'node:fs';

const builder = fs.readFileSync('scripts/build-release-candidate.sh', 'utf8');
const workflow = fs.readFileSync('.github/workflows/release-candidate.yml', 'utf8');
const predeploy = fs.readFileSync('scripts/verify-7d-predeploy.sh', 'utf8');

const mustInclude = (source, value, label) => {
  if (!source.includes(value)) throw new Error(`Missing ${label}: ${value}`);
};

mustInclude(builder, 'Release candidates must be built from main', 'main-only guard');
mustInclude(builder, 'git archive --format=zip', 'git archive packaging');
mustInclude(builder, 'source_sha', 'manifest source SHA');
mustInclude(builder, 'archive_sha256', 'archive checksum');
mustInclude(builder, "sha256sum release-manifest.json", 'manifest checksum');

mustInclude(workflow, 'workflow_dispatch:', 'manual release trigger');
mustInclude(workflow, 'ref: main', 'main checkout');
mustInclude(workflow, 'git rev-parse HEAD', 'checked-out SHA capture');
mustInclude(workflow, 'Verificar CI post-merge del SHA', 'validated-SHA guard');
mustInclude(workflow, 'scripts/build-release-candidate.sh', 'release builder invocation');
mustInclude(workflow, 'sha256sum -c SHA256SUMS', 'checksum verification');
mustInclude(workflow, 'actions/upload-artifact@v4', 'candidate artifact upload');
mustInclude(workflow, 'release-manifest.json', 'manifest upload');

mustInclude(predeploy, 'HEAD mismatch', '7D exact-SHA guard');
mustInclude(predeploy, 'git merge-base --is-ancestor', '7D main ancestry guard');
mustInclude(predeploy, 'scripts/build-release-candidate.sh', '7D release builder reuse');
mustInclude(predeploy, 'sha256sum -c SHA256SUMS', '7D checksum verification');
mustInclude(predeploy, 'Manifest source_sha mismatch', '7D manifest SHA guard');
mustInclude(predeploy, 'bash -n scripts/smoke-staging.sh', '7D smoke syntax guard');
mustInclude(predeploy, '7D PREDEPLOY GATE: OK', '7D success marker');

if (/PASSWORD|PRIVATE_KEY|secrets\./i.test(predeploy)) {
  throw new Error('7D predeploy gate must not contain credentials or secret material.');
}

if (/HOSTINGER|PASSWORD|secrets\./i.test(workflow)) {
  throw new Error('6A release candidate workflow must not contain deployment credentials or Hostinger writes.');
}

console.log('Release foundation 6A + 7D predeploy contract: OK');
