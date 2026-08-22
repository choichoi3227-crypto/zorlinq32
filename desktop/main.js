const { app, BrowserWindow, ipcMain, shell, safeStorage } = require('electron');
const path = require('path');
const fs = require('fs/promises');
const { createWriteStream } = require('fs');
const archiver = require('archiver');

const STORE_FILE = () => path.join(app.getPath('userData'), 'settings.json');
const PLUGIN_DIR = path.join(__dirname, '..', 'zorlinq32');
const PLUGIN_ZIP = () => path.join(app.getPath('userData'), 'zorlinq32-wordpress-plugin.zip');
const SENSITIVE_KEYS = new Set(['applicationPassword', 'geminiKey', 'workerToken']);
const DEFAULT_SETTINGS = { wordpressSites: [], remote: {}, flow: { linked: false } };

function encryptValue(value) {
  if (!value) return '';
  if (!safeStorage.isEncryptionAvailable()) return value;
  return `safe:${safeStorage.encryptString(String(value)).toString('base64')}`;
}

function decryptValue(value) {
  if (!value || typeof value !== 'string') return value || '';
  if (!value.startsWith('safe:')) return value;
  if (!safeStorage.isEncryptionAvailable()) return '';
  return safeStorage.decryptString(Buffer.from(value.slice(5), 'base64'));
}

function secureForDisk(settings) {
  const copy = structuredClone({ ...DEFAULT_SETTINGS, ...settings });
  copy.wordpressSites = (copy.wordpressSites || []).map((site) => Object.fromEntries(
    Object.entries(site).map(([key, value]) => [key, SENSITIVE_KEYS.has(key) ? encryptValue(value) : value])
  ));
  copy.remote = Object.fromEntries(
    Object.entries(copy.remote || {}).map(([key, value]) => [key, SENSITIVE_KEYS.has(key) ? encryptValue(value) : value])
  );
  return copy;
}

function revealFromDisk(settings) {
  const copy = structuredClone({ ...DEFAULT_SETTINGS, ...settings });
  copy.wordpressSites = (copy.wordpressSites || []).map((site) => Object.fromEntries(
    Object.entries(site).map(([key, value]) => [key, SENSITIVE_KEYS.has(key) ? decryptValue(value) : value])
  ));
  copy.remote = Object.fromEntries(
    Object.entries(copy.remote || {}).map(([key, value]) => [key, SENSITIVE_KEYS.has(key) ? decryptValue(value) : value])
  );
  return copy;
}

async function readSettings() {
  try {
    return revealFromDisk(JSON.parse(await fs.readFile(STORE_FILE(), 'utf8')));
  } catch (error) {
    return structuredClone(DEFAULT_SETTINGS);
  }
}

async function writeSettings(settings) {
  await fs.mkdir(path.dirname(STORE_FILE()), { recursive: true });
  await fs.writeFile(STORE_FILE(), JSON.stringify(secureForDisk(settings), null, 2));
  return revealFromDisk(secureForDisk(settings));
}

async function createMainWindow() {
  const win = new BrowserWindow({
    width: 1360,
    height: 900,
    minWidth: 1120,
    minHeight: 760,
    title: 'Zorlinq32 Desktop',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      webviewTag: true
    }
  });

  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  await win.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

function requireSite(site) {
  const url = String(site?.url || '').replace(/\/$/, '');
  const username = String(site?.username || '').trim();
  const applicationPassword = String(site?.applicationPassword || site?.appPassword || '').trim();
  if (!url || !username || !applicationPassword) {
    throw new Error('워드프레스 링크, 사용자명, 애플리케이션 비밀번호를 모두 입력하세요.');
  }
  return { ...site, url, username, applicationPassword };
}

async function wordpressFetch(siteInput, route, options = {}) {
  const site = requireSite(siteInput);
  const credentials = Buffer.from(`${site.username}:${site.applicationPassword}`).toString('base64');
  const response = await fetch(`${site.url}/wp-json${route}`, {
    ...options,
    headers: {
      Authorization: `Basic ${credentials}`,
      'Content-Type': 'application/json',
      ...(options.headers || {})
    }
  });

  const text = await response.text();
  let body = text;
  try {
    body = text ? JSON.parse(text) : null;
  } catch (error) {
    body = text;
  }

  if (!response.ok) {
    throw new Error(`WordPress API ${response.status}: ${typeof body === 'string' ? body : JSON.stringify(body)}`);
  }
  return body;
}

async function zipPlugin() {
  await fs.mkdir(path.dirname(PLUGIN_ZIP()), { recursive: true });
  await fs.rm(PLUGIN_ZIP(), { force: true });
  await new Promise((resolve, reject) => {
    const output = createWriteStream(PLUGIN_ZIP());
    const archive = archiver('zip', { zlib: { level: 9 } });
    output.on('close', resolve);
    archive.on('error', reject);
    archive.pipe(output);
    archive.directory(PLUGIN_DIR, 'zorlinq32');
    archive.finalize();
  });
  return PLUGIN_ZIP();
}

async function callWorker(settings, payload) {
  const workerUrl = String(settings.remote?.workerUrl || '').trim();
  if (!workerUrl) throw new Error('Cloudflare Worker URL을 원격 설정에 입력하세요.');
  const response = await fetch(workerUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(settings.remote?.workerToken ? { Authorization: `Bearer ${settings.remote.workerToken}` } : {})
    },
    body: JSON.stringify(payload)
  });
  const text = await response.text();
  const body = text ? JSON.parse(text) : null;
  if (!response.ok) throw new Error(`Worker ${response.status}: ${text}`);
  return body;
}

async function callGemini(settings, prompt) {
  const key = String(settings.remote?.geminiKey || '').trim();
  if (!key) throw new Error('Gemini API Key를 원격 설정에 입력하세요.');
  const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${encodeURIComponent(key)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }] })
  });
  const body = await response.json();
  if (!response.ok) throw new Error(`Gemini ${response.status}: ${JSON.stringify(body)}`);
  return body.candidates?.[0]?.content?.parts?.map((part) => part.text).join('\n') || '';
}

app.whenReady().then(createMainWindow);

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

ipcMain.handle('settings:load', readSettings);
ipcMain.handle('settings:save', async (_event, settings) => writeSettings(settings));
ipcMain.handle('wordpress:test', async (_event, site) => wordpressFetch(site, '/wp/v2/users/me'));
ipcMain.handle('wordpress:createPost', async (_event, site, post) => wordpressFetch(site, '/wp/v2/posts', { method: 'POST', body: JSON.stringify(post) }));
ipcMain.handle('wordpress:listPosts', async (_event, site) => wordpressFetch(site, '/wp/v2/posts?per_page=10&context=edit'));
ipcMain.handle('wordpress:listPlugins', async (_event, site) => wordpressFetch(site, '/wp/v2/plugins'));
ipcMain.handle('wordpress:listThemes', async (_event, site) => wordpressFetch(site, '/wp/v2/themes'));
ipcMain.handle('wordpress:updatePlugin', async (_event, site, plugin, status) => wordpressFetch(site, `/wp/v2/plugins/${encodeURIComponent(plugin)}`, { method: 'POST', body: JSON.stringify({ status }) }));
ipcMain.handle('plugin:bundle', zipPlugin);
ipcMain.handle('ai:generatePost', async (_event, settings, topic) => ({ content: await callGemini(settings, `한국어 워드프레스 블로그 글을 HTML 형식으로 작성해줘. 주제: ${topic}`) }));
ipcMain.handle('worker:writePost', async (_event, settings, payload) => callWorker(settings, { action: 'writePost', ...payload }));

ipcMain.handle('flow:link', async () => {
  await shell.openExternal('https://labs.google/fx/tools/flow');
  const settings = await readSettings();
  settings.flow = { ...(settings.flow || {}), linked: true, linkedAt: new Date().toISOString() };
  return writeSettings(settings);
});

ipcMain.handle('flow:generate', async (_event, prompt) => {
  await shell.openExternal(`https://labs.google/fx/tools/flow?prompt=${encodeURIComponent(prompt)}`);
  return { opened: true, note: 'Google Flow opened in the user browser.' };
});
