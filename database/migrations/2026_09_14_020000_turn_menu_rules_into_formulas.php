<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_rules', function (Blueprint $table) {
            $table->dropUnique(['category_key']);
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('position')->default(0);
        });
        $labels = ['starter' => 'Entrée', 'main' => 'Plat', 'dessert' => 'Dessert', 'drink' => 'Boisson'];
        foreach (DB::table('menu_rules')->get() as $rule) {
            DB::table('menu_rules')->where('id', $rule->id)->update(['name' => 'Menu '.implode(' + ', array_map(fn ($code) => $labels[$code] ?? $code, explode(',', $rule->category_key)))]);
        }
    }

    public function down(): void
    {
        if (DB::table('menu_rules')->select('category_key')->groupBy('category_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Plusieurs formules partagent des catégories : consolidez-les avant de revenir à l’ancien schéma.');
        }
        Schema::table('menu_rules', function (Blueprint $table) {
            $table->dropColumn(['name', 'description', 'visible', 'position']);
            $table->unique('category_key');
        });
    }
};
