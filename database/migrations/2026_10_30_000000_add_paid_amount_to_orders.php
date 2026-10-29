<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Amount actually received, so an order edited after payment shows what is left to pay or to refund. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('paid_amount')->nullable();
        });
        DB::table('orders')->whereNotNull('paid_at')->update(['paid_amount' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('paid_amount'));
    }
};
