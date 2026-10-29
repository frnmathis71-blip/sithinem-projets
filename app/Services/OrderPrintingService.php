<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RestaurantSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderPrintingService
{
    public function configured(): bool
    {
        return strlen((string) config('restaurant.printing.token')) >= 32;
    }

    /** @return Builder<Order> */
    public function today(): Builder
    {
        $date = CarbonImmutable::now(RestaurantSetting::query()->findOrFail(1)->timezone)->format('Y-m-d');

        return Order::query()->with(['slot', 'items'])
            ->whereHas('slot', fn (Builder $query) => $query->whereDate('date', $date))
            ->orderBy('time_slot_id')->orderBy('id');
    }

    public function enqueueToday(string $batch): int
    {
        return app(RestaurantLock::class)->run(function () use ($batch) {
            $count = 0;
            foreach ($this->today()->get() as $order) {
                $this->enqueue($order, 'auto:'.$order->id, true);
                DB::table('order_print_jobs')->where('request_key', 'auto:'.$order->id)->where('status', 'pending')
                    ->update(['status' => 'skipped', 'updated_at' => now()]);
                $count += $this->enqueue($order, 'batch:'.$batch.':'.$order->id, false);
            }

            return $count;
        });
    }

    private function enqueue(Order $order, string $key, bool $automatic): int
    {
        return DB::table('order_print_jobs')->insertOrIgnore([
            'order_id' => $order->id, 'request_key' => $key, 'automatic' => $automatic,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{id: int, token: string, title: string, text: string}|null */
    public function claim(): ?array
    {
        return app(RestaurantLock::class)->run(function () {
            // Retain uncertain deliveries for explicit review, never print them twice automatically.
            DB::table('order_print_jobs')->where('status', 'printing')->where('claimed_at', '<', now()->subMinutes(5))
                ->update(['status' => 'uncertain', 'error' => 'Le poste n’a pas confirmé l’envoi. Vérifiez l’imprimante avant de relancer.', 'updated_at' => now()]);
            foreach ($this->today()->whereIn('status', Order::ACTIVE)->get() as $order) {
                $this->enqueue($order, 'auto:'.$order->id, true);
            }
            while ($job = DB::table('order_print_jobs')->where('status', 'pending')->orderBy('id')->first()) {
                $order = Order::query()->with(['slot', 'items'])->whereKey($job->order_id)->firstOrFail();
                $today = CarbonImmutable::now(RestaurantSetting::query()->findOrFail(1)->timezone)->format('Y-m-d');
                if ($order->slot->date->format('Y-m-d') !== $today || ($job->automatic && ! $order->isActive())) {
                    DB::table('order_print_jobs')->where('id', $job->id)->update(['status' => 'skipped', 'updated_at' => now()]);

                    continue;
                }
                $token = (string) Str::uuid();
                DB::table('order_print_jobs')->where('id', $job->id)->update([
                    'status' => 'printing', 'claim_token' => $token, 'claimed_at' => now(), 'updated_at' => now(),
                ]);

                return ['id' => (int) $job->id, 'token' => $token, 'title' => 'Sithinem #'.$order->id, 'text' => $this->text($order)];
            }

            return null;
        });
    }

    public function acknowledge(int $id, string $token, bool $success, ?string $error): bool
    {
        return DB::table('order_print_jobs')->where('id', $id)->where('claim_token', $token)
            ->whereIn('status', ['printing', 'uncertain'])->update([
                'status' => $success ? 'submitted' : 'failed', 'submitted_at' => $success ? now() : null,
                'error' => $success ? null : $error, 'updated_at' => now(),
            ]) > 0;
    }

    public function retry(int $id): void
    {
        DB::table('order_print_jobs')->where('id', $id)->whereIn('status', ['failed', 'uncertain'])
            ->update(['status' => 'pending', 'claim_token' => null, 'claimed_at' => null, 'error' => null, 'updated_at' => now()]);
    }

    public function text(Order $order): string
    {
        $lines = ['SITHINEM', 'COMMANDE #'.$order->id, $order->number,
            $order->slot->date->format('d/m/Y').' - Retrait '.substr($order->slot->starts_at, 0, 5),
            $order->customer_name, Order::STATUSES[$order->status], str_repeat('-', 24)];
        foreach ($order->items as $item) {
            $lines[] = $item->quantity.' x '.$item->name;
            if ($item->item_type !== 'product') {
                foreach ($item->composition ?? [] as $part) {
                    $lines[] = '  '.$part['quantity'].' x '.$part['name'].' (par menu)';
                }
            }
            $lines[] = '';
        }
        $lines[] = str_repeat('-', 24);
        $lines[] = 'COMMENTAIRE CLIENT';
        $lines[] = $order->customer_message ?: 'Aucun commentaire.';

        // Keep line breaks, strip printer/control characters from customer content.
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', implode("\n", $lines)) ?? '';
    }
}
