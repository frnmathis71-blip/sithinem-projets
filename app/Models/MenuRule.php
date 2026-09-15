<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $category_key
 * @property int $price
 * @property bool $available
 * @property int $version
 * @property string|null $name
 * @property string|null $description
 * @property bool $visible
 * @property int $position
 */
class MenuRule extends Model
{
    protected $guarded = [];

    protected $attributes = ['price' => 2000, 'available' => true, 'visible' => true, 'position' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['price' => 'integer', 'available' => 'boolean', 'visible' => 'boolean', 'position' => 'integer', 'version' => 'integer'];
    }

    public function label(): string
    {
        return $this->name ?: 'Menu '.$this->categoryLabel();
    }

    public function categoryLabel(): string
    {
        return implode(' + ', array_map(fn ($code) => Category::LABELS[$code] ?? $code, $this->categories()));
    }

    /** @return array<int, string> */
    public function categories(): array
    {
        return $this->category_key === '' ? [] : explode(',', $this->category_key);
    }

    /** @return array<int, string> */
    public function steps(): array
    {
        $categories = $this->categories();

        return array_values(array_filter(['starter', 'main', 'chef_main', 'side', 'dessert', 'drink'], fn ($code) => in_array($code === 'side' ? 'main' : $code, $categories, true)));
    }
}
