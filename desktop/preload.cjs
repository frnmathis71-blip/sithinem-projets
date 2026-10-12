const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('sithinem', {
  settings: () => ipcRenderer.invoke('settings:get'),
  printers: () => ipcRenderer.invoke('printers:list'),
  save: (settings) => ipcRenderer.invoke('settings:save', settings),
  open: () => ipcRenderer.invoke('management:open'),
  restart: () => ipcRenderer.invoke('printer:restart'),
  test: () => ipcRenderer.invoke('printer:test')
});
