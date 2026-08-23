const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

const root = path.resolve(__dirname, '..');
const dist = path.join(root, 'dist');
const zipPath = path.join(dist, 'zorlinq32-desktop-source.zip');
const entries = ['desktop', 'zorlinq32', 'package.json', 'README.md'];

fs.mkdirSync(dist, { recursive: true });
if (fs.existsSync(zipPath)) fs.rmSync(zipPath);

const output = fs.createWriteStream(zipPath);
const archive = archiver('zip', { zlib: { level: 9 } });

output.on('close', () => {
  console.log(`Created ${zipPath} (${archive.pointer()} bytes)`);
});

archive.on('warning', (error) => {
  if (error.code === 'ENOENT') {
    console.warn(error.message);
    return;
  }
  throw error;
});

archive.on('error', (error) => {
  throw error;
});

archive.pipe(output);

for (const entry of entries) {
  const entryPath = path.join(root, entry);
  const stat = fs.statSync(entryPath);
  if (stat.isDirectory()) {
    archive.directory(entryPath, entry);
  } else {
    archive.file(entryPath, { name: entry });
  }
}

archive.finalize();
