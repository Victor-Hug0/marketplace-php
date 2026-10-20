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
        Schema::create('platform_fee_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->unsignedInteger('percent_bps')->nullable();
            $table->unsignedBigInteger('fixed_cents')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE platform_fee_rules ADD CONSTRAINT platform_fee_rules_value_check CHECK (
                    (type = 'percentage' AND percent_bps IS NOT NULL AND percent_bps <= 10000 AND fixed_cents IS NULL)
                    OR (type = 'fixed' AND fixed_cents IS NOT NULL AND percent_bps IS NULL)
                )
            SQL);

            DB::statement(
                'ALTER TABLE platform_fee_rules ADD CONSTRAINT platform_fee_rules_period_check CHECK (ends_at IS NULL OR ends_at > starts_at)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_rules');
    }
};
