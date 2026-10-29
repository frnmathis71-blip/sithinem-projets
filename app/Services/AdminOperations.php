<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration actions shared by the web back office and the Windows application,
 * so both apply the same validation, locks and business rules.
 */
class AdminOperations
{
    /** @return array{query: Builder<Order>, date: string, scope: string} */
    public function boardQuery(Request $request): array
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'view' => 'nullable|in:active,history,all', 'slot' => 'nullable|integer']);
        $timezone = RestaurantSetting::query()->findOrFail(1)->timezone;
        $date = $request->filled('date') ? $request->string('date')->toString() : CarbonImmutable::now($timezone)->format('Y-m-d');
        $scope = $request->string('view', 'active')->toString() ?: 'active';
        $query = Order::query()->with(['slot', 'items'])->whereHas('slot', fn (Builder $q) => $q->whereDate('date', $date));
        if ($scope === 'active') {
            $query->where('status', 'preparing');
        } elseif ($scope === 'history') {
            $query->whereIn('status', ['completed', 'cancelled']);
        }
        if ($request->filled('slot')) {
            $query->where('time_slot_id', $request->integer('slot'));
        }
        $query->orderBy(TimeSlot::query()->select('starts_at')->whereColumn('time_slots.id', 'orders.time_slot_id'))->orderBy('id');

        return ['query' => $query, 'date' => $date, 'scope' => $scope];
    }

    public function changeStatus(Request $request, Order $order): string
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))]]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        app(OrderService::class)->changeStatus($order, $actor, $data['status']);

        return $data['status'];
    }

    public function markPaid(Order $order): void
    {
        app(RestaurantLock::class)->run(function () use ($order) {
            $order->refresh();
            abort_if($order->status === 'cancelled', 422, 'Une commande annulée ne peut pas être encaissée.');
            if (! $order->paid_at) {
                $order->update(['paid_at' => now()]);
            }
        });
    }

    public function saveSlot(Request $request, TimeSlot $slot): void
    {
        $data = $request->validate(['amount_capacity_override' => ['nullable', 'numeric', 'min:0', 'max:200000', 'regex:/^\d+(?:[.]\d{1,2})?$/D'], 'manually_closed' => 'required|boolean']);
        $data['amount_capacity_override'] = isset($data['amount_capacity_override']) ? app(CatalogManagementService::class)->cents((string) $data['amount_capacity_override']) : null;
        app(RestaurantLock::class)->run(fn () => $slot->update($data));
    }

    public function saveCapacity(Request $request): void
    {
        $data = $request->validate(['default_amount_capacity' => ['required', 'numeric', 'min:0', 'max:200000', 'regex:/^\d+(?:[.]\d{1,2})?$/D']]);
        $data['default_amount_capacity'] = app(CatalogManagementService::class)->cents((string) $data['default_amount_capacity']);
        app(RestaurantLock::class)->run(fn (RestaurantSetting $settings) => $settings->update($data));
    }

    /** @return array{month: CarbonImmutable, days: array<int, array<string, mixed>>} */
    public function calendar(Request $request): array
    {
        $request->validate(['month' => 'sometimes|required|date_format:Y-m']);
        $month = CarbonImmutable::parse($request->string('month', now('Europe/Paris')->format('Y-m'))->toString().'-01');
        $slots = app(SlotService::class);
        $days = [];
        for ($day = $month->startOfWeek(); $day->lte($month->endOfMonth()->endOfWeek()); $day = $day->addDay()) {
            $days[] = ['date' => $day->format('Y-m-d'), 'number' => $day->day, 'in_month' => $day->month === $month->month, 'hours' => $slots->hours($day->format('Y-m-d'))];
        }

        return ['month' => $month, 'days' => $days];
    }

    public function saveHours(Request $request): void
    {
        $data = $request->validate(['weekday' => 'required|integer|between:1,7', 'is_open' => 'required|boolean', 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i|after:starts_at']);
        app(RestaurantLock::class)->run(fn () => DB::table('opening_hours')->where('weekday', $data['weekday'])->update([...$data, 'updated_at' => now()]));
    }

    public function saveException(Request $request): void
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'is_open' => 'required|boolean', 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i|after:starts_at', 'reason' => 'nullable|string|max:255']);
        app(RestaurantLock::class)->run(fn () => DB::table('opening_exceptions')->updateOrInsert(['date' => $data['date']], [...$data, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function deleteException(int $exception): void
    {
        app(RestaurantLock::class)->run(fn () => DB::table('opening_exceptions')->where('id', $exception)->delete());
    }

    /**
     * Products that can be placed in the composition of a menu or an offer.
     *
     * @return Builder<Product>
     */
    public function compositionProducts(string $kind): Builder
    {
        $products = Product::query()->with('parent')->where('has_variants', false)->where(fn (Builder $q) => $q->whereNull('parent_id')->orWhereHas('parent', fn (Builder $parent) => $parent->whereNull('deleted_at')->where('has_variants', true)));
        if ($kind !== 'offer') {
            $products->where('offer_only', false)->where(fn (Builder $q) => $q->whereNull('parent_id')->orWhereHas('parent', fn (Builder $parent) => $parent->where('offer_only', false)));
        }

        return $products->orderBy('name');
    }

    /** @return array<int, int> */
    public function composition(string $kind, ?int $item): array
    {
        return $item && $kind !== 'product' ? DB::table($kind.'_items')->where($kind.'_id', $item)->pluck('quantity', 'product_id')->all() : [];
    }

    public function saveItem(Request $request, string $kind, ?int $item = null): CatalogItem
    {
        $catalog = app(CatalogManagementService::class);
        $money = 'regex:/^\d{1,5}([.,]\d{1,2})?$/';
        $rules = ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'price' => ['required', $money], 'available' => 'required|boolean', 'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:20480', 'remove_image' => 'sometimes|boolean'];
        if ($kind === 'product') {
            $rules['restricted_sides'] = 'sometimes|boolean';
            $rules['compatible_side_ids'] = 'nullable|array|max:100';
            $rules['compatible_side_ids.*'] = 'required|integer|distinct|exists:products,id';
            $rules['category_id'] = ['nullable', function ($attribute, $value, $fail) {
                if (! is_scalar($value) || (! in_array($value, array_map(fn ($code) => 'type:'.$code, array_keys(Category::LABELS)), true) && (! ctype_digit((string) $value) || ! Category::query()->whereKey($value)->exists()))) {
                    $fail('Choisissez une catégorie valide.');
                }
            }];
            $rules['has_variants'] = 'sometimes|boolean';
            $rules['offer_only'] = 'sometimes|boolean';
            $rules['price'] = ['exclude_if:has_variants,1', 'required', $money];
            $rules['variants'] = 'exclude_unless:has_variants,1|required|array|min:1|max:50';
            $rules['variants.*.id'] = 'nullable|integer|distinct';
            $rules['variants.*.label'] = 'required|string|max:100|distinct:ignore_case';
            $rules['variants.*.price'] = ['required', $money];
            $rules['variants.*.image'] = 'nullable|image|mimes:jpg,jpeg,png,webp|max:20480';
            $rules['variants.*.remove_image'] = 'sometimes|boolean';
            $rules['variants.*.available'] = 'required|boolean';
        } else {
            $rules['composition'] = 'nullable|array';
            $rules['composition.*'] = 'nullable|integer|min:0|max:50';
        }
        if ($kind === 'offer') {
            $rules['starts_at'] = 'required|date_format:Y-m-d\TH:i';
            $rules['no_end_date'] = 'sometimes|boolean';
            $rules['ends_at'] = 'exclude_if:no_end_date,1|required|date_format:Y-m-d\TH:i|after:starts_at';
            $rules['create_offer_product'] = 'sometimes|boolean';
            $rules['offer_product'] = 'exclude_unless:create_offer_product,1|required|array';
            $rules['offer_product.name'] = 'required|string|max:255';
            $rules['offer_product.description'] = 'nullable|string|max:5000';
            $rules['offer_product.price'] = ['required', $money];
            $rules['offer_product.quantity'] = 'required|integer|min:1|max:50';
        }
        $rules['limit_weekdays'] = 'sometimes|boolean';
        $rules['available_weekdays'] = 'exclude_unless:limit_weekdays,1|required|array|min:1|max:7';
        $rules['available_weekdays.*'] = 'integer|distinct|between:1,7';
        $data = $request->validate($rules, ['available_weekdays.required' => 'Cochez au moins un jour, ou désactivez la limitation par jour.']);
        // Optional and off by default: without it the item is sold every day.
        $days = $request->boolean('limit_weekdays') ? array_map('intval', $data['available_weekdays'] ?? []) : [];
        sort($days);
        unset($data['limit_weekdays'], $data['available_weekdays']);
        if (in_array($kind, ['product', 'offer'], true)) {
            $data['available_weekdays'] = $days === [] ? null : $days;
        }
        if (isset($data['price'])) {
            $data['price'] = $catalog->cents($data['price']);
        }
        if ($kind === 'product') {
            $data['has_variants'] = $request->boolean('has_variants');
            $data['offer_only'] = $request->boolean('offer_only');
            $data['variants'] = $data['variants'] ?? [];
            foreach ($data['variants'] as &$variant) {
                $variant['price'] = $catalog->cents($variant['price']);
                if (isset($variant['image'])) {
                    $variant['image'] = app(CatalogImageService::class)->store($variant['image']);
                } elseif ($variant['remove_image'] ?? false) {
                    $variant['image'] = null;
                }
            }
            unset($variant);
        }
        if ($kind === 'offer') {
            $tz = RestaurantSetting::query()->findOrFail(1)->timezone;
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], $tz)->utc();
            $data['ends_at'] = $request->boolean('no_end_date') ? null : CarbonImmutable::parse($data['ends_at'], $tz)->utc();
            if (isset($data['offer_product'])) {
                $data['offer_product']['price'] = $catalog->cents($data['offer_product']['price']);
            }
        }
        unset($data['image'], $data['remove_image']);
        if ($request->boolean('remove_image')) {
            $data['image'] = null;
        }
        if ($request->hasFile('image')) {
            $data['image'] = app(CatalogImageService::class)->store($request->file('image'));
        }

        return $catalog->save($kind, $item, $data);
    }

    public function archiveItem(string $kind, int $item): void
    {
        app(RestaurantLock::class)->run(fn () => app(CatalogService::class)->model($kind)::query()->findOrFail($item)->delete());
    }

    public function saveCategory(Request $request): Category
    {
        $data = $request->validate(['id' => 'nullable|exists:categories,id', 'code' => ['nullable', Rule::in(array_keys(Category::LABELS))], 'name' => ['required', 'string', 'max:255', Rule::unique('categories')->ignore($request->integer('id'))], 'position' => 'required|integer|min:0|max:999']);

        return app(RestaurantLock::class)->run(fn () => Category::query()->updateOrCreate(['id' => $data['id'] ?? null], $data));
    }

    public function deleteCategory(int $category): void
    {
        app(RestaurantLock::class)->run(function () use ($category) {
            $category = Category::query()->findOrFail($category);
            // Unclassified main courses must not become orderable without their side.
            $productIds = Product::withTrashed()->where('category_id', $category->id)->pluck('id');
            Product::withTrashed()->where(fn (Builder $query) => $query->where('category_id', $category->id)->orWhereIn('parent_id', $productIds))->update(['category_id' => null, 'available' => false]);
            $category->delete();
        });
    }

    public function saveRule(Request $request, ?int $rule = null): MenuRule
    {
        $menus = app(DynamicMenuService::class);
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'categories' => 'required|array|min:1|max:5', 'categories.*' => ['required', 'string', 'distinct', Rule::in(DynamicMenuService::CATEGORIES)], 'price' => ['required', 'regex:/^\d{1,5}([.,]\d{1,2})?$/'], 'available' => 'required|boolean', 'visible' => 'required|boolean', 'position' => 'required|integer|min:0|max:999']);
        if (! $menus->validCategories($data['categories'])) {
            throw ValidationException::withMessages(['categories' => 'Une formule propose soit un plat classique, soit un plat du chef, jamais les deux.']);
        }

        return app(RestaurantLock::class)->run(function () use ($data, $menus, $rule) {
            $entry = $rule ? MenuRule::query()->findOrFail($rule) : new MenuRule;
            $entry->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'category_key' => $menus->categoryKey($data['categories']), 'price' => app(CatalogManagementService::class)->cents($data['price']), 'available' => $data['available'], 'visible' => $data['visible'], 'position' => $data['position'], 'version' => $entry->exists ? $entry->version + 1 : 1])->save();

            return $entry;
        });
    }
}
