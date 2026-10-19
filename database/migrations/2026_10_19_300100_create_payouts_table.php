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
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')
                ->constrained('stores')
                ->restrictOnDelete();
            $table->foreignUuid('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUuid('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('idempotency_key', 128);
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['store_id', 'status']);
            $table->index('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive_check CHECK (amount_cents > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
