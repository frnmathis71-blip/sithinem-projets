// Phone and counter orders. The cart is kept on this computer and priced by the server at every change.
registerView('take-order', {
  title: 'Prise de commande',
  render(main) {
    const draft = Object.assign({ items: [], refused: [], name: '', phone: '', source: 'phone', date: '', slot: null, message: '', key: uuid() }, store('order-draft') || {});
    let catalog = null, quote = { lines: [], total: 0, valid: false, suggestion: null }, slots = null, tab = 'card', category = 'all', search = '', quoteTimer = null, quoteRun = 0, slotRun = 0, done = null;
    const save = () => store('order-draft', draft);
    const catalogArea = h('section', { class: 'catalog' }, loading());
    const cartArea = h('aside', { class: 'cart' });
    main.append(h('div', { class: 'ordering' }, catalogArea, cartArea));
    main.classList.add('flush');

    function cartChanged() {
      draft.key = uuid();
      save();
      renderCart();
      clearTimeout(quoteTimer);
      quoteTimer = setTimeout(requote, 120);
    }
    function add(key, quantity, label) {
      const existing = draft.items.find((item) => item.key === key);
      if (existing) existing.quantity = Math.min(50, existing.quantity + quantity);
      else draft.items.push({ key, quantity, label });
      done = null;
      cartChanged();
      toast(label + ' ajouté.');
    }
    async function requote() {
      const run = ++quoteRun;
      if (!draft.items.length) { quote = { lines: [], total: 0, valid: false, suggestion: null }; renderCart(); return loadSlots(); }
      try {
        const result = await api('POST', '/ordering/quote', { json: { items: draft.items.map(({ key, quantity }) => ({ key, quantity })), refused: draft.refused } });
        if (run !== quoteRun) return;
        quote = result;
        renderCart();
        loadSlots();
      } catch (error) { if (run === quoteRun) toast(error.message, 'error'); }
    }
    async function loadSlots() {
      const run = ++slotRun;
      try {
        const result = await api('GET', '/ordering/slots', { query: { date: draft.date, total: quote.total } });
        if (run !== slotRun) return;
        slots = result;
        if (!draft.date || !result.dates.some((day) => day.date === draft.date)) draft.date = result.date || '';
        const chosen = result.slots.find((slot) => slot.id === draft.slot);
        if (draft.slot && (!chosen || !chosen.selectable)) draft.slot = null;
        save();
        renderCart();
      } catch (error) { if (run === slotRun) { slots = { error: error.message, dates: [], slots: [] }; renderCart(); } }
    }

    // Catalogue column.
    function renderCatalog() {
      if (!catalog) return;
      const term = search.trim().toLocaleLowerCase('fr');
      const matches = (text) => !term || String(text).toLocaleLowerCase('fr').includes(term);
      const searchBox = h('label', { class: 'search' }, icon('search'), h('input', { type: 'search', placeholder: 'Rechercher un plat, une offre…', value: search, oninput: (event) => { search = event.target.value; renderCatalog(); document.querySelector('.search input')?.focus(); } }));
      const head = h('header', { class: 'view-head compact' }, h('div', {}, h('h1', { text: 'Prise de commande' }), h('p', { class: 'muted', text: 'Téléphone ou comptoir · sans compte client · prix et capacités contrôlés par le serveur.' })),
        h('div', { class: 'toolbar' }, segmented([{ value: 'card', label: 'La carte' }, { value: 'formulas', label: 'Formules' + (catalog.formulas.length ? ' · ' + catalog.formulas.length : '') }, { value: 'offers', label: 'Offres' + (catalog.offers.length ? ' · ' + catalog.offers.length : '') }], tab, (value) => { tab = value; renderCatalog(); }), searchBox,
          h('button', { class: 'icon-button', type: 'button', title: 'Actualiser la carte', 'aria-label': 'Actualiser la carte', onclick: () => loadCatalog() }, icon('refresh'))));
      let body;
      if (tab === 'card') {
        const groups = catalog.categories.map((group) => ({ ...group, products: catalog.products.filter((product) => (product.category_id ?? null) === (group.id ?? null) && matches(product.name + ' ' + product.options.map((option) => option.label || '').join(' '))) })).filter((group) => group.products.length);
        const chips = h('div', { class: 'chips' }, h('button', { type: 'button', class: category === 'all' ? 'chip active' : 'chip', onclick: () => { category = 'all'; renderCatalog(); } }, 'Tout'),
          groups.map((group) => h('button', { type: 'button', class: String(category) === String(group.id) ? 'chip active' : 'chip', onclick: () => { category = group.id; renderCatalog(); } }, group.name)));
        const shown = groups.filter((group) => category === 'all' || String(category) === String(group.id));
        body = [chips, shown.length ? shown.map((group) => h('section', { class: 'product-group' }, h('h2', { text: group.name }), h('div', { class: 'product-grid' }, group.products.map(productTile)))) : empty('Aucun plat trouvé', term ? 'Modifiez la recherche.' : 'Aucun plat disponible à la vente.')];
      } else if (tab === 'formulas') {
        const list = catalog.formulas.filter((formula) => matches(formula.name + ' ' + formula.categories));
        body = list.length ? h('div', { class: 'product-grid wide' }, list.map((formula) => h('button', { type: 'button', class: 'tile formula', onclick: () => chooseFormula(formula) },
          h('div', { class: 'tile-body' }, h('strong', { text: formula.name }), h('span', { class: 'muted', text: formula.categories }), formula.description ? h('span', { class: 'muted small', text: formula.description }) : null),
          h('span', { class: 'price', text: money(formula.price) })))) : empty('Aucune formule', 'Créez des formules dans l’écran « Formules ».');
      } else {
        const list = catalog.offers.filter((offer) => matches(offer.name));
        body = list.length ? h('div', { class: 'product-grid wide' }, list.map((offer) => h('button', { type: 'button', class: 'tile', onclick: () => add('offer:' + offer.id, 1, offer.name) },
          thumb(offer.image, offer.name), h('div', { class: 'tile-body' }, h('strong', { text: offer.name }), h('span', { class: 'muted small', text: offer.composition.map((part) => part.quantity + ' × ' + part.name).join(' · ') }), offer.ends_at ? h('span', { class: 'muted small', text: 'Jusqu’au ' + offer.ends_at }) : null),
          h('span', { class: 'price', text: money(offer.price) })))) : empty('Aucune offre en cours');
      }
      fill(catalogArea, head, h('div', { class: 'catalog-body' }, body));
    }
    function productTile(product) {
      const available = product.options.filter((option) => option.available);
      const from = Math.min(...available.map((option) => option.price));
      return h('button', { type: 'button', class: 'tile', onclick: () => chooseProduct(product) }, thumb(product.image || available[0]?.image, product.name),
        h('div', { class: 'tile-body' }, h('strong', { text: product.name }), product.has_variants ? h('span', { class: 'muted small', text: available.map((option) => option.label).join(' · ') }) : null,
          available.some((option) => option.code === 'main' && option.side_ids.length) ? h('span', { class: 'tag', text: 'Accompagnement au choix' }) : null),
        h('span', { class: 'price', text: (product.has_variants && available.length > 1 ? 'dès ' : '') + money(from) }));
    }
    function stepper(value, onchange) {
      const output = h('output', { class: 'num', text: value });
      const set = (next) => { value = Math.max(1, Math.min(50, next)); output.textContent = value; onchange(value); };
      return h('div', { class: 'stepper' }, h('button', { type: 'button', class: 'icon-button', 'aria-label': 'Retirer un', onclick: () => set(value - 1) }, '−'), output, h('button', { type: 'button', class: 'icon-button', 'aria-label': 'Ajouter un', onclick: () => set(value + 1) }, '+'));
    }
    function chooseProduct(product) {
      const options = product.options.filter((option) => option.available);
      const pairs = (option) => option.code === 'main' ? catalog.options.filter((side) => option.side_ids.includes(side.id)) : [];
      if (options.length === 1 && !pairs(options[0]).length) return add('product:' + options[0].id, 1, options[0].name);
      let option = options.length === 1 ? options[0] : null, side = null, quantity = 1;
      const body = h('div', { class: 'chooser' });
      const confirm = h('button', { class: 'button primary', type: 'button' });
      function render() {
        const sides = option ? pairs(option) : [];
        const price = option ? (option.price + (side ? side.price : 0)) * quantity : 0;
        confirm.textContent = option ? 'Ajouter · ' + money(price) : 'Choisissez une déclinaison';
        confirm.disabled = !option;
        fill(body, 
          product.description ? h('p', { class: 'muted', text: product.description }) : null,
          options.length > 1 ? h('fieldset', {}, h('legend', { text: 'Déclinaison' }), h('div', { class: 'choices' }, product.options.map((candidate) => h('button', { type: 'button', class: 'choice' + (option?.id === candidate.id ? ' selected' : ''), disabled: !candidate.available, onclick: () => { option = candidate; side = null; render(); } },
            h('span', { text: candidate.label || candidate.name }), h('span', { class: 'num', text: candidate.available ? money(candidate.price) : 'Indisponible' }))))) : null,
          sides.length ? h('fieldset', {}, h('legend', { text: 'Accompagnement' }), h('div', { class: 'choices' },
            h('button', { type: 'button', class: 'choice' + (!side ? ' selected' : ''), onclick: () => { side = null; render(); } }, h('span', { text: 'Sans accompagnement' }), h('span', { class: 'muted', text: 'à la carte' })),
            sides.map((candidate) => h('button', { type: 'button', class: 'choice' + (side?.id === candidate.id ? ' selected' : ''), onclick: () => { side = candidate; render(); } }, h('span', { text: candidate.name }), h('span', { class: 'num', text: '+ ' + money(candidate.price) }))))) : null,
          h('div', { class: 'row' }, h('span', { class: 'label', text: 'Quantité' }), stepper(quantity, (value) => { quantity = value; confirm.textContent = 'Ajouter · ' + money((option.price + (side ? side.price : 0)) * quantity); })));
      }
      confirm.onclick = async () => {
        if (!side) { add('product:' + option.id, quantity, option.name); return closeDrawer(); }
        const composed = await busy(confirm, () => api('POST', '/ordering/compose', { json: { selections: { main: option.id, side: side.id } } }));
        if (composed) { add(composed.key, quantity, option.name + ' + ' + side.name); closeDrawer(); }
      };
      render();
      drawer(product.name, body, { footer: [h('button', { class: 'button', type: 'button', onclick: closeDrawer }, 'Annuler'), confirm] });
    }
    function chooseFormula(formula) {
      const labels = Object.assign({ side: 'Accompagnement' }, catalog.labels);
      const selections = {};
      let quantity = 1;
      const body = h('div', { class: 'chooser' });
      const confirm = h('button', { class: 'button primary', type: 'button' });
      function render() {
        const missing = formula.steps.filter((step) => !selections[step]);
        confirm.disabled = missing.length > 0;
        confirm.textContent = missing.length ? 'Choisissez : ' + missing.map((step) => labels[step].toLowerCase()).join(', ') : 'Ajouter · ' + money(formula.price * quantity);
        fill(body, formula.description ? h('p', { class: 'muted', text: formula.description }) : null,
          formula.steps.map((step) => {
            const main = catalog.options.find((option) => option.id === selections.main);
            const choices = catalog.options.filter((option) => option.code === step && (step !== 'side' || (main && main.side_ids.includes(option.id))));
            return h('fieldset', {}, h('legend', { text: labels[step] }), step === 'side' && !main ? h('p', { class: 'muted', text: 'Choisissez d’abord le plat.' })
              : choices.length ? h('div', { class: 'choices' }, choices.map((option) => h('button', { type: 'button', class: 'choice' + (selections[step] === option.id ? ' selected' : ''), onclick: () => { selections[step] = option.id; if (step === 'main') delete selections.side; render(); } }, h('span', { text: option.name }))))
                : h('p', { class: 'notice error', text: 'Aucun produit disponible pour cette étape.' }));
          }),
          h('div', { class: 'row' }, h('span', { class: 'label', text: 'Quantité' }), stepper(quantity, (value) => { quantity = value; render(); })));
      }
      confirm.onclick = async () => {
        const composed = await busy(confirm, () => api('POST', '/ordering/compose', { json: { rule_id: formula.id, selections } }));
        if (composed) { add(composed.key, quantity, formula.name); closeDrawer(); }
      };
      render();
      drawer(formula.name + ' · ' + money(formula.price), body, { footer: [h('button', { class: 'button', type: 'button', onclick: closeDrawer }, 'Annuler'), confirm] });
    }

    // Cart and customer column.
    // The customer inputs are built once; prices, slots and the summary refresh around them.
    let parts = null;
    function buildCart() {
      const name = h('input', { value: draft.name, maxlength: '255', autocomplete: 'off', oninput: (event) => { draft.name = event.target.value; save(); refreshFooter(); } });
      const phone = h('input', { type: 'tel', value: draft.phone, maxlength: '30', autocomplete: 'off', placeholder: '06 12 34 56 78', oninput: (event) => { draft.phone = event.target.value; save(); refreshFooter(); } });
      const message = h('textarea', { rows: '2', maxlength: '1000', placeholder: 'Allergies, cuisson, sans oignons…', oninput: (event) => { draft.message = event.target.value; save(); } });
      message.value = draft.message;
      parts = { head: h('header', { class: 'cart-head' }), lines: h('div'), suggestion: h('div'), total: h('div', { class: 'total-line big' }), source: h('div'), date: h('div'), slots: h('div'), foot: h('footer', { class: 'cart-foot' }), name, phone, message };
      fill(cartArea, parts.head,
        h('div', { class: 'cart-scroll' }, parts.lines, parts.suggestion, parts.total, h('p', { class: 'muted small', text: 'Paiement sur place lors du retrait.' }),
          h('section', { class: 'customer-form' }, h('h3', { text: 'Client' }), parts.source, field('Nom du client', name), field('Téléphone', phone, 'Obligatoire, y compris au comptoir.'),
            h('h3', { text: 'Retrait' }), parts.date, parts.slots, field('Consignes', message))),
        parts.foot);
    }
    function renderCart() {
      if (done) return renderDone();
      if (!parts || !cartArea.contains(parts.lines)) buildCart();
      if (parts.name.value !== draft.name) parts.name.value = draft.name;
      if (parts.phone.value !== draft.phone) parts.phone.value = draft.phone;
      if (parts.message.value !== draft.message) parts.message.value = draft.message;
      const lines = new Map(quote.lines.map((line) => [line.key, line]));
      fill(parts.head, h('h2', { text: 'Commande' }), draft.items.length ? h('button', { class: 'link danger', type: 'button', onclick: async () => { if (await confirmBox('Vider le panier de cette commande ?', 'Vider', true)) { draft.items = []; draft.refused = []; cartChanged(); } } }, 'Vider') : '');
      fill(parts.lines, draft.items.length ? h('ul', { class: 'cart-lines' }, draft.items.map((item) => {
        const line = lines.get(item.key);
        return h('li', { class: line && !line.available ? 'unavailable' : '' },
          h('div', { class: 'cart-line-main' }, h('strong', { text: line?.name || item.label }), line?.composition?.length ? h('small', { class: 'muted', text: line.composition.map((part) => part.name).join(' · ') }) : null,
            line && !line.available ? h('small', { class: 'danger-text', text: 'Indisponible : retirez cet article.' }) : null),
          h('div', { class: 'cart-line-side' }, stepper(item.quantity, (value) => { item.quantity = value; cartChanged(); }), h('span', { class: 'num', text: line ? money(line.unit_price * item.quantity) : '…' }),
            h('button', { type: 'button', class: 'link danger', onclick: () => { draft.items = draft.items.filter((other) => other !== item); cartChanged(); } }, 'Retirer')));
      })) : h('p', { class: 'muted cart-empty', text: 'Ajoutez des plats, formules ou offres depuis la carte.' }));
      fill(parts.suggestion, quote.suggestion ? h('div', { class: 'suggestion' }, h('div', {}, h('strong', { text: 'Regrouper en « ' + quote.suggestion.name + ' »' }), h('span', { text: money(quote.suggestion.before) + ' → ' + money(quote.suggestion.after) + ' · économie ' + money(quote.suggestion.saving) })),
        h('div', { class: 'row' }, h('button', { class: 'button small primary', type: 'button', onclick: async (event) => {
          const result = await busy(event.currentTarget, () => api('POST', '/ordering/suggestion', { json: { items: draft.items.map(({ key, quantity }) => ({ key, quantity })), signature: quote.suggestion.signature, refused: draft.refused } }), 'Articles regroupés en formule.');
          if (result) { draft.items = result.items.map((item) => ({ ...item, label: draft.items.find((old) => old.key === item.key)?.label || quote.suggestion.name })); cartChanged(); }
        } }, 'Appliquer'), h('button', { class: 'button small', type: 'button', onclick: () => { draft.refused = [...draft.refused, quote.suggestion.signature].slice(-100); cartChanged(); } }, 'Non merci'))) : '');
      fill(parts.total, h('span', { text: 'Total' }), h('strong', { class: 'num', text: money(quote.total) }));
      fill(parts.source, segmented([{ value: 'phone', label: 'Téléphone' }, { value: 'counter', label: 'Comptoir' }], draft.source, (value) => { draft.source = value; save(); renderCart(); }));
      const dates = slots?.dates || [];
      fill(parts.date, field('Date', select(dates.length ? dates.map((day) => ({ value: day.date, label: day.label })) : [{ value: '', label: slots ? 'Aucune date ouverte' : 'Chargement…' }], draft.date, { disabled: !dates.length, onchange: (event) => { draft.date = event.target.value; draft.slot = null; save(); loadSlots(); } })));
      fill(parts.slots, slots?.error ? h('p', { class: 'notice error', text: slots.error })
        : !slots ? h('p', { class: 'muted', text: 'Chargement des créneaux…' })
          : slots.slots.length ? h('div', { class: 'slot-picker', role: 'radiogroup', 'aria-label': 'Créneau de retrait' }, slots.slots.map((slot) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(draft.slot === slot.id), class: 'slot-choice' + (draft.slot === slot.id ? ' selected' : ''), disabled: !slot.selectable, title: slot.reason || slot.status, onclick: () => { draft.slot = slot.id; save(); renderCart(); } },
            h('strong', { text: slot.start }), h('small', { text: slot.selectable ? (slot.status === 'Presque complet' ? 'Presque complet' : 'Libre') : slot.reason })))) : h('p', { class: 'muted', text: 'Aucun créneau ce jour-là.' }));
      refreshFooter();
    }
    function refreshFooter() {
      if (!parts || done) return;
      const missing = [!quote.valid && draft.items.length ? 'retirer les articles indisponibles' : '', !draft.name.trim() ? 'nom' : '', !draft.phone.trim() ? 'téléphone' : '', !draft.slot ? 'créneau' : ''].filter(Boolean);
      const submit = h('button', { class: 'button primary full big', type: 'button', disabled: !draft.items.length, onclick: () => place(submit) }, 'Enregistrer la commande · ' + money(quote.total));
      fill(parts.foot, draft.items.length && missing.length ? h('p', { class: 'muted small', text: 'À compléter : ' + missing.join(' · ') }) : '', submit);
    }
    async function place(button) {
      if (!quote.valid) return toast('Retirez d’abord les articles indisponibles.', 'error');
      if (!draft.name.trim() || !draft.phone.trim()) return toast('Indiquez le nom et le téléphone du client.', 'error');
      if (!draft.slot) return toast('Choisissez un créneau de retrait.', 'error');
      const result = await busy(button, () => api('POST', '/orders', { json: { items: draft.items.map(({ key, quantity }) => ({ key, quantity })), customer_name: draft.name.trim(), source: draft.source, phone: draft.phone.trim(),
        slot_id: draft.slot, idempotency_key: draft.key, expected_total: quote.total, customer_message: draft.message.trim() || null } }));
      if (!result) { loadSlots(); return; }
      done = result.order;
      Object.assign(draft, { items: [], refused: [], name: '', phone: '', slot: null, message: '', key: uuid() });
      save();
      quote = { lines: [], total: 0, valid: false, suggestion: null };
      App.views.orders.changed?.();
      renderDone();
      loadSlots();
    }
    function renderDone() {
      const order = done;
      fill(cartArea, h('div', { class: 'done' },
        h('div', { class: 'done-mark' }, icon('check')), h('h2', { text: 'Commande #' + order.id + ' enregistrée' }),
        h('p', { text: order.customer_name + ' · retrait ' + frenchDate(order.slot.date) + ' à ' + order.slot.start }),
        h('p', { class: 'num big-total', text: money(order.total) }),
        h('div', { class: 'stack' },
          h('button', { class: 'button full', type: 'button', onclick: (event) => printTicket(order, event.currentTarget) }, icon('printer'), 'Imprimer le ticket'),
          h('button', { class: 'button full', type: 'button', onclick: () => openOrder(order.id) }, 'Voir la commande'),
          h('button', { class: 'button full', type: 'button', onclick: () => { done = null; parts = null; renderCart(); } }, icon('plus'), 'Nouvelle commande'))));
    }
    async function loadCatalog() {
      fill(catalogArea, loading());
      try { catalog = await api('GET', '/ordering/catalog'); renderCatalog(); }
      catch (error) { fill(catalogArea, failure(error, loadCatalog)); }
    }
    loadCatalog();
    renderCart();
    requote();
    return () => { clearTimeout(quoteTimer); main.classList.remove('flush'); };
  }
});
