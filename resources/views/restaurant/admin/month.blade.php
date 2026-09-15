<section class="panel month-panel">
    <div class="section-heading">
        <h2>{{ ucfirst($month->locale('fr')->translatedFormat('F Y')) }}</h2>
        <div class="inline-form">
            <a class="button small secondary" href="{{ route('admin.calendar', ['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="Mois précédent">←</a>
            <a class="button small secondary" href="{{ route('admin.calendar', ['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="Mois suivant">→</a>
        </div>
    </div>
    <div class="month-grid">
        @foreach(['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $weekday)<span class="weekday">{{ $weekday }}</span>@endforeach
        @foreach($days as $day)
            <a href="{{ route('admin.slots', ['date' => $day['date']]) }}" @class(['month-day', 'open' => $day['hours']['open'], 'outside' => !$day['in_month']]) aria-label="{{ $day['date'] }} : {{ $day['hours']['open'] ? 'ouvert '.$day['hours']['start'].' à '.$day['hours']['end'] : 'fermé' }}">
                <strong>{{ $day['number'] }}</strong>
                <span>{{ $day['hours']['open'] ? 'Ouvert' : 'Fermé' }}</span>
                @if($day['hours']['open'])<small>{{ $day['hours']['start'] }}–{{ $day['hours']['end'] }}</small>@endif
            </a>
        @endforeach
    </div>
    <p class="muted">Sélectionnez une journée pour consulter ses créneaux et les commandes déjà enregistrées.</p>
</section>
