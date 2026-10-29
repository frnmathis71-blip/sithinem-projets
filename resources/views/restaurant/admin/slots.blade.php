<x-restaurant-layout title="Créneaux & capacité" :admin="true">
<div class="section-heading"><div><p class="eyebrow">CHARGE DE PRÉPARATION</p><h1>Créneaux & capacité</h1></div></div>
<div class="checkout-grid">
<form class="panel" method="post" action="{{ route('admin.settings') }}">@csrf @method('PATCH')
<h2>Montant maximal par créneau</h2>
<p>Réglez le montant total de commandes que la cuisine peut préparer en 20 minutes. Par exemple, 200 € permettent 20 commandes à 5 € ou 2 commandes à 100 €.</p>
<label>Capacité par défaut (€ / 20 minutes)<input type="number" name="default_amount_capacity" min="0" max="200000" step="0.01" required value="{{ old('default_amount_capacity', number_format($settings->default_amount_capacity / 100, 2, '.', '')) }}"></label>
<p class="muted">0 € bloque les nouvelles commandes. Ce plafond s’applique aux créneaux sans personnalisation. Le montant est un indicateur de charge : ajustez-le selon votre cuisine et votre carte.</p>
<button class="button">Enregistrer la capacité</button></form>
@php($day = \Carbon\CarbonImmutable::parse($date))
<form class="panel" method="get"><h2>Choisir une journée</h2><p class="day-links"><a class="button secondary small" href="{{ route('admin.slots', ['date' => $day->subDay()->format('Y-m-d')]) }}">← Veille</a><a class="button secondary small" href="{{ route('admin.slots') }}">Aujourd’hui</a><a class="button secondary small" href="{{ route('admin.slots', ['date' => $day->addDay()->format('Y-m-d')]) }}">Lendemain →</a></p><p class="admin-date">{{ $day->locale('fr')->translatedFormat('l j F Y') }}</p><label>Date du service<input type="date" name="date" required value="{{ $date }}"></label><button class="button secondary">Afficher les créneaux</button><p>{{ $hours['open'] ? 'Service de '.$hours['start'].' à '.$hours['end'] : 'Restaurant fermé à cette date' }}{{ $hours['reason'] ? ' · '.$hours['reason'] : '' }}</p><p>Les commandes annulées libèrent leur montant. Les commandes terminées restent comptabilisées. Les commandes déjà acceptées sont conservées si vous baissez le plafond.</p></form>
</div>
<div class="slots-admin-grid">
@forelse($slots as $slot)
@php($load = $slot['capacity'] > 0 ? $slot['used'] / $slot['capacity'] : ($slot['used'] > 0 ? 1 : 0))
<article @class(['panel', 'slot-card', 'closed' => $slot['manually_closed'] || $slot['status'] === 'Fermé'])><div class="section-heading"><h2>{{ $slot['start'] }}</h2><span @class(['badge', 'danger' => $slot['remaining'] === 0 || $slot['manually_closed']])>{{ $slot['status'] }}</span></div>
<p class="capacity-count"><strong>{{ number_format($slot['used'] / 100, 2, ',', ' ') }} €</strong> / {{ number_format($slot['capacity'] / 100, 2, ',', ' ') }} €</p>
<progress @class(['high' => $load >= 0.8 && $load < 1, 'full' => $load >= 1]) value="{{ min($slot['used'], $slot['capacity']) }}" max="{{ max(1, $slot['capacity']) }}" aria-label="Charge du créneau {{ $slot['start'] }}"></progress>
<p>{{ number_format($slot['remaining'] / 100, 2, ',', ' ') }} € disponibles · {{ $slot['count'] }} commande(s)</p>
@if($slot['used'] > $slot['capacity'])<p class="notice error">Dépassement de {{ number_format(($slot['used'] - $slot['capacity']) / 100, 2, ',', ' ') }} €. Les commandes existantes sont conservées ; aucune nouvelle commande ne sera acceptée.</p>@endif
@if($slot['count'] > 0 && $slot['status'] === 'Fermé')<p class="notice error">Des commandes existent sur ce créneau fermé. Contactez les clients si nécessaire.</p>@endif
<form class="slot-form" method="post" action="{{ route('admin.slots.update', $slot['id']) }}">@csrf @method('PATCH')
<label>Capacité personnalisée (€)<input type="number" name="amount_capacity_override" min="0" max="200000" step="0.01" value="{{ $slot['capacity_override'] === null ? '' : number_format($slot['capacity_override'] / 100, 2, '.', '') }}" placeholder="Par défaut : {{ number_format($settings->default_amount_capacity / 100, 2, ',', ' ') }} €"></label>
<p class="muted small-hint">Vide = capacité par défaut.</p>
<input type="hidden" name="manually_closed" value="0"><label class="checkbox-label"><input type="checkbox" name="manually_closed" value="1" @checked($slot['manually_closed'])> Fermer manuellement</label><button class="button secondary full">Enregistrer</button></form>
<a class="text-button" href="{{ route('admin.orders', ['slot' => $slot['id'], 'date' => $date, 'view' => 'all']) }}">Voir les {{ $slot['count'] }} commandes</a></article>
@empty<div class="empty-state"><h2>Pas de service ce jour-là</h2><p>Ouvrez exceptionnellement cette date dans le calendrier pour proposer des créneaux.</p></div>@endforelse
</div></x-restaurant-layout>
