<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_seller_id')
                ->unique()
                ->constrained('order_sellers')
                ->restrictOnDelete();
            $table->string('tracking_code', 20)->unique();
            $table->string('carrier', 40)->default('simulated');
            $table->string('service_code', 60)->nullable();
            $table->string('status', 20);
            $table->timestamp('shipped_at');
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
