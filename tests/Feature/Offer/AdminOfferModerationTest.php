<?php

declare(strict_types=1);

namespace Tests\Feature\Offer;

use App\Enums\OfferStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Notifications\OfferModeratedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

final class AdminOfferModerationTest extends TestCase
{
    use BuildsPaidOrders;
    use RefreshDatabase;

    private const REASON = 'Preço muito acima do praticado no mercado.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_admin_lists_offers_from_every_store_with_filters(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $active = $this->purchasableOffer();
        $sellerPaused = Offer::factory()->paused()->create();
        $locked = Offer::factory()->adminPaused()->create();

        $token = $this->accessToken($admin);

        $this->api($token)->getJson('/api/v1/admin/offers')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->api($token)->getJson('/api/v1/admin/offers?status=paused')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->api($token)->getJson('/api/v1/admin/offers?admin_paused=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $locked->id)
            ->assertJsonPath('data.0.admin_paused', true)
            ->assertJsonPath('data.0.admin_pause_reason', 'Preço incompatível com o mercado.');

        $this->api($token)->getJson('/api/v1/admin/offers?admin_paused=0')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->api($token)->getJson("/api/v1/admin/offers?store_id={$sellerPaused->store_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sellerPaused->id);

        $this->api($token)->getJson("/api/v1/admin/offers?product_id={$active->product_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.stock', 10);

        $this->api($token)->getJson('/api/v1/admin/offers?status=deleted&store_id=nope')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'store_id']);
    }

    public function test_admin_offer_routes_require_an_admin(): void
    {
        $offer = $this->purchasableOffer();
        $seller = $this->sellerOf($offer->store);
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->asGuest()->getJson('/api/v1/admin/offers')->assertUnauthorized();
        $this->asGuest()->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => self::REASON])->assertUnauthorized();

        foreach ([$seller, $buyer] as $user) {
            $token = $this->accessToken($user);

            $this->api($token)->getJson('/api/v1/admin/offers')->assertForbidden();
            $this->api($token)->getJson("/api/v1/admin/offers/{$offer->id}")->assertForbidden();
            $this->api($token)->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => self::REASON])->assertForbidden();
            $this->api($token)->postJson("/api/v1/admin/offers/{$offer->id}/resume")->assertForbidden();
        }

        $this->assertSame(OfferStatus::ACTIVE, $offer->fresh()->status);
    }

    public function test_admin_gets_404_for_unknown_or_deleted_offer(): void
    {
        $token = $this->accessToken($this->userWithRole(RoleName::ADMIN));
        $deleted = $this->purchasableOffer();
        $deleted->delete();

        $this->api($token)->getJson('/api/v1/admin/offers/'.Str::uuid())->assertNotFound();
        $this->api($token)->getJson("/api/v1/admin/offers/{$deleted->id}")->assertNotFound();
        $this->api($token)->postJson("/api/v1/admin/offers/{$deleted->id}/pause", ['reason' => self::REASON])->assertNotFound();
    }

    public function test_admin_pause_takes_offer_out_of_sale_audits_and_notifies_the_seller(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $offer = $this->purchasableOffer();
        $seller = $this->sellerOf($offer->store);
        $buyer = $this->userWithRole(RoleName::BUYER);
        $slug = $offer->product->slug;

        $this->asGuest()->getJson("/api/v1/products/{$slug}")->assertJsonPath('buy_box.id', $offer->id);

        $this->api($this->accessToken($admin))
            ->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::PAUSED->value)
            ->assertJsonPath('data.admin_paused', true)
            ->assertJsonPath('data.admin_pause_reason', self::REASON);

        $this->asGuest()->getJson("/api/v1/products/{$slug}")
            ->assertOk()
            ->assertJsonPath('buy_box', null)
            ->assertJsonCount(0, 'data.offers');

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/offers/{$offer->id}/reservations", ['quantity' => 1])
            ->assertConflict();

        $this->assertTrue(AuditLog::query()
            ->where('action', 'offer.admin_paused')
            ->where('auditable_id', $offer->id)
            ->where('actor_id', $admin->id)
            ->exists());

        Notification::assertSentTo(
            $seller,
            OfferModeratedNotification::class,
            fn (OfferModeratedNotification $n): bool => $n->type() === 'offer.admin_paused'
                && str_contains($n->toArray($seller)['message'], self::REASON),
        );
    }

    public function test_seller_sees_the_lock_and_cannot_resume_an_admin_paused_offer(): void
    {
        $offer = $this->purchasableOffer();
        $seller = $this->sellerOf($offer->store);

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => self::REASON])
            ->assertOk();

        $token = $this->accessToken($seller);

        $this->api($token)->getJson("/api/v1/seller/offers/{$offer->id}")
            ->assertOk()
            ->assertJsonPath('data.admin_paused', true)
            ->assertJsonPath('data.admin_pause_reason', self::REASON);

        $this->api($token)->postJson("/api/v1/seller/offers/{$offer->id}/resume")
            ->assertConflict()
            ->assertJsonPath('message', 'A oferta foi pausada pela moderação e só pode ser reativada por um administrador.');

        $this->assertSame(OfferStatus::PAUSED, $offer->fresh()->status);
    }

    public function test_admin_can_lock_an_offer_the_seller_had_already_paused(): void
    {
        $offer = Offer::factory()->paused()->create();
        $seller = $this->sellerOf($offer->store);

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.admin_paused', true);

        $this->api($this->accessToken($seller))
            ->postJson("/api/v1/seller/offers/{$offer->id}/resume")
            ->assertConflict();
    }

    public function test_admin_resume_lifts_the_lock_and_puts_the_offer_back_on_sale(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $offer = Offer::factory()->adminPaused()->withStock(5)->create();
        $seller = $this->sellerOf($offer->store);

        $this->api($this->accessToken($admin))
            ->postJson("/api/v1/admin/offers/{$offer->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::ACTIVE->value)
            ->assertJsonPath('data.admin_paused', false)
            ->assertJsonPath('data.admin_pause_reason', null);

        $this->asGuest()->getJson("/api/v1/products/{$offer->product->slug}")
            ->assertJsonPath('buy_box.id', $offer->id);

        $this->assertTrue(AuditLog::query()->where('action', 'offer.admin_resumed')->where('auditable_id', $offer->id)->exists());
        Notification::assertSentTo($seller, OfferModeratedNotification::class, fn (OfferModeratedNotification $n): bool => $n->type() === 'offer.admin_resumed');
    }

    public function test_moderation_transitions_that_do_not_apply_conflict(): void
    {
        $token = $this->accessToken($this->userWithRole(RoleName::ADMIN));
        $locked = Offer::factory()->adminPaused()->create();
        $sellerPaused = Offer::factory()->paused()->create();

        $this->api($token)
            ->postJson("/api/v1/admin/offers/{$locked->id}/pause", ['reason' => self::REASON])
            ->assertConflict()
            ->assertJsonPath('message', 'A oferta já está pausada pela moderação.');

        $this->api($token)
            ->postJson("/api/v1/admin/offers/{$sellerPaused->id}/resume")
            ->assertConflict()
            ->assertJsonPath('message', 'A oferta não está pausada pela moderação.');

        Notification::assertNothingSent();
    }

    public function test_admin_pause_requires_a_reason(): void
    {
        $offer = $this->purchasableOffer();
        $token = $this->accessToken($this->userWithRole(RoleName::ADMIN));

        $this->api($token)->postJson("/api/v1/admin/offers/{$offer->id}/pause")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->api($token)->postJson("/api/v1/admin/offers/{$offer->id}/pause", ['reason' => 'curto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(OfferStatus::ACTIVE, $offer->fresh()->status);
    }
}
