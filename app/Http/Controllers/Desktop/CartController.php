<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Offer;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\SlotService;
use App\Support\DesktopPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Order taking: the cart lives in the application and is priced by the server on every change. */
class CartController extends Controller
{
    private const KEY = ['required', 'string', 'max:3000', 'regex:/^(?:(?:product|offer):\d+|composed:[A-Za-z0-9_-]+)$/'];

    public function catalog(CatalogService $catalog, DynamicMenuService $menus): JsonResponse
    {
        $unit = fn (Product $product) => [
            'id' => $product->id, 'name' => $product->displayName(), 'label' => $product->variant_label, 'price' => $product->price,
            'available' => $catalog->productAvailable($product), 'code' => $product->categoryCode(),
            'side_ids' => $product->requiresSide() ? $menus->sides($product) : [],
            'image' => DesktopPresenter::image($product->image ?: $product->parent?->image),
        ];
        $products = Product::query()->with(['variants.parent'])->whereNull('parent_id')->where('offer_only', false)->where('available', true)->orderBy('name')->get()
            ->map(fn (Product $product) => [
                'id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'category_id' => $product->getAttribute('category_id'),
                'image' => DesktopPresenter::image($product->image), 'has_variants' => $product->has_variants,
                'options' => $product->has_variants ? $product->variants->map($unit)->values()->all() : [$unit($product)],
            ])->filter(fn ($product) => collect($product['options'])->contains('available', true))->values();
        $offers = Offer::query()->where('available', true)->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->orderBy('name')->get()
            ->filter(fn (Offer $offer) => $catalog->available($offer))
            ->map(fn (Offer $offer) => ['id' => $offer->id, 'name' => $offer->name, 'description' => $offer->description, 'price' => $offer->price, 'image' => DesktopPresenter::image($offer->image),
                'ends_at' => $offer->ends_at?->timezone('Europe/Paris')->format('d/m/Y à H:i'),
                'composition' => array_map(fn ($part) => ['name' => $part['name'], 'quantity' => $part['quantity']], $catalog->composition($offer))])->values();
        $formulas = MenuRule::query()->where('available', true)->orderBy('position')->orderBy('id')->get()
            ->filter(fn (MenuRule $rule) => $menus->validCategories($rule->categories()))
            ->map(fn (MenuRule $rule) => ['id' => $rule->id, 'name' => $rule->label(), 'description' => $rule->description, 'price' => $rule->price, 'categories' => $rule->categoryLabel(), 'steps' => $rule->steps(), 'visible' => $rule->visible])->values();
        $options = collect($menus->options())->map(fn ($option) => ['id' => $option['id'], 'name' => $option['name'], 'code' => $option['code'], 'price' => $option['price'], 'side_ids' => $option['side_ids'], 'image' => $option['has_photo'] ? $option['image_url'] : null])->values();

        return response()->json([
            'categories' => [...Category::query()->orderBy('position')->orderBy('id')->get()->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name, 'code' => $category->getAttribute('code')])->all(), ['id' => null, 'name' => 'Sans catégorie', 'code' => null]],
            'labels' => Category::LABELS, 'products' => $products, 'offers' => $offers, 'formulas' => $formulas, 'options' => $options,
        ]);
    }

    public function quote(Request $request, CatalogService $catalog, DynamicMenuService $menus): JsonResponse
    {
        $data = $request->validate(['items' => 'present|array|max:50', 'items.*.key' => self::KEY, 'items.*.quantity' => 'required|integer|min:1|max:50', 'refused' => 'nullable|array|max:100', 'refused.*' => 'string|size:64']);
        $cart = self::cart($data['items']);
        $lines = [];
        $total = 0;
        $valid = $cart !== [];
        foreach ($cart as $key => $quantity) {
            try {
                $line = $catalog->quote([$key => $quantity])['lines'][0];
                $lines[] = ['key' => $key, 'name' => $line['name'], 'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'available' => true,
                    'composition' => $line['item_type'] === 'product' ? [] : array_map(fn ($part) => ['name' => $part['name'], 'quantity' => $part['quantity']], $line['composition'])];
                $total += $line['unit_price'] * $quantity;
            } catch (ValidationException) {
                $valid = false;
                $lines[] = ['key' => $key, 'name' => 'Article indisponible — à retirer', 'quantity' => $quantity, 'unit_price' => 0, 'available' => false, 'composition' => []];
            }
        }
        $suggestion = $valid ? $menus->suggestion($cart, $data['refused'] ?? []) : null;

        return response()->json(['lines' => $lines, 'total' => $total, 'valid' => $valid,
            'suggestion' => $suggestion ? ['signature' => $suggestion['signature'], 'name' => $suggestion['line']['name'], 'before' => $suggestion['before'], 'after' => $suggestion['after'], 'saving' => $suggestion['saving']] : null]);
    }

    public function compose(Request $request, DynamicMenuService $menus): JsonResponse
    {
        $data = $request->validate(['rule_id' => 'nullable|integer|min:1', 'selections' => 'required|array:starter,main,chef_main,side,dessert,drink', 'selections.*' => 'nullable|integer|min:1']);
        $key = $menus->key(array_map('intval', array_filter($data['selections'], fn ($id) => $id !== null)), isset($data['rule_id']) ? (int) $data['rule_id'] : null);
        $menus->quote($key, 1);

        return response()->json(['key' => $key]);
    }

    public function acceptSuggestion(Request $request, DynamicMenuService $menus): JsonResponse
    {
        $data = $request->validate(['items' => 'required|array|max:50', 'items.*.key' => self::KEY, 'items.*.quantity' => 'required|integer|min:1|max:50', 'signature' => 'required|string|size:64', 'refused' => 'nullable|array|max:100', 'refused.*' => 'string|size:64']);
        $cart = self::cart($data['items']);
        $proposal = $menus->suggestion($cart, $data['refused'] ?? []);
        if (! $proposal || $proposal['signature'] !== $data['signature']) {
            throw ValidationException::withMessages(['cart' => 'Le panier ou le tarif a changé. Vérifiez la nouvelle proposition.']);
        }
        foreach ($proposal['keys'] as $key) {
            if (--$cart[$key] === 0) {
                unset($cart[$key]);
            }
        }
        $cart[$proposal['key']] = 1;

        return response()->json(['items' => collect($cart)->map(fn ($quantity, $key) => ['key' => $key, 'quantity' => $quantity])->values()->all()]);
    }

    public function slots(Request $request, SlotService $slots): JsonResponse
    {
        $settings = RestaurantSetting::query()->findOrFail(1);
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->format('Y-m-d'), 'before_or_equal:'.$today->addDays(SlotService::HORIZON)->format('Y-m-d')], 'total' => 'nullable|integer|min:0']);
        $dates = $slots->bookingDates($settings);
        $date = $data['date'] ?? ($dates[0]['date'] ?? null);
        $open = $date !== null && in_array($date, array_column($dates, 'date'), true);

        return response()->json(['dates' => $dates, 'date' => $date, 'slots' => $open ? $slots->forDate($date, (int) ($data['total'] ?? 0)) : [], 'idempotency_key' => (string) Str::uuid()]);
    }

    /**
     * @param  array<int, array{key: string, quantity: int|string}>  $items
     * @return array<string, int>
     */
    public static function cart(array $items): array
    {
        $cart = [];
        foreach ($items as $item) {
            $cart[$item['key']] = min(50, ($cart[$item['key']] ?? 0) + (int) $item['quantity']);
        }

        return $cart;
    }
}
