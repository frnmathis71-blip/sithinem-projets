const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('sithinem', {
  state: () => ipcRenderer.invoke('app:state'),
  login: (credentials) => ipcRenderer.invoke('app:login', credentials),
  logout: () => ipcRenderer.invoke('app:logout'),
  api: (method, route, input) => ipcRenderer.invoke('app:api', method, route, input),
  print: (ticket) => ipcRenderer.invoke('app:print', ticket),
  notify: (note) => ipcRenderer.invoke('app:notify', note),
  settings: () => ipcRenderer.invoke('app:settings'),
  installUpdate: () => ipcRenderer.invoke('app:update'),
  checkUpdate: () => ipcRenderer.invoke('app:check-update'),
  titlebar: (theme) => ipcRenderer.invoke('app:titlebar', theme),
  onEvent: (listener) => ipcRenderer.on('app:event', (_event, payload) => listener(payload))
});
