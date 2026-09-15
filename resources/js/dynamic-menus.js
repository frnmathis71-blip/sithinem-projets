const money = cents => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(cents / 100);

const selection = document.querySelector('[data-formula-selection]');
if (selection) {
    const options = JSON.parse(selection.querySelector('[data-formula-options]').textContent);
    const choices = [...selection.querySelectorAll('[data-formula-choice]')];
    const screens = [...selection.querySelectorAll('[data-formula-step]')];
    const surface = selection.querySelector('[data-story-surface]');
    const previous = selection.querySelector('[data-story-previous]');
    const next = selection.querySelector('[data-story-next]');
    const submit = selection.querySelector('[data-formula-submit]');
    const status = selection.querySelector('[data-formula-status]');
    const pair = selection.dataset.pair === '1';
    const selected = code => choices.find(choice => choice.dataset.formulaChoice === code && choice.checked && !choice.disabled);
    let index = 0;
    const update = () => {
        if (screens.some(screen => screen.dataset.formulaStep === 'main')) {
            const main = options.find(p => p.id === Number(selected('main')?.value));
            choices.filter(choice => choice.dataset.formulaChoice === 'side').forEach(choice => {
                choice.disabled = !main?.side_ids.includes(Number(choice.value));
                choice.closest('[data-product-card]').hidden = true;
                if (choice.disabled) choice.checked = false;
            });
        }
        screens.forEach((screen, step) => {
            screen.hidden = step !== index;
            screen.querySelectorAll('input[type="radio"]').forEach(input => { input.required = step === index && !input.disabled; });
            screen.querySelector('[data-story-empty]').hidden = [...screen.querySelectorAll('input[type="radio"]')].some(input => !input.disabled);
        });
        selection.querySelectorAll('[data-product-group]').forEach(group => {
            const inputs = [...group.querySelectorAll('input')];
            const chosen = inputs.find(input => input.checked && !input.disabled);
            const button = group.querySelector('[data-choose-group]');
            button.disabled = !inputs.some(input => !input.disabled);
            group.hidden = button.disabled && group.closest('[data-formula-step]').dataset.formulaStep === 'side';
            button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
            group.querySelector('[data-group-choice]').textContent = chosen ? options.find(p => p.id === Number(chosen.value)).variant_name ?? 'Sélectionné' : button.disabled ? 'Accompagnement indisponible' : '';
        });
        const complete = screens.every(screen => selected(screen.dataset.formulaStep));
        const current = selected(screens[index].dataset.formulaStep);
        const last = index === screens.length - 1;
        previous.disabled = index === 0;
        next.hidden = last;
        next.disabled = !current;
        submit.hidden = !last;
        submit.disabled = !complete;
        selection.querySelector('[data-story-progress]').textContent = 'Étape ' + (index + 1) + ' / ' + screens.length;
        selection.querySelector('[data-story-bar]').value = index + 1;
        status.textContent = last ? (complete ? 'Votre menu est prêt à être ajouté au panier.' : 'Complétez tous les choix pour ajouter au panier.') : current ? 'Votre choix est enregistré. Passez à la suite.' : 'Sélectionnez un produit pour continuer.';
        const names = screens.map(screen => options.find(p => p.id === Number(selected(screen.dataset.formulaStep)?.value))?.name).filter(Boolean);
        selection.querySelector('[data-formula-summary]').textContent = names.length ? names.join(' · ') : 'Aucun produit sélectionné.';
        const side = selected('side');
        const price = Number(selection.dataset.basePrice) + (pair ? options.find(p => p.id === Number(side?.value))?.price ?? 0 : 0);
        selection.querySelector('[data-formula-total]').textContent = money(price) + (pair && !side ? ' + accompagnement' : '');
    };
    const move = direction => {
        if (direction > 0 && !selected(screens[index].dataset.formulaStep)) {
            status.textContent = 'Sélectionnez un produit avant de passer à la suite.';
            return;
        }
        const destination = Math.max(0, Math.min(screens.length - 1, index + direction));
        if (destination === index) return;
        index = destination;
        update();
        const title = screens[index].querySelector('legend');
        title.focus({preventScroll: true});
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            screens[index].animate([{opacity: 0, transform: 'translateX(' + (direction * 24) + 'px)'}, {opacity: 1, transform: 'translateX(0)'}], {duration: 200, easing: 'ease-out'});
        }
        if (title.getBoundingClientRect().top < 0) title.scrollIntoView({block: 'start', behavior: 'instant'});
    };
    selection.querySelectorAll('[data-choose-group]').forEach(button => button.addEventListener('click', () => {
        const enabled = [...button.closest('[data-product-group]').querySelectorAll('input')].filter(input => !input.disabled);
        chooseVariant(options.filter(p => enabled.some(input => Number(input.value) === p.id)), product => {
            enabled.find(input => Number(input.value) === product.id).checked = true;
            update();
        }, pair, button);
    }));
    previous.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    selection.addEventListener('change', update);
    selection.addEventListener('submit', event => {
        update();
        if (index !== screens.length - 1 || submit.disabled) event.preventDefault();
    });
    // Horizontal gestures never replace vertical scrolling or a simple tap on a card.
    let gesture = null, suppressClickUntil = 0;
    surface.addEventListener('pointerdown', event => {
        if (!event.isPrimary || event.button !== 0) return;
        gesture = {id: event.pointerId, x: event.clientX, y: event.clientY};
    });
    surface.addEventListener('pointerup', event => {
        if (!gesture || gesture.id !== event.pointerId) return;
        const dx = event.clientX - gesture.x, dy = event.clientY - gesture.y;
        gesture = null;
        if (Math.abs(dx) >= 65 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            suppressClickUntil = Date.now() + 350;
            event.preventDefault();
            move(dx < 0 ? 1 : -1);
        }
    });
    surface.addEventListener('pointercancel', () => { gesture = null; });
    surface.addEventListener('click', event => {
        if (Date.now() < suppressClickUntil) { event.preventDefault(); event.stopPropagation(); }
    }, true);
    update();
}

const admin = document.querySelector('[data-formula-admin]');
if (admin) {
    const categories = [...admin.querySelectorAll('[name="categories[]"]')];
    const main = categories.find(input => input.value === 'main');
    const chef = categories.find(input => input.value === 'chef_main');
    const update = changed => {
        if (changed === main && main.checked) chef.checked = false;
        if (changed === chef && chef.checked) main.checked = false;
        admin.querySelector('[data-formula-side-note]').textContent = main.checked ? 'Accompagnement ajouté automatiquement après le plat classique.' : chef.checked ? 'Garniture du chef incluse : aucun choix d’accompagnement séparé.' : 'Cette formule ne demande aucun accompagnement séparé.';
        categories[0].setCustomValidity(categories.some(input => input.checked) ? '' : 'Choisissez au moins une catégorie.');
    };
    categories.forEach(input => input.addEventListener('change', () => update(input)));
    update();
}


function chooseVariant(products, onChoose, showPrice, trigger) {
    if (!products.length) return;
    if (products.length === 1) { onChoose(products[0]); return; }
    const dialog = document.querySelector('[data-variant-dialog]');
    const grid = dialog.querySelector('[data-variant-grid]');
    dialog.querySelector('h2').textContent = products[0].group_name + ' — votre déclinaison';
    grid.replaceChildren(...products.map(product => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'variant-card';
        const image = document.createElement('img');
        image.src = product.image_url; image.alt = product.has_photo ? product.name : 'Illustration — photo à venir';
        image.draggable = false;
        const label = document.createElement('strong'); label.textContent = product.variant_name || product.name;
        button.append(image, label);
        if (showPrice) { const price = document.createElement('span'); price.textContent = money(product.price); button.append(price); }
        button.addEventListener('click', () => { dialog.close(); onChoose(product); });
        return button;
    }));
    dialog.onclose = () => trigger?.focus();
    dialog.querySelector('[data-variant-close]').onclick = () => dialog.close();
    dialog.showModal();
}

const catalogData = document.querySelector('[data-catalog-options]');
if (catalogData) {
    const products = JSON.parse(catalogData.textContent);
    document.querySelectorAll('form[data-product-add]').forEach(form => {
        const genericId = Number(form.elements.id.value);
        form.addEventListener('submit', event => {
            if (form.dataset.chosen) return;
            const variants = products.filter(p => p.group_id === genericId);
            if (!variants.length) return;
            event.preventDefault();
            chooseVariant(variants, product => {
                form.elements.id.value = product.id;
                form.dataset.chosen = '1';
                form.requestSubmit();
            }, true, form.querySelector('button'));
        });
        form.closest('[data-catalog-card]').addEventListener('click', event => {
            if (event.target.closest('button, a, input, form')) return;
            if (!form.querySelector('button').disabled) form.requestSubmit();
        });
    });
}
