<?php

use App\Http\Controllers\DynamicMenuController;
use App\Models\Category;
use App\Models\MenuRule;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\DynamicMenuService;
use App\Services\FeaturedProductService;
use App\Services\OrderService;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('all six product types can be selected even after categories were removed', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $page = $this->get(route('admin.catalog.create', 'product'))->assertOk();
    foreach (Category::LABELS as $code => $label) {
        $page->assertSee('value="type:'.$code.'"', false)->assertSee($label);
        $this->post(route('admin.catalog.save', 'product'), ['name' => $label, 'price' => '8', 'available' => 1, 'category_id' => 'type:'.$code])->assertSessionHasNoErrors();
        expect(Product::where('name', $label)->firstOrFail()->categoryCode())->toBe($code);
    }
    expect(Category::count())->toBe(6);
});

test('product and variant photos accept large files and are resized without changing their proportions', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->post(route('admin.catalog.save', 'product'), [
        'name' => 'Curry visuel', 'available' => 1, 'category_id' => 'type:main', 'has_variants' => 1,
        'image' => UploadedFile::fake()->image('portrait.jpg', 1800, 2400)->size(12000),
        'variants' => [7 => ['label' => 'Poulet', 'price' => '12', 'available' => 1, 'image' => UploadedFile::fake()->image('paysage.png', 2400, 1200)->size(9000)]],
    ])->assertSessionHasNoErrors();
    $parent = Product::whereNull('parent_id')->firstOrFail();
    $variant = $parent->variants()->firstOrFail();
    $parentSize = getimagesize(Storage::disk('public')->path($parent->image));
    $variantSize = getimagesize(Storage::disk('public')->path($variant->image));
    expect([$parentSize[0], $parentSize[1], $parentSize['mime']])->toBe([1200, 1600, 'image/webp']);
    expect([$variantSize[0], $variantSize[1]])->toBe([1600, 800]);
    $photo = $variant->image;
    $this->post(route('admin.catalog.save', ['product', $parent->id]), ['name' => $parent->name, 'available' => 1, 'has_variants' => 1, 'variants' => [['id' => $variant->id, 'label' => 'Poulet', 'price' => '13', 'available' => 1]]])->assertSessionHasNoErrors();
    expect($variant->fresh()->image)->toBe($photo);
});

test('variant options keep one generic group and exclude unavailable choices', function () {
    $category = Category::create(['name' => 'Chefs', 'code' => 'chef_main']);
    $parent = Product::create(['name' => 'Curry', 'has_variants' => true, 'price' => 1000, 'category_id' => $category->id]);
    $a = Product::create(['name' => 'Curry', 'parent_id' => $parent->id, 'variant_label' => 'Poulet', 'price' => 1000]);
    $b = Product::create(['name' => 'Curry', 'parent_id' => $parent->id, 'variant_label' => 'Crevettes', 'price' => 1300]);
    Product::create(['name' => 'Curry', 'parent_id' => $parent->id, 'variant_label' => 'Épuisé', 'price' => 1300, 'available' => false]);
    $options = app(DynamicMenuService::class)->options();
    expect(array_column($options, 'id'))->toBe([$a->id, $b->id]);
    expect(array_column($options, 'group_id'))->toBe([$parent->id, $parent->id]);
    expect($options[1]['prix_supplement'])->toBe(300);
    $rule = MenuRule::create(['category_key' => 'chef_main', 'price' => 2000]);
    $html = $this->get(route('menus.show', $rule))->assertOk()->assertDontSee('<select', false)->getContent();
    expect(substr_count($html, 'data-choose-group="'.$parent->id.'"'))->toBe(1);
    expect(app(DynamicMenuService::class)->quote(app(DynamicMenuService::class)->key(['chef_main' => $b->id], $rule->id), 1)['unit_price'])->toBe(2000);
});

test('separately added main and compatible side convert exactly one unit of each', function () {
    $products = [];
    foreach (['main' => 1500, 'side' => 400, 'dessert' => 600] as $code => $price) {
        $category = Category::create(['name' => $code, 'code' => $code]);
        $products[$code] = Product::create(['name' => $code, 'price' => $price, 'category_id' => $category->id]);
    }
    MenuRule::create(['category_key' => 'main,dessert', 'price' => 2000]);
    $cart = ['product:'.$products['main']->id => 2, 'product:'.$products['side']->id => 1, 'product:'.$products['dessert']->id => 1];
    $service = app(DynamicMenuService::class);
    $proposal = $service->suggestion($cart);
    expect($proposal['keys'])->toHaveCount(3)->and($proposal['saving'])->toBe(500);
    $this->withSession(['cart' => $cart])->post(route('menus.proposal'), ['signature' => $proposal['signature'], 'revision' => DynamicMenuController::revision($cart), 'action' => 'accept'])->assertSessionHasNoErrors();
    expect(session('cart')['product:'.$products['main']->id])->toBe(1);
    expect(app(CatalogService::class)->quote(session('cart'))['total'])->toBe(3500);
    $products['main']->update(['restricted_sides' => true, 'compatible_side_ids' => []]);
    expect($service->suggestion($cart))->toBeNull();
});

test('home ranks ordered units across variants and menus with chef fallback and excludes cancelled orders desserts and drinks', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));
    try {
        $products = [];
        foreach (['starter', 'main', 'side', 'chef_main', 'dessert', 'drink'] as $code) {
            $category = Category::create(['name' => $code, 'code' => $code]);
            $products[$code] = Product::create(['name' => $code, 'price' => 500, 'category_id' => $category->id]);
        }
        $service = app(FeaturedProductService::class);
        $all = fn () => Product::whereNull('parent_id')->get();
        expect($service->select($all())->pluck('id')->all())->toBe([$products['chef_main']->id]);
        $user = User::factory()->create();
        $slot = app(SlotService::class)->forDate('2026-09-18')[0]['id'];
        $order = fn ($cart) => app(OrderService::class)->place($user, $slot, '0612345678', (string) Str::uuid(), $cart);
        $order(['product:'.$products['starter']->id => 2]);
        expect($service->select($all())->first()->id)->toBe($products['chef_main']->id);
        $order(['product:'.$products['starter']->id => 1, 'product:'.$products['dessert']->id => 10, 'product:'.$products['drink']->id => 10]);
        $cancelled = $order(['product:'.$products['side']->id => 8]);
        $cancelled->update(['status' => 'cancelled']);
        $products['main']->update(['has_variants' => true]);
        $variant = Product::create(['name' => 'main', 'parent_id' => $products['main']->id, 'variant_label' => 'Grand', 'price' => 700]);
        $rule = MenuRule::create(['category_key' => 'main', 'price' => 1000]);
        $order([app(DynamicMenuService::class)->key(['main' => $variant->id, 'side' => $products['side']->id], $rule->id) => 4]);
        expect($service->select($all())->pluck('id')->all())->toBe([$products['main']->id, $products['side']->id, $products['starter']->id, $products['chef_main']->id]);
        $this->get('/')->assertOk()->assertSee('Les favoris de notre table');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
