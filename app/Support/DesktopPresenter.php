<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** JSON shapes consumed by the Windows application. */
class DesktopPresenter
{
    public const SOURCES = ['online' => 'Site internet', 'phone' => 'Téléphone', 'counter' => 'Comptoir'];

    public static function image(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }

    /** @return array<string, mixed> */
    public static function order(Order $order, bool $details = false): array
    {
        $data = [
            'id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'status_label' => Order::STATUSES[$order->status],
            'source' => $order->getAttribute('source') ?? 'online', 'source_label' => self::SOURCES[$order->getAttribute('source') ?? 'online'] ?? 'Site internet',
            'customer_name' => $order->customer_name, 'phone' => $order->phone, 'total' => $order->total,
            'paid' => $order->paid_at !== null, 'customer_message' => $order->getAttribute('customer_message'), 'accent' => $order->accent(),
            'slot' => ['id' => $order->slot->id, 'date' => $order->slot->date->format('Y-m-d'), 'start' => substr($order->slot->starts_at, 0, 5)],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'name' => $item->getAttribute('name'), 'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
                'composition' => $item->getAttribute('item_type') !== 'product' ? array_map(fn ($part) => ['name' => $part['name'], 'quantity' => $part['quantity']], $item->composition ?? []) : [],
            ])->all(),
        ];
        if ($details) {
            $data['history'] = DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn ($history) => ['label' => Order::HISTORY_LABELS[$history->to_status] ?? $history->to_status, 'at' => CarbonImmutable::parse($history->created_at, 'UTC')->timezone('Europe/Paris')->format('d/m à H:i')])->all();
        }

        return $data;
    }
}
