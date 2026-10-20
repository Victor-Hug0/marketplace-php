<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Enums\RoleName;
use App\Models\Offer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class ProductReviewTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
    }

    public function test_buyer_reviews_a_delivered_item(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->buyerNamed('Maria Compradora Silva');
        $item = $this->deliveredItem($buyer, $offer);

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-items/{$item->id}/reviews", [
                'rating' => 5,
                'title' => 'Excelente',
                'body' => 'Chegou rápido e bem embalado.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.product_id', $offer->product_id)
            ->assertJsonPath('data.author.name', 'Maria');

        $this->assertDatabaseHas('product_reviews', [
            'order_item_id' => $item->id,
            'user_id' => $buyer->id,
            'product_id' => $offer->product_id,
            'store_id' => $offer->store_id,
            'rating' => 5,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_review.created', 'actor_id' => $buyer->id]);
    }

    public function test_item_must_be_delivered_before_review(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $item = $subOrder->items()->firstOrFail();
        $buyerToken = $this->accessToken($buyer);

        $this->api($buyerToken)
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 4])
            ->assertConflict();

        $this->api($this->accessToken($this->sellerOf($offer->store)))
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertCreated();

        $this->api($buyerToken)
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 4])
            ->assertConflict();

        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_only_the_buyer_can_review_once(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $item = $this->deliveredItem($buyer, $offer);

        $this->api($this->accessToken($this->userWithRole(RoleName::BUYER)))
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 1])
            ->assertForbidden();

        $this->asGuest()
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 1])
            ->assertUnauthorized();

        $buyerToken = $this->accessToken($buyer);
        $this->api($buyerToken)
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 4])
            ->assertCreated();

        $this->api($buyerToken)
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 2])
            ->assertConflict();

        $this->assertSame(4, ProductReview::query()->where('order_item_id', $item->id)->value('rating'));
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $item = $this->deliveredItem($buyer, $offer);
        $token = $this->accessToken($buyer);

        foreach ([0, 6, 'cinco'] as $rating) {
            $this->api($token)
                ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => $rating])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['rating']);
        }

        $this->api($token)
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'body']);

        $this->expectException(QueryException::class);
        ProductReview::factory()->create(['order_item_id' => $item->id, 'rating' => 7]);
    }

    public function test_seller_cannot_review_own_store_product(): void
    {
        $offer = $this->purchasableOffer();
        $owner = $this->sellerOf($offer->store);
        $item = $this->deliveredItem($owner, $offer);

        $this->api($this->accessToken($owner))
            ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => 5])
            ->assertForbidden()
            ->assertJsonPath('message', 'Vendedores não podem avaliar produtos da própria loja.');
    }

    public function test_public_reviews_list_with_summary_and_minimal_author_data(): void
    {
        $offer = $this->purchasableOffer();
        $product = $offer->product;

        foreach ([['Ana Paula Souza', 5], ['Bruno Lima', 4]] as [$name, $rating]) {
            $buyer = $this->buyerNamed($name);
            $item = $this->deliveredItem($buyer, $offer);
            $this->api($this->accessToken($buyer))
                ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => $rating])
                ->assertCreated();
        }

        $response = $this->asGuest()
            ->getJson("/api/v1/products/{$product->slug}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.average', 4.5)
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('data.0.author.name', 'Bruno')
            ->assertJsonPath('data.1.author.name', 'Ana');

        $this->assertArrayNotHasKey('email', $response->json('data.0.author'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));

        $this->asGuest()->getJson('/api/v1/products/nao-existe/reviews')->assertNotFound();
    }

    public function test_product_and_store_pages_show_aggregated_rating(): void
    {
        $offerA = $this->purchasableOffer();
        $productB = Product::factory()->active()->create(['store_id' => $offerA->store_id]);
        $offerB = Offer::factory()->active()->withStock(10, 0)->priced('20.00')->create([
            'store_id' => $offerA->store_id,
            'product_id' => $productB->id,
        ]);

        foreach ([[$offerA, 5], [$offerB, 2]] as [$offer, $rating]) {
            $buyer = $this->userWithRole(RoleName::BUYER);
            $item = $this->deliveredItem($buyer, $offer);
            $this->api($this->accessToken($buyer))
                ->postJson("/api/v1/order-items/{$item->id}/reviews", ['rating' => $rating])
                ->assertCreated();
        }

        $this->asGuest()
            ->getJson("/api/v1/products/{$offerA->product->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating.average', 5)
            ->assertJsonPath('data.rating.count', 1);

        $this->asGuest()
            ->getJson("/api/v1/stores/{$offerA->store->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating.average', 3.5)
            ->assertJsonPath('data.rating.count', 2);
    }

    public function test_product_without_reviews_has_empty_rating(): void
    {
        $offer = $this->purchasableOffer();

        $this->asGuest()
            ->getJson("/api/v1/products/{$offer->product->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating.average', null)
            ->assertJsonPath('data.rating.count', 0);
    }

    private function deliveredItem(User $buyer, Offer $offer): OrderItem
    {
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertCreated();
        $this->api($sellerToken)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/deliver")
            ->assertOk();

        return $subOrder->items()->firstOrFail();
    }

    private function buyerNamed(string $name): User
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $buyer->update(['name' => $name]);

        return $buyer->fresh();
    }
}
