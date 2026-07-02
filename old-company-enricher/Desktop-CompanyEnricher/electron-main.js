const { app, BrowserWindow, ipcMain } = require("electron");
const path = require("path");
const { enrichByDomain } = require("./lib/enrichService");

let mainWindow = null;

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 920,
    height: 720,
    minWidth: 640,
    minHeight: 480,
    title: "Company Enricher",
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, "preload.js"),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  mainWindow.loadFile(path.join(__dirname, "renderer", "index.html"));
}

ipcMain.handle("enrich", async (_event, payload) => {
  const domain =
    typeof payload === "string" ? payload : String(payload?.domain || "");
  const contactUrl =
    typeof payload === "object" && payload
      ? String(payload.contactUrl || "")
      : "";

  return enrichByDomain(domain, contactUrl);
});

app.whenReady().then(() => {
  createWindow();

  app.on("activate", () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on("window-all-closed", () => {
  if (process.platform !== "darwin") {
    app.quit();
  }
});
