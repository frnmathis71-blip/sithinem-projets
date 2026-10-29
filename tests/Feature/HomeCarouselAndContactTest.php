<?php

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\RestaurantSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the home carousel shows current offers and chef dishes after the welcome illustration', function () {
    $nems = Product::create(['name' => 'Nems', 'price' => 650]);
    $offer = Offer::create(['name' => 'Duo du vendredi', 'price' => 1500, 'available' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    DB::table('offer_items')->insert(['offer_id' => $offer->id, 'product_id' => $nems->id, 'quantity' => 2]);
    $expired = Offer::create(['name' => 'Offre terminée', 'price' => 900, 'available' => true, 'starts_at' => now()->subWeek(), 'ends_at' => now()->subDay()]);
    DB::table('offer_items')->insert(['offer_id' => $expired->id, 'product_id' => $nems->id, 'quantity' => 1]);
    $chef = Category::create(['name' => 'Plat du chef', 'code' => 'chef_main']);
    Product::create(['name' => 'Bo bun du chef', 'price' => 1400, 'category_id' => $chef->id]);
    Product::create(['name' => 'Curry épuisé', 'price' => 1300, 'category_id' => $chef->id, 'available' => false]);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-hero-carousel', false)
        ->assertSeeInOrder(['1 sur 3', 'Offre du moment', 'Duo du vendredi', '15,00 €', 'Plat du chef', 'Bo bun du chef', '14,00 €'])
        ->assertDontSee('Offre terminée')
        ->assertDontSee('Curry épuisé');
});

test('the home carousel stays a still illustration when there is nothing to feature', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('hero-art', false)
        ->assertDontSee('data-hero-carousel', false)
        ->assertDontSee('data-carousel-dot', false);
});

test('the admin sets the phone number shown on the contact page', function () {
    $this->get(route('contact'))->assertOk()->assertSee('Notre numéro sera bientôt disponible')->assertDontSee('href="tel:', false);

    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->get(route('admin.contact'))->assertOk();
    $this->patch(route('admin.contact.update'), ['contact_phone' => ' 01 23 45 67 89 '])->assertSessionHasNoErrors();
    expect(RestaurantSetting::query()->findOrFail(1)->contact_phone)->toBe('01 23 45 67 89');

    $this->get(route('contact'))->assertOk()->assertSee('01 23 45 67 89')->assertSee('href="tel:0123456789"', false);
    $this->patch(route('admin.contact.update'), ['contact_phone' => 'appelez-nous'])->assertSessionHasErrors('contact_phone');
    $this->patch(route('admin.contact.update'), ['contact_phone' => ''])->assertSessionHasNoErrors();
    expect(RestaurantSetting::query()->findOrFail(1)->contact_phone)->toBeNull();
});

test('international numbers keep their leading plus in the call link', function () {
    RestaurantSetting::query()->whereKey(1)->update(['contact_phone' => '+33 1 23 45 67 89']);

    expect(RestaurantSetting::query()->findOrFail(1)->contactPhoneLink())->toBe('tel:+33123456789');
});

test('clients cannot change the restaurant phone number', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('admin.contact'))->assertForbidden();
    $this->patch(route('admin.contact.update'), ['contact_phone' => '0600000000'])->assertForbidden();
});
