<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $time_slot_id
 * @property string $number
 * @property string $status
 * @property string $customer_name
 * @property string $phone
 * @property int $total
 * @property CarbonImmutable|null $paid_at
 * @property string $payment_method
 * @property int|null $paid_amount
 * @property string|null $stripe_session_id
 * @property CarbonImmutable $created_at
 * @property-read TimeSlot $slot
 * @property-read User $user
 */
class Order extends Model
{
    /**
     * An online order waits for its Stripe payment before reaching the kitchen; it keeps its slot meanwhile.
     * Then the restaurant moves it along: received, accepted ("preparing", the value older orders already use), ready, completed.
     */
    public const STATUSES = ['awaiting_payment' => 'Paiement en attente', 'received' => 'Reçue', 'preparing' => 'Acceptée', 'ready' => 'Prête', 'completed' => 'Terminée', 'cancelled' => 'Annulée'];

    /** Orders the kitchen still has to handle: on the board, printed automatically, editable. */
    public const ACTIVE = ['received', 'preparing', 'ready'];

    /** Steps shown to the customer, with what each one means for them. */
    public const TRACKING = [
        'received' => 'Le restaurant a bien reçu votre commande.',
        'preparing' => 'Votre commande est acceptée : elle sera préparée pour votre heure de retrait.',
        'ready' => 'Votre commande est prête : vous pouvez venir la retirer.',
        'completed' => 'Commande retirée. Bon appétit !',
    ];

    /**
     * Where the restaurant may move an order from each status: one step forward at a time, never back to "received";
     * a ready or completed order can step back once to correct a mistake, a cancelled one comes back accepted.
     */
    public const TRANSITIONS = [
        'awaiting_payment' => ['cancelled'],
        'received' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'preparing', 'cancelled'],
        'completed' => ['ready'],
        'cancelled' => ['preparing'],
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public const PAYMENT_METHODS = ['onsite' => 'Sur place au retrait', 'online' => 'En ligne'];

    /** Older status names, current ones, and the changes the restaurant made to an order. */
    public const HISTORY_LABELS = ['new' => 'Nouvelle', 'confirmed' => 'Confirmée', 'collected' => 'Retirée', ...self::STATUSES, 'rescheduled' => 'Heure de retrait modifiée', 'edited' => 'Articles modifiés par le restaurant'];

    public function accent(): string
    {
        return ['#28796b', '#b5691c', '#7760a5', '#386fa4', '#b44f65', '#5c7935', '#ad613e', '#547583'][$this->id % 8];
    }

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total' => 'integer', 'paid_amount' => 'integer', 'paid_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TimeSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
