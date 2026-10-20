<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductAttributeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $sellerRole = Role::query()->firstOrCreate(['name' => RoleName::SELLER]);
        $buyerRole = Role::query()->firstOrCreate(['name' => RoleName::BUYER]);

        $seller = User::query()->firstOrCreate(
            ['email' => 'seller.demo@marketplace.test'],
            [
                'name' => 'Seller Demo',
                'password' => Hash::make('Password1!'),
                'cpf' => '52998224725',
                'phone_number' => '11988887777',
            ],
        );

        if (! $seller->hasVerifiedEmail()) {
            $seller->markEmailAsVerified();
        }

        $seller->roles()->syncWithoutDetaching([$buyerRole->id, $sellerRole->id]);

        $store = Store::query()->firstOrCreate(
            ['user_id' => $seller->id],
            [
                'store_name' => 'Loja Demo',
                'slug' => 'loja-demo',
                'description' => 'Loja de demonstração do marketplace.',
                'commercial_email' => 'contato@lojademo.test',
                'commercial_phone' => '11988887777',
                'document' => '12345678000199',
                'document_type' => 'cnpj',
                'status' => StoreStatus::ACTIVE,
                'reviewed_at' => now(),
            ],
        );

        $phones = Category::query()->where('slug', 'celulares')->first()
            ?? Category::query()->where('is_active', true)->first();

        $notebooks = Category::query()->where('slug', 'notebooks')->first()
            ?? $phones;

        if ($phones === null) {
            return;
        }

        $smartphone = Product::query()->firstOrCreate(
            ['slug' => 'smartphone-demo-128gb'],
            [
                'store_id' => $store->id,
                'category_id' => $phones->id,
                'name' => 'Smartphone Demo 128GB',
                'description' => 'Produto de demonstração para o catálogo público.',
                'status' => ProductStatus::ACTIVE,
                'reviewed_at' => now(),
            ],
        );

        Product::query()->firstOrCreate(
            ['slug' => 'notebook-demo-16gb'],
            [
                'store_id' => $store->id,
                'category_id' => $notebooks->id,
                'name' => 'Notebook Demo 16GB',
                'description' => 'Notebook de demonstração com foco em produtividade.',
                'status' => ProductStatus::ACTIVE,
                'reviewed_at' => now(),
            ],
        );

        $headphones = Product::query()->firstOrCreate(
            ['slug' => 'fone-bluetooth-demo'],
            [
                'store_id' => $store->id,
                'category_id' => $phones->id,
                'name' => 'Fone Bluetooth Demo',
                'description' => 'Acessório de demonstração em rascunho (não aparece no público).',
                'status' => ProductStatus::DRAFT,
            ],
        );

        $attributes = app(ProductAttributeService::class);
        $attributes->apply($smartphone, ['color' => 'preto', 'storage' => '128GB']);
        $attributes->apply($headphones, ['color' => 'branco', 'storage' => '64GB']);
    }
}
