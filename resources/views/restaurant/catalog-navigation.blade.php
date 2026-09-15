<nav class="category-links" aria-label="Catégories de la carte">
<a href="{{ route('menu') }}#carte" @class(['active' => $type === 'product' && !request('category')])>Tout voir</a>
@foreach($categories as $category)<a href="{{ route('menu', ['category' => $category->id]) }}#carte" @class(['active' => $type === 'product' && (string) request('category') === (string) $category->id])>{{ $category->name }}</a>@endforeach
<a href="{{ route('menus.index') }}" @class(['active' => $type === 'menu'])>Menus</a><a href="{{ route('offers') }}" @class(['active' => $type === 'offer'])>Offres</a>
</nav>
