@forelse($orders as $order)
<a class="panel rush-order {{ $order->status }}" style="--order-accent: {{ $order->accent() }}" data-order="{{ $order->id }}" href="{{ route('admin.orders.show', $order) }}">
<div class="rush-order-top"><strong class="pickup-clock">{{ substr($order->slot->starts_at, 0, 5) }}</strong><span class="rush-order-badges"><span @class(['badge', 'status-'.$order->status])>{{ \App\Models\Order::STATUSES[$order->status] }}</span>@if($order->paid_at)<span class="badge paid">Encaissée</span>@endif</span></div>
<h2><span class="order-number">#{{ $order->id }}</span> {{ $order->customer_name }}</h2>
<span class="order-source">{{ ['phone' => 'Téléphone', 'counter' => 'Comptoir'][$order->source] ?? 'Site internet' }}</span>
@include('restaurant.customer-message')
<ul class="rush-items">
@foreach($order->items as $item)
    <li>
        <b>{{ $item->quantity }} ×</b> {{ $item->name }}
        @if($item->item_type !== 'product' && $item->composition)
            <ul>
                @foreach($item->composition as $part)
                    <li>{{ $part['quantity'] }} × {{ $part['name'] }}</li>
                @endforeach
            </ul>
        @endif
    </li>
@endforeach
</ul>
<div class="total-row"><strong>{{ number_format($order->total / 100, 2, ',', ' ') }} €</strong><span>Voir le détail →</span></div>
</a>
@empty<div class="panel board-empty"><h2>{{ $scope === 'active' ? 'Tout est à jour' : 'Aucune commande' }}</h2><p>{{ $scope === 'active' ? 'Aucune commande en cours pour cette journée.' : 'Aucune commande dans cette vue pour cette journée.' }}</p></div>@endforelse
