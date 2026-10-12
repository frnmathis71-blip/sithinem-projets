<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates the Windows application with a revocable bearer token, for administrators only. */
class AuthenticateDesktop
{
    public const IDLE_DAYS = 30;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?? '';
        $token = strlen($plain) === 64 ? DB::table('desktop_tokens')->where('token_hash', hash('sha256', $plain))->first() : null;
        if ($token && $token->last_used_at && now()->subDays(self::IDLE_DAYS)->gt($token->last_used_at)) {
            DB::table('desktop_tokens')->where('id', $token->id)->delete();
            $token = null;
        }
        $user = $token ? User::query()->whereKey($token->user_id)->first() : null;
        abort_unless($user !== null, 401, 'Session expirée. Reconnectez-vous.');
        abort_unless($user->role === 'admin', 403, 'Ce compte n’a pas accès à l’administration.');
        if (! $token->last_used_at || now()->subMinute()->gt($token->last_used_at)) {
            DB::table('desktop_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        }
        Auth::setUser($user);
        $request->attributes->set('desktop_token_id', $token->id);

        return $next($request);
    }
}
