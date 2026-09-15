<?php

use App\Jobs\SendOrderPush;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\CatalogManagementService;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    $this->client = User::factory()->create();
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->product = Product::query()->create(['name' => 'Nems', 'price' => 650, 'available' => true]);
    $this->slots = app(SlotService::class)->forDate('2026-09-18');
    $this->slot = TimeSlot::query()->findOrFail($this->slots[0]['id']);
    $this->cart = ['product:'.$this->product->id => 2];
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function restaurantPlace($test, ?string $key = null): Order
{
    return app(OrderService::class)->place($test->client, $test->slot->id, '06 12 34 56 78', $key ?? (string) Str::uuid(), $test->cart);
}

test('checkout saves the customer message and shows it safely on the board and live feed', function () {
    $message = "Allergie aux arachides.\nSans oignons <script>alert(1)</script>";
    $this->actingAs($this->client)->withSession(['cart' => $this->cart])
        ->get(route('cart'))->assertOk()->assertSee('name="customer_message"', false);
    $this->post(route('orders.store'), ['slot_id' => $this->slot->id, 'phone' => '0612345678', 'idempotency_key' => (string) Str::uuid(), 'customer_message' => $message])
        ->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::firstOrFail();
    expect($order->customer_message)->toBe($message);
    $this->get(route('orders.show', $order))->assertOk()->assertSee($message)->assertDontSee('<script>alert(1)</script>', false);
    $this->actingAs($this->admin)->get(route('admin.orders'))->assertOk()->assertSee($message)->assertDontSee('<script>alert(1)</script>', false);
    $html = $this->getJson(route('admin.feed'))->assertOk()->json('html');
    expect($html)->toContain(e($message))->not->toContain('<script>alert(1)</script>');
    $this->get(route('admin.orders.show', $order))->assertOk()->assertSee($message);
});

test('checkout rejects oversized messages and preserves input and cart', function () {
    $message = str_repeat('a', 1001);
    $this->actingAs($this->client)->withSession(['cart' => $this->cart])->from(route('cart'))
        ->post(route('orders.store'), ['slot_id' => $this->slot->id, 'phone' => '0612345678', 'idempotency_key' => (string) Str::uuid(), 'customer_message' => $message])
        ->assertSessionHasErrors('customer_message')->assertSessionHas('cart', $this->cart)->assertSessionHas('_old_input.customer_message', $message);
    expect(Order::count())->toBe(0);
});

test('customer message is optional and duplicate submissions preserve the original message', function () {
    expect(restaurantPlace($this)->customer_message)->toBeNull();
    $key = (string) Str::uuid();
    $service = app(OrderService::class);
    $order = $service->place($this->client, $this->slot->id, '0612345678', $key, $this->cart, customerMessage: 'Sans oignons');
    $duplicate = $service->place($this->client, $this->slot->id, '0612345678', $key, $this->cart, customerMessage: 'Autre message');
    expect($duplicate->id)->toBe($order->id)->and($duplicate->customer_message)->toBe('Sans oignons');
});

test('service board defaults to today and completed orders move to history', function () {
    $order = restaurantPlace($this);
    $tomorrow = app(SlotService::class)->forDate('2026-09-19')[0]['id'];
    $future = app(OrderService::class)->place($this->client, $tomorrow, '0612345678', (string) Str::uuid(), $this->cart);
    $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('data-order="'.$order->id.'"', false)->assertDontSee('data-order="'.$future->id.'"', false)->assertSee('Se déconnecter');
    $this->get(route('admin.orders.show', $order))->assertSee('Terminé')->assertSee('Nems');
    $this->patch(route('admin.orders.status', $order), ['status' => 'completed'])->assertSessionHasNoErrors();
    $this->get('/admin')->assertDontSee('data-order="'.$order->id.'"', false);
    $this->get(route('admin.orders', ['view' => 'history']))->assertSee('data-order="'.$order->id.'"', false);
    $this->getJson(route('admin.feed'))->assertJsonPath('preparing', 0)->assertJsonPath('date', '2026-09-18');
    expect($order->fresh()->status)->toBe('completed');
});

test('admin has one card page and can log out', function () {
    $this->actingAs($this->admin)->get('/admin/carte')->assertOk()->assertSee('Modifier les catégories')->assertSee('Créer une offre');
    $this->get('/admin/catalogue/product')->assertRedirect(route('admin.card'));
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('variants are saved with their category and only concrete available choices can be ordered', function () {
    $category = Category::create(['name' => 'Plats', 'position' => 1]);
    $this->actingAs($this->admin)->post(route('admin.catalog.save', 'product'), ['name' => 'Bo bun', 'available' => 1, 'category_id' => $category->id, 'has_variants' => 1, 'variants' => [
        ['label' => 'Petit', 'price' => '8,50', 'available' => 1], ['label' => 'Grand', 'price' => '12', 'available' => 0],
    ]])->assertSessionHasNoErrors();
    $parent = Product::where('name', 'Bo bun')->whereNull('parent_id')->firstOrFail();
    $child = $parent->variants()->firstOrFail();
    expect($parent->variants)->toHaveCount(2)->and($child->price)->toBe(850)->and($child->category_id)->toBe($category->id);
    $catalog = app(CatalogService::class);
    expect($catalog->available($parent))->toBeFalse()->and($catalog->available($child))->toBeTrue()->and($catalog->available($parent->variants->last()))->toBeFalse();
    $this->get('/menu')->assertSee('data-variant-dialog', false)->assertDontSee('<select name="id"', false)->assertSee('Petit');
    $this->cart = ['product:'.$child->id => 1];
    $order = restaurantPlace($this);
    $parent->update(['available' => false]);
    expect($catalog->available($child->fresh()))->toBeFalse();
    expect($order->items->first()->name)->toBe('Bo bun — Petit')->and($order->total)->toBe(850);
});

test('variant ownership is checked atomically and removed choices are archived', function () {
    $service = app(CatalogManagementService::class);
    $data = ['name' => 'Tailles', 'available' => true, 'has_variants' => true, 'variants' => [['label' => 'Petit', 'price' => 500, 'available' => true], ['label' => 'Grand', 'price' => 800, 'available' => true]]];
    $parent = $service->save('product', null, $data);
    $children = $parent->variants;
    $invalid = $data;
    $invalid['name'] = 'Ne pas enregistrer';
    $invalid['variants'][0]['id'] = $this->product->id;
    expect(fn () => $service->save('product', $parent->id, $invalid))->toThrow(ValidationException::class);
    expect($parent->fresh()->name)->toBe('Tailles');
    $data['variants'] = [['id' => $children[0]->id, 'label' => 'Petit', 'price' => 600, 'available' => true]];
    $service->save('product', $parent->id, $data);
    expect($children[1]->fresh()->trashed())->toBeTrue()->and($children[0]->fresh()->price)->toBe(600);
});

test('offer can create an exclusive product with no end date', function () {
    $this->actingAs($this->admin)->post(route('admin.catalog.save', 'offer'), ['name' => 'Offre spéciale', 'price' => '10', 'available' => 1, 'starts_at' => '2026-09-18T16:00', 'no_end_date' => 1, 'create_offer_product' => 1, 'offer_product' => ['name' => 'Dessert exclusif', 'price' => '4,50', 'quantity' => 2]])->assertSessionHasNoErrors();
    $offer = Offer::firstOrFail();
    $product = Product::where('name', 'Dessert exclusif')->firstOrFail();
    expect($offer->ends_at)->toBeNull()->and($product->offer_only)->toBeTrue();
    $catalog = app(CatalogService::class);
    expect($catalog->available($offer, CarbonImmutable::parse('2027-01-01')))->toBeTrue()->and($catalog->available($product))->toBeFalse();
    $this->get('/menu')->assertDontSee('Dessert exclusif');
    $this->get('/offres')->assertOk()->assertSee('Dessert exclusif');
    $this->post(route('cart.update'), ['type' => 'product', 'id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('cart');
    expect(fn () => $catalog->quote(['product:'.$product->id => 1]))->toThrow(ValidationException::class);
    $this->post(route('admin.catalog.save', 'menu'), ['name' => 'Menu interdit', 'price' => '12', 'available' => 1, 'composition' => [$product->id => 1]])->assertRedirect(route('admin.menu-rules'));
    expect(Menu::count())->toBe(0);
});

test('ordinary offers do not require exclusive product fields and dated offers still require an end', function () {
    $data = ['name' => 'Offre classique', 'price' => '10', 'available' => 1, 'starts_at' => '2026-09-18T16:00', 'no_end_date' => 1, 'create_offer_product' => 0, 'composition' => [$this->product->id => 1]];
    $this->actingAs($this->admin)->post(route('admin.catalog.save', 'offer'), $data)->assertSessionHasNoErrors();
    $data['no_end_date'] = 0;
    $this->post(route('admin.catalog.save', 'offer'), $data)->assertSessionHasErrors('ends_at');
    $data['ends_at'] = '2026-09-20T23:00';
    $this->post(route('admin.catalog.save', 'offer'), $data)->assertSessionHasNoErrors();
});

test('booking date list follows weekly hours and exceptions and rejects closed days', function () {
    $slots = app(SlotService::class);
    $dates = $slots->bookingDates(RestaurantSetting::find(1));
    foreach ($dates as $day) {
        expect(CarbonImmutable::parse($day['date'])->isoWeekday())->toBeIn([5, 6]);
    }
    $this->withSession(['cart' => $this->cart])->get('/panier')->assertOk()->assertSee('vendredi 18 septembre')->assertDontSee('value="2026-09-20"', false);
    $this->get('/panier?date=2026-09-20')->assertRedirect(route('cart'))->assertSessionHasErrors('date');
    $this->actingAs($this->admin)->post(route('admin.exceptions'), ['date' => '2026-09-20', 'is_open' => 1, 'starts_at' => '18:00', 'ends_at' => '23:00'])->assertSessionHasNoErrors();
    $this->post(route('admin.exceptions'), ['date' => '2026-09-18', 'is_open' => 0, 'starts_at' => '18:00', 'ends_at' => '23:00'])->assertSessionHasNoErrors();
    $dates = array_column($slots->bookingDates(RestaurantSetting::find(1)), 'date');
    expect($dates)->toContain('2026-09-20')->not->toContain('2026-09-18');
    $this->getJson(route('slots', ['date' => '2026-09-18']))->assertJsonPath('slots', []);
});

test('no open dates never falls back to a closed selectable date', function () {
    DB::table('opening_hours')->update(['is_open' => false]);
    expect(app(SlotService::class)->bookingDates(RestaurantSetting::find(1)))->toBe([]);
    $this->withSession(['cart' => $this->cart])->get('/panier')->assertOk()->assertSee('Aucune date ouverte')->assertDontSee('type="radio"', false);
});

test('default opening creates fifteen twenty minute slots ending at closing', function () {
    expect($this->slots)->toHaveCount(15);
    expect($this->slots[0]['start'])->toBe('18:00');
    expect($this->slots[14]['start'])->toBe('22:40');
    expect($this->slots[14]['end'])->toBe('23:00');
    expect(app(SlotService::class)->forDate('2026-09-20'))->toBe([]);
});

test('exact thirty minute cutoff uses seconds and Paris timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:30:00', 'UTC'));
    expect(app(SlotService::class)->describe($this->slot, RestaurantSetting::find(1))['selectable'])->toBeTrue();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:30:01', 'UTC'));
    expect(app(SlotService::class)->describe($this->slot, RestaurantSetting::find(1))['selectable'])->toBeFalse();
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
});

test('last place is consumed once and complete slot cannot be booked', function () {
    $this->slot->update(['capacity_override' => 1]);
    restaurantPlace($this);
    $description = app(SlotService::class)->describe($this->slot, RestaurantSetting::find(1));
    expect($description['count'])->toBe(1)->and($description['remaining'])->toBe(0)->and($description['status'])->toBe('Complet');
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    expect(Order::count())->toBe(1);
});

test('lowering capacity preserves eight existing orders and blocks the ninth', function () {
    foreach (range(1, 8) as $i) {
        restaurantPlace($this);
    }
    $this->actingAs($this->admin)->patch(route('admin.slots.update', $this->slot), ['capacity_override' => 5, 'manually_closed' => 0])->assertSessionHasNoErrors();
    expect(Order::where('status', 'preparing')->count())->toBe(8);
    $description = app(SlotService::class)->describe($this->slot->fresh(), RestaurantSetting::find(1));
    expect($description['remaining'])->toBe(0)->and($description['count'])->toBe(8);
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
});

test('default capacity follows settings but specific overrides are preserved', function () {
    $this->slot->update(['capacity_override' => 8]);
    $this->actingAs($this->admin)->patch(route('admin.settings'), ['default_capacity' => 3])->assertSessionHasNoErrors();
    $slots = app(SlotService::class)->forDate('2026-09-18');
    expect($slots[0]['capacity'])->toBe(8)->and($slots[1]['capacity'])->toBe(3);
    $this->actingAs($this->admin)->patch(route('admin.slots.update', $this->slot), ['capacity_override' => null, 'manually_closed' => 0]);
    expect(app(SlotService::class)->describe($this->slot->fresh(), RestaurantSetting::find(1))['capacity'])->toBe(3);
});

test('manual closure and reopening are enforced on the server', function () {
    $this->slot->update(['manually_closed' => true]);
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    $this->slot->update(['manually_closed' => false]);
    expect(restaurantPlace($this))->toBeInstanceOf(Order::class);
});

test('exceptional closure preserves orders and prevents bookings', function () {
    restaurantPlace($this);
    $this->actingAs($this->admin)->post(route('admin.exceptions'), ['date' => '2026-09-18', 'is_open' => 0, 'starts_at' => '18:00', 'ends_at' => '23:00', 'reason' => 'Fermeture'])->assertSessionHasNoErrors();
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    expect(Order::count())->toBe(1);
    $this->get(route('admin.slots', ['date' => '2026-09-18']))->assertOk()->assertSee('Des commandes existent sur ce créneau fermé');
});

test('exceptional opening supersedes a closed weekday', function () {
    $this->actingAs($this->admin)->post(route('admin.exceptions'), ['date' => '2026-09-20', 'is_open' => 1, 'starts_at' => '12:00', 'ends_at' => '13:00'])->assertSessionHasNoErrors();
    expect(app(SlotService::class)->forDate('2026-09-20'))->toHaveCount(3);
});

test('changed hours reject an old slot even when its row still exists', function () {
    DB::table('opening_hours')->where('weekday', 5)->update(['starts_at' => '18:10']);
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    expect(app(SlotService::class)->forDate('2026-09-18')[0]['selectable'])->toBeFalse();
});

test('idempotency returns the same order even after the slot becomes full', function () {
    $this->slot->update(['capacity_override' => 1]);
    $key = (string) Str::uuid();
    $first = restaurantPlace($this, $key);
    $second = app(OrderService::class)->place($this->client, $this->slot->id, '0612345678', $key, []);
    expect($second->id)->toBe($first->id)->and(Order::count())->toBe(1)->and(DB::table('notification_outbox')->count())->toBe(1);
});

test('failed item validation rolls back the whole reservation', function () {
    $this->product->update(['available' => false]);
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    expect(Order::count())->toBe(0)->and(DB::table('notification_outbox')->count())->toBe(0);
    expect(app(SlotService::class)->describe($this->slot, RestaurantSetting::find(1))['count'])->toBe(0);
});

test('cancellation releases capacity and reactivation cannot overbook', function () {
    $this->slot->update(['capacity_override' => 1]);
    $order = restaurantPlace($this);
    app(OrderService::class)->changeStatus($order, $this->admin, 'cancelled');
    restaurantPlace($this);
    expect(fn () => app(OrderService::class)->changeStatus($order, $this->admin, 'preparing'))->toThrow(ValidationException::class);
    expect($order->fresh()->status)->toBe('cancelled');
});

test('collected orders continue consuming capacity and all transitions are recorded', function () {
    $order = restaurantPlace($this);
    foreach (['completed'] as $status) {
        app(OrderService::class)->changeStatus($order, $this->admin, $status);
    }
    expect(app(SlotService::class)->describe($this->slot, RestaurantSetting::find(1))['count'])->toBe(1);
    expect(DB::table('order_status_histories')->count())->toBe(2);
    expect(fn () => app(OrderService::class)->changeStatus($order, $this->admin, 'preparing'))->toThrow(ValidationException::class);
});

test('prices and order composition are frozen server side', function () {
    $this->actingAs($this->client)->withSession(['cart' => $this->cart])->post(route('orders.store'), ['slot_id' => $this->slot->id, 'phone' => '0612345678', 'idempotency_key' => (string) Str::uuid(), 'total' => 1, 'unit_price' => 1])->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::first();
    $this->product->update(['price' => 9999, 'name' => 'Nouveau nom']);
    expect($order->total)->toBe(1300)->and($order->items->first()->unit_price)->toBe(650)->and($order->items->first()->name)->toBe('Nems');
});

test('menus require available components and snapshot their quantities', function () {
    $menu = Menu::create(['name' => 'Menu', 'price' => 1000, 'available' => true]);
    DB::table('menu_items')->insert(['menu_id' => $menu->id, 'product_id' => $this->product->id, 'quantity' => 3]);
    $this->cart = ['menu:'.$menu->id => 2];
    $order = restaurantPlace($this);
    expect($order->items->first()->composition)->toBe([['name' => 'Nems', 'quantity' => 3, 'product_id' => $this->product->id]]);
    $this->product->delete();
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
});

test('offers must be valid both now and at pickup', function () {
    $offer = Offer::create(['name' => 'Offre', 'price' => 500, 'available' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addMinutes(10)]);
    DB::table('offer_items')->insert(['offer_id' => $offer->id, 'product_id' => $this->product->id, 'quantity' => 1]);
    $this->cart = ['offer:'.$offer->id => 1];
    expect(fn () => restaurantPlace($this))->toThrow(ValidationException::class);
    $offer->update(['ends_at' => now()->addDay()]);
    expect(restaurantPlace($this)->total)->toBe(500);
});

test('admin routes reject clients and unauthenticated users', function () {
    $this->get('/admin')->assertRedirect('/login');
    $this->actingAs($this->client)->get('/admin')->assertForbidden();
    $this->patch(route('admin.slots.update', $this->slot), ['capacity_override' => 999, 'manually_closed' => 0])->assertForbidden();
    $this->post(route('admin.catalog.save', 'product'), ['name' => 'Pirate'])->assertForbidden();
});

test('after login administrators reach their dashboard and clients resume their cart', function () {
    $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/admin');
    $this->actingAs($this->client)->withSession(['cart' => $this->cart])->get('/dashboard')->assertRedirect('/commande');
});

test('clients cannot read another customers order or elevate their role', function () {
    $order = restaurantPlace($this);
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('orders.show', $order))->assertForbidden();
    $this->patch(route('account.update'), ['name' => 'Client', 'phone' => '0612345678', 'role' => 'admin'])->assertSessionHasNoErrors();
    expect($other->fresh()->role)->toBe('client');
});

test('phone is mandatory and a failed checkout keeps the cart', function () {
    $this->actingAs($this->client)->withSession(['cart' => $this->cart])->post(route('orders.store'), ['slot_id' => $this->slot->id, 'phone' => '', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('phone')->assertSessionHas('cart', $this->cart);
    expect(Order::count())->toBe(0);
});

test('all restaurant screens render with data and empty states', function () {
    foreach (['/', '/menu', '/offres', '/panier'] as $url) {
        $this->get($url)->assertOk();
    }
    $order = restaurantPlace($this);
    $this->actingAs($this->client)->withSession(['cart' => $this->cart]);
    foreach (['/panier?date=2026-09-18', '/mes-commandes', '/mon-compte', '/mes-commandes/'.$order->id] as $url) {
        $this->get($url)->assertOk();
    }
    $this->actingAs($this->admin);
    $this->get('/admin/catalogue/menu/nouveau')->assertRedirect(route('admin.menu-rules'));
    foreach (['/admin', '/admin/commandes', '/admin/commandes/'.$order->id, '/admin/statistiques', '/admin/creneaux?date=2026-09-18', '/admin/calendrier', '/admin/carte', '/admin/menus-personnalisables', '/admin/catalogue/offer/nouveau', '/admin/catalogue/product/'.$this->product->id] as $url) {
        $this->get($url)->assertOk();
    }
});

test('admin creates edits and archives products with integer cents', function () {
    $this->actingAs($this->admin)->post(route('admin.catalog.save', 'product'), ['name' => 'Dessert', 'price' => '4,05', 'available' => 1])->assertSessionHasNoErrors()->assertRedirect();
    $product = Product::where('name', 'Dessert')->firstOrFail();
    expect($product->price)->toBe(405);
    $this->delete(route('admin.catalog.archive', ['product', $product->id]))->assertRedirect();
    expect($product->fresh()->trashed())->toBeTrue();
});

test('invalid capacities and reversed hours are rejected', function () {
    $this->actingAs($this->admin)->patch(route('admin.settings'), ['default_capacity' => -1])->assertSessionHasErrors('default_capacity');
    $this->post(route('admin.hours'), ['weekday' => 5, 'is_open' => 1, 'starts_at' => '23:00', 'ends_at' => '18:00'])->assertSessionHasErrors('ends_at');
});

test('push failure never rolls back a saved order', function () {
    $order = restaurantPlace($this);
    config(['restaurant.vapid.private_key' => null]);
    (new SendOrderPush(DB::table('notification_outbox')->value('id')))->handle();
    expect($order->fresh())->not->toBeNull();
    expect(DB::table('notification_outbox')->value('attempts'))->toBe(1);
    expect(DB::table('notification_outbox')->value('retry_at'))->not->toBeNull();
});

test('push endpoints cannot point at an arbitrary server', function () {
    $this->actingAs($this->admin)->postJson(route('admin.push.store'), ['endpoint' => 'https://127.0.0.1/private', 'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)]])->assertUnprocessable();
    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

test('web push encrypts and signs the payload and records successful delivery without network', function () {
    // The real Web Push implementation runs against an in-memory HTTP transport.
    $keys = VAPID::createVapidKeys();
    $device = VAPID::createVapidKeys();
    config(['restaurant.vapid.private_key' => $keys['privateKey'], 'restaurant.vapid.public_key' => $keys['publicKey'], 'restaurant.vapid.subject' => 'https://restaurant.example']);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/test-device';
    DB::table('push_subscriptions')->insert(['user_id' => $this->admin->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'public_key' => $device['publicKey'], 'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]);
    restaurantPlace($this);
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(201)]));
    $handler->push(Middleware::history($history));
    $transport = new WebPush(['VAPID' => ['subject' => 'https://restaurant.example', 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], [], new Client(['handler' => $handler]));
    $job = new class(DB::table('notification_outbox')->value('id'), $transport) extends SendOrderPush
    {
        public function __construct(int $id, private WebPush $transport)
        {
            parent::__construct($id);
        }

        protected function makeWebPush(): WebPush
        {
            return $this->transport;
        }
    };
    $job->handle();
    expect(DB::table('notification_outbox')->value('sent_at'))->not->toBeNull();
    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getHeaderLine('Content-Encoding'))->toBe('aes128gcm');
    expect($history[0]['request']->getHeaderLine('Authorization'))->toContain('vapid');
    expect((string) $history[0]['request']->getBody())->not->toContain('Nouvelle commande');
    $job->handle();
    expect($history)->toHaveCount(1);
});

test('paid action is idempotent and refuses cancelled orders', function () {
    $order = restaurantPlace($this);
    $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();
    $paidAt = $order->fresh()->paid_at;
    $this->post(route('admin.orders.paid', $order))->assertRedirect();
    expect($order->fresh()->paid_at->eq($paidAt))->toBeTrue();
    $other = restaurantPlace($this);
    app(OrderService::class)->changeStatus($other, $this->admin, 'cancelled');
    $this->post(route('admin.orders.paid', $other))->assertUnprocessable();
});
