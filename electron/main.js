const { app, BrowserWindow, shell, Menu, Tray, nativeImage, dialog } = require('electron');
const path = require('path');
const fs = require('fs');

// Config
const APP_NAME = 'WashHub';
// Point to localhost for custom software development
const START_URL = 'http://localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php';
// Use WashHub logo from local electron folder
const ICON_PATH = path.resolve(__dirname, 'new logo.png');

let mainWindow;
let loadingWindow;
let tray;

// Basic file logger to help diagnose startup issues
let logFile;
function ensureLog() {
  try {
    const logsDir = path.join(app.getPath('userData'), 'logs');
    if (!fs.existsSync(logsDir)) fs.mkdirSync(logsDir, { recursive: true });
    logFile = path.join(logsDir, 'main.log');
  } catch (e) {
    // ignore
  }
}

function log(...args) {
  const line = `[${new Date().toISOString()}] ${args.map(String).join(' ')}\n`;
  try {
    if (!logFile) ensureLog();
    if (logFile) fs.appendFileSync(logFile, line);
  } catch (_) { /* ignore */ }
  try { console.log(...args); } catch (_) { /* ignore */ }
}

function createLoadingWindow() {
  log('Creating loading window');
  loadingWindow = new BrowserWindow({
    width: 600,
    height: 500,
    transparent: true,
    frame: false,
    resizable: false,
    show: false,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
    },
  });

  const loadingPath = path.join(__dirname, 'loading.html');
  loadingWindow.loadFile(loadingPath);

  loadingWindow.once('ready-to-show', () => {
    log('Loading window ready-to-show');
    loadingWindow.show();
  });
}

function createTray() {
  try {
    log('Creating tray with icon path:', ICON_PATH);
    const icon = nativeImage.createFromPath(ICON_PATH);
    tray = new Tray(icon);
    tray.setToolTip(APP_NAME);
    const contextMenu = Menu.buildFromTemplate([
      { label: 'Show', click: () => { if (mainWindow) { mainWindow.show(); mainWindow.focus(); } } },
      { type: 'separator' },
      { label: 'Quit', click: () => app.quit() }
    ]);
    tray.setContextMenu(contextMenu);
    tray.on('click', () => {
      if (mainWindow) {
        mainWindow.isVisible() ? mainWindow.hide() : mainWindow.show();
      }
    });
  } catch (e) {
    log('Tray init failed:', e && e.stack ? e.stack : String(e));
  }
}

function createWindow() {
  log('Creating main BrowserWindow');
  mainWindow = new BrowserWindow({
    title: APP_NAME,
    width: 1200,
    height: 800,
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    },
    icon: ICON_PATH,
  });

  // Show the window as soon as it's ready or finished loading
  mainWindow.once('ready-to-show', () => {
    log('Window ready-to-show');
    if (mainWindow) mainWindow.show();
    // Close loading window after a short delay for smooth transition
    setTimeout(() => {
      if (loadingWindow) {
        loadingWindow.close();
        loadingWindow = null;
      }
    }, 500);
  });
  mainWindow.webContents.on('did-finish-load', () => {
    log('did-finish-load');
    if (mainWindow && !mainWindow.isVisible()) mainWindow.show();
    // Close loading window if still open
    if (loadingWindow) {
      loadingWindow.close();
      loadingWindow = null;
    }
  });
  mainWindow.webContents.on('did-fail-load', (_event, errorCode, errorDescription, validatedURL) => {
    log('did-fail-load', 'code=', errorCode, 'desc=', errorDescription, 'url=', validatedURL);
    // Fallback error page and ensure the window is visible
    try {
      mainWindow.loadURL('data:text/html,<h2 style="font-family:Arial;">Cannot Connect to Server</h2><p>Please ensure XAMPP is running and you can access <a href="http://localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php">localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php</a> in your browser.</p>');
    } catch (_) {}
    if (mainWindow && !mainWindow.isVisible()) mainWindow.show();
  });

  // Restrict navigation to our origin only
  const allowedOrigins = ['http://localhost', 'https://easywashpoint.wuaze.com'];
  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (!allowedOrigins.some(origin => url.startsWith(origin))) {
      event.preventDefault();
      shell.openExternal(url);
    }
  });
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (!allowedOrigins.some(origin => url.startsWith(origin))) {
      shell.openExternal(url);
      return { action: 'deny' };
    }
    return { action: 'allow' };
  });

  const loadTarget = async () => {
    try {
      log('Loading URL:', START_URL);
      await mainWindow.loadURL(START_URL, { userAgent: `${app.name}/${app.getVersion()}` });
      log('URL loaded');
    } catch (err) {
      log('Failed to load URL:', err && err.stack ? err.stack : String(err));
      mainWindow.loadURL('data:text/html,<h2 style="font-family:Arial;">Cannot connect to server</h2><p>Please ensure XAMPP is running and try opening <a href="http://localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php" target="_blank">localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php</a> in your browser.</p>');
    }
  };

  loadTarget();

  mainWindow.on('closed', () => {
    log('Main window closed');
    mainWindow = null;
  });
}

// Single instance
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  log('Second instance detected, quitting');
  app.quit();
} else {
  app.on('second-instance', () => {
    log('second-instance event');
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore();
      mainWindow.show();
      mainWindow.focus();
    } else {
      // If no window exists (e.g., user closed it), create a new one
      createLoadingWindow();
      setTimeout(() => createWindow(), 6000);
    }
  });
}

app.setName(APP_NAME);

app.whenReady().then(() => {
  ensureLog();
  log('App whenReady');
  createLoadingWindow();
  // Delay main window creation to show loading screen for 6 seconds
  setTimeout(() => {
    createWindow();
    createTray();
  }, 6000);

  // Launch on startup (Windows supported)
  try {
    app.setLoginItemSettings({ openAtLogin: true, openAsHidden: true });
  } catch (e) {
    log('openAtLogin not supported:', e && e.stack ? e.stack : String(e));
  }

  // Basic application menu (minimal)
  const template = [
    {
      label: 'File',
      submenu: [
        { role: 'reload' },
        { role: 'forceReload' },
        { type: 'separator' },
        { role: 'quit' }
      ]
    },
    {
      label: 'View',
      submenu: [
        { role: 'togglefullscreen' },
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' }
      ]
    },
    {
      label: 'Help',
      submenu: [
        {
          label: 'Open in Browser',
          click: () => shell.openExternal('http://localhost/WASHING%20BAY%20MANAGEMENT%20SYSTEM(SAAS)/carwash/login.php')
        }
      ]
    }
  ];
  const menu = Menu.buildFromTemplate(template);
  Menu.setApplicationMenu(menu);
});

// Recreate a window in the app when the dock icon is clicked (macOS)
app.on('activate', () => {
  log('activate event');
  if (BrowserWindow.getAllWindows().length === 0) {
    createLoadingWindow();
    setTimeout(() => createWindow(), 6000);
  }
});

app.on('window-all-closed', () => {
  // On Windows/Linux, quit the app when all windows are closed
  if (process.platform !== 'darwin') {
    log('window-all-closed -> quitting');
    app.quit();
  }
});

app.on('before-quit', () => {
  log('before-quit');
  if (tray) tray.destroy();
  if (loadingWindow) loadingWindow.close();
});

process.on('uncaughtException', (err) => {
  log('uncaughtException:', err && err.stack ? err.stack : String(err));
});
process.on('unhandledRejection', (reason) => {
  log('unhandledRejection:', reason && reason.stack ? reason.stack : String(reason));
});
