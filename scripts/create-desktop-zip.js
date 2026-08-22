const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const dist = path.join(root, 'dist');
fs.mkdirSync(dist, { recursive: true });
const zipPath = path.join(dist, 'zorlinq32-desktop-source.zip');
if (fs.existsSync(zipPath)) fs.rmSync(zipPath);
execFileSync('zip', ['-qr', zipPath, 'desktop', 'zorlinq32', 'package.json', 'README.md'], { cwd: root, stdio: 'inherit' });
console.log(`Created ${zipPath}`);
