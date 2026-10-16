<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_product_with_uuid_and_draft_status(): void
    {
        $product = Product::factory()->create();

        $this->assertNotNull($product->id);
        $this->assertTrue(Str::isUuid($product->id));
        $this->assertSame(ProductStatus::DRAFT, $product->status);
        $this->assertFalse($product->isActive());
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => ProductStatus::DRAFT->value,
        ]);
    }

    public function test_soft_delete_keeps_product_row_and_images(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->cover()->create([
            'product_id' => $product->id,
        ]);

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_store_slug_combination_must_be_unique(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->create();

        Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'slug' => 'notebook-x',
        ]);

        $this->expectException(QueryException::class);

        Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'slug' => 'notebook-x',
        ]);
    }

    public function test_store_and_product_image_relations_load(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
        ]);
        $cover = ProductImage::factory()->cover()->create([
            'product_id' => $product->id,
            'path' => 'products/demo/cover.jpg',
        ]);
        ProductImage::factory()->create([
            'product_id' => $product->id,
            'position' => 2,
            'path' => 'products/demo/2.jpg',
        ]);

        $store->load('products');
        $product->load('images');

        $this->assertTrue($store->products->contains('id', $product->id));
        $this->assertCount(2, $product->images);
        $this->assertSame($cover->id, $product->coverImage()?->id);
        $this->assertTrue($product->isActive());
        $this->assertSame('products/demo/cover.jpg', $product->coverImage()?->path);
    }

    public function test_hard_delete_cascades_product_images(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->create([
            'product_id' => $product->id,
        ]);

        $product->forceDelete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }
}
