<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\StripeCheckoutService;
use Illuminate\Console\Command;

class CancelUnpaidOrders extends Command
{
    protected $signature = 'restaurant:cancel-unpaid-orders';

    protected $description = 'Cancel online orders whose payment window is over, freeing their slot, in case the Stripe webhook was missed';

    public function handle(OrderService $orders): int
    {
        $limit = now()->subMinutes(StripeCheckoutService::PAYMENT_WINDOW + 10);
        foreach (Order::query()->where('status', 'awaiting_payment')->whereNull('paid_at')->where('created_at', '<', $limit)->get() as $order) {
            $orders->cancelUnpaid($order);
        }

        return self::SUCCESS;
    }
}
