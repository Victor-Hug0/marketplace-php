<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rate_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('service_code', 60);
            $table->string('service_name', 120);
            $table->string('zone', 20);
            $table->unsignedInteger('max_weight_grams');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedSmallInteger('eta_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_code', 'zone', 'max_weight_grams']);
            $table->index(['zone', 'is_active', 'max_weight_grams']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rate_rules');
    }
};
