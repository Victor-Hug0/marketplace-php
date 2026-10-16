<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StoreService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function requestStore(User $user, array $data): Store
    {
        return DB::transaction(function () use ($user, $data): Store {
            if ($user->store()->withTrashed()->exists()) {
                throw new ConflictHttpException('O usuário já possui uma solicitação de loja.');
            }

            $store = Store::query()->create([
                ...$data,
                'user_id' => $user->id,
                'slug' => $this->uniqueSlug($data['store_name']),
                'status' => StoreStatus::PENDING,
            ]);

            $this->audit($user, $store, 'store.requested');

            return $store;
        });
    }

    public function approve(Store $store, User $admin): Store
    {
        return DB::transaction(function () use ($store, $admin): Store {
            $store = $this->lockedStore($store);
            $this->ensureStatus($store, StoreStatus::PENDING);

            $store->update([
                'status' => StoreStatus::ACTIVE,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $sellerRole = Role::query()->firstOrCreate([
                'name' => RoleName::SELLER,
            ]);

            $store->owner->roles()->syncWithoutDetaching([$sellerRole->id]);

            $this->audit($admin, $store, 'store.approved');

            return $store->refresh();
        });
    }

    public function reject(Store $store, User $admin, string $reason): Store
    {
        return DB::transaction(function () use ($store, $admin, $reason): Store {
            $store = $this->lockedStore($store);
            $this->ensureStatus($store, StoreStatus::PENDING);

            $store->update([
                'status' => StoreStatus::REJECTED,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $this->removeSellerRole($store->owner);
            $this->audit($admin, $store, 'store.rejected', ['reason' => $reason]);

            return $store->refresh();
        });
    }

    public function suspend(Store $store, User $admin, string $reason): Store
    {
        return DB::transaction(function () use ($store, $admin, $reason): Store {
            $store = $this->lockedStore($store);
            $this->ensureStatus($store, StoreStatus::ACTIVE);

            $store->update([
                'status' => StoreStatus::SUSPENDED,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $this->removeSellerRole($store->owner);
            $this->audit($admin, $store, 'store.suspended', ['reason' => $reason]);

            return $store->refresh();
        });
    }

    private function lockedStore(Store $store): Store
    {
        return Store::query()
            ->with('owner')
            ->lockForUpdate()
            ->findOrFail($store->id);
    }

    private function ensureStatus(Store $store, StoreStatus $expected): void
    {
        if ($store->status !== $expected) {
            throw new ConflictHttpException(
                "A loja precisa estar com status {$expected->value} para executar esta ação."
            );
        }
    }

    private function removeSellerRole(User $user): void
    {
        $sellerRoleId = Role::query()
            ->where('name', RoleName::SELLER->value)
            ->value('id');

        if ($sellerRoleId !== null) {
            $user->roles()->detach($sellerRoleId);
        }
    }

    private function uniqueSlug(string $storeName): string
    {
        $base = Str::slug($storeName) ?: 'loja';
        $slug = $base;
        $suffix = 2;

        while (Store::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Store $store, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $store->id,
            'auditable_type' => $store->getMorphClass(),
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
