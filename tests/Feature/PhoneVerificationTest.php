<?php

use App\Models\User;
use App\Services\OrderService;
use App\Services\PhoneVerificationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;

beforeEach(function () {
    config(['services.twilio.enabled' => true]);
    config(['services.twilio.simulate' => false]);
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.twilio.account_sid' => 'ACtest', 'services.twilio.auth_token' => 'secret', 'services.twilio.verify_service_sid' => 'VAtest']);
    $this->user = User::factory()->create();
});

test('phone verification requires authentication', function () {
    $this->get(route('phone.show'))->assertRedirect(route('login'));
    $this->post(route('phone.send'), ['phone' => '0612345678'])->assertRedirect(route('login'));
    Http::assertNothingSent();
});

test('a pending phone is normalized and approved only by the provider', function () {
    Http::fake(['*/Verifications' => Http::response(['status' => 'pending']), '*/VerificationCheck' => Http::response(['status' => 'approved'])]);
    $this->actingAs($this->user)->get(route('phone.show'))->assertOk();
    $this->withSession(['cart' => ['product:1' => 2]])->post(route('phone.send'), ['phone' => '06 12 34 56 78'])->assertSessionHasNoErrors();
    expect($this->user->fresh()->pending_phone)->toBe('+33612345678');
    expect($this->user->fresh()->phone_verified_at)->toBeNull();
    $this->post(route('phone.check'), ['code' => '123456', 'phone' => '+33799999999'])->assertRedirect(route('cart'))->assertSessionHas('cart', ['product:1' => 2]);
    $user = $this->user->fresh();
    expect($user->phone)->toBe('+33612345678')->and($user->phone_verified_at)->not->toBeNull()->and($user->pending_phone)->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/VerificationCheck') && $request['To'] === '+33612345678' && $request['Code'] === '123456');
});

test('wrong expired and exhausted codes never verify the phone', function ($status, $body) {
    $this->user->forceFill(['pending_phone' => '+33612345678'])->save();
    Http::fake(['*' => Http::response($body, $status)]);
    $this->actingAs($this->user)->post(route('phone.check'), ['code' => '123456'])->assertSessionHasErrors('code');
    expect($this->user->fresh()->phone_verified_at)->toBeNull();
    expect(session()->getOldInput('code'))->toBeNull();
})->with([[200, ['status' => 'pending']], [404, []], [429, []]]);

test('missing configuration and provider failures cannot mark or replace a phone', function ($mode) {
    if ($mode === 'config') {
        config(['services.twilio.auth_token' => null]);
    } elseif ($mode === 'network') {
        Http::fake(['*' => Http::failedConnection()]);
    } else {
        Http::fake(['*' => Http::response([], 500)]);
    }
    $this->actingAs($this->user)->post(route('phone.send'), ['phone' => '0612345678'])->assertSessionHasErrors('phone');
    expect($this->user->fresh()->pending_phone)->toBeNull()->and($this->user->fresh()->phone_verified_at)->toBeNull();
})->with(['config', 'network', 'provider']);

test('resends are limited by account and number across accounts', function () {
    Http::fake(['*' => Http::response(['status' => 'pending'])]);
    $this->actingAs($this->user)->post(route('phone.send'), ['phone' => '0612345678'])->assertSessionHasNoErrors();
    $this->post(route('phone.send'), ['phone' => '0677777777'])->assertSessionHasErrors('phone');
    Http::assertSentCount(1);
    for ($i = 0; $i < 4; $i++) {
        $this->actingAs(User::factory()->create())->post(route('phone.send'), ['phone' => '+33612345678'])->assertSessionHasNoErrors();
    }
    $this->actingAs(User::factory()->create())->post(route('phone.send'), ['phone' => '06 12 34 56 78'])->assertSessionHasErrors('phone');
    Http::assertSentCount(5);
});

test('code attempts are limited and replay is rejected', function () {
    $this->user->forceFill(['pending_phone' => '+33612345678'])->save();
    Http::fake(['*' => Http::response(['status' => 'pending'])]);
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($this->user)->post(route('phone.check'), ['code' => '123456'])->assertSessionHasErrors();
    }
    Http::assertSentCount(5);
});

test('an approved code cannot be replayed', function () {
    $this->user->forceFill(['pending_phone' => '+33612345678'])->save();
    $this->actingAs($this->user);
    Http::fake(['*' => Http::response(['status' => 'approved'])]);
    $this->post(route('phone.check'), ['code' => '123456'])->assertSessionHasNoErrors();
    $this->post(route('phone.check'), ['code' => '123456'])->assertSessionHasErrors('phone');
    Http::assertSentCount(1);
});

test('changing a profile phone keeps the verified number until approval', function () {
    $this->user->forceFill(['phone' => '+33612345678', 'phone_verified_at' => now()])->save();
    $this->actingAs($this->user)->patch(route('account.update'), ['name' => 'Client', 'phone' => '0677777777'])->assertRedirect(route('phone.show'));
    $user = $this->user->fresh();
    expect($user->phone)->toBe('+33612345678')->and($user->pending_phone)->toBe('+33677777777')->and($user->phone_verified_at)->not->toBeNull();
});

test('order service rejects unverified or substituted phones even with a stale user model', function ($verified, $phone) {
    $this->user->forceFill(['phone' => '+33612345678', 'phone_verified_at' => $verified ? now() : null])->save();
    expect(fn () => app(OrderService::class)->place($this->user, 999, $phone, (string) Str::uuid(), []))->toThrow(ValidationException::class);
})->with([[false, '0612345678'], [true, '0677777777']]);

test('phone normalization accepts local and international formats and rejects garbage', function () {
    $service = app(PhoneVerificationService::class);
    expect($service->normalize('06 12 34 56 78'))->toBe('+33612345678');
    expect($service->normalize('0032 471 12 34 56'))->toBe('+32471123456');
    expect(fn () => $service->normalize('hello0612345678'))->toThrow(ValidationException::class);
});

test('order verification uses persisted state instead of a stale authenticated model', function () {
    $this->user->forceFill(['phone' => '+33612345678', 'phone_verified_at' => now()])->save();
    User::whereKey($this->user->id)->update(['phone_verified_at' => null]);
    expect(fn () => app(OrderService::class)->place($this->user, 999, '0612345678', (string) Str::uuid(), []))->toThrow(ValidationException::class);
});

test('send limits also apply to a shared IP with different accounts and numbers', function () {
    Http::fake(['*' => Http::response(['status' => 'pending'])]);
    for ($i = 0; $i < 20; $i++) {
        $this->actingAs(User::factory()->create())->post(route('phone.send'), ['phone' => '+336123456'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->assertSessionHasNoErrors();
    }
    $this->actingAs(User::factory()->create())->post(route('phone.send'), ['phone' => '+33612345699'])->assertSessionHasErrors('phone');
    Http::assertSentCount(20);
});

test('local simulation completes verification without sending SMS and rejects replay', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->instance('env', 'local');
    config(['services.twilio.simulate' => true]);
    $logger = Mockery::mock(LoggerInterface::class);
    $code = null;
    $logger->shouldReceive('info')->once()->withArgs(function ($message, $context) use (&$code) {
        $code = $context['code'];

        return $message === 'Simulation SMS locale';
    });
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    $this->actingAs($this->user)->post(route('phone.send'), ['phone' => '0612345678'])->assertSessionHasNoErrors();
    $this->user->refresh();
    $this->get(route('phone.show'))->assertOk()->assertSee($code);
    config(['services.twilio.simulate' => false]);
    $this->get(route('phone.show'))->assertOk()->assertDontSee('verification-preview', false);
    config(['services.twilio.simulate' => true]);
    $this->post(route('phone.check'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post(route('phone.check'), ['code' => $code])->assertRedirect(route('cart'))->assertSessionHasNoErrors();
    expect($this->user->fresh()->phone_verified_at)->not->toBeNull();
    expect(app(PhoneVerificationService::class)->check('+33612345678', $code))->toBeFalse();
    Http::assertNothingSent();
});

test('simulation codes expire and are bound to their phone number', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->instance('env', 'local');
    config(['services.twilio.simulate' => true]);
    $codes = [];
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->twice()->withArgs(function ($message, $context) use (&$codes) {
        $codes[] = $context['code'];

        return true;
    });
    Log::shouldReceive('channel')->with('single')->andReturn($logger);
    $service = app(PhoneVerificationService::class);
    $service->send('+33612345678');
    $this->travel(11)->minutes();
    expect($service->check('+33612345678', $codes[0]))->toBeFalse();
    $service->send('+33612345678');
    expect($service->check('+33699999999', $codes[1]))->toBeFalse();
    expect($service->check('+33612345678', $codes[1]))->toBeTrue();
    Http::assertNothingSent();
});

test('simulation is refused outside local even when explicitly enabled', function ($environment) {
    app()->instance('env', $environment);
    config(['services.twilio.simulate' => true]);
    $service = app(PhoneVerificationService::class);
    expect($service->simulated())->toBeFalse();
    Cache::put('local-phone-preview-'.hash('sha256', '+33612345678'), '123456', 600);
    expect($service->previewCode('+33612345678'))->toBeNull();
    expect(fn () => $service->send('+33612345678'))->toThrow(ValidationException::class);
    expect(fn () => $service->check('+33612345678', '123456'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['production', 'staging', 'testing']);
