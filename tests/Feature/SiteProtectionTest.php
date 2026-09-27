<?php

use App\Models\MenuRule;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['site.force_https' => false]);
});

test('legal pages are accessible and show missing operator details honestly', function () {
    config(['site.operator' => null]);
    foreach (['privacy', 'terms', 'cookies'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('privacy'))->assertSee('Document en cours de finalisation');
    $this->get(route('home'))->assertSee('Vos préférences de cookies')->assertSee('Refuser les cookies facultatifs')->assertSee('Accepter les cookies facultatifs');
});

test('cookie refusal is persisted and preference can be changed', function () {
    $this->from(route('cookies'))->post(route('cookies.save'), ['choice' => 'rejected'])
        ->assertRedirect(route('cookies'))->assertCookie('site_cookie_choice', 'v1:rejected');
    $this->withCookie('site_cookie_choice', 'v1:rejected')->get(route('home'))->assertDontSee('Vos préférences de cookies')->assertSee('Gérer les cookies');
    $this->post(route('cookies.save'), ['choice' => 'accepted'])->assertCookie('site_cookie_choice', 'v1:accepted');
    $this->post(route('cookies.save'), ['choice' => 'other'])->assertSessionHasErrors('choice');
});

test('unknown pages return a real custom 404', function () {
    $this->get('/page-inexistante')->assertNotFound()->assertSee('Cette page n’est plus au menu')->assertSee('noindex');
});

test('http is redirected to the canonical HTTPS origin while secure requests receive HSTS', function () {
    config(['site.force_https' => true, 'app.url' => 'https://sithinem.test']);
    $this->get('http://sithinem.test/menu?category=1')->assertStatus(308)->assertRedirect('https://sithinem.test/menu?category=1');
    $this->get('https://sithinem.test/cgu')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('production enforces HTTPS even when the explicit flag is false', function () {
    app()->instance('env', 'production');
    config(['app.url' => 'https://sithinem.test']);
    $this->get('http://sithinem.test/menu')->assertStatus(308);
});

test('robots prevents local indexing and publishes production sitemap', function () {
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
    app()->instance('env', 'production');
    config(['restaurant.demo' => false, 'app.url' => 'https://sithinem.test']);
    $this->get('https://sithinem.test/robots.txt')->assertOk()->assertSee('Disallow: /admin')->assertSee('Sitemap: https://sithinem.test/sitemap.xml');
});

test('sitemap lists public pages and visible available menus only', function () {
    $visible = MenuRule::create(['category_key' => 'main', 'visible' => true, 'available' => true]);
    $hidden = MenuRule::create(['category_key' => 'dessert', 'visible' => false, 'available' => true]);
    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    $response->assertSee('/menus/'.$visible->id.'</loc>', false)->assertDontSee('/menus/'.$hidden->id.'</loc>', false)->assertDontSee('/admin')->assertDontSee('/mon-compte');
});

test('honeypot rejects bot registration before creating an account', function () {
    $this->post('/register', ['website_url' => 'https://spam.example'])->assertStatus(422);
    $this->assertDatabaseCount('users', 0);
});

test('registration flood is rate limited independently of validation', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', [])->assertSessionHasErrors();
    }
    $this->post('/register', [])->assertStatus(429)->assertHeader('Retry-After');
});
