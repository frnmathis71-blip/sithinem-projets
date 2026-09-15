<?php

use App\Http\Controllers\DynamicMenuController;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->menus = app(DynamicMenuService::class);
    $this->products = [];
    foreach (['starter' => 800, 'main' => 1500, 'side' => 400, 'dessert' => 600, 'drink' => 400, 'chef_main' => 1700] as $code => $price) {
        $category = Category::create(['name' => $code, 'code' => $code]);
        $this->products[$code] = Product::create(['name' => $code, 'price' => $price, 'category_id' => $category->id]);
    }
    $this->rule = MenuRule::create(['category_key' => 'main,dessert', 'price' => 2000]);
    $this->selection = ['main' => $this->products['main']->id, 'side' => $this->products['side']->id, 'dessert' => $this->products['dessert']->id];
    $this->pair = $this->menus->key(array_diff_key($this->selection, ['dessert' => 1]));
});

test('classic main courses can be ordered alone while composed pairs require compatible available sides', function () {
    foreach (['main'] as $code) {
        expect(app(CatalogService::class)->quote(['product:'.$this->products[$code]->id => 1])['total'])->toBe(1500);
        $key = $this->menus->key(['main' => $this->products[$code]->id, 'side' => $this->products['side']->id]);
        expect($this->menus->quote($key, 1)['composition'])->toHaveCount(2);
    }
    $this->products['main']->update(['restricted_sides' => true, 'compatible_side_ids' => []]);
    expect(fn () => $this->menus->quote($this->pair, 1))->toThrow(ValidationException::class);
    $this->products['main']->update(['compatible_side_ids' => [$this->products['side']->id]]);
    expect($this->menus->quote($this->pair, 1)['unit_price'])->toBe(1900);
    $this->products['side']->update(['available' => false]);
    expect(fn () => $this->menus->quote($this->pair, 1))->toThrow(ValidationException::class);
});

test('menu validation rejects wrong categories extras missing choices and client prices', function () {
    $key = $this->menus->key($this->selection, $this->rule->id);
    expect($this->menus->quote($key, 1)['unit_price'])->toBe(2000);
    foreach ([array_diff_key($this->selection, ['side' => 1]), [...$this->selection, 'drink' => $this->products['drink']->id], [...$this->selection, 'dessert' => $this->products['starter']->id]] as $selection) {
        expect(fn () => $this->menus->quote($this->menus->key($selection, $this->rule->id), 1))->toThrow(ValidationException::class);
    }
    $this->post(route('menus.add'), ['selections' => $this->selection, 'rule_id' => $this->rule->id, 'price' => 1])->assertSessionHasNoErrors()->assertRedirect(route('cart'));
    expect(app(CatalogService::class)->quote(session('cart'))['total'])->toBe(2000);
});

test('menus without main courses are priced by their exact category combination', function () {
    $rule = MenuRule::create(['category_key' => 'starter,dessert', 'price' => 1200]);
    $key = $this->menus->key(['starter' => $this->products['starter']->id, 'dessert' => $this->products['dessert']->id], $rule->id);
    expect($this->menus->quote($key, 1)['unit_price'])->toBe(1200);
});

test('conversion preserves remaining quantities and refuses duplicate and stale requests', function () {
    $cart = [$this->pair => 1, 'product:'.$this->products['dessert']->id => 2];
    $proposal = $this->menus->suggestion($cart);
    expect($proposal['saving'])->toBe(500);
    $data = ['signature' => $proposal['signature'], 'revision' => DynamicMenuController::revision($cart), 'action' => 'accept'];
    $this->withSession(['cart' => $cart])->post(route('menus.proposal'), $data)->assertSessionHasNoErrors();
    $converted = session('cart');
    expect($converted['product:'.$this->products['dessert']->id])->toBe(1)->and(count($converted))->toBe(2)->and(app(CatalogService::class)->quote($converted)['total'])->toBe(2600);
    $this->post(route('menus.proposal'), $data)->assertSessionHasErrors('cart');
    expect(session('cart'))->toBe($converted);
});

test('declining is non destructive and unrelated additions do not repeat the proposal', function () {
    $cart = [$this->pair => 1, 'product:'.$this->products['dessert']->id => 1];
    $proposal = $this->menus->suggestion($cart);
    $this->withSession(['cart' => $cart])->post(route('menus.proposal'), ['signature' => $proposal['signature'], 'revision' => DynamicMenuController::revision($cart), 'action' => 'decline'])->assertSessionHasNoErrors();
    expect(session('cart'))->toBe($cart);
    $cart['product:'.$this->products['drink']->id] = 1;
    expect($this->menus->suggestion($cart, session('menu_refused')))->toBeNull();
    $this->rule->update(['price' => 1800, 'version' => 2]);
    expect($this->menus->suggestion($cart, session('menu_refused'))['saving'])->toBe(700);
});

test('equal prices unavailable products and existing menus never trigger savings', function () {
    $cart = [$this->pair => 1, 'product:'.$this->products['dessert']->id => 1];
    $this->rule->update(['price' => 2500]);
    expect($this->menus->suggestion($cart))->toBeNull();
    $this->rule->update(['price' => 2000]);
    $this->products['side']->update(['available' => false]);
    expect($this->menus->suggestion($cart))->toBeNull();
    $this->products['side']->update(['available' => true]);
    expect($this->menus->suggestion([$this->menus->key($this->selection, $this->rule->id) => 1]))->toBeNull();
});

test('only administrators can set prices and category types and edit compatibility', function () {
    $data = ['name' => 'Le gourmand', 'categories' => ['main', 'dessert'], 'price' => '18,90', 'available' => 1, 'visible' => 1, 'position' => 0];
    $this->actingAs(User::factory()->create())->post(route('admin.menu-rules.save', $this->rule), $data)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('admin.menu-rules.save', $this->rule), $data)->assertSessionHasNoErrors();
    expect($this->rule->fresh()->price)->toBe(1890)->and($this->rule->fresh()->version)->toBe(2);
    $this->get(route('admin.menu-rules.edit', $this->rule))->assertOk()->assertSee('18.90')->assertSee('Le gourmand');
    $this->get(route('admin.card'))->assertOk()->assertSee('Type de produits');
    $this->get(route('admin.catalog.edit', ['product', $this->products['main']->id]))->assertOk()->assertSee('Limiter aux accompagnements');
});

test('builder and cart render compositions and a la carte accepts a main alone', function () {
    $this->get(route('menus.builder'))->assertRedirect(route('menus.index'));
    $this->get(route('menus.show', $this->rule))->assertOk()->assertSee('Choisissez votre plat');
    $this->post(route('cart.update'), ['type' => 'product', 'id' => $this->products['main']->id, 'quantity' => 1])->assertSessionHasNoErrors();
    expect(session('cart', []))->toBe(['product:'.$this->products['main']->id => 1]);
    $this->post(route('cart.update'), ['type' => 'product', 'id' => $this->products['main']->id, 'side_id' => $this->products['side']->id, 'quantity' => 1])->assertSessionHasNoErrors();
    $this->get(route('cart'))->assertOk()->assertSee('19,00')->assertSee('side');
});

test('checkout snapshots compositions and detects changed totals under the reservation lock', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    try {
        $cart = [$this->menus->key($this->selection, $this->rule->id) => 1];
        $user = User::factory()->create();
        $slot = app(SlotService::class)->forDate('2026-09-18')[0]['id'];
        $orders = app(OrderService::class);
        $this->rule->update(['price' => 2100]);
        expect(fn () => $orders->place($user, $slot, '0612345678', (string) Str::uuid(), $cart, 2000))->toThrow(ValidationException::class);
        $order = $orders->place($user, $slot, '0612345678', (string) Str::uuid(), $cart, 2100);
        $this->rule->update(['price' => 3000]);
        $this->products['main']->update(['name' => 'Changed']);
        expect($order->total)->toBe(2100)->and($order->items->first()->composition[0]['name'])->toBe('main')->and($order->items->first()->pricing_snapshot['rule_id'])->toBe($this->rule->id);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
