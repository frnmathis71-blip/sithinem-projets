<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('role')->default('client')->index();
        });
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('default_capacity')->default(10);
            $table->string('timezone')->default('Europe/Paris');
            $table->timestamps();
        });
        DB::table('restaurant_settings')->insert(['id' => 1, 'default_capacity' => 10, 'timezone' => 'Europe/Paris', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        foreach (['products', 'menus', 'offers'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('image')->nullable();
                $table->unsignedInteger('price');
                $table->boolean('available')->default(true);
                if ($name === 'products') {
                    $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
                }
                if ($name === 'offers') {
                    $table->timestampTz('starts_at');
                    $table->timestampTz('ends_at');
                }
                $table->softDeletes();
                $table->timestamps();
            });
        }
        foreach (['menu', 'offer'] as $type) {
            Schema::create($type.'_items', function (Blueprint $table) use ($type) {
                $table->id();
                $table->foreignId($type.'_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('quantity');
                $table->unique([$type.'_id', 'product_id']);
            });
        }
        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday')->unique();
            $table->boolean('is_open')->default(false);
            $table->time('starts_at')->default('18:00');
            $table->time('ends_at')->default('23:00');
            $table->timestamps();
        });
        foreach (range(1, 7) as $day) {
            DB::table('opening_hours')->insert(['weekday' => $day, 'is_open' => in_array($day, [5, 6]), 'starts_at' => '18:00', 'ends_at' => '23:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('opening_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->boolean('is_open');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('capacity_override')->nullable();
            $table->boolean('manually_closed')->default(false);
            $table->unique(['date', 'starts_at']);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('time_slot_id')->constrained()->restrictOnDelete();
            $table->string('customer_name');
            $table->string('phone', 30);
            $table->unsignedInteger('total');
            $table->string('status')->default('new');
            $table->timestampTz('paid_at')->nullable();
            $table->uuid('idempotency_key');
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['time_slot_id', 'status']);
            $table->timestamps();
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->string('name');
            $table->json('composition');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamps();
        });
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('public_key');
            $table->string('auth_token');
            $table->timestamps();
        });
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('delivered_endpoints')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notification_outbox', 'push_subscriptions', 'order_status_histories', 'order_items', 'orders', 'time_slots', 'opening_exceptions', 'opening_hours', 'offer_items', 'menu_items', 'offers', 'menus', 'products', 'categories', 'restaurant_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['first_name', 'phone', 'role']));
    }
};
