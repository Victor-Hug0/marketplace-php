<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_request_a_store(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $response = $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/stores', $this->validStorePayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', StoreStatus::PENDING->value)
            ->assertJsonPath('data.slug', 'loja-teste');

        $this->assertDatabaseHas('stores', [
            'user_id' => $buyer->id,
            'status' => StoreStatus::PENDING->value,
        ]);

        $this->assertFalse($buyer->fresh()->hasRole(RoleName::SELLER));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'store.requested',
            'actor_id' => $buyer->id,
        ]);
    }

    public function test_store_request_normalizes_origin_cep(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/stores', $this->validStorePayload(['origin_cep' => '01310-100']))
            ->assertCreated()
            ->assertJsonPath('data.origin_cep', '01310100');

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/stores', $this->validStorePayload([
                'document' => '98765432100',
                'origin_cep' => '0131',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['origin_cep']);
    }

    public function test_user_cannot_request_a_second_store(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        Store::factory()->create(['user_id' => $buyer->id]);

        $response = $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/stores', $this->validStorePayload([
                'document' => '98765432100',
            ]));

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'O usuário já possui uma solicitação de loja.');
    }

    public function test_buyer_cannot_access_admin_store_routes(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/admin/stores')
            ->assertForbidden();
    }

    public function test_admin_can_approve_pending_store_and_assign_seller_role(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $admin = $this->userWithRole(RoleName::ADMIN);
        $store = Store::factory()->create(['user_id' => $buyer->id]);

        $response = $this->withToken($this->accessToken($admin))
            ->postJson("/api/v1/admin/stores/{$store->id}/approve");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', StoreStatus::ACTIVE->value);

        $this->assertTrue($buyer->fresh()->hasRole(RoleName::SELLER));
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'reviewed_by' => $admin->id,
            'status' => StoreStatus::ACTIVE->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'store.approved',
            'actor_id' => $admin->id,
            'auditable_id' => $store->id,
        ]);
    }

    public function test_rejection_requires_reason_and_does_not_assign_seller_role(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $admin = $this->userWithRole(RoleName::ADMIN);
        $store = Store::factory()->create(['user_id' => $buyer->id]);
        $token = $this->accessToken($admin);

        $this->withToken($token)
            ->postJson("/api/v1/admin/stores/{$store->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $response = $this->withToken($token)
            ->postJson("/api/v1/admin/stores/{$store->id}/reject", [
                'reason' => 'Documento comercial inconsistente.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', StoreStatus::REJECTED->value)
            ->assertJsonPath('data.rejection_reason', 'Documento comercial inconsistente.');

        $this->assertFalse($buyer->fresh()->hasRole(RoleName::SELLER));
    }

    public function test_admin_can_suspend_active_store_and_remove_seller_role(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $this->assignRole($buyer, RoleName::SELLER);
        $admin = $this->userWithRole(RoleName::ADMIN);
        $store = Store::factory()->active()->create(['user_id' => $buyer->id]);

        $response = $this->withToken($this->accessToken($admin))
            ->postJson("/api/v1/admin/stores/{$store->id}/suspend", [
                'reason' => 'Violação das regras comerciais.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', StoreStatus::SUSPENDED->value);

        $this->assertFalse($buyer->fresh()->hasRole(RoleName::SELLER));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'store.suspended',
            'auditable_id' => $store->id,
        ]);
    }

    public function test_store_owner_policy_denies_another_buyer(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $otherBuyer = $this->userWithRole(RoleName::BUYER);
        $store = Store::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($owner->can('view', $store));
        $this->assertFalse($otherBuyer->can('view', $store));
    }

    public function test_me_returns_roles_and_store_summary(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $store = Store::factory()->create(['user_id' => $buyer->id]);

        $response = $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/users/me');

        $response
            ->assertOk()
            ->assertJsonPath('user.roles.0', RoleName::BUYER->value)
            ->assertJsonPath('user.store.id', $store->id)
            ->assertJsonPath('user.store.status', StoreStatus::PENDING->value);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, $role);

        return $user;
    }

    private function assignRole(User $user, RoleName $role): void
    {
        $roleModel = Role::query()->firstOrCreate(['name' => $role]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);
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
     * @return array<string, string>
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'store_name' => 'Loja Teste',
            'description' => 'Loja para testes do marketplace.',
            'commercial_email' => 'loja@teste.com',
            'commercial_phone' => '11999999999',
            'document_type' => 'cpf',
            'document' => '12345678901',
        ], $overrides);
    }
}
