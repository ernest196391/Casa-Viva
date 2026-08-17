import fs from 'node:fs';

const builder = fs.readFileSync('scripts/build-release-candidate.sh', 'utf8');
const workflow = fs.readFileSync('.github/workflows/release-candidate.yml', 'utf8');

const mustInclude = (source, value, label) => {
  if (!source.includes(value)) throw new Error(`Missing ${label}: ${value}`);
};

mustInclude(builder, 'Release candidates must be built from main', 'main-only guard');
mustInclude(builder, 'git archive --format=zip', 'git archive packaging');
mustInclude(builder, 'source_sha', 'manifest source SHA');
mustInclude(builder, 'archive_sha256', 'archive checksum');
mustInclude(builder, 'sha256sum "$manifest"', 'manifest checksum');

mustInclude(workflow, 'workflow_dispatch:', 'manual release trigger');
mustInclude(workflow, 'ref: main', 'main checkout');
mustInclude(workflow, 'git rev-parse HEAD', 'checked-out SHA capture');
mustInclude(workflow, 'scripts/build-release-candidate.sh', 'release builder invocation');
mustInclude(workflow, 'actions/upload-artifact@v4', 'candidate artifact upload');
mustInclude(workflow, 'release-manifest.json', 'manifest upload');

if (/HOSTINGER|PASSWORD|TOKEN|SECRET\s*:/i.test(workflow)) {
  throw new Error('6A release candidate workflow must not contain deployment credentials or Hostinger writes.');
}

console.log('Release foundation 6A contract: OK');
