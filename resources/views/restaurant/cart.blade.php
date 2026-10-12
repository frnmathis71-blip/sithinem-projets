@php($staffOrder = request()->routeIs('admin.orders.create'))
<x-restaurant-layout :title="$staffOrder ? 'Commande téléphone / comptoir' : 'Mon panier'" :admin="$staffOrder">
@if($staffOrder)
<div class="panel"><h2>Commande téléphone / comptoir</h2><p>Ajoutez les plats et les menus depuis la carte, puis revenez ici pour enregistrer la commande au nom du client. Aucun compte client n’est nécessaire. Les horaires et capacités habituels s’appliquent.</p><a href="{{ route('menu') }}" class="button">Ajouter des articles</a></div>
@elseif(auth()->user()?->role === 'admin')
<div class="notice"><a class="button" href="{{ route('admin.orders.create', request()->only('date')) }}">Enregistrer ce panier pour un client · Téléphone / Comptoir</a></div>
@endif
<div class="section-heading"><div><p class="eyebrow">ENCORE UN PEU AVANT DE SE RÉGALER</p><h1>Votre panier</h1></div><a href="{{ route('menu') }}">← Continuer mes achats</a></div>
@if(!$lines)
    <div class="empty-state"><span>♡</span><h2>Une petite faim ?</h2><p>Votre panier attend ses premières saveurs.</p><a class="button" href="{{ route('menu') }}">Découvrir la carte</a></div>
@else
<div class="checkout-grid"><section class="panel"><h2>Votre sélection</h2>
@foreach($lines as $line)
    <div class="cart-row"><div><h3>{{ $line['name'] }}</h3><p>{{ number_format($line['price'] / 100, 2, ',', ' ') }} € / unité</p>
    @foreach($line['composition'] as $part)<small>{{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}</small>@endforeach
    @if(!$line['available'])<span class="badge danger">Indisponible — à retirer et recomposer</span>@endif</div>
    <form method="post" action="{{ route('cart.update') }}" class="inline-form">@csrf <x-spam-trap /><input type="hidden" name="key" value="{{ $line['key'] }}"><label><span class="sr-only">Quantité de {{ $line['name'] }}</span><input class="quantity" type="number" name="quantity" min="0" max="50" value="{{ $line['quantity'] }}"></label><button class="button small secondary">Mettre à jour</button><button class="text-button" name="quantity" value="0" aria-label="Retirer {{ $line['name'] }}">Retirer</button></form><strong>{{ number_format($line['price'] * $line['quantity'] / 100, 2, ',', ' ') }} €</strong></div>
@endforeach
<div class="total-row"><span>Total</span><strong>{{ number_format($total / 100, 2, ',', ' ') }} €</strong></div><p class="payment-note">Paiement sur place lors du retrait.</p></section>
<section class="panel"><p class="eyebrow">VOTRE RENDEZ-VOUS</p><h2>Choisir mon retrait</h2><p class="muted">Créneaux de 20 minutes · 30 minutes de préparation minimum.</p>
<form method="get" action="{{ route($staffOrder ? 'admin.orders.create' : 'cart') }}" class="date-form"><label for="pickup-date">Date de retrait</label><div class="inline-form"><select id="pickup-date" name="date" required @disabled(!$bookingDates)>@forelse($bookingDates as $day)<option value="{{ $day['date'] }}" @selected($date === $day['date'])>{{ $day['label'] }}</option>@empty<option>Aucune date ouverte</option>@endforelse</select><button class="button secondary small">Afficher</button></div></form>
<form method="post" action="{{ route($staffOrder ? 'admin.orders.store' : 'orders.store') }}" data-checkout>@csrf <x-spam-trap /><input type="hidden" name="idempotency_key" value="{{ session('checkout_key') }}">
@if($staffOrder)
<input type="hidden" name="expected_total" value="{{ $total }}">
<label for="source">Origine de la commande</label><select id="source" name="source"><option value="phone" @selected(old('source') === 'phone')>Téléphone</option><option value="counter" @selected(old('source') === 'counter')>Comptoir</option></select>
<label for="customer-name">Nom du client</label><input id="customer-name" name="customer_name" maxlength="255" required value="{{ old('customer_name') }}" autocomplete="off">
@endif
<fieldset class="slot-grid" data-slots-url="{{ route('slots', ['date' => $date]) }}"><legend class="sr-only">Heure de retrait</legend>
@forelse($slots as $slot)<label @class(['slot-option', 'unavailable' => !$slot['selectable']]) data-slot="{{ $slot['id'] }}"><input type="radio" name="slot_id" value="{{ $slot['id'] }}" @disabled(!$slot['selectable']) @checked((string) old('slot_id') === (string) $slot['id'] && $slot['selectable']) required><strong>{{ $slot['start'] }}</strong><span data-slot-label>{{ $slot['reason'] ?? 'Disponible' }}</span></label>@empty<p class="notice">Le restaurant est fermé à cette date. Choisissez un autre jour.</p>@endforelse
</fieldset><p class="muted" data-slot-update aria-live="polite">La disponibilité est vérifiée à la validation.</p>
@auth
<label for="customer-message">Message pour le restaurant (facultatif)</label>
<textarea id="customer-message" name="customer_message" rows="4" maxlength="1000" aria-describedby="customer-message-help" placeholder="Ex. : sans oignons dans le plat de poulet. Précisez le plat concerné et toute allergie.">{{ old('customer_message') }}</textarea>
<p id="customer-message-help" class="muted">Allergies, ingrédient à retirer ou autre précision · 1 000 caractères maximum.</p>
@endauth
@auth
@if($staffOrder || !config('services.twilio.enabled'))
<label for="phone">Téléphone pour le retrait</label><input id="phone" type="tel" name="phone" autocomplete="tel" maxlength="30" required value="{{ old('phone', $staffOrder ? '' : auth()->user()->phone) }}" placeholder="06 12 34 56 78">
@elseif(auth()->user()->phone_verified_at)
<p>Téléphone vérifié : {{ auth()->user()->phone }} · <a href="{{ route('phone.show') }}">Modifier</a></p>
<input type="hidden" name="phone" value="{{ auth()->user()->phone }}">

@else
<p>Vérifiez votre téléphone par SMS pour confirmer votre première commande. Votre panier sera conservé.</p><a class="button full" href="{{ route('phone.show') }}">Vérifier mon téléphone</a>
@endif
@if($staffOrder || !config('services.twilio.enabled') || auth()->user()->phone_verified_at)
<p class="payment-note">Paiement sur place lors du retrait.</p><button class="button full" @disabled(!collect($slots)->contains('selectable', true) || collect($lines)->contains('available', false))>Confirmer ma commande · {{ number_format($total / 100, 2, ',', ' ') }} €</button>
@endif
@else<p>Connectez-vous pour confirmer votre commande. Votre panier sera conservé.</p><a href="{{ route('login') }}" class="button full">Me connecter</a><a href="{{ route('register') }}" class="text-button">Créer un compte</a>@endauth
</form></section></div>
@endif
</x-restaurant-layout>
