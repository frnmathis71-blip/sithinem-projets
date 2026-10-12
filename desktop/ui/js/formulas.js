// Menu formulas: categories and a fixed price; customers choose the products.
function editFormula(formula, categories, reload) {
  const errors = errorsBox();
  const name = h('input', { value: formula?.name || '', maxlength: '255', placeholder: 'Menu du chef gourmand' });
  const description = h('textarea', { rows: '3', maxlength: '5000' }); description.value = formula?.description || '';
  const price = h('input', { inputmode: 'decimal', value: formula ? decimal(formula.price) : '20.00' });
  const position = h('input', { type: 'number', min: '0', max: '999', value: String(formula?.position ?? 0) });
  const active = check('Formule active', { checked: formula ? formula.available : true });
  const visible = check('Visible dans l’onglet Menus du site', { checked: formula ? formula.visible : true });
  const boxes = categories.map((category) => ({ code: category.code, box: check(category.label, { checked: formula?.categories.includes(category.code) }) }));
  const note = h('p', { class: 'notice', role: 'status' });
  const sync = () => {
    const chosen = boxes.filter((entry) => entry.box.querySelector('input').checked).map((entry) => entry.code);
    note.className = chosen.includes('main') && chosen.includes('chef_main') ? 'notice error' : 'notice';
    note.textContent = chosen.includes('main') && chosen.includes('chef_main') ? 'Une formule propose soit un plat classique, soit un plat du chef, jamais les deux.' : 'Plat classique : accompagnement ajouté automatiquement. Plat du chef : garniture déjà incluse.';
  };
  boxes.forEach((entry) => entry.box.querySelector('input').addEventListener('change', sync));
  sync();
  const saveButton = h('button', { class: 'button primary', type: 'button' }, 'Enregistrer la formule');
  saveButton.onclick = async () => {
    saveButton.disabled = true;
    try {
      await api('POST', '/formulas' + (formula ? '/' + formula.id : ''), { json: { name: name.value.trim(), description: description.value.trim() || null, price: price.value.trim(), position: Number(position.value),
        available: active.querySelector('input').checked, visible: visible.querySelector('input').checked, categories: boxes.filter((entry) => entry.box.querySelector('input').checked).map((entry) => entry.code) } });
      toast('Formule enregistrée.'); closeDrawer(); reload();
    } catch (error) { showErrors(errors, error); }
    finally { saveButton.disabled = false; }
  };
  drawer(formula ? 'Modifier ' + formula.label : 'Nouvelle formule', h('div', { class: 'editor' }, errors,
    h('section', {}, h('h3', { text: 'Présentation et prix' }), field('Nom de la formule', name), field('Description', description), h('div', { class: 'grid-2' }, field('Prix fixe (€)', price, 'Le prix couvre tous les produits.'), field('Ordre d’affichage', position)),
      active, h('p', { class: 'hint', text: 'Une formule inactive ne peut plus être commandée ni proposée en regroupement.' }), visible, h('p', { class: 'hint', text: 'Masquée mais active, elle reste proposée en regroupement si elle fait économiser.' })),
    h('section', {}, h('h3', { text: 'Catégories (un produit par catégorie)' }), h('div', { class: 'check-grid' }, boxes.map((entry) => entry.box)), note)), { wide: true, footer: [h('button', { class: 'button', type: 'button', onclick: closeDrawer }, 'Annuler'), saveButton] });
}

registerView('formulas', {
  title: 'Formules',
  render(main) {
    const content = h('div', {}, loading());
    let data = null;
    const reload = () => load();
    main.append(h('header', { class: 'view-head' }, h('div', {}, h('h1', { text: 'Formules de menu' }), h('p', { class: 'muted', text: 'Définissez les catégories et le prix. Les clients choisissent leurs produits dans la formule.' })),
      h('div', { class: 'toolbar' }, h('button', { class: 'button primary', type: 'button', onclick: () => data && editFormula(null, data.categories, reload) }, icon('plus'), 'Créer une formule'))), content);
    async function load() {
      try {
        data = await api('GET', '/formulas');
        fill(content, data.formulas.length ? h('section', { class: 'panel list' }, data.formulas.map((formula) => h('button', { type: 'button', class: 'list-row', onclick: () => editFormula(formula, data.categories, reload) },
          h('div', { class: 'list-main' }, h('strong', { text: formula.label }), h('span', { class: 'muted small', text: formula.categories_label + ' · ordre ' + formula.position })),
          h('div', { class: 'pills' }, pill(formula.available ? 'Active' : 'Inactive', formula.available ? 'ok' : 'muted'), pill(formula.visible ? 'Visible' : 'Masquée')),
          h('strong', { class: 'num', text: money(formula.price) }))))
          : empty('Votre première formule', 'Choisissez les catégories, donnez-lui un nom et fixez son prix.', h('button', { class: 'button primary', type: 'button', onclick: () => editFormula(null, data.categories, reload) }, 'Créer une formule')));
      } catch (error) { fill(content, failure(error, load)); }
    }
    load();
  }
});
