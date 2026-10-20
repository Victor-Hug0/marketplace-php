<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Notifications\ProductDecidedNotification;
use App\Notifications\ProductSubmittedNotification;
use App\Notifications\StoreDecidedNotification;
use App\Notifications\StoreRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

final class ModerationNotificationsTest extends TestCase
{
    use BuildsPaidOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_store_request_notifies_admins_and_decision_notifies_the_applicant(): void
    {
        $admins = [$this->userWithRole(RoleName::ADMIN), $this->userWithRole(RoleName::ADMIN)];
        $applicant = $this->userWithRole(RoleName::BUYER);

        $storeId = $this->api($this->accessToken($applicant))
            ->postJson('/api/v1/stores', ['store_name' => 'Loja Nova', 'document_type' => 'cnpj', 'document' => '11222333000181'])
            ->assertCreated()
            ->json('data.id');

        Notification::assertSentTo($admins, StoreRequestedNotification::class);
        Notification::assertNotSentTo($applicant, StoreRequestedNotification::class);

        $this->api($this->accessToken($admins[0]))->postJson("/api/v1/admin/stores/{$storeId}/approve")->assertOk();

        Notification::assertSentTo($applicant, StoreDecidedNotification::class, fn (StoreDecidedNotification $n): bool => $n->type() === 'store.active');
    }

    public function test_rejected_store_notification_carries_the_reason(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $store = Store::factory()->create();

        $this->api($this->accessToken($admin))
            ->postJson("/api/v1/admin/stores/{$store->id}/reject", ['reason' => 'Documento ilegível na análise.'])
            ->assertOk();

        Notification::assertSentTo($store->owner, StoreDecidedNotification::class, function (StoreDecidedNotification $n) use ($store): bool {
            return $n->type() === 'store.rejected'
                && str_contains($n->toArray($store->owner)['message'], 'Documento ilegível na análise.');
        });
    }

    public function test_product_submission_notifies_admins_and_decision_notifies_the_seller(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $store = Store::factory()->active()->create();
        $seller = $this->sellerOf($store);
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => Category::factory()->create(['is_active' => true])->id,
            'status' => ProductStatus::DRAFT,
        ]);

        $this->api($this->accessToken($seller))->postJson("/api/v1/seller/products/{$product->id}/submit")->assertOk();
        Notification::assertSentTo($admin, ProductSubmittedNotification::class);

        $this->api($this->accessToken($admin))
            ->postJson("/api/v1/admin/products/{$product->id}/reject", ['reason' => 'As fotos não mostram o produto.'])
            ->assertOk();

        Notification::assertSentTo($seller, ProductDecidedNotification::class, fn (ProductDecidedNotification $n): bool => $n->type() === 'product.rejected');
    }
}
