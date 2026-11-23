const { app, BrowserWindow, Menu } = require('electron');
const path = require('path');

const SERVER_URL = 'http://172.16.5.11/';
const isKioskMode = process.argv.includes('--kiosk');

function createWindow() {
  const mainWindow = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 1024,
    minHeight: 700,
    title: "Kios & Kasir POS Cendekia",
    icon: path.join(__dirname, '../assets/images/favicon.ico'),
    kiosk: isKioskMode, // Kiosk mode fullscreen jika dipanggil dengan --kiosk
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true
    }
  });

  // Hilangkan bar menu browser bawaan agar terlihat seperti aplikasi desktop native
  Menu.setApplicationMenu(null);

  // Muat URL server
  mainWindow.loadURL(SERVER_URL);

  // Jika koneksi ke server Raspberry Pi terputus / gagal dimuat
  mainWindow.webContents.on('did-fail-load', () => {
    mainWindow.loadFile(path.join(__dirname, 'offline.html'));
  });
}

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) {
    createWindow();
  }
});
