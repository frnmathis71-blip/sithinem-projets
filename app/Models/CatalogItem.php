<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $image
 * @property int $price
 * @property bool $available
 * @property list<int>|null $available_weekdays
 */
abstract class CatalogItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['available' => 'boolean', 'price' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'available_weekdays' => 'array'];
    }

    public const WEEKDAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /**
     * ISO weekdays (1 = Monday) on which the item can be picked up, or null when it is sold every day.
     *
     * @return list<int>|null
     */
    public function weekdays(): ?array
    {
        $days = $this->getAttribute('available_weekdays');

        return is_array($days) && $days !== [] ? array_values(array_map('intval', $days)) : null;
    }

    /** Without a pickup day (browsing the menu) every item stays visible; the day is checked once known. */
    public function availableOn(?CarbonInterface $day): bool
    {
        $days = $this->weekdays();

        return $days === null || $day === null || in_array($day->isoWeekday(), $days, true);
    }

    /** "Uniquement le mercredi", "Uniquement le mardi et le mercredi", or null when sold every day. */
    public function weekdayLabel(): ?string
    {
        $days = $this->weekdays();
        if ($days === null) {
            return null;
        }
        sort($days);
        $names = array_map(fn (int $day) => self::WEEKDAYS[$day], $days);
        if (count($names) === 1) {
            return 'Uniquement le '.$names[0];
        }
        $last = array_pop($names);

        return 'Uniquement les '.implode(', ', $names).' et '.$last;
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
