<x-restaurant-layout title="Cookies et préférences"><article class="panel legal-page"><h1>Cookies et préférences</h1>
<p>Les cookies strictement nécessaires servent à la connexion, au panier et à la protection des formulaires. Ils sont actifs sans consentement. La session expire après {{ config('session.lifetime') }} minutes d’inactivité selon la configuration du service ; une connexion mémorisée peut durer plus longtemps.</p>
<p>Aucun cookie publicitaire ni outil de mesure d’audience n’est actuellement utilisé. Accepter les cookies facultatifs n’active donc aucun traceur supplémentaire. Tout ajout de nouvelles finalités nécessitera une nouvelle information et un nouveau choix.</p>
<p>Votre préférence est conservée six mois dans un cookie de choix. Vous pouvez la modifier à tout moment sur cette page. Le refus n’empêche pas de commander.</p>
<p>Choix actuel : {{ request()->cookie('site_cookie_choice') === 'v1:accepted' ? 'acceptation des cookies facultatifs' : (request()->cookie('site_cookie_choice') === 'v1:rejected' ? 'refus des cookies facultatifs' : 'non exprimé') }}.</p>
@include('restaurant.legal.cookie-form')
<p><a href="{{ route('privacy') }}">Politique de confidentialité</a></p></article></x-restaurant-layout>
