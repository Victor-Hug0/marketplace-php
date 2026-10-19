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
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();
            $table->foreignUuid('order_seller_id')
                ->constrained('order_sellers')
                ->restrictOnDelete();
            $table->foreignUuid('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('external_id', 100)->unique();
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('reason', 500);
            $table->boolean('restocked')->default(false);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['order_seller_id', 'idempotency_key']);
            $table->index('payment_id');
            $table->index('order_seller_id');
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive_check CHECK (amount_cents > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
