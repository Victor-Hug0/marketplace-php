<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('currency', 3)->default('BRL');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->json('address_snapshot');
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['buyer_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('order_sellers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('status');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();

            $table->unique(['order_id', 'store_id']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_seller_id')->constrained('order_sellers')->cascadeOnDelete();
            $table->foreignUuid('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index('order_seller_id');
            $table->index('offer_id');
            $table->index('product_id');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('statusable');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->nullableUuidMorphs('actor');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['statusable_type', 'statusable_id', 'created_at'], 'order_status_histories_statusable_created_idx');
        });

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->foreignUuid('order_id')
                ->nullable()
                ->after('user_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_sellers');
        Schema::dropIfExists('orders');
    }
};
