@php($admin = request()->routeIs('admin.*'))
@php($paymentNote = $order->payment_method === 'online' ? ($order->paid_at ? 'Payée en ligne.' : ($order->status === 'awaiting_payment' ? 'Paiement en ligne en attente.' : 'Paiement en ligne non abouti.')) : ($order->paid_at ? 'Paiement sur place enregistré.' : 'Paiement sur place lors du retrait.'))
@php($money = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' €')
@php($editable = $admin && $order->isActive())
<x-restaurant-layout :title="'Commande #'.$order->id" :admin="$admin">
@if($admin)
<a class="back-link" href="{{ route('admin.orders', ['date' => $order->slot->date->format('Y-m-d')]) }}">← Commandes du {{ $order->slot->date->format('d/m/Y') }}</a>
<div class="admin-page-head">
    <div><p class="eyebrow">{{ $order->number }}</p><h1>Commande #{{ $order->id }}</h1>
        <p class="order-pills"><span @class(['badge', 'status-'.$order->status])>{{ \App\Models\Order::STATUSES[$order->status] }}</span><span @class(['badge', 'paid' => $order->paid_at])>{{ $order->paid_at ? ($order->payment_method === 'online' ? 'Payée en ligne' : 'Encaissée') : ($order->status === 'awaiting_payment' ? 'Paiement en ligne en cours' : 'À encaisser') }}</span><span class="badge">{{ ['phone' => 'Téléphone', 'counter' => 'Comptoir'][$order->source] ?? 'Site internet' }}</span></p></div>
</div>
@include('restaurant.customer-message')
@if($order->paid_amount !== null && $order->paid_amount !== $order->total)
<div class="notice error" role="status">Commande modifiée après paiement : le client a payé {{ $money($order->paid_amount) }} pour un total de {{ $money($order->total) }}. {{ $order->total > $order->paid_amount ? 'Reste à encaisser : '.$money($order->total - $order->paid_amount) : 'À rembourser : '.$money($order->paid_amount - $order->total) }}.</div>
@endif
<div class="order-detail-grid">
    <div class="order-main">
        <section class="panel"><h2>Articles</h2>
            @foreach($order->items as $item)
                <div class="cart-row"><div><h3>{{ $item->quantity }} × {{ $item->name }}</h3>@if($item->item_type !== 'product')<p>@foreach($item->composition as $part){{ $part['quantity'] }} × {{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif</div>
                    <strong>{{ $money($item->quantity * $item->unit_price) }}</strong>
                    @if($editable && $order->items->count() > 1)<form method="post" action="{{ route('admin.orders.items.remove', [$order, $item->id]) }}" data-confirm="Retirer {{ $item->quantity }} × {{ $item->name }} de la commande ? Le total passera à {{ $money($order->total - $item->quantity * $item->unit_price) }}.">@csrf @method('DELETE')<button class="text-button danger-link">Retirer</button></form>@endif
                </div>
            @endforeach
            <div class="total-row"><span>Total</span><strong>{{ $money($order->total) }}</strong></div><p class="payment-note">{{ $paymentNote }}</p>
            @if($editable)
                <form class="order-add-item" method="post" action="{{ route('admin.orders.items.add', $order) }}" data-confirm="Ajouter cet article à la commande ? Le total sera recalculé.">@csrf
                    <p class="eyebrow">AJOUTER UN ARTICLE</p>
                    <div class="inline-form"><label class="sr-only" for="add-item">Article</label><select id="add-item" name="item" required><option value="">Choisir un plat ou une offre…</option>@foreach($addChoices as $choice)<option value="{{ $choice['key'] }}" @selected(old('item') === $choice['key'])>{{ $choice['label'] }}</option>@endforeach</select>
                    <label class="sr-only" for="add-quantity">Quantité</label><input id="add-quantity" class="quantity" type="number" name="quantity" min="1" max="50" value="{{ old('quantity', 1) }}" required><button class="button small">Ajouter</button></div>
                </form>
            @endif
        </section>
        @if($editable)
            <section class="panel order-reschedule"><h2>Changer l’heure de retrait</h2>
                <p class="muted">Le client est prévenu par email{{ app(\App\Services\CustomerNotifier::class)->smsEnabled() ? ' et par SMS' : '' }} de la nouvelle heure.</p>
                <form method="get" action="{{ route('admin.orders.show', $order) }}" class="inline-form"><label class="sr-only" for="reschedule-day">Jour</label><select id="reschedule-day" name="jour">@foreach($rescheduleDates as $day)<option value="{{ $day['date'] }}" @selected($day['date'] === $rescheduleDate)>{{ $day['label'] }}</option>@endforeach</select><button class="button secondary small">Afficher</button></form>
                <form method="post" action="{{ route('admin.orders.reschedule', $order) }}" data-confirm="Déplacer le retrait de la commande #{{ $order->id }} ? Le client recevra la nouvelle heure.">@csrf @method('PATCH')
                    <fieldset class="slot-grid"><legend class="sr-only">Nouveau créneau</legend>
                        @forelse($rescheduleSlots as $slot)<label @class(['slot-option', 'current-slot' => $slot['id'] === $order->time_slot_id])><input type="radio" name="slot_id" value="{{ $slot['id'] }}" required @checked($slot['id'] === $order->time_slot_id)><strong>{{ $slot['start'] }}</strong><span>{{ $slot['id'] === $order->time_slot_id ? 'Heure actuelle' : 'Reste '.$money((int) $slot['remaining']) }}</span></label>@empty<p class="notice">Aucun créneau à venir ce jour-là.</p>@endforelse
                    </fieldset>
                    @if($rescheduleSlots)<button class="button full">Enregistrer la nouvelle heure</button>@endif
                </form>
            </section>
        @endif
    </div>
    <div class="order-side">
        <section class="panel order-actions"><p class="eyebrow">RETRAIT AU RESTAURANT</p><h2>{{ $order->slot->date->format('d/m/Y') }} à {{ substr($order->slot->starts_at, 0, 5) }}</h2>
            <p class="order-customer"><strong>{{ $order->customer_name }}</strong><a href="tel:{{ preg_replace('/[^+0-9]/', '', $order->phone) }}">{{ $order->phone }}</a></p>
            @include('restaurant.order-steps')
            @php($moves = array_values(array_diff(\App\Models\Order::TRANSITIONS[$order->status] ?? [], ['cancelled'])))
            @php($steps = array_keys(\App\Models\Order::TRACKING))
            @php($next = $steps[(int) array_search($order->status, $steps, true) + 1] ?? null)
            @if($moves)<div class="status-actions">@foreach($moves as $move)<form method="post" action="{{ route('admin.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $move }}"><button @class(['button', 'full', 'secondary' => $move !== $next])>{{ ['received' => $order->status === 'cancelled' ? 'Rétablir la commande' : 'Revenir à « Reçue »','preparing' => in_array($order->status, ['ready'], true) ? 'Revenir à « Acceptée »' : 'Accepter la commande', 'ready' => in_array($order->status, ['completed'], true) ? 'Revenir à « Prête »' : 'Marquer comme prête', 'completed' => 'Terminer · retirée ✓'][$move] }}</button></form>@endforeach</div>@endif
            @if(!$order->paid_at && in_array($order->status, [...\App\Models\Order::ACTIVE, 'completed'], true))<form method="post" action="{{ route('admin.orders.paid', $order) }}">@csrf<button class="button secondary full">Confirmer l’encaissement sur place · {{ $money($order->total) }}</button></form>@endif
            <a class="text-button" href="{{ route('admin.orders.ticket', $order) }}" target="_blank" rel="noopener">Imprimer ou voir le ticket</a>
            @if(in_array($order->status, [...\App\Models\Order::ACTIVE, 'awaiting_payment'], true))<details class="danger-zone"><summary>Annuler la commande</summary><p class="muted">Le montant du créneau sera libéré. La commande reste dans l’historique.</p><form method="post" action="{{ route('admin.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><button class="button secondary full danger">Confirmer l’annulation</button></form></details>@endif        </section>
        <section class="panel"><h3>Suivi de la commande</h3><ol class="timeline">@foreach($histories as $history)<li><strong>{{ \App\Models\Order::HISTORY_LABELS[$history->to_status] ?? $history->to_status }}</strong><span>{{ \Carbon\CarbonImmutable::parse($history->created_at, 'UTC')->timezone('Europe/Paris')->format('d/m à H:i') }}</span></li>@endforeach</ol></section>
    </div>
</div>
@else
@include('restaurant.customer-message')
<div class="section-heading"><div><p class="eyebrow">{{ $order->number }}</p><h1>Commande #{{ $order->id }}</h1></div><span @class(['badge', 'status-'.$order->status])>{{ \App\Models\Order::STATUSES[$order->status] }}</span></div>
@if($order->status === 'awaiting_payment' && !$order->paid_at)
<div class="notice payment-pending" role="status"><p>{{ request('paiement') === 'annule' ? 'Le paiement n’a pas été effectué.' : 'Votre commande attend son paiement en ligne.' }} Votre créneau reste réservé {{ \App\Services\StripeCheckoutService::PAYMENT_WINDOW }} minutes ; sans paiement, la commande sera annulée.</p><form method="post" action="{{ route('orders.pay', $order) }}">@csrf<button class="button">Payer maintenant · {{ $money($order->total) }}</button></form></div>
@elseif($order->payment_method === 'online' && $order->paid_at && request('paiement') === 'confirme')
<div class="notice success" role="status">Paiement reçu, merci ! Votre commande est transmise à la cuisine.</div>
@endif
{{-- The page checks the order every 20 seconds and reloads itself when the restaurant changes it. --}}
<section class="panel order-tracking" @if($order->isActive() || $order->status === 'awaiting_payment') data-order-tracking="{{ route('orders.tracking', $order) }}" data-signature="{{ md5($order->status.'|'.$order->time_slot_id.'|'.$order->total.'|'.$order->paid_at) }}" @endif>
    <p class="eyebrow">SUIVI EN DIRECT</p>
    @include('restaurant.order-steps')
    <p class="order-tracking-message" aria-live="polite">{{ \App\Models\Order::TRACKING[$order->status] ?? ($order->status === 'cancelled' ? 'Cette commande a été annulée. Contactez-nous pour toute question.' : 'Votre commande sera transmise au restaurant dès réception du paiement.') }}</p>
</section>
<div class="checkout-grid"><section class="panel"><h2>Votre sélection</h2>@foreach($order->items as $item)<div class="cart-row"><div><h3>{{ $item->quantity }} × {{ $item->name }}</h3>@if($item->item_type !== 'product')<p>@foreach($item->composition as $part){{ $part['quantity'] }} × {{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif</div><strong>{{ $money($item->quantity * $item->unit_price) }}</strong></div>@endforeach<div class="total-row"><span>Total</span><strong>{{ $money($order->total) }}</strong></div><p class="payment-note">{{ $paymentNote }}</p></section>
<section class="panel"><p class="eyebrow">RETRAIT AU RESTAURANT</p><h2>{{ $order->slot->date->format('d/m/Y') }} à {{ substr($order->slot->starts_at, 0, 5) }}</h2><p>{{ $order->customer_name }}<br><a href="tel:{{ preg_replace('/[^+0-9]/', '', $order->phone) }}">{{ $order->phone }}</a></p>
<h3 class="mt-6">Historique</h3><ol class="timeline">@foreach($histories as $history)<li><strong>{{ \App\Models\Order::HISTORY_LABELS[$history->to_status] ?? $history->to_status }}</strong><span>{{ \Carbon\CarbonImmutable::parse($history->created_at, 'UTC')->timezone('Europe/Paris')->format('d/m à H:i') }}</span></li>@endforeach</ol><a href="{{ route('orders.index') }}">← Toutes les commandes</a></section></div>
@endif
</x-restaurant-layout>
