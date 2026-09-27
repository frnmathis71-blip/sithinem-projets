<?php

namespace App\Http\Controllers;

use App\Models\MenuRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteController extends Controller
{
    private function origin(): string
    {
        $origin = rtrim((string) config('app.url'), '/');

        return app()->isProduction() || config('site.force_https') ? (string) preg_replace('#^http://#', 'https://', $origin) : $origin;
    }

    public function consent(Request $request): RedirectResponse
    {
        $data = $request->validate(['choice' => 'required|in:accepted,rejected']);

        return back()->withCookie(cookie('site_cookie_choice', 'v1:'.$data['choice'], 60 * 24 * 180, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public function sitemap(): Response
    {
        $base = $this->origin();
        $paths = ['/', '/menu', '/menus', '/offres', '/confidentialite', '/cgu', '/cookies'];
        foreach (MenuRule::query()->where('visible', true)->where('available', true)->pluck('id') as $id) {
            $paths[] = '/menus/'.$id;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) {
            $xml .= '<url><loc>'.htmlspecialchars($base.$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $rules = "User-agent: *\n";
        if (! app()->isProduction() || config('restaurant.demo')) {
            $rules .= "Disallow: /\n";
        } else {
            $rules .= "Allow: /\n";
            foreach (['/admin', '/api/', '/panier', '/commande', '/mes-commandes', '/mon-compte', '/settings', '/login', '/register', '/verifier-telephone', '/forgot-password', '/reset-password', '/creneaux'] as $path) {
                $rules .= 'Disallow: '.$path."\n";
            }
            $rules .= 'Sitemap: '.$this->origin()."/sitemap.xml\n";
        }

        return response($rules)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
