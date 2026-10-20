<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'google-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'http://127.0.0.1:8080/api/v1/auth/google/callback',
        ]);
    }

    public function test_redirect_returns_google_consent_url(): void
    {
        $url = $this->getJson('/api/v1/auth/google/redirect')
            ->assertOk()
            ->json('data.url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $url);
        $this->assertStringContainsString('client_id=google-client-id.apps.googleusercontent.com', $url);
        $this->assertStringContainsString('redirect_uri='.urlencode('http://127.0.0.1:8080/api/v1/auth/google/callback'), $url);
    }

    public function test_endpoints_answer_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->getJson('/api/v1/auth/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Login com Google não está configurado neste ambiente.');
        $this->getJson('/api/v1/auth/google/callback?code=abc')->assertStatus(503);
    }

    public function test_first_login_creates_verified_buyer_and_returns_tokens(): void
    {
        $this->fakeGoogleUser('google-123', 'Maria@Gmail.com', 'Maria Souza');

        $response = $this->getJson('/api/v1/auth/google/callback?code=valid-code')
            ->assertCreated()
            ->assertJsonPath('user.email', 'maria@gmail.com')
            ->assertJsonPath('user.name', 'Maria Souza')
            ->assertJsonStructure(['access_token', 'refresh_token']);

        $user = User::query()->where('email', 'maria@gmail.com')->firstOrFail();
        $this->assertSame('google-123', $user->google_id);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($user->hasRole(RoleName::BUYER));
        $this->assertNotNull($response->json('user.email_verified_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.google_register', 'actor_id' => $user->id]);

        $this->withToken($response->json('access_token'))->getJson('/api/v1/users/me')->assertOk();
    }

    public function test_returning_google_user_logs_in_without_creating_account(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $user->forceFill(['google_id' => 'google-123'])->save();
        $this->fakeGoogleUser('google-123', 'maria@gmail.com');

        $this->getJson('/api/v1/auth/google/callback?code=valid-code')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.google_login', 'actor_id' => $user->id]);
    }

    public function test_existing_account_with_same_email_is_linked_and_verified(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'maria@gmail.com']);
        $this->fakeGoogleUser('google-123', 'maria@gmail.com');

        $this->getJson('/api/v1/auth/google/callback?code=valid-code')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $user->refresh();
        $this->assertSame('google-123', $user->google_id);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.google_linked', 'actor_id' => $user->id]);
    }

    public function test_email_linked_to_another_google_account_is_a_conflict(): void
    {
        $user = User::factory()->create(['email' => 'maria@gmail.com']);
        $user->forceFill(['google_id' => 'google-original'])->save();
        $this->fakeGoogleUser('google-outro', 'maria@gmail.com');

        $this->getJson('/api/v1/auth/google/callback?code=valid-code')
            ->assertConflict()
            ->assertJsonPath('message', 'Este e-mail já está vinculado a outra conta Google.');

        $this->assertSame('google-original', $user->refresh()->google_id);
    }

    public function test_google_account_without_verified_email_is_rejected(): void
    {
        $this->fakeGoogleUser('google-123', 'maria@gmail.com', emailVerified: false);

        $this->getJson('/api/v1/auth/google/callback?code=valid-code')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A conta Google precisa ter um e-mail verificado.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_denied_consent_returns_401(): void
    {
        $this->getJson('/api/v1/auth/google/callback?error=access_denied')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Login com Google cancelado ou negado.');
    }

    public function test_invalid_code_returns_401(): void
    {
        Socialite::shouldReceive('driver->stateless->user')->andThrow(
            new ClientException('invalid_grant', new Request('POST', 'https://oauth2.googleapis.com/token'), new Response(400)),
        );

        $this->getJson('/api/v1/auth/google/callback?code=expired-code')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Não foi possível autenticar com o Google. Tente entrar novamente.');
    }

    public function test_callback_requires_code(): void
    {
        $this->getJson('/api/v1/auth/google/callback')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    private function fakeGoogleUser(string $id, string $email, string $name = 'Maria', bool $emailVerified = true): void
    {
        $googleUser = (new SocialiteUser)
            ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified, 'name' => $name])
            ->map(['id' => $id, 'name' => $name, 'email' => $email]);

        Socialite::shouldReceive('driver->stateless->user')->andReturn($googleUser);
    }
}
