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
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('category_attribute_id')->constrained('category_attributes')->cascadeOnDelete();
            // Exactly one column is filled, according to category_attributes.data_type (enum options go in value_string).
            $table->string('value_string', 255)->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'category_attribute_id']);
            // Catalog filters look up products by (attribute, value).
            $table->index(['category_attribute_id', 'value_number']);
            $table->index(['category_attribute_id', 'value_boolean']);
        });

        // Text and option filters compare case-insensitively: lower(value_string) IN (...).
        DB::statement('CREATE INDEX product_attribute_values_attribute_lower_string_index ON product_attribute_values (category_attribute_id, lower(value_string))');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
