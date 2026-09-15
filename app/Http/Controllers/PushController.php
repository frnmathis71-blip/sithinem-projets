<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|url:https|max:2048', 'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{87}$/'], 'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{22}$/']]);
        $host = parse_url($data['endpoint'], PHP_URL_HOST);
        // Only known push services: never let subscriptions become arbitrary HTTP targets.
        if (! is_string($host) || ! (in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true) || str_ends_with($host, '.notify.windows.com'))
            || parse_url($data['endpoint'], PHP_URL_USER) || parse_url($data['endpoint'], PHP_URL_PORT)) {
            throw ValidationException::withMessages(['endpoint' => 'Service de notifications non pris en charge.']);
        }
        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => hash('sha256', $data['endpoint'])], ['user_id' => $request->user()?->id, 'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2048']);
        DB::table('push_subscriptions')->where('user_id', $request->user()?->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['subscribed' => false]);
    }
}
