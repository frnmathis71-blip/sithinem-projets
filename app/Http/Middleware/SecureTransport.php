<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        $enforced = app()->isProduction() || config('site.force_https');
        if ($enforced && ! $request->isSecure()) {
            // Use the configured canonical origin, not the incoming Host header.
            $origin = preg_replace('#^http://#', 'https://', rtrim(config('app.url'), '/'));

            return redirect()->away($origin.$request->getRequestUri(), 308);
        }
        $response = $next($request);
        if ($enforced && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        if ($request->is('admin*', 'mon-compte*', 'mes-commandes*', 'panier', 'commande', 'verifier-telephone*', 'settings*', 'api/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
