<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $quantity
 * @property int $unit_price
 * @property array<int, array{name: string, quantity: int}> $composition
 */
class OrderItem extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['composition' => 'array', 'pricing_snapshot' => 'array', 'quantity' => 'integer', 'unit_price' => 'integer'];
    }
}
