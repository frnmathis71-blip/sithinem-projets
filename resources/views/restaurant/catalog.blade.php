<x-restaurant-layout :title="$home ? 'Bienvenue à notre table' : (request()->routeIs('offers') ? 'Les offres' : 'La carte')">
@if($home)
<div class="home-intro" data-home-intro>
    <section class="hero">
        <div class="hero-copy"><p class="eyebrow"><span class="little-dot"></span> VOTRE RENDEZ-VOUS GOURMAND</p><h1>Du cœur en cuisine.<br>Du bonheur <em>à emporter.</em></h1><p>Les saveurs de l’Asie, cuisinées à la commande et prêtes à l’heure que vous choisissez.</p><a class="button" href="#carte">Découvrir la carte <span aria-hidden="true">↗</span></a></div>
        @include('restaurant.hero-carousel')
    </section>
    <ul class="promise-strip"><li><span class="promise-icon" aria-hidden="true">✦</span><span><b>Préparé pour vous</b><small>Cuisiné à la commande</small></span></li><li><span class="promise-icon" aria-hidden="true">◷</span><span><b>Retrait sur rendez-vous</b><small>Choisissez votre créneau</small></span></li><li><span class="promise-icon" aria-hidden="true">€</span><span><b>Paiement sur place</b><small>Rien à régler en ligne</small></span></li></ul>
</div>
@endif
<section id="carte" class="catalog-section">
    <div class="section-heading"><div><p class="eyebrow">À CHAQUE ENVIE, SA SAVEUR</p>@php($heading = $home ? 'h2' : 'h1')<{{ $heading }}>{{ request()->routeIs('offers') ? 'Nos offres du moment' : ($home ? 'Les favoris de notre table' : 'Qu’est-ce qui vous fait envie ?') }}</{{ $heading }}></div><span class="muted">À savourer chez vous.</span></div>
    @include('restaurant.catalog-navigation')
    <div class="product-grid">
    @forelse($items as $item)
        @php($kind = $item instanceof \App\Models\Offer ? 'offer' : ($item instanceof \App\Models\Menu ? 'menu' : 'product'))
        @php($variants = $kind === 'product' && $item->has_variants)
        @php($available = $variants ? $item->variants->contains(fn($variant) => $catalog->available($variant)) : $catalog->available($item))
        <article class="product-card" @if($kind === 'product') data-catalog-card @endif>
            @php($photo = $item->image ?: ($variants ? $item->variants->first(fn($v) => $v->available && $v->image)?->image : null))
            @if($photo)<img class="product-image" src="{{ Storage::disk('public')->url($photo) }}" alt="{{ $item->name }}" loading="lazy">@else<img class="product-image" src="{{ asset('images/product-placeholder.svg') }}" alt="Illustration — photo à venir" loading="lazy">@endif
            <div class="product-content"><span class="eyebrow">{{ ['product' => 'À LA CARTE', 'menu' => 'LE BON ACCORD', 'offer' => 'ÉDITION GOURMANDE'][$kind] }}</span><h3>{{ $item->name }}</h3>@if($item->weekdayLabel())<span class="badge weekday">{{ $item->weekdayLabel() }}</span>@endif@if($item instanceof \App\Models\Product && $item->includesSide())<span class="badge">Garniture incluse</span>@endif@if($item->description && trim($item->description) !== trim($item->name))<p>{{ $item->description }}</p>@endif
            @if($kind !== 'product')<p class="composition">@foreach($catalog->composition($item) as $part){{ $part['quantity'] }} × {{ $part['name'] }}{{ !$loop->last ? ' · ' : '' }}@endforeach</p>@endif
            @if($kind === 'offer' && $item->ends_at)<small>Jusqu’au {{ $item->ends_at->timezone('Europe/Paris')->format('d/m/Y à H:i') }}</small>@endif
            <div class="product-bottom"><strong>{{ $variants ? 'À partir de ' : '' }}{{ number_format($item->price / 100, 2, ',', ' ') }} €</strong><form method="post" action="{{ route('cart.update') }}" @if($kind === 'product') data-product-add @endif>@csrf<input type="hidden" name="type" value="{{ $kind }}"><input type="hidden" name="id" value="{{ $item->id }}"><input type="hidden" name="quantity" value="1"><input type="hidden" name="mode" value="add"><button class="button small" @disabled(!$available) aria-label="Ajouter {{ $item->name }} au panier">{{ $available ? 'Ajouter +' : 'Indisponible' }}</button></form></div>@if($kind === 'product' && !$variants && $item->requiresSide())<a class="text-button" href="{{ route('menus.builder', ['product' => $item->id]) }}">Avec un accompagnement →</a>@endif</div>
        </article>
    @empty
        <div class="empty-state"><h3>{{ $type === 'offer' ? 'De nouvelles offres se préparent' : 'La carte se prépare' }}</h3><p>{{ $type === 'offer' ? 'Découvrez nos plats et menus en attendant la prochaine occasion gourmande.' : 'Les plats seront bientôt disponibles. Revenez découvrir nos saveurs.' }}</p>@if($type === 'offer')<a class="button" href="{{ route('menu') }}">Voir la carte</a>@endif</div>
    @endforelse
    </div>
    @if($home)<p><a class="button secondary" href="{{ route('menu') }}">Voir toute la carte →</a></p>@endif
</section>
<section class="bottom-cta"><div><p class="eyebrow">VOTRE SOIRÉE, EN PLUS SIMPLE</p><h2>Vous choisissez.<br>Nous cuisinons.</h2></div><p>Réservez un créneau disponible au moins 30 minutes à l’avance. Votre commande vous attendra au restaurant.</p><a class="button light" href="{{ route('cart') }}">Préparer mon retrait ↗</a></section>
@if($type === 'product')
<script type="application/json" data-catalog-options>@json(app(\App\Services\DynamicMenuService::class)->options())</script>
@include('restaurant.variant-dialog')
@endif
</x-restaurant-layout>
