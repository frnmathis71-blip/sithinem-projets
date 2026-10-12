// Orders board and order detail, with the same actions as the web back office.
function orderItems(order, prices = false) {
  return h('ul', { class: 'items' }, order.items.map((item) => h('li', {},
    h('div', { class: 'item-line' }, h('span', {}, h('b', { text: item.quantity + ' × ' }), item.name), prices ? h('span', { class: 'num', text: money(item.quantity * item.unit_price) }) : null),
    item.composition.length ? h('ul', { class: 'composition' }, item.composition.map((part) => h('li', { text: part.quantity + ' × ' + part.name }))) : null)));
}

async function printTicket(order, button) {
  return busy(button, async () => {
    const ticket = await api('GET', '/orders/' + order.id + '/ticket');
    await window.sithinem.print(ticket);
  }, 'Ticket transmis à l’imprimante du poste.');
}

async function openOrder(id, onChange) {
  const body = h('div', { class: 'order-detail' }, loading());
  const view = drawer('Commande #' + id, body, { wide: false });
  const changed = () => { onChange?.(); App.views.orders.changed?.(); };
  async function action(button, method, route, input, message) {
    const result = await busy(button, () => api(method, route, input), message);
    if (result?.order) { render(result.order); changed(); }
  }
  function render(order) {
    const actions = [];
    if (order.status === 'preparing') actions.push(h('button', { class: 'button primary', type: 'button', onclick: (event) => action(event.currentTarget, 'PATCH', '/orders/' + order.id + '/status', { json: { status: 'completed' } }, 'Commande terminée.') }, icon('check'), 'Terminée'));
    if (!order.paid && order.status !== 'cancelled') actions.push(h('button', { class: 'button', type: 'button', onclick: (event) => action(event.currentTarget, 'POST', '/orders/' + order.id + '/paid', undefined, 'Encaissement enregistré.') }, 'Encaisser ' + money(order.total)));
    actions.push(h('button', { class: 'button', type: 'button', onclick: (event) => printTicket(order, event.currentTarget) }, icon('printer'), 'Imprimer le ticket'));
    fill(body, 
      h('div', { class: 'order-head' }, h('div', {}, h('p', { class: 'eyebrow', text: order.number }), h('h3', { class: 'customer', text: order.customer_name })),
        h('div', { class: 'pills' }, pill(order.status_label, statusKind(order.status)), pill(order.paid ? 'Encaissée' : 'À encaisser', order.paid ? 'ok' : ''), pill(order.source_label))),
      h('dl', { class: 'facts' },
        h('div', {}, h('dt', { text: 'Retrait' }), h('dd', { text: frenchDate(order.slot.date) + ' à ' + order.slot.start })),
        h('div', {}, h('dt', { text: 'Téléphone' }), h('dd', { class: 'num', text: order.phone }))),
      order.customer_message ? h('div', { class: 'message' }, h('strong', { text: 'Consignes du client' }), h('p', { text: order.customer_message })) : null,
      h('section', { class: 'box' }, orderItems(order, true), h('div', { class: 'total-line' }, h('span', { text: 'Total' }), h('strong', { class: 'num', text: money(order.total) }))),
      h('div', { class: 'actions' }, actions),
      order.history ? h('section', {}, h('h4', { text: 'Suivi' }), h('ol', { class: 'timeline' }, order.history.map((step) => h('li', {}, h('strong', { text: step.label }), h('span', { text: step.at }))))) : null,
      h('div', { class: 'danger-zone' },
        order.status === 'preparing' ? h('button', { class: 'link danger', type: 'button', onclick: async (event) => { if (await confirmBox('Annuler la commande #' + order.id + ' de ' + order.customer_name + ' ? Le montant du créneau sera libéré.', 'Annuler la commande', true)) action(event.target, 'PATCH', '/orders/' + order.id + '/status', { json: { status: 'cancelled' } }, 'Commande annulée.'); } }, 'Annuler la commande') : null,
        order.status === 'cancelled' ? h('button', { class: 'link', type: 'button', onclick: (event) => action(event.target, 'PATCH', '/orders/' + order.id + '/status', { json: { status: 'preparing' } }, 'Commande remise en préparation.') }, 'Remettre en préparation') : null,
        h('button', { class: 'link', type: 'button', onclick: async (event) => { const ticket = await busy(event.target, () => api('GET', '/orders/' + order.id + '/ticket')); if (ticket) body.append(h('pre', { class: 'ticket', text: ticket.text })); } }, 'Aperçu du ticket')));
  }
  try { render((await api('GET', '/orders/' + id)).order); }
  catch (error) { fill(body, failure(error, () => openOrder(id, onChange))); }
  return view;
}

registerView('orders', {
  title: 'Commandes',
  render(main, params) {
    const state = { date: params.date || today(), view: params.view || 'active', slot: params.slot || '' };
    let timer = null, signature = '', warned = false, shownHeading = '';
    const header = h('header', { class: 'view-head' });
    const board = h('div', { class: 'board' }, loading());
    const side = h('aside', { class: 'board-side' });
    main.append(header, h('div', { class: 'board-layout' }, board, side));
    function controls(label) {
      const date = h('input', { type: 'date', value: state.date, onchange: (event) => { if (event.target.value) { state.date = event.target.value; state.slot = ''; load(true); } } });
      fill(header, 
        h('div', {}, h('h1', { text: state.view === 'active' ? 'En préparation' : state.view === 'history' ? 'Historique' : 'Toutes les commandes' }), h('p', { class: 'muted capital', text: label || frenchDate(state.date) })),
        h('div', { class: 'toolbar' },
          h('div', { class: 'date-nav' }, h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Jour précédent', onclick: () => { state.date = shiftDate(state.date, -1); state.slot = ''; load(true); } }, icon('left')), date,
            h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Jour suivant', onclick: () => { state.date = shiftDate(state.date, 1); state.slot = ''; load(true); } }, icon('right')),
            state.date !== today() ? h('button', { class: 'button small', type: 'button', onclick: () => { state.date = today(); state.slot = ''; load(true); } }, 'Aujourd’hui') : null),
          segmented([{ value: 'active', label: 'En préparation' }, { value: 'history', label: 'Historique' }, { value: 'all', label: 'Toutes' }], state.view, (value) => { state.view = value; load(true); }),
          h('button', { class: 'button primary', type: 'button', onclick: () => App.navigate('take-order') }, icon('plus'), 'Nouvelle commande')));
    }
    function card(order) {
      return h('button', { type: 'button', class: 'order-card ' + order.status, style: '--accent:' + order.accent, onclick: () => openOrder(order.id, () => load()) },
        h('div', { class: 'order-card-top' }, h('strong', { class: 'clock', text: order.slot.start }), h('div', { class: 'pills' }, pill(order.status_label, statusKind(order.status)), order.paid ? pill('Encaissée', 'ok') : null)),
        h('div', { class: 'order-card-title' }, h('span', { class: 'order-id', text: '#' + order.id }), h('span', { class: 'customer', text: order.customer_name })),
        h('span', { class: 'source', text: order.source_label }),
        order.customer_message ? h('p', { class: 'message small', text: order.customer_message }) : null,
        orderItems(order),
        h('div', { class: 'order-card-foot' }, h('strong', { class: 'num', text: money(order.total) }), h('span', { class: 'muted', text: 'Détail →' })));
    }
    function printing(data) {
      const info = data.printing;
      return h('section', { class: 'panel' }, h('h2', { text: 'Impression' }),
        info.configured
          ? [h('p', { text: info.pending + ' ticket(s) en attente ou en cours d’envoi. Les commandes du jour partent automatiquement vers le poste d’impression.' }),
            h('button', { class: 'button full', type: 'button', onclick: async (event) => { if (await confirmBox('Imprimer toutes les commandes du jour ? Les commandes déjà imprimées et annulées seront aussi imprimées, identifiées comme telles.', 'Tout imprimer')) { const result = await busy(event.target, () => api('POST', '/orders/print-today')); if (result) { toast(result.count + ' ticket(s) ajouté(s) à la file d’impression.'); load(); } } } }, icon('printer'), 'Imprimer toutes les commandes du jour')]
          : h('p', { class: 'muted', text: 'Impression automatique non configurée sur le serveur. Utilisez « Imprimer le ticket » dans chaque commande pour imprimer sur l’imprimante de ce poste.' }),
        info.issues.map((issue) => h('div', { class: 'notice error' }, h('strong', { text: 'Ticket #' + issue.order_id + ' : envoi à vérifier' }), h('p', { text: issue.error || '' }),
          h('button', { class: 'button small', type: 'button', onclick: async (event) => { if (await busy(event.target, () => api('POST', '/printing/' + issue.id + '/retry'), 'Le ticket sera repris par le poste d’impression.')) load(); } }, 'J’ai vérifié l’imprimante : relancer'))));
    }
    function summary(data) {
      const totals = data.orders.reduce((sum, order) => sum + (order.status !== 'cancelled' ? order.total : 0), 0);
      return h('section', { class: 'panel' }, h('h2', { text: 'Ce service' }),
        h('div', { class: 'metric' }, h('span', { text: 'Commandes affichées' }), h('strong', { class: 'num', text: data.orders.length })),
        h('div', { class: 'metric' }, h('span', { text: 'À encaisser' }), h('strong', { class: 'num', text: data.orders.filter((order) => !order.paid && order.status !== 'cancelled').length })),
        h('div', { class: 'metric' }, h('span', { text: 'Montant' }), h('strong', { class: 'num', text: money(totals) })),
        check('Son pour les nouvelles commandes', { checked: store('sound') !== false, onchange: (event) => store('sound', event.target.checked) }));
    }
    async function load(reset = false) {
      if (reset) { signature = ''; fill(board, loading()); }
      try {
        const data = await api('GET', '/orders', { query: { date: state.date, view: state.view, slot: state.slot } });
        const feeds = data.date === today() && state.view === 'active' && !state.slot;
        App.boardWatching = feeds;
        if (feeds) App.observeActive(data.orders);
        const heading = data.date_label + state.view;
        if (heading !== shownHeading) { shownHeading = heading; controls(data.date_label); }
        warned = false;
        const next = JSON.stringify(data);
        if (next === signature) return;
        signature = next;
        fill(board, state.slot ? h('div', { class: 'notice' }, 'Commandes d’un seul créneau. ', h('button', { class: 'link', type: 'button', onclick: () => { state.slot = ''; load(true); } }, 'Afficher tous les créneaux')) : '',
          data.orders.length ? h('div', { class: 'order-grid' }, data.orders.map(card)) : empty(state.view === 'active' ? 'Tout est à jour' : 'Aucune commande', state.view === 'active' ? 'Aucune commande en préparation pour cette journée.' : 'Aucune commande dans cette vue pour cette journée.'));
        fill(side, summary(data), printing(data));
      } catch (error) {
        if (!signature) fill(board, failure(error, () => load(true)));
        else if (!warned) { warned = true; toast('Actualisation impossible : ' + error.message, 'error'); }
      }
    }
    controls();
    load(true);
    timer = setInterval(() => load(), 4000);
    this.changed = () => load();
    return () => { clearInterval(timer); this.changed = null; App.boardWatching = false; };
  }
});
