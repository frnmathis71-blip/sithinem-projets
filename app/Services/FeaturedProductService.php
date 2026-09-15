<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;

class FeaturedProductService
{
    /** @param Collection<int, Product> $products
     * @return Collection<int, Product>
     */
    public function select(Collection $products): Collection
    {
        $catalog = app(CatalogService::class);
        $eligible = $products->filter(fn (Product $p) => in_array($p->categoryCode(), ['starter', 'main', 'chef_main', 'side'], true)
            && ($p->has_variants ? $p->variants->contains(fn (Product $v) => $catalog->productAvailable($v)) : $catalog->productAvailable($p)))->keyBy('id');
        $parents = Product::withTrashed()->pluck('parent_id', 'id');
        $counts = [];
        foreach (OrderItem::query()->whereIn('order_id', Order::query()->select('id')->where('status', '!=', 'cancelled'))->cursor() as $line) {
            $parts = $line->item_type === 'product' ? [['product_id' => $line->item_id, 'quantity' => 1]] : $line->composition;
            foreach ($parts as $part) {
                $id = $part['product_id'] ?? null;
                $id = $parents[$id] ?? $id;
                if ($id && $eligible->has($id)) {
                    $counts[$id] = ($counts[$id] ?? 0) + $line->quantity * $part['quantity'];
                }
            }
        }
        $ranked = $eligible->filter(fn (Product $p) => ($counts[$p->id] ?? 0) >= config('restaurant.featured_min_units', 3))
            ->sort(fn (Product $a, Product $b) => $counts[$b->id] <=> $counts[$a->id] ?: $a->id <=> $b->id);

        return $ranked->concat($eligible->filter(fn (Product $p) => $p->categoryCode() === 'chef_main' && ! $ranked->has($p->id)))
            ->take(config('restaurant.featured_limit', 6))->values();
    }
}
