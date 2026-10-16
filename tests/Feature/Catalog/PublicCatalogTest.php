<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\StoreStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_store_page_returns_active_store_and_active_products(): void
    {
        $store = Store::factory()->active()->create([
            'store_name' => 'Loja Pública',
            'slug' => 'loja-publica',
            'description' => 'Vitrine de testes.',
        ]);
        $category = Category::factory()->create();

        $active = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Produto Ativo',
            'slug' => 'produto-ativo',
        ]);
        Product::factory()->draft()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Produto Rascunho',
            'slug' => 'produto-rascunho',
        ]);

        $response = $this->getJson('/api/v1/stores/loja-publica');

        $response
            ->assertOk()
            ->assertJsonPath('data.slug', 'loja-publica')
            ->assertJsonPath('data.store_name', 'Loja Pública')
            ->assertJsonMissingPath('data.document')
            ->assertJsonMissingPath('data.rejection_reason')
            ->assertJsonPath('products.data.0.id', $active->id)
            ->assertJsonCount(1, 'products.data');
    }

    public function test_public_store_page_hides_inactive_stores(): void
    {
        Store::factory()->create([
            'slug' => 'loja-pendente',
            'status' => StoreStatus::PENDING,
        ]);

        $this->getJson('/api/v1/stores/loja-pendente')->assertNotFound();
    }

    public function test_public_product_search_filters_by_text_category_and_store(): void
    {
        $categoryPhones = Category::factory()->create(['slug' => 'celulares']);
        $categoryHome = Category::factory()->create(['slug' => 'casa']);
        $storeA = Store::factory()->active()->create(['slug' => 'loja-a']);
        $storeB = Store::factory()->active()->create(['slug' => 'loja-b']);

        $match = Product::factory()->active()->create([
            'store_id' => $storeA->id,
            'category_id' => $categoryPhones->id,
            'name' => 'Smartphone Alpha',
            'slug' => 'smartphone-alpha',
            'description' => 'Telefone com boa câmera',
        ]);
        Product::factory()->active()->create([
            'store_id' => $storeB->id,
            'category_id' => $categoryPhones->id,
            'name' => 'Smartphone Beta',
            'slug' => 'smartphone-beta',
        ]);
        Product::factory()->active()->create([
            'store_id' => $storeA->id,
            'category_id' => $categoryHome->id,
            'name' => 'Sofá Conforto',
            'slug' => 'sofa-conforto',
        ]);
        Product::factory()->paused()->create([
            'store_id' => $storeA->id,
            'category_id' => $categoryPhones->id,
            'name' => 'Smartphone Pausado',
            'slug' => 'smartphone-pausado',
        ]);

        $this->getJson('/api/v1/products?q=smartphone&category=celulares&store=loja-a')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.store.slug', 'loja-a');
    }

    public function test_public_product_search_supports_store_id_and_category_id(): void
    {
        $category = Category::factory()->create();
        $store = Store::factory()->active()->create();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Item Filtrado',
        ]);
        Product::factory()->active()->create([
            'name' => 'Outro Item',
        ]);

        $this->getJson("/api/v1/products?store_id={$store->id}&category_id={$category->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);
    }

    public function test_public_product_detail_requires_active_store_and_product(): void
    {
        $inactiveStore = Store::factory()->create([
            'status' => StoreStatus::SUSPENDED,
        ]);
        $product = Product::factory()->active()->create([
            'store_id' => $inactiveStore->id,
            'slug' => 'produto-loja-suspensa',
        ]);

        $this->getJson('/api/v1/products/produto-loja-suspensa')->assertNotFound();

        $activeStore = Store::factory()->active()->create();
        $product->update(['store_id' => $activeStore->id]);

        $this->getJson('/api/v1/products/produto-loja-suspensa')
            ->assertOk()
            ->assertJsonPath('data.slug', 'produto-loja-suspensa');
    }

    public function test_authenticated_store_me_still_works_after_public_show_route(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->active()->create([
            'user_id' => $owner->id,
            'slug' => 'minha-loja',
        ]);

        $token = $owner->createToken('access_token', ['access'], now()->addHour())->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/stores/me')
            ->assertOk()
            ->assertJsonPath('data.id', $store->id)
            ->assertJsonPath('data.document', $store->document);
    }
}
