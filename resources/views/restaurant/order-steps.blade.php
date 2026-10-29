{{-- Progress of the order: received, accepted, ready, completed. --}}
@php($steps = array_keys(\App\Models\Order::TRACKING))
@php($current = array_search($order->status, $steps, true))
@if($current !== false)
<ol class="order-steps" aria-label="Avancement de la commande">
    @foreach($steps as $index => $step)
        <li @class(['done' => $index < $current, 'current' => $index === $current]) @if($index === $current) aria-current="step" @endif><span aria-hidden="true">{{ $index < $current ? '✓' : $index + 1 }}</span>{{ \App\Models\Order::STATUSES[$step] }}</li>
    @endforeach
</ol>
@endif
