<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\TimeSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PickupTimeChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public TimeSlot $previous) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nouvelle heure de retrait pour votre commande '.$this->order->number);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.pickup-time-changed');
    }
}
