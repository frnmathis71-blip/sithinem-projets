<?php

use App\Models\Menu;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\SlotService;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    DB::disableQueryLog();
});

test('booking dates use two queries and immediately reflect dated exceptions', function () {
    $settings = RestaurantSetting::findOrFail(1);
    $service = app(SlotService::class);
    DB::enableQueryLog();
    $dates = $service->bookingDates($settings);
    expect(DB::getQueryLog())->toHaveCount(2);
    expect(array_column($dates, 'date'))->toContain('2026-09-18');

    DB::table('opening_exceptions')->insert(['date' => '2026-09-18', 'is_open' => false]);
    expect(array_column($service->bookingDates($settings), 'date'))->not->toContain('2026-09-18');
});

test('batched slot descriptions match fresh checks and react to order changes', function () {
    $service = app(SlotService::class);
    $settings = RestaurantSetting::findOrFail(1);
    $slots = $service->forDate('2026-09-18');
    expect($slots)->toBe($service->forDate('2026-09-18'));
    $slot = TimeSlot::findOrFail($slots[0]['id']);
    $user = User::factory()->create();
    foreach (['preparing', 'completed', 'cancelled'] as $status) {
        Order::create(['number' => (string) Str::ulid(), 'user_id' => $user->id,
            'time_slot_id' => $slot->id, 'customer_name' => 'Test', 'phone' => '0612345678',
            'total' => 650, 'status' => $status, 'idempotency_key' => (string) Str::uuid()]);
    }

    DB::enableQueryLog();
    $descriptions = $service->forDate('2026-09-18', 1000);
    $orderQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"orders"'));
    expect($orderQueries)->toHaveCount(1);
    $slotQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "time_slots"'));
    expect($slotQueries)->toHaveCount(1);
    foreach ($descriptions as $description) {
        expect($description)->toBe($service->describe(TimeSlot::findOrFail($description['id']), $settings, 1000));
    }
    expect($descriptions[0]['count'])->toBe(2)->and($descriptions[0]['used'])->toBe(1300);
    Order::where('status', 'preparing')->update(['status' => 'cancelled']);
    expect($service->describe($slot, $settings)['used'])->toBe(650);
    expect($service->forDate('2026-09-18')[0]['used'])->toBe(650);
});

test('catalog composition batches product reads while preserving archive validation', function () {
    $menu = Menu::create(['name' => 'Menu', 'price' => 2000, 'available' => true]);
    $products = collect(range(1, 10))->map(fn ($i) => Product::create(['name' => 'Produit '.$i, 'price' => 100, 'available' => true]));
    foreach ($products as $product) {
        DB::table('menu_items')->insert(['menu_id' => $menu->id, 'product_id' => $product->id, 'quantity' => 2]);
    }
    DB::enableQueryLog();
    $composition = app(CatalogService::class)->composition($menu, true);
    expect(DB::getQueryLog())->toHaveCount(2);
    expect($composition)->toHaveCount(10);
    expect(array_column($composition, 'quantity'))->toBe(array_fill(0, 10, 2));
    $products->first()->delete();
    expect(app(CatalogService::class)->composition($menu))->toBe($composition);
    expect(fn () => app(CatalogService::class)->composition($menu, true))->toThrow(ValidationException::class);
});

test('statistics preserve Paris day boundaries and exclude cancelled sales', function () {
    $slot = TimeSlot::findOrFail(app(SlotService::class)->forDate('2026-09-18')[0]['id']);
    $user = User::factory()->create();
    foreach ([['2026-09-17 21:59:59', 'completed', 100], ['2026-09-17 22:00:00', 'completed', 200], ['2026-09-18 14:00:00', 'preparing', 300], ['2026-09-18 14:00:00', 'cancelled', 900]] as [$created, $status, $total]) {
        Order::create(['number' => (string) Str::ulid(), 'user_id' => $user->id,
            'time_slot_id' => $slot->id, 'customer_name' => 'Test', 'phone' => '0612345678',
            'total' => $total, 'status' => $status, 'idempotency_key' => (string) Str::uuid(), 'created_at' => $created]);
    }
    $report = app(StatisticsService::class)->report('Europe/Paris');
    expect($report['periods'][0]['revenue'])->toBe(500)->and($report['periods'][0]['count'])->toBe(2);
    expect($report['chart'][12])->toBe(['label' => '17/09', 'value' => 100, 'count' => 1]);
    expect($report['chart'][13])->toBe(['label' => '18/09', 'value' => 500, 'count' => 2]);
    expect($report['pending'])->toBe(1)->and($report['completed'])->toBe(2);
});
