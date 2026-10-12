const NAV = [
  ['orders', 'Commandes'], ['take-order', 'Prise de commande'], ['card', 'Carte'], ['formulas', 'Formules'],
  ['slots', 'Créneaux'], ['calendar', 'Calendrier'], ['statistics', 'Statistiques']
];
const root = document.getElementById('root');
let watcher = null, known = null, audio = null;

// Loud kitchen bell of about 1.8 s: two rising three-note phrases, square + triangle for a piercing tone,
// through a compressor so the peaks stay loud without distorting.
function chime() {
  try {
    audio = audio || new AudioContext();
    if (audio.state === 'suspended') audio.resume();
    const output = audio.createDynamicsCompressor();
    output.threshold.value = -12; output.ratio.value = 6;
    output.connect(audio.destination);
    const notes = [988, 1319, 1760, 988, 1319, 1760];
    notes.forEach((frequency, index) => {
      const start = audio.currentTime + 0.05 + index * 0.3;
      const gain = audio.createGain();
      gain.connect(output);
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.8, start + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + (index === notes.length - 1 ? 0.5 : 0.28));
      for (const [type, level] of [['square', 0.35], ['triangle', 1]]) {
        const oscillator = audio.createOscillator(), mix = audio.createGain();
        oscillator.type = type; oscillator.frequency.value = frequency; mix.gain.value = level;
        oscillator.connect(mix); mix.connect(gain);
        oscillator.start(start); oscillator.stop(start + 0.55);
      }
    });
  } catch { /* Sound is a convenience; the badge and notification still show new orders. */ }
}
App.chime = chime;

// Live feed: every 3 seconds, asks only for orders created since the last one seen, whatever their pickup day.
// New orders ring, notify, are highlighted and refresh the board at once.
App.fresh = new Map();
App.live = { ok: null, at: null, error: '' };
let watching = false, legacyFeed = false;
// Sites not yet updated have no live feed: fall back to today's board and report its new orders.
async function recentOrders() {
  if (!legacyFeed) {
    try { return await api('GET', '/orders/recent', { query: { after: known ?? '' } }); }
    catch (error) { if (error.status !== 404) throw error; legacyFeed = true; }
  }
  const board = await api('GET', '/orders', { query: { view: 'active' } });
  const latest = Math.max(0, ...board.orders.map((order) => order.id));
  return { latest_id: latest, preparing_today: board.orders.length, orders: known === null ? [] : board.orders.filter((order) => order.id > known) };
}
async function watchOrders() {
  if (watching) return;
  watching = true;
  try {
    const feed = await recentOrders();
    const fresh = known === null ? [] : feed.orders;
    known = Math.max(known ?? 0, feed.latest_id, ...feed.orders.map((order) => order.id));
    App.live = { ok: true, at: new Date(), error: '' };
    const badge = document.querySelector('[data-nav="orders"] .badge');
    if (badge) { badge.textContent = feed.preparing_today || ''; badge.hidden = !feed.preparing_today; }
    if (fresh.length) {
      if (store('sound') !== false) chime();
      for (const order of fresh) {
        App.fresh.set(order.id, Date.now());
        window.sithinem.notify({ title: 'Nouvelle commande #' + order.id, body: order.customer_name + ' · retrait ' + frenchDate(order.slot.date) + ' à ' + order.slot.start + ' · ' + money(order.total) });
      }
      App.views.orders.arrived?.(fresh);
    }
  } catch (error) {
    App.live = { ok: false, at: App.live.at, error: error.message };
  } finally {
    watching = false;
    App.views.orders.liveChanged?.();
  }
}

function navigate(view, params = {}) {
  if (!App.views[view]) view = 'orders';
  closeDrawer();
  App.leave?.();
  App.leave = null;
  App.current = view; App.params = params;
  store('view', view);
  for (const link of document.querySelectorAll('[data-nav]')) link.setAttribute('aria-current', link.dataset.nav === view ? 'page' : 'false');
  const main = document.getElementById('main');
  if (!main) return;
  fill(main);
  main.scrollTop = 0;
  App.leave = App.views[view].render(main, params) || null;
  document.title = App.views[view].title + ' · Sithinem';
}
App.navigate = navigate;
App.refresh = () => navigate(App.current, App.params);

function updateBanner() {
  const holder = document.getElementById('update');
  if (!holder) return;
  const update = App.state.update || {};
  fill(holder, update.status === 'ready'
    ? h('div', { class: 'update-banner' }, h('span', { text: 'Version ' + update.version + ' prête.' }), h('button', { class: 'button small primary', type: 'button', onclick: () => window.sithinem.installUpdate() }, 'Redémarrer pour l’installer'))
    : update.status === 'downloading' ? h('div', { class: 'update-banner quiet', text: 'Téléchargement de la version ' + update.version + '…' }) : '');
}

function printerLine() {
  const printer = App.state.printer;
  const trouble = /erreur|impossible|interrompue|indisponible/i.test(printer.status);
  return h('div', { class: 'printer-status' + (printer.printing ? (trouble ? ' warn' : ' ok') : '') }, h('span', { class: 'dot' }),
    printer.printing ? printer.status.replace(/\.$/, '') : (printer.name ? 'Impression automatique désactivée' : 'Aucune imprimante'));
}

function shell() {
  const user = App.state.user || {};
  fill(root, h('div', { class: 'layout' },
    h('nav', { class: 'sidebar', 'aria-label': 'Navigation' },
      h('div', { class: 'brand' }, h('img', { src: 'img/mark.png', alt: '' }), h('div', {}, h('strong', { text: 'Sithinem' }), h('span', { text: 'Poste restaurant' }))),
      h('ul', {}, NAV.map(([id, label], index) => h('li', {}, h('button', { type: 'button', class: 'nav-item' + (id === 'take-order' ? ' accent' : ''), dataset: { nav: id }, title: label + ' (Ctrl+' + (index + 1) + ')', onclick: () => navigate(id) },
        icon(id), h('span', { text: label }), id === 'orders' ? h('span', { class: 'badge', hidden: true }) : null)))),
      h('div', { class: 'sidebar-foot' },
        h('div', { id: 'printer-line' }, printerLine()),
        h('button', { type: 'button', class: 'nav-item small', onclick: () => window.sithinem.settings() }, icon('settings'), h('span', { text: 'Poste et imprimante' })),
        h('button', { type: 'button', class: 'nav-item small', onclick: async () => { if (await confirmBox('Se déconnecter de ce poste ? Les commandes ne seront plus affichées jusqu’à la prochaine connexion.', 'Se déconnecter')) { await window.sithinem.logout(); signedOut(); } } },
          icon('logout'), h('span', { text: user.first_name ? 'Déconnexion · ' + user.first_name : 'Déconnexion' })),
        h('div', { class: 'version' }, h('span', { text: 'Version ' + App.state.version }), h('button', { class: 'link small', type: 'button', onclick: () => window.sithinem.checkUpdate() }, 'Mises à jour'))
      )),
    h('div', { class: 'workspace' }, h('div', { class: 'titlebar' }), h('div', { id: 'update' }), h('main', { id: 'main', tabindex: '-1' }))));
  window.sithinem.titlebar('light');
  updateBanner();
  navigate(store('view') || 'orders');
  clearInterval(watcher);
  known = null;
  watchOrders();
  watcher = setInterval(watchOrders, 3000);
}

function loginScreen(message) {
  clearInterval(watcher);
  closeDrawer();
  let twoFactor = false;
  const email = h('input', { type: 'email', autocomplete: 'username', required: true, maxlength: '255' });
  const password = h('input', { type: 'password', autocomplete: 'current-password', required: true, maxlength: '1000' });
  const code = h('input', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '100' });
  const codeField = field('Code de vérification', code, 'Code à 6 chiffres de votre application, ou un code de récupération.');
  codeField.hidden = true;
  const error = h('p', { class: 'form-error', role: 'alert' }, message || '');
  const submit = h('button', { class: 'button primary full', type: 'submit' }, 'Se connecter');
  const form = h('form', { class: 'login-card', onsubmit: async (event) => {
    event.preventDefault();
    error.textContent = '';
    submit.disabled = true;
    try {
      const result = await window.sithinem.login({ email: email.value.trim(), password: password.value, code: twoFactor ? code.value.trim() : undefined });
      if (result.ok) { password.value = ''; return boot(); }
      if (result.data?.two_factor) { twoFactor = true; codeField.hidden = false; code.focus(); error.textContent = result.data.message; return; }
      if (result.status === 429) { error.textContent = 'Trop de tentatives de connexion. Patientez une minute avant de réessayer.'; return; }
      error.textContent = Object.values(result.data?.errors || {}).flat().join(' ') || result.data?.message || 'Connexion impossible (code ' + result.status + ').';
    } catch (failure) { error.textContent = cleanError(failure); }
    finally { submit.disabled = false; }
  } },
    h('img', { class: 'login-logo', src: 'img/logo.png', alt: 'Sithinem Traiteur' }), h('h1', { text: 'Connexion' }),
    h('p', { class: 'muted', text: 'Connectez-vous avec votre compte administrateur du site ' + (App.state.siteUrl || '') + '.' }),
    field('Adresse e-mail', email), field('Mot de passe', password), codeField, error, submit,
    h('button', { class: 'link', type: 'button', onclick: () => window.sithinem.settings() }, 'Changer l’adresse du site ou l’imprimante'));
  window.sithinem.titlebar('dark');
  fill(root, h('div', { class: 'login' }, h('div', { class: 'titlebar' }), form));
  email.focus();
}

function setupScreen() {
  window.sithinem.titlebar('dark');
  fill(root, h('div', { class: 'login' }, h('div', { class: 'titlebar' }), h('div', { class: 'login-card' }, h('img', { class: 'login-logo', src: 'img/logo.png', alt: 'Sithinem Traiteur' }), h('h1', { text: 'Bienvenue' }),
    h('p', { text: 'Indiquez d’abord l’adresse HTTPS de votre site dans les paramètres du poste. Vous pourrez ensuite vous connecter.' }),
    h('button', { class: 'button primary full', type: 'button', onclick: () => window.sithinem.settings() }, 'Ouvrir les paramètres du poste'))));
}

function signedOut() { App.state.signedIn = false; loginScreen('Session terminée. Reconnectez-vous pour continuer.'); }
App.signedOut = signedOut;

async function boot() {
  App.state = await window.sithinem.state();
  if (!App.state.siteUrl) return setupScreen();
  if (!App.state.signedIn) return loginScreen();
  shell();
}

window.sithinem.onEvent(async (event) => {
  if (event.type === 'navigate' && App.state?.signedIn) navigate(event.view);
  if (event.type === 'refresh' && App.state?.signedIn) App.refresh();
  if (event.type === 'signed-out' && App.state?.signedIn) signedOut();
  if (event.type === 'signed-in' && !document.querySelector('.layout')) boot();
  if (event.type === 'state') boot();
  if (event.type === 'update') { App.state.update = event.update; updateBanner(); }
});
setInterval(async () => {
  if (!App.state?.signedIn) return;
  try { const state = await window.sithinem.state(); App.state.printer = state.printer; { const line = document.getElementById('printer-line'); if (line) fill(line, printerLine()); }; } catch { /* ignored */ }
}, 4000);
boot().catch((error) => fill(root, failure(error, () => location.reload())));
