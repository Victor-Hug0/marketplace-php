<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->nullableUuidMorphs('actor');
            $table->nullableUuidMorphs('auditable');
            $table->json('payload')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
