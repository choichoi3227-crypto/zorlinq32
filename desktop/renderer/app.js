let settings = { wordpressSites: [], remote: {}, flow: { linked: false } };

const $ = (id) => document.getElementById(id);
const toast = (message) => {
  $('toast').textContent = message;
  $('toast').classList.add('show');
  setTimeout(() => $('toast').classList.remove('show'), 3000);
};
const currentSite = () => settings.wordpressSites[$('siteSelect').selectedIndex];
const renderSites = () => {
  const options = settings.wordpressSites.map((site) => `<option>${site.name || site.url} (${site.username})</option>`).join('');
  $('siteSelect').innerHTML = options || '<option>등록된 사이트 없음</option>';
  $('flowState').textContent = settings.flow?.linked ? `연동됨 (${settings.flow.linkedAt || ''})` : '연동 안 됨';
};

function fillSettingsForm() {
  const site = settings.wordpressSites[0] || {};
  $('wpName').value = site.name || '';
  $('wpUrl').value = site.url || '';
  $('wpUser').value = site.username || '';
  $('wpPassword').value = site.applicationPassword || '';
  $('workerUrl').value = settings.remote?.workerUrl || '';
  $('workerToken').value = settings.remote?.workerToken || '';
  $('geminiKey').value = settings.remote?.geminiKey || '';
}

async function init() {
  settings = await window.zorlinq32.loadSettings();
  renderSites();
  fillSettingsForm();
}

document.querySelectorAll('aside button').forEach((button) => button.addEventListener('click', () => {
  document.querySelectorAll('aside button, .view').forEach((node) => node.classList.remove('active'));
  button.classList.add('active');
  $(button.dataset.view).classList.add('active');
}));

$('saveSettings').addEventListener('click', async () => {
  const site = {
    name: $('wpName').value.trim(),
    url: $('wpUrl').value.trim().replace(/\/$/, ''),
    username: $('wpUser').value.trim(),
    applicationPassword: $('wpPassword').value.trim()
  };
  settings.wordpressSites = site.url ? [site] : [];
  settings.remote = {
    workerUrl: $('workerUrl').value.trim(),
    workerToken: $('workerToken').value.trim(),
    geminiKey: $('geminiKey').value.trim()
  };
  settings = await window.zorlinq32.saveSettings(settings);
  renderSites();
  toast('설정을 암호화 저장했습니다.');
});

$('openAdmin').addEventListener('click', () => {
  const site = currentSite();
  if (!site) return toast('먼저 워드프레스 사이트를 등록하세요.');
  $('wpWebview').src = `${site.url.replace(/\/$/, '')}/wp-admin/`;
});

$('refreshPosts').addEventListener('click', async () => {
  try { $('siteOutput').textContent = JSON.stringify(await window.zorlinq32.listPosts(currentSite()), null, 2); }
  catch (error) { $('siteOutput').textContent = error.message; }
});

$('testConnection').addEventListener('click', async () => {
  try { $('siteOutput').textContent = JSON.stringify(await window.zorlinq32.testWordPress(currentSite()), null, 2); }
  catch (error) { $('siteOutput').textContent = error.message; }
});

$('generatePost').addEventListener('click', async () => {
  try {
    const result = await window.zorlinq32.generatePost(settings, $('postTopic').value || $('postTitle').value);
    $('postContent').value = result.content;
    toast('Gemini 초안을 만들었습니다.');
  } catch (error) { toast(error.message); }
});

$('workerPost').addEventListener('click', async () => {
  try {
    const result = await window.zorlinq32.writeViaWorker(settings, { site: currentSite(), topic: $('postTopic').value, title: $('postTitle').value });
    $('postContent').value = result.content || JSON.stringify(result, null, 2);
    toast('Worker 원격 글쓰기 요청 완료');
  } catch (error) { toast(error.message); }
});

$('publishPost').addEventListener('click', async () => {
  try {
    const result = await window.zorlinq32.createPost(currentSite(), { title: $('postTitle').value, content: $('postContent').value, status: $('postStatus').value });
    toast(`글 전송 완료: ${result.link || result.id}`);
  } catch (error) { toast(error.message); }
});

$('loadPlugins').addEventListener('click', async () => {
  try { $('pluginOutput').textContent = JSON.stringify(await window.zorlinq32.listPlugins(currentSite()), null, 2); }
  catch (error) { $('pluginOutput').textContent = error.message; }
});
$('loadThemes').addEventListener('click', async () => {
  try { $('pluginOutput').textContent = JSON.stringify(await window.zorlinq32.listThemes(currentSite()), null, 2); }
  catch (error) { $('pluginOutput').textContent = error.message; }
});
$('bundlePlugin').addEventListener('click', async () => {
  try { $('pluginOutput').textContent = `내장 플러그인 zip 생성 완료:\n${await window.zorlinq32.bundlePlugin()}`; }
  catch (error) { $('pluginOutput').textContent = error.message; }
});
$('linkFlow').addEventListener('click', async () => { settings = await window.zorlinq32.linkFlow(); renderSites(); });
$('unlinkFlow').addEventListener('click', async () => { settings.flow = { linked: false }; settings = await window.zorlinq32.saveSettings(settings); renderSites(); });
$('generateImage').addEventListener('click', async () => { await window.zorlinq32.generateFlowImage($('flowPrompt').value); toast('Flow를 브라우저에서 열었습니다.'); });

init();
