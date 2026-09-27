<x-restaurant-layout title="Commandes" :admin="true">
<div class="section-heading"><div><p class="eyebrow">LE SERVICE</p><h1>{{ $scope === 'active' ? 'En préparation' : 'Historique des commandes' }}</h1><p data-board-date>{{ \Carbon\CarbonImmutable::parse($date)->locale('fr')->translatedFormat('l j F Y') }}</p></div><a class="button secondary" href="{{ route('admin.orders', ['date' => $date, 'view' => $scope === 'active' ? 'history' : 'active']) }}">{{ $scope === 'active' ? 'Voir l’historique' : 'En préparation' }}</a></div>
<details class="panel"><summary>Consulter une autre journée</summary><form class="inline-form" method="get" action="{{ route('admin.orders') }}"><input type="hidden" name="view" value="{{ $scope }}"><label>Date du service<input type="date" name="date" value="{{ $date }}" required></label><button class="button small">Afficher</button><a href="{{ route('admin.orders') }}">Aujourd’hui</a></form></details>
<section data-feed-url="{{ route('admin.feed', request()->only('date', 'view', 'slot')) }}"><p class="muted" data-feed-message aria-live="polite">Actualisation automatique toutes les 10 secondes.</p><div class="rush-board" data-board-orders>@include('restaurant.admin.order-cards')</div></section>
<section class="panel print-panel" aria-labelledby="print-heading">
    <h2 id="print-heading">Impression des commandes</h2>
    @if($printingConfigured)
        <p>Les commandes en préparation du jour sont envoyées automatiquement lorsque le poste d’impression est connecté. {{ $printPending }} ticket(s) en attente ou en cours d’envoi.</p>
        <form method="post" action="{{ route('admin.orders.print') }}">@csrf<input type="hidden" name="batch" value="{{ \Illuminate\Support\Str::uuid() }}"><button class="button">Imprimer toutes les commandes du jour</button></form>
        <p class="muted">Cette action imprime aussi les commandes déjà imprimées et les commandes annulées, identifiées comme telles.</p>
    @else
        <p>Connectez le poste d’impression pour activer l’envoi automatique à votre imprimante Wi-Fi.</p>
        <a class="button" href="{{ route('admin.orders.tickets', ['print' => 1]) }}" target="_blank" rel="noopener">Imprimer toutes les commandes du jour</a>
    @endif
    <a class="text-button" href="{{ route('admin.orders.tickets') }}" target="_blank" rel="noopener">Aperçu des tickets · 58 / 80 mm</a>
    @foreach($printIssues as $issue)
        <div class="notice error"><strong>Ticket #{{ $issue->order_id }} : envoi à vérifier</strong><p>{{ $issue->error }}</p>
            <form method="post" action="{{ route('admin.printing.retry', $issue->id) }}">@csrf<button class="button secondary small">J’ai vérifié l’imprimante : relancer ce ticket</button></form>
        </div>
    @endforeach
</section>
<details class="panel"><summary>Notifications</summary><button class="button secondary" data-enable-push data-key="{{ config('restaurant.vapid.public_key') }}" data-url="{{ route('admin.push.store') }}" @disabled(!$pushConfigured)>Activer les notifications sur cet appareil</button><p data-push-status aria-live="polite"></p></details>
</x-restaurant-layout>
