<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Enums\RoleName;
use App\Models\Address;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AddressWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_address_and_first_becomes_default(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);

        $response = $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/addresses', $this->validAddressPayload([
                'cep' => '01310-100',
                'is_default' => false,
            ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.cep', '01310-100')
            ->assertJsonPath('data.state', 'SP')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'cep' => '01310100',
            'is_default' => true,
        ]);
    }

    public function test_setting_default_clears_previous_default(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($user);

        $first = Address::factory()->belongingToUser($user)->default()->create([
            'cep' => '01310100',
        ]);

        $second = $this->withToken($token)
            ->postJson('/api/v1/addresses', $this->validAddressPayload([
                'label' => 'Trabalho',
                'cep' => '22041080',
                'is_default' => true,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(Address::query()->findOrFail($second)->is_default);
    }

    public function test_user_can_list_only_own_addresses(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $other = $this->userWithRole(RoleName::BUYER);

        Address::factory()->belongingToUser($owner)->create(['label' => 'Casa']);
        Address::factory()->belongingToUser($other)->create(['label' => 'Outro']);

        $this->withToken($this->accessToken($owner))
            ->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Casa');
    }

    public function test_user_cannot_view_or_update_another_users_address(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $intruder = $this->userWithRole(RoleName::BUYER);
        $address = Address::factory()->belongingToUser($owner)->create();

        $token = $this->accessToken($intruder);

        $this->withToken($token)
            ->getJson("/api/v1/addresses/{$address->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->putJson("/api/v1/addresses/{$address->id}", [
                'recipient_name' => 'Hack',
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson("/api/v1/addresses/{$address->id}")
            ->assertNotFound();
    }

    public function test_invalid_cep_is_rejected(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/addresses', $this->validAddressPayload([
                'cep' => '123',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cep']);
    }

    public function test_invalid_state_is_rejected(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($user))
            ->postJson('/api/v1/addresses', $this->validAddressPayload([
                'state' => 'XX',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state']);
    }

    public function test_guest_cannot_access_addresses(): void
    {
        $this->getJson('/api/v1/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/addresses', $this->validAddressPayload())->assertUnauthorized();
    }

    public function test_make_default_endpoint_switches_default_address(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($user);

        $first = Address::factory()->belongingToUser($user)->default()->create();
        $second = Address::factory()->belongingToUser($user)->create([
            'is_default' => false,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/addresses/{$second->id}/default")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_deleting_default_promotes_another_address(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($user);

        $default = Address::factory()->belongingToUser($user)->default()->create([
            'created_at' => now()->subHour(),
        ]);
        $other = Address::factory()->belongingToUser($user)->create([
            'is_default' => false,
            'created_at' => now(),
        ]);

        $this->withToken($token)
            ->deleteJson("/api/v1/addresses/{$default->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('addresses', ['id' => $default->id]);
        $this->assertTrue($other->fresh()->is_default);
    }

    public function test_cannot_unset_default_flag_directly(): void
    {
        $user = $this->userWithRole(RoleName::BUYER);
        $address = Address::factory()->belongingToUser($user)->default()->create();

        $this->withToken($this->accessToken($user))
            ->putJson("/api/v1/addresses/{$address->id}", [
                'is_default' => false,
            ])
            ->assertConflict();
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $roleModel = Role::query()->firstOrCreate(['name' => $role]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);

        return $user;
    }

    private function accessToken(User $user): string
    {
        return $user->createToken(
            'access_token',
            ['access'],
            now()->addHour(),
        )->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAddressPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Casa',
            'recipient_name' => 'Victor Barreto',
            'phone' => '11999998888',
            'cep' => '01310100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Apto 12',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ], $overrides);
    }
}
