<?php

namespace App\Services;

use App\Models\RestaurantSetting;
use Closure;
use Illuminate\Support\Facades\DB;

class RestaurantLock
{
    /**
     * All availability, catalog and order writes acquire this lock first.
     * Serializing writes is intentionally simple for a single restaurant.
     * SQLite needs a write before any reads; FOR UPDATE is ignored there.
     *
     * @template T
     *
     * @param  Closure(RestaurantSetting): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('restaurant_settings')->where('id', 1)->update(['id' => 1]);
            }

            return $callback(RestaurantSetting::query()->lockForUpdate()->findOrFail(1));
        }, 5);
    }
}
