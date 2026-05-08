// Minimal secure preload. Add APIs later if needed.
const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('easywash', {
  version: '0.1.0'
});
