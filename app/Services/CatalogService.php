<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Menu;
use App\Models\Offer;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    /** @return class-string<CatalogItem> */
    public function model(string $type): string
    {
        return match ($type) {
            'product' => Product::class,
            'menu' => Menu::class,
            'offer' => Offer::class,
            default => throw ValidationException::withMessages(['cart' => 'Type de produit invalide.']),
        };
    }

    /** @return array<int, array{name: string, quantity: int}> */
    public function composition(CatalogItem $item, bool $validate = false, ?CarbonImmutable $pickup = null): array
    {
        if ($item instanceof Product) {
            return [['name' => $item->displayName(), 'quantity' => 1, 'product_id' => $item->id]];
        }
        $rows = DB::table($item->kind().'_items')->where($item->kind().'_id', $item->id)->get();
        if ($validate && $rows->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'La composition de « '.$item->name.' » est indisponible.']);
        }
        $composition = [];
        $products = Product::withTrashed()->with('parent')->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        foreach ($rows as $row) {
            $product = $products->get($row->product_id);
            if ($validate && (! $product || ! $this->productAvailable($product, $item instanceof Offer, $pickup))) {
                throw ValidationException::withMessages(['cart' => 'Un produit de « '.$item->name.' » est indisponible.']);
            }
            $composition[] = ['name' => $product?->displayName() ?? 'Produit archivé', 'quantity' => (int) $row->quantity, 'product_id' => $product?->id];
        }

        return $composition;
    }

    public function available(CatalogItem $item, ?CarbonImmutable $pickup = null): bool
    {
        if ($item instanceof Product) {
            return $this->productAvailable($item, false, $pickup);
        }
        if (! $item->available || $item->trashed() || ! $item->availableOn($pickup)) {
            return false;
        }
        if ($item instanceof Offer) {
            $now = CarbonImmutable::now();
            if ($now->lt($item->starts_at) || ($item->ends_at && $now->gt($item->ends_at))
                || ($pickup && ($pickup->lt($item->starts_at) || ($item->ends_at && $pickup->gt($item->ends_at))))) {
                return false;
            }
        }
        try {
            $this->composition($item, true, $pickup);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    public function productAvailable(Product $product, bool $insideOffer = false, ?CarbonImmutable $pickup = null): bool
    {
        if ($product->trashed() || ! $product->available || $product->has_variants || ! $product->availableOn($pickup)) {
            return false;
        }
        if ($product->offer_only && ! $insideOffer) {
            return false;
        }
        if ($product->parent_id !== null) {
            $parent = $product->parent;

            return $parent !== null && ! $parent->trashed() && $parent->available && $parent->has_variants
                && ($insideOffer || ! $parent->offer_only);
        }

        return true;
    }

    /**
     * Weekdays on which the whole cart can be picked up, including the dishes inside offers, menus and formulas:
     * null when nothing in it is limited, an empty list when no single day suits every item.
     *
     * @param  array<string, int>  $cart
     * @return list<int>|null
     */
    public function cartWeekdays(array $cart): ?array
    {
        $allowed = null;
        foreach (array_keys($cart) as $key) {
            try {
                if (str_starts_with($key, 'composed:')) {
                    $entries = Product::query()->with('parent')->whereIn('id', app(DynamicMenuService::class)->decode($key)['selections'])->get()->all();
                } else {
                    [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
                    $item = $this->model($type)::query()->find($id);
                    $entries = ! $item ? [] : ($item instanceof Product ? [$item] : [$item, ...Product::query()->with('parent')
                        ->whereIn('id', DB::table($item->kind().'_items')->where($item->kind().'_id', $item->id)->pluck('product_id'))->get()->all()]);
                }
            } catch (ValidationException) {
                continue;
            }
            foreach ($entries as $entry) {
                $days = $entry->weekdays();
                if ($days !== null) {
                    $allowed = $allowed === null ? $days : array_values(array_intersect($allowed, $days));
                }
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string, int>  $cart
     * @return array{lines: array<int, array<string, mixed>>, total: int}
     */
    public function quote(array $cart, ?CarbonImmutable $pickup = null): array
    {
        if ($cart === [] || count($cart) > 50) {
            throw ValidationException::withMessages(['cart' => 'Votre panier est vide ou contient trop de références.']);
        }
        $lines = [];
        $total = 0;
        foreach ($cart as $key => $quantity) {
            if (str_starts_with($key, 'composed:')) {
                $line = app(DynamicMenuService::class)->quote($key, $quantity, $pickup);
                $lines[] = $line;
                $total += $quantity * $line['unit_price'];

                continue;
            }
            [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
            $item = $this->model($type)::query()->find($id);
            if (! $item || $quantity < 1 || $quantity > 50 || ! $this->available($item, $pickup)) {
                throw ValidationException::withMessages(['cart' => 'Un article du panier est indisponible pour ce retrait. Modifiez votre panier.']);
            }
            $lines[] = ['item_type' => $type, 'item_id' => $item->id, 'name' => $item instanceof Product ? $item->displayName() : $item->name, 'composition' => $this->composition($item, true, $pickup), 'quantity' => $quantity, 'unit_price' => $item->price];
            $total += $quantity * $item->price;
        }

        return ['lines' => $lines, 'total' => $total];
    }
}
