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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->restrictOnDelete();
            $table->string('provider', 30)->default('mock');
            $table->string('method', 30);
            $table->string('status', 30);
            $table->string('external_id', 100)->unique();
            $table->string('idempotency_key', 128);
            $table->string('currency', 3)->default('BRL');
            $table->unsignedBigInteger('amount_cents');
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key']);
            $table->index(['order_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX payments_order_open_unique ON payments (order_id) WHERE status IN ('pending', 'paid')",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
