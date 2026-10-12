<?php

namespace App\Http\Controllers\Desktop;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class SessionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1000', 'code' => 'nullable|string|max:100', 'device' => 'nullable|string|max:100']);
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }
        if ($user->role !== 'admin') {
            throw ValidationException::withMessages(['email' => 'Ce compte n’a pas accès à l’administration.']);
        }
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                return response()->json(['two_factor' => true, 'message' => 'Saisissez le code de votre application d’authentification.'], 422);
            }
            if (! $this->validSecondFactor($user, $code)) {
                throw ValidationException::withMessages(['code' => 'Code de vérification incorrect.']);
            }
        }
        $plain = Str::random(64);
        DB::table('desktop_tokens')->insert(['user_id' => $user->id, 'name' => $data['device'] ?? 'Poste Windows', 'token_hash' => hash('sha256', $plain), 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['token' => $plain, 'user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['user' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::table('desktop_tokens')->where('id', $request->attributes->get('desktop_token_id'))->delete();

        return response()->json(['ok' => true]);
    }

    private function validSecondFactor(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) && app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)) {
            return true;
        }
        $recovery = collect($user->recoveryCodes())->first(fn ($candidate) => hash_equals($candidate, $code));
        if ($recovery) {
            $user->replaceRecoveryCode($recovery);

            return true;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return ['name' => trim(($user->first_name ?? '').' '.$user->name), 'first_name' => $user->first_name ?: $user->name, 'email' => $user->email];
    }
}
