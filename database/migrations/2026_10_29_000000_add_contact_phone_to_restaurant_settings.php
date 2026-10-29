<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->string('contact_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', fn (Blueprint $table) => $table->dropColumn('contact_phone'));
    }
};
