<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->unsignedInteger('default_amount_capacity')->default(20000));
        Schema::table('time_slots', fn (Blueprint $table) => $table->unsignedInteger('amount_capacity_override')->nullable());
        // Preserve closed capacities; convert existing limits using a starting estimate of €20/order.
        DB::table('restaurant_settings')->update(['default_amount_capacity' => DB::raw('default_capacity * 2000')]);
        DB::table('time_slots')->whereNotNull('capacity_override')->update(['amount_capacity_override' => DB::raw('capacity_override * 2000')]);
    }

    public function down(): void
    {
        Schema::table('time_slots', fn (Blueprint $table) => $table->dropColumn('amount_capacity_override'));
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn('default_amount_capacity'));
    }
};
