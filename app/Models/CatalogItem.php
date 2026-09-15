<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $image
 * @property int $price
 * @property bool $available
 */
abstract class CatalogItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['available' => 'boolean', 'price' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function kind(): string
    {
        return match (true) {
            $this instanceof Product => 'product',
            $this instanceof Menu => 'menu',
            default => 'offer',
        };
    }
}
