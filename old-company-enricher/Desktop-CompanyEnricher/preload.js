const { contextBridge, ipcRenderer } = require("electron");

contextBridge.exposeInMainWorld("enricherApi", {
  enrich: (domain) => ipcRenderer.invoke("enrich", domain),
});
