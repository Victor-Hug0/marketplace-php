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
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->uuid('order_seller_id')->nullable()->change();
            $table->foreignUuid('refund_id')
                ->nullable()
                ->after('payment_id')
                ->constrained('refunds')
                ->restrictOnDelete();
            $table->foreignUuid('payout_id')
                ->nullable()
                ->after('refund_id')
                ->constrained('payouts')
                ->restrictOnDelete();

            $table->index('refund_id');
            $table->index('payout_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_source_check CHECK (
                    (reason = 'payout' AND payout_id IS NOT NULL)
                    OR (reason IN ('refund', 'commission_refund') AND refund_id IS NOT NULL AND order_seller_id IS NOT NULL)
                    OR (reason IN ('sale', 'commission') AND order_seller_id IS NOT NULL)
                )
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_source_check');
        }

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
            $table->dropConstrainedForeignId('refund_id');
            $table->uuid('order_seller_id')->nullable(false)->change();
        });
    }
};
