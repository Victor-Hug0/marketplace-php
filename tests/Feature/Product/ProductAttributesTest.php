<?php

namespace Tests\Feature\Product;

use App\Enums\CategoryAttributeDataType;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAttributesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create(['is_active' => true]);
        $this->attribute('voltage', CategoryAttributeDataType::ENUM, position: 1, required: true, options: ['110V', '220V', 'Bivolt']);
        $this->attribute('power_w', CategoryAttributeDataType::INTEGER, position: 2);
        $this->attribute('capacity_l', CategoryAttributeDataType::DECIMAL, position: 3);
        $this->attribute('has_timer', CategoryAttributeDataType::BOOLEAN, position: 4);
        $this->attribute('model', CategoryAttributeDataType::STRING, position: 5, filterable: false);
    }

    public function test_seller_creates_product_with_typed_attribute_values(): void
    {
        [$seller] = $this->sellerWithActiveStore();

        $this->withToken($this->accessToken($seller))
            ->postJson('/api/v1/seller/products', [
                'category_id' => $this->category->id,
                'name' => 'Air Fryer Demo',
                'attributes' => [
                    'model' => 'AF-200',
                    'has_timer' => 'true',
                    'capacity_l' => 4.5,
                    'power_w' => '1500',
                    'voltage' => 'bivolt',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attributes', [
                ['code' => 'voltage', 'label' => 'Voltage', 'data_type' => 'enum', 'value' => 'Bivolt'],
                ['code' => 'power_w', 'label' => 'Power w', 'data_type' => 'integer', 'value' => 1500],
                ['code' => 'capacity_l', 'label' => 'Capacity l', 'data_type' => 'decimal', 'value' => 4.5],
                ['code' => 'has_timer', 'label' => 'Has timer', 'data_type' => 'boolean', 'value' => true],
                ['code' => 'model', 'label' => 'Model', 'data_type' => 'string', 'value' => 'AF-200'],
            ]);

        $this->assertDatabaseCount('product_attribute_values', 5);
    }

    public function test_invalid_values_and_unknown_codes_are_rejected(): void
    {
        [$seller] = $this->sellerWithActiveStore();

        $this->withToken($this->accessToken($seller))
            ->postJson('/api/v1/seller/products', [
                'category_id' => $this->category->id,
                'name' => 'Air Fryer Demo',
                'attributes' => [
                    'voltage' => '380V',
                    'power_w' => '15.5',
                    'has_timer' => 'talvez',
                    'color' => 'preto',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attributes.voltage', 'attributes.power_w', 'attributes.has_timer', 'attributes.color']);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_attribute_values', 0);
    }

    public function test_update_merges_values_and_null_removes_one(): void
    {
        [$seller, $store] = $this->sellerWithActiveStore();
        $product = $this->productWith($store, ['voltage' => '220V', 'power_w' => 1000, 'model' => 'X1']);

        $this->withToken($this->accessToken($seller))
            ->putJson("/api/v1/seller/products/{$product->id}", [
                'attributes' => ['power_w' => 1200, 'model' => null],
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.0.value', '220V')
            ->assertJsonPath('data.attributes.1.value', 1200)
            ->assertJsonCount(2, 'data.attributes');
    }

    public function test_changing_category_drops_values_of_the_old_category(): void
    {
        [$seller, $store] = $this->sellerWithActiveStore();
        $product = $this->productWith($store, ['voltage' => '220V']);
        $otherCategory = Category::factory()->create(['is_active' => true]);

        $this->withToken($this->accessToken($seller))
            ->putJson("/api/v1/seller/products/{$product->id}", ['category_id' => $otherCategory->id])
            ->assertOk()
            ->assertJsonPath('data.attributes', []);

        $this->assertDatabaseCount('product_attribute_values', 0);
    }

    public function test_submit_requires_required_attributes(): void
    {
        [$seller, $store] = $this->sellerWithActiveStore();
        $product = $this->productWith($store, ['power_w' => 1000]);
        $token = $this->accessToken($seller);

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attributes.voltage' => 'O atributo Voltage é obrigatório para enviar o produto à moderação.']);

        app(ProductAttributeService::class)->apply($product, ['voltage' => '110V']);

        $this->withToken($token)
            ->postJson("/api/v1/seller/products/{$product->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', ProductStatus::PENDING_MODERATION->value);
    }

    public function test_public_search_filters_by_attribute_values(): void
    {
        $store = Store::factory()->active()->create();
        $bivolt = $this->productWith($store, ['voltage' => 'Bivolt', 'power_w' => 1500, 'has_timer' => true], ProductStatus::ACTIVE);
        $v220 = $this->productWith($store, ['voltage' => '220V', 'power_w' => 1000, 'has_timer' => false], ProductStatus::ACTIVE);
        $this->productWith($store, ['voltage' => '110V', 'power_w' => 1500, 'model' => 'Z9'], ProductStatus::ACTIVE);

        $this->assertSearch(['voltage' => 'bivolt'], [$bivolt->id]);
        $this->assertSearch(['voltage' => 'Bivolt,220V'], [$bivolt->id, $v220->id]);
        $this->assertSearch(['voltage' => 'Bivolt,220V', 'power_w' => '1000'], [$v220->id]);
        $this->assertSearch(['has_timer' => 'true'], [$bivolt->id]);
        $this->assertSearch(['model' => 'Z9'], []);
    }

    public function test_detail_shows_attributes(): void
    {
        $store = Store::factory()->active()->create();
        $product = $this->productWith($store, ['voltage' => '220V'], ProductStatus::ACTIVE);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.attributes.0.code', 'voltage')
            ->assertJsonPath('data.attributes.0.value', '220V');
    }

    public function test_invalid_attribute_filter_key_is_rejected(): void
    {
        $this->getJson('/api/v1/products?attr[cor%20preta]=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attr']);
    }

    /**
     * @param  array<string, string>  $attr
     * @param  list<string>  $expectedIds
     */
    private function assertSearch(array $attr, array $expectedIds): void
    {
        $ids = collect($this->getJson('/api/v1/products?'.http_build_query(['attr' => $attr]))->assertOk()->json('data'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect($expectedIds)->sort()->values()->all(), $ids, 'attr='.json_encode($attr));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function productWith(Store $store, array $values, ProductStatus $status = ProductStatus::DRAFT): Product
    {
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'category_id' => $this->category->id,
            'status' => $status,
        ]);

        app(ProductAttributeService::class)->apply($product, $values);

        return $product;
    }

    /**
     * @param  list<string>|null  $options
     */
    private function attribute(string $code, CategoryAttributeDataType $type, int $position, bool $required = false, bool $filterable = true, ?array $options = null): void
    {
        CategoryAttribute::factory()->create([
            'category_id' => $this->category->id,
            'code' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'data_type' => $type,
            'options' => $options,
            'is_required' => $required,
            'is_filterable' => $filterable,
            'position' => $position,
        ]);
    }

    /**
     * @return array{User, Store}
     */
    private function sellerWithActiveStore(): array
    {
        $user = User::factory()->create();
        foreach ([RoleName::BUYER, RoleName::SELLER] as $role) {
            $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => $role])->id]);
        }

        return [$user->fresh(), Store::factory()->active()->create(['user_id' => $user->id])];
    }

    private function accessToken(User $user): string
    {
        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
