<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OfferStatus;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class OfferSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()
            ->where('slug', 'smartphone-demo-128gb')
            ->where('status', ProductStatus::ACTIVE)
            ->first();

        $storeA = Store::query()->where('slug', 'loja-demo')->first();

        if ($product === null || $storeA === null) {
            return;
        }

        Offer::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'store_id' => $storeA->id,
            ],
            [
                'price_cents' => 199990,
                'promotional_price_cents' => 179990,
                'promo_starts_at' => now()->subDay(),
                'promo_ends_at' => now()->addDays(30),
                'stock' => 25,
                'reserved_stock' => 0,
                'status' => OfferStatus::ACTIVE,
            ],
        );

        $sellerRole = Role::query()->firstOrCreate(['name' => RoleName::SELLER]);
        $buyerRole = Role::query()->firstOrCreate(['name' => RoleName::BUYER]);

        $sellerB = User::query()->firstOrCreate(
            ['email' => 'seller.b.demo@marketplace.test'],
            [
                'name' => 'Seller B Demo',
                'password' => Hash::make('Password1!'),
                'cpf' => '39053344705',
                'phone_number' => '11977776666',
            ],
        );

        if (! $sellerB->hasVerifiedEmail()) {
            $sellerB->markEmailAsVerified();
        }

        $sellerB->roles()->syncWithoutDetaching([$buyerRole->id, $sellerRole->id]);

        $storeB = Store::query()->firstOrCreate(
            ['user_id' => $sellerB->id],
            [
                'store_name' => 'Loja Rival Demo',
                'slug' => 'loja-rival-demo',
                'description' => 'Segunda loja para demonstrar Buy Box multi-seller.',
                'commercial_email' => 'contato@lojarival.test',
                'commercial_phone' => '11977776666',
                'document' => '11222333000181',
                'document_type' => 'cnpj',
                'status' => StoreStatus::ACTIVE,
                'reviewed_at' => now(),
            ],
        );

        Offer::query()->firstOrCreate(
            [
                'product_id' => $product->id,
                'store_id' => $storeB->id,
            ],
            [
                'price_cents' => 189900,
                'promotional_price_cents' => null,
                'stock' => 10,
                'reserved_stock' => 0,
                'status' => OfferStatus::ACTIVE,
            ],
        );
    }
}
