<?php

namespace App\Services;

use App\Models\MenuRule;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DynamicMenuService
{
    public const CATEGORIES = ['starter', 'main', 'chef_main', 'dessert', 'drink'];

    /** @param array<int, string> $categories */
    public function categoryKey(array $categories): string
    {
        return implode(',', array_values(array_intersect(self::CATEGORIES, $categories)));
    }

    /** @param array<int, string> $categories */
    public function validCategories(array $categories): bool
    {
        return $categories !== [] && count($categories) === count(array_unique($categories))
            && array_diff($categories, self::CATEGORIES) === []
            && ! (in_array('main', $categories, true) && in_array('chef_main', $categories, true));
    }

    /** @param array<string, int> $selections */
    public function key(array $selections, ?int $ruleId = null): string
    {
        ksort($selections);

        return 'composed:'.rtrim(strtr(base64_encode(json_encode(['selections' => $selections, 'rule_id' => $ruleId, 'uuid' => (string) Str::uuid()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{selections: array<string, int>, rule_id: int|null, uuid: string} */
    public function decode(string $key): array
    {
        $raw = base64_decode(strtr(substr($key, 9), '-_', '+/'), true);
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data) || ! isset($data['selections'], $data['uuid']) || ! is_array($data['selections']) || ! is_string($data['uuid']) || ! Str::isUuid($data['uuid']) || (isset($data['rule_id']) && ! is_int($data['rule_id']))) {
            $this->invalid();
        }
        foreach ($data['selections'] as $code => $id) {
            if (! in_array($code, ['starter', 'main', 'chef_main', 'side', 'dessert', 'drink'], true) || ! is_int($id) || $id < 1) {
                $this->invalid();
            }
        }

        return ['selections' => $data['selections'], 'rule_id' => $data['rule_id'] ?? null, 'uuid' => $data['uuid']];
    }

    /** @return array<string, mixed> */
    public function quote(string $key, int $quantity): array
    {
        $data = $this->decode($key);
        $selections = $data['selections'];
        if ($quantity < 1 || $quantity > 50 || $selections === [] || (isset($selections['main']) !== isset($selections['side'])) || (isset($selections['main']) && isset($selections['chef_main']))) {
            $this->invalid();
        }
        $catalog = app(CatalogService::class);
        $products = [];
        $composition = [];
        $sum = 0;
        foreach (['starter', 'main', 'chef_main', 'side', 'dessert', 'drink'] as $code) {
            if (! isset($selections[$code])) {
                continue;
            }
            $product = Product::query()->find($selections[$code]);
            if (! $product || ! $catalog->productAvailable($product) || $product->categoryCode() !== $code) {
                $this->invalid();
            }
            $products[$code] = $product;
            $sum += $product->price;
            $composition[] = ['name' => $product->displayName(), 'quantity' => 1, 'product_id' => $product->id, 'category' => $code, 'price' => $product->price, 'includes_side' => $product->includesSide()];
        }
        if (isset($products['main']) && (! isset($products['side']) || ! $products['main']->acceptsSide($products['side']))) {
            $this->invalid();
        }
        $rule = null;
        if ($data['rule_id'] !== null) {
            $rule = MenuRule::query()->find($data['rule_id']);
            if (! $rule || ! $rule->available || $rule->category_key !== $this->categoryKey(array_keys($selections)) || ! $this->validCategories($rule->categories())) {
                $this->invalid();
            }
        } elseif (array_keys($products) !== ['main', 'side']) {
            $this->invalid();
        }

        $main = $products['main'] ?? null;
        if ($rule === null && $main === null) {
            $this->invalid();
        }

        return ['item_type' => $rule ? 'dynamic_menu' : 'paired_product', 'item_id' => $rule ? $rule->id : $main->id, 'instance_uuid' => $data['uuid'], 'name' => $rule ? $rule->label() : $main->displayName(), 'composition' => $composition, 'quantity' => $quantity, 'unit_price' => $rule ? $rule->price : $sum, 'pricing_snapshot' => ['rule_id' => $rule?->id, 'version' => $rule?->version, 'a_la_carte' => $sum, 'categories' => array_keys($products)]];
    }

    /** @return array<int, array<string, mixed>> */
    public function options(): array
    {
        return Product::query()->with('parent')->where('available', true)->where('has_variants', false)->get()
            ->filter(fn (Product $p) => app(CatalogService::class)->productAvailable($p))
            ->map(function (Product $p) {
                $image = $p->image ?: $p->parent?->image;

                return ['group_id' => $p->parent_id ?? $p->id, 'group_name' => $p->parent_id ? $p->parent->name : $p->name, 'variant_name' => $p->variant_label, 'prix_supplement' => $p->parent ? max(0, $p->price - $p->parent->price) : 0, 'group_image' => ($p->parent?->image ?: $image) ? Storage::disk('public')->url($p->parent?->image ?: $image) : asset('images/product-placeholder.svg'), 'id' => $p->id, 'name' => $p->displayName(), 'code' => $p->categoryCode(), 'price' => $p->price, 'image_url' => $image ? Storage::disk('public')->url($image) : asset('images/product-placeholder.svg'), 'has_photo' => (bool) $image, 'side_ids' => $this->sides($p)];
            })->values()->all();
    }

    /** @return array<int, int> */
    public function sides(Product $product): array
    {
        if (! $product->requiresSide()) {
            return [];
        }

        return Product::query()->with('parent')->where('available', true)->where('has_variants', false)->get()->filter(fn (Product $side) => $product->acceptsSide($side) && app(CatalogService::class)->productAvailable($side))->pluck('id')->all();
    }

    /** @param array<string, int> $cart
     * @param  array<int, string>  $refused
     * @return array<string, mixed>|null
     */
    public function suggestion(array $cart, array $refused = []): ?array
    {
        $candidates = [];
        $mains = [];
        $sides = [];
        foreach ($cart as $key => $quantity) {
            try {
                if (str_starts_with($key, 'composed:')) {
                    $data = $this->decode($key);
                    if ($data['rule_id'] !== null) {
                        continue;
                    }
                    $line = $this->quote($key, $quantity);
                    $candidates['main'][] = ['key' => $key, 'keys' => [$key], 'selections' => $data['selections'], 'price' => $line['unit_price']];
                } elseif (str_starts_with($key, 'product:') && $quantity > 0) {
                    $product = Product::query()->find(substr($key, 8));
                    if ($product && app(CatalogService::class)->productAvailable($product)) {
                        if ($product->categoryCode() === 'main') {
                            $mains[$key] = $product;
                        }
                        if ($product->categoryCode() === 'side') {
                            $sides[$key] = $product;
                        }
                    }
                    if ($product && app(CatalogService::class)->productAvailable($product) && in_array($product->categoryCode(), ['starter', 'chef_main', 'dessert', 'drink'], true)) {
                        $candidates[$product->categoryCode()][] = ['key' => $key, 'keys' => [$key], 'selections' => [$product->categoryCode() => $product->id], 'price' => $product->price];
                    }
                }
            } catch (ValidationException) {
                continue;
            }
        }
        foreach ($mains as $mainKey => $main) {
            foreach ($sides as $sideKey => $side) {
                if ($main->acceptsSide($side)) {
                    $candidates['main'][] = ['key' => $mainKey.'|'.$sideKey, 'keys' => [$mainKey, $sideKey], 'selections' => ['main' => $main->id, 'side' => $side->id], 'price' => $main->price + $side->price];
                }
            }
        }
        $best = null;
        foreach (MenuRule::query()->where('available', true)->orderBy('id')->get() as $rule) {
            if (! $this->validCategories($rule->categories())) {
                continue;
            }
            $groups = [];
            foreach (explode(',', $rule->category_key) as $code) {
                if (empty($candidates[$code])) {
                    continue 2;
                }
                $group = $candidates[$code];
                usort($group, fn ($a, $b) => $b['price'] <=> $a['price'] ?: strcmp($a['key'], $b['key']));
                $groups[] = $group;
            }
            // Best-first search: at most one more candidate than remembered refusals.
            $queue = new \SplPriorityQueue;
            $start = array_fill(0, count($groups), 0);
            $priority = fn ($indices) => array_sum(array_map(fn ($group, $index) => $group[$index]['price'], $groups, $indices));
            $queue->insert($start, $priority($start));
            $seen = [implode(',', $start) => true];
            while (! $queue->isEmpty()) {
                $indices = $queue->extract();
                $selected = array_map(fn ($group, $index) => $group[$index], $groups, $indices);
                $sum = array_sum(array_column($selected, 'price'));
                if ($sum <= $rule->price) {
                    break;
                }
                $keys = array_merge(...array_column($selected, 'keys'));
                $signature = hash('sha256', json_encode([$keys, $rule->id, $rule->version, $sum, $rule->price], JSON_THROW_ON_ERROR));
                if (! in_array($signature, $refused, true)) {
                    $selections = array_merge(...array_column($selected, 'selections'));
                    $key = $this->key($selections, $rule->id);
                    $proposal = ['signature' => $signature, 'keys' => $keys, 'key' => $key, 'before' => $sum, 'after' => $rule->price, 'saving' => $sum - $rule->price, 'line' => $this->quote($key, 1)];
                    if ($best === null || $proposal['saving'] > $best['saving']) {
                        $best = $proposal;
                    }
                    break;
                }
                foreach ($indices as $axis => $index) {
                    $next = $indices;
                    $next[$axis]++;
                    $identity = implode(',', $next);
                    if (isset($groups[$axis][$index + 1]) && ! isset($seen[$identity])) {
                        $seen[$identity] = true;
                        $queue->insert($next, $priority($next));
                    }
                }
            }
        }

        return $best;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['cart' => 'Composition indisponible ou incomplète. Vérifiez les catégories et l’accompagnement obligatoire.']);
    }
}
