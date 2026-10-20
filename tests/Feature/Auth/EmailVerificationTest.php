<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\Address;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_queues_verification_email_and_its_link_verifies_the_user(): void
    {
        Notification::fake();
        $payload = $this->validRegistrationPayload();

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.email_verified_at', null);

        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $url = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return $notification instanceof ShouldQueue;
        });
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('message', 'E-mail confirmado.');

        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified', 'actor_id' => $user->id]);

        $this->getJson($url)->assertOk();
    }

    public function test_verification_link_with_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user).'tampered')
            ->assertForbidden()
            ->assertJsonPath('message', 'Link de verificação inválido ou expirado.');

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);

        $this->travel(61)->minutes();

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_signed_link_with_hash_of_another_email_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('outro@exemplo.test'),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_can_request_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verified_user_cannot_request_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertConflict()
            ->assertJsonPath('message', 'O e-mail já foi confirmado.');

        Notification::assertNothingSent();
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    }

    public function test_unverified_user_cannot_checkout(): void
    {
        $user = User::factory()->unverified()->create();
        $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => RoleName::BUYER])->id]);
        $address = Address::factory()->belongingToUser($user)->create();

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/checkout', ['address_id' => $address->id], ['Idempotency-Key' => 'checkout-unverified-001'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Confirme seu e-mail para continuar. Peça um novo link em POST /api/v1/auth/email/verification-notification.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unverified_user_cannot_request_a_store(): void
    {
        $user = User::factory()->unverified()->create();
        $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => RoleName::BUYER])->id]);

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/stores', [
                'store_name' => 'Loja Sem Confirmação',
                'document_type' => 'cnpj',
                'document' => '11222333000181',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Confirme seu e-mail para continuar. Peça um novo link em POST /api/v1/auth/email/verification-notification.');

        $this->assertDatabaseCount('stores', 0);
    }

    public function test_unverified_seller_cannot_request_a_payout(): void
    {
        $seller = User::factory()->unverified()->create();
        foreach ([RoleName::BUYER, RoleName::SELLER] as $role) {
            $seller->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => $role])->id]);
        }
        Store::factory()->active()->create(['user_id' => $seller->id]);

        $this->withToken($this->accessToken($seller))
            ->postJson('/api/v1/seller/payouts', ['amount_cents' => 1000], ['Idempotency-Key' => 'payout-unverified-001'])
            ->assertForbidden();

        $this->assertDatabaseCount('payouts', 0);
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }

    private function accessToken(User $user): string
    {
        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
