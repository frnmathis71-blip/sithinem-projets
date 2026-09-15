<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Services\CatalogImageService;
use App\Services\CatalogManagementService;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\RestaurantLock;
use App\Services\SlotService;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(Request $request, StatisticsService $statistics): View
    {
        if (! $request->routeIs('admin.statistics')) {
            return $this->orders($request);
        }
        $settings = RestaurantSetting::query()->findOrFail(1);

        return view('restaurant.admin.dashboard', ['report' => $statistics->report($settings->timezone), 'orders' => Order::query()->with('slot')->where('status', 'preparing')->whereHas('slot', fn (Builder $q) => $q->whereDate('date', now($settings->timezone)->format('Y-m-d')))->get(), 'pushConfigured' => (bool) config('restaurant.vapid.public_key'), 'failedNotifications' => DB::table('notification_outbox')->whereNull('sent_at')->whereNotNull('last_error')->count()]);
    }

    public function orderFeed(Request $request): JsonResponse
    {
        $board = $this->boardQuery($request);
        $orders = $board['query']->get();
        $html = view('restaurant.admin.order-cards', ['orders' => $orders, 'scope' => $board['scope']])->render();
        $day = CarbonImmutable::parse($board['date']);
        $day->locale('fr');

        return response()->json(['preparing' => $orders->where('status', 'preparing')->count(), 'date' => $board['date'], 'date_label' => $day->translatedFormat('l j F Y'), 'html' => $html, 'signature' => hash('sha256', $html)])->header('Cache-Control', 'no-store');
    }

    public function orders(Request $request): View
    {
        $board = $this->boardQuery($request);

        return view('restaurant.admin.board', ['orders' => $board['query']->get(), 'date' => $board['date'], 'scope' => $board['scope'], 'pushConfigured' => (bool) config('restaurant.vapid.public_key')]);
    }

    /** @return array{query: Builder<Order>, date: string, scope: string} */
    private function boardQuery(Request $request): array
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

    public function status(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))]]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $orders->changeStatus($order, $actor, $data['status']);

        return redirect()->route('admin.orders', ['date' => $order->slot->date->format('Y-m-d')])->with('success', $data['status'] === 'completed' ? 'Commande terminée et déplacée dans l’historique.' : 'Commande mise à jour.');
    }

    public function paid(Order $order, RestaurantLock $lock): RedirectResponse
    {
        $lock->run(function () use ($order) {
            $order->refresh();
            abort_if($order->status === 'cancelled', 422, 'Une commande annulée ne peut pas être encaissée.');
            if (! $order->paid_at) {
                $order->update(['paid_at' => now()]);
            }
        });

        return back()->with('success', 'Paiement sur place enregistré.');
    }

    public function slots(Request $request, SlotService $slots): View
    {
        $request->validate(['date' => 'sometimes|required|date_format:Y-m-d']);
        $settings = RestaurantSetting::query()->findOrFail(1);
        $date = $request->string('date', CarbonImmutable::now($settings->timezone)->format('Y-m-d'))->toString();

        return view('restaurant.admin.slots', ['settings' => $settings, 'date' => $date, 'slots' => $slots->forDate($date), 'hours' => $slots->hours($date)]);
    }

    public function saveSlot(Request $request, TimeSlot $slot, RestaurantLock $lock): RedirectResponse
    {
        $data = $request->validate(['capacity_override' => 'nullable|integer|min:0|max:10000', 'manually_closed' => 'required|boolean']);
        $lock->run(fn () => $slot->update($data));

        return back()->with('success', 'Créneau mis à jour. Toutes les commandes existantes sont conservées.');
    }

    public function settings(Request $request, RestaurantLock $lock): RedirectResponse
    {
        $data = $request->validate(['default_capacity' => 'required|integer|min:0|max:10000']);
        $lock->run(fn (RestaurantSetting $settings) => $settings->update($data));

        return back()->with('success', 'Capacité par défaut mise à jour pour les créneaux sans personnalisation.');
    }

    public function calendar(Request $request, SlotService $slots): View
    {
        $request->validate(['month' => 'sometimes|required|date_format:Y-m']);
        $month = CarbonImmutable::parse($request->string('month', now('Europe/Paris')->format('Y-m'))->toString().'-01');
        $days = [];
        for ($day = $month->startOfWeek(); $day->lte($month->endOfMonth()->endOfWeek()); $day = $day->addDay()) {
            $days[] = ['date' => $day->format('Y-m-d'), 'number' => $day->day, 'in_month' => $day->month === $month->month, 'hours' => $slots->hours($day->format('Y-m-d'))];
        }

        return view('restaurant.admin.calendar', ['weekly' => DB::table('opening_hours')->orderBy('weekday')->get(), 'exceptions' => DB::table('opening_exceptions')->orderByDesc('date')->get(), 'month' => $month, 'days' => $days]);
    }

    public function saveHours(Request $request, RestaurantLock $lock): RedirectResponse
    {
        $data = $request->validate(['weekday' => 'required|integer|between:1,7', 'is_open' => 'required|boolean', 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i|after:starts_at']);
        $lock->run(fn () => DB::table('opening_hours')->where('weekday', $data['weekday'])->update([...$data, 'updated_at' => now()]));

        return back()->with('success', 'Horaires enregistrés. Vérifiez les commandes existantes si vous avez réduit les horaires.');
    }

    public function saveException(Request $request, RestaurantLock $lock): RedirectResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'is_open' => 'required|boolean', 'starts_at' => 'required|date_format:H:i', 'ends_at' => 'required|date_format:H:i|after:starts_at', 'reason' => 'nullable|string|max:255']);
        $lock->run(fn () => DB::table('opening_exceptions')->updateOrInsert(['date' => $data['date']], [...$data, 'created_at' => now(), 'updated_at' => now()]));

        return back()->with('success', 'Exception enregistrée. Les commandes sont conservées ; consultez les créneaux de cette date.');
    }

    public function deleteException(int $exception, RestaurantLock $lock): RedirectResponse
    {
        $lock->run(fn () => DB::table('opening_exceptions')->where('id', $exception)->delete());

        return back()->with('success', 'Les horaires habituels s’appliquent à nouveau.');
    }

    public function card(): View
    {
        return view('restaurant.admin.card', ['categories' => Category::query()->orderBy('position')->orderBy('id')->get(), 'products' => Product::query()->with('variants')->whereNull('parent_id')->orderBy('name')->get(), 'menus' => Menu::query()->orderBy('name')->get(), 'offers' => Offer::query()->orderBy('name')->get()]);
    }

    public function catalog(): RedirectResponse
    {
        return redirect()->route('admin.card');
    }

    public function editItem(string $kind, CatalogService $catalog, ?int $item = null): View|RedirectResponse
    {
        if ($kind === 'menu') {
            return redirect()->route('admin.menu-rules');
        }
        $entry = $item ? $catalog->model($kind)::query()->findOrFail($item) : new ($catalog->model($kind));
        abort_if($entry instanceof Product && $entry->parent_id !== null, 404);
        $composition = $item && $kind !== 'product' ? DB::table($kind.'_items')->where($kind.'_id', $item)->pluck('quantity', 'product_id')->all() : [];

        $products = Product::query()->with('parent')->where('has_variants', false)->where(fn (Builder $q) => $q->whereNull('parent_id')->orWhereHas('parent', fn (Builder $parent) => $parent->whereNull('deleted_at')->where('has_variants', true)));
        if ($kind !== 'offer') {
            $products->where('offer_only', false)->where(fn (Builder $q) => $q->whereNull('parent_id')->orWhereHas('parent', fn (Builder $parent) => $parent->where('offer_only', false)));
        }

        return view('restaurant.admin.item', ['kind' => $kind, 'item' => $entry, 'categories' => Category::query()->orderBy('position')->get(), 'products' => $products->orderBy('name')->get(), 'composition' => $composition]);
    }

    public function saveItem(Request $request, string $kind, CatalogManagementService $catalog, ?int $item = null): RedirectResponse
    {
        if ($kind === 'menu') {
            return redirect()->route('admin.menu-rules');
        }
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
        $data = $request->validate($rules);
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
        $catalog->save($kind, $item, $data);

        return redirect()->route('admin.card')->with('success', 'Article enregistré.');
    }

    public function archiveItem(string $kind, int $item, CatalogService $catalog, RestaurantLock $lock): RedirectResponse
    {
        $lock->run(fn () => $catalog->model($kind)::query()->findOrFail($item)->delete());

        return redirect()->route('admin.card')->with('success', 'Article archivé. Les anciennes commandes sont conservées.');
    }

    public function categories(): RedirectResponse
    {
        return redirect()->to(route('admin.card').'#categories');
    }

    public function saveCategory(Request $request, RestaurantLock $lock): RedirectResponse
    {
        $data = $request->validate(['id' => 'nullable|exists:categories,id', 'code' => ['nullable', Rule::in(array_keys(Category::LABELS))], 'name' => ['required', 'string', 'max:255', Rule::unique('categories')->ignore($request->integer('id'))], 'position' => 'required|integer|min:0|max:999']);
        $lock->run(fn () => Category::query()->updateOrCreate(['id' => $data['id'] ?? null], $data));

        return back()->with('success', 'Catégorie enregistrée.');
    }

    public function deleteCategory(Category $category, RestaurantLock $lock): RedirectResponse
    {
        $lock->run(function () use ($category) {
            $category = Category::query()->findOrFail($category->id);
            // Unclassified main courses must not become orderable without their side.
            $productIds = Product::withTrashed()->where('category_id', $category->id)->pluck('id');
            Product::withTrashed()->where(fn (Builder $query) => $query->where('category_id', $category->id)->orWhereIn('parent_id', $productIds))->update(['category_id' => null, 'available' => false]);
            $category->delete();
        });

        return redirect()->to(route('admin.card').'#categories')->with('success', 'Catégorie supprimée. Ses produits sont conservés dans « Sans catégorie » et désactivés. Reclassez-les avant de les remettre en vente.');
    }
}
