<?php

namespace App\Services;

use App\Models\RestaurantSetting;
use App\Models\TimeSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SlotService
{
    public const DURATION = 20;

    public const LEAD_TIME = 30;

    public const HORIZON = 30;

    /** @return array<int, array{date: string, label: string}> */
    public function bookingDates(RestaurantSetting $settings): array
    {
        $now = CarbonImmutable::now($settings->timezone);
        $weeklyHours = DB::table('opening_hours')->get()->keyBy('weekday');
        $exceptions = DB::table('opening_exceptions')
            ->whereBetween('date', [$now->format('Y-m-d'), $now->addDays(self::HORIZON)->format('Y-m-d')])
            ->get()->keyBy('date');
        $dates = [];
        for ($i = 0; $i <= self::HORIZON; $i++) {
            $day = $now->startOfDay()->addDays($i);
            $date = $day->format('Y-m-d');
            $hours = $this->formatHours($exceptions->get($date) ?? $weeklyHours->get($day->isoWeekday()));
            if (! $hours['open']) {
                continue;
            }
            $start = CarbonImmutable::parse($date.' '.$hours['start'], $settings->timezone);
            $end = CarbonImmutable::parse($date.' '.$hours['end'], $settings->timezone);
            $count = (int) floor($start->diffInMinutes($end) / self::DURATION);
            if ($count > 0 && $start->addMinutes(($count - 1) * self::DURATION)->gte($now->addMinutes(self::LEAD_TIME))) {
                $day->locale('fr');
                $dates[] = ['date' => $date, 'label' => $day->translatedFormat('l j F')];
            }
        }

        return $dates;
    }

    /** @return array{open: bool, start: string, end: string, reason: string} */
    public function hours(string $date): array
    {
        $row = DB::table('opening_exceptions')->where('date', $date)->first()
            ?? DB::table('opening_hours')->where('weekday', CarbonImmutable::parse($date)->isoWeekday())->first();

        return $this->formatHours($row);
    }

    /** @return array{open: bool, start: string, end: string, reason: string} */
    private function formatHours(?object $row): array
    {
        return ['open' => (bool) ($row->is_open ?? false), 'start' => substr($row->starts_at ?? '18:00', 0, 5), 'end' => substr($row->ends_at ?? '23:00', 0, 5), 'reason' => $row->reason ?? ''];
    }

    /** @return array<int, array<string, mixed>> */
    public function forDate(string $date, int $orderTotal = 0): array
    {
        return app(RestaurantLock::class)->run(function (RestaurantSetting $settings) use ($date, $orderTotal) {
            $hours = $this->hours($date);
            $slots = TimeSlot::query()->whereDate('date', $date)->orderBy('starts_at')->get()->keyBy('starts_at');
            if ($hours['open']) {
                $start = CarbonImmutable::parse($date.' '.$hours['start'], $settings->timezone);
                $end = CarbonImmutable::parse($date.' '.$hours['end'], $settings->timezone);
                for ($cursor = $start; $cursor->addMinutes(self::DURATION)->lte($end); $cursor = $cursor->addMinutes(self::DURATION)) {
                    $time = $cursor->format('H:i:s');
                    if (! $slots->has($time)) {
                        $slots->put($time, TimeSlot::query()->firstOrCreate(['date' => $date, 'starts_at' => $time], ['ends_at' => $cursor->addMinutes(self::DURATION)->format('H:i:s')])->refresh());
                    }
                }
            }

            $usage = DB::table('orders')->join('time_slots', 'time_slots.id', '=', 'orders.time_slot_id')
                ->whereDate('time_slots.date', $date)->where('orders.status', '!=', 'cancelled')
                ->selectRaw('orders.time_slot_id, COUNT(*) as order_count, SUM(orders.total) as used')
                ->groupBy('orders.time_slot_id')->get()->keyBy('time_slot_id');

            return $slots->sortKeys()->values()
                ->map(fn (TimeSlot $slot) => $this->describeSnapshot($slot, $settings, $orderTotal, $hours,
                    (int) ($usage->get($slot->id)->order_count ?? 0), (int) ($usage->get($slot->id)->used ?? 0)))->all();
        });
    }

    /** @return array<string, mixed> */
    public function describe(TimeSlot $slot, RestaurantSetting $settings, int $orderTotal = 0): array
    {
        // Always read fresh usage for reservation and reactivation checks.
        $usage = $slot->orders()->where('status', '!=', 'cancelled')
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as used')->toBase()->first();

        return $this->describeSnapshot($slot, $settings, $orderTotal, $this->hours($slot->date->format('Y-m-d')),
            (int) $usage->order_count, (int) $usage->used);
    }

    /**
     * @param  array{open: bool, start: string, end: string, reason: string}  $hours
     * @return array<string, mixed>
     */
    private function describeSnapshot(TimeSlot $slot, RestaurantSetting $settings, int $orderTotal, array $hours, int $count, int $used): array
    {
        $date = $slot->date->format('Y-m-d');
        $start = CarbonImmutable::parse($date.' '.$slot->starts_at, $settings->timezone);
        $end = CarbonImmutable::parse($date.' '.$slot->ends_at, $settings->timezone);
        $opening = CarbonImmutable::parse($date.' '.$hours['start'], $settings->timezone);
        $closing = CarbonImmutable::parse($date.' '.$hours['end'], $settings->timezone);
        $now = CarbonImmutable::now($settings->timezone);
        $capacity = $slot->amount_capacity_override ?? $settings->default_amount_capacity;
        $remaining = max(0, $capacity - $used);
        $inside = $hours['open'] && $start->gte($opening) && $end->lte($closing)
            && $end->eq($start->addMinutes(self::DURATION))
            && ((int) $opening->diffInMinutes($start)) % self::DURATION === 0;
        $reason = match (true) {
            $slot->manually_closed => 'Fermé manuellement',
            ! $inside => 'Restaurant fermé',
            $remaining === 0 => 'Complet',
            $orderTotal > $remaining => 'Capacité insuffisante pour votre panier',
            $start->lt($now->addMinutes(self::LEAD_TIME)) => 'Délai insuffisant',
            $start->startOfDay()->gt($now->startOfDay()->addDays(self::HORIZON)) => 'Réservations non ouvertes',
            default => null,
        };
        $status = match (true) {
            $slot->manually_closed || ! $inside => 'Fermé',
            $remaining === 0 => 'Complet',
            $remaining <= max(1, (int) ceil($capacity * 0.2)) => 'Presque complet',
            default => 'Disponible',
        };

        return ['id' => $slot->id, 'date' => $date, 'start' => $start->format('H:i'), 'end' => $end->format('H:i'), 'capacity' => $capacity, 'capacity_override' => $slot->amount_capacity_override, 'used' => $used, 'count' => $count, 'remaining' => $remaining, 'status' => $status, 'selectable' => $reason === null, 'reason' => $reason, 'manually_closed' => $slot->manually_closed];
    }
}
