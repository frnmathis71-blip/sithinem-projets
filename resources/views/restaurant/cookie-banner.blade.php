@if(!in_array(request()->cookie('site_cookie_choice'), ['v1:accepted', 'v1:rejected'], true) && !request()->routeIs('cookies'))
<aside class="cookie-banner" aria-labelledby="cookie-title"><h2 id="cookie-title">Vos préférences de cookies</h2><p>Ce site utilise les cookies nécessaires au panier, à la connexion et à la sécurité. Aucun traceur publicitaire ou de mesure d’audience n’est actif.</p>
@include('restaurant.legal.cookie-form')
<a href="{{ route('cookies') }}">Détails et paramètres</a></aside>
@endif
