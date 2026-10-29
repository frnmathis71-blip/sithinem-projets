<?php

use App\Models\Offer;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Friday 18 September 2026; the restaurant opens on Fridays and Saturdays by default.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    $this->client = User::factory()->create(['phone' => '+33612345678', 'phone_verified_at' => now()]);
    $this->friday = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-18')[0]['id']);
    $this->saturday = TimeSlot::query()->findOrFail(app(SlotService::class)->forDate('2026-09-19')[0]['id']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the weekday limit is off by default and existing items stay available every day', function () {
    $product = Product::create(['name' => 'Nems', 'price' => 650]);

    expect($product->fresh()->weekdays())->toBeNull()
        ->and($product->weekdayLabel())->toBeNull()
        ->and(app(CatalogService::class)->available($product, CarbonImmutable::parse('2026-09-21 19:00', 'Europe/Paris')))->toBeTrue();
});

test('the admin can limit a dish or an offer to some weekdays, then remove the limit', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->post(route('admin.catalog.save', 'product'), ['name' => 'Bo bun du mercredi', 'price' => '12', 'available' => 1, 'limit_weekdays' => 1, 'available_weekdays' => [3]])->assertSessionHasNoErrors();
    $product = Product::where('name', 'Bo bun du mercredi')->firstOrFail();
    expect($product->weekdays())->toBe([3])->and($product->weekdayLabel())->toBe('Uniquement le mercredi');

    $this->get(route('admin.catalog.edit', ['product', $product->id]))->assertOk()->assertSee('Valable uniquement certains jours');
    $this->post(route('admin.catalog.save', ['product', $product->id]), ['name' => 'Bo bun du mercredi', 'price' => '12', 'available' => 1, 'limit_weekdays' => 1])->assertSessionHasErrors('available_weekdays');
    $this->post(route('admin.catalog.save', ['product', $product->id]), ['name' => 'Bo bun du mercredi', 'price' => '12', 'available' => 1, 'limit_weekdays' => 0, 'available_weekdays' => [3]])->assertSessionHasNoErrors();
    expect($product->fresh()->weekdays())->toBeNull();

    $nems = Product::create(['name' => 'Nems', 'price' => 650]);
    $this->post(route('admin.catalog.save', 'offer'), [
        'name' => 'Duo du week-end', 'price' => '15', 'available' => 1, 'starts_at' => '2026-09-18T00:00', 'no_end_date' => 1,
        'composition' => [$nems->id => 2], 'limit_weekdays' => 1, 'available_weekdays' => [7, 6],
    ])->assertSessionHasNoErrors();
    expect(Offer::where('name', 'Duo du week-end')->firstOrFail()->weekdayLabel())->toBe('Uniquement les samedi et dimanche');
});

test('an order is only accepted for a pickup on an allowed weekday, variants included', function () {
    $saturdayOnly = Product::create(['name' => 'Curry', 'price' => 1000, 'has_variants' => true, 'available_weekdays' => [6]]);
    $variant = Product::create(['name' => 'Curry', 'parent_id' => $saturdayOnly->id, 'variant_label' => 'Poulet', 'price' => 1000]);
    $place = fn (TimeSlot $slot) => app(OrderService::class)->place($this->client, $slot->id, '06 12 34 56 78', (string) Str::uuid(), ['product:'.$variant->id => 1]);

    expect(fn () => $place($this->friday))->toThrow(ValidationException::class);
    expect($place($this->saturday)->total)->toBe(1000);
});

test('an offer is refused on another day, even when only a dish inside it is limited', function () {
    $saturdayDish = Product::create(['name' => 'Sushi', 'price' => 900, 'available_weekdays' => [6]]);
    $offer = Offer::create(['name' => 'Plateau', 'price' => 1500, 'available' => true, 'starts_at' => now()->subDay()]);
    DB::table('offer_items')->insert(['offer_id' => $offer->id, 'product_id' => $saturdayDish->id, 'quantity' => 2]);

    expect(fn () => app(OrderService::class)->place($this->client, $this->friday->id, '06 12 34 56 78', (string) Str::uuid(), ['offer:'.$offer->id => 1]))->toThrow(ValidationException::class);
    expect(app(CatalogService::class)->cartWeekdays(['offer:'.$offer->id => 1]))->toBe([6]);
});

test('the cart only offers pickup dates every item allows and explains why', function () {
    $saturdayOnly = Product::create(['name' => 'Sushi du samedi', 'price' => 900, 'available_weekdays' => [6]]);
    $this->get(route('menu'))->assertOk()->assertSee('Uniquement le samedi');

    $cart = $this->withSession(['cart' => ['product:'.$saturdayOnly->id => 1]])->get(route('cart'))->assertOk()
        ->assertSee('value="2026-09-19"', false)->assertDontSee('value="2026-09-18"', false)
        ->assertSee('seules les dates compatibles sont affichées');
    $this->withSession(['cart' => ['product:'.$saturdayOnly->id => 1]])->get(route('cart', ['date' => '2026-09-18']))->assertRedirect(route('cart'));

    $fridayOnly = Product::create(['name' => 'Bo bun du vendredi', 'price' => 1100, 'available_weekdays' => [5]]);
    $this->withSession(['cart' => ['product:'.$saturdayOnly->id => 1, 'product:'.$fridayOnly->id => 1]])->get(route('cart'))->assertOk()
        ->assertSee('ne sont pas proposés le même jour de la semaine');
});
