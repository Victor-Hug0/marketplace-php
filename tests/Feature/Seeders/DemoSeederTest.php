<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\DisputeStatus;
use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_the_full_journey(): void
    {
        $this->seed(DemoSeeder::class);

        $admin = User::query()->where('email', 'admin@marketplace.test')->firstOrFail();
        $buyer = User::query()->where('email', 'comprador@marketplace.test')->firstOrFail();
        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertTrue($buyer->hasRole(RoleName::BUYER));
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $buyer->password));

        $statuses = Order::query()
            ->where('buyer_id', $buyer->id)
            ->pluck('status', 'idempotency_key')
            ->map(fn (OrderStatus $status): string => $status->value)
            ->all();

        $this->assertEquals([
            'demo-aguardando-pagamento' => 'pending_payment',
            'demo-pago' => 'paid',
            'demo-enviado' => 'shipped',
            'demo-entregue-avaliado' => 'delivered',
            'demo-em-disputa' => 'delivered',
            'demo-devolucao-pendente' => 'delivered',
            'demo-cancelado' => 'cancelled',
        ], $statuses);

        $paid = Order::query()->where('idempotency_key', 'demo-pago')->firstOrFail();
        $this->assertCount(2, $paid->sellers);

        $this->assertSame(4, Shipment::query()->count());
        $this->assertSame(1, ProductReview::query()->count());
        $this->assertSame(DisputeStatus::OPEN, Dispute::query()->sole()->status);
        $this->assertSame(ReturnRequestStatus::REQUESTED, ReturnRequest::query()->sole()->status);
        $this->assertSame(PayoutStatus::REQUESTED, Payout::query()->sole()->status);
        $this->assertSame(StoreStatus::PENDING, Store::query()->where('slug', 'loja-candidata-demo')->value('status'));
        $this->assertSame(ProductStatus::PENDING_MODERATION, Product::query()->where('slug', 'fone-bluetooth-demo')->value('status'));
        $this->assertSame(OrderSellerStatus::DELIVERED, Dispute::query()->sole()->orderSeller->status);

        $this->assertSame(
            (int) Payment::query()->where('status', PaymentStatus::PAID)->sum('amount_cents'),
            (int) LedgerEntry::query()->sum('amount_cents'),
        );

        foreach (Offer::query()->get() as $offer) {
            $this->assertGreaterThanOrEqual(0, $offer->stock - $offer->reserved_stock);
        }
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = $this->counts();

        $this->seed(DemoSeeder::class);

        $this->assertSame($counts, $this->counts());
    }

    public function test_demo_seeder_never_runs_in_production(): void
    {
        $this->app['env'] = 'production';

        // db:seed would ask for confirmation in production, so invoke the seeder directly.
        $seeder = $this->app->make(DemoSeeder::class);
        $seeder->setContainer($this->app);
        $seeder->__invoke();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_database_seeder_skips_demo_data_outside_local(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(User::query()->where('email', 'admin@marketplace.test')->exists());
        $this->assertSame(0, Order::query()->count());
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'orders' => Order::query()->count(),
            'payments' => Payment::query()->count(),
            'ledger' => LedgerEntry::query()->count(),
            'shipments' => Shipment::query()->count(),
            'reviews' => ProductReview::query()->count(),
            'disputes' => Dispute::query()->count(),
            'returns' => ReturnRequest::query()->count(),
            'payouts' => Payout::query()->count(),
            'stores' => Store::query()->count(),
        ];
    }
}
