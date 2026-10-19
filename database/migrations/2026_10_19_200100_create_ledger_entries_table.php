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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_type', 20);
            $table->foreignUuid('store_id')
                ->nullable()
                ->constrained('stores')
                ->restrictOnDelete();
            $table->foreignUuid('order_seller_id')
                ->constrained('order_sellers')
                ->restrictOnDelete();
            $table->foreignUuid('payment_id')
                ->nullable()
                ->constrained('payments')
                ->restrictOnDelete();
            $table->foreignUuid('platform_fee_rule_id')
                ->nullable()
                ->constrained('platform_fee_rules')
                ->restrictOnDelete();
            $table->string('reason', 30);
            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('idempotency_key', 150)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['account_type', 'store_id']);
            $table->index('order_seller_id');
            $table->index('payment_id');
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_store_check CHECK (
                    (account_type = 'seller' AND store_id IS NOT NULL)
                    OR (account_type = 'platform' AND store_id IS NULL)
                )
            SQL);

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION ledger_entries_prevent_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'ledger_entries is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_prevent_mutation()
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS ledger_entries_prevent_mutation()');
        }
    }
};
