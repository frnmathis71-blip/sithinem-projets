<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $default_amount_capacity
 * @property string $timezone
 * @property string|null $contact_phone
 */
class RestaurantSetting extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['default_amount_capacity' => 'integer'];
    }

    /** "tel:" link for the public phone number, keeping only digits and a leading plus. */
    public function contactPhoneLink(): ?string
    {
        return $this->contact_phone ? 'tel:'.preg_replace('/(?!^\+)[^0-9]/', '', $this->contact_phone) : null;
    }
}
