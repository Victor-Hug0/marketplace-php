<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable()->after('description');
            $table->unsignedSmallInteger('length_cm')->nullable()->after('weight_grams');
            $table->unsignedSmallInteger('width_cm')->nullable()->after('length_cm');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('width_cm');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('origin_cep', 8)->nullable()->after('commercial_phone');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('origin_cep');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['weight_grams', 'length_cm', 'width_cm', 'height_cm']);
        });
    }
};
