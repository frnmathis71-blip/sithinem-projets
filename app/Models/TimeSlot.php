<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $capacity_override
 * @property bool $manually_closed
 */
class TimeSlot extends Model
{
    protected $guarded = [];

    public function setDateAttribute(string|CarbonImmutable $value): void
    {
        $this->attributes['date'] = CarbonImmutable::parse($value)->format('Y-m-d');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'capacity_override' => 'integer', 'manually_closed' => 'boolean'];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
