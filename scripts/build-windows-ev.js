const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const root = path.resolve(__dirname, '..');
const packageJson = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const certificateSubjectName = process.env.EV_CERT_SUBJECT_NAME || '';
const certificateSha1 = process.env.EV_CERT_SHA1 || '';

if (!certificateSubjectName && !certificateSha1) {
  console.error('EV_CERT_SUBJECT_NAME or EV_CERT_SHA1 is required for EV signing.');
  process.exit(1);
}

const config = structuredClone(packageJson.build);
config.forceCodeSigning = true;
config.win = {
  ...(config.win || {}),
  sign: {
    type: 'signtool',
    ...(certificateSubjectName ? { certificateSubjectName } : {}),
    ...(certificateSha1 ? { certificateSha1 } : {}),
    signingHashAlgorithms: ['sha256']
  }
};

const configPath = path.join(os.tmpdir(), `zorlinq32-electron-builder-ev-${Date.now()}.json`);
fs.writeFileSync(configPath, JSON.stringify(config, null, 2));

const result = spawnSync(
  process.platform === 'win32' ? 'npx.cmd' : 'npx',
  ['electron-builder', '--win', 'portable', '--x64', '--config', configPath],
  { cwd: root, stdio: 'inherit', shell: false }
);

fs.rmSync(configPath, { force: true });
process.exit(result.status ?? 1);
