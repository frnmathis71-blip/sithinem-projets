const NAV = [
  ['orders', 'Commandes'], ['take-order', 'Prise de commande'], ['card', 'Carte'], ['formulas', 'Formules'],
  ['slots', 'Créneaux'], ['calendar', 'Calendrier'], ['statistics', 'Statistiques']
];
const root = document.getElementById('root');
let watcher = null, known = null, audio = null;

function chime() {
  try {
    audio = audio || new AudioContext();
    for (const [index, frequency] of [880, 1175].entries()) {
      const oscillator = audio.createOscillator(), gain = audio.createGain();
      const start = audio.currentTime + index * 0.18;
      oscillator.frequency.value = frequency; oscillator.connect(gain); gain.connect(audio.destination);
      gain.gain.setValueAtTime(0.0001, start); gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02); gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.35);
      oscillator.start(start); oscillator.stop(start + 0.4);
    }
  } catch { /* Sound is a convenience; the badge and notification still show new orders. */ }
}

// Watches today's orders in the background so new online orders are announced on every screen.
// The board feeds the same check while it shows today's orders, so the server is not polled twice.
App.observeActive = (orders) => {
  const ids = new Set(orders.map((order) => order.id));
  const fresh = known ? orders.filter((order) => !known.has(order.id)) : [];
  known = ids;
  const badge = document.querySelector('[data-nav="orders"] .badge');
  if (badge) { badge.textContent = orders.length || ''; badge.hidden = !orders.length; }
  if (fresh.length && store('sound') !== false) chime();
  for (const order of fresh) window.sithinem.notify({ title: 'Nouvelle commande #' + order.id, body: order.customer_name + ' · retrait ' + order.slot.start + ' · ' + money(order.total) });
  return fresh.length > 0;
};
async function watchOrders() {
  if (App.boardWatching) return;
  try {
    const board = await api('GET', '/orders', { query: { view: 'active' } });
    if (App.observeActive(board.orders)) App.views.orders.changed?.();
  } catch { /* The orders screen shows connection problems itself. */ }
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
  document.title = App.views[view].title + ' · Sithi Nem';
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
      h('div', { class: 'brand' }, h('strong', { text: 'SITHI NEM' }), h('span', { text: 'Poste restaurant' })),
      h('ul', {}, NAV.map(([id, label], index) => h('li', {}, h('button', { type: 'button', class: 'nav-item' + (id === 'take-order' ? ' accent' : ''), dataset: { nav: id }, title: label + ' (Ctrl+' + (index + 1) + ')', onclick: () => navigate(id) },
        icon(id), h('span', { text: label }), id === 'orders' ? h('span', { class: 'badge', hidden: true }) : null)))),
      h('div', { class: 'sidebar-foot' },
        h('div', { id: 'printer-line' }, printerLine()),
        h('button', { type: 'button', class: 'nav-item small', onclick: () => window.sithinem.settings() }, icon('settings'), h('span', { text: 'Poste et imprimante' })),
        h('button', { type: 'button', class: 'nav-item small', onclick: async () => { if (await confirmBox('Se déconnecter de ce poste ? Les commandes ne seront plus affichées jusqu’à la prochaine connexion.', 'Se déconnecter')) { await window.sithinem.logout(); signedOut(); } } },
          icon('logout'), h('span', { text: user.first_name ? 'Déconnexion · ' + user.first_name : 'Déconnexion' })),
        h('small', { class: 'version', text: 'Version ' + App.state.version })
      )),
    h('div', { class: 'workspace' }, h('div', { id: 'update' }), h('main', { id: 'main', tabindex: '-1' }))));
  updateBanner();
  navigate(store('view') || 'orders');
  clearInterval(watcher);
  known = null;
  watchOrders();
  watcher = setInterval(watchOrders, 5000);
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
    h('p', { class: 'eyebrow', text: 'SITHI NEM · POSTE RESTAURANT' }), h('h1', { text: 'Connexion' }),
    h('p', { class: 'muted', text: 'Connectez-vous avec votre compte administrateur du site ' + (App.state.siteUrl || '') + '.' }),
    field('Adresse e-mail', email), field('Mot de passe', password), codeField, error, submit,
    h('button', { class: 'link', type: 'button', onclick: () => window.sithinem.settings() }, 'Changer l’adresse du site ou l’imprimante'));
  fill(root, h('div', { class: 'login' }, form));
  email.focus();
}

function setupScreen() {
  fill(root, h('div', { class: 'login' }, h('div', { class: 'login-card' }, h('p', { class: 'eyebrow', text: 'SITHI NEM · POSTE RESTAURANT' }), h('h1', { text: 'Bienvenue' }),
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
