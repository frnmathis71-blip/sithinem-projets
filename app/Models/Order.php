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
 * @property-read TimeSlot $slot
 */
class Order extends Model
{
    public const STATUSES = ['preparing' => 'En préparation', 'completed' => 'Terminée', 'cancelled' => 'Annulée'];

    public const HISTORY_LABELS = ['new' => 'Nouvelle', 'confirmed' => 'Confirmée', 'ready' => 'Prête', 'collected' => 'Retirée', ...self::STATUSES];

    public function accent(): string
    {
        return ['#28796b', '#b5691c', '#7760a5', '#386fa4', '#b44f65', '#5c7935', '#ad613e', '#547583'][$this->id % 8];
    }

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total' => 'integer', 'paid_at' => 'immutable_datetime'];
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
}
