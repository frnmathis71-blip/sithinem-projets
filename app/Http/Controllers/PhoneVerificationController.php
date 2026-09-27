<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PhoneVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function show(): View
    {
        abort_unless(config('services.twilio.enabled'), 404);

        return view('restaurant.verify-phone');
    }

    public function send(Request $request, PhoneVerificationService $verification): RedirectResponse
    {
        abort_unless(config('services.twilio.enabled'), 404);
        $data = $request->validate(['phone' => 'required|string|max:30']);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $phone = $verification->normalize($data['phone']);
        $verification->limit('send', $user->id, $phone, $request->ip() ?? '');
        $verification->send($phone);
        $user->forceFill(['pending_phone' => $phone])->save();

        return redirect()->route('phone.show')->with('success', 'Un code vous a été envoyé par SMS.');
    }

    public function check(Request $request, PhoneVerificationService $verification): RedirectResponse
    {
        abort_unless(config('services.twilio.enabled'), 404);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{4,10}$/D']]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $user->refresh();
        $phone = $user->pending_phone;
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Demandez d’abord un code SMS.']);
        }
        $verification->limit('check', $user->id, $phone, $request->ip() ?? '');
        if (! $verification->check($phone, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré. Réessayez ou demandez un nouveau code.']);
        }
        // Conditional update prevents an in-flight check from approving a replaced number.
        $updated = User::query()->whereKey($user->id)->where('pending_phone', $phone)->update([
            'phone' => $phone, 'phone_verified_at' => now(), 'pending_phone' => null,
        ]);
        if (! $updated) {
            throw ValidationException::withMessages(['phone' => 'Le numéro a changé. Demandez un nouveau code.']);
        }

        return redirect()->route('cart')->with('success', 'Votre téléphone est vérifié. Vous pouvez confirmer votre commande.');
    }
}
