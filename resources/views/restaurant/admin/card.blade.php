<x-restaurant-layout title="Gestion de la carte" :admin="true">
    <div class="section-heading">
        <div><p class="eyebrow">TOUTE VOTRE CARTE, AU MÊME ENDROIT</p><h1>La carte</h1></div>
        <div class="inline-form">
            <a class="button small" href="{{ route('admin.catalog.create', 'product') }}">Ajouter un plat +</a>
            <a class="button small secondary" href="{{ route('admin.menu-rules') }}">Créer un menu</a>
            <a class="button small secondary" href="{{ route('admin.catalog.create', 'offer') }}">Créer une offre</a>
        </div>
    </div>
    <details id="categories" class="panel card-categories">
        <summary>Modifier les catégories de nourriture</summary>
        <p class="muted">Les déclinaisons d’un plat partagent sa catégorie. L’ordre détermine l’affichage de la carte.</p>
        @foreach($categories as $category)
            <form class="filter-form" method="post" action="{{ route('admin.categories.save') }}">
                @csrf<input type="hidden" name="id" value="{{ $category->id }}">
                <label>Nom de la catégorie<input name="name" value="{{ $category->name }}" required maxlength="255"></label>
                <label>Type de produits<select name="code"><option value="">À classer</option>@foreach(\App\Models\Category::LABELS as $code => $label)<option value="{{ $code }}" @selected(($category->code ?? '') === $code)>{{ $label }}</option>@endforeach</select></label><label>Ordre<input type="number" name="position" value="{{ $category->position }}" min="0" max="999" required></label>
                <button class="button small secondary">Enregistrer</button>
            </form>
            <details class="category-delete">
                <summary>Supprimer la catégorie « {{ $category->name }} »</summary>
                <p>Les produits de cette catégorie seront conservés dans « Sans catégorie » et désactivés, y compris leurs déclinaisons. Reclassez-les avant de les remettre en vente. Les commandes déjà enregistrées sont conservées.</p>
                <form method="post" action="{{ route('admin.categories.delete', $category) }}">
                    @csrf @method('DELETE')
                    <button class="button small secondary">Confirmer la suppression de « {{ $category->name }} »</button>
                </form>
            </details>
        @endforeach
        <form class="filter-form" method="post" action="{{ route('admin.categories.save') }}">
            @csrf<label>Nouvelle catégorie<input name="name" required maxlength="255" placeholder="Entrées, plats, desserts…"></label>
            <label>Type de produits<select name="code"><option value="">À classer</option>@foreach(\App\Models\Category::LABELS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></label><label>Ordre<input type="number" name="position" value="{{ $categories->count() }}" min="0" max="999" required></label>
            <button class="button small">Ajouter la catégorie</button>
        </form>
    </details>
    @foreach($categories->map(fn($category) => ['id' => $category->id, 'name' => $category->name])->push(['id' => null, 'name' => 'Sans catégorie']) as $category)
        @php($group = $products->where('offer_only', false)->where('category_id', $category['id']))
        @if($group->isNotEmpty())
            <section class="card-group"><div class="section-heading"><h2>{{ $category['name'] }}</h2><span class="muted">{{ $group->count() }} plat(s)</span></div><div class="order-list">@foreach($group as $product)@include('restaurant.admin.product-row')@endforeach</div></section>
        @endif
    @endforeach
    @if($products->where('offer_only', false)->isEmpty())<div class="empty-state"><h2>Ajoutez votre premier plat</h2><p>Vous pourrez ensuite lui ajouter des tailles, garnitures ou variantes.</p></div>@endif
    <section class="panel"><h2>Les formules de menu</h2><p>Vous choisissez les catégories et le prix de chaque formule. Vos clients personnalisent uniquement les produits.</p><a class="button" href="{{ route('admin.menu-rules') }}">Gérer les formules et leurs prix</a></section>
    <section class="card-group">
        <div class="section-heading"><h2>Les offres</h2><a href="{{ route('admin.catalog.create', 'offer') }}">Créer une offre +</a></div>
        <div class="order-list">@forelse($offers as $offer)<a class="panel order-summary" href="{{ route('admin.catalog.edit', ['offer', $offer->id]) }}"><div><h3>{{ $offer->name }}</h3><p>{{ $offer->ends_at ? 'Jusqu’au '.$offer->ends_at->timezone('Europe/Paris')->format('d/m/Y à H:i') : 'Sans date de fin' }}</p><span class="badge">{{ $offer->available ? 'Activée' : 'Indisponible' }}</span></div><strong>{{ number_format($offer->price / 100, 2, ',', ' ') }} € · Modifier ↗</strong></a>@empty<p class="muted">Créez une offre à partir de vos plats ou d’un produit réservé à cette occasion.</p>@endforelse</div>
    </section>
    @if($products->where('offer_only', true)->isNotEmpty())<section class="card-group"><h2>Produits réservés aux offres</h2><p class="muted">Ces produits sont absents de la carte classique. Ils sont disponibles uniquement dans la composition d’une offre.</p><div class="order-list">@foreach($products->where('offer_only', true) as $product)@include('restaurant.admin.product-row')@endforeach</div></section>@endif
</x-restaurant-layout>
