<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')
                ->unique()
                ->constrained('order_items')
                ->restrictOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUuid('product_id')
                ->constrained('products')
                ->restrictOnDelete();
            $table->foreignUuid('store_id')
                ->constrained('stores')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index('store_id');
            $table->index('user_id');
        });

        DB::statement(
            'ALTER TABLE product_reviews ADD CONSTRAINT product_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
