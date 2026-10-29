// Shared helpers. Every value from the server is inserted as text, never as HTML.
window.__errors = [];
window.addEventListener('error', (event) => window.__errors.push(String(event.message)));
window.addEventListener('unhandledrejection', (event) => { if (!(event.reason instanceof ApiError)) window.__errors.push(String(event.reason?.message || event.reason)); });

const App = { views: {}, current: null, params: {}, state: null };

function h(tag, attrs, ...children) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attrs || {})) {
    if (value === undefined || value === null || value === false) continue;
    if (name === 'class') node.className = value;
    else if (name === 'text') node.textContent = value;
    else if (name === 'dataset') Object.assign(node.dataset, value);
    else if (name === 'style') node.style.cssText = value;
    else if (name.startsWith('on')) node.addEventListener(name.slice(2), value);
    else if (name === 'value') node.value = value;
    else if (name === 'checked' || name === 'disabled' || name === 'selected' || name === 'required' || name === 'multiple') node[name] = Boolean(value);
    else node.setAttribute(name, value === true ? '' : value);
  }
  append(node, children);
  return node;
}
function append(node, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}
function fill(node, ...children) { node.replaceChildren(); return append(node, children); }
const svgNS ='http://www.w3.org/2000/svg';
function icon(name) {
  const paths = {
    orders: 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5', 'take-order': 'M3 5h2l2.4 10h10.2L20 8H7M9 20h.01M17 20h.01M13 10v4M11 12h4',
    card: 'M4 5h16v14H4zM8 5v14M11 9h6M11 13h6', formulas: 'M4 6h16M4 12h16M4 18h10M18 16v4M16 18h4', slots: 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18z',
    calendar: 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4', statistics: 'M5 20V11M10 20V5M15 20v-8M20 20v-4', settings: 'M12 9a3 3 0 1 0 0 6a3 3 0 0 0 0-6zM4 12h2M18 12h2M12 4v2M12 18v2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4',
    logout: 'M10 5H5v14h5M14 8l4 4l-4 4M18 12H9', close: 'M6 6l12 12M18 6L6 18', left: 'M15 6l-6 6l6 6', right: 'M9 6l6 6l-6 6', plus: 'M12 5v14M5 12h14',
    printer: 'M7 9V4h10v5M7 17H5V9h14v8h-2M7 14h10v6H7z', search: 'M11 4a7 7 0 1 0 0 14a7 7 0 0 0 0-14zM20 20l-4-4', refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6', check: 'M5 12l5 5L20 7', phone: 'M6 3h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2z'
  };
  const svg = document.createElementNS(svgNS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('class', 'icon');
  const path = document.createElementNS(svgNS, 'path');
  path.setAttribute('d', paths[name] || ''); svg.append(path);
  return svg;
}

const euro = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
const money = (cents) => euro.format((Number(cents) || 0) / 100);
const decimal = (cents) => cents === null || cents === undefined ? '' : ((Number(cents) || 0) / 100).toFixed(2);
const today = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Paris' });
function shiftDate(date, days) { const value = new Date(date + 'T12:00:00Z'); value.setUTCDate(value.getUTCDate() + days); return value.toISOString().slice(0, 10); }
const frenchDate = (date) => new Date(date + 'T12:00:00Z').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
function uuid() { return crypto.randomUUID(); }
function store(key, value) { try { if (value === undefined) return JSON.parse(localStorage.getItem('sithinem.' + key) || 'null'); localStorage.setItem('sithinem.' + key, JSON.stringify(value)); } catch { return null; } return value; }

class ApiError extends Error {
  constructor(message, status, errors = {}) { super(message); this.status = status; this.errors = errors; }
}
async function api(method, route, input) {
  let result;
  try { result = await window.sithinem.api(method, route, input); }
  catch (error) { throw new ApiError(cleanError(error), 0); }
  const data = result.data || {};
  if (result.status >= 200 && result.status < 300) return data;
  if (result.status === 401) { App.signedOut?.(); throw new ApiError(data.message || 'Session expirée. Reconnectez-vous.', 401); }
  if (result.status === 429) throw new ApiError('Trop de requêtes en peu de temps. Patientez quelques secondes puis réessayez.', 429);
  const errors = data.errors || {};
  const messages = Object.values(errors).flat();
  const message = messages.length ? messages.join(' ') : (data.message && !/^Server Error$/i.test(data.message) ? data.message : 'Le serveur a refusé l’action (code ' + result.status + ').');
  throw new ApiError(message, result.status, errors);
}
const cleanError = (error) => String(error?.message || error).replace(/^Error invoking remote method '[^']+': (?:Error: )?/, '');

function toast(message, kind = 'ok') {
  const node = h('div', { class: 'toast ' + kind, role: kind === 'error' ? 'alert' : 'status' }, message);
  document.getElementById('toasts').append(node);
  setTimeout(() => node.classList.add('leaving'), kind === 'error' ? 6000 : 3200);
  setTimeout(() => node.remove(), kind === 'error' ? 6400 : 3600);
}
// Runs an action with a busy button and reports the error in place, so a failure is never silent.
async function busy(button, work, success) {
  if (button) button.disabled = true;
  try { const result = await work(); if (success) toast(success); return result; }
  catch (error) { if (!(error instanceof ApiError && error.status === 401)) toast(error.message || cleanError(error), 'error'); return undefined; }
  finally { if (button) button.disabled = false; }
}

let drawerClose = null;
function drawer(title, body, { wide = false, footer = [] } = {}) {
  closeDrawer();
  const panel = h('aside', { class: 'drawer' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('header', { class: 'drawer-head' }, h('h2', { text: title }), h('button', { class: 'icon-button', type: 'button', title: 'Fermer (Échap)', 'aria-label': 'Fermer', onclick: () => closeDrawer() }, icon('close'))),
    h('div', { class: 'drawer-body' }, body), footer.length ? h('footer', { class: 'drawer-foot' }, footer) : null);
  const backdrop = h('div', { class: 'backdrop', onclick: (event) => { if (event.target === backdrop) closeDrawer(); } }, panel);
  const keys = (event) => { if (event.key === 'Escape') closeDrawer(); };
  document.addEventListener('keydown', keys);
  document.body.append(backdrop);
  drawerClose = () => { document.removeEventListener('keydown', keys); backdrop.remove(); drawerClose = null; };
  requestAnimationFrame(() => panel.querySelector('input:not([type=hidden]),select,textarea,button.primary')?.focus());
  return { panel, close: closeDrawer };
}
function closeDrawer() { if (drawerClose) drawerClose(); }
function confirmBox(message, label = 'Confirmer', danger = false) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (value) => { if (done) return; done = true; dialog.remove(); document.removeEventListener('keydown', keys); resolve(value); };
    const keys = (event) => { if (event.key === 'Escape') finish(false); };
    const dialog = h('div', { class: 'backdrop confirm' }, h('div', { class: 'confirm-box', role: 'alertdialog', 'aria-modal': 'true' },
      h('p', { text: message }),
      h('div', { class: 'row end' }, h('button', { class: 'button', type: 'button', onclick: () => finish(false) }, 'Retour'),
        h('button', { class: 'button ' + (danger ? 'danger' : 'primary'), type: 'button', onclick: () => finish(true) }, label))));
    document.addEventListener('keydown', keys);
    document.body.append(dialog);
    dialog.querySelector('.button:last-child').focus();
  });
}

// Small form helpers.
function field(label, control, hint) { return h('label', { class: 'field' }, h('span', { class: 'label', text: label }), control, hint ? h('small', { class: 'hint', text: hint }) : null); }
function check(label, attrs = {}) { return h('label', { class: 'check' }, h('input', { type: 'checkbox', ...attrs }), h('span', { text: label })); }
function select(options, value, attrs = {}) {
  return h('select', attrs, options.map((option) => option.group
    ? h('optgroup', { label: option.group }, option.options.map((inner) => h('option', { value: inner.value, selected: String(inner.value) === String(value) }, inner.label)))
    : h('option', { value: option.value, selected: String(option.value) === String(value), disabled: option.disabled }, option.label)));
}
function segmented(options, value, onchange) {
  const group = h('div', { class: 'segmented', role: 'radiogroup' });
  for (const option of options) {
    group.append(h('button', { type: 'button', role: 'radio', 'aria-checked': String(option.value === value), class: option.value === value ? 'active' : '', onclick: () => onchange(option.value) }, option.label));
  }
  return group;
}
function pill(text, kind = '') { return h('span', { class: 'pill ' + kind, text }); }
function empty(title, text, action) { return h('div', { class: 'empty' }, h('h3', { text: title }), text ? h('p', { text }) : null, action || null); }
function loading() { return h('div', { class: 'loading', role: 'status' }, h('span', { class: 'spinner' }), 'Chargement…'); }
function failure(error, retry) { return h('div', { class: 'empty error' }, h('h3', { text: 'Impossible de charger cet écran' }), h('p', { text: error.message }), h('button', { class: 'button', type: 'button', onclick: retry }, 'Réessayer')); }
function thumb(src, name, size = '') { return src ? h('img', { class: 'thumb ' + size, src, alt: '', loading: 'lazy' }) : h('span', { class: 'thumb placeholder ' + size, 'aria-hidden': 'true', text: (name || '?').trim().charAt(0).toUpperCase() }); }
function statusKind(status) { return { awaiting_payment: 'muted', received: 'warn', preparing: 'warn', ready: 'ok', completed: 'ok', cancelled: 'muted' }[status] || ''; }

// A view module renders into the main area: { title, render(main, params) }.
function registerView(id, view) { App.views[id] = view; }
