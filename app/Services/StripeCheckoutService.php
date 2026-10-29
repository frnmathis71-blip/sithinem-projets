<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout through its REST API: a hosted payment page per online order, confirmed by webhook.
 * Card details never reach this site.
 */
class StripeCheckoutService
{
    /** Minutes the customer has to pay; the slot stays reserved meanwhile. Stripe accepts 30 minutes at least. */
    public const PAYMENT_WINDOW = 30;

    private const API = 'https://api.stripe.com/v1';

    public function enabled(): bool
    {
        return (bool) config('services.stripe.enabled') && filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret'));
    }

    /** Payment page address for the order: the open session is reused, otherwise a new one is created. */
    public function paymentUrl(Order $order): string
    {
        if ($order->stripe_session_id) {
            $session = $this->session($order->stripe_session_id);
            if (($session['status'] ?? null) === 'open' && isset($session['url'])) {
                return $session['url'];
            }
        }
        $page = route('orders.show', $order);
        $session = $this->client()->withHeaders(['Idempotency-Key' => 'checkout-'.$order->id.'-'.md5((string) $order->stripe_session_id)])
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => (string) $order->id,
                'customer_email' => $order->user->email,
                'success_url' => $page.'?paiement=confirme&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $page.'?paiement=annule',
                'expires_at' => now()->addMinutes(self::PAYMENT_WINDOW)->addMinute()->timestamp,
                'locale' => 'fr',
                'metadata' => ['order_id' => $order->id, 'order_number' => $order->number],
                'payment_intent_data' => ['metadata' => ['order_id' => $order->id, 'order_number' => $order->number]],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => ['currency' => 'eur', 'unit_amount' => $order->total, 'product_data' => ['name' => 'Commande '.$order->number.' · Sithinem']],
                ]],
            ])->throw()->json();
        $order->update(['stripe_session_id' => $session['id']]);

        return $session['url'];
    }

    /** Confirms the payment straight from Stripe when the customer comes back, without waiting for the webhook. */
    public function confirmReturn(Order $order, string $sessionId): void
    {
        if ($sessionId === $order->stripe_session_id) {
            $this->apply($this->session($sessionId));
        }
    }

    /**
     * Checks the Stripe-Signature header against the webhook secret and returns the event.
     *
     * @return array<string, mixed>
     */
    public function verifiedEvent(string $payload, string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$name][] = $value;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'));
        $valid = collect($parts['v1'] ?? [])->contains(fn (string $signature) => hash_equals($expected, $signature));
        if (! $valid || abs(time() - $timestamp) > 300) {
            throw new RuntimeException('Invalid Stripe signature.');
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['type'], $event['data']['object'])) {
            throw new RuntimeException('Invalid Stripe event.');
        }

        return $event;
    }

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $session = $event['data']['object'];
        match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->apply($session),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->abandon($session),
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    private function apply(array $session): void
    {
        $order = $this->order($session);
        if ($order && ($session['payment_status'] ?? null) === 'paid' && ($session['currency'] ?? null) === 'eur') {
            app(OrderService::class)->confirmOnlinePayment($order, (int) ($session['amount_total'] ?? 0));
        }
    }

    /** @param array<string, mixed> $session */
    private function abandon(array $session): void
    {
        $order = $this->order($session);
        // An expired older session must not cancel an order that a newer session may still pay.
        if ($order && $order->stripe_session_id === ($session['id'] ?? null)) {
            app(OrderService::class)->cancelUnpaid($order);
        }
    }

    /** @param array<string, mixed> $session */
    private function order(array $session): ?Order
    {
        $id = $session['metadata']['order_id'] ?? $session['client_reference_id'] ?? null;

        return $id ? Order::query()->where('payment_method', 'online')->find((int) $id) : null;
    }

    /** @return array<string, mixed> */
    private function session(string $id): array
    {
        return $this->client()->get(self::API.'/checkout/sessions/'.rawurlencode($id))->throw()->json();
    }

    private function client(): PendingRequest
    {
        return Http::asForm()->withToken((string) config('services.stripe.secret'))->timeout(15)->retry(2, 300, throw: false);
    }
}
