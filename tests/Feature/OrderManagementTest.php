<?php

use App\Mail\PickupTimeChanged;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Friday 18 September 2026, 17:00 in Paris; the restaurant opens on Fridays and Saturdays by default.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    $this->client = User::factory()->create(['email' => 'client@example.com', 'phone' => '+33612345678', 'phone_verified_at' => now()]);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->nems = Product::create(['name' => 'Nems', 'price' => 650]);
    $this->rice = Product::create(['name' => 'Riz', 'price' => 300]);
    $this->friday = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-18')[0]['id']);
    $this->saturday = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-19')[2]['id']);
    $this->order = app(OrderService::class)->place($this->client, $this->friday->id, '06 12 34 56 78', (string) Str::uuid(), ['product:'.$this->nems->id => 2]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('a new order is received, then the restaurant accepts it, marks it ready and completes it', function () {
    expect($this->order->status)->toBe('received');
    $this->actingAs($this->admin);
    foreach (['preparing', 'ready', 'completed'] as $status) {
        $this->from(route('admin.orders.show', $this->order))->patch(route('admin.orders.status', $this->order), ['status' => $status])
            ->assertRedirect(route('admin.orders.show', $this->order))->assertSessionHasNoErrors();
        expect($this->order->fresh()->status)->toBe($status);
    }
    expect(DB::table('order_status_histories')->where('order_id', $this->order->id)->pluck('to_status')->all())->toBe(['received', 'preparing', 'ready', 'completed']);
    $this->patch(route('admin.orders.status', $this->order), ['status' => 'received'])->assertSessionHasErrors('status');
});

test('the customer follows the progress of the order from their account', function () {
    $this->actingAs($this->admin)->patch(route('admin.orders.status', $this->order), ['status' => 'ready']);

    $this->actingAs($this->client)->get(route('orders.show', $this->order))->assertOk()
        ->assertSee('SUIVI EN DIRECT')->assertSee('aria-current="step"', false)->assertSee('vous pouvez venir la retirer')
        ->assertSee('data-order-tracking', false);
    $this->get(route('orders.index'))->assertOk()->assertSee('Prête');
    $this->getJson(route('orders.tracking', $this->order))->assertOk()->assertJsonPath('status', 'ready');

    $this->actingAs(User::factory()->create())->getJson(route('orders.tracking', $this->order))->assertForbidden();
});

test('the restaurant adds and removes articles, and the total follows', function () {
    $this->actingAs($this->admin)->get(route('admin.orders.show', $this->order))->assertOk()
        ->assertSee('AJOUTER UN ARTICLE')->assertSee('data-confirm', false)->assertSee('Changer l’heure de retrait');

    $this->post(route('admin.orders.items.add', $this->order), ['item' => 'product:'.$this->rice->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->post(route('admin.orders.items.add', $this->order), ['item' => 'product:'.$this->nems->id, 'quantity' => 1])->assertSessionHasNoErrors();
    $order = $this->order->fresh('items');
    expect($order->total)->toBe(3 * 650 + 2 * 300)->and($order->items)->toHaveCount(2)
        ->and($order->items->firstWhere('item_id', $this->nems->id)->quantity)->toBe(3);

    $rice = $order->items->firstWhere('item_id', $this->rice->id);
    $this->delete(route('admin.orders.items.remove', [$order, $rice->id]))->assertSessionHasNoErrors();
    expect($order->fresh()->total)->toBe(3 * 650);

    $last = $order->fresh('items')->items->first();
    $this->delete(route('admin.orders.items.remove', [$order, $last->id]))->assertSessionHasErrors('item');
    $this->rice->update(['available' => false]);
    $this->post(route('admin.orders.items.add', $order), ['item' => 'product:'.$this->rice->id, 'quantity' => 1])->assertSessionHasErrors('item');
    expect(DB::table('order_status_histories')->where('order_id', $order->id)->where('to_status', 'edited')->count())->toBe(3);
});

test('only an order in progress can be edited, and only by the restaurant', function () {
    $this->actingAs($this->client)->post(route('admin.orders.items.add', $this->order), ['item' => 'product:'.$this->rice->id, 'quantity' => 1])->assertForbidden();

    app(OrderService::class)->changeStatus($this->order, $this->admin, 'completed');
    expect(fn () => app(OrderService::class)->addItem($this->order, $this->admin, 'product:'.$this->rice->id, 1))->toThrow(ValidationException::class);
    expect(fn () => app(OrderService::class)->reschedule($this->order, $this->admin, $this->saturday->id))->toThrow(ValidationException::class);
});

test('a new pickup time is emailed to the customer and texted when SMS is set up', function () {
    Mail::fake();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    config(['services.sms.enabled' => true, 'services.sms.from' => '+33700000000', 'services.twilio.account_sid' => 'AC1', 'services.twilio.auth_token' => 'token']);

    $this->actingAs($this->admin)->patch(route('admin.orders.reschedule', $this->order), ['slot_id' => $this->saturday->id])
        ->assertRedirect(route('admin.orders.show', $this->order))->assertSessionHasNoErrors();

    expect($this->order->fresh()->time_slot_id)->toBe($this->saturday->id)
        ->and(DB::table('order_status_histories')->where('order_id', $this->order->id)->where('to_status', 'rescheduled')->exists())->toBeTrue();
    Mail::assertSent(PickupTimeChanged::class, fn (PickupTimeChanged $mail) => $mail->hasTo('client@example.com') && $mail->previous->is($this->friday));
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/Accounts/AC1/Messages.json') && $request['To'] === '+33612345678' && str_contains($request['Body'], $this->saturday->date->format('d/m')));
});

test('without an SMS provider only the email is sent, and staff orders get no email', function () {
    Mail::fake();
    Http::fake();
    $this->actingAs($this->admin)->patch(route('admin.orders.reschedule', $this->order), ['slot_id' => $this->saturday->id]);
    Mail::assertSent(PickupTimeChanged::class);
    Http::assertNothingSent();

    Mail::fake();
    $phoneOrder = app(OrderService::class)->placeForStaff($this->admin, $this->friday->id, '0611223344', (string) Str::uuid(), ['product:'.$this->nems->id => 1], 'Client au téléphone', 'phone', 650);
    app(OrderService::class)->reschedule($phoneOrder, $this->admin, $this->saturday->id);
    Mail::assertNothingSent();
});

test('a pickup cannot be moved to a slot that has already started', function () {
    $started = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-18')[1]['id']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 '.$started->starts_at, 'Europe/Paris')->addMinutes(5));

    expect(fn () => app(OrderService::class)->reschedule($this->order, $this->admin, $started->id))->toThrow(ValidationException::class);
    expect($this->order->fresh()->time_slot_id)->toBe($this->friday->id);
});
