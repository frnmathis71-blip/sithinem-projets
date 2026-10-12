// Statistics: period totals, 14-day activity and today's service.
function barChart(title, points, value, format, caption) {
  const max = Math.max(1, ...points.map(value));
  const tip = h('div', { class: 'chart-tip', hidden: true });
  const bars = h('div', { class: 'bars', role: 'img', 'aria-label': title + ' ; valeurs détaillées dans le tableau sous le graphique' }, points.map((point) => {
    const amount = value(point);
    return h('div', { class: 'bar-slot', onmouseenter: (event) => { tip.hidden = false; tip.textContent = point.label + ' · ' + format(amount); tip.style.left = event.currentTarget.offsetLeft + event.currentTarget.offsetWidth / 2 + 'px'; }, onmouseleave: () => { tip.hidden = true; } },
      h('div', { class: 'bar' + (amount === 0 ? ' zero' : ''), style: 'height:' + Math.max(amount ? 2 : 0, amount / max * 100) + '%' }), h('span', { class: 'bar-label', text: point.label }));
  }));
  return h('section', { class: 'panel chart' }, h('div', { class: 'list-head' }, h('h2', { text: title }), h('span', { class: 'muted small', text: points.some((point) => value(point) > 0) ? caption + ' · max ' + format(max) : 'Aucune commande sur la période' })),
    h('div', { class: 'chart-area' }, bars, tip),
    h('details', {}, h('summary', { text: 'Voir les données' }), h('table', {}, h('thead', {}, h('tr', {}, h('th', { text: 'Jour' }), h('th', { text: caption }))),
      h('tbody', {}, points.map((point) => h('tr', {}, h('td', { text: point.label }), h('td', { class: 'num', text: format(value(point)) })))))));
}

registerView('statistics', {
  title: 'Statistiques',
  render(main) {
    const content = h('div', {}, loading());
    main.append(h('header', { class: 'view-head' }, h('div', {}, h('h1', { text: 'Bonjour ' + (App.state.user?.first_name || '') }), h('p', { class: 'muted', text: 'Valeur des commandes non annulées, par date de création. Comparaison avec la même durée écoulée de la période précédente.' }))), content);
    async function load() {
      try {
        const data = await api('GET', '/statistics');
        const report = data.report;
        fill(content, 
          data.failed_notifications ? h('div', { class: 'notice error', text: data.failed_notifications + ' notification(s) en attente d’envoi. Les commandes sont bien enregistrées.' }) : null,
          h('div', { class: 'stat-grid' }, report.periods.map((period) => h('article', { class: 'panel stat' }, h('span', { class: 'eyebrow', text: period.label }), h('strong', { class: 'num', text: money(period.revenue) }),
            h('span', { text: period.count + ' commande(s)' }), h('small', { class: period.evolution === null ? 'muted' : period.evolution >= 0 ? 'up' : 'down', text: period.evolution === null ? 'Pas de comparaison disponible' : (period.evolution >= 0 ? '▲ +' : '▼ ') + period.evolution + ' % sur la période comparable' })))),
          h('div', { class: 'grid-2 top' },
            barChart('Activité des 14 derniers jours', report.chart, (point) => point.value, money, 'Montant'),
            barChart('Nombre de commandes sur 14 jours', report.chart, (point) => point.count, (count) => count + ' cmd', 'Commandes')),
          h('div', { class: 'grid-2 top' },
            h('section', { class: 'panel' }, h('h2', { text: 'Le service d’aujourd’hui' }),
              h('div', { class: 'metric' }, h('span', { text: 'Commandes en attente' }), h('strong', { class: 'num', text: report.pending })),
              h('div', { class: 'metric' }, h('span', { text: 'Commandes retirées' }), h('strong', { class: 'num', text: report.completed })),
              h('div', { class: 'metric' }, h('span', { text: 'Produits remis aux clients' }), h('strong', { class: 'num', text: report.units })),
              h('div', { class: 'metric' }, h('span', { text: 'Encaissé aujourd’hui' }), h('strong', { class: 'num', text: money(report.cash) }))),
            h('section', { class: 'panel list' }, h('div', { class: 'list-head' }, h('h2', { text: 'Commandes à préparer' }), h('button', { class: 'link', type: 'button', onclick: () => App.navigate('orders') }, 'Toutes les commandes →')),
              data.waiting.length ? data.waiting.map((order) => h('button', { type: 'button', class: 'list-row', onclick: () => openOrder(order.id, load) },
                h('strong', { class: 'clock', text: order.slot.start }), h('div', { class: 'list-main' }, h('strong', { text: '#' + order.id + ' · ' + order.customer_name }), h('span', { class: 'muted small', text: order.source_label })),
                h('strong', { class: 'num', text: money(order.total) }))) : h('p', { class: 'muted pad', text: 'Aucune commande en attente. Vous êtes à jour.' }))));
      } catch (error) { fill(content, failure(error, load)); }
    }
    load();
  }
});
