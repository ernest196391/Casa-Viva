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
  "tr -d '\\r'",
  'ssh-keygen -y -f ~/.ssh/id_ed25519',
  'command -v php >/dev/null',
  'json_decode(file_get_contents("/tmp/release-manifest.json")',
  '/home/u824654880/domains/casavivadecuba.com/public_html',
  'casa-viva-dropship-core',
  '.cvd-deployed-sha',
  '.cvd-deployed-archive-sha256',
  'smoke-staging.sh',
  'Rollback automático si falla el deploy o smoke',
  'predeploy-',
  'if: failure()',
]) {
  if (!workflow.includes(marker)) {
    throw new Error(`6B prototype deploy missing contract marker: ${marker}`);
  }
}

if (workflow.includes('python3 -c')) {
  throw new Error('6B Hostinger deploy must not require python3 on the remote host');
}

const forbidden = /(password|passwd|api[_-]?token)\s*[:=]\s*['\"][^$]/i;
if (forbidden.test(workflow)) {
  throw new Error('6B prototype deploy must not embed credentials');
}

console.log('6B prototype deploy contract OK');
