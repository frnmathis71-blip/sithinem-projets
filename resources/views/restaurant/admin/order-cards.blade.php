@forelse($orders as $order)
<a class="panel rush-order {{ $order->status === 'preparing' ? 'preparing' : '' }}" style="--order-accent: {{ $order->accent() }}" data-order="{{ $order->id }}" href="{{ route('admin.orders.show', $order) }}">
<div class="section-heading"><strong class="pickup-clock">{{ substr($order->slot->starts_at, 0, 5) }}</strong><span class="badge">{{ \App\Models\Order::STATUSES[$order->status] }}</span></div>
<h2>#{{ $order->id }} · {{ $order->customer_name }}</h2>
@include('restaurant.customer-message')
<ul>
@foreach($order->items as $item)
    <li>
        {{ $item->quantity }} × {{ $item->name }}
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
@empty<div class="panel"><h2>{{ $scope === 'active' ? 'Tout est à jour' : 'Aucune commande' }}</h2><p>{{ $scope === 'active' ? 'Aucune commande en préparation pour cette journée.' : 'Aucune commande dans cette vue pour cette journée.' }}</p></div>@endforelse
