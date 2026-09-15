<?php

use App\Http\Controllers\DynamicMenuController;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->menus = app(DynamicMenuService::class);
    $this->choices = [];
    foreach (['starter' => 700, 'main' => 1600, 'chef_main' => 1800, 'side' => 300, 'dessert' => 600, 'drink' => 400] as $code => $price) {
        $category = Category::create(['name' => $code, 'code' => $code]);
        $this->choices[$code] = Product::create(['name' => 'Produit '.$code, 'price' => $price, 'category_id' => $category->id]);
    }
    $this->classic = MenuRule::create(['name' => 'Formule classique', 'category_key' => 'main,dessert', 'price' => 2000]);
    $this->chef = MenuRule::create(['name' => 'Le chef gourmand', 'category_key' => 'chef_main,dessert', 'price' => 2000]);
    $this->admin = User::factory()->create(['role' => 'admin']);
});

test('chef is a complete product at a la carte price without any side step', function () {
    $chef = $this->choices['chef_main'];
    $chef->update(['restricted_sides' => true, 'compatible_side_ids' => []]);
    $this->choices['side']->update(['available' => false]);
    expect($chef->requiresSide())->toBeFalse()->and($chef->includesSide())->toBeTrue();
    expect(app(CatalogService::class)->quote(['product:'.$chef->id => 1])['total'])->toBe(1800);
    $this->post(route('cart.update'), ['type' => 'product', 'id' => $chef->id, 'quantity' => 1])->assertSessionHasNoErrors();
    expect(session('cart'))->toBe(['product:'.$chef->id => 1]);
    $this->get(route('menus.builder', ['product' => $chef->id]))->assertNotFound();
    $this->get(route('menus.show', $this->chef))->assertOk()->assertSee('data-formula-step="chef_main"', false)->assertDontSee('data-formula-step="side"', false)->assertDontSee('data-formula-step="main"', false)->assertDontSee('data-formula-step="starter"', false)->assertSee('Garniture incluse');
});

test('chef formula has no side and cannot accept a classic main or additional side', function () {
    $selection = ['chef_main' => $this->choices['chef_main']->id, 'dessert' => $this->choices['dessert']->id];
    $key = $this->menus->key($selection, $this->chef->id);
    $line = $this->menus->quote($key, 1);
    expect($line['name'])->toBe('Le chef gourmand')->and($line['unit_price'])->toBe(2000)->and($line['composition'])->toHaveCount(2)->and($line['composition'][0]['includes_side'])->toBeTrue();
    foreach ([['chef_main' => $this->choices['main']->id, 'dessert' => $this->choices['dessert']->id], [...$selection, 'side' => $this->choices['side']->id], [...$selection, 'main' => $this->choices['main']->id, 'side' => $this->choices['side']->id]] as $invalid) {
        expect(fn () => $this->menus->quote($this->menus->key($invalid, $this->chef->id), 1))->toThrow(ValidationException::class);
    }
    $this->post(route('menus.add'), ['rule_id' => $this->chef->id, 'selections' => $selection])->assertSessionHasNoErrors();
    expect(app(CatalogService::class)->quote(session('cart'))['total'])->toBe(2000);
});

test('chef and classic formulas are not interchangeable when suggesting conversion', function () {
    $cart = ['product:'.$this->choices['chef_main']->id => 1, 'product:'.$this->choices['dessert']->id => 2];
    $proposal = $this->menus->suggestion($cart);
    expect($proposal['line']['item_id'])->toBe($this->chef->id)->and($proposal['saving'])->toBe(400);
    $this->withSession(['cart' => $cart])->post(route('menus.proposal'), ['signature' => $proposal['signature'], 'revision' => DynamicMenuController::revision($cart), 'action' => 'accept'])->assertSessionHasNoErrors();
    expect(session('cart')['product:'.$this->choices['dessert']->id])->toBe(1);
    $this->chef->update(['available' => false]);
    expect($this->menus->suggestion($cart))->toBeNull();
    $pair = $this->menus->key(['main' => $this->choices['main']->id, 'side' => $this->choices['side']->id]);
    expect($this->menus->suggestion([$pair => 1, 'product:'.$this->choices['dessert']->id => 1])['line']['item_id'])->toBe($this->classic->id);
});

test('public menu listing respects activation visibility and order', function () {
    $this->classic->update(['position' => 4]);
    $this->chef->update(['position' => 1]);
    $hidden = MenuRule::create(['name' => 'Formule masquée', 'category_key' => 'starter,drink', 'visible' => false]);
    MenuRule::create(['name' => 'Formule inactive', 'category_key' => 'starter,dessert', 'available' => false]);
    $this->get(route('menus.index'))->assertOk()->assertSeeInOrder(['Le chef gourmand', 'Formule classique'])->assertDontSee('Formule masquée')->assertDontSee('Formule inactive')->assertDontSee('data-category-picker', false)->assertDontSee('Créer mon menu');
    $this->get('/menu?type=menu')->assertOk()->assertSee('Nos menus');
    $this->get(route('menus.show', $hidden))->assertOk();
    $this->chef->update(['available' => false]);
    $this->get(route('menus.show', $this->chef))->assertNotFound();
    $this->post(route('menus.add'), ['rule_id' => $this->chef->id, 'selections' => ['chef_main' => $this->choices['chef_main']->id, 'dessert' => $this->choices['dessert']->id]])->assertSessionHasErrors('cart');
});

test('administrator creates names edits prices and freely combines categories', function () {
    $data = ['name' => 'Douceur et fraîcheur', 'description' => 'À votre goût', 'categories' => ['drink', 'starter', 'dessert'], 'price' => '20', 'available' => 1, 'visible' => 1, 'position' => 3];
    $this->actingAs($this->admin)->get(route('admin.menu-rules.create'))->assertOk()->assertSee('20.00');
    $this->post(route('admin.menu-rules.save'), $data)->assertSessionHasNoErrors();
    $rule = MenuRule::where('name', $data['name'])->firstOrFail();
    expect($rule->category_key)->toBe('starter,dessert,drink')->and($rule->steps())->toBe(['starter', 'dessert', 'drink']);
    $this->post(route('admin.menu-rules.save', $rule), [...$data, 'name' => 'Nouvelle formule', 'categories' => ['chef_main', 'dessert'], 'price' => '18,50', 'visible' => 0])->assertSessionHasNoErrors();
    expect($rule->fresh()->price)->toBe(1850)->and($rule->fresh()->visible)->toBeFalse()->and($rule->fresh()->version)->toBe(2)->and($rule->fresh()->steps())->toBe(['chef_main', 'dessert']);
    $this->post(route('admin.menu-rules.save'), [...$data, 'name' => 'Même structure, autre formule'])->assertSessionHasNoErrors();
    $this->post(route('admin.menu-rules.save'), [...$data, 'name' => 'Encore une formule'])->assertSessionHasNoErrors();
    expect(MenuRule::where('category_key', 'starter,dessert,drink')->count())->toBe(2);
});

test('invalid formula categories are rejected without changing the existing formula', function () {
    $data = ['name' => 'Ne pas enregistrer', 'price' => '20', 'available' => 1, 'visible' => 1, 'position' => 0];
    $this->actingAs($this->admin);
    foreach ([[], ['main', 'chef_main'], ['side', 'dessert'], ['dessert', 'dessert'], ['unknown']] as $categories) {
        $this->post(route('admin.menu-rules.save', $this->classic), [...$data, 'categories' => $categories])->assertSessionHasErrors();
        expect($this->classic->fresh()->name)->toBe('Formule classique');
    }
    $this->post(route('admin.menu-rules.save'), [...$data, 'categories' => ['main']])->assertSessionHasNoErrors();
    expect(MenuRule::where('name', 'Ne pas enregistrer')->firstOrFail()->steps())->toBe(['main', 'side']);
});

test('duplicate structures offer the cheapest active formula and tariff changes invalidate old proposals', function () {
    $cheap = MenuRule::create(['name' => 'Avantage chef', 'category_key' => 'chef_main,dessert', 'price' => 1900, 'visible' => false]);
    $cart = ['product:'.$this->choices['chef_main']->id => 1, 'product:'.$this->choices['dessert']->id => 1];
    $proposal = $this->menus->suggestion($cart);
    expect($proposal['line']['name'])->toBe('Avantage chef');
    $cheap->update(['price' => 2100, 'version' => 2]);
    $this->withSession(['cart' => $cart])->post(route('menus.proposal'), ['signature' => $proposal['signature'], 'revision' => DynamicMenuController::revision($cart), 'action' => 'accept'])->assertSessionHasErrors('cart');
    expect(session('cart'))->toBe($cart)->and($this->menus->suggestion($cart)['line']['item_id'])->toBe($this->chef->id);
});

test('chef variants inherit included garnish and old chef side pairs require recomposition', function () {
    $parent = $this->choices['chef_main'];
    $parent->update(['has_variants' => true]);
    $variant = Product::create(['parent_id' => $parent->id, 'name' => $parent->name, 'variant_label' => 'Grand', 'price' => 2100]);
    expect($variant->includesSide())->toBeTrue()->and($variant->requiresSide())->toBeFalse();
    expect(app(CatalogService::class)->quote(['product:'.$variant->id => 1])['total'])->toBe(2100);
    $oldPair = $this->menus->key(['main' => $variant->id, 'side' => $this->choices['side']->id]);
    expect(fn () => $this->menus->quote($oldPair, 1))->toThrow(ValidationException::class);
    $parent->update(['available' => false]);
    expect(fn () => app(CatalogService::class)->quote(['product:'.$variant->id => 1]))->toThrow(ValidationException::class);
});

test('visual formula cards inherit parent photos and use a labelled illustration when missing', function () {
    $parent = $this->choices['chef_main'];
    $parent->update(['has_variants' => true, 'image' => 'catalog/chef.webp']);
    $variant = Product::create(['parent_id' => $parent->id, 'name' => $parent->name, 'variant_label' => 'Grand', 'price' => 2100]);
    $options = collect($this->menus->options())->keyBy('id');
    expect($options[$variant->id]['image_url'])->toEndWith('/storage/catalog/chef.webp')->and($options[$variant->id]['has_photo'])->toBeTrue();
    expect($options[$this->choices['dessert']->id]['has_photo'])->toBeFalse();
    $this->get(route('menus.show', $this->chef))->assertOk()->assertDontSee('<select', false)->assertSee('type="radio"', false)->assertSee('/storage/catalog/chef.webp')->assertSee('Photo à venir')->assertSee('data-story-previous', false)->assertSee('data-story-next', false);
    expect($this->get(route('menus.show', $this->chef))->getContent())->toMatch('/data-formula-step="dessert"\s+hidden/');
});
