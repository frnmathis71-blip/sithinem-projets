<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PreventSpam
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('register', 'forgot-password', 'verifier-telephone*', 'commandes', 'panier', 'composer-mon-menu')) {
            abort_if($request->filled('website_url'), 422, 'Le formulaire n’a pas pu être envoyé.');
            $sensitive = $request->is('register', 'forgot-password');
            $key = 'anti-spam:'.hash('sha256', ($request->ip() ?? '').'|'.$request->path());
            $maximum = $sensitive ? 5 : 60;
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                return response('Trop de demandes. Réessayez dans quelques instants.', 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, $sensitive ? 600 : 60);
        }

        return $next($request);
    }
}
