<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class SendOrderPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public int $outboxId) {}

    public function uniqueId(): string
    {
        return (string) $this->outboxId;
    }

    public function handle(): void
    {
        $row = DB::table('notification_outbox')->where('id', $this->outboxId)->first();
        if (! $row || $row->sent_at) {
            return;
        }
        DB::table('notification_outbox')->where('id', $this->outboxId)->increment('attempts');
        try {
            if (! config('restaurant.vapid.private_key') || ! config('restaurant.vapid.subject')) {
                throw new \RuntimeException('Notifications push non configurées.');
            }
            $order = Order::query()->with('slot')->whereKey($row->order_id)->firstOrFail();
            $subscriptions = DB::table('push_subscriptions')->whereIn('user_id', DB::table('users')->select('id')->where('role', 'admin'))->get();
            if ($subscriptions->isEmpty()) {
                throw new \RuntimeException('Aucun appareil administrateur abonné.');
            }
            $webPush = $this->makeWebPush();
            $delivered = json_decode($row->delivered_endpoints ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $failed = false;
            foreach ($subscriptions as $subscription) {
                if (in_array($subscription->endpoint_hash, $delivered, true)) {
                    continue;
                }
                $report = $webPush->sendOneNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key, 'authToken' => $subscription->auth_token, 'contentEncoding' => 'aes128gcm']), json_encode(['title' => 'Nouvelle commande #'.$order->id, 'body' => $order->customer_name.' — retrait à '.substr($order->slot->starts_at, 0, 5).' — '.number_format($order->total / 100, 2, ',', ' ').' €', 'tag' => 'order-'.$order->id, 'url' => route('admin.orders.show', $order)], JSON_THROW_ON_ERROR));
                if ($report->isSuccess()) {
                    $delivered[] = $subscription->endpoint_hash;
                    DB::table('notification_outbox')->where('id', $this->outboxId)->update(['delivered_endpoints' => json_encode($delivered, JSON_THROW_ON_ERROR)]);
                } elseif ($report->isSubscriptionExpired()) {
                    DB::table('push_subscriptions')->where('id', $subscription->id)->delete();
                } else {
                    $failed = true;
                }
            }
            if ($failed || $delivered === []) {
                throw new \RuntimeException('Un service push n’a pas accepté la notification. Nouvelle tentative planifiée.');
            }
            DB::table('notification_outbox')->where('id', $this->outboxId)->update(['sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            // Do not log endpoint tokens or customer data from HTTP exceptions.
            DB::table('notification_outbox')->where('id', $this->outboxId)->update(['last_error' => $exception instanceof \RuntimeException ? 'Envoi indisponible : vérifiez la configuration et les abonnements.' : 'Échec de l’envoi push.', 'retry_at' => now()->addSeconds(min(3600, 30 * (2 ** min((int) $row->attempts, 7)))), 'updated_at' => now()]);
        }
    }

    protected function makeWebPush(): WebPush
    {
        return new WebPush(['VAPID' => ['subject' => config('restaurant.vapid.subject'), 'publicKey' => config('restaurant.vapid.public_key'), 'privateKey' => config('restaurant.vapid.private_key')]], ['TTL' => 3600, 'urgency' => 'high'], new Client(['timeout' => 10, 'allow_redirects' => false]));
    }
}
