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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('idempotency_key');
            $table->index(['status', 'payment_due_at']);
        });

        // Orders already waiting for payment get a fresh window from deploy time.
        DB::table('orders')
            ->where('status', 'pending_payment')
            ->update(['payment_due_at' => now()->addMinutes((int) config('orders.unpaid_ttl_minutes'))]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_due_at']);
            $table->dropColumn('payment_due_at');
        });
    }
};
