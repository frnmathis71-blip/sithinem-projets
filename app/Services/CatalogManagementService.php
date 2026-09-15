<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogManagementService
{
    /** @param array<string, mixed> $data */
    public function save(string $kind, ?int $id, array $data): CatalogItem
    {
        return app(RestaurantLock::class)->run(function () use ($kind, $id, $data) {
            $model = app(CatalogService::class)->model($kind);
            $entry = $id ? $model::query()->findOrFail($id) : new $model;
            if ($entry instanceof Product && $entry->parent_id !== null) {
                throw ValidationException::withMessages(['variants' => 'Modifiez cette déclinaison depuis son plat principal.']);
            }
            $variants = $data['variants'] ?? [];
            $composition = array_filter($data['composition'] ?? [], fn ($q) => (int) $q > 0);
            $newProduct = $data['offer_product'] ?? [];
            unset($data['variants'], $data['composition'], $data['offer_product'], $data['create_offer_product'], $data['no_end_date']);
            if ($kind === 'product' && ($data['has_variants'] ?? false)) {
                $prices = array_column($variants, 'price');
                if ($prices === []) {
                    throw ValidationException::withMessages(['variants' => 'Ajoutez au moins une déclinaison.']);
                }
                $data['price'] = min($prices);
            }
            if ($kind === 'product' && str_starts_with((string) ($data['category_id'] ?? ''), 'type:')) {
                $code = substr($data['category_id'], 5);
                $category = Category::query()->where('code', $code)->first();
                if (! $category) {
                    $name = Category::LABELS[$code];
                    while (Category::query()->where('name', $name)->exists()) {
                        $name .= ' · '.$code;
                    }
                    $category = Category::create(['name' => $name, 'code' => $code]);
                }
                $data['category_id'] = $category->id;
            }
            $entry->fill($data)->save();
            if ($entry instanceof Product) {
                if (array_key_exists('restricted_sides', $data)) {
                    $sideIds = array_map('intval', $data['compatible_side_ids'] ?? []);
                    foreach ($sideIds as $sideId) {
                        $side = Product::query()->find($sideId);
                        if (! $side || $side->has_variants || $side->categoryCode() !== 'side') {
                            throw ValidationException::withMessages(['compatible_side_ids' => 'Choisissez uniquement des accompagnements ou leurs déclinaisons.']);
                        }
                    }
                    $entry->update(['compatible_side_ids' => $sideIds]);
                }
                $ids = [];
                foreach (($entry->has_variants ? $variants : []) as $position => $variant) {
                    $child = empty($variant['id']) ? new Product : $entry->variants()->whereKey($variant['id'])->first();
                    if (! $child) {
                        throw ValidationException::withMessages(['variants' => 'Une déclinaison ne correspond pas à ce plat. Rechargez le formulaire.']);
                    }
                    if (array_key_exists('image', $variant)) {
                        $child->image = $variant['image'];
                    }
                    $child->fill(['parent_id' => $entry->id, 'name' => $entry->name, 'variant_label' => $variant['label'], 'price' => $variant['price'], 'available' => $variant['available'], 'offer_only' => $entry->offer_only, 'category_id' => $entry->getAttribute('category_id'), 'position' => $position])->save();
                    $ids[] = $child->id;
                }
                $entry->variants()->whereNotIn('id', $ids)->delete();
            } else {
                if ($kind === 'offer' && $newProduct !== []) {
                    $product = Product::query()->create(['name' => $newProduct['name'], 'description' => $newProduct['description'] ?? null, 'price' => $newProduct['price'], 'available' => true, 'offer_only' => true]);
                    $composition[$product->id] = $newProduct['quantity'];
                }
                $products = Product::query()->with('parent')->whereIn('id', array_keys($composition))->get();
                if ($composition === [] || $products->count() !== count($composition)) {
                    throw ValidationException::withMessages(['composition' => 'Sélectionnez au moins un produit valide ou créez un produit réservé à l’offre.']);
                }
                foreach ($products as $product) {
                    if ($product->has_variants || ($product->parent_id && (! $product->parent || $product->parent->trashed() || ! $product->parent->has_variants))
                        || ($kind !== 'offer' && ($product->offer_only || $product->parent?->offer_only))) {
                        throw ValidationException::withMessages(['composition' => 'Choisissez une déclinaison précise. Les produits réservés aux offres ne peuvent pas être inclus dans un menu classique.']);
                    }
                }
                DB::table($kind.'_items')->where($kind.'_id', $entry->id)->delete();
                foreach ($composition as $product => $quantity) {
                    DB::table($kind.'_items')->insert([$kind.'_id' => $entry->id, 'product_id' => $product, 'quantity' => $quantity]);
                }
            }

            return $entry;
        });
    }

    public function cents(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $price)), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
