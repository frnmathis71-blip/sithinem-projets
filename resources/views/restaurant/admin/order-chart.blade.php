<section class="panel orders-chart-panel">
    <h2>Le nombre de commandes sur 14 jours</h2>
    <div class="bar-chart" role="img" aria-label="Nombre quotidien de commandes ; données détaillées sous le graphique">
        @php($maxCount = max(1, max(array_column($report['chart'], 'count'))))
        @foreach($report['chart'] as $point)
            <div class="bar-column" title="{{ $point['label'] }} : {{ $point['count'] }} commandes">
                <div class="bar" style="height: {{ max(2, $point['count'] / $maxCount * 100) }}%"></div>
                <span>{{ $point['label'] }}</span>
            </div>
        @endforeach
    </div>
    <details><summary>Nombre de commandes par jour</summary><table><thead><tr><th>Jour</th><th>Commandes</th></tr></thead><tbody>@foreach($report['chart'] as $point)<tr><td>{{ $point['label'] }}</td><td>{{ $point['count'] }}</td></tr>@endforeach</tbody></table></details>
</section>
