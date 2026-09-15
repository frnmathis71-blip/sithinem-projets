<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $default_capacity
 * @property string $timezone
 */
class RestaurantSetting extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['default_capacity' => 'integer'];
    }
}
