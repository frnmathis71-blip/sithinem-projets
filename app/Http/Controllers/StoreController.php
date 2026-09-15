<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\FeaturedProductService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function dashboard(Request $request): View|RedirectResponse
    {
        if ($request->user()?->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }
        if ($request->session()->get('cart', []) !== []) {
            return redirect()->route('checkout');
        }

        return $this->orders($request);
    }

    public function catalog(Request $request, CatalogService $catalog): View
    {
        $type = $request->routeIs('offers') ? 'offer' : $request->string('type')->toString();
        $type = in_array($type, ['product', 'menu', 'offer'], true) ? $type : 'product';
        if ($type === 'menu') {
            return app(DynamicMenuController::class)->index();
        }
        $query = $catalog->model($type)::query()->where('available', true);
        if ($type === 'product') {
            $query->whereNull('parent_id')->where('offer_only', false)->with('variants');
        }
        if ($type === 'product' && $request->filled('category')) {
            $query->where('category_id', $request->integer('category'));
        }
        if ($type === 'offer') {
            $query->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
        }

        $items = $query->orderBy('name')->get();
        if ($request->routeIs('home') && $type === 'product') {
            $items = app(FeaturedProductService::class)->select($items->filter(fn ($item) => $item instanceof Product));
        }

        return view('restaurant.catalog', ['items' => $items, 'categories' => Category::query()->orderBy('position')->get(), 'type' => $type, 'catalog' => $catalog, 'home' => $request->routeIs('home')]);
    }

    public function cart(Request $request, CatalogService $catalog, SlotService $slots): View|RedirectResponse
    {
        $settings = RestaurantSetting::query()->findOrFail(1);
        $date = $this->date($request, $settings);
        $bookingDates = $slots->bookingDates($settings);
        if ($date !== null && ! in_array($date, array_column($bookingDates, 'date'), true)) {
            return redirect()->route('cart')->withErrors(['date' => 'Cette journée n’est pas ouverte à la réservation.']);
        }
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        $lines = [];
        $total = 0;
        foreach ($cart as $key => $quantity) {
            if (str_starts_with($key, 'composed:')) {
                try {
                    $quoted = app(DynamicMenuService::class)->quote($key, $quantity);
                    $lines[] = ['key' => $key, 'item' => null, 'name' => $quoted['name'], 'price' => $quoted['unit_price'], 'composition' => $quoted['composition'], 'quantity' => $quantity, 'available' => true];
                    $total += $quoted['unit_price'] * $quantity;
                } catch (ValidationException) {
                    $lines[] = ['key' => $key, 'item' => null, 'name' => 'Composition à modifier', 'price' => 0, 'composition' => [], 'quantity' => $quantity, 'available' => false];
                }

                continue;
            }
            [$type, $id] = explode(':', $key);
            $item = $catalog->model($type)::withTrashed()->find($id);
            $lines[] = ['key' => $key, 'item' => $item, 'name' => $item instanceof Product ? $item->displayName() : ($item ? $item->name : 'Article retiré'), 'price' => $item ? $item->price : 0, 'composition' => [], 'quantity' => $quantity, 'available' => $type !== 'menu' && $item && $catalog->available($item)];
            $total += ($item->price ?? 0) * $quantity;
        }
        $request->session()->put('cart_displayed_total', $total);
        if (! $request->session()->has('checkout_key')) {
            $request->session()->put('checkout_key', (string) Str::uuid());
        }

        return view('restaurant.cart', ['lines' => $lines, 'total' => $total, 'date' => $date, 'slots' => $date ? $slots->forDate($date) : [], 'settings' => $settings, 'bookingDates' => $bookingDates]);
    }

    public function updateCart(Request $request, CatalogService $catalog): RedirectResponse
    {
        if ($request->filled('key')) {
            $data = $request->validate(['key' => 'required|string|max:3000', 'quantity' => 'required|integer|min:0|max:50']);
            $cart = $request->session()->get('cart', []);
            abort_unless(array_key_exists($data['key'], $cart), 422);
            if ((int) $data['quantity'] === 0) {
                unset($cart[$data['key']]);
            } else {
                $catalog->quote([$data['key'] => (int) $data['quantity']]);
                $cart[$data['key']] = (int) $data['quantity'];
            }
            $request->session()->put('cart', $cart);
            $request->session()->put('checkout_key', (string) Str::uuid());

            return back()->with('success', 'Panier mis à jour.');
        }
        $data = $request->validate(['type' => 'required|in:product,menu,offer', 'id' => 'required|integer|min:1', 'quantity' => 'required|integer|min:0|max:50', 'mode' => 'sometimes|in:add,set']);
        $item = $catalog->model($data['type'])::withTrashed()->whereKey($data['id'])->firstOrFail();
        if ($data['type'] === 'menu' && (int) $data['quantity'] > 0) {
            return redirect()->route('menus.index');
        }
        if ((int) $data['quantity'] > 0 && ! $catalog->available($item)) {
            return back()->withErrors(['cart' => 'Cet article n’est plus disponible.']);
        }
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        $key = $data['type'].':'.$data['id'];
        if ($item instanceof Product && $item->requiresSide() && $request->filled('side_id') && (int) $data['quantity'] > 0) {
            $request->validate(['side_id' => 'required|integer|min:1']);
            $key = app(DynamicMenuService::class)->key(['main' => $item->id, 'side' => $request->integer('side_id')]);
            app(DynamicMenuService::class)->quote($key, (int) $data['quantity']);
        }
        $quantity = ($data['mode'] ?? 'set') === 'add' ? ($cart[$key] ?? 0) + (int) $data['quantity'] : (int) $data['quantity'];
        if ($quantity === 0) {
            unset($cart[$key]);
        } else {
            $cart[$key] = min(50, $quantity);
        }
        abort_if(count($cart) > 50, 422, 'Le panier contient trop de références.');
        $request->session()->put('cart', $cart);
        $request->session()->put('checkout_key', (string) Str::uuid());

        return back()->with('success', 'Panier mis à jour.');
    }

    public function slots(Request $request, SlotService $slots): JsonResponse
    {
        $date = $this->date($request, RestaurantSetting::query()->findOrFail(1));

        return response()->json(['slots' => $date && in_array($date, array_column($slots->bookingDates(RestaurantSetting::query()->findOrFail(1)), 'date'), true) ? $slots->forDate($date) : [], 'dates' => $slots->bookingDates(RestaurantSetting::query()->findOrFail(1))])->header('Cache-Control', 'no-store');
    }

    public function place(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['slot_id' => 'required|integer|exists:time_slots,id', 'phone' => 'required|string|max:30', 'idempotency_key' => 'required|uuid', 'customer_message' => 'nullable|string|max:1000']);
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        if (collect(array_keys($cart))->contains(fn ($key) => str_starts_with($key, 'menu:'))) {
            return back()->withErrors(['cart' => 'Les anciennes formules doivent être retirées du panier et recomposées avec le constructeur de menu.']);
        }
        $user = $request->user();
        abort_unless($user !== null, 401);
        $order = $orders->place($user, (int) $data['slot_id'], $data['phone'], $data['idempotency_key'], $cart, $request->session()->get('cart_displayed_total'), $data['customer_message'] ?? null);
        $request->session()->forget(['cart', 'checkout_key']);

        return redirect()->route('orders.show', $order)->with('success', 'Votre commande est enregistrée. Paiement sur place lors du retrait.');
    }

    public function orders(Request $request): View
    {
        return view('restaurant.orders', ['orders' => Order::query()->with('slot')->where('user_id', $request->user()?->id)->latest()->paginate(15)]);
    }

    public function show(Request $request, Order $order): View
    {
        abort_unless($order->user_id === $request->user()?->id || $request->user()?->role === 'admin', 403);

        return view('restaurant.order', ['order' => $order->load(['slot', 'items']), 'histories' => DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')->get()]);
    }

    public function account(): View
    {
        return view('restaurant.account');
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'first_name' => 'nullable|string|max:255', 'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9 .()\-]{7,24}$/']]);
        $request->user()?->forceFill($data)->save();

        return back()->with('success', 'Vos informations ont été enregistrées.');
    }

    private function date(Request $request, RestaurantSetting $settings): ?string
    {
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $request->validate(['date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$today->format('Y-m-d'), 'before_or_equal:'.$today->addDays(SlotService::HORIZON)->format('Y-m-d')]]);
        if ($request->filled('date')) {
            return $request->string('date')->toString();
        }

        return app(SlotService::class)->bookingDates($settings)[0]['date'] ?? null;
    }
}
