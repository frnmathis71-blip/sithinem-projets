<x-restaurant-layout title="Contact" :admin="true">
<div class="section-heading"><div><p class="eyebrow">VOS COORDONNÉES</p><h1>Contact</h1></div><a href="{{ route('contact') }}">Voir la page Contact</a></div>
<div class="checkout-grid">
<form class="panel" method="post" action="{{ route('admin.contact.update') }}">@csrf @method('PATCH')
<h2>Téléphone du restaurant</h2>
<p>Ce numéro s’affiche sur la page Contact du site. Sur téléphone, vos clients peuvent vous appeler d’un simple toucher.</p>
<label>Numéro de téléphone<input name="contact_phone" type="tel" autocomplete="tel" maxlength="30" placeholder="01 23 45 67 89" value="{{ old('contact_phone', $settings->contact_phone) }}"></label>
<p class="muted">Laissez vide pour ne plus afficher de numéro.</p>
<button class="button">Enregistrer</button>
</form>
<section class="panel"><h2>Également sur la page Contact</h2><p>Les horaires habituels du <a href="{{ route('admin.calendar') }}">calendrier d’ouverture</a> s’affichent automatiquement à côté du numéro.</p></section>
</div>
</x-restaurant-layout>
