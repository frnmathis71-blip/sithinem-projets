<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tickets de commande · Sithinem</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eee; color: #000; font: 12px/1.4 monospace; }
        .toolbar { padding: 20px; font-family: system-ui, sans-serif; }
        .toolbar button, .toolbar select { min-height: 44px; padding: 10px 18px; font: inherit; }
        .ticket { width: {{ $width }}mm; padding: 4mm; margin: 20px auto; background: #fff; overflow-wrap: anywhere; }
        h1 { font-size: 24px; line-height: 1.2; margin: 8px 0; }
        h2 { font-size: 14px; margin: 12px 0 4px; }
        p { margin: 4px 0; }
        header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; }
        ul { list-style: none; padding: 0; margin: 10px 0; }
        li { margin: 8px 0; break-inside: avoid; }
        li ul { padding-left: 10px; margin: 4px 0; }
        li li { margin: 3px 0; }
        .comment { border-top: 1px dashed #000; padding-top: 8px; white-space: pre-wrap; }
        @page { margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .ticket { margin: 0; break-after: page; page-break-after: always; }
            .ticket:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <form method="get">
            <label>Largeur du ticket <select name="width" onchange="this.form.submit()"><option value="80" @selected($width === 80)>80 mm</option><option value="58" @selected($width === 58)>58 mm</option></select></label>
            <button type="button" onclick="window.print()" @disabled($orders->isEmpty())>Imprimer les tickets</button>
        </form>
        <p>Sélectionnez l’imprimante thermique et son format rouleau, avec une échelle de 100 %, sans en-têtes ni pieds de page.</p>
        @if($orders->isEmpty())<p>Aucune commande à imprimer aujourd’hui.</p>@endif
    </div>
    @foreach($orders as $order)
        <article class="ticket" data-ticket="{{ $order->id }}">
            <header>
                <strong>SITHINEM</strong>
                <h1>Commande #{{ $order->id }}</h1>
                <p>{{ $order->number }}</p>
                <p>{{ $order->slot->date->format('d/m/Y') }} · Retrait {{ substr($order->slot->starts_at, 0, 5) }}</p>
                <p>{{ $order->customer_name }}</p>
                <strong>{{ \App\Models\Order::STATUSES[$order->status] }}</strong>
            </header>
            <ul>
                @foreach($order->items as $item)
                    <li><strong>{{ $item->quantity }} × {{ $item->name }}</strong>
                        @if($item->item_type !== 'product' && $item->composition)
                            <ul>@foreach($item->composition as $part)<li>{{ $part['quantity'] }} × {{ $part['name'] }} (par menu)</li>@endforeach</ul>
                        @endif
                    </li>
                @endforeach
            </ul>
            <h2>Commentaire client</h2>
            <p class="comment">{{ $order->customer_message ?: 'Aucun commentaire.' }}</p>
        </article>
    @endforeach
    @if(request()->boolean('print') && $orders->isNotEmpty())
        <script>window.addEventListener('load', () => window.print(), { once: true });</script>
    @endif
</body>
</html>
