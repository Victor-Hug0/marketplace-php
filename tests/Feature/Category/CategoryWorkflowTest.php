<?php

declare(strict_types=1);

namespace Tests\Feature\Category;

use App\Enums\CategoryAttributeDataType;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_lists_only_active_categories_as_tree(): void
    {
        $root = Category::factory()->create([
            'name' => 'Eletrônicos',
            'slug' => 'eletronicos',
            'position' => 1,
        ]);
        $child = Category::factory()->childOf($root)->create([
            'name' => 'Celulares',
            'slug' => 'celulares',
            'position' => 1,
        ]);
        Category::factory()->inactive()->create([
            'name' => 'Inativa',
            'slug' => 'inativa',
        ]);
        Category::factory()->childOf($root)->inactive()->create([
            'name' => 'Filho Inativo',
            'slug' => 'filho-inativo',
        ]);

        $response = $this->getJson('/api/v1/categories');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'eletronicos')
            ->assertJsonPath('data.0.children.0.id', $child->id)
            ->assertJsonMissing(['slug' => 'inativa']);
    }

    public function test_public_show_returns_category_with_attributes(): void
    {
        $category = Category::factory()->create([
            'name' => 'Celulares',
            'slug' => 'celulares',
        ]);
        CategoryAttribute::factory()->enum(['preto', 'branco'])->create([
            'category_id' => $category->id,
            'code' => 'color',
            'label' => 'Cor',
            'position' => 1,
        ]);

        $this->getJson('/api/v1/categories/celulares')
            ->assertOk()
            ->assertJsonPath('data.slug', 'celulares')
            ->assertJsonPath('data.attributes.0.code', 'color')
            ->assertJsonPath('data.attributes.0.options.0', 'preto');
    }

    public function test_public_show_hides_inactive_category(): void
    {
        Category::factory()->inactive()->create(['slug' => 'oculta']);

        $this->getJson('/api/v1/categories/oculta')->assertNotFound();
    }

    public function test_admin_can_create_root_and_child_categories(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $token = $this->accessToken($admin);

        $rootResponse = $this->withToken($token)
            ->postJson('/api/v1/admin/categories', [
                'name' => 'Eletrônicos',
            ]);

        $rootResponse
            ->assertCreated()
            ->assertJsonPath('data.slug', 'eletronicos')
            ->assertJsonPath('data.is_active', true);

        $rootId = $rootResponse->json('data.id');

        $childResponse = $this->withToken($token)
            ->postJson('/api/v1/admin/categories', [
                'name' => 'Celulares',
                'parent_id' => $rootId,
            ]);

        $childResponse
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $rootId)
            ->assertJsonPath('data.slug', 'celulares');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'category.created',
            'actor_id' => $admin->id,
            'auditable_id' => $rootId,
        ]);
    }

    public function test_admin_cannot_create_fourth_level_category(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $level1 = Category::factory()->create();
        $level2 = Category::factory()->childOf($level1)->create();
        $level3 = Category::factory()->childOf($level2)->create();

        $this->withToken($this->accessToken($admin))
            ->postJson('/api/v1/admin/categories', [
                'name' => 'Nível 4',
                'parent_id' => $level3->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_admin_cannot_create_cycle_when_moving_parent(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();

        $this->withToken($this->accessToken($admin))
            ->putJson("/api/v1/admin/categories/{$parent->id}", [
                'parent_id' => $child->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_admin_cannot_delete_category_with_children(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $parent = Category::factory()->create();
        Category::factory()->childOf($parent)->create();

        $this->withToken($this->accessToken($admin))
            ->deleteJson("/api/v1/admin/categories/{$parent->id}")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Não é possível remover uma categoria que possui subcategorias.',
            );

        $this->assertDatabaseHas('categories', [
            'id' => $parent->id,
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_soft_delete_leaf_category(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $category = Category::factory()->create();

        $this->withToken($this->accessToken($admin))
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'category.deleted',
            'auditable_id' => $category->id,
        ]);
    }

    public function test_buyer_cannot_access_admin_category_routes(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/admin/categories')
            ->assertForbidden();

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/admin/categories', ['name' => 'Hack'])
            ->assertForbidden();
    }

    public function test_admin_can_manage_category_attributes(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $category = Category::factory()->create();
        $token = $this->accessToken($admin);

        $create = $this->withToken($token)
            ->postJson("/api/v1/admin/categories/{$category->id}/attributes", [
                'code' => 'color',
                'label' => 'Cor',
                'data_type' => CategoryAttributeDataType::ENUM->value,
                'options' => ['preto', 'branco'],
                'is_required' => true,
            ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.code', 'color')
            ->assertJsonPath('data.data_type', 'enum');

        $attributeId = $create->json('data.id');

        $this->withToken($token)
            ->putJson("/api/v1/admin/categories/{$category->id}/attributes/{$attributeId}", [
                'label' => 'Cor do produto',
                'options' => ['preto', 'branco', 'azul'],
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Cor do produto')
            ->assertJsonPath('data.options.2', 'azul');

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/categories/{$category->id}/attributes/{$attributeId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('category_attributes', [
            'id' => $attributeId,
        ]);
    }

    public function test_enum_attribute_requires_options(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $category = Category::factory()->create();

        $this->withToken($this->accessToken($admin))
            ->postJson("/api/v1/admin/categories/{$category->id}/attributes", [
                'code' => 'color',
                'label' => 'Cor',
                'data_type' => CategoryAttributeDataType::ENUM->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options']);
    }

    public function test_attribute_code_must_be_unique_per_category(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        $category = Category::factory()->create();
        CategoryAttribute::factory()->create([
            'category_id' => $category->id,
            'code' => 'color',
        ]);

        $this->withToken($this->accessToken($admin))
            ->postJson("/api/v1/admin/categories/{$category->id}/attributes", [
                'code' => 'color',
                'label' => 'Cor',
                'data_type' => CategoryAttributeDataType::STRING->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
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
}
