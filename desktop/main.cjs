const { app, BrowserWindow, Menu, Tray, Notification, nativeImage, ipcMain, safeStorage, session, dialog, net } = require('electron');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawn } = require('node:child_process');
const { validateSettings } = require('./config.cjs');
const { apiPath, apiBody, apiMethod } = require('./api.cjs');
let mainWindow, settingsWindow, tray, worker, quitting = false;
let printerTransition = Promise.resolve();
const smokeTest = process.argv.includes('--smoke-test');
// Diagnostic: asks the site whether a newer version exists, downloads nothing and installs nothing.
const updateCheck = process.argv.includes('--check-update');
if (smokeTest || updateCheck) {
  app.disableHardwareAcceleration();
  const temporaryProfile = path.join(app.getPath('temp'), 'sithinem-smoke-' + process.pid);
  fs.mkdirSync(temporaryProfile, { recursive: true });
  app.setPath('userData', temporaryProfile);
} else {
  // The settings folder keeps its original name, so renaming the product never loses a workstation's settings.
  app.setPath('userData', path.join(app.getPath('appData'), 'Sithi Nem'));
}
const iconPath = path.join(__dirname, 'ui/img/mark.png');
const TITLEBAR = { light: { color: '#f4f2ec', symbolColor: '#1d2b24', height: 40 }, dark: { color: '#1c3328', symbolColor: '#ffffff', height: 40 } };
let status = 'Impression arrêtée.', lastEvent = '', config = {}, update = { status: 'idle' };
const localPage = pathToFileURL(path.join(__dirname, 'ui/settings.html')).href;
const appPage = pathToFileURL(path.join(__dirname, 'ui/app.html')).href;
const powerShell = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32/WindowsPowerShell/v1.0/powershell.exe');
const securePreferences = { nodeIntegration: false, contextIsolation: true, sandbox: true };
const configPath = () => path.join(app.getPath('userData'), 'settings.json');
const printerPath = (name) => app.isPackaged ? path.join(process.resourcesPath, 'printer', name) : path.join(__dirname, name === 'Desktop-Printer.ps1' ? 'printer' : '../scripts', name);
const decrypt = (value) => value ? safeStorage.decryptString(Buffer.from(value, 'base64')) : '';
const encrypt = (value) => value ? safeStorage.encryptString(value).toString('base64') : '';
function token() { return decrypt(config.secret); }
function sessionToken() { try { return decrypt(config.session); } catch { return ''; } }
function writeConfig(next) {
  const temporary = configPath() + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(next, null, 2));
  fs.renameSync(temporary, configPath());
  config = next;
}
function stopPrinter() {
  const old = worker;
  worker = null;
  status = 'Impression arrêtée.';
  if (!old) return Promise.resolve();
  if (old.exitCode !== null) return Promise.resolve();
  return new Promise((resolve) => { old.once('exit', resolve); old.once('error', resolve); old.kill(); });
}
function printerEnvironment(mode, extra = {}) {
  return { ...process.env, SITHINEM_SITE: config.siteUrl, SITHINEM_PRINTER: config.printer,
    SITHINEM_WIDTH: String(config.width), SITHINEM_MODE: mode, SITHINEM_AGENT: printerPath('Start-OrderPrinter.ps1'),
    RESTAURANT_PRINT_TOKEN: mode === 'agent' ? token() : '', ...extra };
}
function startPrinter() {
  printerTransition = printerTransition.catch(() => {}).then(restartPrinter);
  return printerTransition;
}
async function restartPrinter() {
  await stopPrinter();
  if (quitting) return;
  if (!config.printing || !config.printer || !config.secret) return;
  status = 'Démarrage de l’impression…';
  const child = spawn(powerShell, ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', printerPath('Desktop-Printer.ps1')],
    { windowsHide: true, env: printerEnvironment('agent'), stdio: ['ignore', 'pipe', 'pipe'] });
  worker = child;
  child.stdout.on('data', (buffer) => {
    if (worker !== child) return;
    const output = buffer.toString('utf8');
    if (output.includes('Impression Sithinem active')) status = 'Impression active — en attente de commandes.';
    if (output.includes('SITHINEM_CONNECTED')) status = 'Connecté — impression active.';
    if (output.includes('Connexion ou confirmation indisponible')) status = 'Connexion indisponible — nouvelle tentative automatique.';
    if (output.includes('transmis au pilote')) { status = 'Impression active.'; lastEvent = 'Dernier ticket transmis au pilote à ' + new Date().toLocaleTimeString('fr-FR'); }
    if (output.includes("Le pilote n'a pas")) status = 'Erreur d’impression : vérifiez la file Windows et les tickets dans Commandes.';
  });
  child.stderr.on('data', () => { if (worker === child) status = 'Erreur du poste d’impression. Vérifiez les paramètres et la file Windows.'; });
  child.on('error', () => { if (worker === child) { worker = null; status = 'Impossible de démarrer le poste d’impression.'; } });
  child.on('exit', () => { if (worker === child) { worker = null; status = 'Impression interrompue. Vérifiez les paramètres puis relancez.'; } });
}
// One-off print (test page or a single ticket) through the same Windows driver as the agent.
function printOnce(mode, extra = {}) {
  if (!config.printer) return Promise.reject(new Error('Choisissez d’abord une imprimante dans « Poste ».'));
  return new Promise((resolve, reject) => {
    const child = spawn(powerShell, ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', printerPath('Desktop-Printer.ps1')], { windowsHide: true, env: printerEnvironment(mode, extra), stdio: 'ignore' });
    const timer = setTimeout(() => { child.kill(); reject(new Error('Le pilote ne répond pas. Vérifiez la file Windows avant un nouvel essai.')); }, 30000);
    child.on('error', () => { clearTimeout(timer); reject(new Error('Impossible de lancer l’impression.')); });
    child.on('exit', (code) => { clearTimeout(timer); code === 0 ? resolve(true) : reject(new Error('Échec de l’impression. Vérifiez l’imprimante dans Windows.')); });
  });
}
function send(channel, payload) { if (mainWindow && !mainWindow.isDestroyed()) mainWindow.webContents.send(channel, payload); }
function appState() {
  return { siteUrl: config.siteUrl || '', signedIn: Boolean(config.session), user: config.user || null, version: app.getVersion(),
    printer: { name: config.printer || '', printing: Boolean(config.printing), status, lastEvent }, update };
}
function clearSession() {
  if (!config.session && !config.user) return;
  const next = { ...config }; delete next.session; delete next.user;
  writeConfig(next);
  send('app:event', { type: 'signed-out' });
}
async function fetchApi(method, route, input = {}, bearer = sessionToken()) {
  if (!config.siteUrl) throw new Error('Configurez d’abord l’adresse du site dans « Poste ».');
  const target = apiPath(route, input.query);
  const { headers, body } = await apiBody(input);
  let response;
  try {
    // Redirects are refused so the token is never sent anywhere but the configured site.
    response = await net.fetch(config.siteUrl + target, { method: apiMethod(method), body, redirect: 'error', cache: 'no-store', signal: AbortSignal.timeout(30000),
      headers: { Accept: 'application/json', ...(bearer ? { Authorization: 'Bearer ' + bearer } : {}), ...headers } });
  } catch {
    throw new Error('Le site est injoignable. Vérifiez la connexion Internet puis réessayez.');
  }
  const text = await response.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = null; }
  return { status: response.status, data };
}
async function callApi(method, route, input) {
  apiMethod(method); apiPath(route, input?.query);
  if (!sessionToken()) { clearSession(); return { status: 401, data: { message: 'Connectez-vous pour continuer.' } }; }
  const result = await fetchApi(method, route, input);
  if (result.status === 401) clearSession();
  return result;
}
async function login(input) {
  if (!input || typeof input.email !== 'string' || typeof input.password !== 'string' || (input.code !== undefined && typeof input.code !== 'string')) throw new Error('Identifiants invalides.');
  if (!safeStorage.isEncryptionAvailable()) throw new Error('Le stockage sécurisé Windows est indisponible.');
  const result = await fetchApi('POST', '/session', { json: { email: input.email.slice(0, 255), password: input.password.slice(0, 1000), code: input.code || undefined, device: os.hostname().slice(0, 100) } }, '');
  if (result.status !== 200 || !result.data?.token) return { ok: false, status: result.status, data: result.data };
  writeConfig({ ...config, session: encrypt(result.data.token), user: result.data.user });
  send('app:event', { type: 'signed-in' });
  return { ok: true, user: result.data.user };
}
async function logout() {
  if (sessionToken()) await fetchApi('DELETE', '/session').catch(() => {});
  clearSession();
}
let updater = null, updateTimer = null;
function setupUpdates() {
  if (!app.isPackaged || smokeTest || !config.siteUrl) return null;
  if (!updater) {
    try { ({ autoUpdater: updater } = require('electron-updater')); } catch { return null; }
    updater.on('update-available', (info) => { update = { status: updateCheck ? 'available' : 'downloading', version: info.version }; send('app:event', { type: 'update', update }); });
    updater.on('update-not-available', () => { if (update.status !== 'ready') update = { status: 'current' }; });
    updater.on('update-downloaded', (info) => { update = { status: 'ready', version: info.version }; send('app:event', { type: 'update', update }); });
    updater.on('error', (error) => { if (update.status !== 'ready') update = { status: 'error', message: String(error?.message || error).slice(0, 300) }; });
  }
  // Updates are published on the restaurant's own site, next to the API.
  updater.setFeedURL({ provider: 'generic', url: config.siteUrl + '/logiciel/mises-a-jour' });
  updater.autoDownload = !updateCheck;
  updater.autoInstallOnAppQuit = !updateCheck;
  if (!updateCheck && !updateTimer) updateTimer = setInterval(() => updater.checkForUpdates().catch(() => {}), 4 * 60 * 60 * 1000).unref();
  if (!updateCheck) updater.checkForUpdates().catch(() => {});
  return updater;
}
setupUpdates.install = () => { if (!updater || update.status !== 'ready') return; quitting = true; stopPrinter().finally(() => updater.quitAndInstall(true, true)); };
async function checkUpdatesNow() {
  const source = setupUpdates();
  if (!source) return dialog.showMessageBox({ type: 'info', title: 'Mises à jour', message: app.isPackaged ? 'Configurez d’abord l’adresse du site dans « Poste et imprimante ».' : 'Les mises à jour automatiques concernent la version installée.' });
  if (update.status === 'ready') return dialog.showMessageBox({ type: 'info', title: 'Mises à jour', message: 'La version ' + update.version + ' est prête. Utilisez « Redémarrer pour l’installer » dans le bandeau.' });
  try {
    await source.checkForUpdates();
    dialog.showMessageBox({ type: 'info', title: 'Mises à jour', message: update.status === 'downloading' ? 'La version ' + update.version + ' est en cours de téléchargement. Un bandeau proposera de l’installer.' : 'Vous utilisez la dernière version (' + app.getVersion() + ').' });
  } catch {
    dialog.showMessageBox({ type: 'warning', title: 'Mises à jour', message: 'Impossible de vérifier les mises à jour pour le moment. Le logiciel réessaiera automatiquement.' });
  }
}
async function runUpdateCheck() {
  const output = process.env.SITHINEM_SMOKE_OUTPUT || app.getPath('userData');
  fs.mkdirSync(output, { recursive: true });
  config = { siteUrl: process.env.SITHINEM_SMOKE_SITE || '' };
  const source = setupUpdates();
  try { if (source) await source.checkForUpdates(); } catch { /* reported below */ }
  fs.writeFileSync(path.join(output, 'update-check.json'), JSON.stringify({ current: app.getVersion(), packaged: app.isPackaged, ...update }));
  app.exit(0);
}
function openApp(view) {
  if (!mainWindow || mainWindow.isDestroyed()) {
    // No system title bar or menu: the window buttons are drawn over the application's own top strip.
    mainWindow = new BrowserWindow({ show: false, width: 1400, height: 900, minWidth: 1024, minHeight: 680, title: 'Sithinem', icon: iconPath, backgroundColor: '#f4f2ec',
      titleBarStyle: 'hidden', titleBarOverlay: TITLEBAR.light,
      // No background throttling: the board must keep updating while another window covers it.
      webPreferences: { ...securePreferences, offscreen: smokeTest, backgroundThrottling: false, autoplayPolicy: 'no-user-gesture-required', preload: path.join(__dirname, 'app-preload.cjs') } });
    mainWindow.webContents.on('will-navigate', (event) => event.preventDefault());
    mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    mainWindow.webContents.on('will-attach-webview', (event) => event.preventDefault());
    mainWindow.webContents.on('before-input-event', (event, input) => { if (shortcut(input)) event.preventDefault(); });
    mainWindow.on('close', (event) => { if (!quitting) { event.preventDefault(); mainWindow.hide(); } });
    mainWindow.once('ready-to-show', () => { if (!smokeTest) mainWindow.show(); });
    mainWindow.loadFile(path.join(__dirname, 'ui/app.html'));
  } else if (!smokeTest) { mainWindow.show(); mainWindow.focus(); }
  if (view) {
    if (mainWindow.webContents.isLoading()) mainWindow.webContents.once('did-finish-load', () => send('app:event', { type: 'navigate', view }));
    else send('app:event', { type: 'navigate', view });
  }
}
// Keyboard shortcuts, kept without a menu bar.
const VIEWS = ['orders', 'take-order', 'card', 'formulas', 'slots', 'calendar', 'statistics'];
function shortcut(input) {
  if (input.type !== 'keyDown') return false;
  const contents = mainWindow.webContents;
  const control = input.control && !input.alt && !input.meta;
  if (control && /^[1-7]$/.test(input.key)) { send('app:event', { type: 'navigate', view: VIEWS[Number(input.key) - 1] }); return true; }
  if (control && input.key.toLowerCase() === 'n') { send('app:event', { type: 'navigate', view: 'take-order' }); return true; }
  if (input.key === 'F5' && !input.control) { send('app:event', { type: 'refresh' }); return true; }
  if (input.key === 'F11') { mainWindow.setFullScreen(!mainWindow.isFullScreen()); return true; }
  if (control && input.key === '0') { contents.setZoomLevel(0); return true; }
  if (control && ['+', '='].includes(input.key)) { contents.setZoomLevel(Math.min(3, contents.getZoomLevel() + 0.5)); return true; }
  if (control && input.key === '-') { contents.setZoomLevel(Math.max(-3, contents.getZoomLevel() - 0.5)); return true; }
  if (input.key === 'F12' && !app.isPackaged) { contents.toggleDevTools(); return true; }
  return false;
}
function openSettings() {
  if (settingsWindow && !settingsWindow.isDestroyed()) { settingsWindow.show(); settingsWindow.focus(); return; }
  settingsWindow = new BrowserWindow({ show: !smokeTest, width: 740, height: 860, minWidth: 620, minHeight: 680, autoHideMenuBar: true, title: 'Sithinem — Paramètres du poste', icon: iconPath, webPreferences: { ...securePreferences, offscreen: smokeTest, preload: path.join(__dirname, 'preload.cjs') } });
  settingsWindow.webContents.on('will-navigate', (event) => event.preventDefault());
  settingsWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  settingsWindow.loadFile(path.join(__dirname, 'ui/settings.html'));
}
function trusted(event) {
  if (!settingsWindow || event.sender !== settingsWindow.webContents || event.senderFrame !== settingsWindow.webContents.mainFrame || event.senderFrame.url !== localPage) throw new Error('Action non autorisée.');
}
function trustedApp(event) {
  if (!mainWindow || event.sender !== mainWindow.webContents || event.senderFrame !== mainWindow.webContents.mainFrame || event.senderFrame.url !== appPage) throw new Error('Action non autorisée.');
}
async function saveSettings(input) {
  const next = validateSettings(input);
  const printers = await settingsWindow.webContents.getPrintersAsync();
  if (next.printer && !printers.some((printer) => printer.name === next.printer)) throw new Error('Cette imprimante n’est plus installée dans Windows.');
  const oldToken = next.siteUrl === config.siteUrl ? token() : '';
  const secret = next.token || oldToken;
  if (next.printing && (!next.printer || !secret)) throw new Error('Choisissez une imprimante et saisissez le jeton pour activer l’impression.');
  if (secret && !safeStorage.isEncryptionAvailable()) throw new Error('Le stockage sécurisé Windows est indisponible.');
  const changedSite = config.siteUrl !== next.siteUrl;
  if (changedSite && config.session) await logout();
  const saved = { ...config, ...next, secret: encrypt(secret) };
  delete saved.token;
  writeConfig(saved);
  if (app.isPackaged && !smokeTest) app.setLoginItemSettings({ openAtLogin: config.startup });
  await startPrinter();
  if (changedSite) setupUpdates();
  send('app:event', { type: 'state' });
}
const handle = (channel, check, work) => ipcMain.handle(channel, async (event, ...args) => { check(event); return work(...args); });
if (!app.requestSingleInstanceLock()) app.quit();
else {
  app.on('second-instance', () => openApp());
  app.whenReady().then(async () => {
    if (updateCheck) return runUpdateCheck();
    try { config = JSON.parse(fs.readFileSync(configPath(), 'utf8')); if (config.secret) token(); }
    catch (error) { config = {}; if (error.code !== 'ENOENT') dialog.showErrorBox('Paramètres à vérifier', 'Les paramètres enregistrés ne peuvent pas être lus. Configurez à nouveau le poste.'); }
    session.defaultSession.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
    session.defaultSession.setPermissionCheckHandler(() => false);
    session.defaultSession.on('will-download', (event) => event.preventDefault());
    handle('settings:get', trusted, () => ({ siteUrl: config.siteUrl || '', printer: config.printer || '', width: config.width || 80, hasToken: Boolean(config.secret), printing: Boolean(config.printing), startup: Boolean(config.startup), status, lastEvent }));
    handle('printers:list', trusted, async () => (await settingsWindow.webContents.getPrintersAsync()).map(({ name, displayName }) => ({ name, displayName })));
    handle('settings:save', trusted, async (input) => { await saveSettings(input); return true; });
    handle('management:open', trusted, () => openApp());
    handle('printer:restart', trusted, () => startPrinter());
    handle('printer:test', trusted, () => printOnce('test'));
    handle('app:state', trustedApp, () => appState());
    handle('app:login', trustedApp, (input) => login(input));
    handle('app:logout', trustedApp, () => logout());
    handle('app:api', trustedApp, (method, route, input) => callApi(method, route, input));
    handle('app:settings', trustedApp, () => openSettings());
    handle('app:update', trustedApp, () => setupUpdates.install());
    handle('app:check-update', trustedApp, () => checkUpdatesNow());
    handle('app:titlebar', trustedApp, (theme) => { if (TITLEBAR[theme] && !smokeTest) mainWindow.setTitleBarOverlay(TITLEBAR[theme]); });
    handle('app:print', trustedApp, (ticket) => {
      if (!ticket || typeof ticket.title !== 'string' || typeof ticket.text !== 'string' || ticket.text.length > 20000) throw new Error('Ticket invalide.');
      return printOnce('ticket', { SITHINEM_TICKET_TITLE: ticket.title.slice(0, 100), SITHINEM_TICKET_TEXT: ticket.text.replace(/[\x00-\x08\x0B-\x1F\x7F]/g, '') });
    });
    handle('app:notify', trustedApp, (note) => {
      if (!note || typeof note.title !== 'string' || typeof note.body !== 'string' || !Notification.isSupported() || mainWindow?.isFocused()) return;
      const notification = new Notification({ title: note.title.slice(0, 120), body: note.body.slice(0, 300), icon: iconPath });
      notification.on('click', () => openApp('orders'));
      notification.show();
    });
    // No menu bar: navigation lives in the application, shortcuts are handled per window.
    Menu.setApplicationMenu(null);
    tray = new Tray(nativeImage.createFromPath(path.join(__dirname, 'ui/img/tray.png')));
    tray.setToolTip('Sithinem — gestion et impression');
    tray.setContextMenu(Menu.buildFromTemplate([ { label: 'Ouvrir les commandes', click: () => openApp('orders') }, { label: 'Nouvelle commande', click: () => openApp('take-order') }, { label: 'Paramètres et imprimante', click: openSettings }, { label: 'Quitter et arrêter l’impression', click: () => app.quit() } ]));
    tray.on('double-click', () => openApp());
    // Refresh the Windows start-up entry so it follows the executable after an update or a rename.
    if (app.isPackaged && !smokeTest && config.startup) app.setLoginItemSettings({ openAtLogin: true });
    await startPrinter();
    setupUpdates();
    openApp();
    if (!config.siteUrl) openSettings();
    if (smokeTest) runSmokeTest();
  }).catch(() => { dialog.showErrorBox('Sithinem', 'Impossible de démarrer l’application.'); app.exit(1); });
  app.on('window-all-closed', () => {});
  app.on('before-quit', () => { quitting = true; stopPrinter(); });
}
// The smoke check validates windows, isolation, the API bridge and token storage without printing.
// With SITHINEM_SMOKE_SITE, SITHINEM_SMOKE_EMAIL and SITHINEM_SMOKE_PASSWORD it also signs in and captures every view.
async function runSmokeTest() {
  const output = process.env.SITHINEM_SMOKE_OUTPUT || app.getPath('userData');
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const capture = async (window, name) => fs.writeFileSync(path.join(output, name + '.png'), (await window.webContents.capturePage(undefined, { stayHidden: true, stayAwake: true })).toPNG());
  setTimeout(() => app.exit(3), 120000).unref();
  try {
    fs.mkdirSync(output, { recursive: true });
    fs.accessSync(printerPath('Desktop-Printer.ps1')); fs.accessSync(printerPath('Print-Ticket.cs')); fs.accessSync(printerPath('Start-OrderPrinter.ps1'));
    if (settingsWindow.webContents.isLoading()) await new Promise((resolve) => settingsWindow.webContents.once('did-finish-load', resolve));
    const result = await settingsWindow.webContents.executeJavaScript('window.sithinem.settings().then(s => ({status: s.status, node: typeof require}))');
    if (result.node !== 'undefined' || !result.status) throw new Error('Invalid renderer isolation');
    await settingsWindow.webContents.executeJavaScript('window.sithinem.printers()');
    const smokeSite = JSON.stringify(process.env.SITHINEM_SMOKE_SITE || 'https://smoke.invalid');
    await settingsWindow.webContents.executeJavaScript(`window.sithinem.save({siteUrl:${smokeSite},printer:'',width:80,token:'sithinem-test-token-not-a-real-secret',printing:false,startup:false})`);
    const persisted = fs.readFileSync(configPath(), 'utf8');
    if (persisted.includes('sithinem-test-token-not-a-real-secret') || token() !== 'sithinem-test-token-not-a-real-secret') throw new Error('Token storage check failed');
    const exposed = await settingsWindow.webContents.executeJavaScript('window.sithinem.settings()');
    if ('secret' in exposed || 'token' in exposed) throw new Error('Token exposed to renderer');
    await wait(800);
    await capture(settingsWindow, 'settings-preview');
    const contents = mainWindow.webContents;
    if (contents.isLoading()) await new Promise((resolve) => contents.once('did-finish-load', resolve));
    const bridge = await contents.executeJavaScript('({node: typeof require, bridge: typeof window.sithinem.api, process: typeof process})');
    if (bridge.node !== 'undefined' || bridge.process !== 'undefined' || bridge.bridge !== 'function') throw new Error('Invalid application isolation');
    const refused = await contents.executeJavaScript('window.sithinem.api("GET", "/../../admin").then(() => false, () => true)');
    if (!refused) throw new Error('API path filter failed');
    await wait(800);
    await capture(mainWindow, 'app-login');
    result.views = [];
    if (process.env.SITHINEM_SMOKE_EMAIL && process.env.SITHINEM_SMOKE_PASSWORD) {
      const signedIn = await contents.executeJavaScript(`window.sithinem.login(${JSON.stringify({ email: process.env.SITHINEM_SMOKE_EMAIL, password: process.env.SITHINEM_SMOKE_PASSWORD })})`);
      if (!signedIn.ok) throw new Error('Sign-in failed: ' + JSON.stringify(signedIn.data));
      if (fs.readFileSync(configPath(), 'utf8').includes(process.env.SITHINEM_SMOKE_PASSWORD)) throw new Error('Password persisted');
      await wait(1500);
      for (const view of (process.env.SITHINEM_SMOKE_VIEWS || 'orders,take-order,card,formulas,slots,calendar,statistics').split(',')) {
        send('app:event', { type: 'navigate', view });
        await wait(2500);
        await capture(mainWindow, 'view-' + view);
        result.views.push(view);
      }
      if (process.env.SITHINEM_SMOKE_LIVE) {
        // Live board: while the board stays open, another client orders for the displayed day, then for another day.
        // The first must appear highlighted without refreshing; the second must be announced by a banner.
        const [shown, other] = process.env.SITHINEM_SMOKE_LIVE.split(',');
        await contents.executeJavaScript(`App.navigate('orders', { date: ${JSON.stringify(shown)} })`);
        await wait(4000);
        const count = () => contents.executeJavaScript(`({ cards: document.querySelectorAll('.order-card').length, fresh: document.querySelectorAll('.order-card.fresh').length, banners: document.querySelectorAll('.live-banner').length, live: document.querySelector('.live')?.textContent })`);
        const catalog = (await callApi('GET', '/ordering/catalog')).data;
        const option = catalog.products.flatMap((product) => product.options).find((unit) => unit.available && !unit.side_ids.length);
        result.live = { before: await count(), steps: [] };
        for (const date of [shown, other].filter(Boolean)) {
          const slots = (await callApi('GET', '/ordering/slots', { query: { date, total: option.price } })).data;
          const slot = slots.slots.find((candidate) => candidate.selectable);
          const created = await callApi('POST', '/orders', { json: { items: [{ key: 'product:' + option.id, quantity: 1 }], customer_name: 'Client en direct ' + date, source: 'phone', phone: '0612345678', slot_id: slot.id, idempotency_key: slots.idempotency_key, expected_total: option.price } });
          if (created.status !== 201) throw new Error('Live order not created: ' + JSON.stringify(created.data).slice(0, 300));
          const started = Date.now();
          let seen = null;
          while (Date.now() - started < 10000) {
            await wait(250);
            const state = await count();
            if (state.fresh > 0 && (date === shown ? state.cards > result.live.before.cards : state.banners > 0)) { seen = { ...state, seconds: (Date.now() - started) / 1000 }; break; }
          }
          result.live.steps.push({ date, order: created.data.order.id, seen });
          await capture(mainWindow, 'live-' + date);
          if (!seen) throw new Error('Live order not shown: ' + JSON.stringify(result.live) + ' ' + JSON.stringify(await count()));
        }
      }
      if (process.env.SITHINEM_SMOKE_ORDER === '1') {
        // Drives the order-taking screen like a user: one dish, customer, first free slot, then save.
        send('app:event', { type: 'navigate', view: 'take-order' });
        await wait(2500);
        const steps = [
          `(() => { const tile = [...document.querySelectorAll('.catalog .tile')].find((node) => !node.querySelector('.tag')); tile.click(); return tile.textContent; })()`,
          `(() => { const confirm = document.querySelector('.drawer-foot .button.primary'); if (confirm) confirm.click(); return Boolean(confirm); })()`,
          `(() => { const set = (input, value) => { input.value = value; input.dispatchEvent(new Event('input')); }; const inputs = document.querySelectorAll('.customer-form input'); set(inputs[0], 'Client test poste'); set(inputs[1], '06 12 34 56 78'); document.querySelector('.customer-form textarea').value = ''; return inputs.length; })()`,
          `(() => { const slot = document.querySelector('.slot-choice:not(:disabled)'); slot.click(); return slot.textContent; })()`,
          `(() => { document.querySelector('.cart-foot .button.primary').click(); return true; })()`
        ];
        result.order = [];
        for (const [index, step] of steps.entries()) {
          try { result.order.push(await contents.executeJavaScript(step)); } catch { throw new Error('Order step ' + (index + 1) + ' failed: ' + JSON.stringify(result.order) + ' ' + await contents.executeJavaScript('document.querySelector("#toasts")?.textContent || document.querySelector(".cart")?.innerText || ""')); }
          await wait(1800);
        }
        await capture(mainWindow, 'order-done');
        const placed = await contents.executeJavaScript(`document.querySelector('.done h2')?.textContent || ''`);
        if (!placed.includes('enregistrée')) throw new Error('Order taking failed: ' + JSON.stringify(result.order) + ' ' + await contents.executeJavaScript(`document.querySelector('#toasts')?.textContent || ''`));
        result.placed = placed;
        await contents.executeJavaScript(`[...document.querySelectorAll('.done .button')].find((node) => node.textContent.includes('Voir la commande')).click()`);
        await wait(1500);
        await capture(mainWindow, 'order-drawer');
        send('app:event', { type: 'navigate', view: 'card' });
        await wait(2000);
        await contents.executeJavaScript(`document.querySelector('.list-row').click()`);
        await wait(2000);
        await capture(mainWindow, 'product-editor');
        send('app:event', { type: 'navigate', view: 'orders' });
        await wait(2500);
        await capture(mainWindow, 'orders-after');
      }
      const errors = await contents.executeJavaScript('window.__errors || []');
      if (errors.length) throw new Error('Renderer errors: ' + errors.join(' | '));
    }
    fs.writeFileSync(path.join(output, 'smoke-result.json'), JSON.stringify({ ok: true, ...result }));
    app.quit();
  } catch (error) {
    fs.writeFileSync(path.join(output, 'smoke-error.txt'), String(error.stack));
    app.exit(2);
  }
}
