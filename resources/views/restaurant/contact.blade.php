<x-restaurant-layout title="Contact">
<div class="section-heading"><div><p class="eyebrow">UNE QUESTION, UNE COMMANDE ?</p><h1>Contact</h1></div></div>
<div class="contact-grid">
<section class="panel contact-card">
    <h2>Nous appeler</h2>
    @if($settings->contact_phone)
        <a class="contact-phone" href="{{ $settings->contactPhoneLink() }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h3l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z"/></svg>{{ $settings->contact_phone }}</a>
        <p>Pour une question sur la carte, une commande en cours ou une demande particulière, appelez-nous pendant nos horaires d’ouverture.</p>
    @else
        <p>Notre numéro sera bientôt disponible. En attendant, vous pouvez commander en ligne et retirer votre commande sur place.</p>
    @endif
    @if(config('site.address'))<p class="contact-address"><strong>Adresse</strong><br>{{ config('site.address') }}</p>@endif
    <a class="button" href="{{ route('menu') }}">Commander en ligne <span aria-hidden="true">↗</span></a>
</section>
<section class="panel">
    <h2>Horaires habituels</h2>
    <dl class="contact-hours">
        @foreach($weekly as $day)
            <div @class(['closed' => ! $day->is_open])><dt>{{ [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'][$day->weekday] }}</dt><dd>{{ $day->is_open ? substr($day->starts_at, 0, 5).' – '.substr($day->ends_at, 0, 5) : 'Fermé' }}</dd></div>
        @endforeach
    </dl>
    <p class="muted">Les horaires peuvent changer les jours fériés : les créneaux proposés lors de la commande sont toujours à jour.</p>
</section>
</div>
</x-restaurant-layout>
