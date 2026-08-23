const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('zorlinq32', {
  loadSettings: () => ipcRenderer.invoke('settings:load'),
  saveSettings: (settings) => ipcRenderer.invoke('settings:save', settings),
  testWordPress: (site) => ipcRenderer.invoke('wordpress:test', site),
  createPost: (site, post) => ipcRenderer.invoke('wordpress:createPost', site, post),
  listPosts: (site) => ipcRenderer.invoke('wordpress:listPosts', site),
  listPlugins: (site) => ipcRenderer.invoke('wordpress:listPlugins', site),
  listThemes: (site) => ipcRenderer.invoke('wordpress:listThemes', site),
  updatePlugin: (site, plugin, status) => ipcRenderer.invoke('wordpress:updatePlugin', site, plugin, status),
  bundlePlugin: () => ipcRenderer.invoke('plugin:bundle'),
  generatePost: (settings, topic) => ipcRenderer.invoke('ai:generatePost', settings, topic),
  writeViaWorker: (settings, payload) => ipcRenderer.invoke('worker:writePost', settings, payload),
  linkFlow: () => ipcRenderer.invoke('flow:link'),
  generateFlowImage: (prompt) => ipcRenderer.invoke('flow:generate', prompt)
});
