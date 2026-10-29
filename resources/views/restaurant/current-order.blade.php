{{-- Top of the home page while the customer has an order on its way; gone once it is completed or cancelled. --}}
@php($order = $currentOrders->first())
@php($today = now('Europe/Paris')->format('Y-m-d'))
@php($day = $order->slot->date->format('Y-m-d') === $today ? 'aujourd’hui' : ($order->slot->date->format('Y-m-d') === now('Europe/Paris')->addDay()->format('Y-m-d') ? 'demain' : 'le '.$order->slot->date->format('d/m')))
<section @class(['current-order', 'is-ready' => $order->status === 'ready', 'is-waiting' => $order->status === 'awaiting_payment']) aria-label="Votre commande en cours"
    data-order-tracking="{{ route('orders.tracking', $order) }}" data-signature="{{ md5($order->status.'|'.$order->time_slot_id.'|'.$order->total.'|'.$order->paid_at) }}">
    <span class="current-order-icon" aria-hidden="true">{{ $order->status === 'ready' ? '✓' : '◷' }}</span>
    <div class="current-order-text">
        <p class="current-order-title"><strong>Commande #{{ $order->id }}</strong><span @class(['badge', 'status-'.$order->status])>{{ \App\Models\Order::STATUSES[$order->status] }}</span></p>
        <p>{{ $order->status === 'awaiting_payment' ? 'Votre commande attend son paiement en ligne.' : \App\Models\Order::TRACKING[$order->status] }} Retrait {{ $day }} à {{ substr($order->slot->starts_at, 0, 5) }}.@if($currentOrders->count() > 1) <a href="{{ route('orders.index') }}">+ {{ $currentOrders->count() - 1 }} autre{{ $currentOrders->count() > 2 ? 's' : '' }} commande{{ $currentOrders->count() > 2 ? 's' : '' }} en cours</a>@endif</p>
    </div>
    <a class="button small" href="{{ route('orders.show', $order) }}">{{ $order->status === 'awaiting_payment' ? 'Payer ma commande' : 'Suivre ma commande' }}</a>
</section>
