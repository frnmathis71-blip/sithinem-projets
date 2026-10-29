@php($admin = request()->routeIs('admin.*'))
@php($paymentNote = $order->payment_method === 'online' ? ($order->paid_at ? 'Payée en ligne.' : ($order->status === 'awaiting_payment' ? 'Paiement en ligne en attente.' : 'Paiement en ligne non abouti.')) : ($order->paid_at ? 'Paiement sur place enregistré.' : 'Paiement sur place lors du retrait.'))
<x-restaurant-layout :title="'Commande #'.$order->id" :admin="$admin">
@if($admin)
<a class="back-link" href="{{ route('admin.orders', ['date' => $order->slot->date->format('Y-m-d')]) }}">← Commandes du {{ $order->slot->date->format('d/m/Y') }}</a>
<div class="admin-page-head">
    <div><p class="eyebrow">{{ $order->number }}</p><h1>Commande #{{ $order->id }}</h1>
        <p class="order-pills"><span @class(['badge', 'status-'.$order->status])>{{ \App\Models\Order::STATUSES[$order->status] }}</span><span @class(['badge', 'paid' => $order->paid_at])>{{ $order->paid_at ? ($order->payment_method === 'online' ? 'Payée en ligne' : 'Encaissée') : ($order->status === 'awaiting_payment' ? 'Paiement en ligne en cours' : 'À encaisser') }}</span><span class="badge">{{ ['phone' => 'Téléphone', 'counter' => 'Comptoir'][$order->source] ?? 'Site internet' }}</span></p></div>
</div>
@include('restaurant.customer-message')
<div class="order-detail-grid">
    <section class="panel"><h2>Articles</h2>@foreach($order->items as $item)<div class="cart-row"><div><h3>{{ $item->quantity }} × {{ $item->name }}</h3>@if($item->item_type !== 'product')<p>@foreach($item->composition as $part){{ $part['quantity'] }} × {{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif</div><strong>{{ number_format($item->quantity * $item->unit_price / 100, 2, ',', ' ') }} €</strong></div>@endforeach<div class="total-row"><span>Total</span><strong>{{ number_format($order->total / 100, 2, ',', ' ') }} €</strong></div><p class="payment-note">{{ $paymentNote }}</p></section>
    <div class="order-side">
        <section class="panel order-actions"><p class="eyebrow">RETRAIT AU RESTAURANT</p><h2>{{ $order->slot->date->format('d/m/Y') }} à {{ substr($order->slot->starts_at, 0, 5) }}</h2>
            <p class="order-customer"><strong>{{ $order->customer_name }}</strong><a href="tel:{{ preg_replace('/[^+0-9]/', '', $order->phone) }}">{{ $order->phone }}</a></p>
            @if($order->status === 'preparing')<form method="post" action="{{ route('admin.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="button full">Terminé ✓</button></form>@endif
            @if(!$order->paid_at && in_array($order->status, ['preparing', 'completed'], true))<form method="post" action="{{ route('admin.orders.paid', $order) }}">@csrf<button class="button secondary full">Confirmer l’encaissement sur place · {{ number_format($order->total / 100, 2, ',', ' ') }} €</button></form>@endif
            <a class="text-button" href="{{ route('admin.orders.ticket', $order) }}" target="_blank" rel="noopener">Imprimer ou voir le ticket</a>
            @if(in_array($order->status, ['preparing', 'awaiting_payment'], true))<details class="danger-zone"><summary>Annuler la commande</summary><p class="muted">Le montant du créneau sera libéré. La commande reste dans l’historique.</p><form method="post" action="{{ route('admin.orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><button class="button secondary full danger">Confirmer l’annulation</button></form></details>@endif
        </section>
        <section class="panel"><h3>Suivi de la commande</h3><ol class="timeline">@foreach($histories as $history)<li><strong>{{ \App\Models\Order::HISTORY_LABELS[$history->to_status] }}</strong><span>{{ \Carbon\CarbonImmutable::parse($history->created_at, 'UTC')->timezone('Europe/Paris')->format('d/m à H:i') }}</span></li>@endforeach</ol></section>
    </div>
</div>
@else
@include('restaurant.customer-message')
<div class="section-heading"><div><p class="eyebrow">{{ $order->number }}</p><h1>Commande #{{ $order->id }}</h1></div><span class="badge">{{ \App\Models\Order::STATUSES[$order->status] }}</span></div>
@if($order->status === 'awaiting_payment' && !$order->paid_at)
<div class="notice payment-pending" role="status"><p>{{ request('paiement') === 'annule' ? 'Le paiement n’a pas été effectué.' : 'Votre commande attend son paiement en ligne.' }} Votre créneau reste réservé {{ \App\Services\StripeCheckoutService::PAYMENT_WINDOW }} minutes ; sans paiement, la commande sera annulée.</p><form method="post" action="{{ route('orders.pay', $order) }}">@csrf<button class="button">Payer maintenant · {{ number_format($order->total / 100, 2, ',', ' ') }} €</button></form></div>
@elseif($order->payment_method === 'online' && $order->paid_at && request('paiement') === 'confirme')
<div class="notice success" role="status">Paiement reçu, merci ! Votre commande est transmise à la cuisine.</div>
@endif
<div class="checkout-grid"><section class="panel"><h2>Votre sélection</h2>@foreach($order->items as $item)<div class="cart-row"><div><h3>{{ $item->quantity }} × {{ $item->name }}</h3>@if($item->item_type !== 'product')<p>@foreach($item->composition as $part){{ $part['quantity'] }} × {{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif</div><strong>{{ number_format($item->quantity * $item->unit_price / 100, 2, ',', ' ') }} €</strong></div>@endforeach<div class="total-row"><span>Total</span><strong>{{ number_format($order->total / 100, 2, ',', ' ') }} €</strong></div><p class="payment-note">{{ $paymentNote }}</p></section>
<section class="panel"><p class="eyebrow">RETRAIT AU RESTAURANT</p><h2>{{ $order->slot->date->format('d/m/Y') }} à {{ substr($order->slot->starts_at, 0, 5) }}</h2><p>{{ $order->customer_name }}<br><a href="tel:{{ preg_replace('/[^+0-9]/', '', $order->phone) }}">{{ $order->phone }}</a></p>
<h3 class="mt-6">Suivi de la commande</h3><ol class="timeline">@foreach($histories as $history)<li><strong>{{ \App\Models\Order::HISTORY_LABELS[$history->to_status] }}</strong><span>{{ \Carbon\CarbonImmutable::parse($history->created_at, 'UTC')->timezone('Europe/Paris')->format('d/m à H:i') }}</span></li>@endforeach</ol><a href="{{ route('orders.index') }}">← Toutes les commandes</a></section></div>
@endif
</x-restaurant-layout>
