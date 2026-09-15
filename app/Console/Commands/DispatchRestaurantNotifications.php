<?php

namespace App\Console\Commands;

use App\Jobs\SendOrderPush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchRestaurantNotifications extends Command
{
    protected $signature = 'restaurant:dispatch-notifications';

    protected $description = 'Enqueue pending restaurant push notifications from the transactional outbox';

    public function handle(): int
    {
        foreach (DB::table('notification_outbox')->whereNull('sent_at')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', now()))->orderBy('id')->limit(100)->get() as $row) {
            SendOrderPush::dispatch((int) $row->id);
        }

        return self::SUCCESS;
    }
}
