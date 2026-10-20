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
        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->nullable()->after('store_id');
            $table->unsignedBigInteger('promotional_price_cents')->nullable()->after('price_cents');
        });

        DB::statement('UPDATE offers SET price_cents = ROUND(price * 100), promotional_price_cents = ROUND(promotional_price * 100)');

        Schema::table('offers', function (Blueprint $table) {
            $table->unsignedBigInteger('price_cents')->nullable(false)->change();
            $table->dropColumn(['price', 'promotional_price']);
        });

        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_price_cents_positive CHECK (price_cents > 0)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_promotional_price_cents_valid CHECK (promotional_price_cents IS NULL OR (promotional_price_cents > 0 AND promotional_price_cents < price_cents))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_promotional_price_cents_valid');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_price_cents_positive');

        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('store_id');
            $table->decimal('promotional_price', 12, 2)->nullable()->after('price');
        });

        DB::statement('UPDATE offers SET price = price_cents / 100.0, promotional_price = promotional_price_cents / 100.0');

        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable(false)->change();
            $table->dropColumn(['price_cents', 'promotional_price_cents']);
        });
    }
};
