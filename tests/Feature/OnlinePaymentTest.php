<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as StripeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    config(['services.stripe.enabled' => true, 'services.stripe.secret' => 'sk_test_123', 'services.stripe.webhook_secret' => 'whsec_test']);
    $this->client = User::factory()->create(['phone' => '+33612345678', 'phone_verified_at' => null]);
    $this->product = Product::create(['name' => 'Nems', 'price' => 650]);
    $this->slot = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-18')[0]['id']);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open']),
        'api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response(['id' => 'cs_test_1', 'status' => 'complete', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => 1300, 'metadata' => ['order_id' => '1']]),
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function stripeWebhook($test, array $event)
{
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

    return $test->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature, 'CONTENT_TYPE' => 'application/json'], $payload);
}

function onlineOrder($test): Order
{
    $test->actingAs($test->client)->withSession(['cart' => ['product:'.$test->product->id => 2], 'cart_displayed_total' => 1300])
        ->post(route('orders.store'), ['slot_id' => $test->slot->id, 'phone' => '06 12 34 56 78', 'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'online'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

    return Order::query()->latest('id')->firstOrFail();
}

test('online payment stays hidden and refused until Stripe is configured', function () {
    config(['services.stripe.enabled' => false]);
    $this->actingAs($this->client)->withSession(['cart' => ['product:'.$this->product->id => 1]])->get(route('cart'))
        ->assertOk()->assertDontSee('Payer en ligne maintenant');
    $this->get(route('home'))->assertSee('Paiement au retrait')->assertDontSee('PAIEMENT EN LIGNE');

    expect(fn () => app(OrderService::class)->place($this->client, $this->slot->id, '0612345678', (string) Str::uuid(), ['product:'.$this->product->id => 1], paymentMethod: 'online'))
        ->toThrow(ValidationException::class);
});

test('an unverified customer can pay online, but must verify the phone to pay on site', function () {
    config(['services.twilio.enabled' => true]);
    $this->actingAs($this->client)->withSession(['cart' => ['product:'.$this->product->id => 1]])->get(route('cart'))
        ->assertOk()->assertSee('Payer en ligne maintenant')->assertSee('vérification de votre téléphone');

    expect(fn () => app(OrderService::class)->place($this->client, $this->slot->id, '+33612345678', (string) Str::uuid(), ['product:'.$this->product->id => 1]))
        ->toThrow(ValidationException::class);

    $order = onlineOrder($this);
    expect($order->status)->toBe('awaiting_payment')->and($order->payment_method)->toBe('online')->and($order->stripe_session_id)->toBe('cs_test_1');
    Http::assertSent(fn (StripeRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && $request['line_items'][0]['price_data']['unit_amount'] === 1300 && $request['metadata']['order_id'] === $order->id);
});

test('an order awaiting payment holds its slot but stays off the kitchen board and notifications', function () {
    $order = onlineOrder($this);

    expect(DB::table('notification_outbox')->where('order_id', $order->id)->exists())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.orders', ['date' => '2026-09-18']))->assertOk()->assertDontSee($order->number);
    expect(collect(app(SlotService::class)->forDate('2026-09-18'))->firstWhere('id', $this->slot->id)['used'])->toBe(1300);
    $this->actingAs($this->client)->get(route('orders.show', $order))->assertOk()->assertSee('Payer maintenant');
});

test('the signed webhook confirms the payment and sends the order to the kitchen once', function () {
    $order = onlineOrder($this);
    $event = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => 1300, 'metadata' => ['order_id' => (string) $order->id]]]];

    $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged'], json_encode($event))->assertStatus(400);
    expect($order->fresh()->status)->toBe('awaiting_payment');

    stripeWebhook($this, $event)->assertOk();
    stripeWebhook($this, $event)->assertOk();
    expect($order->fresh())->status->toBe('received')->paid_at->not->toBeNull()
        ->and(DB::table('notification_outbox')->where('order_id', $order->id)->count())->toBe(1);
});

test('a wrong amount never confirms the payment', function () {
    $order = onlineOrder($this);
    stripeWebhook($this, ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'currency' => 'eur', 'amount_total' => 100, 'metadata' => ['order_id' => (string) $order->id]]]])->assertOk();

    expect($order->fresh())->status->toBe('awaiting_payment')->paid_at->toBeNull();
});

test('coming back from Stripe confirms the payment without waiting for the webhook', function () {
    $order = onlineOrder($this);
    $this->get(route('orders.show', $order).'?paiement=confirme&session_id=cs_test_1')->assertOk()->assertSee('Paiement reçu');

    expect($order->fresh()->status)->toBe('received');
});

test('an expired or abandoned payment cancels the order and frees the slot', function () {
    $order = onlineOrder($this);
    stripeWebhook($this, ['type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_test_1', 'metadata' => ['order_id' => (string) $order->id]]]])->assertOk();
    expect($order->fresh()->status)->toBe('cancelled');

    $late = onlineOrder($this);
    $this->travel(41)->minutes();
    $this->artisan('restaurant:cancel-unpaid-orders')->assertSuccessful();
    expect($late->fresh()->status)->toBe('cancelled');
});
