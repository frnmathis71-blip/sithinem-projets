@props(['title' => 'Sithinem', 'admin' => false])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#263f32">
    <meta name="description" content="Votre cuisine préférée à emporter. Choisissez vos plats et réservez votre heure de retrait chez Sithinem.">
    <title>{{ $title }} · Sithinem</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-title" content="Sithinem">
    <link rel="manifest" href="/manifest.webmanifest">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['restaurant', 'admin-body' => $admin])>
<a class="skip-link" href="#content">Aller au contenu</a>
@if($admin)
{{-- Back office: its own header, without the customer banner, navigation or footer. --}}
@php
$adminLinks = [
    ['route' => 'admin.orders', 'label' => 'Commandes', 'active' => ['admin.dashboard', 'admin.orders', 'admin.orders.show', 'admin.orders.ticket*'], 'icon' => 'M5 4h14v16H5zM8 8h8M8 12h8M8 16h5'],
    ['route' => 'admin.card', 'label' => 'Carte', 'active' => ['admin.card', 'admin.catalog*', 'admin.categories*'], 'icon' => 'M4 5h16v14H4zM8 5v14M11 9h6M11 13h6'],
    ['route' => 'admin.menu-rules', 'label' => 'Formules', 'active' => ['admin.menu-rules*'], 'icon' => 'M4 6h16M4 12h16M4 18h10M18 16v4M16 18h4'],
    ['route' => 'admin.slots', 'label' => 'Créneaux & capacité', 'active' => ['admin.slots*'], 'icon' => 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18z'],
    ['route' => 'admin.calendar', 'label' => 'Calendrier', 'active' => ['admin.calendar'], 'icon' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4'],
    ['route' => 'admin.statistics', 'label' => 'Statistiques', 'active' => ['admin.statistics'], 'icon' => 'M5 20V11M10 20V5M15 20v-8M20 20v-4'],
    ['route' => 'admin.contact', 'label' => 'Contact', 'active' => ['admin.contact*'], 'icon' => 'M6 3h3l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z'],
];
@endphp
{{-- Left sidebar on computers, compact top bar on tablets and phones. --}}
<aside class="admin-header">
    <a href="{{ route('admin.orders') }}" class="admin-brand"><img src="{{ asset('images/sithinem-logo.png') }}" alt="" width="48" height="48"><span><strong>Sithinem</strong><small>Administration</small></span></a>
    <nav class="admin-nav" aria-label="Administration">
        @foreach($adminLinks as $link)
            <a href="{{ route($link['route']) }}" @class(['active' => request()->routeIs(...$link['active'])]) @if(request()->routeIs(...$link['active'])) aria-current="page" @endif><svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $link['icon'] }}"/></svg>{{ $link['label'] }}</a>
        @endforeach
    </nav>
    <div class="admin-actions">
        <a @class(['button', 'small', 'admin-new-order', 'active' => request()->routeIs('admin.orders.create')]) href="{{ route('admin.orders.create') }}">+ Nouvelle commande</a>
        <a class="admin-site-link" href="{{ route('home') }}">Voir le site →</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="button secondary small" data-test="admin-logout">Se déconnecter</button></form>
    </div>
</aside>
@else
@php($onlinePayment = app(\App\Services\StripeCheckoutService::class)->enabled())
<div class="announcement">CUISINÉ AVEC CŒUR · À EMPORTER UNIQUEMENT · {{ $onlinePayment ? 'PAIEMENT EN LIGNE OU AU RETRAIT' : 'PAIEMENT AU RETRAIT' }}</div>
<header class="site-header">
    <a href="{{ route('home') }}" class="brand" aria-label="Sithinem, accueil"><img class="brand-logo" src="{{ asset('images/sithinem-logo.png') }}" alt="Sithinem Traiteur" width="96" height="96"></a>
    <nav class="main-nav" aria-label="Navigation principale">
        <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>Accueil</a>
        <a href="{{ route('menu') }}" @class(['active' => request()->routeIs('menu')])>La carte</a>
        <a href="{{ route('offers') }}" @class(['active' => request()->routeIs('offers')])>Les offres</a>
        <a href="{{ route('contact') }}" @class(['active' => request()->routeIs('contact')])>Contact</a>
        @auth
            <a href="{{ route('orders.index') }}">Mes commandes</a>
            <a href="{{ route('account') }}">Mon compte</a>
            @if(auth()->user()->role === 'admin')<a href="{{ route('admin.dashboard') }}">Administration</a>@endif
        @else
            <a href="{{ route('login') }}">Connexion</a>
        @endauth
    </nav>
    @php($cartCount = array_sum(session('cart', [])))
    <a @class(['button', 'cart-link', 'has-items' => $cartCount > 0]) href="{{ route('cart') }}" aria-label="Mon panier, {{ $cartCount }} article(s)">Mon panier <span aria-hidden="true">{{ $cartCount }}</span></a>
</header>
@endif
<main id="content" @class(['page-shell', 'admin-shell' => $admin])>
    @if(config('restaurant.demo'))<p class="muted">Carte de démonstration · Produits et tarifs d’exemple.</p>@endif
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>Vérifiez ces informations :</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!$admin && request()->routeIs('home', 'menu', 'menus.*', 'offers', 'cart', 'checkout'))@include('restaurant.menu-proposal')@endif
    {{ $slot }}
</main>
@if(!$admin && ($cartCount ?? 0) > 0 && !request()->routeIs('cart', 'checkout', 'menus.show', 'menus.builder'))
<a class="mobile-cart" href="{{ route('cart') }}"><span class="mobile-cart-count" aria-hidden="true">{{ $cartCount }}</span><span>Voir mon panier</span><span aria-hidden="true">→</span></a>
@endif
@if($admin)
<footer class="admin-footer"><span>Sithinem · Administration</span><a href="{{ route('home') }}">Voir le site</a></footer>
@else
<footer class="site-footer">
    <div class="footer-intro"><a class="footer-brand" href="{{ route('home') }}"><img src="{{ asset('images/sithinem-logo.png') }}" alt="Sithinem Traiteur" width="72" height="72" loading="lazy"></a><p>Les saveurs d’Asie, cuisinées avec cœur et à emporter.</p></div>
    <nav class="footer-links" aria-label="Découvrir"><strong>Découvrir</strong><a href="{{ route('menu') }}">La carte</a><a href="{{ route('menus.index') }}">Nos menus</a><a href="{{ route('offers') }}">Les offres</a><a href="{{ route('contact') }}">Contact</a></nav>
    <div class="footer-links"><strong>Votre retrait</strong><span>Retrait sur rendez-vous</span><span>Commande au moins 30 min à l’avance</span><span>{{ $onlinePayment ? 'Paiement en ligne ou sur place' : 'Paiement sur place lors du retrait' }}</span></div>
    <nav class="footer-links" aria-label="Informations légales"><strong>Informations</strong><a href="{{ route('privacy') }}">Confidentialité / RGPD</a><a href="{{ route('terms') }}">CGU</a><a href="{{ route('cookies') }}">Gérer les cookies</a></nav>
    <p class="footer-copy">© {{ date('Y') }} Sithinem · Traiteur asiatique</p>
</footer>
@endif
@include('restaurant.cookie-banner')
</body>
</html>
