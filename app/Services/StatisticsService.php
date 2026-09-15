<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class StatisticsService
{
    /** @return array<string, mixed> */
    public function report(string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $periods = [];
        foreach (['Aujourd’hui' => [$now->startOfDay(), $now->startOfDay()->subDay()], 'Cette semaine' => [$now->startOfWeek(), $now->startOfWeek()->subWeek()], 'Ce mois' => [$now->startOfMonth(), $now->startOfMonth()->subMonth()], 'Cette année' => [$now->startOfYear(), $now->startOfYear()->subYear()]] as $label => [$start, $previousStart]) {
            $elapsed = $start->diffInSeconds($now);
            $previousEnd = $previousStart->addSeconds($elapsed)->min($start);
            $current = $this->query($start, $now);
            $revenue = (int) (clone $current)->sum('total');
            $previous = (int) $this->query($previousStart, $previousEnd)->sum('total');
            $periods[] = ['label' => $label, 'revenue' => $revenue, 'count' => (clone $current)->count(), 'evolution' => $previous > 0 ? round(($revenue - $previous) / $previous * 100) : null];
        }
        $today = Order::query()->whereHas('slot', fn (Builder $q) => $q->whereDate('date', $now->format('Y-m-d')))->where('status', '!=', 'cancelled');
        $units = 0;
        foreach ((clone $today)->where('status', 'completed')->with('items')->get() as $order) {
            foreach ($order->items as $item) {
                $units += $item->quantity * array_sum(array_column($item->composition, 'quantity'));
            }
        }
        $chart = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = $now->subDays($i)->startOfDay();
            $chart[] = ['label' => $day->format('d/m'), 'value' => (int) $this->query($day, $day->endOfDay())->sum('total'), 'count' => $this->query($day, $day->endOfDay())->count()];
        }

        return ['periods' => $periods, 'chart' => $chart, 'units' => $units, 'pending' => (clone $today)->where('status', 'preparing')->count(), 'completed' => (clone $today)->where('status', 'completed')->count(), 'cash' => (int) Order::query()->whereBetween('paid_at', [$now->startOfDay()->utc(), $now->endOfDay()->utc()])->sum('total')];
    }

    /** @return Builder<Order> */
    private function query(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Order::query()->where('status', '!=', 'cancelled')->whereBetween('created_at', [$start->utc(), $end->utc()]);
    }
}
