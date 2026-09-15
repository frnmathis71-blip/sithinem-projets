<x-restaurant-layout title="Éditer la carte" :admin="true">
    <div class="section-heading"><div><p class="eyebrow">LE SOIN DU DÉTAIL</p><h1>{{ $item->exists ? 'Modifier '.$item->name : ['product' => 'Nouveau plat', 'menu' => 'Nouveau menu', 'offer' => 'Nouvelle offre'][$kind] }}</h1></div><a href="{{ route('admin.card') }}">← Retour à la carte</a></div>
    <form method="post" action="{{ route('admin.catalog.save', [$kind, $item->id]) }}" enctype="multipart/form-data" class="checkout-grid">
        @csrf
        <section class="panel">
            <h2>Présentation</h2>
            <label>Nom<input name="name" value="{{ old('name', $item->name) }}" required maxlength="255"></label>
            <label>Description<textarea name="description" rows="4" maxlength="5000">{{ old('description', $item->description) }}</textarea></label>
            <div class="two-fields">
                <label>Prix en euros<input name="price" inputmode="decimal" value="{{ old('price', $item->exists ? number_format($item->price / 100, 2, '.', '') : '') }}" placeholder="12,50" data-base-price @required(!old('has_variants', $item->has_variants ?? false))></label>
                <label>Disponibilité<select name="available"><option value="1" @selected(old('available', $item->available ?? true))>Disponible</option><option value="0" @selected(!old('available', $item->available ?? true))>Indisponible</option></select></label>
            </div>
            @if($kind === 'product')
                <label>Catégorie<select name="category_id"><option value="">Sans catégorie</option>@foreach(\App\Models\Category::LABELS as $code => $label)@php($typed = $categories->firstWhere('code', $code))@php($value = $typed?->id ?? 'type:'.$code)<option value="{{ $value }}" @selected((string) old('category_id', $item->category_id) === (string) $value)>{{ $label }}</option>@endforeach<optgroup label="Catégories personnalisées">@foreach($categories->filter(fn($category) => !$category->code || $categories->firstWhere('code', $category->code)?->id !== $category->id) as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $item->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</optgroup></select></label>
                <input type="hidden" name="offer_only" value="0">
                <label class="checkbox-label"><input type="checkbox" name="offer_only" value="1" @checked(old('offer_only', $item->offer_only ?? false))> Réserver ce produit aux offres</label>
                <p class="muted">Il ne sera pas vendu seul ni inclus dans un menu classique. Ce choix s’applique aussi à ses déclinaisons.</p>
            @endif
            <label>Photo · JPG, PNG ou WebP · 20 Mo maximum · compression automatique<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            @if($item->image)<img class="edit-image" src="{{ Storage::disk('public')->url($item->image) }}" alt="Photo actuelle"><label class="checkbox-label"><input type="checkbox" name="remove_image" value="1"> Retirer la photo</label>@endif
            <button class="button full">Enregistrer {{ ['product' => 'le plat', 'menu' => 'le menu', 'offer' => 'l’offre'][$kind] }}</button>
        </section>
        <section class="panel">
            @if($kind === 'product')
                <h2>Déclinaisons du plat</h2>
                <details class="panel"><summary>Accompagnements du plat classique</summary><p>Dans les menus, le plat classique exige un accompagnement. À la carte, il est facultatif. Le plat du chef inclut déjà sa garniture et ignore ces restrictions. Ce réglage est partagé par les déclinaisons.</p>
                <input type="hidden" name="restricted_sides" value="0"><label class="checkbox-label"><input type="checkbox" name="restricted_sides" value="1" @checked(old('restricted_sides', $item->restricted_sides))> Limiter aux accompagnements cochés</label>
                @foreach($products->filter(fn($p) => $p->categoryCode() === 'side') as $side)<label class="checkbox-label"><input type="checkbox" name="compatible_side_ids[]" value="{{ $side->id }}" @checked(in_array($side->id, old('compatible_side_ids', $item->compatible_side_ids ?? [])))> {{ $side->displayName() }}</label>@endforeach
                <p class="muted">Restriction activée sans choix : le plat sera uniquement disponible seul à la carte.</p></details>
                <input type="hidden" name="has_variants" value="0">
                <label class="checkbox-label"><input type="checkbox" name="has_variants" value="1" data-variants-toggle @checked(old('has_variants', $item->has_variants ?? false))> Proposer plusieurs déclinaisons</label>
                <p class="muted">Exemples : « Petite », « Grande », « Poulet », « Grand · légumes ». Chaque déclinaison a son propre prix. Le client choisit une déclinaison avant de l’ajouter au panier.</p>
                @php($variantRows = old('variants', $item->exists ? $item->variants->map(fn($variant) => ['id' => $variant->id, 'label' => $variant->variant_label, 'price' => number_format($variant->price / 100, 2, '.', ''), 'available' => $variant->available ? '1' : '0', 'image' => $variant->image])->all() : []))
                @php($variantRows = $variantRows ?: [['id' => '', 'label' => '', 'price' => '', 'available' => '1']])
                <div data-variants-panel>
                    <div data-variant-rows>@foreach($variantRows as $index => $variant)@include('restaurant.admin.variant-fields', ['index' => $index, 'variant' => $variant])@endforeach</div>
                    <button type="button" class="button secondary small" data-add-variant>Ajouter une déclinaison +</button>
                    <p class="muted">Retirer une déclinaison la désactive pour les prochains achats. Les commandes existantes sont conservées.</p>
                </div>
                <template data-variant-template>@include('restaurant.admin.variant-fields', ['index' => '__INDEX__', 'variant' => ['id' => '', 'label' => '', 'price' => '', 'available' => '1']])</template>
            @else
                <h2>Composition</h2>
                <p class="muted">Choisissez les produits et leurs quantités. Pour un plat à déclinaisons, sélectionnez la déclinaison précise.</p>
                @foreach($products as $product)<label class="composition-row"><span>{{ $product->displayName() }}{{ $product->offer_only ? ' · réservé aux offres' : '' }}{{ !$product->available ? ' · indisponible' : '' }}</span><input class="quantity" name="composition[{{ $product->id }}]" type="number" min="0" max="50" value="{{ old('composition.'.$product->id, $composition[$product->id] ?? 0) }}"></label>@endforeach
                @if($kind === 'offer')
                    <div class="offer-product-create">
                        <input type="hidden" name="create_offer_product" value="0">
                        <label class="checkbox-label"><input type="checkbox" name="create_offer_product" value="1" data-offer-product-toggle @checked(old('create_offer_product', false))> Créer un produit spécialement pour cette offre</label>
                        <div data-offer-product-fields>
                            <p class="muted">Ce nouveau produit ne sera pas ajouté à la carte classique. Il sera inclus dans cette offre à l’enregistrement.</p>
                            <label>Nom du produit spécifique<input name="offer_product[name]" value="{{ old('offer_product.name') }}" maxlength="255"></label>
                            <label>Description du produit spécifique<textarea name="offer_product[description]" rows="2" maxlength="5000">{{ old('offer_product.description') }}</textarea></label>
                            <div class="two-fields"><label>Prix de référence en euros<input name="offer_product[price]" value="{{ old('offer_product.price') }}" inputmode="decimal" placeholder="8,50"></label><label>Quantité incluse<input name="offer_product[quantity]" type="number" min="1" max="50" value="{{ old('offer_product.quantity', 1) }}"></label></div>
                            <p class="muted">Le prix facturé reste le prix global de l’offre.</p>
                        </div>
                    </div>
                    <h2 class="mt-6">Période de validité</h2>
                    <label>Début<input type="datetime-local" name="starts_at" required value="{{ old('starts_at', $item->starts_at?->timezone('Europe/Paris')->format('Y-m-d\TH:i') ?? now('Europe/Paris')->format('Y-m-d\TH:i')) }}"></label>
                    <input type="hidden" name="no_end_date" value="0">
                    <label class="checkbox-label"><input type="checkbox" name="no_end_date" value="1" data-no-end-date @checked(old('no_end_date', $item->exists && $item->ends_at === null))> Pas de date de fin</label>
                    <label>Fin<input type="datetime-local" name="ends_at" data-offer-end @required(!old('no_end_date', $item->exists && $item->ends_at === null)) value="{{ old('ends_at', $item->ends_at?->timezone('Europe/Paris')->format('Y-m-d\TH:i')) }}"></label>
                    <p class="muted">Heure de Paris. Vous pouvez arrêter une offre sans date de fin en la rendant indisponible.</p>
                @endif
            @endif
        </section>
    </form>
    @if($item->exists)<form class="mt-6" method="post" action="{{ route('admin.catalog.archive', [$kind, $item->id]) }}">@csrf @method('DELETE')<button class="text-button">Archiver cet article</button><p class="muted">Il ne sera plus proposé à la vente. Les anciennes commandes seront conservées.</p></form>@endif
</x-restaurant-layout>
