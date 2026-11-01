<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\FeaturedProductService;
use App\Services\OrderService;
use App\Services\PhoneVerificationService;
use App\Services\SlotService;
use App\Services\StripeCheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            // Current offers come first, limited-time ones ahead, then the most ordered dishes.
            $items = $this->currentOffers($catalog)->concat(app(FeaturedProductService::class)->select($items->filter(fn ($item) => $item instanceof Product)));
        }
        $categories = Category::query()->orderBy('position')->get();
        if ($request->routeIs('menu') && $type === 'product' && ! $request->filled('category')) {
            // "Tout voir" follows the category chips: offers, then each category in its order, drinks always last.
            $order = $categories->values()->mapWithKeys(fn (Category $category, int $index) => [$category->id => $category->getAttribute('code') === 'drink' ? PHP_INT_MAX : $index]);
            $items = $this->currentOffers($catalog)->concat($items->sortBy([
                fn (CatalogItem $a, CatalogItem $b) => ($order[$a->getAttribute('category_id')] ?? PHP_INT_MAX - 1) <=> ($order[$b->getAttribute('category_id')] ?? PHP_INT_MAX - 1),
                fn (CatalogItem $a, CatalogItem $b) => $a->name <=> $b->name,
            ])->values());
        }

        return view('restaurant.catalog', ['items' => $items, 'categories' => $categories, 'type' => $type, 'catalog' => $catalog, 'home' => $request->routeIs('home'), 'slides' => $request->routeIs('home') ? $this->heroSlides($catalog) : [], 'currentOrders' => $request->routeIs('home') ? $this->currentOrders($request) : collect()]);
    }

    /**
     * The customer's orders still on their way (awaiting payment, received, accepted or ready), next pickup first.
     * Shown at the top of the home page until the order is completed or cancelled.
     *
     * @return Collection<int, Order>
     */
    private function currentOrders(Request $request): Collection
    {
        if (! $request->user()) {
            return collect();
        }
        $today = CarbonImmutable::now(RestaurantSetting::query()->findOrFail(1)->timezone)->format('Y-m-d');

        return Order::query()->with('slot')->where('user_id', $request->user()->id)->where('source', 'online')
            ->whereIn('status', [...Order::ACTIVE, 'awaiting_payment'])
            ->whereHas('slot', fn ($q) => $q->whereDate('date', '>=', $today))->get()
            ->sortBy(fn (Order $order) => $order->slot->date->format('Y-m-d').' '.$order->slot->starts_at)->values();
    }

    /**
     * Offers available right now: limited-time ones first, ending soonest, then the permanent ones by name.
     *
     * @return Collection<int, Offer>
     */
    private function currentOffers(CatalogService $catalog): Collection
    {
        return Offer::query()->where('available', true)->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByRaw('ends_at is null')->orderBy('ends_at')->orderBy('name')->get()
            ->filter(fn (Offer $offer) => $catalog->available($offer))->values();
    }

    /**
     * Current offers and chef dishes shown in the home carousel.
     *
     * @return list<array{label: string, days: string|null, name: string, price: string, image: string|null, url: string}>
     */
    private function heroSlides(CatalogService $catalog): array
    {
        $price = fn (int $cents, bool $from = false) => ($from ? 'À partir de ' : '').number_format($cents / 100, 2, ',', ' ').' €';
        $image = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;
        $slides = [];
        foreach ($this->currentOffers($catalog)->take(3) as $offer) {
            $slides[] = ['label' => 'Offre du moment', 'days' => $offer->weekdayLabel(), 'name' => $offer->name, 'price' => $price($offer->price), 'image' => $image($offer->image), 'url' => route('offers').'#carte'];
        }
        $chef = Category::query()->where('code', 'chef_main')->value('id');
        $dishes = $chef ? Product::query()->where('available', true)->whereNull('parent_id')->where('offer_only', false)
            ->where('category_id', $chef)->with('variants')->orderBy('name')->get() : collect();
        foreach ($dishes as $dish) {
            $available = $dish->has_variants ? $dish->variants->contains(fn (Product $variant) => $catalog->available($variant)) : $catalog->available($dish);
            if (! $available || count($slides) >= 6) {
                continue;
            }
            $photo = $dish->image ?: ($dish->has_variants ? $dish->variants->first(fn (Product $v) => $v->available && $v->image)?->image : null);
            $slides[] = ['label' => 'Plat du chef', 'days' => $dish->weekdayLabel(), 'name' => $dish->name, 'price' => $price($dish->price, $dish->has_variants), 'image' => $image($photo), 'url' => route('menu', ['category' => $chef]).'#carte'];
        }

        return $slides;
    }

    public function cart(Request $request, CatalogService $catalog, SlotService $slots): View|RedirectResponse
    {
        $settings = RestaurantSetting::query()->findOrFail(1);
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        $weekdays = $catalog->cartWeekdays($cart);
        $bookingDates = $this->bookingDates($slots, $settings, $weekdays);
        $date = $this->date($request, $settings, $bookingDates);
        if ($date !== null && ! in_array($date, array_column($bookingDates, 'date'), true)) {
            return redirect()->route('cart')->withErrors(['date' => $weekdays === null ? 'Cette journée n’est pas ouverte à la réservation.' : 'Votre panier ne peut pas être retiré ce jour-là.']);
        }
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

        return view('restaurant.cart', ['lines' => $lines, 'total' => $total, 'date' => $date, 'slots' => $date ? $slots->forDate($date, $total) : [], 'settings' => $settings, 'bookingDates' => $bookingDates, 'weekdays' => $weekdays, 'onlinePayment' => app(StripeCheckoutService::class)->enabled()]);
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
        $settings = RestaurantSetting::query()->findOrFail(1);
        $bookingDates = $this->bookingDates($slots, $settings, app(CatalogService::class)->cartWeekdays($request->session()->get('cart', [])));
        $date = $this->date($request, $settings, $bookingDates);

        return response()->json(['slots' => $date && in_array($date, array_column($bookingDates, 'date'), true) ? $slots->forDate($date, $this->cartAmount($request)) : [], 'dates' => $bookingDates])->header('Cache-Control', 'no-store');
    }

    public function place(Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['slot_id' => 'required|integer|exists:time_slots,id', 'phone' => 'required|string|max:30', 'idempotency_key' => 'required|uuid', 'customer_message' => 'nullable|string|max:1000', 'payment_method' => 'sometimes|in:onsite,online']);
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        if (collect(array_keys($cart))->contains(fn ($key) => str_starts_with($key, 'menu:'))) {
            return back()->withErrors(['cart' => 'Les anciennes formules doivent être retirées du panier et recomposées avec le constructeur de menu.']);
        }
        $user = $request->user();
        abort_unless($user !== null, 401);
        $order = $orders->place($user, (int) $data['slot_id'], $data['phone'], $data['idempotency_key'], $cart, $request->session()->get('cart_displayed_total'), $data['customer_message'] ?? null, $data['payment_method'] ?? 'onsite');
        $request->session()->forget(['cart', 'checkout_key']);
        if ($order->status === 'awaiting_payment') {
            return $this->toPayment($order);
        }

        return redirect()->route('orders.show', $order)->with('success', 'Votre commande est enregistrée. Paiement sur place lors du retrait.');
    }

    /** Resumes the payment of an online order still awaiting it. */
    public function pay(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()?->id, 403);
        if ($order->status !== 'awaiting_payment' || $order->paid_at) {
            return redirect()->route('orders.show', $order);
        }

        return $this->toPayment($order);
    }

    private function toPayment(Order $order): RedirectResponse
    {
        try {
            return redirect()->away(app(StripeCheckoutService::class)->paymentUrl($order));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('orders.show', $order)->withErrors(['payment' => 'Le paiement en ligne est momentanément indisponible. Réessayez dans un instant : votre créneau reste réservé '.StripeCheckoutService::PAYMENT_WINDOW.' minutes.']);
        }
    }

    public function orders(Request $request): View
    {
        return view('restaurant.orders', ['orders' => Order::query()->with('slot')->where('user_id', $request->user()?->id)->latest()->paginate(15)]);
    }

    public function show(Request $request, Order $order): View
    {
        abort_unless($order->user_id === $request->user()?->id || $request->user()?->role === 'admin', 403);
        // Back from Stripe: confirm right away instead of waiting for the webhook.
        if ($order->status === 'awaiting_payment' && $request->query('paiement') === 'confirme' && $request->filled('session_id')) {
            try {
                app(StripeCheckoutService::class)->confirmReturn($order, $request->string('session_id')->toString());
                $order->refresh();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $editing = $request->routeIs('admin.*') && $order->isActive() ? $this->editingData($request, $order) : [];

        return view('restaurant.order', ['order' => $order->load(['slot', 'items']), 'histories' => DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')->get(), ...$editing]);
    }

    /** Current status for the customer's order page, which refreshes itself when the restaurant moves the order along. */
    public function tracking(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()?->id, 403);

        return response()->json(['status' => $order->status, 'signature' => md5($order->status.'|'.$order->time_slot_id.'|'.$order->total.'|'.$order->paid_at)])->header('Cache-Control', 'no-store');
    }

    /**
     * What the restaurant can add to an order, and the slots it can move it to.
     *
     * @return array<string, mixed>
     */
    private function editingData(Request $request, Order $order): array
    {
        $catalog = app(CatalogService::class);
        $choices = [];
        foreach (Product::query()->where('available', true)->where('offer_only', false)->where('has_variants', false)->with('parent')->orderBy('name')->orderBy('variant_label')->get() as $product) {
            if ($catalog->productAvailable($product)) {
                $choices[] = ['key' => 'product:'.$product->id, 'label' => $product->displayName().' · '.number_format($product->price / 100, 2, ',', ' ').' €'];
            }
        }
        foreach ($this->currentOffers($catalog) as $offer) {
            $choices[] = ['key' => 'offer:'.$offer->id, 'label' => 'Offre : '.$offer->name.' · '.number_format($offer->price / 100, 2, ',', ' ').' €'];
        }
        $settings = RestaurantSetting::query()->findOrFail(1);
        $slots = app(SlotService::class);
        $dates = $slots->bookingDates($settings);
        $current = $order->slot->date->format('Y-m-d');
        if (! in_array($current, array_column($dates, 'date'), true)) {
            array_unshift($dates, ['date' => $current, 'label' => $order->slot->date->translatedFormat('l j F')]);
        }
        $date = in_array($request->query('jour'), array_column($dates, 'date'), true) ? (string) $request->query('jour') : $current;
        $now = CarbonImmutable::now($settings->timezone);

        return [
            'addChoices' => $choices,
            'rescheduleDates' => $dates,
            'rescheduleDate' => $date,
            // Every slot still to come; the restaurant may go beyond the online capacity, the remaining amount is shown.
            'rescheduleSlots' => array_values(array_filter($slots->forDate($date), fn (array $slot) => ! $slot['manually_closed'] && CarbonImmutable::parse($date.' '.$slot['start'], $settings->timezone)->gt($now))),
        ];
    }

    public function account(): View
    {
        return view('restaurant.account');
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'first_name' => 'nullable|string|max:255', 'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9 .()\-]{7,24}$/']]);
        $user = $request->user();
        abort_unless($user !== null, 401);
        $phone = empty($data['phone']) ? null : app(PhoneVerificationService::class)->normalize($data['phone']);
        unset($data['phone']);
        $user->forceFill($data)->save();
        if (! config('services.twilio.enabled')) {
            $user->forceFill(['phone' => $phone, 'pending_phone' => null, 'phone_verified_at' => $phone === $user->phone ? $user->phone_verified_at : null])->save();

            return back()->with('success', 'Vos informations ont été enregistrées.');
        }
        if ($phone !== $user->phone) {
            $user->forceFill(['pending_phone' => $phone])->save();

            return redirect()->route('phone.show')->with('success', 'Vos informations sont enregistrées. Vérifiez votre nouveau numéro pour l’utiliser.');
        }

        return back()->with('success', 'Vos informations ont été enregistrées.');
    }

    private function cartAmount(Request $request): int
    {
        /** @var array<string, int> $cart */
        $cart = $request->session()->get('cart', []);
        if ($cart === []) {
            return 0;
        }
        try {
            return app(CatalogService::class)->quote($cart)['total'];
        } catch (ValidationException) {
            return PHP_INT_MAX;
        }
    }

    /** @param  array<int, array{date: string}>  $bookingDates */
    private function date(Request $request, RestaurantSetting $settings, array $bookingDates): ?string
    {
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $request->validate(['date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$today->format('Y-m-d'), 'before_or_equal:'.$today->addDays(SlotService::HORIZON)->format('Y-m-d')]]);
        if ($request->filled('date')) {
            return $request->string('date')->toString();
        }

        return $bookingDates[0]['date'] ?? null;
    }

    /**
     * Opening days offered for pickup, keeping only the weekdays every item of the cart allows.
     *
     * @param  list<int>|null  $weekdays
     * @return array<int, array{date: string, label: string}>
     */
    private function bookingDates(SlotService $slots, RestaurantSetting $settings, ?array $weekdays): array
    {
        $dates = $slots->bookingDates($settings);

        return $weekdays === null ? $dates : array_values(array_filter($dates, fn (array $day) => in_array(CarbonImmutable::parse($day['date'])->isoWeekday(), $weekdays, true)));
    }
}
