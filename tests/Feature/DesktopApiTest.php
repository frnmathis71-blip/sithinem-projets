<?php

use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    config(['services.twilio.enabled' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'chef@example.com', 'password' => 'secret-password', 'phone' => '+33699999999']);
    $main = Category::create(['name' => 'Plats', 'code' => 'main']);
    $side = Category::create(['name' => 'Accompagnements', 'code' => 'side']);
    $dessert = Category::create(['name' => 'Desserts', 'code' => 'dessert']);
    $this->main = Product::create(['name' => 'Curry de poulet', 'price' => 1200, 'category_id' => $main->id]);
    $this->side = Product::create(['name' => 'Riz', 'price' => 300, 'category_id' => $side->id]);
    $this->dessert = Product::create(['name' => 'Perles de coco', 'price' => 500, 'category_id' => $dessert->id]);
    $this->rule = MenuRule::create(['name' => 'Menu midi', 'category_key' => 'main,dessert', 'price' => 1800]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function desktopToken($test): string
{
    return $test->postJson(route('desktop.login'), ['email' => 'chef@example.com', 'password' => 'secret-password'])->assertOk()->json('token');
}

test('only administrators obtain a revocable hashed token', function () {
    $this->postJson(route('desktop.login'), ['email' => 'chef@example.com', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
    User::factory()->create(['email' => 'client@example.com', 'password' => 'secret-password']);
    $this->postJson(route('desktop.login'), ['email' => 'client@example.com', 'password' => 'secret-password'])->assertUnprocessable();
    $this->getJson(route('desktop.orders'))->assertUnauthorized();

    $token = desktopToken($this);
    expect(strlen($token))->toBe(64)->and(DB::table('desktop_tokens')->value('token_hash'))->toBe(hash('sha256', $token));
    $this->withToken($token)->getJson(route('desktop.me'))->assertOk()->assertJsonPath('user.email', 'chef@example.com');
    $this->withToken($token)->deleteJson(route('desktop.logout'))->assertOk();
    $this->withToken($token)->getJson(route('desktop.me'))->assertUnauthorized();
});

test('administrators with two-factor authentication must give a valid code', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $this->admin->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])), 'two_factor_confirmed_at' => now()])->save();
    $credentials = ['email' => 'chef@example.com', 'password' => 'secret-password'];
    $this->postJson(route('desktop.login'), $credentials)->assertUnprocessable()->assertJsonPath('two_factor', true);
    $this->postJson(route('desktop.login'), [...$credentials, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(route('desktop.login'), [...$credentials, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk()->assertJsonStructure(['token']);
    $this->postJson(route('desktop.login'), [...$credentials, 'code' => 'recovery-code-1'])->assertOk();
    $this->postJson(route('desktop.login'), [...$credentials, 'code' => 'recovery-code-1'])->assertUnprocessable();
});

test('demoted administrators and idle tokens lose access', function () {
    $token = desktopToken($this);
    $this->admin->forceFill(['role' => 'customer'])->save();
    $this->withToken($token)->getJson(route('desktop.orders'))->assertForbidden();
    $this->admin->forceFill(['role' => 'admin'])->save();
    DB::table('desktop_tokens')->update(['last_used_at' => now()->subDays(31)]);
    $this->withToken($token)->getJson(route('desktop.orders'))->assertUnauthorized();
    expect(DB::table('desktop_tokens')->count())->toBe(0);
});

test('order taking prices the cart on the server, proposes formulas and places the order once', function () {
    $token = desktopToken($this);
    $catalog = $this->withToken($token)->getJson(route('desktop.ordering.catalog'))->assertOk();
    expect(collect($catalog->json('products'))->firstWhere('id', $this->main->id)['options'][0]['side_ids'])->toBe([$this->side->id])
        ->and($catalog->json('formulas.0.steps'))->toBe(['main', 'side', 'dessert']);

    $pair = $this->withToken($token)->postJson(route('desktop.ordering.compose'), ['selections' => ['main' => $this->main->id, 'side' => $this->side->id]])->assertOk()->json('key');
    $items = [['key' => $pair, 'quantity' => 1], ['key' => 'product:'.$this->dessert->id, 'quantity' => 1]];
    $quote = $this->withToken($token)->postJson(route('desktop.ordering.quote'), ['items' => $items])->assertOk();
    expect($quote->json('total'))->toBe(2000)->and($quote->json('suggestion.after'))->toBe(1800);
    $items = $this->withToken($token)->postJson(route('desktop.ordering.suggestion'), ['items' => $items, 'signature' => $quote->json('suggestion.signature')])->assertOk()->json('items');
    expect($items)->toHaveCount(1);
    expect($this->withToken($token)->postJson(route('desktop.ordering.quote'), ['items' => $items])->json('total'))->toBe(1800);

    $slots = $this->withToken($token)->getJson(route('desktop.ordering.slots', ['date' => '2026-09-18', 'total' => 1800]))->assertOk();
    $slot = collect($slots->json('slots'))->firstWhere('selectable', true);
    $order = ['items' => $items, 'customer_name' => 'Client comptoir', 'source' => 'counter', 'phone' => '06 12 34 56 78', 'slot_id' => $slot['id'],
        'idempotency_key' => $slots->json('idempotency_key'), 'expected_total' => 1800, 'customer_message' => 'Bien épicé'];
    $created = $this->withToken($token)->postJson(route('desktop.orders.store'), $order)->assertCreated();
    expect($created->json('order.source_label'))->toBe('Comptoir')->and($created->json('order.items.0.composition'))->toHaveCount(3);
    $this->withToken($token)->postJson(route('desktop.orders.store'), $order)->assertCreated();
    expect(Order::count())->toBe(1)->and(Order::sole()->user_id)->toBe($this->admin->id)->and($this->admin->fresh()->phone)->toBe('+33699999999');

    $this->withToken($token)->postJson(route('desktop.orders.store'), [...$order, 'idempotency_key' => (string) Str::uuid(), 'expected_total' => 1])->assertUnprocessable()->assertJsonValidationErrors('cart');
    $this->withToken($token)->postJson(route('desktop.orders.store'), [...$order, 'items' => [['key' => 'menu:1', 'quantity' => 1]]])->assertUnprocessable();
});

test('the board, status, payment and tickets follow the web rules', function () {
    $token = desktopToken($this);
    $slot = app(SlotService::class)->forDate('2026-09-18')[0];
    $id = $this->withToken($token)->postJson(route('desktop.orders.store'), ['items' => [['key' => 'product:'.$this->main->id, 'quantity' => 2]], 'customer_name' => 'Client téléphone', 'source' => 'phone',
        'phone' => '0612345678', 'slot_id' => $slot['id'], 'idempotency_key' => (string) Str::uuid(), 'expected_total' => 2400])->assertCreated()->json('order.id');

    $this->withToken($token)->getJson(route('desktop.orders', ['date' => '2026-09-18']))->assertOk()->assertJsonPath('orders.0.id', $id)->assertJsonPath('orders.0.items.0.quantity', 2);
    $this->withToken($token)->getJson(route('desktop.orders.ticket', $id))->assertOk()->assertJsonPath('title', 'Sithinem #'.$id);
    $this->withToken($token)->postJson(route('desktop.orders.paid', $id))->assertOk()->assertJsonPath('order.paid', true);
    $this->withToken($token)->patchJson(route('desktop.orders.status', $id), ['status' => 'completed'])->assertUnprocessable();
    foreach (['preparing', 'ready', 'completed'] as $status) {
        $this->withToken($token)->patchJson(route('desktop.orders.status', $id), ['status' => $status])->assertOk()->assertJsonPath('order.status', $status);
    }
    $this->withToken($token)->patchJson(route('desktop.orders.status', $id), ['status' => 'cancelled'])->assertUnprocessable();
    $this->withToken($token)->getJson(route('desktop.orders', ['date' => '2026-09-18', 'view' => 'history']))->assertJsonCount(1, 'orders');
    $this->withToken($token)->getJson(route('desktop.orders.show', $id))->assertJsonCount(4, 'order.history');
    $this->withToken($token)->getJson(route('desktop.statistics'))->assertOk()->assertJsonStructure(['report' => ['periods', 'chart'], 'waiting']);
});

test('the live feed reports every new order whatever its pickup day', function () {
    $token = desktopToken($this);
    $start = $this->withToken($token)->getJson(route('desktop.orders.recent'))->assertOk()->assertJsonCount(0, 'orders')->json('latest_id');
    foreach (['2026-09-18', '2026-09-19'] as $date) {
        $this->withToken($token)->postJson(route('desktop.orders.store'), ['items' => [['key' => 'product:'.$this->main->id, 'quantity' => 1]], 'customer_name' => 'Client '.$date, 'source' => 'phone',
            'phone' => '0612345678', 'slot_id' => app(SlotService::class)->forDate($date)[0]['id'], 'idempotency_key' => (string) Str::uuid(), 'expected_total' => 1200])->assertCreated();
    }
    $feed = $this->withToken($token)->getJson(route('desktop.orders.recent', ['after' => $start]))->assertOk();
    expect(array_column($feed->json('orders'), 'customer_name'))->toBe(['Client 2026-09-18', 'Client 2026-09-19'])
        ->and($feed->json('preparing_today'))->toBe(1)->and($feed->json('latest_id'))->toBe(Order::max('id'));
    $this->withToken($token)->getJson(route('desktop.orders.recent', ['after' => $feed->json('latest_id')]))->assertJsonCount(0, 'orders');
});

test('card, formulas, slots and calendar are managed with the same validation as the web back office', function () {
    $token = desktopToken($this);
    $this->withToken($token)->postJson(route('desktop.card.save', 'product'), ['name' => 'Bobun', 'price' => '9,50', 'available' => true, 'category_id' => 'type:main',
        'has_variants' => '1', 'variants' => [['label' => 'Petit', 'price' => '9,50', 'available' => true], ['label' => 'Grand', 'price' => '12', 'available' => true]]])->assertCreated();
    $bobun = Product::query()->where('name', 'Bobun')->whereNull('parent_id')->sole();
    expect($bobun->price)->toBe(950)->and($bobun->variants)->toHaveCount(2);
    $this->withToken($token)->getJson(route('desktop.card.item', ['product', $bobun->id]))->assertOk()->assertJsonCount(2, 'item.variants');
    $this->withToken($token)->postJson(route('desktop.card.save', 'offer'), ['name' => 'Offre', 'price' => '10', 'available' => true, 'starts_at' => '2026-09-18T10:00', 'no_end_date' => '1', 'composition' => []])->assertUnprocessable()->assertJsonValidationErrors('composition');
    $this->withToken($token)->postJson(route('desktop.formulas.save'), ['name' => 'Double', 'categories' => ['main', 'chef_main'], 'price' => '20', 'available' => true, 'visible' => true, 'position' => 1])->assertUnprocessable();
    $this->withToken($token)->postJson(route('desktop.formulas.save', $this->rule->id), ['name' => 'Menu soir', 'categories' => ['main', 'dessert'], 'price' => '19,90', 'available' => true, 'visible' => false, 'position' => 2])->assertOk();
    expect($this->rule->fresh()->price)->toBe(1990)->and($this->rule->fresh()->version)->toBe(2);
    $this->withToken($token)->postJson(route('desktop.categories.save'), ['name' => 'Boissons', 'code' => 'drink', 'position' => 4])->assertOk();
    $this->withToken($token)->deleteJson(route('desktop.categories.delete', Category::query()->where('code', 'side')->value('id')))->assertOk();
    expect($this->side->fresh()->available)->toBeFalse();
    $this->withToken($token)->deleteJson(route('desktop.card.archive', ['product', $this->dessert->id]))->assertOk();
    expect(Product::find($this->dessert->id))->toBeNull();

    $slots = $this->withToken($token)->getJson(route('desktop.slots', ['date' => '2026-09-18']))->assertOk();
    $this->withToken($token)->patchJson(route('desktop.slots.update', $slots->json('slots.0.id')), ['amount_capacity_override' => '45.50', 'manually_closed' => true])->assertOk();
    expect(TimeSlot::find($slots->json('slots.0.id'))->amount_capacity_override)->toBe(4550);
    $this->withToken($token)->patchJson(route('desktop.capacity'), ['default_amount_capacity' => '-1'])->assertUnprocessable();
    $this->withToken($token)->postJson(route('desktop.exceptions'), ['date' => '2026-09-25', 'is_open' => false, 'starts_at' => '18:00', 'ends_at' => '23:00', 'reason' => 'Congés'])->assertOk();
    $calendar = $this->withToken($token)->getJson(route('desktop.calendar', ['month' => '2026-09']))->assertOk()->assertJsonPath('exceptions.0.reason', 'Congés');
    $this->withToken($token)->deleteJson(route('desktop.exceptions.delete', $calendar->json('exceptions.0.id')))->assertOk();
    $this->withToken($token)->postJson(route('desktop.hours'), ['weekday' => 1, 'is_open' => true, 'starts_at' => '20:00', 'ends_at' => '19:00'])->assertUnprocessable();
});
