<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->string('code')->nullable());
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('restricted_sides')->default(false);
            $table->json('compatible_side_ids')->nullable();
        });
        Schema::create('menu_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category_key')->unique();
            $table->unsignedInteger('price')->default(2000);
            $table->boolean('available')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->uuid('instance_uuid')->nullable();
            $table->json('pricing_snapshot')->nullable();
        });
        // Only exact, unambiguous labels are mapped; custom labels are editable in admin.
        foreach (['Entrée' => 'starter', 'Entrées' => 'starter', 'Plat' => 'main', 'Plats' => 'main', 'Plat du chef' => 'chef_main', 'Accompagnement' => 'side', 'Accompagnements' => 'side', 'Dessert' => 'dessert', 'Desserts' => 'dessert', 'Boisson' => 'drink', 'Boissons' => 'drink'] as $name => $code) {
            DB::table('categories')->where('name', $name)->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['instance_uuid', 'pricing_snapshot']));
        Schema::dropIfExists('menu_rules');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['restricted_sides', 'compatible_side_ids']));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
