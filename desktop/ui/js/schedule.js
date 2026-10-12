// Slots & capacity, and the opening calendar.
const euroInput = (value) => value.trim().replace(',', '.');
const WEEKDAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

registerView('slots', {
  title: 'Créneaux',
  render(main, params) {
    let date = params.date || today();
    const header = h('header', { class: 'view-head' });
    const content = h('div', {}, loading());
    main.append(header, content);
    function controls() {
      fill(header, h('div', {}, h('h1', { text: 'Créneaux & capacité' }), h('p', { class: 'muted capital', text: frenchDate(date) })),
        h('div', { class: 'toolbar' }, h('div', { class: 'date-nav' },
          h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Jour précédent', onclick: () => { date = shiftDate(date, -1); load(); } }, icon('left')),
          h('input', { type: 'date', value: date, onchange: (event) => { if (event.target.value) { date = event.target.value; load(); } } }),
          h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Jour suivant', onclick: () => { date = shiftDate(date, 1); load(); } }, icon('right')),
          date !== today() ? h('button', { class: 'button small', type: 'button', onclick: () => { date = today(); load(); } }, 'Aujourd’hui') : null)));
    }
    function slotCard(slot, defaultCapacity) {
      const override = h('input', { inputmode: 'decimal', value: decimal(slot.capacity_override), placeholder: 'Par défaut : ' + money(defaultCapacity) });
      const closed = check('Fermer manuellement', { checked: slot.manually_closed });
      const ratio = slot.capacity > 0 ? Math.min(1, slot.used / slot.capacity) : (slot.used > 0 ? 1 : 0);
      const save = h('button', { class: 'button small full', type: 'button', onclick: async () => {
        if (await busy(save, () => api('PATCH', '/slots/' + slot.id, { json: { amount_capacity_override: override.value.trim() ? euroInput(override.value) : null, manually_closed: closed.querySelector('input').checked } }), 'Créneau ' + slot.start + ' mis à jour. Les commandes existantes sont conservées.')) load();
      } }, 'Enregistrer');
      return h('article', { class: 'slot-card' + (slot.manually_closed || slot.status === 'Fermé' ? ' closed' : '') },
        h('div', { class: 'slot-top' }, h('strong', { class: 'clock', text: slot.start }), pill(slot.status, slot.remaining === 0 || slot.manually_closed ? 'danger' : slot.status === 'Presque complet' ? 'warn' : 'ok')),
        h('p', { class: 'num' }, h('strong', { text: money(slot.used) }), ' / ' + money(slot.capacity)),
        h('div', { class: 'meter', role: 'img', 'aria-label': 'Charge ' + Math.round(ratio * 100) + ' %' }, h('span', { style: 'width:' + Math.round(ratio * 100) + '%' })),
        h('p', { class: 'muted small', text: money(slot.remaining) + ' disponibles · ' + slot.count + ' commande(s)' }),
        slot.used > slot.capacity ? h('p', { class: 'notice error small', text: 'Dépassement de ' + money(slot.used - slot.capacity) + '. Aucune nouvelle commande ne sera acceptée.' }) : null,
        slot.count > 0 && slot.status === 'Fermé' ? h('p', { class: 'notice error small', text: 'Des commandes existent sur ce créneau fermé. Contactez les clients si nécessaire.' }) : null,
        field('Capacité personnalisée (€)', override), closed, save,
        slot.count ? h('button', { class: 'link', type: 'button', onclick: () => App.navigate('orders', { date, slot: slot.id, view: 'all' }) }, 'Voir les ' + slot.count + ' commande(s) →') : null);
    }
    async function load() {
      controls();
      fill(content, loading());
      try {
        const data = await api('GET', '/slots', { query: { date } });
        const capacity = h('input', { inputmode: 'decimal', value: decimal(data.default_capacity) });
        const saveCapacity = h('button', { class: 'button primary', type: 'button', onclick: async () => {
          if (await busy(saveCapacity, () => api('PATCH', '/capacity', { json: { default_amount_capacity: euroInput(capacity.value) } }), 'Capacité par défaut mise à jour.')) load();
        } }, 'Enregistrer');
        fill(content, 
          h('div', { class: 'grid-2 top' },
            h('section', { class: 'panel' }, h('h2', { text: 'Montant maximal par créneau de 20 minutes' }),
              h('p', { class: 'muted', text: 'Par exemple, 200 € permettent 20 commandes à 10 € ou 2 commandes à 100 €. 0 € bloque les nouvelles commandes. S’applique aux créneaux sans personnalisation.' }),
              h('div', { class: 'row' }, field('Capacité par défaut (€)', capacity), saveCapacity)),
            h('section', { class: 'panel' }, h('h2', { text: data.hours.open ? 'Service de ' + data.hours.start + ' à ' + data.hours.end : 'Restaurant fermé ce jour-là' }),
              data.hours.reason ? h('p', { text: data.hours.reason }) : null,
              h('p', { class: 'muted', text: 'Les commandes annulées libèrent leur montant ; les commandes terminées restent comptées. Baisser un plafond conserve les commandes déjà acceptées.' }),
              h('button', { class: 'link', type: 'button', onclick: () => App.navigate('calendar') }, 'Modifier les horaires dans le calendrier →'))),
          data.slots.length ? h('div', { class: 'slot-grid' }, data.slots.map((slot) => slotCard(slot, data.default_capacity)))
            : empty('Pas de service ce jour-là', 'Ouvrez exceptionnellement cette date dans le calendrier pour proposer des créneaux.', h('button', { class: 'button', type: 'button', onclick: () => App.navigate('calendar') }, 'Ouvrir le calendrier')));
      } catch (error) { fill(content, failure(error, load)); }
    }
    load();
  }
});

registerView('calendar', {
  title: 'Calendrier',
  render(main) {
    let month = '';
    const content = h('div', {}, loading());
    main.append(h('header', { class: 'view-head' }, h('div', {}, h('h1', { text: 'Calendrier d’ouverture' }), h('p', { class: 'muted', text: 'Les exceptions remplacent les horaires habituels. Fermer une journée ne supprime aucune commande.' }))), content);
    async function load() {
      try {
        const data = await api('GET', '/calendar', { query: { month } });
        month = data.month;
        const grid = h('div', { class: 'month-grid' }, ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'].map((day) => h('span', { class: 'weekday', text: day })),
          data.days.map((day) => h('button', { type: 'button', class: 'day' + (day.hours.open ? ' open' : '') + (day.in_month ? '' : ' outside') + (day.date === today() ? ' today' : ''), title: 'Voir les créneaux du ' + day.date, onclick: () => App.navigate('slots', { date: day.date }) },
            h('strong', { text: day.number }), h('span', { text: day.hours.open ? day.hours.start + '–' + day.hours.end : 'Fermé' }), day.hours.reason ? h('small', { text: day.hours.reason }) : null)));
        const weekly = h('section', { class: 'panel' }, h('h2', { text: 'Horaires habituels' }), data.weekly.map((day) => {
          const open = select([{ value: '1', label: 'Ouvert' }, { value: '0', label: 'Fermé' }], day.is_open ? '1' : '0');
          const starts = h('input', { type: 'time', value: day.starts_at }), ends = h('input', { type: 'time', value: day.ends_at });
          const save = h('button', { class: 'button small', type: 'button', onclick: async () => { if (await busy(save, () => api('POST', '/hours', { json: { weekday: day.weekday, is_open: open.value === '1', starts_at: starts.value, ends_at: ends.value } }), 'Horaires du ' + WEEKDAYS[day.weekday - 1].toLowerCase() + ' enregistrés. Vérifiez les commandes existantes si vous avez réduit les horaires.')) load(); } }, 'Enregistrer');
          return h('div', { class: 'hours-row' }, h('strong', { text: WEEKDAYS[day.weekday - 1] }), open, starts, h('span', { class: 'muted', text: 'à' }), ends, save);
        }));
        const date = h('input', { type: 'date', required: true }), state = select([{ value: '0', label: 'Fermé' }, { value: '1', label: 'Ouvert exceptionnellement' }], '0');
        const starts = h('input', { type: 'time', value: '18:00' }), ends = h('input', { type: 'time', value: '23:00' }), reason = h('input', { maxlength: '255', placeholder: 'Vacances, événement…' });
        const add = h('button', { class: 'button primary', type: 'button', onclick: async () => {
          if (!date.value) return toast('Choisissez une date.', 'error');
          if (await busy(add, () => api('POST', '/exceptions', { json: { date: date.value, is_open: state.value === '1', starts_at: starts.value, ends_at: ends.value, reason: reason.value.trim() || null } }), 'Exception enregistrée. Consultez les créneaux de cette date.')) load();
        } }, 'Enregistrer l’exception');
        fill(content, h('div', { class: 'calendar-layout' },
          h('section', { class: 'panel' }, h('div', { class: 'list-head' }, h('h2', { class: 'capital', text: data.label }), h('div', { class: 'row' },
            h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Mois précédent', onclick: () => { month = data.previous; load(); } }, icon('left')),
            h('button', { class: 'icon-button', type: 'button', 'aria-label': 'Mois suivant', onclick: () => { month = data.next; load(); } }, icon('right')))), grid,
            h('p', { class: 'muted small', text: 'Cliquez sur une journée pour consulter ses créneaux et ses commandes.' })),
          h('div', { class: 'stack' }, weekly,
            h('section', { class: 'panel' }, h('h2', { text: 'Ouverture ou fermeture exceptionnelle' }), h('div', { class: 'grid-2' }, field('Date', date), field('État', state)), h('div', { class: 'grid-2' }, field('Début', starts), field('Fin', ends)), field('Motif', reason), add,
              h('p', { class: 'hint', text: 'Saisir une date déjà présente remplace son exception.' })),
            h('section', { class: 'panel' }, h('h2', { text: 'Exceptions enregistrées' }), data.exceptions.length ? data.exceptions.map((exception) => h('div', { class: 'exception-row' },
              h('div', {}, h('strong', { text: new Date(exception.date + 'T12:00:00Z').toLocaleDateString('fr-FR', { timeZone: 'UTC' }) }), h('span', { class: 'muted', text: ' · ' + (exception.is_open ? exception.starts_at + ' – ' + exception.ends_at : 'Fermé') + (exception.reason ? ' · ' + exception.reason : '') })),
              h('button', { class: 'link', type: 'button', onclick: async (event) => { if (await busy(event.target, () => api('DELETE', '/exceptions/' + exception.id), 'Les horaires habituels s’appliquent à nouveau.')) load(); } }, 'Rétablir les horaires habituels'))) : h('p', { class: 'muted', text: 'Aucune exception enregistrée.' })))));
      } catch (error) { fill(content, failure(error, load)); }
    }
    load();
  }
});
