import fs from 'node:fs';

const workflowPath = '.github/workflows/deploy-prototype.yml';
if (!fs.existsSync(workflowPath)) throw new Error(`Missing ${workflowPath}`);

const workflow = fs.readFileSync(workflowPath, 'utf8');

for (const marker of [
  'workflow_dispatch',
  'expected_sha',
  'git merge-base --is-ancestor',
  'Verificar CI post-merge exitoso',
  'build-release-candidate.sh',
  'sha256sum -c',
  'HOSTINGER_SSH_PRIVATE_KEY',
  'HOSTINGER_SSH_HOST',
  'HOSTINGER_SSH_PORT',
  'HOSTINGER_SSH_USER',
  '/home/u824654880/domains/casavivadecuba.com/public_html',
  'casa-viva-dropship-core',
  '.cvd-deployed-sha',
  'smoke-staging.sh',
  'Rollback automático si falla el deploy o smoke',
  'predeploy-',
]) {
  if (!workflow.includes(marker)) {
    throw new Error(`6B prototype deploy missing contract marker: ${marker}`);
  }
}

const forbidden = /(password|passwd|api[_-]?token)\s*[:=]\s*['\"][^$]/i;
if (forbidden.test(workflow)) {
  throw new Error('6B prototype deploy must not embed credentials');
}

if (!workflow.includes('if: failure()')) {
  throw new Error('6B prototype deploy must retain automatic rollback on failure');
}

console.log('6B prototype deploy contract OK');
