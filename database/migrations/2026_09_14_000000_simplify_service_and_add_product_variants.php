<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('variant_label', 100)->nullable();
            $table->boolean('has_variants')->default(false);
            $table->boolean('offer_only')->default(false)->index();
            $table->unsignedInteger('position')->default(0);
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->timestampTz('ends_at')->nullable()->change();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status')->default('preparing')->change();
        });
        // Preserve orders, their contents and the original status history.
        DB::table('orders')->whereIn('status', ['new', 'confirmed', 'ready'])->update(['status' => 'preparing']);
        DB::table('orders')->where('status', 'collected')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'completed')->update(['status' => 'collected']);
        Schema::table('orders', fn (Blueprint $table) => $table->string('status')->default('new')->change());
        // Keep nullable offer dates on rollback rather than invent expiry dates.
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'variant_label', 'has_variants', 'offer_only', 'position']);
        });
    }
};
