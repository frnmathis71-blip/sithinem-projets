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
    public function place(User $user, int $slotId, string $phone, string $key, array $cart, ?int $expectedTotal = null, ?string $customerMessage = null): Order
    {
        Validator::make(['phone' => $phone, 'key' => $key], ['phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 .()\-]{7,24}$/'], 'key' => 'required|uuid'])->validate();

        Validator::make(['customer_message' => $customerMessage], ['customer_message' => 'nullable|string|max:1000'])->validate();
        $customerMessage = trim($customerMessage ?? '');

        return app(RestaurantLock::class)->run(function (RestaurantSetting $settings) use ($user, $slotId, $phone, $key, $cart, $expectedTotal, $customerMessage) {
            $existing = Order::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $slot = TimeSlot::query()->lockForUpdate()->findOrFail($slotId);
            $this->assertAvailable($slot, $settings);
            $pickup = CarbonImmutable::parse($slot->date->format('Y-m-d').' '.$slot->starts_at, $settings->timezone);
            $quote = app(CatalogService::class)->quote($cart, $pickup);
            if ($expectedTotal !== null && $expectedTotal !== $quote['total']) {
                throw ValidationException::withMessages(['cart' => 'Le tarif a changé. Consultez votre panier avant de confirmer à nouveau.']);
            }
            $order = Order::query()->create(['number' => 'SN-'.strtoupper((string) Str::ulid()), 'user_id' => $user->id, 'time_slot_id' => $slot->id, 'customer_name' => trim($user->first_name.' '.$user->name), 'phone' => $phone, 'total' => $quote['total'], 'status' => 'preparing', 'idempotency_key' => $key]);
            $order->update(['customer_message' => $customerMessage === '' ? null : $customerMessage]);
            $order->items()->createMany($quote['lines']);
            $user->forceFill(['phone' => $phone])->save();
            $this->history($order, $user, null, 'preparing');
            $outboxId = DB::table('notification_outbox')->insertGetId(['order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(static function () use ($outboxId): void {
                try {
                    SendOrderPush::dispatch($outboxId)->onConnection('database');
                } catch (\Throwable) {
                    // The committed outbox is retried by the scheduler if the queue is unavailable.
                    logger()->warning('Restaurant push dispatch postponed; outbox retained.');
                }
            });

            return $order;
        });
    }

    public function changeStatus(Order $order, User $actor, string $status): void
    {
        abort_unless($actor->role === 'admin', 403);
        app(RestaurantLock::class)->run(function (RestaurantSetting $settings) use ($order, $actor, $status) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $transitions = ['preparing' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => ['preparing']];
            if ($status === $order->status) {
                return;
            }
            if (! in_array($status, $transitions[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Ce changement de statut n’est pas autorisé.']);
            }
            if ($order->status === 'cancelled') {
                $this->assertAvailable(TimeSlot::query()->lockForUpdate()->findOrFail($order->time_slot_id), $settings);
            }
            $this->history($order, $actor, $order->status, $status);
            $order->update(['status' => $status]);
        });
    }

    private function assertAvailable(TimeSlot $slot, RestaurantSetting $settings): void
    {
        $availability = app(SlotService::class)->describe($slot, $settings);
        if (! $availability['selectable']) {
            throw ValidationException::withMessages(['slot_id' => 'Ce créneau n’est plus disponible : '.$availability['reason'].'. Choisissez un autre horaire.']);
        }
    }

    private function history(Order $order, User $user, ?string $from, string $to): void
    {
        DB::table('order_status_histories')->insert(['order_id' => $order->id, 'user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'created_at' => now(), 'updated_at' => now()]);
    }
}
