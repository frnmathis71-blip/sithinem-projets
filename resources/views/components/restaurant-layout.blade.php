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
    <link rel="icon" href="/images/sithinem-logo.png" type="image/png">
    <link rel="manifest" href="/manifest.webmanifest">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="restaurant">
<a class="skip-link" href="#content">Aller au contenu</a>
<div class="announcement">CUISINÉ AVEC CŒUR · À EMPORTER UNIQUEMENT · PAIEMENT AU RETRAIT</div>
<header class="site-header">
    <a href="{{ route('home') }}" class="brand" aria-label="Sithinem, accueil"><img class="brand-logo" src="{{ asset('images/sithinem-logo.png') }}" alt="Sithinem Traiteur" width="96" height="96"></a>
    <nav class="main-nav" aria-label="Navigation principale">
        <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>Accueil</a>
        <a href="{{ route('menu') }}" @class(['active' => request()->routeIs('menu')])>La carte</a>
        <a href="{{ route('offers') }}" @class(['active' => request()->routeIs('offers')])>Les offres</a>
        @auth
            <a href="{{ route('orders.index') }}">Mes commandes</a>
            <a href="{{ route('account') }}">Mon compte</a>
            @if(auth()->user()->role === 'admin')<a href="{{ route('admin.dashboard') }}">Administration</a>@endif
        @else
            <a href="{{ route('login') }}">Connexion</a>
        @endauth
    </nav>
    @if($admin)
        <form method="post" action="{{ route('logout') }}">@csrf<button class="button secondary small" data-test="admin-logout">Se déconnecter</button></form>
    @else
        @php($cartCount = array_sum(session('cart', [])))
        <a @class(['button', 'cart-link', 'has-items' => $cartCount > 0]) href="{{ route('cart') }}" aria-label="Mon panier, {{ $cartCount }} article(s)">Mon panier <span aria-hidden="true">{{ $cartCount }}</span></a>
    @endif
</header>
@if($admin)
<nav class="admin-nav" aria-label="Administration">
    @foreach(['admin.dashboard' => 'Commandes', 'admin.card' => 'Carte', 'admin.slots' => 'Créneaux & capacité', 'admin.calendar' => 'Calendrier', 'admin.statistics' => 'Statistiques'] as $route => $label)
        <a href="{{ route($route) }}" @class(['active' => request()->routeIs($route) || ($route === 'admin.card' && request()->routeIs('admin.catalog*', 'admin.categories*')) || ($route === 'admin.dashboard' && request()->routeIs('admin.orders*'))])>{{ $label }}</a>
    @endforeach
</nav>
@endif
<main id="content" class="page-shell">
    @if(config('restaurant.demo'))<p class="muted">Carte de démonstration · Produits et tarifs d’exemple.</p>@endif
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>Vérifiez ces informations :</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!$admin && request()->routeIs('home', 'menu', 'menus.*', 'offers', 'cart', 'checkout'))@include('restaurant.menu-proposal')@endif
    {{ $slot }}
</main>
@if(!$admin && ($cartCount ?? 0) > 0 && !request()->routeIs('cart', 'checkout', 'menus.show', 'menus.builder'))
<a class="mobile-cart" href="{{ route('cart') }}"><span class="mobile-cart-count" aria-hidden="true">{{ $cartCount }}</span><span>Voir mon panier</span><span aria-hidden="true">→</span></a>
@endif
<footer class="site-footer">
    <div class="footer-intro"><a class="footer-brand" href="{{ route('home') }}"><img src="{{ asset('images/sithinem-logo.png') }}" alt="Sithinem Traiteur" width="72" height="72" loading="lazy"></a><p>Les saveurs d’Asie, cuisinées avec cœur et à emporter.</p></div>
    <nav class="footer-links" aria-label="Découvrir"><strong>Découvrir</strong><a href="{{ route('menu') }}">La carte</a><a href="{{ route('menus.index') }}">Nos menus</a><a href="{{ route('offers') }}">Les offres</a></nav>
    <div class="footer-links"><strong>Votre retrait</strong><span>Retrait sur rendez-vous</span><span>Commande au moins 30 min à l’avance</span><span>Paiement sur place lors du retrait</span></div>
    <nav class="footer-links" aria-label="Informations légales"><strong>Informations</strong><a href="{{ route('privacy') }}">Confidentialité / RGPD</a><a href="{{ route('terms') }}">CGU</a><a href="{{ route('cookies') }}">Gérer les cookies</a></nav>
    <p class="footer-copy">© {{ date('Y') }} Sithinem · Traiteur asiatique</p>
</footer>
@include('restaurant.cookie-banner')
</body>
</html>
