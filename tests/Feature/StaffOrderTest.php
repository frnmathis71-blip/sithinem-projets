<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    config(['services.twilio.enabled' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'phone' => '+33699999999', 'phone_verified_at' => null]);
    $this->product = Product::create(['name' => 'Curry de poulet', 'price' => 1200]);
    $this->cart = ['product:'.$this->product->id => 2];
    $this->data = [
        'customer_name' => 'Client téléphone', 'phone' => '0612345678', 'source' => 'phone',
        'slot_id' => app(SlotService::class)->forDate('2026-09-18')[0]['id'],
        'idempotency_key' => (string) Str::uuid(), 'expected_total' => 2400,
        'customer_message' => 'Sans oignons',
    ];
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('only administrators can enter telephone and counter orders', function () {
    $this->get(route('admin.orders.create'))->assertRedirect(route('login'));
    $customer = User::factory()->create();
    $this->actingAs($customer)->get(route('admin.orders.create'))->assertForbidden();
    $this->withSession(['cart' => $this->cart])->post(route('admin.orders.store'), $this->data)->assertForbidden();
    expect(Order::count())->toBe(0);
});

test('staff checkout reuses the cart without SMS and records the customer and actor separately', function () {
    $this->actingAs($this->admin)->withSession(['cart' => $this->cart])
        ->get(route('admin.orders.create', ['date' => '2026-09-18']))
        ->assertOk()->assertSee('Nom du client')->assertSee('name="expected_total" value="2400"', false)
        ->assertDontSee('Vérifiez votre téléphone par SMS');
    $this->post(route('admin.orders.store'), $this->data)->assertSessionHasNoErrors()->assertRedirect();
    $order = Order::sole();
    expect($order->customer_name)->toBe('Client téléphone')
        ->and($order->source)->toBe('phone')->and($order->phone)->toBe('+33612345678')
        ->and($order->user_id)->toBe($this->admin->id)->and($order->total)->toBe(2400)
        ->and($order->items()->sole()->quantity)->toBe(2)
        ->and($order->customer_message)->toBe('Sans oignons')
        ->and($this->admin->fresh()->phone)->toBe('+33699999999')
        ->and(DB::table('order_status_histories')->value('user_id'))->toBe($this->admin->id);
    $this->post(route('admin.orders.store'), $this->data)->assertSessionHasNoErrors();
    expect(Order::count())->toBe(1);
    $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('Téléphone');
    foreach (['preparing', 'ready', 'completed'] as $status) {
        $this->patch(route('admin.orders.status', $order), ['status' => $status])->assertRedirect();
    }
    expect($order->fresh()->status)->toBe('completed');
});

test('staff orders cannot bypass changed prices or closed capacity', function () {
    $this->actingAs($this->admin)->withSession(['cart' => $this->cart]);
    $this->post(route('admin.orders.store'), [...$this->data, 'expected_total' => 1])->assertSessionHasErrors('cart');
    TimeSlot::findOrFail($this->data['slot_id'])->update(['amount_capacity_override' => 0]);
    $this->post(route('admin.orders.store'), $this->data)->assertSessionHasErrors('slot_id');
    expect(Order::count())->toBe(0);
});

test('counter orders are supported and ordinary online checkout still requires SMS verification', function () {
    $this->actingAs($this->admin)->withSession(['cart' => $this->cart])
        ->post(route('admin.orders.store'), [...$this->data, 'source' => 'counter'])->assertSessionHasNoErrors();
    expect(Order::sole()->source)->toBe('counter');
    expect(fn () => app(OrderService::class)->place($this->admin, $this->data['slot_id'], '0612345678', (string) Str::uuid(), $this->cart))
        ->toThrow(ValidationException::class);
});
