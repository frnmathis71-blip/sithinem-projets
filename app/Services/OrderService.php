<?php

namespace App\Services;

use App\Jobs\SendOrderPush;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /** @param array<string, int> $cart */
    public function place(User $user, int $slotId, string $phone, string $key, array $cart, ?int $expectedTotal = null, ?string $customerMessage = null, string $paymentMethod = 'onsite'): Order
    {
        return $this->placeOrder($user, $slotId, $phone, $key, $cart, $expectedTotal, $customerMessage, paymentMethod: $paymentMethod);
    }

    /** @param array<string, int> $cart */
    public function placeForStaff(User $actor, int $slotId, string $phone, string $key, array $cart, string $customerName, string $source, int $expectedTotal, ?string $customerMessage = null): Order
    {
        abort_unless($actor->role === 'admin', 403);
        Validator::make(['customer_name' => $customerName, 'source' => $source], [
            'customer_name' => 'required|string|max:255', 'source' => 'required|in:phone,counter',
        ])->validate();

        return $this->placeOrder($actor, $slotId, $phone, $key, $cart, $expectedTotal, $customerMessage, trim($customerName), $source);
    }

    /** @param array<string, int> $cart */
    private function placeOrder(User $user, int $slotId, string $phone, string $key, array $cart, ?int $expectedTotal, ?string $customerMessage, ?string $customerName = null, string $source = 'online', string $paymentMethod = 'onsite'): Order
    {
        if (! in_array($paymentMethod, ['onsite', 'online'], true) || ($paymentMethod === 'online' && ($source !== 'online' || ! app(StripeCheckoutService::class)->enabled()))) {
            throw ValidationException::withMessages(['payment_method' => 'Ce mode de paiement n’est pas disponible.']);
        }
        Validator::make(['phone' => $phone, 'key' => $key], ['phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 .()\-]{7,24}$/'], 'key' => 'required|uuid'])->validate();

        Validator::make(['customer_message' => $customerMessage], ['customer_message' => 'nullable|string|max:1000'])->validate();
        $customerMessage = trim($customerMessage ?? '');

        return app(RestaurantLock::class)->run(function (RestaurantSetting $settings) use ($user, $slotId, $phone, $key, $cart, $expectedTotal, $customerMessage, $customerName, $source, $paymentMethod) {
            $existing = Order::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $phone = app(PhoneVerificationService::class)->normalize($phone);
            // Paying on site needs a verified phone (when SMS verification is on); paying online does not.
            if ($source === 'online' && $paymentMethod === 'onsite' && config('services.twilio.enabled') && (! $user->phone_verified_at || $user->phone !== $phone)) {
                throw ValidationException::withMessages(['phone' => 'Vérifiez votre numéro de téléphone pour payer sur place, ou choisissez le paiement en ligne.']);
            }
            $slot = TimeSlot::query()->lockForUpdate()->findOrFail($slotId);
            $pickup = CarbonImmutable::parse($slot->date->format('Y-m-d').' '.$slot->starts_at, $settings->timezone);
            $quote = app(CatalogService::class)->quote($cart, $pickup);
            $this->assertAvailable($slot, $settings, $quote['total']);
            if ($expectedTotal !== null && $expectedTotal !== $quote['total']) {
                throw ValidationException::withMessages(['cart' => 'Le tarif a changé. Consultez votre panier avant de confirmer à nouveau.']);
            }
            $order = Order::query()->create(['number' => 'SN-'.strtoupper((string) Str::ulid()), 'user_id' => $user->id, 'time_slot_id' => $slot->id, 'customer_name' => $customerName ?? trim($user->first_name.' '.$user->name), 'source' => $source, 'phone' => $phone, 'total' => $quote['total'], 'status' => $status = $paymentMethod === 'online' ? 'awaiting_payment' : 'preparing', 'payment_method' => $paymentMethod, 'idempotency_key' => $key]);
            $order->update(['customer_message' => $customerMessage === '' ? null : $customerMessage]);
            $order->items()->createMany($quote['lines']);
            if ($source === 'online' && $user->phone !== $phone) {
                $user->forceFill(['phone' => $phone, 'phone_verified_at' => null, 'pending_phone' => null])->save();
            }
            $this->history($order, $user, null, $status);
            if ($status === 'preparing') {
                $this->notifyKitchen($order);
            }

            return $order;
        });
    }

    /** Stripe confirmed the payment: the order reaches the kitchen board, tickets and notifications. */
    public function confirmOnlinePayment(Order $order, int $amount): void
    {
        app(RestaurantLock::class)->run(function () use ($order, $amount) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->payment_method !== 'online' || $order->paid_at) {
                return;
            }
            if ($amount !== $order->total) {
                logger()->error('Stripe amount differs from the order total.', ['order' => $order->number, 'amount' => $amount, 'total' => $order->total]);

                return;
            }
            $order->update(['paid_at' => now()]);
            if ($order->status !== 'awaiting_payment') {
                // Paid after the order was cancelled (late payment): kept visible so the restaurant can refund it.
                logger()->warning('Stripe payment received for an order that no longer awaits payment.', ['order' => $order->number, 'status' => $order->status]);

                return;
            }
            $this->history($order, $order->user, 'awaiting_payment', 'preparing');
            $order->update(['status' => 'preparing']);
            $this->notifyKitchen($order);
        });
    }

    /** The online payment was abandoned, expired or refused: the order is cancelled and its slot freed. */
    public function cancelUnpaid(Order $order): void
    {
        app(RestaurantLock::class)->run(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'awaiting_payment' || $order->paid_at) {
                return;
            }
            $this->history($order, $order->user, 'awaiting_payment', 'cancelled');
            $order->update(['status' => 'cancelled']);
        });
    }

    private function notifyKitchen(Order $order): void
    {
        $outboxId = DB::table('notification_outbox')->insertGetId(['order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::afterCommit(static function () use ($outboxId): void {
            try {
                SendOrderPush::dispatch($outboxId)->onConnection('database');
            } catch (\Throwable) {
                // The committed outbox is retried by the scheduler if the queue is unavailable.
                logger()->warning('Restaurant push dispatch postponed; outbox retained.');
            }
        });
    }

    public function changeStatus(Order $order, User $actor, string $status): void
    {
        abort_unless($actor->role === 'admin', 403);
        app(RestaurantLock::class)->run(function (RestaurantSetting $settings) use ($order, $actor, $status) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $transitions = ['awaiting_payment' => ['cancelled'], 'preparing' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => ['preparing']];
            if ($status === $order->status) {
                return;
            }
            if (! in_array($status, $transitions[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Ce changement de statut n’est pas autorisé.']);
            }
            if ($order->status === 'cancelled') {
                $this->assertAvailable(TimeSlot::query()->lockForUpdate()->findOrFail($order->time_slot_id), $settings, $order->total);
            }
            $this->history($order, $actor, $order->status, $status);
            $order->update(['status' => $status]);
        });
    }

    private function assertAvailable(TimeSlot $slot, RestaurantSetting $settings, int $orderTotal): void
    {
        $availability = app(SlotService::class)->describe($slot, $settings, $orderTotal);
        if (! $availability['selectable']) {
            throw ValidationException::withMessages(['slot_id' => 'Ce créneau n’est plus disponible : '.$availability['reason'].'. Choisissez un autre horaire.']);
        }
    }

    private function history(Order $order, User $user, ?string $from, string $to): void
    {
        DB::table('order_status_histories')->insert(['order_id' => $order->id, 'user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'created_at' => now(), 'updated_at' => now()]);
    }
}
