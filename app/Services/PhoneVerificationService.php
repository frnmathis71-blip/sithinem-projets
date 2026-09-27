<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneVerificationService
{
    public function simulated(): bool
    {
        return app()->environment('local') && config('services.twilio.simulate') === true;
    }

    public function previewCode(?string $phone): ?string
    {
        if (! $this->simulated() || ! $phone) {
            return null;
        }
        $code = Cache::get('local-phone-preview-'.hash('sha256', $phone));

        return is_string($code) ? $code : null;
    }

    private function assertSimulationEnvironment(): void
    {
        if (config('services.twilio.simulate') && ! app()->environment('local')) {
            throw ValidationException::withMessages(['phone' => 'La configuration du service SMS est invalide. Contactez le restaurant.']);
        }
    }

    public function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s.()\-]/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if (preg_match('/^0[1-9][0-9]{8}$/D', $phone)) {
            $phone = '+33'.substr($phone, 1);
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Saisissez un numÃ©ro franÃ§ais Ã  10 chiffres ou un numÃ©ro avec son indicatif international.']);
        }

        return $phone;
    }

    public function limit(string $action, int $userId, string $phone, string $ip): void
    {
        $limits = $action === 'send' ? [['user', (string) $userId, 1, 60], ['daily-user', (string) $userId, 10, 86400], ['phone', $phone, 5, 3600], ['ip', $ip, 20, 3600]] : [['user', (string) $userId, 5, 600], ['phone', $phone, 10, 600], ['ip', $ip, 50, 600]];
        foreach ($limits as [$scope, $value, $max, $seconds]) {
            $key = 'phone-'.$action.'-'.$scope.'-'.hash('sha256', $value);
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['phone' => 'Trop de tentatives. RÃ©essayez dans '.RateLimiter::availableIn($key).' secondes.']);
            }
            RateLimiter::hit($key, $seconds);
        }
    }

    public function send(string $phone): void
    {
        $this->assertSimulationEnvironment();
        if ($this->simulated()) {
            $code = (string) random_int(100000, 999999);
            Cache::put('local-phone-code-'.hash('sha256', $phone), hash('sha256', $code), now()->addMinutes(10));
            Cache::put('local-phone-preview-'.hash('sha256', $phone), $code, now()->addMinutes(10));
            Log::channel('single')->info('Simulation SMS locale', ['phone' => $phone, 'code' => $code]);

            return;
        }
        if ($this->request('Verifications', ['To' => $phone, 'Channel' => 'sms', 'Locale' => 'fr']) !== 'pending') {
            throw ValidationException::withMessages(['phone' => 'Le SMS nâ€™a pas pu Ãªtre envoyÃ©. RÃ©essayez plus tard.']);
        }
    }

    public function check(string $phone, string $code): bool
    {
        $this->assertSimulationEnvironment();
        if ($this->simulated()) {
            $key = 'local-phone-code-'.hash('sha256', $phone);
            $expected = Cache::get($key);
            if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $code))) {
                return false;
            }
            Cache::forget($key);
            Cache::forget('local-phone-preview-'.hash('sha256', $phone));

            return true;
        }

        return $this->request('VerificationCheck', ['To' => $phone, 'Code' => $code]) === 'approved';
    }

    /** @param array<string, string> $data */
    private function request(string $endpoint, array $data): string
    {
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $service = config('services.twilio.verify_service_sid');
        if (! $sid || ! $token || ! $service) {
            throw ValidationException::withMessages(['phone' => 'La vÃ©rification SMS est momentanÃ©ment indisponible. Contactez le restaurant.']);
        }
        try {
            $response = Http::asForm()->withBasicAuth($sid, $token)->connectTimeout(5)->timeout(15)
                ->post('https://verify.twilio.com/v2/Services/'.$service.'/'.$endpoint, $data);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['phone' => 'Le service SMS ne rÃ©pond pas. RÃ©essayez plus tard.']);
        }
        if ($endpoint === 'VerificationCheck' && in_array($response->status(), [400, 404, 429], true)) {
            return 'invalid';
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['phone' => 'Le service SMS est indisponible ou le numÃ©ro ne peut pas recevoir de SMS. RÃ©essayez plus tard.']);
        }

        return (string) $response->json('status', 'invalid');
    }
}
