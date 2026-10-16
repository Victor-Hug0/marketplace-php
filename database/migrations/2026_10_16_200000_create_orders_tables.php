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
            $table->foreignUuid('buyer_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('status');
            $table->string('currency', 3)->default('BRL');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('shipping_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->string('idempotency_key');
            $table->foreignUuid('address_id')
                ->nullable()
                ->constrained('addresses')
                ->nullOnDelete();
            $table->string('shipping_recipient_name', 120);
            $table->string('shipping_phone', 20);
            $table->string('shipping_cep', 8);
            $table->string('shipping_street', 180);
            $table->string('shipping_number', 20);
            $table->string('shipping_complement', 120)->nullable();
            $table->string('shipping_district', 120);
            $table->string('shipping_city', 120);
            $table->string('shipping_state', 2);
            $table->timestamps();

            $table->unique(['buyer_id', 'idempotency_key']);
            $table->index('buyer_id');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('order_sellers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->foreignUuid('store_id')
                ->constrained('stores')
                ->restrictOnDelete();
            $table->string('status');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('shipping_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->string('shipping_service_code', 60)->nullable();
            $table->string('shipping_service_name', 120)->nullable();
            $table->unsignedSmallInteger('shipping_eta_days')->nullable();
            $table->timestamp('shipping_quoted_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'store_id']);
            $table->index('store_id');
            $table->index('status');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_seller_id')
                ->constrained('order_sellers')
                ->cascadeOnDelete();
            $table->foreignUuid('offer_id')
                ->nullable()
                ->constrained('offers')
                ->nullOnDelete();
            $table->foreignUuid('product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->foreignUuid('store_id')
                ->constrained('stores')
                ->restrictOnDelete();
            $table->string('product_name');
            $table->string('product_slug');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index('order_seller_id');
            $table->index('offer_id');
            $table->index('product_id');
            $table->index('store_id');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('statusable');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignUuid('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index('to_status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_sellers');
        Schema::dropIfExists('orders');
    }
};
