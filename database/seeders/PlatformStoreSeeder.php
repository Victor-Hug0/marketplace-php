<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class PlatformStoreSeeder extends Seeder
{
    public function run(): void
    {
        if (Store::query()->platform()->exists()) {
            return;
        }

        $config = config('platform_store');

        DB::transaction(function () use ($config): void {
            $owner = User::query()->firstOrCreate(
                ['email' => $config['owner_email']],
                [
                    'name' => $config['owner_name'],
                    'password' => Hash::make(Str::password(32)),
                ],
            );

            if (! $owner->hasVerifiedEmail()) {
                $owner->markEmailAsVerified();
            }

            $roleIds = Role::query()
                ->whereIn('name', [RoleName::BUYER, RoleName::SELLER])
                ->pluck('id');
            $owner->roles()->syncWithoutDetaching($roleIds);

            $store = new Store([
                'user_id' => $owner->id,
                'store_name' => $config['store_name'],
                'slug' => $config['slug'],
                'description' => 'Produtos vendidos e entregues pelo próprio marketplace.',
                'commercial_email' => $config['commercial_email'],
                'origin_cep' => preg_replace('/\D+/', '', (string) $config['origin_cep']),
                'document' => $config['document'],
                'document_type' => 'cnpj',
                'status' => StoreStatus::ACTIVE,
                'reviewed_at' => now(),
            ]);
            $store->forceFill(['is_platform' => true])->save();
        });
    }
}
