<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class AddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data): Address {
            $data['cep'] = $this->normalizeCep((string) $data['cep']);
            $data['state'] = strtoupper((string) $data['state']);
            $makeDefault = (bool) ($data['is_default'] ?? false);

            if (! $makeDefault && ! $user->addresses()->exists()) {
                $makeDefault = true;
            }

            if ($makeDefault) {
                $this->clearDefaults($user);
            }

            $address = Address::query()->create([
                'user_id' => $user->id,
                'label' => $data['label'] ?? null,
                'recipient_name' => $data['recipient_name'],
                'phone' => $data['phone'],
                'cep' => $data['cep'],
                'street' => $data['street'],
                'number' => $data['number'],
                'complement' => $data['complement'] ?? null,
                'district' => $data['district'],
                'city' => $data['city'],
                'state' => $data['state'],
                'is_default' => $makeDefault,
            ]);

            $this->audit($user, $address, 'address.created');

            return $address;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Address $address, array $data): Address
    {
        return DB::transaction(function () use ($user, $address, $data): Address {
            $address = Address::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($address->id);

            if (array_key_exists('cep', $data)) {
                $data['cep'] = $this->normalizeCep((string) $data['cep']);
            }

            if (array_key_exists('state', $data)) {
                $data['state'] = strtoupper((string) $data['state']);
            }

            if (array_key_exists('is_default', $data) && $data['is_default'] === true) {
                $this->clearDefaults($user, $address->id);
                $data['is_default'] = true;
            } elseif (array_key_exists('is_default', $data) && $data['is_default'] === false && $address->is_default) {
                throw new ConflictHttpException(
                    'Não é possível remover o endereço padrão sem definir outro como padrão.',
                );
            }

            $address->update(array_intersect_key($data, array_flip([
                'label',
                'recipient_name',
                'phone',
                'cep',
                'street',
                'number',
                'complement',
                'district',
                'city',
                'state',
                'is_default',
            ])));

            $this->audit($user, $address, 'address.updated');

            return $address->refresh();
        });
    }

    public function delete(User $user, Address $address): void
    {
        DB::transaction(function () use ($user, $address): void {
            $address = Address::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($address->id);

            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $next = Address::query()
                    ->where('user_id', $user->id)
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();

                if ($next !== null) {
                    $next->update(['is_default' => true]);
                }
            }

            $this->audit($user, $address, 'address.deleted');
        });
    }

    public function makeDefault(User $user, Address $address): Address
    {
        return DB::transaction(function () use ($user, $address): Address {
            $address = Address::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($address->id);

            $this->clearDefaults($user, $address->id);
            $address->update(['is_default' => true]);

            $this->audit($user, $address, 'address.default_set');

            return $address->refresh();
        });
    }

    private function clearDefaults(User $user, ?string $exceptId = null): void
    {
        Address::query()
            ->where('user_id', $user->id)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function normalizeCep(string $cep): string
    {
        return preg_replace('/\D+/', '', $cep) ?? '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Address $address, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $address->id,
            'auditable_type' => $address->getMorphClass(),
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
