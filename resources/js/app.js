import './dynamic-menus';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

const slotGrid = document.querySelector('[data-slots-url]');
if (slotGrid) {
    const refresh = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(slotGrid.dataset.slotsUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error();
            const { slots, dates } = await response.json();
            const dateSelect = document.querySelector('#pickup-date');
            if (dateSelect && dates) {
                const selected = dateSelect.value;
                dateSelect.replaceChildren(...dates.map(day => new Option(day.label, day.date, false, day.date === selected)));
                if (!dates.some(day => day.date === selected)) dateSelect.value = '';
                dateSelect.disabled = dates.length === 0;
            }
            let selectionLost = false;
            for (const label of slotGrid.querySelectorAll('[data-slot]')) {
                const slot = slots.find(slot => String(slot.id) === label.dataset.slot);
                const input = label.querySelector('input');
                const selectable = slot?.selectable ?? false;
                if (input.checked && !selectable) { input.checked = false; selectionLost = true; }
                input.disabled = !selectable;
                label.classList.toggle('unavailable', !selectable);
                label.querySelector('[data-slot-label]').textContent = slot ? (slot.reason ?? (slot.remaining <= 3 ? `${slot.remaining} place${slot.remaining > 1 ? 's' : ''} restante${slot.remaining > 1 ? 's' : ''}` : 'Disponible')) : 'Indisponible';
            }
            document.querySelector('[data-slot-update]').textContent = selectionLost ? 'Ce créneau vient de devenir indisponible. Choisissez un autre horaire.' : 'Disponibilité mise à jour. Vérification finale à la validation.';
        } catch {
            document.querySelector('[data-slot-update]').textContent = 'Actualisation indisponible. La disponibilité sera vérifiée à la validation.';
        }
    };
    setInterval(refresh, 20000);
}

document.querySelector('[data-checkout]')?.addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button[type="submit"], button.button');
    if (button) { button.disabled = true; button.textContent = 'Enregistrement en cours…'; }
});

const feed = document.querySelector('[data-feed-url]');
if (feed) {
    const refresh = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(feed.dataset.feedUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error();
            const data = await response.json();
            feed.querySelector('[data-feed-message]').textContent = `${data.preparing} commande${data.preparing > 1 ? 's' : ''} en préparation. `;
            const board = feed.querySelector('[data-board-orders]');
            if (board && board.dataset.signature !== data.signature && !board.contains(document.activeElement)) {
                board.innerHTML = data.html;
                board.dataset.signature = data.signature;
            }
            const dateLabel = document.querySelector('[data-board-date]');
            if (dateLabel) dateLabel.textContent = data.date_label;
        } catch { feed.querySelector('[data-feed-message]').textContent = 'Actualisation interrompue. Rechargez la page pour consulter les commandes. '; }
    };
    refresh();
    setInterval(refresh, 10000);
}

document.querySelector('[data-enable-push]')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const status = document.querySelector('[data-push-status]');
    button.disabled = true;
    try {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext) throw new Error('Utilisez un navigateur compatible et une connexion HTTPS.');
        if (await Notification.requestPermission() !== 'granted') throw new Error('Les notifications ne sont pas autorisées. Vous pouvez modifier ce choix dans les réglages du navigateur.');
        await navigator.serviceWorker.register('/sw.js');
        const registration = await navigator.serviceWorker.ready;
        const key = button.dataset.key.replace(/-/g, '+').replace(/_/g, '/');
        const applicationServerKey = Uint8Array.from(atob(key + '='.repeat((4 - key.length % 4) % 4)), char => char.charCodeAt(0));
        const subscription = await registration.pushManager.getSubscription() ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey });
        const response = await fetch(button.dataset.url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }, body: JSON.stringify(subscription.toJSON()) });
        if (!response.ok) throw new Error('Impossible d’enregistrer cet appareil. Réessayez.');
        status.textContent = 'Notifications activées sur cet appareil.';
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
});

const variantsToggle = document.querySelector('[data-variants-toggle]');
const syncVariants = () => {
    if (!variantsToggle) return;
    const panel = document.querySelector('[data-variants-panel]');
    panel.hidden = !variantsToggle.checked;
    panel.querySelectorAll('input, select').forEach(input => {
        input.disabled = !variantsToggle.checked;
        input.required = variantsToggle.checked && input.hasAttribute('data-variant-required');
    });
    const price = document.querySelector('[data-base-price]');
    if (price) { price.disabled = variantsToggle.checked; price.required = !variantsToggle.checked; }
};
variantsToggle?.addEventListener('change', syncVariants);
document.querySelector('[data-add-variant]')?.addEventListener('click', () => {
    const rows = document.querySelector('[data-variant-rows]');
    const indices = [...rows.querySelectorAll('input[name]')].map(input => Number(input.name.match(/\[(\d+)\]/)?.[1] ?? -1));
    const index = Math.max(-1, ...indices) + 1;
    rows.insertAdjacentHTML('beforeend', document.querySelector('[data-variant-template]').innerHTML.replaceAll('__INDEX__', String(index)));
    syncVariants();
});
document.querySelector('[data-variant-rows]')?.addEventListener('click', event => {
    event.target.closest('[data-remove-variant]')?.closest('[data-variant-row]').remove();
});
syncVariants();
const offerProductToggle = document.querySelector('[data-offer-product-toggle]');
const syncOfferProduct = () => {
    if (!offerProductToggle) return;
    const fields = document.querySelector('[data-offer-product-fields]');
    fields.hidden = !offerProductToggle.checked;
    fields.querySelectorAll('input, textarea').forEach(input => { input.disabled = !offerProductToggle.checked; });
};
offerProductToggle?.addEventListener('change', syncOfferProduct);
syncOfferProduct();
const noEndDate = document.querySelector('[data-no-end-date]');
const syncEndDate = () => {
    if (!noEndDate) return;
    const end = document.querySelector('[data-offer-end]');
    end.disabled = noEndDate.checked;
    end.required = !noEndDate.checked;
};
noEndDate?.addEventListener('change', syncEndDate);
syncEndDate();
