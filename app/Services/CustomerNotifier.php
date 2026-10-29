<?php

namespace App\Services;

use App\Mail\PickupTimeChanged;
use App\Models\Order;
use App\Models\TimeSlot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** Messages sent to a customer about their order. A failed channel is logged and never blocks the change itself. */
class CustomerNotifier
{
    public function pickupChanged(Order $order, TimeSlot $previous): void
    {
        // Phone and counter orders belong to the staff account: only online customers get the email.
        if ($order->source === 'online' && filled($order->user->email)) {
            try {
                Mail::to($order->user->email)->send(new PickupTimeChanged($order, $previous));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $this->sms($order->phone, 'Sithinem : votre commande '.$order->number.' sera prête le '.$order->slot->date->format('d/m').' à '.substr($order->slot->starts_at, 0, 5).' (au lieu de '.substr($previous->starts_at, 0, 5).'). Suivi : '.route('orders.show', $order));
    }

    public function smsEnabled(): bool
    {
        return (bool) config('services.sms.enabled') && filled(config('services.sms.from')) && filled(config('services.twilio.account_sid')) && filled(config('services.twilio.auth_token'));
    }

    private function sms(?string $phone, string $message): void
    {
        if (blank($phone) || ! $this->smsEnabled()) {
            return;
        }
        try {
            $sid = (string) config('services.twilio.account_sid');
            Http::asForm()->withBasicAuth($sid, (string) config('services.twilio.auth_token'))->timeout(10)
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', [
                    'From' => config('services.sms.from'),
                    'To' => app(PhoneVerificationService::class)->normalize($phone),
                    'Body' => $message,
                ])->throw();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
