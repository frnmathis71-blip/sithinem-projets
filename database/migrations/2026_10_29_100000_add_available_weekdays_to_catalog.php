<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Null means every day, so existing products and offers keep their current availability. */
    public function up(): void
    {
        foreach (['products', 'offers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('available_weekdays')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'offers'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('available_weekdays'));
        }
    }
};
