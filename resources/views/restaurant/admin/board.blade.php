@php($day = \Carbon\CarbonImmutable::parse($date))
@php($today = now('Europe/Paris')->format('Y-m-d'))
<x-restaurant-layout title="Commandes" :admin="true">
<div class="admin-page-head">
    <div><p class="eyebrow">LE SERVICE</p><h1>{{ $scope === 'active' ? 'En préparation' : ($scope === 'history' ? 'Historique des commandes' : 'Toutes les commandes') }}</h1><p class="admin-date" data-board-date>{{ $day->locale('fr')->translatedFormat('l j F Y') }}</p></div>
    <div class="admin-toolbar">
        <form class="day-picker" method="get" action="{{ route('admin.orders') }}" aria-label="Consulter une autre journée">
            <input type="hidden" name="view" value="{{ $scope }}">
            <a class="button secondary small icon-only" href="{{ route('admin.orders', ['date' => $day->subDay()->format('Y-m-d'), 'view' => $scope]) }}" aria-label="Jour précédent">←</a>
            <label><span class="sr-only">Date du service</span><input type="date" name="date" value="{{ $date }}" required></label>
            <button class="button secondary small">Afficher</button>
            <a class="button secondary small icon-only" href="{{ route('admin.orders', ['date' => $day->addDay()->format('Y-m-d'), 'view' => $scope]) }}" aria-label="Jour suivant">→</a>
            @if($date !== $today)<a class="button small" href="{{ route('admin.orders', ['view' => $scope]) }}">Aujourd’hui</a>@endif
        </form>
        <nav class="segmented" aria-label="Commandes affichées">
            @foreach(['active' => 'En préparation', 'history' => 'Historique', 'all' => 'Toutes'] as $view => $label)
                <a href="{{ route('admin.orders', ['date' => $date, 'view' => $view]) }}" @class(['active' => $scope === $view]) @if($scope === $view) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</div>
<div class="board-layout">
    <section class="board-feed" data-feed-url="{{ route('admin.feed', request()->only('date', 'view', 'slot')) }}">
        <div class="live-strip">
            <p class="live-status" data-feed-message aria-live="polite">Actualisation automatique toutes les 3 secondes.</p>
            @if($scope === 'active')
                <div class="sound-control"><button type="button" class="button secondary small" data-order-sound aria-pressed="false">Activer le son</button><p class="muted" data-order-sound-status aria-live="polite">Activez le son pour entendre les nouvelles commandes de la journée affichée. Gardez cette page ouverte.</p></div>
            @endif
        </div>
        @if(request()->filled('slot'))<p class="notice">Commandes d’un seul créneau. <a href="{{ route('admin.orders', ['date' => $date, 'view' => $scope]) }}">Afficher tous les créneaux</a></p>@endif
        <div class="rush-board" data-board-orders>@include('restaurant.admin.order-cards')</div>
    </section>
    <aside class="board-side">
        <section class="panel print-panel" aria-labelledby="print-heading">
            <h2 id="print-heading">Impression des commandes</h2>
            @if($printingConfigured)
                <p>Les commandes en préparation du jour sont envoyées automatiquement lorsque le poste d’impression est connecté. <strong>{{ $printPending }} ticket(s)</strong> en attente ou en cours d’envoi.</p>
                <form method="post" action="{{ route('admin.orders.print') }}">@csrf<input type="hidden" name="batch" value="{{ \Illuminate\Support\Str::uuid() }}"><button class="button full">Imprimer toutes les commandes du jour</button></form>
                <p class="muted">Cette action imprime aussi les commandes déjà imprimées et les commandes annulées, identifiées comme telles.</p>
            @else
                <p>Connectez le poste d’impression pour activer l’envoi automatique à votre imprimante Wi-Fi.</p>
                <a class="button full" href="{{ route('admin.orders.tickets', ['print' => 1]) }}" target="_blank" rel="noopener">Imprimer toutes les commandes du jour</a>
            @endif
            <a class="text-button" href="{{ route('admin.orders.tickets') }}" target="_blank" rel="noopener">Aperçu des tickets · 58 / 80 mm</a>
            @foreach($printIssues as $issue)
                <div class="notice error"><strong>Ticket #{{ $issue->order_id }} : envoi à vérifier</strong><p>{{ $issue->error }}</p>
                    <form method="post" action="{{ route('admin.printing.retry', $issue->id) }}">@csrf<button class="button secondary small">J’ai vérifié l’imprimante : relancer ce ticket</button></form>
                </div>
            @endforeach
        </section>
        <details class="panel"><summary>Notifications sur cet appareil</summary><button class="button secondary full" data-enable-push data-key="{{ config('restaurant.vapid.public_key') }}" data-url="{{ route('admin.push.store') }}" @disabled(!$pushConfigured)>Activer les notifications sur cet appareil</button><p data-push-status aria-live="polite"></p></details>
    </aside>
</div>
</x-restaurant-layout>
