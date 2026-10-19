<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 30)->default('mock');
            $table->string('event_id', 100);
            $table->string('type', 60);
            $table->foreignUuid('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 60)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('payment_id');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
