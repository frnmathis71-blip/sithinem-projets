<?php

use App\Models\Product;
use App\Models\User;
use App\Services\OrderPrintingService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    config(['restaurant.printing.token' => str_repeat('a', 64)]);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->customer = User::factory()->create(['phone' => '+33612345678', 'phone_verified_at' => now()]);
    $product = Product::create(['name' => 'Curry — Poulet', 'price' => 1200]);
    $this->makeOrder = fn ($date = '2026-09-18') => app(OrderService::class)->place(
        $this->customer, app(SlotService::class)->forDate($date)[0]['id'], '0612345678',
        (string) Str::uuid(), ['product:'.$product->id => 2], customerMessage: "Sans oignons\n<script>alert(1)</script>"
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('tickets require admin and contain escaped snapshots and only the restaurant current day', function () {
    $order = ($this->makeOrder)();
    $future = ($this->makeOrder)('2026-09-19');
    $this->get(route('admin.orders.tickets'))->assertRedirect(route('login'));
    $this->actingAs($this->customer)->get(route('admin.orders.tickets'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.orders.tickets', ['date' => '2026-09-19', 'width' => 58]))
        ->assertOk()->assertSee('data-ticket="'.$order->id.'"', false)
        ->assertDontSee('data-ticket="'.$future->id.'"', false)
        ->assertSee('2 × Curry — Poulet')->assertSee($order->number)
        ->assertSee($order->customer_message)->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('width: 58mm', false);
    $this->getJson(route('admin.orders.tickets', ['width' => 999]))->assertUnprocessable();
});

test('agent requires its own secret and automatically claims each eligible order once', function () {
    $order = ($this->makeOrder)();
    ($this->makeOrder)('2026-09-19');
    $cancelled = ($this->makeOrder)();
    $cancelled->update(['status' => 'cancelled']);
    $this->postJson('/api/printing/claim')->assertUnauthorized();
    $this->actingAs($this->admin)->postJson('/api/printing/claim')->assertUnauthorized();
    $this->withToken(str_repeat('a', 64));
    $job = $this->postJson('/api/printing/claim')->assertOk()->json('job');
    expect($job['text'])->toContain('COMMANDE #'.$order->id, '2 x Curry — Poulet', 'Sans oignons');
    $this->postJson('/api/printing/claim')->assertJsonPath('job', null);
    $this->postJson('/api/printing/jobs/'.$job['id'].'/acknowledge', ['token' => (string) Str::uuid(), 'success' => true])->assertOk();
    expect(DB::table('order_print_jobs')->value('status'))->toBe('printing');
    $this->postJson('/api/printing/jobs/'.$job['id'].'/acknowledge', ['token' => $job['token'], 'success' => true])->assertOk();
    $this->postJson('/api/printing/claim')->assertJsonPath('job', null);
    expect(DB::table('order_print_jobs')->value('status'))->toBe('submitted');
});

test('daily batch is idempotent includes all statuses and does not duplicate automatic pending work', function () {
    ($this->makeOrder)();
    ($this->makeOrder)()->update(['status' => 'completed']);
    ($this->makeOrder)()->update(['status' => 'cancelled']);
    ($this->makeOrder)('2026-09-19');
    $batch = (string) Str::uuid();
    $this->actingAs($this->customer)->post(route('admin.orders.print'), ['batch' => $batch])->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.orders.print'), ['batch' => $batch])->assertRedirect();
    $this->post(route('admin.orders.print'), ['batch' => $batch])->assertRedirect();
    expect(DB::table('order_print_jobs')->where('status', 'pending')->count())->toBe(3);
    $service = app(OrderPrintingService::class);
    $jobs = [$service->claim(), $service->claim(), $service->claim()];
    expect(array_column($jobs, 'id'))->toHaveCount(3)->and($service->claim())->toBeNull();
});

test('uncertain delivery needs explicit retry and a late acknowledgement cannot affect a new claim', function () {
    ($this->makeOrder)();
    $service = app(OrderPrintingService::class);
    $first = $service->claim();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
    expect($service->claim())->toBeNull();
    expect(DB::table('order_print_jobs')->value('status'))->toBe('uncertain');
    $service->retry($first['id']);
    $second = $service->claim();
    expect($second['id'])->toBe($first['id'])->and($second['token'])->not->toBe($first['token']);
    expect($service->acknowledge($first['id'], $first['token'], true, null))->toBeFalse();
    expect($service->acknowledge($second['id'], $second['token'], true, null))->toBeTrue();
});

test('menu snapshots and multiline comments survive printing and old pending tickets are skipped', function () {
    $order = ($this->makeOrder)();
    $order->items()->first()->update(['item_type' => 'menu', 'name' => 'Menu duo', 'composition' => [['name' => 'Curry — Crevettes', 'quantity' => 1]]]);
    $service = app(OrderPrintingService::class);
    expect($service->text($order->load(['slot', 'items'])))->toContain('1 x Curry — Crevettes (par menu)', "Sans oignons\n");
    $service->enqueueToday((string) Str::uuid());
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 15:00:00', 'UTC'));
    expect($service->claim())->toBeNull();
});
