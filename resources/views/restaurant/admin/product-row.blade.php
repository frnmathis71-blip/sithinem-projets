<a class="panel order-summary" href="{{ route('admin.catalog.edit', ['product', $product->id]) }}">
    <div><h3>{{ $product->name }}</h3>
        @if($product->has_variants)<div class="variant-summary">@foreach($product->variants as $variant)<span>{{ $variant->variant_label }} · {{ number_format($variant->price / 100, 2, ',', ' ') }} €{{ !$variant->available ? ' · indisponible' : '' }}</span>@endforeach</div>@else<p>{{ Str::limit($product->description, 100) }}</p>@endif
    </div>
    <span class="badge">{{ $product->available ? ($product->has_variants ? $product->variants->count().' déclinaisons' : 'Activé') : 'Indisponible' }}</span>
    <strong>{{ $product->has_variants ? 'À partir de ' : '' }}{{ number_format($product->price / 100, 2, ',', ' ') }} € · Modifier ↗</strong>
</a>
