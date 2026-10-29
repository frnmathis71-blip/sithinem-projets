<?php

namespace App\Http\Controllers;

use App\Services\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeCheckoutService $stripe): Response
    {
        abort_unless($stripe->enabled(), 404);
        try {
            $event = $stripe->verifiedEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (RuntimeException) {
            return response('Signature invalide.', 400);
        }
        $stripe->handle($event);

        return response('OK');
    }
}
