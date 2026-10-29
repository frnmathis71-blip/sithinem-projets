<a class="panel order-summary product-row" href="{{ route('admin.catalog.edit', ['product', $product->id]) }}">
    @php($photo = $product->image ?: $product->variants->first(fn ($variant) => $variant->image)?->image)
    @if($photo)<img class="row-thumb" src="{{ Storage::disk('public')->url($photo) }}" alt="" loading="lazy">@else<span class="row-thumb placeholder" aria-hidden="true">{{ mb_substr($product->name, 0, 1) }}</span>@endif
    <div><h3>{{ $product->name }}</h3>@if($product->weekdayLabel())<span class="badge weekday">{{ $product->weekdayLabel() }}</span>@endif
        @if($product->has_variants)<div class="variant-summary">@foreach($product->variants as $variant)<span>{{ $variant->variant_label }} · {{ number_format($variant->price / 100, 2, ',', ' ') }} €{{ !$variant->available ? ' · indisponible' : '' }}</span>@endforeach</div>@elseif($product->description && trim($product->description) !== trim($product->name))<p>{{ Str::limit($product->description, 100) }}</p>@endif
    </div>
    <span @class(['badge', 'danger' => !$product->available])>{{ $product->available ? ($product->has_variants ? $product->variants->count().' déclinaisons' : 'En vente') : 'Indisponible' }}</span>
    <strong>{{ $product->has_variants ? 'À partir de ' : '' }}{{ number_format($product->price / 100, 2, ',', ' ') }} € <span class="row-edit">Modifier →</span></strong>
</a>
