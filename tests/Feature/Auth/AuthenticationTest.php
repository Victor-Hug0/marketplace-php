<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $payload = $this->validRegistrationPayload();

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Usuário registrado com sucesso. Enviamos um link de confirmação para o seu e-mail.')
            ->assertJsonPath('user.email', $payload['email'])
            ->assertJsonPath('user.cpf', $payload['cpf'])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'cpf' => $payload['cpf'],
        ]);

        $user = User::query()->where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertTrue($user->hasRole(RoleName::BUYER));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.register',
            'actor_id' => $user->id,
        ]);
    }

    public function test_register_fails_with_invalid_payload(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '',
            'email' => 'nao-e-email',
            'password' => '123',
            'password_confirmation' => '456',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Os dados enviados são inválidos.')
            ->assertJsonStructure([
                'message',
                'timestamp',
                'errors',
            ]);
    }

    public function test_register_fails_with_duplicated_email(): void
    {
        User::factory()->create([
            'email' => 'usuario@teste.com',
            'cpf' => '11111111111',
        ]);

        $response = $this->postJson(
            '/api/v1/auth/register',
            $this->validRegistrationPayload(['cpf' => '22222222222'])
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_and_receive_token_pair(): void
    {
        $user = User::factory()->create([
            'email' => 'usuario@teste.com',
            'password' => 'Password1!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@teste.com',
            'password' => 'Password1!',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login realizado com sucesso')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email'],
                'access_token',
                'refresh_token',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'actor_id' => $user->id,
        ]);

        $this->assertSame(
            2,
            PersonalAccessToken::query()->where('tokenable_id', $user->id)->count()
        );
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'usuario@teste.com',
            'password' => 'Password1!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@teste.com',
            'password' => 'WrongPassword1!',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Credenciais inválidas');
    }

    public function test_login_is_rate_limited_after_too_many_attempts(): void
    {
        User::factory()->create([
            'email' => 'usuario@teste.com',
            'password' => 'Password1!',
        ]);

        $key = 'login:127.0.0.1';

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit($key, 60);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@teste.com',
            'password' => 'WrongPassword1!',
        ]);

        $response
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas tentativas de login. Por favor, tente novamente mais tarde.')
            ->assertHeader('Retry-After');
    }

    public function test_authenticated_user_can_access_me(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;

        $response = $this->withToken($accessToken)->getJson('/api/v1/users/me');

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/users/me');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Usuário não autenticado.');
    }

    public function test_refresh_token_rotates_token_pair(): void
    {
        $user = User::factory()->create();
        $refreshToken = $user->createToken('refresh_token', ['refresh'], now()->addDays(30))->plainTextToken;
        $oldRefreshId = $user->tokens()->where('name', 'refresh_token')->value('id');

        $response = $this->withToken($refreshToken)->postJson('/api/v1/auth/refresh');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Token de atualização realizado com sucesso')
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldRefreshId,
        ]);

        $this->assertSame(
            2,
            PersonalAccessToken::query()->where('tokenable_id', $user->id)->count()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.refresh',
            'actor_id' => $user->id,
        ]);
    }

    public function test_refresh_rejects_access_token(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;

        $response = $this->withToken($accessToken)->postJson('/api/v1/auth/refresh');

        $response->assertForbidden();
    }

    public function test_logout_revokes_token_pair(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
        $user->createToken('refresh_token', ['refresh'], now()->addDays(30));

        $response = $this->withToken($accessToken)->postJson('/api/v1/auth/logout');

        $response->assertNoContent();

        $this->assertSame(
            0,
            PersonalAccessToken::query()->where('tokenable_id', $user->id)->count()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.logout',
            'actor_id' => $user->id,
        ]);

        $this->forgetAuthGuards();

        $this->withToken($accessToken)
            ->getJson('/api/v1/users/me')
            ->assertUnauthorized();
    }

    public function test_refresh_after_logout_is_unauthorized(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
        $refreshToken = $user->createToken('refresh_token', ['refresh'], now()->addDays(30))->plainTextToken;

        $this->withToken($accessToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->forgetAuthGuards();

        $this->withToken($refreshToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Usuário não autenticado.');
    }
}
