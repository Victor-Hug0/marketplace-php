<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_seller_id')
                ->unique()
                ->constrained('order_sellers')
                ->restrictOnDelete();
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30);
            $table->string('reason', 30);
            $table->text('description');
            $table->text('seller_response')->nullable();
            $table->timestamp('seller_responded_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_seller_id')
                ->unique()
                ->constrained('order_sellers')
                ->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->text('reason');
            $table->unsignedBigInteger('amount_cents');
            $table->text('decision_notes')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('disputes');
    }
};
