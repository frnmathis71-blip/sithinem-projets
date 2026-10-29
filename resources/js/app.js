import './dynamic-menus';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

const slotGrid = document.querySelector('[data-slots-url]');
if (slotGrid) {
    let refreshing = false;
    const refresh = async () => {
        if (document.hidden || refreshing) return;
        refreshing = true;
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
                label.querySelector('[data-slot-label]').textContent = slot ? (slot.reason ?? 'Disponible') : 'Indisponible';
            }
            document.querySelector('[data-slot-update]').textContent = selectionLost ? 'Ce créneau vient de devenir indisponible. Choisissez un autre horaire.' : 'Disponibilité mise à jour. Vérification finale à la validation.';
        } catch {
            document.querySelector('[data-slot-update]').textContent = 'Actualisation indisponible. La disponibilité sera vérifiée à la validation.';
        } finally {
            refreshing = false;
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
    const board = feed.querySelector('[data-board-orders]');
    const seenOrders = new Set([...feed.querySelectorAll('[data-order]')].map(card => card.dataset.order));
    const soundButton = document.querySelector('[data-order-sound]');
    const soundStatus = document.querySelector('[data-order-sound-status]');
    let audioContext;
    let soundEnabled = false;
    let refreshing = false;
    const chime = async () => {
        await audioContext.resume();
        if (audioContext.state !== 'running') throw new Error('Audio unavailable');
        [660, 880, 1100].forEach((frequency, index) => {
            const start = audioContext.currentTime + index * 0.18;
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(0.18, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, start + 0.3);
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            oscillator.start(start);
            oscillator.stop(start + 0.32);
            oscillator.onended = () => { oscillator.disconnect(); gain.disconnect(); };
        });
    };
    const disableSound = (message) => {
        soundEnabled = false;
        soundButton.setAttribute('aria-pressed', 'false');
        soundButton.textContent = 'Activer le son';
        soundStatus.textContent = message;
    };
    soundButton?.addEventListener('click', async () => {
        if (soundEnabled) { disableSound('Alertes sonores désactivées.'); return; }
        soundButton.disabled = true;
        try {
            audioContext ??= new AudioContext();
            await chime();
            soundEnabled = true;
            soundButton.setAttribute('aria-pressed', 'true');
            soundButton.textContent = 'Désactiver le son';
            soundStatus.textContent = 'Son activé : une alerte retentira à chaque arrivée de nouvelles commandes.';
        } catch { disableSound('Son indisponible. Vérifiez les autorisations audio du navigateur puis réessayez.'); }
        finally { soundButton.disabled = false; }
    });
    const refresh = async () => {
        if (refreshing) return;
        refreshing = true;
        try {
            const response = await fetch(feed.dataset.feedUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(15000) });
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (board && board.dataset.signature !== data.signature) {
                const template = document.createElement('template');
                template.innerHTML = data.html;
                const ids = [...template.content.querySelectorAll('[data-order]')].map(card => card.dataset.order);
                const hasNewOrders = ids.some(id => !seenOrders.has(id));
                ids.forEach(id => seenOrders.add(id));
                const focusedId = board.contains(document.activeElement) ? document.activeElement.closest('[data-order]')?.dataset.order : null;
                board.replaceChildren(template.content);
                board.dataset.signature = data.signature;
                if (focusedId) {
                    const replacement = [...board.querySelectorAll('[data-order]')].find(card => card.dataset.order === focusedId);
                    replacement?.focus({ preventScroll: true });
                }
                if (hasNewOrders && soundEnabled) {
                    chime().catch(() => disableSound('Cliquez sur « Activer le son » pour réactiver les alertes.'));
                }
            }
            feed.querySelector('[data-feed-message]').textContent = `${data.preparing} commande${data.preparing > 1 ? 's' : ''} en préparation. Actualisation automatique toutes les 3 secondes.`;
            const dateLabel = document.querySelector('[data-board-date]');
            if (dateLabel) dateLabel.textContent = data.date_label;
        } catch { feed.querySelector('[data-feed-message]').textContent = 'Connexion interrompue. Nouvelle tentative automatique…'; }
        finally { refreshing = false; }
    };
    refresh();
    setInterval(refresh, 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
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

// The home intro fills the rest of the window, whatever the height of the banner and header above it.
const homeIntro = document.querySelector('[data-home-intro]');
if (homeIntro) {
    const fit = () => homeIntro.style.setProperty('--intro-top', `${Math.round(homeIntro.getBoundingClientRect().top + window.scrollY)}px`);
    fit();
    window.addEventListener('resize', fit);
}

// Home carousel: turns every 5 seconds, pauses on hover, focus, hidden tab or on request.
const carousel = document.querySelector('[data-hero-carousel]');
if (carousel) {
    const slides = [...carousel.querySelectorAll('.hero-slide')];
    const dots = [...carousel.querySelectorAll('[data-carousel-dot]')];
    const pauseButton = carousel.querySelector('[data-carousel-pause]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let active = 0;
    let timer = null;
    let paused = reducedMotion;
    let held = false;

    const show = (index, direction = 1) => {
        if (index === active) return;
        const current = slides[active];
        const next = slides[index];
        // Place the incoming slide on the side it comes from, without animating that jump.
        next.classList.add('no-transition');
        next.classList.remove('from-left', 'from-right');
        next.classList.add(direction > 0 ? 'from-right' : 'from-left');
        void next.offsetWidth;
        next.classList.remove('no-transition', 'from-left', 'from-right');
        next.classList.add('is-active');
        next.inert = false;
        current.classList.remove('is-active', 'from-left', 'from-right');
        current.classList.add(direction > 0 ? 'from-left' : 'from-right');
        current.inert = true;
        dots.forEach((dot, i) => (i === index ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current')));
        active = index;
    };
    const schedule = () => {
        clearInterval(timer);
        timer = paused || held || document.hidden ? null : setInterval(() => show((active + 1) % slides.length), 5000);
    };
    const setPaused = (value) => {
        paused = value;
        carousel.toggleAttribute('data-paused', paused);
        pauseButton.setAttribute('aria-label', paused ? 'Reprendre le défilement' : 'Mettre en pause le défilement');
        schedule();
    };

    dots.forEach((dot, i) => dot.addEventListener('click', () => {
        show(i, i > active ? 1 : -1);
        schedule();
    }));
    pauseButton.addEventListener('click', () => setPaused(!paused));
    // Hovering or focusing a slide holds it, so a link never moves away under the pointer.
    carousel.addEventListener('pointerenter', (event) => { if (event.pointerType === 'mouse') { held = true; schedule(); } });
    carousel.addEventListener('pointerleave', () => { held = false; schedule(); });
    carousel.addEventListener('focusin', () => { held = true; schedule(); });
    carousel.addEventListener('focusout', (event) => { if (!carousel.contains(event.relatedTarget)) { held = false; schedule(); } });
    document.addEventListener('visibilitychange', schedule);

    let touchX = null;
    carousel.addEventListener('touchstart', (event) => { touchX = event.touches[0].clientX; }, { passive: true });
    carousel.addEventListener('touchend', (event) => {
        const distance = touchX === null ? 0 : event.changedTouches[0].clientX - touchX;
        touchX = null;
        if (Math.abs(distance) < 40) return;
        show((active + (distance < 0 ? 1 : -1) + slides.length) % slides.length, distance < 0 ? 1 : -1);
        schedule();
    }, { passive: true });

    setPaused(paused);
}
