<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->timestamp('admin_paused_at')->nullable()->after('status');
            $table->string('admin_pause_reason', 2000)->nullable()->after('admin_paused_at');

            $table->index('admin_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['admin_paused_at']);
            $table->dropColumn(['admin_paused_at', 'admin_pause_reason']);
        });
    }
};
