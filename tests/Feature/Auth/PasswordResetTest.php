<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.';

    public function test_forgot_password_sends_queued_reset_link_to_frontend(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.exemplo.test']);
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC_MESSAGE);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return $notification instanceof ShouldQueue
                && str_starts_with($url, 'https://app.exemplo.test/redefinir-senha?token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_requested', 'actor_id' => $user->id]);
    }

    public function test_reset_email_job_is_encrypted_on_the_queue(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof ResetPasswordNotification && $job->shouldBeEncrypted,
        );
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ninguem@exemplo.test'])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC_MESSAGE);

        Notification::assertNothingSent();
    }

    public function test_second_request_within_a_minute_does_not_send_another_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC_MESSAGE);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_reset_changes_password_revokes_tokens_and_burns_the_token(): void
    {
        $user = User::factory()->unverified()->create();
        $user->createToken('access_token', ['access'], now()->addHour());
        $user->createToken('refresh_token', ['refresh'], now()->addDays(30));
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NovaSenha@123',
            'password_confirmation' => 'NovaSenha@123',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Senha redefinida. Entre novamente com a nova senha.');

        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha@123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'actor_id' => $user->id]);

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NovaSenha@123'])->assertOk();
    }

    public function test_reset_rejects_invalid_token(): void
    {
        $user = User::factory()->create();
        Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'NovaSenha@123',
            'password_confirmation' => 'NovaSenha@123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'O link de redefinição é inválido ou expirou. Peça um novo.');

        $this->assertTrue(Hash::check('Password1!', $user->refresh()->password));
    }

    public function test_reset_rejects_expired_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NovaSenha@123',
            'password_confirmation' => 'NovaSenha@123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function test_reset_validates_password_strength_and_confirmation(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'fraca',
            'password_confirmation' => 'outra',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => "pessoa{$attempt}@exemplo.test"])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pessoa6@exemplo.test'])->assertTooManyRequests();
    }
}
