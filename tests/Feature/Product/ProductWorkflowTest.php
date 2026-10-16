<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_with_active_store_can_create_draft_and_add_image(): void
    {
        Storage::fake('public');

        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $token = $this->accessToken($seller);

        $create = $this->withToken($token)
            ->postJson('/api/v1/seller/products', [
                'category_id' => $category->id,
                'name' => 'Notebook Gamer X',
                'description' => 'Produto em rascunho.',
            ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.status', ProductStatus::DRAFT->value)
            ->assertJsonPath('data.slug', 'notebook-gamer-x');

        $productId = $create->json('data.id');

        $image = $this->withToken($token)
            ->post("/api/v1/seller/products/{$productId}/images", [
                'image' => UploadedFile::fake()->create('capa.jpg', 100, 'image/jpeg'),
                'alt' => 'Capa do produto',
            ], ['Accept' => 'application/json']);

        $image
            ->assertCreated()
            ->assertJsonPath('data.position', 0)
            ->assertJsonPath('data.alt', 'Capa do produto');

        $this->assertDatabaseHas('product_images', [
            'product_id' => $productId,
            'position' => 0,
            'alt' => 'Capa do produto',
        ]);

        $storedPath = Product::query()->findOrFail($productId)->images()->firstOrFail()->path;
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_buyer_without_seller_role_cannot_access_seller_product_routes(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $category = Category::factory()->create();

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/seller/products', [
                'category_id' => $category->id,
                'name' => 'Produto Negado',
            ])
            ->assertForbidden();
    }

    public function test_seller_cannot_edit_another_sellers_product(): void
    {
        [$sellerA] = $this->sellerWithActiveStore();
        [$sellerB, $storeB, $category] = $this->sellerWithActiveStore([
            'email' => 'seller-b@teste.com',
            'cpf' => '98765432100',
            'document' => '11222333000181',
        ]);

        $product = Product::factory()
            ->belongingToStore($storeB)
            ->inCategory($category)
            ->draft()
            ->create();

        $this->withToken($this->accessToken($sellerA))
            ->putJson("/api/v1/seller/products/{$product->id}", [
                'name' => 'Tentativa de sequestro',
            ])
            ->assertForbidden();
    }

    public function test_submit_moves_draft_to_pending_moderation_and_invalid_transition_conflicts(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->draft()
            ->create();

        $token = $this->accessToken($seller);

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::PENDING_MODERATION->value);

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/submit")
            ->assertConflict();
    }

    public function test_admin_approve_makes_product_public_and_reject_requires_reason(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $admin = $this->userWithRole(RoleName::ADMIN);
        $adminToken = $this->accessToken($admin);

        $product = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->pendingModeration()
            ->create(['name' => 'Produto Aprovado', 'slug' => 'produto-aprovado']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/products/{$product->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::ACTIVE->value);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'produto-aprovado');

        $this->getJson('/api/v1/products/produto-aprovado')
            ->assertOk()
            ->assertJsonPath('data.name', 'Produto Aprovado');

        $other = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->pendingModeration()
            ->create();

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/products/{$other->id}/reject", [])
            ->assertUnprocessable();

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/products/{$other->id}/reject", [
                'reason' => 'Conteúdo fora da política da plataforma.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::REJECTED->value);

        $this->getJson('/api/v1/products/'.$other->slug)
            ->assertNotFound();
    }

    public function test_non_active_products_are_hidden_from_public_listing(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();

        Product::factory()->belongingToStore($store)->inCategory($category)->draft()->create();
        Product::factory()->belongingToStore($store)->inCategory($category)->rejected()->create();
        Product::factory()->belongingToStore($store)->inCategory($category)->paused()->create();
        Product::factory()->belongingToStore($store)->inCategory($category)->active()->create([
            'slug' => 'unico-ativo',
            'name' => 'Unico Ativo',
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'unico-ativo');
    }

    public function test_seller_cannot_edit_content_when_product_is_active(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->active()
            ->create();

        $this->withToken($this->accessToken($seller))
            ->putJson("/api/v1/seller/products/{$product->id}", [
                'name' => 'Novo Nome',
            ])
            ->assertConflict();
    }

    public function test_seller_can_pause_and_resume_active_product(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->active()
            ->create(['slug' => 'produto-pausavel']);

        $token = $this->accessToken($seller);

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::PAUSED->value);

        $this->getJson('/api/v1/products/produto-pausavel')->assertNotFound();

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::ACTIVE->value);

        $this->getJson('/api/v1/products/produto-pausavel')->assertOk();
    }

    public function test_admin_cannot_delete_category_with_products(): void
    {
        $admin = $this->userWithRole(RoleName::ADMIN);
        [, $store, $category] = $this->sellerWithActiveStore();

        Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->draft()
            ->create();

        $this->withToken($this->accessToken($admin))
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Não é possível remover uma categoria que possui produtos.');
    }

    public function test_buyer_cannot_access_admin_product_routes(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $product = Product::factory()->pendingModeration()->create();

        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/admin/products')
            ->assertForbidden();

        $this->withToken($this->accessToken($buyer))
            ->postJson("/api/v1/admin/products/{$product->id}/approve")
            ->assertForbidden();
    }

    public function test_rejected_product_can_be_resubmitted_after_edit(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()
            ->belongingToStore($store)
            ->inCategory($category)
            ->rejected()
            ->create();

        $token = $this->accessToken($seller);

        $this->withToken($token)
            ->putJson("/api/v1/seller/products/{$product->id}", [
                'name' => 'Produto Corrigido',
                'description' => 'Versão revisada.',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Produto Corrigido');

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::PENDING_MODERATION->value);
    }

    /**
     * @param  array{email?: string, cpf?: string, document?: string}  $overrides
     * @return array{0: User, 1: Store, 2: Category}
     */
    private function sellerWithActiveStore(array $overrides = []): array
    {
        $user = User::factory()->create([
            'email' => $overrides['email'] ?? fake()->unique()->safeEmail(),
            'cpf' => $overrides['cpf'] ?? fake()->unique()->numerify('###########'),
        ]);
        $this->assignRole($user, RoleName::BUYER);
        $this->assignRole($user, RoleName::SELLER);

        $store = Store::factory()->active()->create([
            'user_id' => $user->id,
            'document' => $overrides['document'] ?? fake()->unique()->numerify('##############'),
            'status' => StoreStatus::ACTIVE,
        ]);

        $category = Category::factory()->create();

        return [$user->fresh(), $store, $category];
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
}
