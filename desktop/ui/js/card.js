// Menu card management: categories, dishes with variants and photos, offers.
function photoInput(current, onchange) {
  let file = null, remove = false;
  const preview = h('div', { class: 'photo-preview' });
  const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', onchange: () => {
    file = input.files[0] || null;
    if (file && file.size > 20 * 1024 * 1024) { toast('Photo trop lourde : 20 Mo maximum.', 'error'); input.value = ''; file = null; }
    remove = false; draw(); onchange?.();
  } });
  function draw() {
    const source = file ? URL.createObjectURL(file) : (!remove && current ? current : null);
    fill(preview, source ? h('img', { src: source, alt: '' }) : h('span', { class: 'muted small', text: 'Aucune photo' }),
      (file || (current && !remove)) ? h('button', { type: 'button', class: 'link danger', onclick: () => { file = null; input.value = ''; remove = Boolean(current); draw(); } }, 'Retirer la photo') : null);
  }
  draw();
  return { node: h('div', { class: 'photo-field' }, preview, input), async entries(prefix) {
    const name = prefix ? prefix + '[image]' : 'image';
    if (file) return [[name, { name: file.name, type: file.type, data: new Uint8Array(await file.arrayBuffer()) }]];
    return remove ? [[prefix ? prefix + '[remove_image]' : 'remove_image', '1']] : [];
  } };
}
function categoryOptions(categories, labels) {
  // Same choices as the web editor: one entry per product type, then custom categories.
  const typed = Object.entries(labels).map(([code, label]) => ({ value: categories.find((category) => category.code === code)?.id ?? 'type:' + code, label }));
  const custom = categories.filter((category) => !category.code || categories.find((other) => other.code === category.code)?.id !== category.id).map((category) => ({ value: category.id, label: category.name }));
  return [{ value: '', label: 'Sans catégorie' }, ...typed, ...(custom.length ? [{ group: 'Catégories personnalisées', options: custom }] : [])];
}
function errorsBox() { return h('div', { class: 'form-errors', role: 'alert' }); }
function showErrors(box, error) { fill(box, error ? h('p', { text: error.message }) : ''); if (error) box.scrollIntoView({ block: 'nearest' }); }

async function editProduct(id, reload) {
  let data;
  try { data = await api('GET', '/card/product' + (id ? '/' + id : '')); } catch (error) { return toast(error.message, 'error'); }
  const item = data.item;
  const errors = errorsBox();
  const name = h('input', { value: item.name || '', maxlength: '255', required: true });
  const description = h('textarea', { rows: '3', maxlength: '5000' }); description.value = item.description || '';
  const price = h('input', { inputmode: 'decimal', value: decimal(item.price), placeholder: '12,50' });
  const available = select([{ value: '1', label: 'Disponible' }, { value: '0', label: 'Indisponible' }], item.available ? '1' : '0');
  const category = select(categoryOptions(data.categories, data.labels), item.category_id ?? '');
  const offerOnly = check('Réserver ce produit aux offres', { checked: item.offer_only });
  const photo = photoInput(item.image);
  const restricted = check('Limiter aux accompagnements cochés', { checked: item.restricted_sides });
  const sides = item.sides.map((side) => ({ side, box: check(side.name, { checked: item.compatible_side_ids.includes(side.id) }) }));
  const variantsToggle = check('Proposer plusieurs déclinaisons', { checked: item.has_variants });
  const rows = h('div', { class: 'variant-rows' });
  const variants = [];
  function addVariant(variant = { id: '', label: '', price: null, available: true, image: null }) {
    const label = h('input', { value: variant.label || '', maxlength: '100', placeholder: 'Grande · poulet' });
    const value = h('input', { inputmode: 'decimal', value: decimal(variant.price), placeholder: '12,50' });
    const state = select([{ value: '1', label: 'Disponible' }, { value: '0', label: 'Indisponible' }], variant.available ? '1' : '0');
    const picture = photoInput(variant.image);
    const entry = { variant, label, value, state, picture };
    entry.node = h('div', { class: 'variant-row' }, h('div', { class: 'grid-3' }, field('Déclinaison', label), field('Prix (€)', value), field('Disponibilité', state)), picture.node,
      h('button', { type: 'button', class: 'link danger', onclick: () => { variants.splice(variants.indexOf(entry), 1); entry.node.remove(); } }, 'Retirer cette déclinaison'));
    variants.push(entry); rows.append(entry.node);
  }
  (item.variants.length ? item.variants : [undefined]).forEach((variant) => addVariant(variant));
  const variantPanel = h('div', {}, rows, h('button', { type: 'button', class: 'button small', onclick: () => addVariant() }, icon('plus'), 'Ajouter une déclinaison'),
    h('p', { class: 'hint', text: 'Retirer une déclinaison la désactive pour les prochains achats. Les commandes existantes sont conservées.' }));
  const syncVariants = () => { variantPanel.hidden = !variantsToggle.querySelector('input').checked; price.closest('.field').hidden = !variantPanel.hidden; };
  variantsToggle.querySelector('input').addEventListener('change', syncVariants);
  const body = h('div', { class: 'editor' }, errors,
    h('section', {}, h('h3', { text: 'Présentation' }), field('Nom', name), field('Description', description),
      h('div', { class: 'grid-2' }, field('Prix (€)', price), field('Disponibilité', available)), field('Catégorie', category),
      offerOnly, h('p', { class: 'hint', text: 'Un produit réservé aux offres n’est ni vendu seul ni inclus dans un menu classique.' }),
      h('div', { class: 'field' }, h('span', { class: 'label', text: 'Photo · JPG, PNG ou WebP · 20 Mo maximum' }), photo.node)),
    h('section', {}, h('h3', { text: 'Déclinaisons' }), variantsToggle, h('p', { class: 'hint', text: 'Exemples : « Petite », « Grande », « Poulet ». Chaque déclinaison a son propre prix.' }), variantPanel),
    h('details', { class: 'box' }, h('summary', { text: 'Accompagnements du plat classique' }),
      h('p', { class: 'hint', text: 'Dans les formules, le plat classique exige un accompagnement. À la carte, il est facultatif. Restriction activée sans choix : le plat est vendu seul uniquement.' }),
      restricted, h('div', { class: 'check-list' }, sides.length ? sides.map((entry) => entry.box) : h('p', { class: 'muted', text: 'Aucun accompagnement dans la carte.' }))));
  syncVariants();
  const saveButton = h('button', { class: 'button primary', type: 'button' }, 'Enregistrer le plat');
  saveButton.onclick = async () => {
    const hasVariants = variantsToggle.querySelector('input').checked;
    const form = [['name', name.value], ['description', description.value], ['available', available.value], ['category_id', category.value],
      ['offer_only', offerOnly.querySelector('input').checked], ['has_variants', hasVariants], ['restricted_sides', restricted.querySelector('input').checked], ...(await photo.entries())];
    if (!hasVariants) form.push(['price', price.value.trim()]);
    for (const entry of sides) if (entry.box.querySelector('input').checked) form.push(['compatible_side_ids[]', entry.side.id]);
    if (hasVariants) for (const [index, entry] of variants.entries()) {
      const prefix = 'variants[' + index + ']';
      form.push([prefix + '[id]', entry.variant.id || ''], [prefix + '[label]', entry.label.value], [prefix + '[price]', entry.value.value.trim()], [prefix + '[available]', entry.state.value], ...(await entry.picture.entries(prefix)));
    }
    saveButton.disabled = true;
    try { await api('POST', '/card/product' + (id ? '/' + id : ''), { form }); toast('Plat enregistré.'); closeDrawer(); reload(); }
    catch (error) { showErrors(errors, error); }
    finally { saveButton.disabled = false; }
  };
  drawer(id ? 'Modifier ' + item.name : 'Nouveau plat', body, { wide: true, footer: [
    id ? h('button', { class: 'link danger push-left', type: 'button', onclick: (event) => archive('product', id, item.name, event.target, reload) }, 'Archiver ce plat') : null,
    h('button', { class: 'button', type: 'button', onclick: closeDrawer }, 'Annuler'), saveButton] });
}

async function editOffer(id, reload) {
  let data;
  try { data = await api('GET', '/card/offer' + (id ? '/' + id : '')); } catch (error) { return toast(error.message, 'error'); }
  const item = data.item;
  const errors = errorsBox();
  const name = h('input', { value: item.name || '', maxlength: '255' });
  const description = h('textarea', { rows: '3', maxlength: '5000' }); description.value = item.description || '';
  const price = h('input', { inputmode: 'decimal', value: decimal(item.price), placeholder: '15,00' });
  const available = select([{ value: '1', label: 'Disponible' }, { value: '0', label: 'Indisponible' }], item.available ? '1' : '0');
  const photo = photoInput(item.image);
  const quantities = item.products.map((product) => ({ product, input: h('input', { class: 'qty', type: 'number', min: '0', max: '50', value: String(item.composition[product.id] || 0) }) }));
  const filter = h('input', { type: 'search', placeholder: 'Filtrer les produits…', oninput: () => { for (const entry of quantities) entry.row.hidden = !entry.product.name.toLowerCase().includes(filter.value.toLowerCase()); } });
  for (const entry of quantities) entry.row = h('label', { class: 'composition-row' }, h('span', {}, entry.product.name, entry.product.offer_only ? h('small', { class: 'muted', text: ' · réservé aux offres' }) : null, !entry.product.available ? h('small', { class: 'muted', text: ' · indisponible' }) : null), entry.input);
  const create = check('Créer un produit spécialement pour cette offre');
  const productName = h('input', { maxlength: '255' }), productDescription = h('textarea', { rows: '2', maxlength: '5000' }), productPrice = h('input', { inputmode: 'decimal', placeholder: '8,50' }), productQuantity = h('input', { type: 'number', min: '1', max: '50', value: '1' });
  const createFields = h('div', { class: 'box' }, h('p', { class: 'hint', text: 'Ce produit ne sera pas ajouté à la carte classique. Le prix facturé reste celui de l’offre.' }), field('Nom du produit', productName), field('Description', productDescription), h('div', { class: 'grid-2' }, field('Prix de référence (€)', productPrice), field('Quantité incluse', productQuantity)));
  create.querySelector('input').addEventListener('change', () => { createFields.hidden = !create.querySelector('input').checked; });
  createFields.hidden = true;
  const starts = h('input', { type: 'datetime-local', value: item.starts_at || '' });
  const noEnd = check('Pas de date de fin', { checked: Boolean(id) && !item.ends_at });
  const ends = h('input', { type: 'datetime-local', value: item.ends_at || '' });
  const syncEnd = () => { ends.closest('.field').hidden = noEnd.querySelector('input').checked; };
  noEnd.querySelector('input').addEventListener('change', syncEnd);
  const body = h('div', { class: 'editor' }, errors,
    h('section', {}, h('h3', { text: 'Présentation' }), field('Nom', name), field('Description', description), h('div', { class: 'grid-2' }, field('Prix de l’offre (€)', price), field('Disponibilité', available)),
      h('div', { class: 'field' }, h('span', { class: 'label', text: 'Photo' }), photo.node)),
    h('section', {}, h('h3', { text: 'Composition' }), h('p', { class: 'hint', text: 'Choisissez les produits et leurs quantités. Pour un plat à déclinaisons, choisissez la déclinaison précise.' }), filter, h('div', { class: 'composition-list' }, quantities.map((entry) => entry.row)), create, createFields),
    h('section', {}, h('h3', { text: 'Période de validité (heure de Paris)' }), h('div', { class: 'grid-2' }, field('Début', starts), field('Fin', ends)), noEnd));
  syncEnd();
  const saveButton = h('button', { class: 'button primary', type: 'button' }, 'Enregistrer l’offre');
  saveButton.onclick = async () => {
    const form = [['name', name.value], ['description', description.value], ['price', price.value.trim()], ['available', available.value], ['starts_at', starts.value], ['no_end_date', noEnd.querySelector('input').checked], ...(await photo.entries())];
    if (!noEnd.querySelector('input').checked) form.push(['ends_at', ends.value]);
    for (const entry of quantities) if (Number(entry.input.value) > 0) form.push(['composition[' + entry.product.id + ']', entry.input.value]);
    if (create.querySelector('input').checked) form.push(['create_offer_product', true], ['offer_product[name]', productName.value], ['offer_product[description]', productDescription.value], ['offer_product[price]', productPrice.value.trim()], ['offer_product[quantity]', productQuantity.value]);
    saveButton.disabled = true;
    try { await api('POST', '/card/offer' + (id ? '/' + id : ''), { form }); toast('Offre enregistrée.'); closeDrawer(); reload(); }
    catch (error) { showErrors(errors, error); }
    finally { saveButton.disabled = false; }
  };
  drawer(id ? 'Modifier ' + item.name : 'Nouvelle offre', body, { wide: true, footer: [
    id ? h('button', { class: 'link danger push-left', type: 'button', onclick: (event) => archive('offer', id, item.name, event.target, reload) }, 'Archiver cette offre') : null,
    h('button', { class: 'button', type: 'button', onclick: closeDrawer }, 'Annuler'), saveButton] });
}

async function archive(kind, id, name, button, reload) {
  if (!await confirmBox('Archiver « ' + name + ' » ? Il ne sera plus proposé à la vente. Les anciennes commandes sont conservées.', 'Archiver', true)) return;
  if (await busy(button, () => api('DELETE', '/card/' + kind + '/' + id), 'Article archivé.')) { closeDrawer(); reload(); }
}

function editCategories(data, reload) {
  const body = h('div', { class: 'editor' }, h('p', { class: 'hint', text: 'Les déclinaisons d’un plat partagent sa catégorie. L’ordre détermine l’affichage de la carte.' }));
  const types = [{ value: '', label: 'À classer' }, ...Object.entries(data.labels).map(([value, label]) => ({ value, label }))];
  function row(category) {
    const name = h('input', { value: category?.name || '', maxlength: '255', placeholder: 'Entrées, plats, desserts…' });
    const code = select(types, category?.code || '');
    const position = h('input', { type: 'number', min: '0', max: '999', value: String(category?.position ?? data.categories.length) });
    const save = h('button', { class: 'button small' + (category ? '' : ' primary'), type: 'button', onclick: async () => {
      if (await busy(save, () => api('POST', '/categories', { json: { id: category?.id || null, name: name.value.trim(), code: code.value || null, position: Number(position.value) } }), category ? 'Catégorie enregistrée.' : 'Catégorie ajoutée.')) { reload(); if (!category) { closeDrawer(); } }
    } }, category ? 'Enregistrer' : 'Ajouter');
    const remove = category ? h('button', { class: 'link danger', type: 'button', onclick: async (event) => {
      if (!await confirmBox('Supprimer « ' + category.name + ' » ? Ses produits et leurs déclinaisons seront conservés dans « Sans catégorie » et désactivés. Reclassez-les avant de les remettre en vente.', 'Supprimer', true)) return;
      if (await busy(event.target, () => api('DELETE', '/categories/' + category.id), 'Catégorie supprimée.')) { reload(); closeDrawer(); }
    } }, 'Supprimer') : null;
    return h('div', { class: 'category-row' }, field(category ? 'Nom' : 'Nouvelle catégorie', name), field('Type de produits', code), field('Ordre', position), h('div', { class: 'row' }, save, remove));
  }
  body.append(...data.categories.map(row), h('hr'), row(null));
  drawer('Catégories', body, { wide: true });
}

registerView('card', {
  title: 'Carte',
  render(main) {
    const content = h('div', {}, loading());
    let data = null;
    const reload = () => load();
    main.append(h('header', { class: 'view-head' }, h('div', {}, h('h1', { text: 'La carte' }), h('p', { class: 'muted', text: 'Plats, déclinaisons, photos, catégories et offres.' })),
      h('div', { class: 'toolbar' }, h('button', { class: 'button', type: 'button', onclick: () => data && editCategories(data, reload) }, 'Catégories'),
        h('button', { class: 'button', type: 'button', onclick: () => editOffer(null, reload) }, icon('plus'), 'Créer une offre'),
        h('button', { class: 'button primary', type: 'button', onclick: () => editProduct(null, reload) }, icon('plus'), 'Ajouter un plat'))), content);
    function productRow(product) {
      return h('button', { type: 'button', class: 'list-row', onclick: () => editProduct(product.id, reload) }, thumb(product.image, product.name, 'small'),
        h('div', { class: 'list-main' }, h('strong', { text: product.name }),
          product.has_variants ? h('span', { class: 'muted small', text: product.variants.map((variant) => variant.label + ' ' + money(variant.price) + (variant.available ? '' : ' (indisponible)')).join(' · ') }) : (product.description ? h('span', { class: 'muted small clamp', text: product.description }) : null)),
        pill(product.available ? (product.has_variants ? product.variants.length + ' déclinaisons' : 'En vente') : 'Indisponible', product.available ? 'ok' : 'muted'),
        h('strong', { class: 'num', text: (product.has_variants ? 'dès ' : '') + money(product.price) }));
    }
    async function load() {
      try {
        data = await api('GET', '/card');
        const sale = data.products.filter((product) => !product.offer_only);
        const groups = [...data.categories, { id: null, name: 'Sans catégorie' }].map((category) => ({ category, products: sale.filter((product) => (product.category_id ?? null) === (category.id ?? null)) })).filter((group) => group.products.length);
        const offerOnly = data.products.filter((product) => product.offer_only);
        fill(content, 
          groups.length ? groups.map((group) => h('section', { class: 'panel list' }, h('div', { class: 'list-head' }, h('h2', { text: group.category.name }), h('span', { class: 'muted', text: group.products.length + ' plat(s)' })), group.products.map(productRow)))
            : empty('Ajoutez votre premier plat', 'Vous pourrez ensuite lui ajouter des déclinaisons et une photo.'),
          h('section', { class: 'panel list' }, h('div', { class: 'list-head' }, h('h2', { text: 'Les offres' }), h('span', { class: 'muted', text: data.offers.length + ' offre(s)' })),
            data.offers.length ? data.offers.map((offer) => h('button', { type: 'button', class: 'list-row', onclick: () => editOffer(offer.id, reload) }, thumb(offer.image, offer.name, 'small'),
              h('div', { class: 'list-main' }, h('strong', { text: offer.name }), h('span', { class: 'muted small', text: offer.ends_at ? 'Jusqu’au ' + offer.ends_at : 'Sans date de fin' })),
              pill(offer.available ? 'Activée' : 'Indisponible', offer.available ? 'ok' : 'muted'), h('strong', { class: 'num', text: money(offer.price) }))) : h('p', { class: 'muted pad', text: 'Créez une offre à partir de vos plats ou d’un produit réservé à cette occasion.' })),
          offerOnly.length ? h('section', { class: 'panel list' }, h('div', { class: 'list-head' }, h('h2', { text: 'Produits réservés aux offres' })), offerOnly.map(productRow)) : null,
          h('p', { class: 'muted pad', text: 'Les formules de menu (catégories et prix fixe) se gèrent dans l’écran « Formules ».' }));
      } catch (error) { fill(content, failure(error, load)); }
    }
    load();
  }
});
