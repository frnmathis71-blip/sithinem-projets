<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $parent_id
 * @property string|null $variant_label
 * @property bool $has_variants
 * @property bool $offer_only
 * @property-read Product|null $parent
 */
class Product extends CatalogItem
{
    protected $attributes = ['available' => true, 'has_variants' => false, 'offer_only' => false, 'restricted_sides' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'has_variants' => 'boolean', 'offer_only' => 'boolean', 'restricted_sides' => 'boolean', 'compatible_side_ids' => 'array'];
    }

    /** @return BelongsTo<Product, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<Product, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    public function displayName(): string
    {
        return $this->variant_label ? $this->name.' — '.$this->variant_label : $this->name;
    }

    public function categoryCode(): ?string
    {
        return Category::query()->whereKey(($this->parent ?? $this)->getAttribute('category_id'))->value('code');
    }

    public function requiresSide(): bool
    {
        return $this->categoryCode() === 'main';
    }

    public function includesSide(): bool
    {
        return $this->categoryCode() === 'chef_main';
    }

    public function acceptsSide(Product $side): bool
    {
        $owner = $this->parent ?? $this;

        return $side->categoryCode() === 'side' && (! $owner->getAttribute('restricted_sides') || in_array($side->id, $owner->getAttribute('compatible_side_ids') ?? [], true));
    }
}
